<?php

// Add soil data to wunderground packets sent from the ObserverIP
// Archive data
// Mike Russell, 7/30/2017
// 8/8/17 Add send and failed send accounting, add send error handling
// rev 2 9/5/2017  change lastdata to temperature

// Make sure the ID and PASSWORD fields match what we think it should match. If it does, this update is valid and continue. 
if ( ($_GET["ID"] == "yourWUstationid") && ($_GET["PASSWORD"] == "yourWUstationpwd") ) {

	$servername = "localhost";
	$username = "yourbitwebname";
	$password = "yourpwd";
	$soiltempf = "-55.0"; // invalid
	$updatetime = 0;

	// Get soil temperature
	$conn = new mysqli($servername, $username, $password, "soildata");
	if ($conn->connect_error) {
		die("Connect failed: " . $conn->error);
	}	

	$sql = "SELECT * FROM " . "SoilDataTemp";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) {
		// only row 0 is updated
		$row = $result->fetch_assoc();
		$soiltempf = $row["temperature"];
		$updatetime = strtotime($row["reg_date"]." UTC");
	}
	$conn->close();

	$wuget = "http://rtupdate.wunderground.com/weatherstation/updateweatherstation.php?" . $_SERVER['QUERY_STRING'];
	// If valid and somewhat recent then concatenate
	if ( $soiltempf > -55.0 && $_SERVER['REQUEST_TIME'] - $updatetime < 2*60*60) { // valid temp and less than 2 hours old
		$wuget .= "&soiltempf=" . $soiltempf;
	}
	
	$failsend = 0;
	$wunderground = file_get_contents($wuget);
	if ( $wunderground != "FALSE" && $wunderground != "" ) {
		echo $wunderground;
	} else {
		header('HTTP/1.0 408 Request Timeout'); // Not sure why file_get_contents failed - report this?
		echo 'failure';
		$failsend = 1;
	}
	
	// Update wx data send cnt
	$conn = new mysqli($servername, $username, $password, "wxdata");
	if ($conn->connect_error) {
		die("Connect failed: " . $conn->error);
	}	

	$sql = "SELECT * FROM WxLastSend";
	$result = $conn->query($sql);

	$sendcnt = 0;
	$failsendcnt = 0;
	if ($result->num_rows > 0) {
		// only row 0 is updated
		$row = $result->fetch_assoc();
		$sendcnt = $row["sendcnt"];
		$failsendcnt = $row["failsendcnt"];
	} else {
		$conn->close();
		die("WxLastSend table not accessible");
	}
	$sendcnt = $sendcnt + (1 - $failsend); // Increment if not failed
	$failsendcnt = $failsendcnt + (1 * $failsend);
	$sql = "UPDATE WxLastSend SET sendcnt=" . $sendcnt . ",  failsendcnt=" . $failsendcnt . " WHERE id=0";
	if ($conn->query($sql) === TRUE) {
	//    echo "Record updated successfully<br>";
	} else {
		echo "Error: " . $sql . "<br>" . $conn->error;
	}	
	$conn->close();

} else {
	header('HTTP/1.0 401 Unauthorized');
    echo 'failure';
    die();
}

?>
