<?php defined("_AFRISOFT") or die(); 

echo '<script type="text/javascript" src="'.WEBPATH.'scripts/couponic.js" ></script>';


if(isset($_POST['make_auto_responder'])){


  $keyword = $afrisoft->antiHacking($_POST['keyword']);

	$validity = $afrisoft->antiHacking($_POST['validity']);
	
	///no of days to redeem coupon////
	
	$cost = $afrisoft->antiHacking($_POST['coupon_days']);
	
	///vendors 
	$vendor_location = $afrisoft->antiHacking($_POST['vendor_location']);
	
	$vendor_name = $afrisoft->antiHacking($_POST['vendor_name']);
	
	$vendor_gsm = $afrisoft->antiHacking($_POST['vendor_gsm']);
	
	///send copy to coupon////
	$email = $afrisoft->antiHacking($_POST['email']);

	$coupon_empty_vouchers = $afrisoft->antiHacking($_POST['coupon_empty_vouchers']);
	
	$newVouchers = removeEmptyKeys(explode(',',$coupon_empty_vouchers));
	
	$coupon_budget = count($newVouchers);

	$ticket_event = $afrisoft->antiHacking($_POST['ticket_event']);

	$ticket_vip = $afrisoft->antiHacking($_POST['ticket_vip']);

	$ticket_normal = $afrisoft->antiHacking($_POST['ticket_normal']);
	
	$ticket_vendor_normal = $afrisoft->antiHacking($_POST['ticket_vendor_normal']);
	
	$ticket_vendor_vip = $afrisoft->antiHacking($_POST['ticket_vendor_vip']);
	

	if(empty($keyword))$afrisoft->makeAlert('Please specify a keyword.');

	if (!preg_match("/^[A-Za-z0-9]+$/i", $keyword)) $afrisoft->makeAlert("Invalid keyword($keyword)! Use only alpha-numeric characters for keyword.");

	if (!preg_match("/^[1-9][0-9]*$/", $validity)) $afrisoft->makeAlert("Invalid validity($validity)! Use integers greater than 0");
 
 	if(!empty($coupon_empty_vouchers) && empty($cost))$afrisoft->makeAlert('Please specify a valid redemption period for vouchers');
	
	if(empty($validity))$afrisoft->makeAlert('Please specify a validity.');

	if(!empty($coupon_budget) && !preg_match("/^[1-9][0-9]*$/", $coupon_budget))$afrisoft->makeAlert('Please specify a valid budget or leave the budget field empty.');

	if(!empty($coupon_empty_vouchers) && !preg_match("/^[a-zA-Z0-9,]*$/", $coupon_empty_vouchers))$afrisoft->makeAlert('Invalid characters detected in vouchers.');

	if ((COUPON_PRICE*$validity)  > $afrisoft->user['Units'] || $validity < 1) $afrisoft->makeAlert('You have insufficient units. Your balance is '.number_format($afrisoft->user['Units'],2).' but you need '.number_format(COUPON_PRICE*$validity,2).' to create COUPON for '.$validity.' month(s)');

	if(!$afrisoft->countAlert()){

		$query="SELECT keyword from #__ss_keywords where keyword LIKE '$keyword' AND status = 1 LIMIT 1";

		$used =  $afrisoft->dbval($query);

		if(!empty($used)) $afrisoft->makeAlert('The keyword you specified has been taken and still in use. Please try another keyword.');

		else{

			$query="INSERT INTO #__ss_keywords (key_user_id,keyword,type,validity,created,status) VALUES ({$afrisoft->user['id']},'$keyword','coupon',NOW() + INTERVAL $validity MONTH,NOW(),'1')";

			
$coupon = true;
			$rowcount = $afrisoft->dbcountchanges($query);

			if($rowcount < 1) $afrisoft->makeAlert("Unable to create the requested keyword. Please try again.");

			else {

				$id=$afrisoft->dbinsertid();

				$afrisoft->makeAlert("Keyword ($keyword) has been created for $validity month(s).");

				$query =  'UPDATE #__spcClient SET Units = Units - '.(COUPON_PRICE*$validity)." WHERE clientID = {$afrisoft->user['id']}";

				$afrisoft->dbquery($query);
					#Save transaction history
					$query ="INSERT INTO #__spcTransactions (txClientID,txAmt,txCredit,txStatus) VALUES ('{$afrisoft->user['id']}', '-".(COUPON_PRICE*$validity)."', '$validity', '$keyword')";
					$afrisoft->dbquery($query);

				
				$afrisoft->makeAlert(number_format((COUPON_PRICE*$validity),2).' has been deducted from your units');

				$coupon_use_vouchers = empty($coupon_empty_vouchers) ? 0 : 1;

				$coupon_use_budget = ($coupon_budget - 0) > 0 ? 1 : 0;

				//Save into autoreply table

				$query = "INSERT INTO #__ss_tickets (ticket_key_id, ticket_event, ticket_vip, ticket_normal, ticket_vendor_vip, ticket_vendor_normal)"

				." VALUES ('$id','$ticket_event','$ticket_vip','$ticket_normal','$ticket_vendor_vip','$ticket_vendor_normal')";
				
				$rowcount = $afrisoft->dbcountchanges($query);

				if($rowcount < 1) $afrisoft->makeAlert("Unable to set COUPON. You can try to edit the keyword that has been created and set the COUPON.");

				else {

					$coupon_id = $afrisoft->dbinsertid();

						
						$ds_sender = $_POST['ds_sender'];
						$ds_message = $_POST['ds_message'];
						$dv_sender = $_POST['dv_sender'];
						$dv_message = $_POST['dv_message'];
            $query = "INSERT INTO #__ss_couponic_sms (	ars_coupon_id, ars_sender, ars_sms, ars_reply_type ) VALUES ($coupon_id,'".$afrisoft->antiHacking($ds_sender)."','".$afrisoft->antiHacking($ds_message)."','buyer'),($coupon_id,'".$afrisoft->antiHacking($dv_sender)."','".$afrisoft->antiHacking($dv_message)."','vendor')";
						

						$rowcount = $afrisoft->dbcountchanges($query);

						if($rowcount > 0) $afrisoft->makeAlert('coupon- messages have been registered sucessfully.');

						else $afrisoft->makeAlert('Unable to register coupon- messages.');
						
				////////////////////////////////////////////////////////////////////////////////////////
				
				
				  #############################will be array#########################################
				
				/////////////////////////////////////////////////////////////////////////////////////////	
					
					

						$dr_vendor_name = $_POST['dr_vendor_name'];

						$dr_vendor_gsm = $_POST['dr_vendor_gsm'];

						$dr_vendor_location = $_POST['dr_vendor_location'];

						$sql_array = array();

						for($i=0; $i<count($dr_vendor_name); $i++){

							if(!empty($dr_vendor_name[$i]) && !empty($dr_vendor_location[$i]))

							$dr_vendor_gsm[$i] = $dr_vendor_gsm[$i]-0;

							$sql_array[] = "($coupon_id,'".$afrisoft->antiHacking($dr_vendor_name[$i])."','".$afrisoft->antiHacking($dr_vendor_location[$i])."','".$afrisoft->antiHacking($dr_vendor_gsm[$i])."')";

						}

						$query = 'INSERT INTO #__ss_tickets_vendors (	ticket_id, vendor_name, vendor_location, vendor_gsm) VALUES '.implode(",",$sql_array);

						$rowcount = $afrisoft->dbcountchanges($query);

						if($rowcount > 0) $afrisoft->makeAlert('Ticket Vendors have been registered sucessfully.');

						else $afrisoft->makeAlert('Unable to register Ticket Vendors.');

					/*
					
						$query = "INSERT INTO #__ss_tickets_vendors"
				
				." (ticket_key_id, vendor_gsm, vendor_count, vendor_name, vendor_location)"

				." VALUES ('$id','$vendor_gsm','0','$vendor_name','$vendor_location')";
				
						$rowcount = $afrisoft->dbcountchanges($query);

						if($rowcount > 0) $afrisoft->makeAlert('ticket vendors have been registered sucessfully.');

						else $afrisoft->makeAlert('Unable to register Ticket- vendors.');
						
						*/

					include(PAGES . "generate_voucher.php");

				}

			}

		}

	}

	

}


