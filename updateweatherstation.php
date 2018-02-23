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
// rev 7 1/23/2018 add temperature compensation for humidity - see humidity_qv_kmci_kojc.ods
// rev 8 1/28/2018 re-order sections to collect data, modify, send out; WU, winddir is now 2min avg
//                 numerous mem leaks fixed via $result->close();
// rev 9 2/2/2018 Update dew point calc
// rev 10 2/5/2018 winddir is now 4min avg
// rev 11 2/8/2018 add baro gain correction per KOJC comparison
// rev 12 2/20/2018 chg upper limit humidity from 99 to 100

// Sections:
// - Get Soil temp and Moisture
// - Apply data corrections as needed to wx data
// - Add to local db
// - send to Weather Underground; log success/fail
// - send to PWS; log success/fail
// - convert / send to WeatherCloud; log success/fail

function mean_of_angles( $angles ) {
  $s_ = 0;
  $c_ = 0;
  $len = count( $angles );
  for ($i = 0; $i < $len; $i++) {
	if ( $angles[$i] > 180 ) {
	  $angles[$i] = $angles[$i] - 360;
	}
	$angles[$i] = deg2rad( $angles[$i] );
	$s_ += sin( $angles[$i] );
	$c_ += cos( $angles[$i] );
  }
  $mean = atan2( $s_, $c_ );
  $mean = rad2deg( $mean ); // Convert to degrees
  if ( $mean < 0 ) {
	$mean += 360;
  }
  $mean = round( $mean );
  if ($mean == 360) {
	  $mean = 0;
  }
  //error_log( count($angles) . " " . $mean. "\r\n" );
  return $mean;
}

