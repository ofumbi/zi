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
				if ( $no_of_filter_words == $badwords ) {
					
					if ( !empty( $contentFilterReplacement ) )
						$message = $contentFilterReplacement;
					else
						return "2916";
					break;
				}
			}
		}
		
		$channel  = ( $this->isAPI ) ? $channel: "Website";
		
		$channel              = ( empty( $channel) ) ? "API" : $channel;
		$msgID   = isset( $optional["msgID"] ) ? $optional["msgID"] : 0;
		$apiDlvrID    = isset( $optional["apiDlvrID"] ) ? $optional["apiDlvrID"] : "";
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
			
			$max_msg_allowed             = $max_msg_allowed;
			$s = substr( $message, 0, $max_msg_allowed );
			$s2              = substr( $message, $max_msg_allowed, 1 );
			
			if ( $s2 != " " ) {
				$pos  = strrpos( $s, " " );
				$s  = substr( $s, 0, $pos );
			}
			$s3 = substr( $message, strlen( $s ) );
			$result  = $this->sendSMS( $sender, $recipientList, $s, $smsClient, $channel, $msgID );
			$result2 = $this->sendSMS( $sender, $recipientList, $s3, $smsClient, $channel, $msgID );
			return $result . " | " . $result2;
		}
		$blockSender = $this->setting( "blockSender" );
		if ( !empty( $blockSender ) ) {
			$blockSender = strtoupper( $blockSender );
			$blockSender = str_replace( explode( ",", " ,-,_,." ), "", $blockSender );
			$s = explode( ",", $blockSender );
			$s2 = str_replace( explode( ",", " ,-,_,." ), "", strtoupper( $sender ) );
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
				
				$value                                      = explode( "=", $value );
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
		$recipient_s     = explode( ",", $recipientList );
		$recipientsz  = array_unique( $recipient_s );
		$outputDetails     = array( );
		$cntrep = count( $recipientsz );
		$cntrep2       = count( $recipient_s );
		$recipients = $this->filterNos( $recipientsz, 1 );
		$cntrep3      = count( $recipients );
		if ( !$this->isAPI ) {
			
			if ( $cntrep < $cntrep2 )
				$outputDetails[] = ( $cntrep2 - $cntrep ) . " repeated numbers were removed";
			if ( $cntrep3 < $cntrep )
				$outputDetails[] = ( $cntrep - $cntrep3 ) . " invalid numbers were removed";
		}
		
		$availableCredit         = (float) $this->member["Units"];
		if ( $availableCredit <= 0 )
			return "2906";
		$blockCountry = trim( $this->member["specialBlockCountry"] );
		
		$blockCountry   = empty( $blockCountry ) ? trim( $this->settings["blockCountry"] ) : $blockCountry;
		if ( !empty( $blockCountry ) ) {
			
			$blockCountry = $this->correctCommas( $blockCountry );
			
			$s                = explode( ",", $blockCountry );
			foreach ( $s as $value ) {
				
				$s_length = strlen( $value );
				foreach ( $recipients as &$dest ) {
					
					if ( substr( $dest, 0, $s_length ) == $value && $s_length > 0 && !empty( $dest ) )
						$dest = "";
				}
			}
		}
		unset( $s_length );
		unset( $s );
		$oldBalance = $availableCredit;
		$credit            = 0.00;
		$destination              = array( );
		$good_recipients = array( );
		$routedRecipients  = array( );
		$unsent = array( );
		$rand   = md5( $sender . implode( ",", $recipients ) . $message );
		$countPriceList = count( $priceList );
		for ( $i = 0; $i < count( $recipients ); $i++ ) {
			if ( $availableCredit <= 0 )
				return "2906";

			$unitcost = 1.00;
			
			
			for ( $j = 0; $j < $countPriceList; $j++ ) {
				
				$len = strlen( $priceList[$j][0] );
				
				if ( substr( $recipients[$i], 0, $len ) == $priceList[$j][0] && $len > 0 ) {
					
					
					$unitcost         = $priceList[$j][1] - 0;
					break;
				}
			}
			
			if ( $availableCredit < $unitcost )
				return "2906";
			
			if ( $useName ) {
				
				$r_name = $this->getName( $id, $recipients[$i] );
				$message    = str_replace( "@@name@@", $r_name, $message );
				$msgLength  = strlen( str_replace( "\r\n", "  ", $message ) );
			}
			$requiredCredit = ( $msgLength <= $this->settings["smsLength"] ) ? $this->mceil( $msgLength / $this->settings["smsLength"] ) * $unitcost : $this->mceil( $msgLength / $this->settings["smsLength2"] ) * $unitcost;
			if ( $availableCredit < $requiredCredit )
				return "2906";
			$api_x            = $api;
			$useRoute = false;
			if ( !empty( $smsRoutes ) ) {
				
				foreach ( $smsRoutes as $key => $value ) {
					$len = strlen( $key );
					
					if ( substr( $recipients[$i], 0, $len ) == $key && $len > 0 && !empty( $value ) ) {
						if ( empty( $smsRoutes[$key]["recipients"] ) )
							$smsRoutes[$key]["recipients"] = "";
						$smsRoutes[$key]["recipients"] = $smsRoutes[$key]["recipients"] . $recipients[$i] . ",";
						
						$api_x                                           = $smsRoutes[$key];
						$useRoute                                            = true;
						break;
					}
				}
			}
			if ( !$useName && !$useRoute )
				$good_recipients[] = $recipients[$i];
			if ( $api["category"] == 2 ) {
				$dlvrID     = "s" . time() . mt_rand( 0, 999 );
				$searchKeys             = array(
					"@@sender@@",
					"@@message@@",
					"@@recipient@@",
					"@@msgid@@" 
				);
				$replaceKeys              = array(
					urlencode( $sender ),
					urlencode( $message ),
					urlencode( $recipients[$i] ),
					urlencode( $dlvrID ) 
				);
				
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
								
								$sent = ( $tok > $api_x["apiResponse"] ) ? true : $sent;
							}
							$tok = strtok( " ,.\n\t" );
						}
						break;
					case "x10":
						$sent               = false;
						$tok = strtok( $response, " ,.\n\t" );
						while ( $tok !== false ) {
							if ( is_numeric( $tok ) ) {
								
								$sent = ( $tok < $api_x["apiResponse"] ) ? true : $sent;
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
					$this->dbquery( "UPDATE #__spcClient SET Units = Units - $requiredCredit WHERE clientID = " . $smsClient );
					$saveMsgz = "INSERT INTO #__spcMessages(msgClientID,senderID,recipients,message,delivered,msgStatus,channel,unitsUsed,apiDlvrID,dlvrID) VALUES \n\t\t\t\t\t($smsClient,'" . addslashes( $sender ) . "','{$recipients[$i]}','" . addslashes( $message ) . "', NOW(),'Sent','$channel','$requiredCredit','$apiDlvrID','#" . implode( "#", $wholeMessageId ) . "#')";
					if ( $msgID > 0 )
						$saveMsgz = "UPDATE #__spcMessages SET msgStatus = 'Sent', delivered=NOW(),apiDlvrID='$apiDlvrID',dlvrID='#" . implode( "#", $wholeMessageId ) . "#', unitsUsed = unitsUsed + '$requiredCredit' WHERE msgID = $msgID";
					$this->dbquery( $saveMsgz );
					$wholeMessageId    = array( );
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
					urlencode( $dlvrID ) 
				);
				
				$smsGateway = str_replace( $searchKeys, $replaceKeys, $api["apiData"] );
				$response    = $this->URLRequest( $smsGateway, $api["protocol"] );
				
				if ( !empty( $api["fnMsgID"] ) ) {
					
					$nuFnName = "f" . time();
					
					$fnMsgID               = false;
					$apifn           = str_replace( " MsgID", " " . $nuFnName, $api["fnMsgID"] );
					
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
						$sent               = $sent = ( $response < $api["apiResponse"] ) ? true : false;
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
					
					$saveMsgz = "INSERT INTO #__spcMessages(msgClientID,senderID,recipients,message,delivered,msgStatus,channel,unitsUsed,apiDlvrID,dlvrID) VALUES ($smsClient,'" . addslashes( $sender ) . "','" . addslashes( implode( ",", $recipients ) ) . "','" . addslashes( $message ) . "', NOW(),'Sent','$channel','$credit','$apiDlvrID','#" . implode( "#", $wholeMessageId ) . "#')";
					if ( $msgID > 0 )
						$saveMsgz = "UPDATE #__spcMessages SET msgStatus = 'Sent', delivered=NOW(), unitsUsed = '$credit',apiDlvrID='$apiDlvrID',dlvrID='#" . implode( "#", $wholeMessageId ) . "#' WHERE msgID = $msgID AND msgStatus = 'processing' AND schedule IS NOT NULL AND schedule < NOW()";
					$this->dbquery( $saveMsgz );
					$this->dbquery( "UPDATE #__spcClient SET Units = Units - " . $credit . " WHERE clientID = " . $smsClient );
					$wholeMessageId  = array( );
					$units_deducted = true;
				}
			}
			if ( !empty( $smsRoutes ) ) {
				foreach ( $smsRoutes as $smsRoute ) {
					
					$routedNos = explode( ",", $smsRoute["recipients"] );
					array_pop( $routedNos );
					$numbs = array_chunk( $routedNos, 50 );
					$api_x    = $smsRoute;
					
					foreach ( $numbs as $numb ) {
						
						if ( !$this->isAPI )
							echo " .";
						$dlvrID     = "d" . time() . mt_rand( 0, 199 );
						
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
							urlencode( $dlvrID ) 
						);
						$smsGateway = str_replace( $searchKeys, $replaceKeys, $api_x["apiData"] );
						
						$response    = $this->URLRequest( $smsGateway, $api_x["protocol"] );
						
						if ( !empty( $api_x["fnMsgID"] ) ) {
							
							$nuFnName    = "f" . time();
							$fnMsgID = false;
							$apifn     = str_replace( " MsgID", " " . $nuFnName, $api_x["fnMsgID"] );
							
							@eval( $apifn );
							if ( function_exists( $nuFnName ) )
								$fnMsgID = $nuFnName( $response );
							$wholeMessageId[] = ( $fnMsgID == false ) ? $dlvrID : str_replace( ",", "#", $fnMsgID );
						} else
							$wholeMessageId[] = $dlvrID;
						
						$confirmType = "x" . $api_x["confirmType"];
						switch ( $confirmType ) {
							case "x1":
								$sent = ( stripos( $response, $api_x["apiResponse"] ) !== false ) ? true : false;
								break;
							case "x2":
								$sent = ( stripos( $response, $api_x["apiResponse"] ) === false ) ? true : false;
								break;
							case "x3":
								$response     = (int) $response;
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
								$pos    = strripos( $response, $api_x["apiResponse"] );
								$sent = ( $pos !== false && $pos == ( strlen( $response ) - strlen( $api_x["apiResponse"] ) ) ) ? true : false;
								break;
							case "x7":
								$sent                = false;
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
								$tok            = strtok( $response, " ,.\n\t" );
								while ( $tok !== false ) {
									
									if ( is_numeric( $tok ) ) {
										
										$sent = ( $tok < $api_x["apiResponse"] ) ? true : false;
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
										
										$sent          = ( $tok > $api_x["apiResponse"] ) ? true : $sent;
									}
									$tok = strtok( " ,.\n\t" );
								}
								break;
							case "x10":
								$sent  = false;
								$tok = strtok( $response, " ,.\n\t" );
								while ( $tok !== false ) {
									if ( is_numeric( $tok ) ) {
										
										$sent = ( $tok < $api_x["apiResponse"] ) ? true : $sent;
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
						if ( $sent != false && $units_deducted == false ) {
							
							$saveMsgz = "INSERT INTO #__spcMessages(msgClientID,senderID,recipients,message,delivered,msgStatus,channel,unitsUsed,apiDlvrID,dlvrID) VALUES \n\t\t\t\t\t\t($smsClient,'" . addslashes( $sender ) . "','" . addslashes( implode( ",", $recipients ) ) . "','" . addslashes( $message ) . "', NOW(),'Sent','$channel','$credit','$apiDlvrID','#" . implode( "#", $wholeMessageId ) . "#')";
							if ( $msgID > 0 )
								$saveMsgz = "UPDATE #__spcMessages SET msgStatus = 'Sent', delivered=NOW(), unitsUsed = '$credit',apiDlvrID='$apiDlvrID', dlvrID='#" . implode( "#", $wholeMessageId ) . "#'  WHERE msgID = $msgID AND msgStatus = 'processing' AND schedule IS NOT NULL AND schedule < NOW()";
								
							$this->dbquery( $saveMsgz );
							$query = "UPDATE #__spcClient SET Units = Units - $credit WHERE clientID = $smsClient";
							$this->dbquery( $query );
							$wholeMessageId = array( );
							$units_deducted             = true;
						}
					}
				}
			}
			if ( !$units_deducted )
				$credit = 0;
		}
		if ( $credit > 0 ) {
			
			$_SESSION["outputDetails"] = $outputDetails;
			if ( $availableCredit <= $this->setting( "lowUnits" ) && $oldBalance > $this->setting( "lowUnits" ) ) {
				
				$this->user["Units"]   = $availableCredit;
				$this->member["Units"] = $availableCredit;
				if ( !empty( $this->settings["lowUnitsMessage"] ) ) {
					$this->settings["lowUnitsMessage"] = $this->customizeMsg( $this->setting( "lowUnitsSMS" ), $this->member["username"], $this->member["name"], $this->member["email"], $this->member["GSM"], $availableCredit );
					$this->notifyMember( $smsClient, $this->settings["lowUnitsMessage"] );
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
		
		if ( $repeat === 0 ) {
			
			$result = $this->dbquery( $sql );
			if ( !$result )
				return;
			return mysql_fetch_assoc( $result );
		} else if ( $repeat === 1 ) {
			$this->result = $this->dbquery( $sql);
			return $this->result;
		} else {
			return mysql_fetch_assoc( $this->result );
		}
	}
	function dbarray( $sql )
	{/* 	This function connects and queries a database. It returns all rows from d result as a 2d array. */
		
		$result = $this->dbquery( $sql );
		if ( !$result )
			return;
		$arr = array( );
		while ( $row = mysql_fetch_assoc( $result ) ) {
			$arr[] = $row;
		}
		return $arr;
	}
	function dbinsertid( )
	{/* 	This function It returns insertid. */
		return mysql_insert_id();
	}
	function dbval( $sql )
	{ /* 	This function connects and queries a database. It returns a value requested. */
		if ( is_null( $this->dbx ) )
			$this->dbconnect();
		
		$result = mysql_query( $this->cleanSQL( $sql), $this->dbx );
		if ( !$result )
			return;
		$x = mysql_fetch_row( $result );
		return $x[0];
	}
	function checkmobile( )
	{
		$useragent = $_SERVER["HTTP_USER_AGENT"];
		if ( preg_match( "/android.+mobile|avantgo|bada\\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i", $useragent ) || preg_match( "/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\\/)|klon|kpt |kwc\\-|kyo(c|k)|le(no|xi)|lg( g|\\/(k|l|u)|50|54|e\-|e\\/|\\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(di|rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\\-|oo|p\-)|sdk\\/|se(c(\\-|0|1)|47|mc|nd|ri)|sgh\\-|shar|sie(\\-|m)|sk\\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\\-9|up(\\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|xda(\\-|2|g)|yas\-|your|zeto|zte\\-/i", substr( $useragent, 0, 4 ) ) )
			return true;
		else
			return false;
	}
	
	######################################################
	##		Function to get ip address of a client		##
	######################################################
	function getIpAddress( )
	{
		return ( empty( $_SERVER["HTTP_CLIENT_IP"] ) ? ( empty( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ? $_SERVER["REMOTE_ADDR"] : $_SERVER["HTTP_X_FORWARDED_FOR"] ) : $_SERVER["HTTP_CLIENT_IP"] );
	}
	
	
	######################################################
	##	This function returns the extension of the file.	##
	######################################################
	function getExtension($str) {
			 $i = strrpos($str,".");
			 if (!$i) { return ""; }
			 $l = strlen($str) - $i;
			 $ext = substr($str,$i+1,$l);
			 return $ext;
	}
	
	######################################################
	##	this function upload a file and return its path	##
	######################################################
	function uploadNewFile($fieldname, $allowedExt, $uploadsDirectory){
		// possible PHP upload errors
		$errors = array(1 => 'Selected file is too large!',//php.ini max file size exceeded!
						2 => 'Selected file is too large!',//html form max file size exceeded!
						3 => 'Incomplete file upload!',//file upload was only partial!
						4 => 'No file was selected!'); 
	
		
		if(!($_FILES[$fieldname]['error'] == 0)) return 'Error - '.$errors[$_FILES[$fieldname]['error']];// check for PHP's built-in uploading errors
		// check that the file we are working on really was the subject of an HTTP upload
		if(!(@is_uploaded_file($_FILES[$fieldname]['tmp_name']))) return 'Error - File is not an HTTP upload!'; 
		if(!empty($allowedExt)){
			//Check if its in the allowed extension list
			$ext = $this->getExtension($_FILES[$fieldname]['name']);
			$pos = strripos($allowedExt,$ext);
			if ($pos === false) return 'Error - Invalid file type!';
		}
		//create unique filename
		$now = time();
		while(file_exists($uploadFilename = $uploadsDirectory.$now.'-'.$_FILES[$fieldname]['name'])){    
			$now++;
		}
		// now let's move the file to its final location
		if(!(move_uploaded_file($_FILES[$fieldname]['tmp_name'], $uploadFilename))) return 'Error - Unable to move file uploaded file'; 
		else return $uploadFilename;
	}
	
	######################################################################
	##	Function to display all messages logged during page generation	##
	##	the messages should be saved as make alert						##
	######################################################################

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
	
	
	######################################################################
	##	Function to create all messages logged during page generation	##
	##	the messages should be saved as make alert						##
	######################################################################
	
	function makeAlert( $msg = "" )
	{
		
		if ( empty( $msg ) )
			return;
		$_SESSION["messageToAlert"][] = $msg;
	}
	
	
	######################################################################
	##	Function to count all messages logged during page generation	##
	##																	##
	######################################################################
	function countAlert( )
	{
		return count( $_SESSION["messageToAlert"] );
	}
	
	
	######################################################################
	##	Function to post with url via curl								##
	##																	##
	######################################################################
	function urlPost( $site, $vars, $headers = 0 )
	{
		if ( is_array( $vars ) ) {
			$temp = array( );
			foreach ( $vars as $key => $value )
				$temp[] = $key . "=" . $value;
			$vars = implode( "&", $temp );
		}
		$ch        = curl_init( $site );
		if ( $ch ) {
			curl_setopt( $ch, CURLOPT_POST, 1 );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $vars );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
			curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
			curl_setopt( $ch, CURLOPT_HEADER, $headers );
			curl_setopt( $ch, CURLOPT_USERAGENT, "SMS Portal Creator" );
			curl_setopt( $ch, CURLOPT_REFERER, "http://smsportalcreator.com" );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			$response = curl_exec( $ch );
			if ( $headers )
				$response = explode( "\r\n\r\n", $response, 2 );
		} else
			$response = "";
		return $response;
	}
	
		#######################################################################################################
	##  The function to post a HTTP Request to the provided url passing the $_data array to the API	     ##
	#######################################################################################################
	function URLRequest( $url_full, $protocol = "GET" )
	{
		$url          = parse_url( $url_full );
		$port       = ( empty( $url["port"] ) ) ? false : true;
		if ( !$port ) {
			if ( $url["scheme"] == "http" ) {
				$url["port"] = 80;
			} elseif ( $url["scheme"] == "https" ) {
				$url["port"] = 443;
			}
		}
		$url["query"]   = empty( $url["query"] ) ? "" : $url["query"];
		$url["path"] = empty( $url["path"] ) ? "" : $url["path"];
		if ( function_exists( "curl_init" ) ) {
			if ( $protocol == "GET" ) {
				$ch = curl_init( $url["protocol"] . $url["host"] . $url["path"] . "?" . $url["query"] );
				if ( $ch ) {
					
					curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
					curl_setopt( $ch, CURLOPT_HEADER, 0 );
					if ( $port )
						curl_setopt( $ch, CURLOPT_PORT, $url["port"] );
					$content          = curl_exec( $ch );
					curl_close( $ch );
				} else
					$content = "";
			} else {
				$ch       = curl_init( $url["protocol"] . $url["host"] . $url["path"] );
				if ( $ch ) {
					curl_setopt( $ch, CURLOPT_POST, 1 );
					curl_setopt( $ch, CURLOPT_POSTFIELDS, $url["query"] );
					curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
					curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
					curl_setopt( $ch, CURLOPT_HEADER, 0 );
					curl_setopt( $ch, CURLOPT_USERAGENT, "SMS Portal Creator" );
	
					if ( $port)
						curl_setopt( $ch, CURLOPT_PORT, $url["port"] );
					curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
					curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
					$content = curl_exec( $ch );
					curl_close( $ch );
				} else
					$content = "";
			}
		} else if ( function_exists( "fsockopen" ) ) {
			$url["protocol"] = $url["scheme"] . "://";
			$eol             = "\r\n";
			$h               = "";
			$postdata_str    = "";
			$getdata_str     = "";
			if ( $protocol == "POST" ) {
				$h           = "Content-Type: text/html" . $eol . "Content-Length: " . strlen( $url["query"] ) . $eol;
				$postdata_str   = $url["query"];
			} else
				$getdata_str = "?" . $url["query"];
			$headers = "$protocol " . $url["protocol"] . $url["host"] . $url["path"] . $getdata_str . " HTTP/1.0" . $eol . "Host: " . $url["host"] . $eol . "Referer: " . $url["protocol"] . $url["host"] . $url["path"] . $eol . $h . "Connection: Close" . $eol . $eol . $postdata_str;
			$fp  = fsockopen( $url["host"], $url["port"], $errno, $errstr, 60 );
			if ( $fp ) {
				fputs( $fp, $headers );
				$content = "";
				while ( !feof( $fp ) ) {
					$content .= fgets( $fp, 128 );
				}
				fclose( $fp );
				
				//remove headers
				$pattern               = "/^.*\r\n\r\n/s";
				$content = preg_replace( $pattern, "", $content);
			}
		} else {
			try {
				if ( $protocol == "GET" )
					return file_get_contents( $url_full );
				else {                   
					$site   = explode( "?", $url_full, 2 );
					$content = file_get_contents( $site[0], false, stream_context_create( array(
						"http" => array(
							"method" => "POST",
							"header" => "Connection: close\r\nContent-Length: " . strlen( $site[1] ) . "\r\n",
							"content" => $site[1] 
						) 
					) ) );
				}
			}
			catch ( Exception $g ) {
				
				$content = "";
			}
		}
		return $content;
	}
	
	///////////////////////////////////////////////////////////////////////////

	
	##############################################
	## 		correct gsm numbers					##
	##############################################
	function alterPhone( $gsm )
	{
		$array = is_array($gsm);
		$gsm = ($array) ? $gsm : explode(",",$gsm);
		$homeCountry = $this -> setting("countryCode");
		$outArray = array();
		foreach($gsm as $item)
		{
			if(!empty($item)){
				$item = explode("=",$item);
				$item = $item[0];
				$item = (substr($item,0,1) == "+") ? substr($item,1) : $item;
				$item = (substr($item,0,3) == "009") ? substr($item,3): $item;
				$outArray[] = (substr($item,0,1) == "0") ? $homeCountry.substr($item,1): $item;
			}
		}
		return ($array) ? $outArray : implode(",",$outArray);
	}
	
	##############################################
	## 		correct input csv numbers			##
	##############################################
	
	function correctCommas( $csv ){
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
		$csv           = str_replace( $inpt, ",", $csv );
		while ( strpos( $csv, ",," ) !== false ) {
			$csv = str_replace( ",,", ",", $csv );
			$csv = str_replace( ",,", ",", $csv );
		}
		return trim( $csv, "," );
	}
	
	##############################################
	## 		get unique array fields				##
	##############################################
	function uniqueArray($myArray) {
		$array = is_array($myArray);
		$myArray = ($array) ? $myArray: explode(",",$myArray);
		$myArray = array_flip(array_flip(array_reverse($myArray,true)));
		return ($array) ? $myArray : implode(',',$myArray);
	}
	
	
	
	##############################################
	## 		correct input gsm numbers			##
	##############################################
	function filterNos($csv) {
		$array = is_array($csv);
		$csv = ($array) ? $csv : explode(",",$csv);
		$validArray = array();
		foreach($csv as $value){
			$l = strlen($value);
			if($l >= 7 && $l <= 15) $validArray[]= $value;
		}
		return ($array) ? $validArray : implode(',',$validArray);
	}
	
	##############################################
	## 			Replace user parameters			##
	##############################################
	function customizeMsg($msg,$username='',$name='',$email='',$GSM='',$units='x',$orderAmt='',$orderUnits='') {
		$inpt= array('@@username@@','@@name@@','@@email@@','@@GSM@@','@@units@@','@@orderAmt@@','@@orderUnits@@');
		$oupt= array($username,$name,$email,$GSM,$units,$orderAmt,$orderUnits);
		$msg= str_ireplace($inpt,$oupt,$msg);
		return $msg;
	}
	
	
	
	
	##############################################
	## 			Round up numbers				##
	## 		function ceil in php has			##
	## 	  a bug so this mceil was written		##
	##############################################
	function mceil($x) {
		$c = 1;
		return  ( ($y = $x/$c) == ($y = (int)$y) ) ? $x : ( $x>=0 ?++$y:--$y)*$c ; 
	}
	
	
	
	
	##############################################
	## 			Generate random characters		##
	##############################################
	function generatePassword($length=10){
	   $characters = "QWERTYU23456789PLKJHGFDSAZXCVBNM ";
		$string = "";    
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters))];
		}
		return $string;
	}
	
	
	
	##############################################
	##		Validate an email address 			##
	##############################################
	function isValidEmail($email){
		if(!preg_match("/^[_\.0-9a-zA-Z-]+@([0-9a-zA-Z][0-9a-zA-Z-]+\.)+[a-zA-Z]{2,6}$/i", $email)) {
			return false; 
		} else {
			return true;
		}
	}

	
	##############################################################################################
	##	Function to subtract two dates and return the difference in d= days, h=hours, m=minutes 	##
	##############################################################################################
	function datedsub($first_date,$second_date,$f="d"){
		$d1 = strtotime($first_date);
		$d2 = strtotime($second_date);
		$delta = $d2 - $d1;
		switch ($f)
		{
			case "d":
				$num = ($delta / 86400);
			break;
			case "h":
				$num = ($delta / (3600));;
			break;
			case "m":
				$num = ($delta / (60));;
			break;
			default:
				$num = ($delta / 86400);
		} 
		return round($num,0,PHP_ROUND_HALF_UP);
	
	}
	
	
	
	###############################################
	##				Get page url				 ##
	###############################################
	function thisURL() {
		 $pageURL = 'http';
		 if ($_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
		 $pageURL .= "://";
		 if ($_SERVER["SERVER_PORT"] != "80") {
		  $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
		 } else {
		  $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
		 }
		 return $pageURL;
	}
	
	
	###############################################
	##				Reload the current page 	 ##
	##		without resending POST variables	 ##
	###############################################
	
	
	function pageReload( )
	{
		die( "<meta http-equiv=\"refresh\" content=\"0\"/> <script type=\"text/javascript\">window.location.href=window.location.href;</script><input type=\"button\" value=\"      [PROCEED]      \" onClick=\"window.location.href=window.location.href\">" );
	}
	
	##################################################
	##			Fetch files in a directory			##
	##################################################
	
	 function fetchFiles ($directory,$filter="") {
		// create an array to hold directory list
		$results = array();
		
		// create a handler for the directory
		$handler = opendir($directory);
		
		// open directory and walk through the filenames
		while ($file = readdir($handler)) {
			// if file isn't this directory or its parent,  and contains the filter or filter is empty, add it to the results
			if ($file != "." && $file != "..") {
			if((!empty($filter) && stripos($file,$filter) !== false) || empty($filter)) $results[] = $file;
			}
		}
		closedir($handler);
		return $results;
	  }


	##################################################
	##			tabulation							##
	##################################################
	

	function autoGrid( $tablename, $primaryKey, $query, $optional = array( ) )
	{
		$optional["return"]          = empty( $optional["return"] ) ? 1 : $optional["return"];
		$optional["page_link_class"] = ( empty( $optional["page_link_class"] ) ) ? "" : "class=\"" . $optional["page_link_class"] . "\"";
		$datagrid                     = array( );
		if ( empty( $optional["where_condition"] ) )
			$optional["where_condition"] = "1=1";
		if ( is_array( $optional["search_array"] ) ) {
			if ( isset( $_POST["srch_field"] ) )
				$_SESSION["srch_field"] = $_POST["srch_field"];
			if ( isset( $_POST["search_word"] ) )
				$_SESSION["search_word"] = $_POST["search_word"];
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
			$searchForm .= "\" /><select name=\"srch_field\" >";
			foreach ( $optional["search_array"] as $key => $value ) {
				$sel = "";
				if ( isset( $_SESSION["srch_field"] ) )
					$sel = ( $_SESSION["srch_field"] == $key ) ? " selected=\"selected\"" : "";
				
				$searchForm .= "<option value='$key' $sel > $value </option>";
			}
			$searchForm .= "</select>" . "<input type=\"submit\" class=\"myButton\" name=\"search_now\" value=\"Search\" />" . "<input type=\"submit\" class=\"myButton\" name=\"search_now\" value=\"Reset\" />" . "</form>" . "</div>";
		} else {
			$searchForm      = "";
			$_SESSION["query_condition"] = "";
		}
		$_SESSION["query_condition"]                  = empty( $_SESSION["query_condition"] ) ? "1=1" : $_SESSION["query_condition"];
		$optional["where_condition"] = $optional["where_condition"] . " AND " . $_SESSION["query_condition"];
		$paginationArray             = array( );
		$this_page                       = parse_url( $this->thisURL() );
		$this_page["path"]                          = ( substr( $this_page["path"], -1 ) == "/" || substr( $this_page["path"], -4, 1 ) == "." ) ? $this_page["path"] : $this_page["path"] . "/";
		$this_page                       = $this_page["scheme"] . "://" . $this_page["host"] . $range["path"];
		parse_str( $_SERVER["QUERY_STRING"], $url );
		$rowsperpage = empty( $optional["page_size"] ) ? $this->setting( "rows_per_page" ) : $optional["page_size"];
		if ( $rowsperpage <= 1 )
			$rowsperpage = 10;
		$range              = empty( $optional["range"] ) ? 2 : $optional["range"];
		$numrows = "select count(*) as cnt from $tablename ";
		$numrows .= ( stripos( $optional["where_condition"], "WHERE" ) === false ) ? "WHERE " . $optional["where_condition"] : $optional["where_condition"];
		$numrows   = $this->dbval( $numrows );
		$totalpages = ceil( $numrows / $rowsperpage );
		$currentpage     = isset( $_GET["currentpage"] ) ? $_GET["currentpage"] : 1;
		if ( $currentpage > $totalpages )
			$currentpage = $totalpages;
		if ( $currentpage < 1 )
			$currentpage = 1;
		$offset           = ( $currentpage - 1 ) * $rowsperpage;
		$paginationArray["start"] = ( $numrows == 0 ) ? 0 : $offset + 1;
		$paginationArray["total"] = $numrows;
		$paginationArray["size"]                 = ( ( $paginationArray["start"] + $rowsperpage ) > $paginationArray["total"] ) ? ( $paginationArray["total"] - $paginationArray["start"] ) + 1 : $rowsperpage;
		$pagination               = "Pages: ";
		if ( $currentpage - $range > 1 ) {
			$url["currentpage"] = 1;
			$pagination .= "<a {$optional['page_link_class']} href='$this_page?" . http_build_query( $url ) . "'><u>1</u></a>...";
		}
		for ( $x = ( $currentpage - $range ); $x < ( ( $currentpage + $range ) + 1 ); $x++ ) {
			if ( ( $x > 0 ) && ( $x <= $totalpages ) ) {
				
				$url["currentpage"] = $x;
				$pagination .= ( $x == $currentpage ) ? $x : " <a {$optional['page_link_class']} href='$this_page?" . http_build_query( $url ) . "'><u>$x</u></a> ";
			}
		}
		if ( $currentpage + $range < $totalpages ) {
			$url["currentpage"] = $totalpages;
			$pagination .= "... <a {$optional['page_link_class']} href='$this_page?" . http_build_query( $url ) . "'><u>$totalpages</u></a> ";
		}
		$paginationArray["links"] = ( $totalpages > 1 ) ? $pagination : "";
		if ( $paginationArray["size"] > 0 ) {
			$sql  = "SELECT $primaryKey FROM $tablename ";
			$sql .= ( stripos( $optional["where_condition"], "WHERE" ) === false ) ? "WHERE " . $optional["where_condition"] . " " : $optional["where_condition"] . " ";
			$sql .= empty( $optional["order_by"] ) ? " ORDER BY $primaryKey DESC " : $optional["order_by"];
			$sql .= " LIMIT " . ( $paginationArray["start"] - 1 ) . "," . $paginationArray["size"];
			$objectIDs = $this->dbarray( $sql);
			$ids = array( );
			if ( is_array( $objectIDs ) ) {
				foreach ( $objectIDs as $value )
					$ids[] = $value[$primaryKey];
			}
			$ids           = "'" . implode( "','", $ids ) . "'";
			$query .= ( stripos( $query, "FROM" ) == false ) ? " FROM " . $tablename : "";
			$query .= ( stripos( $query, "WHERE" ) == false ) ? " WHERE " . $optional["where_condition"] . " AND " : " AND ";
			$query .= "$primaryKey IN ( $ids ) ";
			$query .= empty( $optional["order_by"] ) ? " ORDER BY $primaryKey DESC " : $optional["order_by"];
			$tableObject   = $this->dbarray( $query );
			$n   = 0;
			$dataTable = "<table class='table'>";
			$dataTable2 = "";
			$cto               = count( $tableObject );
			for ( $i = 0; $i < $cto; $i++ ) {
				$row = $tableObject[$i];
				if ( $n == 0 && empty( $optional["template"] ) ) {
					$th = array( );
					foreach ( $row as $key => $value )
						$th[] = $key;
					$dataTable .= "<tr class='th'><th>" . implode( "</th><th>", $th ) . "</th></tr>";
					$n += 1;
				}
				if ( empty( $optional["template"] ) ) {
					$th = array( );
					foreach ( $row as $value ) {
						if ( is_numeric( $value ) ) {
							$th[] = "<div align='right'>" . number_format( $value, 2 ) . "</div>";
						} else
							$th[] = $value;
					}
					if ( $n >= 2 ) {
						
						$dataTable .= "<tr class='tr2'><td class='cell'>" . implode( "</td><td class='cell'>", $th ) . "</td></tr>";
						$n = 0;
					} else
						$dataTable .= "<tr class='tr1'><td class='cell'>" . implode( "</td><td class='cell'>", $th ) . "</td></tr>";
					$n += 1;
				} else {
					$tmp = $optional["template"];
					foreach ( $row as $key => $value ) {
						$tmp = str_replace( "@" . $key . "@", $value, $tmp );
					}
					$dataTable .= $tmp;
				}
			}
			$dataTable .= "</table>";
			if ( !empty( $optional["template"] ) )
				$dataTable = $dataTable2;
		} else
			$dataTable = "";
		$grid = array( );
		$a     = "{$paginationArray['start']} - " . ( $paginationArray["start"] + $paginationArray["size"] - 1 ) . " of " . number_format( $paginationArray["total"] - 0 );
		if ( empty( $optional["search_template"] ) )
			$grid[] = "<table width=\"100%\" style=\"margin-top:10px;\" ><tr style=\"vertical-align:middle;\">" . "<td>$a</td><td>{$paginationArray['links']}</td><td align='right'>$searchForm</td></tr></table>";
		else {
			$optional["search_template"] = str_replace( "@a@", $a, $optional["search_template"] );
			$optional["search_template"]        = str_replace( "@b@", $paginationArray["links"], $optional["search_template"] );
			$grid[]               = str_replace( "@c@", $searchForm, $optional["search_template"] );
		}
		$grid[] = $dataTable;
		if ( $optional["return"] )
			return $grid;
		echo implode( "", $grid );
	}
	
	
	
	
	function cacheInfo( $smsClient, $data )
	{
		$GLOBALS["qoyhpmovhx"]    = "data";
		$data = date( "Y-m-d h:i" ) . ": " . $data;
		$this->dbquery( "UPDATE #__spcClient SET pendingNotifications = CONCAT(pendingNotifications,'#',$data) WHERE clientID = $smsClient" );
	}
		

##########################################################
##		Function to validate date						##
##########################################################
function validateDate( $date, $format='YYYY-MM-DD')
{
	switch( $format )
	{
		case 'YYYY/MM/DD':
		case 'YYYY-MM-DD':
		list( $y, $m, $d ) = preg_split( '/[-\.\/ ]/', $date );
		break;

		case 'YYYY/DD/MM':
		case 'YYYY-DD-MM':
		list( $y, $d, $m ) = preg_split( '/[-\.\/ ]/', $date );
		break;

		case 'DD-MM-YYYY':
		case 'DD/MM/YYYY':
		list( $d, $m, $y ) = preg_split( '/[-\.\/ ]/', $date );
		break;

		case 'MM-DD-YYYY':
		case 'MM/DD/YYYY':
		list( $m, $d, $y ) = preg_split( '/[-\.\/ ]/', $date );
		break;

		case 'YYYYMMDD':
		$y = substr( $date, 0, 4 );
		$m = substr( $date, 4, 2 );
		$d = substr( $date, 6, 2 );
		break;

		case 'YYYYDDMM':
		$y = substr( $date, 0, 4 );
		$d = substr( $date, 4, 2 );
		$m = substr( $date, 6, 2 );
		break;

		default:
		throw new Exception( "Invalid Date Format" );
	}
	return checkdate( $m, $d, $y );
}
	function clientRedirect( $url )
	{
		$r        = "<meta http-equiv=\"Refresh\" content=\"0;url=" . $url . "\" />";
		$r .= "<script type=\"text/javascript\">window.location = \"" . $url . "\"</script>";
		die( $r );
	}
	function antiHacking( $string, $length = null, $html = false, $striptags = true )
	{
		
		$length = 0 + $length;
		if ( !$html )
			return ( $length > 0 ) ? substr( addslashes( trim( preg_replace( "/<[^>]*>/", "", $string ) ) ), 0, $length ) : addslashes( trim( preg_replace( "/<[^>]*>/", "", $string) ) );
		$allow = "<b><h1><h2><h3><h4><h5><h6><br><br /><hr><hr /><em><strong><a><ul><ol><li><dl><dt><dd><table><tr><th><td><blockquote><address><div><p><span><i><u><s><sup><sub><style><tbody>";
		$string               = utf8_decode( trim( $string ) );
		if ( $striptags )
			$string = strip_tags( $string, $allow );
		$aDisabledAttributes = array(
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
		$string  = str_ireplace( $aDisabledAttributes, "x", $string );
		while ( preg_match( "/<(.*)?javascript.*?\(.*?((?>[^()]+)|(?R)).*?\\)?\\)(.*)?>/i", $string ) )
			$string = preg_replace( "/<(.*)?javascript.*?\\(.*?((?>[^()]+)|(?R)).*?\)?\\)(.*)?>/i", "<\$1\$3\$4\$5>", $string );
		$string = preg_replace( "/:expression\\(.*?((?>[^(.*?)]+)|(?R)).*?\)\)/i", "", $string );
		while ( preg_match( "/<(.*)?:expr.*?\(.*?((?>[^()]+)|(?R)).*?\\)?\)(.*)?>/i", $string ) )
			$string = preg_replace( "/<(.*)?:expr.*?\\(.*?((?>[^()]+)|(?R)).*?\\)?\\)(.*)?>/i", "<\$1\$3$4\$5>", $string );
	
		$string      = str_replace( "#", "#", htmlentities( $string ) );
		$string     = addslashes( str_replace( "%", "%", $string ) );
		if ( $length > 0 )
			$string = substr( $string, 0, $length );
		return $string;
	}
	
	
	
	function uuid( )
	{
		return sprintf( "%04x%04x-%04x-%04x-%04x-%04x%04x%04x", mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
	}
	function notifyMember( $id, $message )
	{
		if ( !is_numeric( $id ) || $id < 1 )
			return false;
		$query = "SELECT * FROM #__spcClient LEFT JOIN #__users ON clientID=id WHERE clientID = '$id'";
		$me = $this->dbrow( $query );
		if ( empty( $me["id"] ) )
			return false;
		if ( $me["notifyMe"] == 1 || $me["notifyMe"] == 3 )
			$sms = $this->sendSMS( $this->setting( "smsSender" ), $me["GSM"], str_ireplace( array(
				"<br>",
				"<br />",
				"<br/>" 
			), "\n", $message ), $id );
		if ( $me["notifyMe"] == 2 || $me["notifyMe"] == 3 ) {
			
			$mailer =& JFactory::getMailer();
			$mailer->setSender( $this->setting( "emailSender" ) );
			$mailer->addRecipient( $me["email"] );
			$mailer->setSubject( "Notification from " . $this->setting( "domain" ) );
			$mailer->isHTML( true );
			$mailer->setBody( nl2br( $message ) );
			$send =& $mailer->Send();
		}
		if ( $sms|| $send )
			return true;
		else
			return false;
	}
	function preventSessionHijack( )
	{
		$ip     = $this->getIpAddress();
		$agent   = $this->GetBrowser();
		$ip1                = md5( "host" . $ip );
		$agent1            = md5( "useragent" . $agent );
		$ip2 = md5( "getIpAddress" . $ip );
		$dataTable2   = md5( "HTTP_USER_AGENT" . $agent );
		if ( !empty( $_SESSION[$agent1] ) && !empty( $_SESSION[$ip1] ) ) {
			$dwfrtuh               = "agent2";
			$GLOBALS["fecvcydkku"] = "agent1";
			if ( $_SESSION[$ip1] != $ip2 || $_SESSION[$agent1] != $agent2 ) {
				unset( $_SESSION["email"] );
				unset( $_SESSION[$agent1] );
				unset( $_SESSION[$ip1] );
				session_regenerate_id( true );
			}
		} else {
			unset( $_SESSION["email"] );
			session_regenerate_id( true );
			$_SESSION[$agent1]    = $agent2;
			$_SESSION[$ipl] = $ip2;
		}
	}
}
defined( "_JEXEC" ) or die( "Restricted access" );
?>
