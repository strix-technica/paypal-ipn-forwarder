<?php
/*
Plugin Name: IPN Forwarder
Description: Authenticates and forwards IPN requests to whatever scripts need them.
Author: Aaron Edwards (Incsub)
Author URI: http://uglyrobot.com
Copyright 2007-2013 Incsub (http://incsub.com)
*/

/*
//--------------------- Configuration -----------------//
You can add or modify IPN URL's here. You must include
a prefix in one of the custom fields to forward it on
correctly. This will check 'PROFILEREFERENCE' (rp_invoice_id), 'custom',
'INVNUM' (invoice) in the IPN response.

Monthly IPN logs are saved to the /logs/ directory and may be pulled via FTP. You should probably protect the /logs/ directory from direct downloads via an htaccess restriction for security.
*/

//Our password. Check for this in your script to make sure it's from us ($_POST['inc_pass']). Do not change!
define('INC_PASS', 'xxxxxxxxxxxxxxx');

// An array of prefix to search for, domain and path to post to, and whether we're using sandbox or not for applications
$apps = array(
  /* App 1 */
  array('prefix' => 'app1',
        'domain' => 'mysite.com',
        'path'   => '/ipn-handler.php',
        'live'   => true),

  /* App2 */
  array('prefix' => 'app2',
        'domain' => 'myothersite.com',
        'path'   => '/ipn-handler.php',
        'live'   => false)
);



/////////////////////////////////////////////////////////////////////
///////////////////////* Begin Script *//////////////////////////////
if (isset($_POST['payment_status']) || isset($_POST['txn_type'])) {
  
  //figure out where to send it from the prefix and if sandbox
  foreach ($apps as $app) {
    if (strpos($_POST['rp_invoice_id'], $app['prefix']) !== false || strpos($_POST['custom'], $app['prefix']) !== false || strpos($_POST['invoice'], $app['prefix']) !== false) {
      $appDomain = $app['domain'];
      $appPath = $app['path'];
      $live = $app['live'];
    }
  }

  //did we find somewhere to send it?
  if (!$appDomain || !$appPath) {
    $error = "No valid prefix: \t".http_build_query($_POST);
    write_to_log($error);
		exit;
  }
  
  if ($live)
    $domain = 'www.paypal.com';
	else
		$domain = 'www.sandbox.paypal.com';
  

  ////validate the IPN with PayPal
	$req = 'cmd=_notify-validate';
	foreach ($_POST as $k => $v) {
		if (get_magic_quotes_gpc()) $v = stripslashes($v);
		$req .= '&' . $k . '=' . urlencode($v);
	}
	
	/* update to HTTP1.1 as per new paypal requirements
	$header = 'POST /cgi-bin/webscr HTTP/1.0' . "\r\n"
			. 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
			. 'Content-Length: ' . strlen($req) . "\r\n"
			. "\r\n";
	*/
	$header = "POST /cgi-bin/webscr HTTP/1.1\r\n"
					. "Host: $domain\r\n"
					. 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
					. 'Content-Length: ' . strlen($req) . "\r\n"
					. "Connection: close\r\n\r\n";

	@set_time_limit(60);
	if ($conn = @fsockopen("ssl://$domain", 443, $errno, $errstr, 30)) {
		fputs($conn, $header . $req);
		socket_set_timeout($conn, 30);

		$response = '';
		$close_connection = false;
		while (true) {
			if (feof($conn) || $close_connection) {
				fclose($conn);
				break;
			}

			$st = @fgets($conn, 4096);
			if ($st === false) {
				$close_connection = true;
				continue;
			}

			$response .= $st;
		}

		$error = '';
		$lines = explode("\n", str_replace("\r\n", "\n", $response));
		// looking for: HTTP/1.1 200 OK
		if (count($lines) == 0) $error = 'Response Error: Header not found while verifying with PayPal';
		else if (substr($lines[0], -7) != ' 200 OK') {
			$error = 'Response Error: Unexpected HTTP response while verifying with PayPal';
		} else {
			// remove HTTP header
			while (count($lines) > 0 && trim($lines[0]) != '') array_shift($lines);

			// first line will be empty, second line will have the result
			if (count($lines) < 2) $error = 'Response Error: No content found in transaction response while verifying with PayPal';
			else if (strtoupper(trim($lines[1])) != 'VERIFIED' && strtoupper(trim($lines[2])) != 'VERIFIED') {
				$error = 'Response Error: Unexpected transaction response while verifying with PayPal: '.$lines[1] . $req;
			}
		}

		if ($error != '') {
      //There was an issue with the paypal verification, log the error
      write_to_log($error);
      header("HTTP/1.1 503 Service Unavailable");
			exit;
		}
	} else {
    //error connecting
    $error = 'Could not make a connection with fsockopen while verifying with PayPal: '.$errstr;
    write_to_log($error);
    header("HTTP/1.1 503 Service Unavailable");
		exit;
  }


  //// Now POST the IPN variables to the proper script as it is now verified
  
  //add our password
  $req .= "&inc_pass=".INC_PASS;

	$header = "POST $appPath HTTP/1.1\r\n"
			. "Host: $appDomain\r\n"
			. 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
			. 'Content-Length: ' . strlen($req) . "\r\n"
			. "Connection: close\r\n\r\n";

	@set_time_limit(60);
	if ($conn = @fsockopen($appDomain, 80, $errno, $errstr, 30)) {
		fputs($conn, $header . $req);
		socket_set_timeout($conn, 30);

		$response = '';
		$close_connection = false;
		while (true) {
			if (feof($conn) || $close_connection) {
				fclose($conn);
				break;
			}

			$st = @fgets($conn, 4096);
			if ($st === false) {
				$close_connection = true;
				continue;
			}

			$response .= $st;
		}

		$error = '';
		$lines = explode("\n", str_replace("\r\n", "\n", $response));
		// looking for: HTTP/1.1 200 OK
		if (count($lines) == 0)
      $error = "Response Error: Header not found: \t".http_build_query($_POST);
		else if (substr($lines[0], -7) != ' 200 OK')
      $error = 'Could not contact '.$appDomain.$appPath." to send the IPN: {$lines[0]} \t".http_build_query($_POST);

		if ($error != '') {
      //There was an issue with the connection, log the error
      write_to_log($error);
      header("HTTP/1.1 503 Service Unavailable");
			exit;
		}
	} else {
    //error connecting
    $error = "Could not make a connection with fsockopen: $errstr\t".http_build_query($_POST);
    write_to_log($error);
    header("HTTP/1.1 503 Service Unavailable");
		exit;
  }

  //Yeay! Everything worked! Lets log it anyway
  $message = "Successfuly sent to ".$appDomain.$appPath.": \t".http_build_query($_POST);
  write_to_log($message);
  exit;
	
} else {
	// Did not find expected POST variables. Possible access attempt from a non PayPal site.
	header("HTTP/1.1 401 Authorization Required");
	echo 'Error: Missing POST variables. Identification is not possible.';
	exit;
}


function write_to_log($error) {
  //create filename for each month
  $filename = 'logs/IPN_Log_' . date('Y_m') . '.log';
  
  //add timestamp to error
  $message = gmdate('[Y-m-d H:i:s] ') . $error;
  
  //write to file
  $contents = @file_get_contents($filename);
  file_put_contents($filename, trim($contents)."\n".$message);
}
?>