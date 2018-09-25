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
// rev 13 2/24/2018 correct weathercloud windir
// rev 14 3/5/2018 correct sql time based fetches
// rev 15 3/26/2018 update humidity correction using humidity_qv_kmci_kojc_rev1.ods
// rev 16 5/30/2018 correct weathercloud heat index
// rev 17 6/3/2018 winddir is now 10 min avg; WX station get is acknowledged immediately since new WU ingest method
//                 could delay breaking connection to indoor sensor (fix IP observer bug)
// rev 18 6/13/2018 IP server fix in rev 17 didn't work.  Add time limit on data sends to wx network servers
// rev 19 6/27/2018 Add one retry on data send failures
// rev 20 7/9/2018 Add OpenWeatherMap
// rev 21 7/12/2018 Add CWOP; change periodic send handling to limit time slipage
// rev 22 7/21/2018 Switch humidity to curve fit 10 minute average
// rev 23 8/22/2018 reduce correction slope on baro; fix 1 hr rain to OpenWeatherMap

// Sections:
// - Get Soil temp and Moisture
// - Apply data corrections as needed to wx data
// - Add to local db
// - send data to following and log successes/failures
// - Weather Underground each call to this php
// - WeatherCloud every 10 min
// - PWS every 5 min
// - OpenWeatherMap every 10 min
// - CWOP every 5 min

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

// http://en.wikipedia.org/wiki/Heat_index
// from 15 float multiplies to 8 float multiplies.
function heatIndex( $tempF, $humidity )
{
	if ($tempF >= 80.0 && $humidity >= 40) {
		$c1 = -42.38; $c2 = 2.049; $c3 = 10.14; $c4 = -0.2248; $c5= -6.838e-3; $c6=-5.482e-2; $c7=1.228e-3; $c8=8.528e-4; $c9=-1.99e-6;

		$A = (($c5 * $tempF) + $c2) * $tempF + $c1;
		$B = (($c7 * $tempF) + $c4) * $tempF + $c3;
		$C = (($c9 * $tempF) + $c8) * $tempF + $c6;

		//$hi = ($C * $humidity + $B) * $humidity + $A;
		$hi = ($C * $humidity + $B) * $humidity + $A + 0.4; // adding some offset observed at 83F
		//error_log( "hi " . $hi . "\r\n" );
	} else {
		$hi = $tempF;
	}
	return $hi;
}

function senddata($url)
{
	$maxfailsend = 2;
	$result = FALSE;
	$failsend = 0;
	while ($result === FALSE && $failsend < $maxfailsend) {
		$ch=curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 7);
		$result = curl_exec($ch);
		if ($result === FALSE || strpos($result, 'fail') !== FALSE) {
			$failsend += 1;
			if ($result !== FALSE) {
				//error_log($urlparm . $result . "\r\n");
				$result = FALSE;
			} else {
				//error_log('Curl error: ' . curl_error($ch));
			}
			if($failsend == $maxfailsend) {
				error_log("max failsend " . $failsend . substr($url, 0, 25) . "\r\n");
			}
		}
		curl_close($ch);
	}
	return $failsend;
}

function postjson($url,$data)
{
	$content = "[".json_encode($data)."]";
	$maxfailsend = 2;
	$status = 0;
	$failsend = 0;
	while ($status != 204 && $failsend < $maxfailsend) {
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER,
				array("Content-type: application/json"));
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl, CURLOPT_TIMEOUT, 7);
		$json_response = curl_exec($curl);
		//error_log($content . "\r\n");
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		//error_log("HTTP_CODE " . $status . "\r\n");
		if ($status != 204) {
			$failsend += 1;
		}
		curl_close($curl);
	}
	return $failsend;
}

