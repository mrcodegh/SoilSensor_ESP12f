<?php

// Add soil data to wunderground packets sent from the ObserverIP
// Archive data
// Mike Russell, 7/30/2017
// 8/8/17 Add send and failed send accounting, add send error handling
// rev 2 9/5/2017  change lastdata to temperature
// rev 3 9/20/2017  add soil moisture
// rev 4 11/14/2017  add WeatherCloud reporting
// rev 5 11/21/2017  add PWS reporting via wufyi.com using WU API calls
// rev 6 11/28/2017  switch pws reporting to direct

// Sections:
// - Get Soil temp and Moisture
// - send to Weather Underground
// - note data sent/fail in database
// - convert and send to WeatherCloud

// The ObserverIP does not provide heat index data in its packet, this will calculate it
// Formula from NOAA http://www.wpc.ncep.noaa.gov/html/heatindex_equation.shtml
function heatindex($T, $RH) {
    $HI = -42.379 + 2.04901523*$T + 10.14333127*$RH - .22475541*$T*$RH - .00683783*$T*$T - .05481717*$RH*$RH + .00122874*$T*$T*$RH + .00085282*$T*$RH*$RH - .00000199*$T*$T*$RH*$RH;
    return round( $HI, 1 );
}

// Make sure the ID and PASSWORD fields match what we think it should match. If it does, this update is valid and continue. 
if ( ($_GET["ID"] == "yourWUstationid") && ($_GET["PASSWORD"] == "yourWUstationpwd") ) {

	$servername = "localhost";
	$username = "yourbitwebname";
	$password = "yourpwd";
	$soiltempf = "-55.0"; // invalid
	$moisture_percent = 0;
	$soilupdatetime = 0;

	// - Get Soil temp and Moisture
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
		$moisture_percent = $row["moisture_percent"];
		$soilupdatetime = strtotime($row["reg_date"]." UTC");
	}
	$conn->close();

	// - send to Weather Underground
	$wuget = "http://rtupdate.wunderground.com/weatherstation/updateweatherstation.php?" . $_SERVER['QUERY_STRING'];
	// If valid and somewhat recent then concatenate
	if ( $soiltempf > -55.0 && $_SERVER['REQUEST_TIME'] - $soilupdatetime < 2*60*60) { // valid temp and less than 2 hours old
		$wuget .= "&soiltempf=" . $soiltempf;
		$wuget .= "&soilmoisture=" . $moisture_percent;
	}
	
	$failsend = 0;
	$response = @file_get_contents($wuget);
	if ( $response === FALSE ) {
		echo 'failure';
		$failsend = 1;
	} else {
		echo $response;
	}
	
	// - note data sent/fail in database
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
	if ($conn->query($sql) === FALSE) {
		error_log($sql . $conn->error . "\r\n");
	}	

	$sql = "INSERT INTO ws1400 (" . 
	"tempf, humidity, dewptf, windchillf, winddir, windspeedmph," .
	"windgustmph, rainin, dailyrainin, solarradiation, UV, " .
	"indoortempf, indoorhumidity, baromin, reg_date ) VALUES (" .
	$_GET['tempf'].",".$_GET['humidity'].",".$_GET['dewptf'].",".$_GET['windchillf'].",".
	$_GET['winddir'].",".$_GET['windspeedmph'].",".$_GET['windgustmph'].",".$_GET['rainin'].",".
	$_GET['dailyrainin'].",".$_GET['solarradiation'].",".$_GET['UV'].",".
	$_GET['indoortempf'].",".$_GET['indoorhumidity'].",".$_GET['baromin'].",NOW())";
	if ($conn->query($sql) === FALSE) {
		error_log( $sql . $conn->error . "\r\n" );
	}

	$sql = "SELECT * FROM WCLastSend";
	$result = $conn->query($sql);
	$sendcnt = 0;
	$failsendcnt = 0;
	if ($result->num_rows > 0) {
		// only row 0 is updated
		$row = $result->fetch_assoc();
		$sendcnt = $row["sendcnt"];
		$failsendcnt = $row["failsendcnt"];
		$lastupdatetime = strtotime($row["reg_date"]." UTC");
	} else {
		$conn->close();
		die("WCLastSend table not accessible");
	}
	$secondssincelastupdate = $_SERVER['REQUEST_TIME'] - $lastupdatetime;
	//error_log( "seconds since last update " . $secondssincelastupdate);
	
	if ($secondssincelastupdate > 593) { // ws1400 updates at 14 sec so 7 sec before 10 min WC updates
	
		// - convert and send to WeatherCloud; build url
		// example http://api.weathercloud.net/set/wid/xxxxxxx/key/xxxxxxxxxxxxx/temp/210/tempin/233/chill/214/heat/247/hum/33/humin/29/wspd/30/wspdhi/30/wspdavg/21/wdir/271/wdiravg/256/bar/10175/rain/0/solarrad/1630/uvi/ 5/ver/1.2/type/201
		
		$wcurl = "http://api.weathercloud.net/v01/set/ver/3.7.1/type/251/wid/yourwid/key/yourkey";
		// time record here?
		$metric10x = round(($_GET['tempf'] - 32) * 5 * 10 / 9);
		$wcurl .= "/temp/" . $metric10x;
		$wcurl .= "/hum/" . $_GET['humidity'];
		$wcurl .= "/wdir/" . $_GET['winddir'];
		$sql = "SELECT AVG(winddir) FROM ws1400 WHERE reg_date>NOW()-600";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$metric10x = round($row['AVG(winddir)']);
			if ($metric10x > 359) {
				$metric10x -= 360;
			}
			$wcurl .= "/wdiravg/" . $metric10x;
		}
		$metric10x = round($_GET['windspeedmph'] * 4.47);
		$wcurl .= "/wspd/" . $metric10x;
		$sql = "SELECT MAX(windspeedmph) FROM ws1400 WHERE reg_date>NOW()-600";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$metric10x = round($row['MAX(windspeedmph)'] * 4.47);
			$wcurl .= "/wspdhi/" . $metric10x;
		}
		$sql = "SELECT AVG(windspeedmph) FROM ws1400 WHERE reg_date>NOW()-600";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$metric10x = round($row['AVG(windspeedmph)'] * 4.47);
			$wcurl .= "/wspdavg/" . $metric10x;
		}
		$metric10x = round($_GET['baromin'] * 338.6);
		$wcurl .= "/bar/" . $metric10x;
		$metric10x = $_GET['dailyrainin'] * 254;
		$wcurl .= "/rain/" . $metric10x;
		$metric10x = $_GET['rainin'] * 254;
		$wcurl .= "/rainrate/" . $metric10x;
		$metric10x = round(($_GET["indoortempf"] - 32) * 5 * 10 / 9);
		$wcurl .= "/tempin/" . $metric10x;
		$wcurl .= "/humin/" . $_GET['indoorhumidity'];
		$metric10x = $_GET['UV'] * 10;
		$wcurl .= "/uvi/" . $metric10x;
		$metric10x = round($_GET['solarradiation'] * 10);
		$wcurl .= "/solarrad/" . $metric10x;
		$metric10x = round(($_GET['windchillf'] - 32) * 5 * 10 / 9);
		$wcurl .= "/chill/" . $metric10x;
		//$metric10x = heatindex($_GET["tempf"], $_GET['humidity']);
		//$metric10x = round(($metric10x - 32) * 5 * 10 / 9);
		// use reg temp for heat index till fixed
		$metric10x = round(($_GET['tempf'] - 32) * 5 * 10 / 9);
		$wcurl .= "/heat/" . $metric10x;
		$metric10x = round(($_GET['dewptf'] - 32) * 5 * 10 / 9);
		$wcurl .= "/dew/" . $metric10x;
		if ( $soiltempf > -55.0 && $_SERVER['REQUEST_TIME'] - $soilupdatetime < 2*60*60) { // valid temp and less than 2 hours old
			$metric10x = round(($soiltempf - 32) * 5 * 10 / 9);
			$wcurl .= "/temp07/" . $metric10x;
			$metric10x = round(2 * (100 - $moisture_percent));
			$wcurl .= "/soilmoist01/" . $metric10x;
		}
		$failsend = 0;
		$response = @file_get_contents($wcurl);
		if ( $response === FALSE ) {
			echo 'failure';
			$failsend = 1;
		} else {
			echo $response;
		}

		//error_log( "wcurl " . $wcurl . "\r\n");
		$sendcnt = $sendcnt + (1 - $failsend); // Increment if not failed
		$failsendcnt = $failsendcnt + (1 * $failsend);
		$sql = "UPDATE WCLastSend SET sendcnt=" . $sendcnt . ",  failsendcnt=" . $failsendcnt . " WHERE id=0";
		if ($conn->query($sql) === FALSE) {
			error_log($sql . $conn->error . "\r\n");
		}	
	}

	$sql = "SELECT * FROM PWSLastSend";
	$result = $conn->query($sql);
	$sendcnt = 0;
	$failsendcnt = 0;
	if ($result->num_rows > 0) {
		// only row 0 is updated
		$row = $result->fetch_assoc();
		$sendcnt = $row["sendcnt"];
		$failsendcnt = $row["failsendcnt"];
		$lastupdatetime = strtotime($row["reg_date"]." UTC");
	} else {
		$conn->close();
		die("PWSLastSend table not accessible");
	}
	$secondssincelastupdate = $_SERVER['REQUEST_TIME'] - $lastupdatetime;
	if ($secondssincelastupdate > 293) { // ws1400 updates at 14 sec so 7 sec before 5 min PWS updates
		$date = new DateTime('now');
		$pwsurl = "http://www.pwsweather.com/pwsupdate/pwsupdate.php?ID=yourid&PASSWORD=yourpwd&dateutc=" . $date->format('Y-m-d+H:i:s') .
				"&winddir=" . $_GET['winddir'] . "&windspeedmph=" . $_GET['windspeedmph'] . "&windgustmph=". $_GET['windgustmph'] .  
				"&tempf=" . $_GET['tempf'] . "&rainin=" . $_GET['rainin'] . "&dailyrainin=" . $_GET['dailyrainin'] . 
				"&baromin=" . $_GET['baromin'] . "&dewptf=" . $_GET['dewptf'] . "&humidity=" . $_GET['humidity'] . 
				"&softwaretype=ebviaphpV0.3&action=updateraw";
		$failsend = 0;
		$response = @file_get_contents($pwsurl);
		if ( $response === FALSE ) {
			echo 'failure';
			$failsend = 1;
		} else {
			echo $response;
		}
		$sendcnt = $sendcnt + (1 - $failsend); // Increment if not failed
		$failsendcnt = $failsendcnt + (1 * $failsend);
		$sql = "UPDATE PWSLastSend SET sendcnt=" . $sendcnt . ",  failsendcnt=" . $failsendcnt . " WHERE id=0";
		if ($conn->query($sql) === FALSE) {
			error_log($sql . $conn->error . "\r\n");
		}
	}
	$conn->close();	

} else {
	header('HTTP/1.0 401 Unauthorized');
    echo 'failure';
    die();
}

?>
