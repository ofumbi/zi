<?php

class afrisoftClass
{
  private $dbx;
	public $user = array( );
	public $privilege = 0;
	private $member = array( );
	public $settings = array( );
	public $loggedIn = false;
	public $responses = array( 'OK' => 'Successful', '2904' => 'SMS Sending Failed', '2905' => 'Invalid username/password combination', '2906' => 'Credit exhausted', '2907' => 'Gateway unavailable', '2908' => 'Invalid schedule date format', '2909' => 'Unable to schedule', '2910' => 'Username is empty', '2911' => 'Password is empty', '2912' => 'Recipient is empty', '2913' => 'Message is empty', '2914' => 'Sender is empty', '2915' => 'One or more required fields are empty', '2916' => 'Message rejected by anti-spam!' );
	function __construct( )
	{
		if ( get_magic_quotes_gpc() ) {
			
			function magicQuotes_awStripslashes( &$value, $key )
			{
				
				$value = stripslashes( $value );
			}
			$gpc = array(
				&$_GET,
				&$_POST,
				&$_COOKIE,
				&$_REQUEST 
			);
			array_walk_recursive( $gpc, "magicQuotes_awStripslashes" );
		}
		$this->DB_HOST            = func_get_arg( 0 );
		$this->DB_NAME            = func_get_arg( 1 );
		$this->DB_USER            = func_get_arg( 2 );
		$this->DB_PASS            = func_get_arg( 3 );
		$this->DB_PREFIX          = func_get_arg( 4 );
		$fromAPi = @func_get_arg( 5 );
		$this->is_mobile          = $this->checkmobile();
		if ( empty( $fromAPi ) || $fromAPi == 0 )
			$this->isAPI = false;
		else
			$this->isAPI = true;
		$this->data    = array( );
		$this->cPrices = "";
	}
	function sendSMS( $sender, $recipientList, $message, $smsClient, $channel = "", $optional = array( ) )
	{
		 $contentFilter = $this->setting( "contentFilter" );
		if ( !empty( $contentFilter ) ) {
			
			
			$contentFilter = explode( ",", $contentFilter );
			$contentFilterReplacement  = $this->setting( "contentFilterReplacement" );
			foreach ( $contentFilter as $filterGroup ) {
				$filters = explode( " ", $filterGroup );
				$no_of_filter_words      = count( $filters );
				$badwords     = 0;
				foreach ( $filters as $filter ) {
					if ( stripos( $message, $filter ) !== false && !empty( $filter ) )
						$badwords++;
				}
				if ( $no_of_filter_words== $badwords ) {
					if ( !empty( $contentFilterReplacement ) )
						$message = $contentFilterReplacement;
					else
						return "2916";
					break;
				}
			}
		}
		
		

		$channel   = ( $this->isAPI ) ? $channel : "Website";
		$channel              = ( empty( $channel ) ) ? "API" : $channel;
		$msgID   = isset( $apiDlvrID["msgID"] ) ? $optional["msgID"] : 0;
		$apiDlvrID     = isset( $optional["apiDlvrID"] ) ? $optional["apiDlvrID"] : "";
		$dlvrID = $this->uuid() . time();
		$wholeMessageId  = array( );
		$scheduledMsgQuery2    = "UPDATE #__spcMessages SET msgStatus = 'Failed' WHERE msgID = $msgID AND msgStatus like 'processing' AND schedule IS NOT NULL AND schedule < NOW()";
		$this->setting();
		if ( empty( $recipientList ) ) {
			if ( $msgID > 0 )
				$this->dbquery( $scheduledMsgQuery2 );
			return "2912";
		}
		if ( empty( $message ) ) {
			if ( $msgID > 0 )
				$this->dbquery( $scheduledMsgQuery2 );
			return "2913";
		}
		
		if ( empty( $sender ) ) {
			if ( $msgID > 0 )
				$this->dbquery( $scheduledMsgQuery2 );
			return "2914";
		}
		$max_msg_allowed = $this->setting( "max_msg_allowed" ) * $this->setting( "smsLength2" );
		if ( !empty( $max_msg_allowed ) && $max_msg_allowed > $this->setting( "smsLength" ) && strlen( $message ) > $max_msg_allowed ) {
		
			$s = substr( $message, 0, $max_msg_allowed );
			$s2              = substr( $message, $max_msg_allowed, 1 );
			if ( $s2 != " " ) {
	
				
				$pos          = strrpos( $s, " " );
				$s        = substr( $s, 0, $pos );
			}
			$s3     = substr( $message, strlen( $s ) );
			
			$result               = $this->sendSMS( $sender, $recipientList, $s, $smsClient, $channel, $msgID );
			$result2 = $this->sendSMS( $sender, $recipientList, $s3, $smsClient, $channel, $msgID );
			return $result . " | " . $result2;
		}
		
		
		$blockSender = $this->setting( "blockSender" );
		if ( !empty( $blockSender ) ) {
			
			$blockSender     = strtoupper( $blockSender );
			
			$blockSender = str_replace( explode( ",", " ,-,_,." ), "", $blockSender );
			$s              = explode( ",", $blockSender );
			$s2     = str_replace( explode( ",", " ,-,_,." ), "", strtoupper( $sender ) );
			if ( in_array( $s2, $s ) ) {
				
				$rpl = $this->setting( "blockSenderReplacement" );
				if ( !empty( $rpl ) )
					$sender = $rpl;
				else {
					if ( $msgID > 0 )
						$this->dbquery( $scheduledMsgQuery2 );
					return "2916";
				}
			}
		}
		$this->getUser( $smsClient );
		if ( !empty( $this->member["specialAPI"] ) && $this->member["specialAPI"] != 0 )
			$smsApiGateway = $this->member["specialAPI"];
		else
			$smsApiGateway = $this->settings["smsGateway"];
		
		$query = "SELECT * FROM #__spcAPI WHERE apiID = $smsApiGateway";
		
		$api                = $this->dbrow( $query );
		$tempSmsRoutes             = trim( $this->member["specialRoute"] );
		$tempSmsRoutes = empty( $tempSmsRoutes ) ? trim( $this->setting( "smsRoutes" ) ) : $tempSmsRoutes;
		if ( !empty( $tempSmsRoutes ) ) {
			$query = "SELECT * FROM #__spcAPI";
			$tempSmsApis = $this->dbarray( $query );
			$smsApis   = array( );
			foreach ( $tempSmsApis as $value ) {
		
				$smsApis[strtolower( $value["apiName"] )] = $value;
			}
			$tempSmsRoutes = explode( "\n", $tempSmsRoutes );
			$smsRoutes = array( );
			foreach ( $tempSmsRoutes as $value ) {
				
				$value  = explode( "=", $value );
				$smsRoutes[trim( $value[0] )] = $smsApis[strtolower( trim( $value[1] ) )];
			}
		}
		$useName  = $this->settings["useName"];
		
		
		if ( $useName == 1 ) {
			
			if ( $api["category"] == 2 )
				$useName = ( $this->isAPI ) ? false : ( ( strpos( $message, "@@name@@" ) === false ) ? false : true );
			else
				$useName = false;
		} else
			$useName = false;
		$message     = $message . stripslashes( $this->member["signature"] ) . stripslashes( $this->settings["appendSMS"] );
		$msgLength                  = strlen( str_replace( "\r\n", "  ", $message ) );
		$priceList     = $this->countryPrices( "" );
		$recipientList    = $this->correctCommas( $recipientList );
		$recipientList               = $this->alterPhone( $recipientList );
		$recipient_s    = explode( ",", $recipientList );
		$recipientsz  = array_unique( $recipient_s);
		$outputDetails     = array( );
		$cntrep = count( $recipientsz );
		$cntrep2       = count( $recipient_s );
		$hnsthwtmbl                 = "credit";
		$recipients = $this->filterNos( $recipientsz, 1 );
		$cntrep3     = count( $recipients );
		if ( !$this->isAPI ) {
			if ( $cntrep < $cntrep2 )
				$outputDetails[] = ( $cntrep2 - $cntrep ) . " repeated numbers were removed";
			if ( $cntrep3< $cntrep )
				$outputDetails[] = ( $cntrep - $cntrep3) . " invalid numbers were removed";
		}
		
		$availableCredit         = (float) $this->member["Units"];
		if ( $availableCredit <= 0 )
			return "2906";
		          ////////// Check if country code has not been blocked
		$blockCountry = $this->settingsArray['blockCountry'];
                if(!empty($blockCountry)){
                    $blockCountry = $this -> correctCommas($blockCountry);
                    $s = explode(",",$blockCountry);
                    foreach($s as $value) {
                        $s_length = strlen($value);
                        foreach($recipients as &$dest){
                            if(substr($dest,0,$s_length) == $value && $s_length > 0 && !empty($dest)) $dest = "";
                        }
                    }
                }
		unset($s_length);unset($s);
                ////////// End check if country code has not been blocked
				
				
		$oldBalance = $availableCredit;
		${$hnsthwtmbl}            = 0.00;
		$destination              = array( );
		$good_recipients = array( );
		$routedRecipients  = array( );
		$unsent = array( );
		$rand   = md5( $sender . implode( ",", $recipients ) . $message );
		$countPriceList = count( $priceList );
		
		for($i=0; $i<count($recipients); $i++){
			if ($availableCredit <= 0) return "2906";//break;
			$unitcost=1;
			for($j=0; $j<count($priceList); $j++){
				$len = strlen($priceList[$j][0]);
				if(substr($recipients[$i],0,$len) == $priceList[$j][0] && $len > 0 && !empty($priceList[$j][0])){
					$unitcost=$priceList[$j][1];
					break;
				}
			}
			
			if ( $availableCredit < $unitcost )
				return "2906";///BREAK
			
			if ($useName){
				$r_name = $this->getName($id,$recipients[$i]);
				$message = str_replace("@@name@@",$r_name,$message);
				$msgLength = strlen(str_replace("\r\n","  ",$message));
			}
			$requiredCredit = ( $msgLength <= $this->settings["smsLength"] ) ? $this->mceil( $msgLength / $this->settings["smsLength"] ) * $unitcost : $this->mceil( $msgLength / $this->settings["smsLength2"] ) * $unitcost;
			if ( $availableCredit < $requiredCredit )
				return "2906";///BREAK
				
		//SMS Routing if no special api is set
			$api_x = $api;
			$useRoute = false;
			if(!empty($smsRoutes)){
				foreach($smsRoutes as $key => $value){
					$len = strlen($key);
					if(substr($recipients[$i],0,$len) == $key && $len > 0 && !empty($value)){
						if (empty($smsRoutes[$key]['recipients']))$smsRoutes[$key]['recipients'] = "";
						$smsRoutes[$key]['recipients'] = $smsRoutes[$key]['recipients'] . $recipients[$i].",";
						$api_x = $smsRoutes[$key];
						$useRoute = true;
						break;
					}
				}
			}
			
			if (!$useName && !$useRoute) $good_recipients[] = $recipients[$i];//store for bulk api;
			
			
			if ( $api["category"] == 2 ) {
				$dlvrID     = "s" . time() . mt_rand( 0, 999 );
				$searchKeys= array("@@sender@@","@@message@@","@@recipient@@","@@msgid@@" );
				$replaceKeys = array(urlencode( $sender ),urlencode( $message ),urlencode( $recipients[$i] ),urlencode( $dlvrID ));
				
				$smsGateway = str_replace( $searchKeys, $replaceKeys, $api_x["apiData"] );
				$response    = $this->URLRequest( $smsGateway, $api_x["protocol"] );
				if ( !empty( $api_x["fnMsgID"] ) ) {
					$nuFnName  = "f" . time();
					$fnMsgID = false;
					$apifn   = str_replace( " MsgID", " " . $nuFnName, $api_x["fnMsgID"] );
					@eval( $apifn );
					if ( function_exists( $nuFnName ) )
						$fnMsgID = $nuFnName( $response );
					$wholeMessageId[] = ( $fnMsgID == false ) ? $dlvrID : str_replace( ",", "#", $fnMsgID );
				} else
					$wholeMessageId[] = $dlvrID;
				if ( !$this->isAPI )
					echo " .";
				$confirmType = "x" . $api_x["confirmType"];
				switch ( $confirmType ) {
					case "x1":
						$sent = ( stripos( $response, $api_x["apiResponse"] ) !== false ) ? true : false;
						break;
					case "x2":
						$sent = ( stripos( $response, $api_x["apiResponse"] ) === false ) ? true : false;
						break;
					case "x3":
						$response    = (int) $response;
						$sent = ( $response > $api_x["apiResponse"] ) ? true : false;
						break;
					case "x4":
						$response    = (int) $response;
						$sent = $sent = ( $response < $api_x["apiResponse"] ) ? true : false;
						break;
					case "x5":
						$sent = ( stripos( $response, $api_x["apiResponse"] ) === 0 ) ? true : false;
						break;
					case "x6":
						$pos = strripos( $response, $api_x["apiResponse"] );
						$sent           = ( $pos !== false && $pos == ( strlen( $response ) - strlen( $api_x["apiResponse"] ) ) ) ? true : false;
						break;
					case "x7":
						$sent   = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							
							
							if ( is_numeric( $tok ) ) {
								$sent = ( $tok > $api_x["apiResponse"] ) ? true : false;
								break;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x8":
						$sent = false;
						$tok     = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								$sent = ( $tok < $api_x["apiResponse"] ) ? true : false;
								break;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x9":
						$sent              = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								
								$sent= ( $tok > $api_x["apiResponse"] ) ? true : $sent;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x10":
						$sent               = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								
								$sent = ( $tok < $api_x) ? true : $sent;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x11":
						$sent = ( preg_match( $api_x["apiResponse"], $response ) ) ? true : false;
						break;
					default:
						$sent = ( stripos( $response, $api_x["apiResponse"] ) !== false ) ? true : false;
						break;
				}
				if ( $sent != false ) {
					$credit += $requiredCredit;
					$availableCredit -= $requiredCredit;
					$this->dbquery("UPDATE#__spcClient SET Units = Units - ".$requiredCredit." WHERE clientID = ".$smsClient);
					$saveMsgz = "INSERT INTO#__spcMessages(msgClientID,senderID,recipients,message,delivered,msgStatus,channel,unitsUsed) VALUES 
					($smsClient,'".addslashes($sender)."','".$recipients[$i]."','".addslashes($message)."', NOW(),'Sent','".$channel."',".$requiredCredit.")";
					if($msgID > 0)$saveMsgz="UPDATE#__spcMessages SET msgStatus = 'Sent', delivered=NOW(), unitsUsed = unitsUsed + $requiredCredit WHERE msgID = $msgID";
                    $this->dbquery($saveMsgz);
					$good_recipients[] = $recipients[$i];
				} else {
					$unsent[] = $recipients[$i];
				}
			} else {
				$credit += $requiredCredit;
				$availableCredit -= $requiredCredit;
			}
		}
		if ( $api["category"] == 1 ) {
			
			$numbs    = array_chunk( $good_recipients, 50 );
			$sent             = false;
			$units_deducted = false;
			foreach ( $numbs as $numb ) {
				if ( !$this->isAPI )
					echo " .";
				$dlvrID     = "y" . time() . mt_rand( 0, 199 );
				$searchKeys    = array(
					"@@sender@@",
					"@@message@@",
					"@@recipient@@",
					"@@msgid@@" 
				);
				$replaceKeys     = array(
					urlencode( $sender ),
					urlencode( $message ),
					urlencode( implode( ",", $numb ) ),
					urlencode( $dlvrID) 
				);
				$smsGateway = str_replace( $searchKeys, $replaceKeys, $api["apiData"] );
				$response    = $this->URLRequest( $smsGateway, $api["protocol"] );
				
				if ( !empty( $api["fnMsgID"] ) ) {
					$nuFnName  = "f" . time();
					$fnMsgID = false;
					$apifn   = str_replace( " MsgID", " " . $nuFnName, $api_x["fnMsgID"] );
					@eval( $apifn );
					if ( function_exists( $nuFnName ) )
						$fnMsgID = $nuFnName( $response );
					$wholeMessageId[] = ( $fnMsgID == false ) ? $dlvrID : str_replace( ",", "#", $fnMsgID );
				} else
					$wholeMessageId[] = $dlvrID;
				$confirmType         = "x" . $api["confirmType"];
				switch ( $confirmType ) {
					case "x1":
						$sent = ( stripos( $response, $api["apiResponse"] ) !== false ) ? true : false;
						break;
					case "x2":
						$sent = ( stripos( $response, $api["apiResponse"] ) === false ) ? true : false;
						break;
					case "x3":
						$response    = (int) $response;
						$sent = ( $response > $api["apiResponse"] ) ? true : false;
						break;
					case "x4":
						$response = (int) $response;
						$sent               = $sent = ( $response < $response["apiResponse"] ) ? true : false;
						break;
					case "x5":
						$sent = ( stripos( $response, $api["apiResponse"] ) === 0 ) ? true : false;
						break;
					case "x6":
						$pos      = strripos( $response, $api["apiResponse"] );
						$sent = ( $pos !== false && $pos == ( strlen( $response ) - strlen( $api["apiResponse"] ) ) ) ? true : false;
						break;
					case "x7":
						$sent               = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								$sent = ( $tok > $api["apiResponse"] ) ? true : false;
								break;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x8":
						$sent  = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								$sent = ( $tok < $api["apiResponse"] ) ? true : false;
								break;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x9":
						$sent  = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								
								$sent = ( $tok > $api["apiResponse"] ) ? true : $sent;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x10":
						$sent                 = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								$sent  = ( $tok < $api["apiResponse"] ) ? true : $sent;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x11":
						$sent = ( preg_match( $api["apiResponse"], $response ) ) ? true : false;
						break;
					default:
						$sent = ( stripos( $response, $api["apiResponse"] ) !== false ) ? true : false;
						break;
				}
				if ( $sent != false && $units_deducted == false ) { 
					$saveMsgz = "INSERT INTO#__spcMessages(msgClientID,senderID,recipients,message,delivered,msgStatus,channel,unitsUsed) VALUES 
					($smsClient,'".addslashes($sender)."','".addslashes(implode(",",$recipients))."','".addslashes($message)."', NOW(),'Sent','".$channel."',".$credit.")";
					if($msgID > 0)$saveMsgz="UPDATE#__spcMessages SET msgStatus = 'Sent', delivered=NOW(), unitsUsed = $credit WHERE msgID = $msgID AND msgStatus like 'processing' AND schedule IS NOT NULL AND schedule < NOW()";
                                        $this->dbquery($saveMsgz);
					$this->dbquery("UPDATE#__spcClient SET Units = Units - ".$credit." WHERE clientID = ".$smsClient);
					$units_deducted = true;
				}
			}
			
			
			#########################################
			##  Routing if no special api is set   ##
			#########################################
			if ( !empty( $smsRoutes ) ) {
				foreach ( $smsRoutes as $smsRoute ) {
					$routedNos = explode( ",", $smsRoute["recipients"] );
					array_pop( $routedNos );
					$numbs = array_chunk( $routedNos, 50 );
					$api = $smsRoute;		
					foreach ( $numbs as $numb ) {
				if ( !$this->isAPI )echo " .";
				$dlvrID = "y" . time() . mt_rand( 0, 199 );
				$searchKeys = array("@@sender@@","@@message@@","@@recipient@@","@@msgid@@");
				$replaceKeys = array(urlencode( $sender ),urlencode( $message ),urlencode( implode( ",", $numb ) ),urlencode( $dlvrID));
				$smsGateway = str_replace( $searchKeys, $replaceKeys, $api["apiData"] );
				$response    = $this->URLRequest( $smsGateway, $api["protocol"] );
			
				if ( !empty( $api["fnMsgID"] ) ) {
					$nuFnName  = "f" . time();
					$fnMsgID = false;
					$apifn   = str_replace( " MsgID", " " . $nuFnName, $api_x["fnMsgID"] );
					@eval( $apifn );
					if ( function_exists( $nuFnName ) )
						$fnMsgID = $nuFnName( $response );
					$wholeMessageId[] = ( $fnMsgID == false ) ? $dlvrID : str_replace( ",", "#", $fnMsgID );
				} else
					$wholeMessageId[] = $dlvrID;
				$confirmType         = "x" . $api["confirmType"];
				switch ( $confirmType ) {
					case "x1":
						$sent = ( stripos( $response, $api["apiResponse"] ) !== false ) ? true : false;
						break;
					case "x2":
						$sent = ( stripos( $response, $api["apiResponse"] ) === false ) ? true : false;
						break;
					case "x3":
						$response    = (int) $response;
						$sent = ( $response > $api["apiResponse"] ) ? true : false;
						break;
					case "x4":
						$response = (int) $response;
						$sent               = $sent = ( $response < $response["apiResponse"] ) ? true : false;
						break;
					case "x5":
						$sent = ( stripos( $response, $api["apiResponse"] ) === 0 ) ? true : false;
						break;
					case "x6":
						$pos      = strripos( $response, $api["apiResponse"] );
						$sent = ( $pos !== false && $pos == ( strlen( $response ) - strlen( $api["apiResponse"] ) ) ) ? true : false;
						break;
					case "x7":
						$sent               = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								$sent = ( $tok > $api["apiResponse"] ) ? true : false;
								break;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x8":
						$sent  = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								$sent = ( $tok < $api["apiResponse"] ) ? true : false;
								break;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x9":
						$sent  = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								
								$sent = ( $tok > $api["apiResponse"] ) ? true : $sent;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x10":
						$sent                 = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								$sent  = ( $tok < $api["apiResponse"] ) ? true : $sent;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x11":
						$sent = ( preg_match( $api["apiResponse"], $response ) ) ? true : false;
						break;
					default:
						$sent = ( stripos( $response, $api["apiResponse"] ) !== false ) ? true : false;
						break;
				}
				if ( $sent != false && $units_deducted == false ) { 
					$saveMsgz = "INSERT INTO#__spcMessages(msgClientID,senderID,recipients,message,delivered,msgStatus,channel,unitsUsed) VALUES 
					($smsClient,'".addslashes($sender)."','".addslashes(implode(",",$recipients))."','".addslashes($message)."', NOW(),'Sent','".$channel."',".$credit.")";
					if($msgID > 0)$saveMsgz="UPDATE#__spcMessages SET msgStatus = 'Sent', delivered=NOW(), unitsUsed = $credit WHERE msgID = $msgID AND msgStatus like 'processing' AND schedule IS NOT NULL AND schedule < NOW()";
                                        $this->dbquery($saveMsgz);
					$this->dbquery("UPDATE#__spcClient SET Units = Units - ".$credit." WHERE clientID = ".$smsClient);
					$units_deducted = true;
				}
			}
				}
			}
			if ( !$units_deducted )
				$credit = 0;
		}
		if ( $credit > 0 ) {// process suceesfull sms
			$_SESSION["outputDetails"] = $outputDetails;
			if ( $availableCredit <= $this->setting( "lowUnits" ) && $oldBalance > $this->setting( "lowUnits" ) ) {
				$zrzkusly              = "availableCredit";
				$this->user["Units"]   = ${$zrzkusly};
				$this->member["Units"] = $availableCredit;
				if ( !empty( $this->settings["lowUnitsMessage"] ) ) {
					$this->settings["lowUnitsMessage"] = $this->customizeMsg( $this->setting( "lowUnitsSMS" ), $this->member["username"], $this->member["name"], $this->member["email"], $this->member["GSM"], $availableCredit );
					$this->notifyMember( $message, $this->settings["lowUnitsMessage"] );
				}
			}
		}
		$returnedString = ( $credit <= 0 ) ? "2904 " . implode( ",", $unsent ) : "OK " . $credit . " " . implode( ",", $unsent );
		return $returnedString;
	}
	
	
	
	
	##############################################
	##	Function to trigger scheduled msg		##
	##############################################
	
	
	function autoSendSMS( )
	{
		set_time_limit( 0 );
		ini_set( "memory_limit", "1000M" );
		$lastschedule           = $this->setting( "lastschedule" );
		$lastschedule = ( empty( $lastschedule ) ) ? 0 : $lastschedule;
		if ( ( time() - $lastschedule ) > 60 )
			$this->dbquery( "REPLACE INTO #__spcSettings (setName,setValue) VALUES ('lastschedule','" . time() . "')" );
		else
			return false;
		$query = "SELECT * FROM #__spcMessages WHERE msgStatus like 'pending' AND schedule IS NOT NULL AND schedule < NOW() AND schedule > '1980-29-04'";
		$tableObject = $this->dbarray( $query );
		$countTableObject   = count( $tableObject );
		foreach ( $tableObject as $obj ) {
			$msgID = $obj["msgID"];
			$query = "UPDATE #__spcMessages SET msgStatus = 'processing' WHERE msgID = $msgID AND msgStatus like 'pending' AND schedule IS NOT NULL AND schedule < NOW()";
			if ( $this->dbcountchanges( $query ) < 1 )
				continue;
				//get msg
			$message     = stripslashes( $obj["message"] );
				// get senderid
			$sender      = stripslashes( $obj["senderID"] );
			$optional = array(
				"msgID" => $msgID,
				"apiDlvrID" => $obj["apiDlvrID"] 
			);
			$this->sendSMS( $sender, stripslashes( $obj["recipients"] ), $message, $obj["msgClientID"], "Website", $optional );
		}
	}
	
	
	######################################################
	##	Function to get reciepint for sending cust msg	##
	######################################################

	
	function getName( $id, $GSM )
	{
		return $this->dbval( "SELECT fullname FROM #__spcAddressBook WHERE addClientID = '$id' AND addphone like '$GSM' LIMIT 1" );
	}
	
	##############################################
	#				get a setting				 #
	##############################################
	function setting( $settingname = "" )
	{
		if ( empty( $this->settings ) || count( $this->settings ) == 0 ) {
		
			$arr = $this->dbarray( "SELECT * FROM #__spcSettings" );
			$this->settings          = array( );
			foreach ( $arr as $value )
				$this->settings[$value["setName"]] = $value["setValue"];
		}
		
		if ( !empty( $settingname ) )
			return $this->settings[$settingname];
	}
	
	
	##############################################
	#			    fetch a user			 	 #
	##############################################
	
	
	function getUser( $id = 0, $property = "" )
	{
	
		
		if ( !empty( $this->user["id"] ) && $id == $this->user["id"] ) {
			$this->member = $this->user;
		} else if ( !empty( $this->user["id"] ) && $id > 0 && $this->user["id"] > 0 ) {
			
			$query = "SELECT * FROM #__spcClient LEFT JOIN #__users ON #__spcClient.clientID = #__users.id WHERE clientID = " . $this->antiHacking( $id );
			$this->member          = $this->dbrow( $query );
		} else if ( $id > 0 ) {
			$query        = "SELECT * FROM #__spcClient LEFT JOIN #__users ON #__spcClient.clientID = #__users.id WHERE clientID = " . $this->antiHacking( $id );
			$this->user           = $this->dbrow( $query );
			$this->member         = $this->user;
		}
		
		if ( !empty( $property ) )
			return $this->member[$property];
	}
	
	
	##############################################
	#			get destination cost			 #
	##############################################
	
	
	function countryPrices( $personalPrice = "" )
	{
		if ( empty( $this->cPrices ) && empty( $personalPrice ) ) {
			
			$piyyjg                     = "pricelist";
			$row = $this->dbval( "SELECT setValue FROM #__spcSettings WHERE setName = 'countryCodes';" );
			$pricelist = str_replace( array(
				"\r\n",
				"\n",
				"\r" 
			), ",", $row );
			while ( strpos( $pricelist, ",," ) !== false )
				$pricelist = str_replace( ",,", ",", $pricelist );
			$pricelist = explode( ",", $pricelist );
			$this->cPrices        = array( );
			foreach ( $pricelist as $value ) {
				$value   = str_replace( " ", "", $value );
				$this->cPrices[]        = explode( "=", trim( $value ) );
			}
		} else if ( !empty( $personalPrice ) ) {
			$pricelist = str_replace( array(
				"\r\n",
				"\n",
				"\r" 
			), ",", $personalPrice );
			while ( strpos( $pricelist, ",," ) !== false )
				$pricelist = str_replace( ",,", ",", $pricelist );
			$pricelist      = explode( ",", $pricelist );
			$cp = array( );
			foreach ( $pricelist as $value ) {
				$value        = str_replace( " ", "", $value );
				$cp[] = explode( "=", trim( $value ) );
			}
			return $cp;
		}
		return $this->cPrices;
	}
	
	
	
	##############################################
	##	Database connection and query functions	## 
	##############################################
	
	function dbconnect( )
	{
		
		//This function connects to mysql and selects a database. 
		//It returns the connection string.
		$this->dbx = mysql_connect( $this->DB_HOST, $this->DB_USER, $this->DB_PASS ) or die( "Error. Could not connect to database!" );
		mysql_select_db( $this->DB_NAME, $this->dbx ) or die( "<br>Error. Could not select database!" );
		mysql_query( "SET time_zone = '" . $this->setting( "timezone" ) . "'" );
	}
	
	
	
	
	function cleanSQL( $sql )
	{
		return str_replace( "#__", $this->DB_PREFIX, $sql );
	}
	
	
	
	
	function dbquery( $sql )
	{ /* 	This function connects and queries a database. It returns the query result identifier.	*/
	
		if ( is_null( $this->dbx ) )
			$this->dbconnect();
		$result = mysql_query( $this->cleanSQL( $sql ), $this->dbx );
		return $result;
	}
	
	
	
	
	function dbcount( $sql )
	{/* 	This function connects and queries a database. It returns the number of selected rows.	*/
		if ( is_null( $this->dbx ) )
			$this->dbconnect();
		$result = mysql_query( $this->cleanSQL( $sql ), $this->dbx );
		if ( !$result )
			return;
		return mysql_num_rows();
	}
	function dbcountchanges( $sql )
	{ /* 	This function connects and queries a database. It returns the number of insert/updated/deleted/replace rows.	*/
		if ( is_null( $this->dbx ) )
			$this->dbconnect();
		$sql = $this->cleanSQL( $sql );
		$result = mysql_query( $sql, $this->dbx );
		if ( !$result )
			return;
		return mysql_affected_rows();
	}
	
	
	
	function dbrow( $sql, $repeat = 0 )
	{
		/* 	This function connects and queries a database. It returns a single row from d db as a 1d array. */
		$ytggsctzyrm = "repeat";
		if ( $repeat === 0 ) {
			$bmkblwayydj             = "sql";
			$result = $this->dbquery( ${$bmkblwayydj} );
			if ( !$result )
				return;
			return mysql_fetch_assoc( $result );
		} else if ( ${$ytggsctzyrm} === 1 ) {
			$this->result = $this->dbquery( $sql);
			return $this->result;
		} else {
			return mysql_fetch_assoc( $this->result );
		}
	}
	function dbarray( $sql )
	{
		$wlvqukqijy                = "sql";
		$GLOBALS["oqtfynumzua"]    = "result";
		$qcxpjgmforhp              = "result";
		${$GLOBALS["oqtfynumzua"]} = $this->dbquery( ${$wlvqukqijy} );
		if ( !${$qcxpjgmforhp} )
			return;
		$arr = array( );
		while ( $row = mysql_fetch_assoc( $result ) ) {
			$GLOBALS["igiyvhk"]        = "row";
			$GLOBALS["bqrrxyqfz"]      = "arr";
			${$GLOBALS["bqrrxyqfz"]}[] = ${$GLOBALS["igiyvhk"]};
		}
		return $arr;
	}
	function dbinsertid( )
	{
		return mysql_insert_id();
	}
	function dbval( $sql )
	{
		if ( is_null( $this->dbx ) )
			$this->dbconnect();
		$GLOBALS["tzvpxbdtkkk"]  = "result";
		$result = mysql_query( $this->cleanSQL( $sql), $this->dbx );
		if ( !$result )
			return;
		$x = mysql_fetch_row( ${$GLOBALS["tzvpxbdtkkk"]} );
		return $x[0];
	}
	function checkmobile( )
	{
		$GLOBALS["ubfvraak"]    = "useragent";
		${$GLOBALS["ubfvraak"]} = $_SERVER["HTTP_USER_AGENT"];
		$tuodbpkhxw             = "useragent";
		if ( preg_match( "/android.+mobile|avantgo|bada\\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i", $useragent ) || preg_match( "/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\\/)|klon|kpt |kwc\\-|kyo(c|k)|le(no|xi)|lg( g|\\/(k|l|u)|50|54|e\-|e\\/|\\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(di|rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\\-|oo|p\-)|sdk\\/|se(c(\\-|0|1)|47|mc|nd|ri)|sgh\\-|shar|sie(\\-|m)|sk\\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\\-9|up(\\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|xda(\\-|2|g)|yas\-|your|zeto|zte\\-/i", substr( ${$tuodbpkhxw}, 0, 4 ) ) )
			return true;
		else
			return false;
	}
	function getIpAddress( )
	{
		return ( empty( $_SERVER["HTTP_CLIENT_IP"] ) ? ( empty( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ? $_SERVER["REMOTE_ADDR"] : $_SERVER["HTTP_X_FORWARDED_FOR"] ) : $_SERVER["HTTP_CLIENT_IP"] );
	}
	function getExtension( $str )
	{
		$ibjdwjbgjwi           = "str";
		$GLOBALS["tcgyxbomdy"] = "i";
		$GLOBALS["lpfohnuqu"]  = "ext";
		$GLOBALS["tvcwqjbr"]   = "i";
		$GLOBALS["quqnnx"]     = "l";
		$i = strrpos( ${$ibjdwjbgjwi}, "." );
		if ( !${$GLOBALS["tcgyxbomdy"]} ) {
			return "";
		}
		$l  = strlen( $str ) - $i;
		${$GLOBALS["lpfohnuqu"]} = substr( $str, ${$GLOBALS["tvcwqjbr"]} + 1, ${$GLOBALS["quqnnx"]} );
		return $ext;
	}
	function uploadNewFile( $fieldname, $allowedExt, $uploadsDirectory )
	{
		$GLOBALS["whdvxdn"]     = "errors";
		$GLOBALS["dmmiybitlnh"] = "fieldname";
		$GLOBALS["ukrtywn"]     = "errors";
		$mxxwhiyrlbq            = "fieldname";
		${$GLOBALS["ukrtywn"]}  = array(
			1 => "Selected file is too large!",
			2 => "Selected file is too large!",
			3 => "Incomplete file upload!",
			4 => "No file was selected!" 
		);
		$quciycczyfj            = "uploadFilename";
		$GLOBALS["lveypiimj"]   = "now";
		if ( !( $_FILES[$fieldname]["error"] == 0 ) )
			return "Error - " . ${$GLOBALS["whdvxdn"]}[$_FILES[${$GLOBALS["dmmiybitlnh"]}]["error"]];
		$GLOBALS["rhdqgzi"] = "uploadsDirectory";
		if ( !( @is_uploaded_file( $_FILES[${$mxxwhiyrlbq}]["tmp_name"] ) ) )
			return "Error - File is not an HTTP upload!";
		$rlsjhtvxsl = "fieldname";
		if ( !empty( $allowedExt ) ) {
			$GLOBALS["goyxuhxeghik"] = "pos";
			$GLOBALS["foyfcvccvkg"]  = "fieldname";
			$ext    = $this->getExtension( $_FILES[${$GLOBALS["foyfcvccvkg"]}]["name"] );
			$pos   = strripos( $allowedExt, $ext );
			if ( ${$GLOBALS["goyxuhxeghik"]} === false )
				return "Error - Invalid file type!";
		}
		${$GLOBALS["lveypiimj"]} = time();
		while ( file_exists( $uploadFilename = ${$GLOBALS["rhdqgzi"]} . $now . "-" . $_FILES[$fieldname]["name"] ) ) {
			$now++;
		}
		if ( !( move_uploaded_file( $_FILES[${$rlsjhtvxsl}]["tmp_name"], ${$quciycczyfj} ) ) )
			return "Error - Unable to move file uploaded file";
		else
			return $uploadFilename;
	}
	function alert( )
	{
		if ( !isset( $_SESSION["messageToAlert"] ) )
			$_SESSION["messageToAlert"] = array( );
		if ( !empty( $_SESSION["messageToAlert"] ) && count( $_SESSION["messageToAlert"] ) > 0 ) {
			if ( $this->setting( "alert" ) == 1 )
				echo "<script type=\"text/javascript\"> alert('" . str_replace( "'", "\'", implode( "\\n", $_SESSION["messageToAlert"] ) ) . "');</script>";
			echo "<div class=\"alert\">" . implode( "<br />", $_SESSION["messageToAlert"] ) . "</div>";
			$_SESSION["messageToAlert"] = array( );
		}
	}
	function makeAlert( $msg = "" )
	{
		$GLOBALS["admcdriadk"] = "msg";
		if ( empty( $msg ) )
			return;
		$_SESSION["messageToAlert"][] = ${$GLOBALS["admcdriadk"]};
	}
	function countAlert( )
	{
		return count( $_SESSION["messageToAlert"] );
	}
	function urlPost( $site, $vars, $headers = 0 )
	{
		$utuqqot             = "site";
		$GLOBALS["tvypgksi"] = "ch";
		$wxnvjlccnsjv        = "ch";
		if ( is_array( $vars ) ) {
			$GLOBALS["tdkpcdev"]    = "temp";
			$cesundnp               = "key";
			${$GLOBALS["tdkpcdev"]} = array( );
			$utgxlfenr              = "temp";
			foreach ( $vars as ${$cesundnp} => $value )
				$temp[] = $key . "=" . $value;
			$vars = implode( "&", ${$utgxlfenr} );
		}
		${$wxnvjlccnsjv}        = curl_init( ${$utuqqot} );
		$GLOBALS["byuxvjsxtvt"] = "response";
		if ( ${$GLOBALS["tvypgksi"]} ) {
			$GLOBALS["ckewdmacfqv"] = "ch";
			$epyzdxjcuj             = "ch";
			$GLOBALS["ruaksd"]      = "headers";
			$klykzpstv              = "ch";
			curl_setopt( ${$GLOBALS["ckewdmacfqv"]}, CURLOPT_POST, 1 );
			$krnhwskap = "ch";
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $vars );
			$qfdxquabn = "response";
			curl_setopt( ${$krnhwskap}, CURLOPT_FOLLOWLOCATION, 1 );
			$mxkkhjpkg = "ch";
			curl_setopt( ${$epyzdxjcuj}, CURLOPT_MAXREDIRS, 5 );
			curl_setopt( $ch, CURLOPT_HEADER, $headers );
			curl_setopt( $ch, CURLOPT_USERAGENT, "SMS Portal Creator" );
			$eeroovnyk = "response";
			curl_setopt( ${$klykzpstv}, CURLOPT_REFERER, "http://smsportalcreator.com" );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			${$qfdxquabn} = curl_exec( ${$mxkkhjpkg} );
			if ( ${$GLOBALS["ruaksd"]} )
				$response = explode( "\r\n\r\n", ${$eeroovnyk}, 2 );
		} else
			$response = "";
		return ${$GLOBALS["byuxvjsxtvt"]};
	}
	function URLRequest( $url_full, $protocol = "GET" )
	{
		$vnulnueeo             = "url";
		$mwtojesvegdv          = "port";
		$GLOBALS["axhgtte"]    = "url";
		$GLOBALS["gcydohgjzl"] = "url";
		$rsufgqta              = "port";
		$GLOBALS["futnviv"]    = "url";
		${$vnulnueeo}          = parse_url( $url_full );
		$GLOBALS["xlxdhcs"]    = "url";
		${$mwtojesvegdv}       = ( empty( $url["port"] ) ) ? false : true;
		if ( !${$rsufgqta} ) {
			$pxjsagcjnr = "url";
			if ( $url["scheme"] == "http" ) {
				$GLOBALS["meolqrtp"]            = "url";
				${$GLOBALS["meolqrtp"]}["port"] = 80;
			} elseif ( ${$pxjsagcjnr}["scheme"] == "https" ) {
				$url["port"] = 443;
			}
		}
		${$GLOBALS["axhgtte"]}["query"]   = empty( $url["query"] ) ? "" : ${$GLOBALS["futnviv"]}["query"];
		${$GLOBALS["gcydohgjzl"]}["path"] = empty( $url["path"] ) ? "" : ${$GLOBALS["xlxdhcs"]}["path"];
		if ( function_exists( "curl_init" ) ) {
			$ouewgqgi = "protocol";
			if ( ${$ouewgqgi} == "GET" ) {
				$GLOBALS["yfjqlg"]        = "content";
				$GLOBALS["hkljhosvytps"]  = "url";
				$xkcfdlds                 = "url";
				$ch = curl_init( ${$xkcfdlds}["protocol"] . $url["host"] . ${$GLOBALS["hkljhosvytps"]}["path"] . "?" . $url["query"] );
				if ( $ch ) {
					$GLOBALS["jriwegt"]  = "ch";
					$GLOBALS["wjjydipm"] = "ch";
					curl_setopt( ${$GLOBALS["wjjydipm"]}, CURLOPT_RETURNTRANSFER, true );
					curl_setopt( ${$GLOBALS["jriwegt"]}, CURLOPT_HEADER, 0 );
					$GLOBALS["aqzretxe"] = "ch";
					$uhwbpuhj            = "content";
					$utwenoglqc          = "url";
					if ( $port )
						curl_setopt( $ch, CURLOPT_PORT, ${$utwenoglqc}["port"] );
					$GLOBALS["ryeppiouk"] = "ch";
					${$uhwbpuhj}          = curl_exec( ${$GLOBALS["aqzretxe"]} );
					curl_close( ${$GLOBALS["ryeppiouk"]} );
				} else
					${$GLOBALS["yfjqlg"]} = "";
			} else {
				$vsgdocsa           = "ch";
				$GLOBALS["vsvlmvl"] = "url";
				${$vsgdocsa}        = curl_init( $url["protocol"] . ${$GLOBALS["vsvlmvl"]}["host"] . $url["path"] );
				if ( $ch ) {
					$GLOBALS["vpjmgjqfqc"] = "url";
					$syqdjw                = "ch";
					$jxyhwbgb              = "ch";
					$GLOBALS["jxaoczgq"]   = "ch";
					curl_setopt( $ch, CURLOPT_POST, 1 );
					curl_setopt( $ch, CURLOPT_POSTFIELDS, ${$GLOBALS["vpjmgjqfqc"]}["query"] );
					curl_setopt( ${$jxyhwbgb}, CURLOPT_FOLLOWLOCATION, 1 );
					curl_setopt( ${$GLOBALS["jxaoczgq"]}, CURLOPT_MAXREDIRS, 5 );
					curl_setopt( ${$syqdjw}, CURLOPT_HEADER, 0 );
					$GLOBALS["myybdcwlcn"] = "ch";
					curl_setopt( $ch, CURLOPT_USERAGENT, "SMS Portal Creator" );
					$qtuyxgevced = "ch";
					$tqsfjmqsk   = "port";
					if ( ${$tqsfjmqsk} )
						curl_setopt( $ch, CURLOPT_PORT, $url["port"] );
					curl_setopt( ${$GLOBALS["myybdcwlcn"]}, CURLOPT_TIMEOUT, 60 );
					curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
					$content = curl_exec( $ch );
					curl_close( ${$qtuyxgevced} );
				} else
					$content = "";
			}
		} else if ( function_exists( "fsockopen" ) ) {
			$bvwbhjqpudb                           = "url";
			$GLOBALS["nelelgdfg"]                  = "h";
			$GLOBALS["oysyvuvzibn"]                = "headers";
			$xosafyu                               = "getdata_str";
			$url["protocol"] = $url["scheme"] . "://";
			$gfvqtfpfn                             = "h";
			$GLOBALS["ulcluer"]                    = "url";
			$GLOBALS["yisdme"]                     = "protocol";
			$gsulpkoth                             = "url";
			$GLOBALS["hmcuhyfjrr"]                 = "eol";
			$adfchhspni                            = "postdata_str";
			$tsumuxxoi                             = "url";
			$GLOBALS["ygfobszacm"]                 = "errno";
			${$GLOBALS["hmcuhyfjrr"]}              = "\r\n";
			${$gfvqtfpfn}                          = "";
			$postdata_str                 = "";
			$getdata_str              = "";
			$GLOBALS["nkchvh"]                     = "errstr";
			$wssqjqjxzlso                          = "fp";
			if ( ${$GLOBALS["yisdme"]} == "POST" ) {
				$GLOBALS["ykmbwqrhidlo"] = "eol";
				$GLOBALS["rwmfixwwp"]    = "eol";
				$fhxbtwlju               = "h";
				$GLOBALS["trrqagv"]      = "postdata_str";
				${$fhxbtwlju}            = "Content-Type: text/html" . ${$GLOBALS["rwmfixwwp"]} . "Content-Length: " . strlen( $url["query"] ) . ${$GLOBALS["ykmbwqrhidlo"]};
				${$GLOBALS["trrqagv"]}   = $url["query"];
			} else
				${$xosafyu} = "?" . ${$bvwbhjqpudb}["query"];
			$GLOBALS["aprefhmtggt"]    = "eol";
			$wmpcpljihx                = "url";
			${$GLOBALS["oysyvuvzibn"]} = "$protocol " . $url["protocol"] . ${$tsumuxxoi}["host"] . $url["path"] . $getdata_str . " HTTP/1.0" . $eol . "Host: " . ${$wmpcpljihx}["host"] . $eol . "Referer: " . $url["protocol"] . ${$GLOBALS["ulcluer"]}["host"] . ${$gsulpkoth}["path"] . $eol . ${$GLOBALS["nelelgdfg"]} . "Connection: Close" . $eol . ${$GLOBALS["aprefhmtggt"]} . ${$adfchhspni};
			$GLOBALS["vfyubeo"]        = "url";
			$fp  = fsockopen( ${$GLOBALS["vfyubeo"]}["host"], $url["port"], ${$GLOBALS["ygfobszacm"]}, ${$GLOBALS["nkchvh"]}, 60 );
			if ( ${$wssqjqjxzlso} ) {
				$nfeshdqm    = "headers";
				$ughtxogyeks = "content";
				$blwfrh      = "content";
				$vfmaxdefqd  = "fp";
				$ytlsrsvy    = "pattern";
				$jdqcflsfn   = "fp";
				fputs( ${$vfmaxdefqd}, ${$nfeshdqm} );
				${$ughtxogyeks} = "";
				while ( !feof( ${$jdqcflsfn} ) ) {
					$content .= fgets( $fp, 128 );
				}
				fclose( $fp );
				${$ytlsrsvy}               = "/^.*\r\n\r\n/s";
				$content = preg_replace( $pattern, "", ${$blwfrh} );
			}
		} else {
			try {
				$jobhvhp = "protocol";
				if ( ${$jobhvhp} == "GET" )
					return file_get_contents( $url_full );
				else {
					$qcmjyzx                   = "site";
					$ctkyvupfhny               = "site";
					$GLOBALS["vtnsumtbsks"]    = "url_full";
					${$qcmjyzx}                = explode( "?", ${$GLOBALS["vtnsumtbsks"]}, 2 );
					$GLOBALS["gjrcllsfpv"]     = "site";
					$content = file_get_contents( ${$ctkyvupfhny}[0], false, stream_context_create( array(
						"http" => array(
							"method" => "POST",
							"header" => "Connection: close\r\nContent-Length: " . strlen( ${$GLOBALS["gjrcllsfpv"]}[1] ) . "\r\n",
							"content" => $site[1] 
						) 
					) ) );
				}
			}
			catch ( Exception $g ) {
				$GLOBALS["gllvuvl"]    = "content";
				${$GLOBALS["gllvuvl"]} = "";
			}
		}
		return $content;
	}
	function alterPhone( $gsm )
	{
		$GLOBALS["urnedfebtrm"]     = "outArray";
		$GLOBALS["rzygxofirfa"]     = "gsm";
		$GLOBALS["ueblqfajftx"]     = "gsm";
		$GLOBALS["yocntv"]          = "gsm";
		$array    = is_array( ${$GLOBALS["rzygxofirfa"]} );
		$dcpxymel                   = "gsm";
		$GLOBALS["jmvedlogcxol"]    = "outArray";
		$ehlwbshp                   = "array";
		$xlrcripst                  = "gsm";
		${$GLOBALS["yocntv"]}       = ( ${$ehlwbshp} ) ? ${$GLOBALS["ueblqfajftx"]} : explode( ",", ${$xlrcripst} );
		$homeCountry   = $this->setting( "countryCode" );
		${$GLOBALS["jmvedlogcxol"]} = array( );
		foreach ( ${$dcpxymel} as $item ) {
			if ( !empty( $item ) ) {
				$gtdbukppwde           = "item";
				$GLOBALS["mgsuiyx"]    = "item";
				$GLOBALS["ojcnzglao"]  = "item";
				$GLOBALS["fhusome"]    = "homeCountry";
				$agjhftjbwmt           = "item";
				${$GLOBALS["mgsuiyx"]} = explode( "=", $item );
				$GLOBALS["ftpervvvy"]  = "item";
				$hqojweitjy            = "outArray";
				${$gtdbukppwde}        = $item[0];
				if ( substr( $item, 0, 1 ) == "+" )
					$item = substr( ${$GLOBALS["ftpervvvy"]}, 1 );
				if ( substr( $item, 0, 3 ) == "009" )
					$item = substr( ${$agjhftjbwmt}, 3 );
				${$hqojweitjy}[] = ( substr( ${$GLOBALS["ojcnzglao"]}, 0, 1 ) == "0" ) ? ${$GLOBALS["fhusome"]} . substr( $item, 1 ) : $item;
			}
		}
		return ( $array ) ? $outArray : implode( ",", ${$GLOBALS["urnedfebtrm"]} );
	}
	function correctCommas( $csv )
	{
		$okyyrhpm              = "csv";
		$fzjzpmfewj            = "inpt";
		$inpt = array(
			"\r",
			"\n",
			" ",
			";",
			":",
			"\"",
			".",
			"'",
			"`",
			"\t",
			"(",
			")",
			"<",
			">",
			"{",
			"}",
			"#",
			"\r\n",
			"-",
			"_",
			"?",
			"+",
			"=" 
		);
		$qhycnsnsduv           = "csv";
		$uwjbgwtntchm          = "csv";
		${$okyyrhpm}           = str_replace( ${$fzjzpmfewj}, ",", ${$uwjbgwtntchm} );
		while ( strpos( $csv, ",," ) !== false ) {
			$GLOBALS["trtszgiymm"]     = "csv";
			$csv = str_replace( ",,", ",", ${$GLOBALS["trtszgiymm"]} );
			$csv = str_replace( ",,", ",", $csv );
		}
		return trim( ${$qhycnsnsduv}, "," );
	}
	function uniqueArray( $myArray )
	{
		$qrfwbiapgd              = "myArray";
		$gdswssxc                = "myArray";
		$fdiyqbogob              = "array";
		$GLOBALS["lcwtrdruhwd"]  = "array";
		$GLOBALS["rtqhkrnnoxr"]  = "myArray";
		$GLOBALS["ahiurhejs"]    = "myArray";
		$array = is_array( ${$gdswssxc} );
		$myArray   = ( ${$GLOBALS["lcwtrdruhwd"]} ) ? $myArray : explode( ",", ${$GLOBALS["rtqhkrnnoxr"]} );
		$myArray   = array_flip( array_flip( array_reverse( $myArray, true ) ) );
		return ( ${$fdiyqbogob} ) ? ${$GLOBALS["ahiurhejs"]} : implode( ",", ${$qrfwbiapgd} );
	}
	function filterNos( $csv )
	{
		$GLOBALS["oawogut"]        = "csv";
		$GLOBALS["rlnurweelosn"]   = "validArray";
		$GLOBALS["zdhingeo"]       = "csv";
		$array   = is_array( ${$GLOBALS["oawogut"]} );
		$GLOBALS["eeckdcwk"]       = "csv";
		$GLOBALS["hkbablng"]       = "array";
		$csv = ( ${$GLOBALS["hkbablng"]} ) ? $csv : explode( ",", ${$GLOBALS["eeckdcwk"]} );
		$validArray      = array( );
		foreach ( ${$GLOBALS["zdhingeo"]} as $value ) {
			$l = strlen( $value );
			if ( $l >= 7 && $l <= 15 )
				$validArray[] = $value;
		}
		return ( $array ) ? $validArray : implode( ",", ${$GLOBALS["rlnurweelosn"]} );
	}
	function customizeMsg( $msg, $username = '', $name = '', $email = '', $GSM = '', $units = 'x', $orderAmt = '', $orderUnits = '' )
	{
		$tlsmfvodewz              = "inpt";
		$vdwykywykx               = "oupt";
		$GLOBALS["srynxespm"]     = "oupt";
		$okknnwprur               = "inpt";
		$GLOBALS["jpmgmxvuf"]     = "name";
		${$tlsmfvodewz}           = array(
			"@@username@@",
			"@@name@@",
			"@@email@@",
			"@@GSM@@",
			"@@units@@",
			"@@orderAmt@@",
			"@@orderUnits@@" 
		);
		${$vdwykywykx}            = array(
			$username,
			${$GLOBALS["jpmgmxvuf"]},
			$email,
			$GSM,
			$units,
			$orderAmt,
			$orderUnits 
		);
		$msg = str_ireplace( ${$okknnwprur}, ${$GLOBALS["srynxespm"]}, $msg );
		return $msg;
	}
	function mceil( $x )
	{
		$yteazqoc               = "c";
		$GLOBALS["dfojxpyx"]    = "c";
		$tksrcierjjck           = "y";
		$GLOBALS["zgnlkbord"]   = "c";
		$GLOBALS["srkuerwb"]    = "y";
		$hmtxpc                 = "y";
		${$GLOBALS["dfojxpyx"]} = 1;
		return ( ( ${$tksrcierjjck} = $x / ${$GLOBALS["zgnlkbord"]} ) == ( $y = (int) ${$hmtxpc} ) ) ? $x : ( $x >= 0 ? ++${$GLOBALS["srkuerwb"]} : --$y ) * ${$yteazqoc};
	}
	function generatePassword( $length = 10 )
	{
		$GLOBALS["hlhbfnj"]         = "p";
		$characters      = "QWERTYU23456789PLKJHGFDSAZXCVBNM ";
		$string = "";
		$ruivldbh                   = "p";
		for ( $p = 0; ${$GLOBALS["hlhbfnj"]} < $length; ${$ruivldbh}++ ) {
			$jupkneng  = "string";
			$vdqlyqqkr = "characters";
			${$jupkneng} .= $characters[mt_rand( 0, strlen( ${$vdqlyqqkr} ) )];
		}
		return $string;
	}
	function isValidEmail( $email )
	{
		$jcrian = "email";
		if ( !preg_match( "/^[_\.0-9a-zA-Z-]+@([0-9a-zA-Z][0-9a-zA-Z-]+\\.)+[a-zA-Z]{2,6}\$/i", ${$jcrian} ) ) {
			return false;
		}
		return true;
	}
	function datedsub( $first_date, $second_date, $f = "d" )
	{
		$GLOBALS["jybmbh"]         = "d2";
		$GLOBALS["fvhezunyoob"]    = "delta";
		$GLOBALS["emmpvc"]         = "num";
		$iyydplrkuyl               = "first_date";
		$qrpxtqqjq                 = "d2";
		$rtlkfj                    = "num";
		$dl = strtotime( ${$iyydplrkuyl} );
		$quclwoee                  = "num";
		$GLOBALS["huatusqf"]       = "delta";
		$GLOBALS["gywhusc"]        = "delta";
		$GLOBALS["fhehtbymrla"]    = "num";
		${$GLOBALS["jybmbh"]}      = strtotime( $second_date );
		$jkmvravrh                 = "f";
		$ipvhuenbb                 = "delta";
		$GLOBALS["chwhtaim"]       = "delta";
		$GLOBALS["pjvkdstdh"]      = "d1";
		${$GLOBALS["fvhezunyoob"]} = ${$qrpxtqqjq} - ${$GLOBALS["pjvkdstdh"]};
		switch ( ${$jkmvravrh} ) {
			case "d":
				${$rtlkfj} = ( ${$GLOBALS["gywhusc"]} / 86400 );
				break;
			case "h":
				${$GLOBALS["emmpvc"]} = ( ${$GLOBALS["huatusqf"]} / ( 3600 ) );
				break;
			case "m":
				$num = ( ${$GLOBALS["chwhtaim"]} / ( 60 ) );
				break;
			default:
				${$quclwoee} = ( ${$ipvhuenbb} / 86400 );
		}
		return round( ${$GLOBALS["fhehtbymrla"]}, 0, PHP_ROUND_HALF_UP );
	}
	function thisURL( )
	{
		$pageURL = "http";
		$qxxxdrhcm             = "pageURL";
		if ( isset( $_SERVER["HTTPS"] ) && $_SERVER["HTTPS"] == "on" ) {
			$pageURL .= "s";
		}
		${$qxxxdrhcm} .= "://";
		if ( $_SERVER["SERVER_PORT"] != "80" ) {
			$vluokvwwhrmk = "pageURL";
			${$vluokvwwhrmk} .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
		} else {
			$GLOBALS["ebouxv"] = "pageURL";
			${$GLOBALS["ebouxv"]} .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
		}
		return $pageURL;
	}
	function pageReload( )
	{
		die( "<meta http-equiv=\"refresh\" content=\"0\"/> <script type=\"text/javascript\">window.location.href=window.location.href;</script><input type=\"button\" value=\"      [PROCEED]      \" onClick=\"window.location.href=window.location.href\">" );
	}
	function fetchFiles( $directory, $filter = "" )
	{
		$GLOBALS["jnlhcpvenbmn"] = "directory";
		$ucfbfxrgq               = "handler";
		$khtnmbb                 = "handler";
		$cusedbtl                = "results";
		$juahqokqk               = "file";
		${$cusedbtl}             = array( );
		$handler    = opendir( ${$GLOBALS["jnlhcpvenbmn"]} );
		while ( ${$juahqokqk} = readdir( ${$ucfbfxrgq} ) ) {
			$yglrmmnmlpq         = "file";
			$GLOBALS["dxccpzfa"] = "file";
			if ( ${$GLOBALS["dxccpzfa"]} != "." && ${$yglrmmnmlpq} != ".." ) {
				$GLOBALS["pjfkodsgwg"] = "filter";
				if ( ( !empty( $filter ) && stripos( $file, $filter ) !== false ) || empty( ${$GLOBALS["pjfkodsgwg"]} ) )
					$results[] = $file;
			}
		}
		closedir( ${$khtnmbb} );
		return $results;
	}
	function autoGrid( $tablename, $primaryKey, $query, $optional = array( ) )
	{
		$vmlnvcrdwhf                                  = "optional";
		$GLOBALS["txjxpvqrpla"]                       = "numrows";
		$GLOBALS["nvixsqenl"]                         = "paginationArray";
		$vowvvhugscfk                                 = "rowsperpage";
		$GLOBALS["imsyxwfhpmci"]                      = "x";
		$pxjduxv                                      = "range";
		$nqegme                                       = "optional";
		$GLOBALS["pgtzqszunmr"]                       = "optional";
		$knapnysv                                     = "paginationArray";
		$GLOBALS["tbrxmb"]                            = "paginationArray";
		$tfhjcrr                                      = "url";
		$upjgmlnrt                                    = "optional";
		$GLOBALS["uaiowj"]                            = "this_page";
		$lndkmirdn                                    = "paginationArray";
		$GLOBALS["mzqorbxdkfr"]                       = "this_page";
		$GLOBALS["mlvokfjyoqf"]                       = "rowsperpage";
		$GLOBALS["tfqxnojqxyv"]                       = "paginationArray";
		$axkmpdehykcf                                 = "paginationArray";
		$gymlligjmxii                                 = "range";
		$GLOBALS["jkmsdibfoo"]                        = "datagrid";
		$GLOBALS["zvrjjaes"]                          = "optional";
		$hdwjoegupvqh                                 = "currentpage";
		$GLOBALS["jwfbwsng"]                          = "currentpage";
		$optional["return"]          = empty( $optional["return"] ) ? 1 : ${$upjgmlnrt}["return"];
		$GLOBALS["wbgehirb"]                          = "rowsperpage";
		$optional["page_link_class"] = ( empty( ${$GLOBALS["pgtzqszunmr"]}["page_link_class"] ) ) ? "" : "class=\"" . $optional["page_link_class"] . "\"";
		${$GLOBALS["jkmsdibfoo"]}                     = array( );
		$brgxkjtc                                     = "this_page";
		$kkjigyjcpyux                                 = "paginationArray";
		$xxknypahw                                    = "paginationArray";
		$vtzjjou                                      = "numrows";
		$GLOBALS["tfkxbcyyp"]                         = "dataTable";
		if ( empty( $optional["where_condition"] ) )
			${$GLOBALS["zvrjjaes"]}["where_condition"] = "1=1";
		$evbnsjvrzrc         = "optional";
		$GLOBALS["ljvysxli"] = "this_page";
		$GLOBALS["vqzmeghp"] = "range";
		$ietqdithlw          = "pagination";
		if ( is_array( ${$vmlnvcrdwhf}["search_array"] ) ) {
			if ( isset( $_POST["srch_field"] ) )
				$_SESSION["srch_field"] = $_POST["srch_field"];
			if ( isset( $_POST["search_word"] ) )
				$_SESSION["search_word"] = $_POST["search_word"];
			$GLOBALS["otnaaimzh"] = "searchForm";
			$xzbxjsbrmz           = "searchForm";
			if ( isset( $_POST["search_now"] ) && $_POST["search_now"] == "Reset" ) {
				$_SESSION["query_condition"] = "1=1";
				$_GET["currentpage"]         = 1;
				unset( $_SESSION["srch_field"] );
				unset( $_SESSION["search_word"] );
			} else if ( isset( $_POST["search_now"] ) && $_POST["search_now"] == "Search" ) {
				$_SESSION["query_condition"] = " " . $_POST["srch_field"] . " LIKE '%" . $_POST["search_word"] . "%'";
				$_GET["currentpage"]         = 1;
			} else
				$_SESSION["query_condition"] = ( !empty( $_SESSION["query_condition"] ) ) ? $_SESSION["query_condition"] : "1=1";
			if ( isset( $_SESSION["last_page_visited"] ) && $_SESSION["last_page_visited"] != $_GET["view"] ) {
				$optional["where_condition"] = explode( $_SESSION["query_condition"], $optional["where_condition"] );
				$optional["where_condition"] = implode( "1=1", $optional["where_condition"] );
				$_SESSION["query_condition"]                  = "1=1";
				$_SESSION["last_page_visited"]                = $_GET["view"];
				unset( $_SESSION["srch_field"] );
				unset( $_SESSION["search_word"] );
			}
			$searchForm = "<div class=\"full\" align=\"right\">" . "<form name=\"searchForm\" method=\"post\" action=\"\">" . "<input type=\"text\" class=\"searchbox\" name=\"search_word\"  placeholder=\"Search word\" value=\"";
			if ( isset( $_POST["search_now"] ) && $_POST["search_now"] != "Reset" )
				$searchForm .= $_SESSION["search_word"];
			$jmgvnhnxru = "key";
			${$GLOBALS["otnaaimzh"]} .= "\" /><select name=\"srch_field\" >";
			foreach ( $optional["search_array"] as ${$jmgvnhnxru} => $value ) {
				$cdhshheonon                = "key";
				$sel = "";
				if ( isset( $_SESSION["srch_field"] ) )
					$sel = ( $_SESSION["srch_field"] == ${$cdhshheonon} ) ? " selected=\"selected\"" : "";
				$gjhfprgh = "searchForm";
				${$gjhfprgh} .= "<option value='$key' $sel > $value </option>";
			}
			${$xzbxjsbrmz} .= "</select>" . "<input type=\"submit\" class=\"myButton\" name=\"search_now\" value=\"Search\" />" . "<input type=\"submit\" class=\"myButton\" name=\"search_now\" value=\"Reset\" />" . "</form>" . "</div>";
		} else {
			$searchForm      = "";
			$_SESSION["query_condition"] = "";
		}
		$fhwvdijk                                     = "range";
		$_SESSION["query_condition"]                  = empty( $_SESSION["query_condition"] ) ? "1=1" : $_SESSION["query_condition"];
		$optional["where_condition"] = ${$evbnsjvrzrc}["where_condition"] . " AND " . $_SESSION["query_condition"];
		$paginationArray                   = array( );
		$ibfrvqikp                                    = "rowsperpage";
		$this_page                       = parse_url( $this->thisURL() );
		${$brgxkjtc}["path"]                          = ( substr( ${$GLOBALS["uaiowj"]}["path"], -1 ) == "/" || substr( $this_page["path"], -4, 1 ) == "." ) ? $this_page["path"] : $this_page["path"] . "/";
		$this_page                       = $this_page["scheme"] . "://" . ${$GLOBALS["ljvysxli"]}["host"] . ${$GLOBALS["mzqorbxdkfr"]}["path"];
		parse_str( $_SERVER["QUERY_STRING"], ${$tfhjcrr} );
		$rowsperpage = empty( $optional["page_size"] ) ? $this->setting( "rows_per_page" ) : $optional["page_size"];
		if ( ${$GLOBALS["wbgehirb"]} <= 1 )
			${$GLOBALS["mlvokfjyoqf"]} = 10;
		${$pxjduxv}              = empty( $optional["range"] ) ? 2 : ${$nqegme}["range"];
		$numrows = "select count(*) as cnt from $tablename ";
		${$vtzjjou} .= ( stripos( $optional["where_condition"], "WHERE" ) === false ) ? "WHERE " . $optional["where_condition"] : $optional["where_condition"];
		$numrows   = $this->dbval( $numrows );
		$totalpages = ceil( $numrows / ${$ibfrvqikp} );
		$GLOBALS["hfsdqna"]        = "totalpages";
		$currentpage     = isset( $_GET["currentpage"] ) ? $_GET["currentpage"] : 1;
		if ( $currentpage > ${$GLOBALS["hfsdqna"]} )
			$currentpage = $totalpages;
		$khbgsnjhzd = "grid";
		if ( $currentpage < 1 )
			$currentpage = 1;
		$offset           = ( $currentpage - 1 ) * $rowsperpage;
		$bjqhkujlr                           = "x";
		$paginationArray["start"] = ( $numrows == 0 ) ? 0 : $offset + 1;
		$GLOBALS["auyhxpgdps"]               = "rowsperpage";
		$paginationArray["total"] = ${$GLOBALS["txjxpvqrpla"]};
		${$knapnysv}["size"]                 = ( ( $paginationArray["start"] + ${$vowvvhugscfk} ) > $paginationArray["total"] ) ? ( ${$xxknypahw}["total"] - ${$kkjigyjcpyux}["start"] ) + 1 : ${$GLOBALS["auyhxpgdps"]};
		$pagination               = "Pages: ";
		if ( $currentpage - $range > 1 ) {
			$ilckrfyn                   = "url";
			${$ilckrfyn}["currentpage"] = 1;
			$pagination .= "<a {$optional['page_link_class']} href='$this_page?" . http_build_query( $url ) . "'><u>1</u></a>...";
		}
		for ( $x = ( $currentpage - ${$fhwvdijk} ); ${$bjqhkujlr} < ( ( ${$GLOBALS["jwfbwsng"]} + ${$GLOBALS["vqzmeghp"]} ) + 1 ); ${$GLOBALS["imsyxwfhpmci"]}++ ) {
			$cekpsfmcwwwc = "x";
			$kdnyvjxl     = "totalpages";
			if ( ( ${$cekpsfmcwwwc} > 0 ) && ( $x <= ${$kdnyvjxl} ) ) {
				$uiznsssan                                = "x";
				$GLOBALS["fkomkhb"]                       = "x";
				$bovrswmula                               = "currentpage";
				$url["currentpage"] = $x;
				$pagination .= ( ${$uiznsssan} == ${$bovrswmula} ) ? ${$GLOBALS["fkomkhb"]} : " <a {$optional['page_link_class']} href='$this_page?" . http_build_query( $url ) . "'><u>$x</u></a> ";
			}
		}
		if ( ${$hdwjoegupvqh} + ${$gymlligjmxii} < $totalpages ) {
			$mlqpixt                                  = "totalpages";
			$url["currentpage"] = ${$mlqpixt};
			$GLOBALS["vhqzwchdx"]                     = "url";
			$pagination .= "... <a {$optional['page_link_class']} href='$this_page?" . http_build_query( ${$GLOBALS["vhqzwchdx"]} ) . "'><u>$totalpages</u></a> ";
		}
		${$GLOBALS["tfqxnojqxyv"]}["links"] = ( $totalpages > 1 ) ? ${$ietqdithlw} : "";
		if ( ${$axkmpdehykcf}["size"] > 0 ) {
			$GLOBALS["fcnjclklqu"]  = "objectIDs";
			$iccnmjyudf             = "optional";
			$GLOBALS["vsispxykk"]   = "sql";
			$esogihlcaoe            = "optional";
			$xwwykphvhj             = "paginationArray";
			$gyerhumvwkw            = "sql";
			$wwlgpl                 = "i";
			$GLOBALS["voxiqaeqsrd"] = "optional";
			$qeekolvhlzh            = "optional";
			$sql  = "SELECT $primaryKey FROM $tablename ";
			$kppnwqtfrv             = "query";
			${$GLOBALS["vsispxykk"]} .= ( stripos( ${$qeekolvhlzh}["where_condition"], "WHERE" ) === false ) ? "WHERE " . $optional["where_condition"] . " " : $optional["where_condition"] . " ";
			${$gyerhumvwkw} .= empty( $optional["order_by"] ) ? " ORDER BY $primaryKey DESC " : $optional["order_by"];
			$GLOBALS["rjsxkgliv"] = "sql";
			${$GLOBALS["rjsxkgliv"]} .= " LIMIT " . ( $paginationArray["start"] - 1 ) . "," . ${$xwwykphvhj}["size"];
			$GLOBALS["smgyofybk"]     = "cto";
			$GLOBALS["fmrtqdr"]       = "objectIDs";
			${$GLOBALS["fcnjclklqu"]} = $this->dbarray( $sql);
			$ids = array( );
			$ruurjucw                 = "cto";
			$eklnrn                   = "ids";
			$qcksndoojxey             = "query";
			$gvumkbedy                = "optional";
			if ( is_array( ${$GLOBALS["fmrtqdr"]} ) ) {
				$mtsgqhadlxf           = "value";
				$GLOBALS["dkbsjpekvf"] = "value";
				$lrsjdt                = "objectIDs";
				foreach ( ${$lrsjdt} as ${$mtsgqhadlxf} )
					$ids[] = ${$GLOBALS["dkbsjpekvf"]}[$primaryKey];
			}
			$GLOBALS["ungwhtxp"] = "query";
			${$eklnrn}           = "'" . implode( "','", $ids ) . "'";
			$query .= ( stripos( ${$kppnwqtfrv}, "FROM" ) == false ) ? " FROM " . $tablename : "";
			${$GLOBALS["ungwhtxp"]} .= ( stripos( $query, "WHERE" ) == false ) ? " WHERE " . ${$iccnmjyudf}["where_condition"] . " AND " : " AND ";
			${$qcksndoojxey} .= "$primaryKey IN ( $ids ) ";
			$query .= empty( ${$esogihlcaoe}["order_by"] ) ? " ORDER BY $primaryKey DESC " : ${$GLOBALS["voxiqaeqsrd"]}["order_by"];
			$tableObject   = $this->dbarray( $query );
			$n   = 0;
			$dataTable = "<table class='table'>";
			$dataTable2 = "";
			${$ruurjucw}               = count( $tableObject );
			for ( $i = 0; $i < ${$GLOBALS["smgyofybk"]}; ${$wwlgpl}++ ) {
				$htvpwkolau             = "optional";
				$GLOBALS["bvgkakod"]    = "row";
				$GLOBALS["xydvcnrt"]    = "i";
				${$GLOBALS["bvgkakod"]} = $tableObject[${$GLOBALS["xydvcnrt"]}];
				if ( $n == 0 && empty( ${$htvpwkolau}["template"] ) ) {
					$GLOBALS["hbmxwhfpo"]      = "value";
					$GLOBALS["rtwyjeniaoee"]   = "th";
					$GLOBALS["gojbfknqbcx"]    = "th";
					$ldnjdocxhi                = "dataTable";
					${$GLOBALS["gojbfknqbcx"]} = array( );
					foreach ( $row as $key => ${$GLOBALS["hbmxwhfpo"]} )
						${$GLOBALS["rtwyjeniaoee"]}[] = $key;
					${$ldnjdocxhi} .= "<tr class='th'><th>" . implode( "</th><th>", $th ) . "</th></tr>";
					$n += 1;
				}
				if ( empty( $optional["template"] ) ) {
					$ybqgsd                  = "n";
					$th = array( );
					foreach ( $row as $value ) {
						$lcbcwiktu              = "th";
						$GLOBALS["klleocqhasi"] = "value";
						$GLOBALS["supkujn"]     = "value";
						if ( is_numeric( ${$GLOBALS["supkujn"]} ) ) {
							$GLOBALS["ttedtmgwrx"]      = "th";
							$GLOBALS["jwnukejp"]        = "value";
							${$GLOBALS["ttedtmgwrx"]}[] = "<div align='right'>" . number_format( ${$GLOBALS["jwnukejp"]}, 2 ) . "</div>";
						} else
							${$lcbcwiktu}[] = ${$GLOBALS["klleocqhasi"]};
					}
					if ( ${$ybqgsd} >= 2 ) {
						$bqbeqnegty = "dataTable";
						$hznzxnsmmy = "n";
						${$bqbeqnegty} .= "<tr class='tr2'><td class='cell'>" . implode( "</td><td class='cell'>", $th ) . "</td></tr>";
						${$hznzxnsmmy} = 0;
					} else
						$dataTable .= "<tr class='tr1'><td class='cell'>" . implode( "</td><td class='cell'>", $th ) . "</td></tr>";
					$n += 1;
				} else {
					$utbxepo                = "optional";
					$GLOBALS["olxuxthqbas"] = "dataTable2";
					$tmp = ${$utbxepo}["template"];
					foreach ( $row as $key => $value ) {
						$GLOBALS["qashdshy"]    = "tmp";
						$tmp = str_replace( "@" . $key . "@", $value, ${$GLOBALS["qashdshy"]} );
					}
					${$GLOBALS["olxuxthqbas"]} .= $tmp;
				}
			}
			$dataTable .= "</table>";
			if ( !empty( ${$gvumkbedy}["template"] ) )
				$dataTable = $dataTable2;
		} else
			${$GLOBALS["tfkxbcyyp"]} = "";
		$grid = array( );
		$a     = "{$paginationArray['start']} - " . ( ${$lndkmirdn}["start"] + ${$GLOBALS["tbrxmb"]}["size"] - 1 ) . " of " . number_format( ${$GLOBALS["nvixsqenl"]}["total"] - 0 );
		if ( empty( $optional["search_template"] ) )
			$grid[] = "<table width=\"100%\" style=\"margin-top:10px;\" ><tr style=\"vertical-align:middle;\">" . "<td>$a</td><td>{$paginationArray['links']}</td><td align='right'>$searchForm</td></tr></table>";
		else {
			$GLOBALS["bxeifww"]                       = "optional";
			$qwqtvdwp                                 = "optional";
			$GLOBALS["nsjuld"]                        = "optional";
			$wfgsxhgshqd                              = "optional";
			$bdorscxb                                 = "paginationArray";
			$GLOBALS["jmvslsajpj"]                    = "a";
			${$GLOBALS["bxeifww"]}["search_template"] = str_replace( "@a@", ${$GLOBALS["jmvslsajpj"]}, ${$GLOBALS["nsjuld"]}["search_template"] );
			${$wfgsxhgshqd}["search_template"]        = str_replace( "@b@", ${$bdorscxb}["links"], ${$qwqtvdwp}["search_template"] );
			$grid[]               = str_replace( "@c@", $searchForm, $optional["search_template"] );
		}
		$grid[] = $dataTable;
		if ( $optional["return"] )
			return $grid;
		echo implode( "", ${$khbgsnjhzd} );
	}
	function cacheInfo( $smsClient, $data )
	{
		$GLOBALS["uydocbmfss"]    = "data";
		$GLOBALS["qoyhpmovhx"]    = "data";
		${$GLOBALS["uydocbmfss"]} = date( "Y-m-d h:i" ) . ": " . ${$GLOBALS["qoyhpmovhx"]};
		$this->dbquery( "UPDATE #__spcClient SET pendingNotifications = CONCAT(pendingNotifications,'#',$data) WHERE clientID = $smsClient" );
	}
	function validateDate( $date, $format = 'YYYY-MM-DD' )
	{
		$lufzgyotha             = "m";
		$lqfeewhay              = "date";
		$GLOBALS["djgqgtvkde"]  = "date";
		$ferholnd               = "date";
		$GLOBALS["doqbjjhz"]    = "d";
		$rvvfsdy                = "date";
		$jngvndirdu             = "m";
		$GLOBALS["kikmtzli"]    = "m";
		$GLOBALS["moioewthsej"] = "d";
		$wwcpflwms              = "date";
		$GLOBALS["zeospexjevl"] = "d";
		$ufmtvekfj              = "date";
		$GLOBALS["veyhwrj"]     = "d";
		$lnonmdg                = "y";
		$mbrviyndbvaw           = "d";
		$ixrxstjni              = "y";
		switch ( $format ) {
			case "YYYY/MM/DD":
			case "YYYY-MM-DD":
				list( ${$ixrxstjni}, $m, ${$GLOBALS["doqbjjhz"]} ) = preg_split( "/[-\.\/ ]/", ${$ufmtvekfj} );
				break;
			case "YYYY/DD/MM":
			case "YYYY-DD-MM":
				list( $y, $d, $m ) = preg_split( "/[-\.\/ ]/", ${$lqfeewhay} );
				break;
			case "DD-MM-YYYY":
			case "DD/MM/YYYY":
				list( $d, $m, $y ) = preg_split( "/[-\\.\\/ ]/", $date );
				break;
			case "MM-DD-YYYY":
			case "MM/DD/YYYY":
				list( $m, ${$GLOBALS["zeospexjevl"]}, $y ) = preg_split( "/[-\\.\/ ]/", ${$rvvfsdy} );
				break;
			case "YYYYMMDD":
				$y  = substr( $date, 0, 4 );
				${$GLOBALS["kikmtzli"]} = substr( ${$wwcpflwms}, 4, 2 );
				${$mbrviyndbvaw}        = substr( ${$ferholnd}, 6, 2 );
				break;
			case "YYYYDDMM":
				${$lnonmdg}            = substr( $date, 0, 4 );
				${$GLOBALS["veyhwrj"]} = substr( ${$GLOBALS["djgqgtvkde"]}, 4, 2 );
				${$jngvndirdu}         = substr( $date, 6, 2 );
				break;
			default:
				throw new Exception( "Invalid Date Format" );
		}
		return checkdate( ${$lufzgyotha}, ${$GLOBALS["moioewthsej"]}, $y );
	}
	function clientRedirect( $url )
	{
		$GLOBALS["cxokzeink"] = "url";
		$huaxtixfh            = "url";
		$lwmynpmnvl           = "r";
		${$lwmynpmnvl}        = "<meta http-equiv=\"Refresh\" content=\"0;url=" . ${$huaxtixfh} . "\" />";
		$r .= "<script type=\"text/javascript\">window.location = \"" . ${$GLOBALS["cxokzeink"]} . "\"</script>";
		die( $r );
	}
	function antiHacking( $string, $length = null, $html = false, $striptags = true )
	{
		$gvfacz                    = "string";
		$GLOBALS["hhcxwql"]        = "length";
		$gornknftm                 = "length";
		$qrwvyialqcn               = "length";
		$nellglcgjq                = "length";
		$GLOBALS["qsrolzy"]        = "aDisabledAttributes";
		$GLOBALS["zleexjdcgqq"]    = "allow";
		$fypusbcbfeo               = "string";
		$GLOBALS["sypnvpiqjmpa"]   = "aDisabledAttributes";
		$GLOBALS["pefdnxnej"]      = "string";
		$GLOBALS["yrgovtdjbab"]    = "string";
		$GLOBALS["nmmckmsrxdn"]    = "length";
		${$GLOBALS["nmmckmsrxdn"]} = 0 + ${$qrwvyialqcn};
		$GLOBALS["bsyrkrdpe"]      = "string";
		$GLOBALS["lvpfoykn"]       = "string";
		$egvbvyj                   = "string";
		$GLOBALS["zxyrcvvcfc"]     = "string";
		$GLOBALS["iturxhai"]       = "string";
		$vwphfyjes                 = "string";
		if ( !$html )
			return ( ${$GLOBALS["hhcxwql"]} > 0 ) ? substr( addslashes( trim( preg_replace( "/<[^>]*>/", "", ${$vwphfyjes} ) ) ), 0, $length ) : addslashes( trim( preg_replace( "/<[^>]*>/", "", ${$GLOBALS["pefdnxnej"]} ) ) );
		$allow = "<b><h1><h2><h3><h4><h5><h6><br><br /><hr><hr /><em><strong><a><ul><ol><li><dl><dt><dd><table><tr><th><td><blockquote><address><div><p><span><i><u><s><sup><sub><style><tbody>";
		$cuzjxdoij                  = "string";
		${$cuzjxdoij}               = utf8_decode( trim( $string ) );
		$blkimiubngb                = "string";
		$GLOBALS["rerfoirxadm"]     = "string";
		if ( $striptags )
			${$GLOBALS["bsyrkrdpe"]} = strip_tags( ${$egvbvyj}, ${$GLOBALS["zleexjdcgqq"]} );
		$qyihjvfwvvmg               = "string";
		${$GLOBALS["sypnvpiqjmpa"]} = array(
			"onabort",
			"onactivate",
			"onafterprint",
			"onafterupdate",
			"onbeforeactivate",
			"onbeforecopy",
			"onbeforecut",
			"onbeforedeactivate",
			"onbeforeeditfocus",
			"onbeforepaste",
			"onbeforeprint",
			"onbeforeunload",
			"onbeforeupdate",
			"onblur",
			"onbounce",
			"oncellchange",
			"onchange",
			"onclick",
			"oncontextmenu",
			"oncontrolselect",
			"oncopy",
			"oncut",
			"ondataavaible",
			"ondatasetchanged",
			"ondatasetcomplete",
			"ondblclick",
			"ondeactivate",
			"ondrag",
			"ondragdrop",
			"ondragend",
			"ondragenter",
			"ondragleave",
			"ondragover",
			"ondragstart",
			"ondrop",
			"onerror",
			"onerrorupdate",
			"onfilterupdate",
			"onfinish",
			"onfocus",
			"onfocusin",
			"onfocusout",
			"onhelp",
			"onkeydown",
			"onkeypress",
			"onkeyup",
			"onlayoutcomplete",
			"onload",
			"onlosecapture",
			"onmousedown",
			"onmouseenter",
			"onmouseleave",
			"onmousemove",
			"onmoveout",
			"onmouseover",
			"onmouseup",
			"onmousewheel",
			"onmove",
			"onmoveend",
			"onmovestart",
			"onpaste",
			"onpropertychange",
			"onreadystatechange",
			"onreset",
			"onresize",
			"onresizeend",
			"onresizestart",
			"onrowexit",
			"onrowsdelete",
			"onrowsinserted",
			"onscroll",
			"onselect",
			"onselectionchange",
			"onselectstart",
			"onstart",
			"onstop",
			"onsubmit",
			"onunload" 
		);
		${$GLOBALS["rerfoirxadm"]}  = str_ireplace( ${$GLOBALS["qsrolzy"]}, "x", $string );
		while ( preg_match( "/<(.*)?javascript.*?\(.*?((?>[^()]+)|(?R)).*?\\)?\\)(.*)?>/i", $string ) )
			${$blkimiubngb} = preg_replace( "/<(.*)?javascript.*?\\(.*?((?>[^()]+)|(?R)).*?\)?\\)(.*)?>/i", "<\$1\$3\$4\$5>", ${$gvfacz} );
		$string = preg_replace( "/:expression\\(.*?((?>[^(.*?)]+)|(?R)).*?\)\)/i", "", $string );
		while ( preg_match( "/<(.*)?:expr.*?\(.*?((?>[^()]+)|(?R)).*?\\)?\)(.*)?>/i", $string ) )
			${$GLOBALS["yrgovtdjbab"]} = preg_replace( "/<(.*)?:expr.*?\\(.*?((?>[^()]+)|(?R)).*?\\)?\\)(.*)?>/i", "<\$1\$3$4\$5>", ${$GLOBALS["lvpfoykn"]} );
		$GLOBALS["eiyfgyyc"] = "string";
		${$fypusbcbfeo}      = str_replace( "#", "#", htmlentities( ${$GLOBALS["iturxhai"]} ) );
		${$qyihjvfwvvmg}     = addslashes( str_replace( "%", "%", $string ) );
		if ( ${$nellglcgjq} > 0 )
			$string = substr( ${$GLOBALS["zxyrcvvcfc"]}, 0, ${$gornknftm} );
		return ${$GLOBALS["eiyfgyyc"]};
	}
	function uuid( )
	{
		return sprintf( "%04x%04x-%04x-%04x-%04x-%04x%04x%04x", mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
	}
	function notifyMember( $id, $message )
	{
		if ( !is_numeric( $id ) || $id < 1 )
			return false;
		$tysubjkpr             = "message";
		$query = "SELECT * FROM #__spcClient LEFT JOIN #__users ON clientID=id WHERE clientID = '$id'";
		$GLOBALS["xzcixemp"]   = "me";
		$me = $this->dbrow( $query );
		if ( empty( $me["id"] ) )
			return false;
		if ( ${$GLOBALS["xzcixemp"]}["notifyMe"] == 1 || $me["notifyMe"] == 3 )
			$sms = $this->sendSMS( $this->setting( "smsSender" ), $me["GSM"], str_ireplace( array(
				"<br>",
				"<br />",
				"<br/>" 
			), "\n", ${$tysubjkpr} ), $id );
		$GLOBALS["wjgvwxfi"] = "sms";
		if ( $me["notifyMe"] == 2 || $me["notifyMe"] == 3 ) {
			$GLOBALS["rhrlktwxycp"] = "mailer";
			${$GLOBALS["rhrlktwxycp"]} =& JFactory::getMailer();
			$mailer->setSender( $this->setting( "emailSender" ) );
			$GLOBALS["mammfwfiyoji"] = "send";
			$mailer->addRecipient( $me["email"] );
			$mailer->setSubject( "Notification from " . $this->setting( "domain" ) );
			$weftmidb = "message";
			$mailer->isHTML( true );
			$mailer->setBody( nl2br( ${$weftmidb} ) );
			${$GLOBALS["mammfwfiyoji"]} =& $mailer->Send();
		}
		if ( ${$GLOBALS["wjgvwxfi"]} || $send )
			return true;
		else
			return false;
	}
	function preventSessionHijack( )
	{
		$ip     = $this->getIpAddress();
		$GLOBALS["pogwbxuomc"]    = "ip2";
		$GLOBALS["shnmkriorj"]    = "agent";
		$ibrmznaokj               = "agent1";
		$agent   = $this->GetBrowser();
		$kqhnsi                   = "ip1";
		$eruerxegagfv             = "agent1";
		${$kqhnsi}                = md5( "host" . $ip );
		$GLOBALS["hmxmbbhzhvk"]   = "ip1";
		${$ibrmznaokj}            = md5( "useragent" . ${$GLOBALS["shnmkriorj"]} );
		${$GLOBALS["pogwbxuomc"]} = md5( "getIpAddress" . $ip );
		$dataTable2   = md5( "HTTP_USER_AGENT" . $agent );
		if ( !empty( $_SESSION[${$eruerxegagfv}] ) && !empty( $_SESSION[${$GLOBALS["hmxmbbhzhvk"]}] ) ) {
			$dwfrtuh               = "agent2";
			$GLOBALS["yfnsojafb"]  = "ip1";
			$GLOBALS["fecvcydkku"] = "agent1";
			if ( $_SESSION[${$GLOBALS["yfnsojafb"]}] != $ip2 || $_SESSION[${$GLOBALS["fecvcydkku"]}] != ${$dwfrtuh} ) {
				$GLOBALS["xqdmmfdfg"] = "ip1";
				unset( $_SESSION["email"] );
				unset( $_SESSION[$agent1] );
				unset( $_SESSION[${$GLOBALS["xqdmmfdfg"]}] );
				session_regenerate_id( true );
			}
		} else {
			unset( $_SESSION["email"] );
			$yffctjimvrm = "agent2";
			session_regenerate_id( true );
			$_SESSION[$agent1]    = ${$yffctjimvrm};
			$_SESSION[$ipl] = $ip2;
		}
	}
}
defined( "_JEXEC" ) or die( "Restricted access" );
?>