function get_rain($conn,$today_total)
{
	// 24 hour rain = yesterday total + today total - yesterday older than 24 hour (all local time)
	$yesterday_rain = 0;
	$rain_24hr_ago = 0;
	$rain_1hr_ago = 0;
	$abs_gmt_offset = date('Z')/-3600; // assuming behind gmt
	$sql = "SELECT dailyrainin FROM ws1400 WHERE reg_date < CONCAT(DATE(NOW() - INTERVAL " . $abs_gmt_offset .
			" HOUR),' 0" . $abs_gmt_offset . ":00:00') ORDER BY reg_date DESC LIMIT 1";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		$row = $result->fetch_assoc();
		$yesterday_rain = $row['dailyrainin'];
		$sql = "SELECT dailyrainin FROM ws1400 WHERE reg_date BETWEEN DATE_SUB(NOW(), INTERVAL 1441 MINUTE) AND " .
				"DATE_SUB(NOW(), INTERVAL 1440 MINUTE) LIMIT 1"; // 24 hours ago
		$result1 = $conn->query($sql);
		if ($result1->num_rows > 0) {
			$row = $result1->fetch_assoc();
			$rain_24hr_ago = $row['dailyrainin'];
			$sql = "SELECT dailyrainin FROM ws1400 WHERE reg_date BETWEEN DATE_SUB(NOW(), INTERVAL 61 MINUTE) AND " .
					"DATE_SUB(NOW(), INTERVAL 60 MINUTE) LIMIT 1"; // 1 hour ago
			$result2 = $conn->query($sql);
			if ($result2->num_rows > 0) {
				$row = $result2->fetch_assoc();
				$rain_1hr_ago = $row['dailyrainin'];
			}
			$result2->close();
		}
		$result1->close();
	}
	$result->close();
	$rain_24hr = $today_total + $yesterday_rain - $rain_24hr_ago;
	if (date('H') == '00') {
		$rain_1hr = $today_total + $yesterday_rain - $rain_1hr_ago;
	} else {
		$rain_1hr = $today_total - $rain_1hr_ago;
	}
	return array($rain_1hr,$rain_24hr);
}