// Make sure the ID and PASSWORD fields match what we think it should match. If it does, this update is valid and continue. 
if ( ($_GET["ID"] == "your_id") && ($_GET["PASSWORD"] == "your_pwd") ) {

	$servername = "localhost";
	$username = "root";
	$password = "your_pwd";
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
	$result->close();
	$conn->close();

	// - Correct Relative Humidity which drifts low at low temperatures
	$humidity = $_GET['humidity'];
	$tempf = $_GET['tempf'];
	$dewpointf = $_GET['dewptf'];
	if ( $tempf > 50.0 ) {
		$humidity_adj = $humidity + 7;
	} else {
		$correction = round( $tempf*$tempf*0.005 + $tempf*-0.707 + $tempf/54.7 + 28.8 );
		$humidity_adj = $humidity + $correction;
	}
	if ( $humidity_adj > 100 ) {
		$humidity_adj = 100;
	}
	$tempc = ($tempf - 32) * 5 / 9;
	$H = (log10($humidity_adj)-2)/0.4343 + (17.62*$tempc)/(243.12+$tempc);
	$dewpointc_adj = 243.12*$H/(17.62-$H);
	$dewpointf_adj = round((($dewpointc_adj * 9 / 5) + 32), 1);
	
	// - Correct baro gain - see baro_qv_kojc_compare
	$baro_reading = $_GET['baromin'];
	$baroin = round(($baro_reading*1.0879 - 2.641),2);
	
	// Save in local database
	$conn = new mysqli($servername, $username, $password, "wxdata");
	if ($conn->connect_error) {
		die("Connect failed: " . $conn->error);
	}	
	$sql = "INSERT INTO ws1400 (" . 
	"tempf, humidity, dewptf, windchillf, winddir, windspeedmph," .
	"windgustmph, rainin, dailyrainin, solarradiation, UV, " .
	"indoortempf, indoorhumidity, baromin, reg_date ) VALUES (" .
	$_GET['tempf'].",".$humidity_adj.",".$dewpointf_adj.",".$_GET['windchillf'].",".
	$_GET['winddir'].",".$_GET['windspeedmph'].",".$_GET['windgustmph'].",".$_GET['rainin'].",".
	$_GET['dailyrainin'].",".$_GET['solarradiation'].",".$_GET['UV'].",".
	$_GET['indoortempf'].",".$_GET['indoorhumidity'].",".$baroin.",NOW())";
	if ($conn->query($sql) === FALSE) {
		error_log( $sql . $conn->error . "\r\n" );
	}
	
	$winddir_4min_avg = $_GET['winddir'];
	$sql = "SELECT winddir FROM ws1400 WHERE reg_date>(NOW()-240)";
	$result = $conn->query($sql);
	
	if ($result->num_rows > 0) {
		while($row = $result->fetch_array(MYSQLI_NUM)) {
		    $rows[] = $row[0];
		}
		$winddir_4min_avg = mean_of_angles( $rows );
		//error_log( count($rows) . " " . $winddir_4min_avg . "\r\n" );
	}
	$result->close();
	
	// - send to Weather Underground
	// example WU string
	// ID=YOURID&PASSWORD=your_pwd&tempf=29.3&humidity=74&dewptf=22.1&windchillf=24.3&winddir=337&windspeedmph=4.70&windgustmph=7.61
	//&rainin=0.00&dailyrainin=0.00&weeklyrainin=0.05&monthlyrainin=0.60&yearlyrainin=0.60&solarradiation=26.32&UV=1&indoortempf=75.2
	//&indoorhumidity=20&baromin=30.14&lowbatt=0&dateutc=2018-1-23%2014:33:30&softwaretype=Weather%20logger%20V3.1.2&action=updateraw&realtime=1&rtfreq=5

	$humidity_str = "humidity=" . $humidity . "&dewptf=" . $dewpointf;
	$humidity_adj_str = "humidity=" . $humidity_adj . "&dewptf=" . $dewpointf_adj;
	$query_str_adjusted = str_replace( $humidity_str, $humidity_adj_str, $_SERVER['QUERY_STRING'] );

	$winddir_str = "winddir=" . $_GET['winddir'];
	$winddir_adj_str = "winddir=" . $winddir_4min_avg;
	$query_str_adjusted1 = str_replace( $winddir_str, $winddir_adj_str, $query_str_adjusted );

	$baro_str = "baromin=" . $_GET['baromin'];
	$baro_adj_str = "baromin=" . $baroin;
	$query_str_adjusted2 = str_replace( $baro_str, $baro_adj_str, $query_str_adjusted1 );

	$wuget = "http://rtupdate.wunderground.com/weatherstation/updateweatherstation.php?" . $query_str_adjusted2;
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
	$result->close();
	
	$sendcnt = $sendcnt + (1 - $failsend); // Increment if not failed
	$failsendcnt = $failsendcnt + (1 * $failsend);
	$sql = "UPDATE WxLastSend SET sendcnt=" . $sendcnt . ",  failsendcnt=" . $failsendcnt . " WHERE id=0";
	if ($conn->query($sql) === FALSE) {
		error_log($sql . $conn->error . "\r\n");
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
	$result->close();
	$secondssincelastupdate = $_SERVER['REQUEST_TIME'] - $lastupdatetime;
	//error_log( "seconds since last update " . $secondssincelastupdate);
	
	if ($secondssincelastupdate > 593) { // ws1400 updates at 14 sec so 7 sec before 10 min WC updates
	
		// - convert and send to WeatherCloud; build url
		// example http://api.weathercloud.net/set/wid/xxxxxxx/key/xxxxxxxxxxxxx/temp/210/tempin/233/chill/214/heat/247/hum/33/humin/29/wspd/30/wspdhi/30/wspdavg/21/wdir/271/wdiravg/256/bar/10175/rain/0/solarrad/1630/uvi/ 5/ver/1.2/type/201
		
		$wcurl = "http://api.weathercloud.net/v01/set/ver/3.7.1/type/251/wid/yourid/key/your_key";
		//$wcurl = "http://api.weathercloud.net/set/ver/1.2/type/201/wid/yourid/key/your_key";
		// time record here?
		$metric10x = round(($_GET['tempf'] - 32) * 5 * 10 / 9);
		$wcurl .= "/temp/" . $metric10x;
		$wcurl .= "/hum/" . $humidity_adj;
		$wcurl .= "/wdir/" . $winddir_4min_avg;
		$sql = "SELECT AVG(winddir) FROM ws1400 WHERE reg_date>(NOW()-600)";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			while($row = $result->fetch_array(MYSQLI_NUM)) {
				$rows[] = $row[0];
			}
			$metric10x = mean_of_angles( $rows );
			$wcurl .= "/wdiravg/" . $metric10x;
		}
		$result->close();
		$metric10x = round($_GET['windspeedmph'] * 4.47);
		$wcurl .= "/wspd/" . $metric10x;
		$sql = "SELECT MAX(windspeedmph) FROM ws1400 WHERE reg_date>(NOW()-600)";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$metric10x = round($row['MAX(windspeedmph)'] * 4.47);
			$wcurl .= "/wspdhi/" . $metric10x;
		}
		$result->close();
		$sql = "SELECT AVG(windspeedmph) FROM ws1400 WHERE reg_date>(NOW()-600)";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$metric10x = round($row['AVG(windspeedmph)'] * 4.47);
			$wcurl .= "/wspdavg/" . $metric10x;
		}
		$result->close();
		$metric10x = round($baroin * 338.6);
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
		//$metric10x = heatindex($_GET["tempf"], $humidity_adj);
		//$metric10x = round(($metric10x - 32) * 5 * 10 / 9);
		// use reg temp for heat index till fixed
		$metric10x = round(($_GET['tempf'] - 32) * 5 * 10 / 9);
		$wcurl .= "/heat/" . $metric10x;
		$metric10x = round($dewpointc_adj * 10);
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
	$result->close();
	$secondssincelastupdate = $_SERVER['REQUEST_TIME'] - $lastupdatetime;
	if ($secondssincelastupdate > 293) { // ws1400 updates at 14 sec so 7 sec before 5 min PWS updates
		$date = new DateTime('now');
		$pwsurl = "http://www.pwsweather.com/pwsupdate/pwsupdate.php?ID=YOURID&PASSWORD=your_pwd&dateutc=" . $date->format('Y-m-d+H:i:s') .
				"&winddir=" . $winddir_4min_avg . "&windspeedmph=" . $_GET['windspeedmph'] . "&windgustmph=". $_GET['windgustmph'] .  
				"&tempf=" . $_GET['tempf'] . "&rainin=" . $_GET['rainin'] . "&dailyrainin=" . $_GET['dailyrainin'] . 
				"&baromin=" . $baroin . "&dewptf=" . $dewpointf_adj . "&humidity=" . $humidity_adj . 
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
