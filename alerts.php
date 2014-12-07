<?php
/* Copyright (C) 2014  <f0o@devilcode.org>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>. */

/**
 * Alerts Transport
 * @author f0o <f0o@devilcode.org>
 * @copyright 2014 f0o, LibreNMS
 * @license GPL
 * @package LibreNMS
 * @subpackage Alerts
 */

include("includes/defaults.inc.php");
include("config.php");
include("includes/definitions.inc.php");
include("includes/functions.php");

RunAlerts();

/**
 * Run all alerts
 * @return void
 */
function RunAlerts() {
	global $config;
	$default_tpl = "%title\r\nSeverity: %severity\r\n{if %state == 0}Time elapsed: %elapsed\r\n{/if}Timestamp: %timestamp\r\nUnique-ID: %uid\r\nRule: %rule\r\n{if %faults}Faults:\r\n{foreach %faults}  #%key: %value\r\n{/foreach}{/if}Alert sent to: {foreach %contacts}%value <%key> {/foreach}"; //FIXME: Put somewhere else?
	foreach( dbFetchRows("SELECT alerts.id,alerts.rule_id,alerts.device_id,alerts.state,alerts.details,alerts.time_logged,alert_rules.rule,alert_rules.severity FROM alerts,alert_rules WHERE alerts.rule_id = alert_rules.id && alerts.alerted = 0 ORDER BY alerts.id ASC") as $alert ) {
		$obj = DescribeAlert($alert);
		if( is_array($obj) ) {
			$tpl = dbFetchRow('SELECT template FROM alert_templates WHERE rule_id LIKE "%,'.$alert['rule_id'].',%"');
			if( isset($tpl['template']) ) {
				$tpl = $tpl['template'];
			} else {
				$tpl = $default_tpl;
			}
			echo "Issuing Alert-UID #".$alert['id'].": ";
			$msg = FormatAlertTpl($tpl,$obj);
			$obj['msg'] = $msg;
			if( !empty($config['alert']['transports']) ) {
				ExtTransports($obj);
			}
			echo "\r\n";
		}
		dbUpdate(array('alerted' => 1),'alerts','id = ?',array($alert['id']));
	}
}

/**
 * Run external transports
 * @param array $obj Alert-Array
 * @return void
 */
function ExtTransports($obj) {
	global $config;
	foreach( $config['alert']['transports'] as $transport=>$opts ) {
		if( file_exists($config['install_dir']."/includes/alerts/transport.".$transport.".php") ) {
			echo $transport." => ";
			eval('$tmp = function($obj,$opts) { '.file_get_contents($config['install_dir']."/includes/alerts/transport.".$transport.".php").' };');
			$tmp = $tmp($obj,$opts);
			echo ($tmp ? "OK" : "ERROR")."; ";
		}
	}
}

/**
 * Format Alert
 * @param string $tpl Template
 * @param array  $obj Alert-Array
 * @return string
 */
function FormatAlertTpl($tpl,$obj) {
	$tpl = addslashes($tpl);

	/**
	 * {if ..}..{else}..{/if}
	 */
	preg_match_all('/\\{if (.+)\\}(.+)\\{\\/if\\}/Uims',$tpl,$m);
	foreach( $m[1] as $k=>$if ) {
		$if = preg_replace('/%(\w+)/i','\$obj["$1"]', $if);
		$ret = "";
		$cond = "if( $if ) {\r\n".'$ret = "'.str_replace("{else}",'";'."\r\n} else {\r\n".'$ret = "',$m[2][$k]).'";'."\r\n}\r\n";
		eval($cond); //FIXME: Eval is Evil
		$tpl = str_replace($m[0][$k],$ret,$tpl);
	}

	/**
	 * {foreach %var}..{/foreach}
	 */
	preg_match_all('/\\{foreach (.+)\\}(.+)\\{\\/foreach\\}/Uims',$tpl,$m);
	foreach( $m[1] as $k=>$for ) {
		$for = preg_replace('/%(\w+)/i','\$obj["$1"]', $for);
		$ret = "";
		$loop = 'foreach( '.$for.' as $key=>$value ) { $ret .= "'.str_replace(array("%key","%value"),array('$key','$value'),$m[2][$k]).'"; }';
		eval($loop); //FIXME: Eval is Evil
		$tpl = str_replace($m[0][$k],$ret,$tpl);
	}

	/**
	 * Populate variables with data
	 */
	foreach( $obj as $k=>$v ) {
		$tpl = str_replace("%".$k, $v, $tpl);
	}
	return $tpl;
}

/**
 * Describe Alert
 * @param array $alert Alert-Result from DB
 * @return string
 */
function DescribeAlert($alert) {
	$tmp = array();
	$obj = array();
	$device = dbFetchRow("SELECT hostname FROM devices WHERE device_id = ?",array($alert['device_id']));
	$obj['hostname'] = $device['hostname'];
	$extra = json_decode(gzuncompress($alert['details']),true);
	$s = (sizeof($extra['rule']) > 1);
	if( $alert['state'] == 1 ) {
		$obj['title'] = 'Alert for device '.$device['hostname'].' Alert-ID #'.$alert['id'];
		foreach( $extra['rule'] as $incident ) {
			$i++;
			foreach( $incident as $k=>$v ) {
				if( !empty($v) && $k != 'device_id' && (stristr($k,'id') || stristr($k,'desc')) && substr_count($k,'_') <= 1 ) {
					$obj['faults'][$i] .= $k.' => '.$v."; ";
				}
			}
		}
	} elseif( $alert['state'] == 0 ) {
		$id = dbFetchRow("SELECT alerts.id,alerts.time_logged,alerts.details FROM alerts WHERE alerts.state = 1 && alerts.rule_id = ? && alerts.device_id = ? && alerts.id < ? ORDER BY id DESC LIMIT 1", array($alert['rule_id'],$alert['device_id'],$alert['id']));
		if( empty($id['id']) ) {
			return false;
		}
		$extra = json_decode(gzuncompress($id['details']),true);
		$s = (sizeof($extra['rule']) > 1);
		$obj['title'] = 'Device '.$device['hostname'].' recovered from Alert-ID #'.$id['id'];
		$obj['elapsed'] = TimeFormat(strtotime($alert['time_logged'])-strtotime($id['time_logged']));
		$obj['id'] = $id['id'];
		$obj['faults'] = false;
	} else {
		return "Unknown State";
	}
	$obj['uid'] = $alert['id'];
	$obj['severity'] = $alert['severity'];
	$obj['rule'] = $alert['rule'];
	$obj['timestamp'] = $alert['time_logged'];
	$obj['contacts'] = $extra['contacts'];
	$obj['state'] = $alert['state'];
	return $obj;
}

/**
 * Format Elapsed Time
 * @param int $secs Seconds elapsed
 * @return string
 */
function TimeFormat($secs){
	$bit = array(
		'y' => $secs / 31556926 % 12,
		'w' => $secs / 604800 % 52,
		'd' => $secs / 86400 % 7,
		'h' => $secs / 3600 % 24,
		'm' => $secs / 60 % 60,
		's' => $secs % 60
	);
	foreach($bit as $k => $v){
		if($v > 0) {
			$ret[] = $v . $k;
		}
	}
	if( empty($ret) ) {
		return "none";
	}
	return join(' ', $ret);
}
?>