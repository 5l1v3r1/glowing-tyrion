<div class="row">
    <div class="col-sm-12">
        <span id="message"></span>
    </div>
</div>
<?php

require_once('includes/modal/new_alert_rule.inc.php');
require_once('includes/modal/delete_alert_rule.inc.php');

?>
<form method="post" action="" id="result_form">
<?php

if(isset($_POST['results_amount']) && $_POST['results_amount'] > 0) {
    $results = $_POST['results'];
} else {
    $results = 50;
}

echo '<div class="table-responsive">
<table class="table table-hover table-condensed" width="100%">
  <tr>
    <th>#</th>
    <th>Rule</th>
    <th>Timestamp</th>
    <th>Severity</th>
    <th>Status</th>
    <th>Enabled</th>
    <th>Action</th>
  </tr>';

echo ('<td colspan="6"></td>
<td><select name="results" id="results" class="form-control input-sm" onChange="updateResults(this);">');
$result_options = array('10','50','100','250','500','1000','5000');
foreach($result_options as $option) {
    echo "<option value='$option'";
    if($results == $option) {
        echo " selected";
    }
    echo ">$option</option>";
}
echo('</select></td>');

$rulei=1;
$count_query = "SELECT COUNT(id)";
$full_query = "SELECT *";
$query = " FROM alert_rules WHERE device_id = -1 or device_id = ? ORDER BY device_id,id";
$param = array($device['device_id']);
$count_query = $count_query . $query;
$count = dbFetchCell($count_query,$param);
if(!isset($_POST['page_number']) && $_POST['page_number'] < 1) {
    $page_number = 1;
} else {
    $page_number = $_POST['page_number'];
}
$start = ($page_number - 1) * $results;
$full_query = $full_query . $query . " LIMIT $start,$results";

foreach( dbFetchRows($full_query, $param) as $rule ) {
	$sub = dbFetchRows("SELECT * FROM alerts WHERE rule_id = ? ORDER BY id DESC LIMIT 1", array($rule['id']));
	$ico = "ok";
	$col = "green";
	$extra = "";
	if( sizeof($sub) == 1 ) {
		$sub = $sub[0];
		if( (int) $sub['state'] === 0 ) {
			$ico = "ok";
			$col = "green";
		} elseif( (int) $sub['state'] === 1 ) {
			$ico = "remove";
			$col = "red";
			$extra = "danger";
		} elseif( (int) $sub['state'] === 2 ) {
			$ico = "time";
			$col = "#800080";
			$extra = "warning";
		}
	}
        $alert_checked = '';
        $orig_ico = $ico;
        $orig_col = $col;
        $orig_class = $extra;
	if( $rule['disabled'] ) {
		$ico = "pause";
		$col = "";
		$extra = "active";
	} else {
            $alert_checked = 'checked';
        }
	echo "<tr class='".$extra."' id='row_".$rule['id']."'>";
	echo "<td><i>#".((int) $rulei++)."</i></td>";
	echo "<td><i>".htmlentities($rule['rule'])."</i></td>";
	echo "<td>".($sub['time_logged'] ? $sub['time_logged'] : "N/A")."</td>";
	echo "<td>".$rule['severity']."</td>";
	echo "<td><i id='alert-rule-".$rule['id']."' class='glyphicon glyphicon-".$ico."' style='color:".$col."; font-size: 24px;' >&nbsp;</i></td>";
        echo "<td>";
        if ($_SESSION['userlevel'] == '10') {
            echo "<input id='".$rule['id']."' type='checkbox' name='alert-rule' data-orig_class='".$orig_class."' data-orig_colour='".$orig_col."' data-orig_state='".$orig_ico."' data-alert_id='".$rule['id']."' ".$alert_checked." data-size='small'>";
        }
        echo "</td>";
        echo "<td>";
        if ($_SESSION['userlevel'] == '10') {
            echo "<button type='button' class='btn btn-primary btn-sm' data-toggle='modal' data-target='#create-alert' data-device_id='".$device['device_id']."' data-alert_id='".$rule['id']."' name='edit-alert-rule'><span class='glyphicon glyphicon-pencil' aria-hidden='true'></span></button>";
            echo "<button type='button' class='btn btn-danger btn-sm' aria-label='Delete' data-toggle='modal' data-target='#confirm-delete' data-alert_id='".$rule['id']."' name='delete-alert-rule'><span class='glyphicon glyphicon-remove' aria-hidden='true'></span></button>";
        }
        echo "</td>";
	echo "</tr>\r\n";
}
if($count % $results > 0) {
    echo('    <tr>
         <td colspan="7" align="center">'. generate_pagination($count,$results,$page_number) .'</td>
     </tr>');
}
echo '</table>
<input type="hidden" name="page_number" id="page_number" value="'.$page_number.'">
<input type="hidden" name="results_amount" id="results_amount" value="'.$results.'">
</form>
</div>';
?>

<script>
$("[name='alert-rule']").bootstrapSwitch('offColor','danger');
$('input[name="alert-rule"]').on('switchChange.bootstrapSwitch',  function(event, state) {
    event.preventDefault();
    var $this = $(this);
    var alert_id = $(this).data("alert_id");
    var orig_state = $(this).data("orig_state");
    var orig_colour = $(this).data("orig_colour");
    var orig_class = $(this).data("orig_class");
    $.ajax({
        type: 'POST',
        url: '/ajax_form.php',
        data: { type: "update-alert-rule", alert_id: alert_id, state: state },
        dataType: "html",
        success: function(msg) {
            if(msg.indexOf("ERROR:") <= -1) {
                if(state) {
                    $('#alert-rule-'+alert_id).removeClass('glyphicon-pause');
                    $('#alert-rule-'+alert_id).addClass('glyphicon-'+orig_state);
                    $('#alert-rule-'+alert_id).css('color',orig_colour);
                    $('#row_'+alert_id).removeClass('active');
                    $('#row_'+alert_id).addClass(orig_class);
                } else {
                    $('#alert-rule-'+alert_id).removeClass('glyphicon-'+orig_state);
                    $('#alert-rule-'+alert_id).addClass('glyphicon-pause');
                    $('#alert-rule-'+alert_id).css('color','');
                    $('#row_'+alert_id).removeClass('warning');
                    $('#row_'+alert_id).addClass('active');
                }
            } else {
                $("#message").html('<div class="alert alert-info">'+msg+'</div>');
                $('#'+alert_id).bootstrapSwitch('toggleState',true );
            }
        },
        error: function() {
            $("#message").html('<div class="alert alert-info">This alert could not be updated.</div>');
            $('#'+alert_id).bootstrapSwitch('toggleState',true );
        }
    });
});

    function updateResults(results) {
       $('#results_amount').val(results.value);
       $('#page_number').val(1);
       $('#result_form').submit();
    }

    function changePage(page,e) {
        e.preventDefault();
        $('#page_number').val(page);
        $('#result_form').submit();
    }
</script>