$afrisoft->alert();
echo '<h3>COUPON KEYWORD costs '.number_format(COUPON_PRICE,2).' SMS units per month.</h3>';
function removeEmptyKeys($couponr = array()){
	$out = array();
	foreach ($couponr as $val){
		if(!empty($val))$out[] = $val;
	}
	return $out; //array_filter($couponr,function ($v){ return(!empty($v)); });

}
?>
<form action="" method="post" name="COUPON"><br /><br />
	<h3>Step 1: Basic Settings</h3><br />
	<table class="table">
	<tr>
		<td class="tdlabel">Keyword<span class="red">*</span></td>
		<td><input name='keyword' type='text' required='required' pattern='^[a-zA-Z0-9]+$' placeholder="Choose a keyword" class='form_element'/></td>
	</tr>
	<tr>
		<td class="tdlabel">Keyword Validity<span class="red">*</span>(Months)</td>
		<td><input name='validity' type='number' min="1" required='required' pattern='^[1-9][0-9]*$' placeholder="No of months" class='form_element'/></td>
	</tr>
	<tr>
		<td class="tdlabel">
			Coupon vouchers(optional)<br />
			Determines how many offers you have available<br />
			Separate multiple coupon vouchers with commas
		</td>
		<td>
			<textarea name='coupon_empty_vouchers' rows='6' id='coupon_empty_vouchers' onchange="check_voucher('coupon_empty_vouchers');" onkeyup="check_voucher('coupon_empty_vouchers');" placeholder="Multiple alpha-numeric vouchers separated by commas(Optional). " class='form_element'></textarea>
            <br/>
			<button onclick="return makeVouchers('coupon_empty_vouchers');" class="button">GENERATE COUPON CODES</button>
						
		</td>
	</tr>
    <tr>
		<td class="tdlabel">Send coupons to this email</td>
		<td><input name="email" type="text" placeholder= "leave blank to disable" class="form_element" id="email" />
        
        </td>
        
	</tr>
    <tr>
		<td class="tdlabel">Valid redemption Days</td>
		<td><input name='coupon_days' type='number' pattern='^[1-9][0-9]*$' placeholder=" customer must use the coupon before this period expires" class='form_element'/>
        </td>
        
	</tr>
	</table>

	<br /><h3>Step 2: Ticket - SMS replies </h3><br />
	
	<div id="manual_entry"  style="display:block;"> SUCESS MESSAGE
		<div id="date_replies" style="padding:15px;">
		<div id="date_reply">
		<table class="table">
		<tr class="tr2"><td class="tdlabel"> Sender ID</td><td><input name='ds_sender' type='text' maxlength="11"  placeholder="Type a sender ID" class='form_element'/></td></tr>
		<tr class="tr2"><td class="tdlabel"> SMS Message ( use @ticket@ to denote the ticket code, @phone@ to denote the phone eand @serial@ for ticket serial)</td><td><textarea rows="6" name='ds_message'  class='form_element'/> Your ticket is @ticket@ for @phone@. Just show your Phone at the gate to enter. friday 25 dec 2013 10:00 PM, Chaka demus and pliers</textarea></td></tr>
		</table>
		<hr style="border:#888 solid 2px;" />
		</div>
		</div> 
	</div>
    
    	<div id="manual_entry"  style="display:block;"> VENDOR MESSAGE
		<div id="date_replies" style="padding:15px;">
		<div id="date_reply">
		<table class="table">
		<tr class="tr2"><td class="tdlabel"> Sender ID</td><td><input name='dv_sender' type='text' maxlength="11"  placeholder="Type a sender ID" class='form_element'/></td></tr>
		<tr class="tr2"><td class="tdlabel">Message ( use @vendor@ to denote the vendors name, @type@ for type of ticket purchased and @serial@ for serial number)</td><td><textarea rows="6" name='dv_message'  class='form_element'/>Dear @vendor@ your @type@ ticket serial is @serial@ .</textarea></td></tr>
		</table>
		
    
    <br /><h3>Step 3: Ticket vendors </h3><br />
    
    <div id="manual_entry"  style="display:none;">
		<div id="date_replies" style="padding:15px;">
		<div id="date_reply">
		<table class="table">
		<tr class="tr2"><td class="tdlabel">Vendor Name</td><td><input name='dr_vendor_name[]' type='text' maxlength="11"  placeholder="Type a sender ID" class='form_element'/></td></tr>
		<tr class="tr2"><td class="tdlabel">vendor GSM</td><td><input name='dr_vendor_gsm[]'  placeholder="Eg 0772306640" class='form_element'/></td></tr>
		<tr class="tr2"><td class="tdlabel">Vendor location</td><td><textarea name='dr_vendor_location[]' placeholder="vendors address details" class='form_element'/></textarea></td></tr>
		</table>
		<hr style="border:#888 solid 2px;" />
		</div>
		</div> 
		<button class="button" onclick="copyObject('date_reply','date_replies'); return false;">Add another vendor</button>
	</div>
	<div><br /><input type="submit" class="button" onclick="return check_voucher('coupon_empty_vouchers');" name="make_auto_responder" value="          Save COUPON          " /></div>
</form>