// Make sure the ID and PASSWORD fields match what we think it should match. If it does, this update is valid and continue. 
if (($_GET["ID"] == "stationid") && ($_GET["PASSWORD"] == "pwd") &&
		($_GET['baromin'] > 26) && ($_GET['baromin'] < 32) && ($_GET['tempf'] > -50)) { // sanity check indoor and outdoor sensor

	//$servername = "localhost";
	$servername = "127.0.0.1";
	$username = "username";
	$password = "password";
	$soiltempf = "-55.0"; // invalid
	$moisture_percent = 0;
	$soilupdatetime = 0;
	
	echo 'success';

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
		$soilbattvolt = $row["battvolt"];
		if ($soilbattvolt < 3.25 && '00' == date('i') && (date('s') < 20)) {
			mail('miker_nomon@gmx.com', 'Soil Sensor Battery Low', "Voltage " . $soilbattvolt);
			error_log("soil sensor low bat\r\n");
		}
	}
	$result->close();
	$conn->close();

	// - Correct Relative Humidity which drifts low at low temperatures
	$humidity = $_GET['humidity'];
	$tempf = $_GET['tempf'];
	$dewpointf = $_GET['dewptf'];
	if ( $tempf > 40.0 ) {
		$humidity_adj = $humidity + 5;
	} else {
		$correction = round( $tempf*$tempf*0.002 + $tempf*-0.77 + 32.5 );
		$humidity_adj = $humidity + $correction;
	}
	if ( $humidity_adj > 100 ) {
		$humidity_adj = 100;
	}
	$tempc = ($tempf - 32) * 5 / 9;
	$H = (log10($humidity_adj)-2)/0.4343 + (17.62*$tempc)/(243.12+$tempc);
	$dewpointc_adj = 243.12*$H/(17.62-$H);
	$dewpointf_adj = round((($dewpointc_adj * 9 / 5) + 32), 1);
	
	// - Curve fit correct baro, if necessary, here
	$baro_reading = $_GET['baromin'];
	$baroin = $baro_reading*1.05125 - 1.5388;
	
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

	$dur1 = time() - $_SERVER['REQUEST_TIME'];
	
	$winddir_10min_avg = $_GET['winddir'];
	$sql = "SELECT winddir FROM ws1400 WHERE reg_date>date_sub(now(), interval 10 minute)";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		while($row = $result->fetch_array(MYSQLI_NUM)) {
		    $rows[] = $row[0];
		}
		$winddir_10min_avg = mean_of_angles( $rows );
		//error_log( count($rows) . " " . $winddir_10min_avg . "\r\n" );
	}
	$result->close();
	$humidity_avg = $humidity_adj;
	$dewpointf_avg = $dewpointf_adj;
	$baromin_avg = $baroin;
	$sql = "SELECT AVG(humidity), AVG(dewptf), AVG(baromin) FROM ws1400 WHERE reg_date>date_sub(now(), interval 10 minute)";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		$row = $result->fetch_assoc();
		$humidity_avg = round($row['AVG(humidity)']);
		$dewpointf_avg = $row['AVG(dewptf)'];
		$baromin_avg = $row['AVG(baromin)'];
	}
	$result->close();
	$dewpointc_avg = ($dewpointf_avg - 32) * 5 / 9;
	if ($humidity_avg == 100) { // seeing some rounding error so set to temp
		$dewpointf_avg = $tempf;
		$dewpointc_avg = $tempc;
	}
	//error_log( "baron_avg " . $baromin_avg . "\r\n" );
	
	// - send to Weather Underground
	// example WU string
	// ID=stationid&PASSWORD=pwd&tempf=29.3&humidity=74&dewptf=22.1&windchillf=24.3&winddir=337&windspeedmph=4.70&windgustmph=7.61
	//&rainin=0.00&dailyrainin=0.00&weeklyrainin=0.05&monthlyrainin=0.60&yearlyrainin=0.60&solarradiation=26.32&UV=1&indoortempf=75.2
	//&indoorhumidity=20&baromin=30.14&lowbatt=0&dateutc=2018-1-23%2014:33:30&softwaretype=Weather%20logger%20V3.1.2&action=updateraw&realtime=1&rtfreq=5

	//error_log( $dewpointf_avg . " " . $dewpointf . "\r\n" );
	
	$humidity_str = "humidity=" . $humidity . "&dewptf=" . $dewpointf;
	$humidity_avg_str = "humidity=" . $humidity_avg . "&dewptf=" . round($dewpointf_avg, 1);
	$query_str_adjusted = str_replace( $humidity_str, $humidity_avg_str, $_SERVER['QUERY_STRING'] );

	$winddir_str = "winddir=" . $_GET['winddir'];
	$winddir_adj_str = "winddir=" . $winddir_10min_avg;
	$query_str_adjusted1 = str_replace( $winddir_str, $winddir_adj_str, $query_str_adjusted );

	$baro_str = "baromin=" . $_GET['baromin'];
	$baro_adj_str = "baromin=" . round($baromin_avg, 2);
	$query_str_adjusted2 = str_replace( $baro_str, $baro_adj_str, $query_str_adjusted1 );

	$wuget = "http://rtupdate.wunderground.com/weatherstation/updateweatherstation.php?" . $query_str_adjusted2;
	// If valid and somewhat recent then concatenate
	if ( $soiltempf > -55.0 && $_SERVER['REQUEST_TIME'] - $soilupdatetime < 2*60*60) { // valid temp and less than 2 hours old
		$wuget .= "&soiltempf=" . $soiltempf;
		$wuget .= "&soilmoisture=" . $moisture_percent;
	}
	//error_log( $wuget . "\r\n" );
	$failsend = senddata($wuget);
	$dur2 = time() - $_SERVER['REQUEST_TIME'];
	
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

	if ($failsend == 0) {
		$sendcnt += 1;
	} else {
		$failsendcnt += $failsend;
	}
	$sql = "UPDATE WxLastSend SET sendcnt=" . $sendcnt . ",  failsendcnt=" . $failsendcnt . " WHERE id=0";
	if ($conn->query($sql) === FALSE) {
		error_log($sql . $conn->error . "\r\n");
	}
	
	// - send to Weather Cloud
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
	$dur3 = -1;
	if ($secondssincelastupdate > 590) { // ws1400 updates at 14 sec so ~10 sec before 10 min WC updates
	
		// - convert and send to WeatherCloud; build url
		// example http://api.weathercloud.net/set/wid/xxxxxxx/key/xxxxxxxxxxxxx/temp/210/tempin/233/chill/214/heat/247/hum/33/humin/29/wspd/30/wspdhi/30/wspdavg/21/wdir/271/wdiravg/256/bar/10175/rain/0/solarrad/1630/uvi/ 5/ver/1.2/type/201
		
		$wcurl = "http://api.weathercloud.net/v01/set/ver/3.7.1/type/251/wid/wcid_here/key/wc_key_here";
		// time record here?
		$metric10x = round(($_GET['tempf'] - 32) * 5 * 10 / 9);
		$wcurl .= "/temp/" . $metric10x;
		$wcurl .= "/hum/" . $humidity_avg;
		$wcurl .= "/wdir/" . $winddir_10min_avg;
		$wcurl .= "/wdiravg/" . $winddir_10min_avg;
		$metric10x = round($_GET['windspeedmph'] * 4.47);
		$wcurl .= "/wspd/" . $metric10x;
		$sql = "SELECT MAX(windgustmph) FROM ws1400 WHERE reg_date>date_sub(now(), interval 10 minute)";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$metric10x = round($row['MAX(windgustmph)'] * 4.47);
			$wcurl .= "/wspdhi/" . $metric10x;
		}
		$result->close();
		$sql = "SELECT AVG(windspeedmph) FROM ws1400 WHERE reg_date>date_sub(now(), interval 10 minute)";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$metric10x = round($row['AVG(windspeedmph)'] * 4.47);
			$wcurl .= "/wspdavg/" . $metric10x;
		}
		$result->close();
		$metric10x = round($baromin_avg * 338.6);
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
		$metric10x = round((heatIndex($_GET["tempf"], $humidity_avg) - 32) * 5 * 10 / 9);
		$wcurl .= "/heat/" . $metric10x;
		$metric10x = round($dewpointc_avg * 10);
		$wcurl .= "/dew/" . $metric10x;
		if ( $soiltempf > -55.0 && $_SERVER['REQUEST_TIME'] - $soilupdatetime < 2*60*60) { // valid temp and less than 2 hours old
			$metric10x = round(($soiltempf - 32) * 5 * 10 / 9);
			$wcurl .= "/temp07/" . $metric10x;
			$metric10x = round(2 * (100 - $moisture_percent));
			$wcurl .= "/soilmoist01/" . $metric10x;
		}
		$failsend = senddata($wcurl);
		//error_log($wcurl . "\r\n");
		$dur3 = time() - $_SERVER['REQUEST_TIME'];

		if ($failsend == 0) {
			$sendcnt += 1;
		} else {
			$failsendcnt += $failsend;
		}
		if ($secondssincelastupdate > 1200) { // If 2x past update reset to now
			$ten_min_later = gmdate("Y-m-d H:i:s");
		} else {
			$ten_min_later = gmdate("Y-m-d H:i:s", $lastupdatetime+600);
		}
		$sql = 'UPDATE WCLastSend SET sendcnt=' . $sendcnt . ', failsendcnt=' . $failsendcnt .
				', reg_date="' . $ten_min_later . '" WHERE id=0';
		//$sql = "UPDATE WCLastSend SET sendcnt=" . $sendcnt . ",  failsendcnt=" . $failsendcnt . " WHERE id=0";
		if ($conn->query($sql) === FALSE) {
			error_log($sql . $conn->error . "\r\n");
		}
	}

	// - send to PWS
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
	$dur4 = -1;
	$secondssincelastupdate = $_SERVER['REQUEST_TIME'] - $lastupdatetime;
	if ($secondssincelastupdate > 290) { // ws1400 updates at 14 sec so 10 sec before 5 min PWS updates
		$date = new DateTime('now');
		$pwsurl = "http://www.pwsweather.com/pwsupdate/pwsupdate.php?ID=stationid&PASSWORD=pwd&dateutc=" . $date->format('Y-m-d+H:i:s') .
				"&winddir=" . $winddir_10min_avg . "&windspeedmph=" . $_GET['windspeedmph'] . "&windgustmph=". $_GET['windgustmph'] .  
				"&tempf=" . $_GET['tempf'] . "&rainin=" . $_GET['rainin'] . "&dailyrainin=" . $_GET['dailyrainin'] . 
				"&baromin=" . round($baromin_avg, 2) . "&dewptf=" . round($dewpointf_avg, 1) . "&humidity=" . $humidity_avg . 
				"&softwaretype=ebviaphpV0.3&action=updateraw";
		$failsend = senddata($pwsurl);
		$dur4 = time() - $_SERVER['REQUEST_TIME'];

		if ($failsend == 0) {
			$sendcnt += 1;
		} else {
			$failsendcnt += $failsend;
		}
		if ($secondssincelastupdate > 600) { // If 2x past update reset to now
			$five_min_later = gmdate("Y-m-d H:i:s");
		} else {
			$five_min_later = gmdate("Y-m-d H:i:s", $lastupdatetime+300);
		}
		$sql = 'UPDATE PWSLastSend SET sendcnt=' . $sendcnt . ', failsendcnt=' . $failsendcnt .
				', reg_date="' . $five_min_later . '" WHERE id=0';
		if ($conn->query($sql) === FALSE) {
			error_log($sql . $conn->error . "\r\n");
		}
	}
	
	// - send to OpenWeatherMap
	$owm_url = "http://api.openweathermap.org/data/3.0/measurements?appid=yourappid";
	
	$sql = "SELECT * FROM OWMLastSend";
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
		die("OWMLastSend table not accessible");
	}
	$result->close();
	$secondssincelastupdate = $_SERVER['REQUEST_TIME'] - $lastupdatetime;
	//error_log( "seconds since last update " . $secondssincelastupdate);
	$dur5 = -1;
	if ($secondssincelastupdate > 590) { // ws1400 updates at 14 sec so ~10 sec before 10 min OWM updates

		$windgust = round(($_GET['windgustmph'] * 0.447),1);
		$windspeed = round(($_GET['windspeedmph'] * 0.447),1);		
		$sql = "SELECT MAX(windgustmph), AVG(windspeedmph) FROM ws1400 WHERE reg_date>date_sub(now(), interval 10 minute)";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$windgust = round(($row['MAX(windgustmph)'] * 0.447),1);
			$windspeed = round(($row['AVG(windspeedmph)'] * 0.447),1);
		}
		$result->close();

		$rain_data = get_rain($conn,$_GET['dailyrainin']);
		
		$owm_data = array(
			"station_id" => "5b43a940199f030001229f37",
			"dt" => $_SERVER['REQUEST_TIME'],
			"temperature" => round((($_GET['tempf'] - 32) * 5 / 9),1),
			"dew_point" => round($dewpointc_avg,1),
			"heat_index" => round(((heatIndex($_GET["tempf"], $humidity_avg) - 32) * 5 / 9),1),
			"wind_speed" => $windspeed,
			"wind_gust" => $windgust,
			"wind_deg" => $winddir_10min_avg,
			"pressure" => round($baromin_avg * 33.86),
			"humidity" => $humidity_avg,
			"rain_1h" => round($rain_data[0] * 25.4),
			"rain_24h" => round($rain_data[1] * 25.4)
		);
	
		$failsend = postjson($owm_url,$owm_data);
		$dur5 = time() - $_SERVER['REQUEST_TIME'];
		if ($failsend == 0) {
			$sendcnt += 1;
		} else {
			$failsendcnt += $failsend;
		}
		if ($secondssincelastupdate > 1200) { // If 2x past update reset to now
			$ten_min_later = gmdate("Y-m-d H:i:s");
		} else {
			$ten_min_later = gmdate("Y-m-d H:i:s", $lastupdatetime+600);
		}
		$sql = 'UPDATE OWMLastSend SET sendcnt=' . $sendcnt . ', failsendcnt=' . $failsendcnt .
				', reg_date="' . $ten_min_later . '" WHERE id=0';
		if ($conn->query($sql) === FALSE) {
			error_log($sql . $conn->error . "\r\n");
		}
	}
	
	// - send to CWOP
	$sql = "SELECT * FROM CWOPLastSend";
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
		die("CWOPLastSend table not accessible");
	}
	$result->close();
	$secondssincelastupdate = $_SERVER['REQUEST_TIME'] - $lastupdatetime;
	//error_log( "seconds since last update " . $secondssincelastupdate);
	$dur6 = -1;
	if ($secondssincelastupdate > 290) { // ws1400 updates at 14 sec so ~10 sec before 5 min CWOP updates

		$windgust = round($_GET['windgustmph']);
		$windspeed = round($_GET['windspeedmph']);
		$sql = "SELECT MAX(windgustmph), AVG(windspeedmph) FROM ws1400 WHERE reg_date>date_sub(now(), interval 5 minute)";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			$windgust = round($row['MAX(windgustmph)']);
			$windspeed = round($row['AVG(windspeedmph)']);
		}
		$result->close();
		
		$rain_data = get_rain($conn,$_GET['dailyrainin']);
		$hum = $humidity_avg;
		if ($hum == 100) {
			$hum = 0;
		}
		if ($_GET['solarradiation'] < 1000) {
			$solar = 'L' . sprintf('%03d', round($_GET['solarradiation']));
		} elseif ($_GET['solarradiation'] < 2000) {
			$solar = 'l' . sprintf('%03d', round($_GET['solarradiation'] - 1000));
		} else {
			$solar = "";
		}
		$APRSdata = 'CWOPStation>APRS,TCPIP*:@' . gmdate("dHi", time()) .
					'z3855.54N/09441.07W' . 
					'_' . sprintf('%03d', $winddir_10min_avg) .
					'/' . sprintf('%03d', $windspeed) .
					'g' . sprintf('%03d', $windgust) .
					't' . sprintf('%03d', round($_GET['tempf'])) .
					'r' . sprintf('%03d', round($rain_data[0] * 100)) .
					//'r...' .
					'p' . sprintf('%03d', round($rain_data[1] * 100)) .
					'P' . sprintf('%03d', round($_GET['dailyrainin'] * 100)) .
					'h' . sprintf('%02d', $hum) .
					'b' . sprintf('%05d', round($baromin_avg * 338.6)) .
					$solar .
					'Ambient Weather WS-1400-IP';
		//error_log($APRSdata . "\r\n");			

		$fp = @fsockopen("cwop.aprs.net", 14580, $errno, $errstr, 30);
		if (!$fp) {
		   error_log("$errstr ($errno)\r\n");
		} else {
		   $out = "user CWOPStation pass pwd vers linux-1wire 1.00\r\n";
		   fwrite($fp, $out);
		   sleep(3);
		   $out = "$APRSdata\r\n`";
		   // echo "\n".$APRSdata;
		   fwrite($fp, $out);
		   sleep(3);
		   fclose($fp);
		}
		$dur6 = time() - $_SERVER['REQUEST_TIME'];

		$failsend = 0;
		if ($failsend == 0) {
			$sendcnt += 1;
		} else {
			$failsendcnt += $failsend;
		}
		if ($secondssincelastupdate > 600) { // If 2x past update reset to now
			$five_min_later = gmdate("Y-m-d H:i:s");
		} else {
			$five_min_later = gmdate("Y-m-d H:i:s", $lastupdatetime+300);
		}
		$sql = 'UPDATE CWOPLastSend SET sendcnt=' . $sendcnt . ', failsendcnt=' . $failsendcnt .
				', reg_date="' . $five_min_later . '" WHERE id=0';
		if ($conn->query($sql) === FALSE) {
			error_log($sql . $conn->error . "\r\n");
		}
	}
	
	$conn->close();

	$duration = time() - $_SERVER['REQUEST_TIME'];
	if ($duration > 30) {
		error_log("updateweatherstation.php duration " . $duration . " " .
				$dur1 . " " . $dur2 . " " . $dur3 . " " . $dur4 . " " . $dur5 . " " . $dur6 . "\r\n");
	}

} else {
	header('HTTP/1.0 401 Unauthorized');
    echo 'failure';
    error_log("WX station WU pwd or reasonability fail\r\n");
}

?>
