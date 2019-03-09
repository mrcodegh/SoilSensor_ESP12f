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
// rev 24 10/14/2018 add email alert if soil sensor goes down
// rev 25 12/5/2018 update humidity correction; move passwords/keys to seperate file
// rev 26 12/29/2018 clean up sending stats code; fix WU interval; fix owm key broke on 12/5; add stuck wind handling


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

require "wx_keys.php";
require "get_rain.php";

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
  //error_log( count($angles) . " " . $mean. "\n" );
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
		//error_log( "hi " . $hi . "\n" );
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
				//error_log($urlparm . $result . "\n");
				$result = FALSE;
			} else {
				//error_log('Curl error: ' . curl_error($ch));
			}
			if($failsend == $maxfailsend) {
				error_log("max failsend " . $failsend . substr($url, 0, 25) . "\n");
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
		//error_log($content . "\n");
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		//error_log("HTTP_CODE " . $status . "\n");
		if ($status != 204) {
			$failsend += 1;
			if($failsend == $maxfailsend) {
				error_log("max failsend " . $failsend . substr($url, 0, 25) . "\n");
			}
		}
		curl_close($curl);
	}
	return $failsend;
}

function get_send_stats($conn, $tablename) {
	$stats = array("tablename"=>"$tablename", "sendcnt"=>"0", "failsendcnt"=>"0", "lastupdatetime"=>"0");
	$sql = "SELECT * FROM $tablename WHERE id=0";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		$row = $result->fetch_assoc();
		$stats['sendcnt'] = $row["sendcnt"];
		$stats['failsendcnt'] = $row["failsendcnt"];
		$stats['lastupdatetime'] = strtotime($row["reg_date"]." UTC");
		$stats['secondssincelastupdate'] = $_SERVER['REQUEST_TIME'] - $stats['lastupdatetime'];
	} else {
		error_log("$tablename table not accessible");
	}
	$result->close();
	return $stats;
}

function save_send_stats($conn, $stats, $failcnt, $interval_sec) {
	if ($failcnt == 0) {
		$stats['sendcnt'] += 1;
	} else {
		$stats['failsendcnt'] += $failcnt;
	}
	if ($stats['secondssincelastupdate'] > $interval_sec * 2) { // If 2x past update reset to now
		$next_update = gmdate("Y-m-d H:i:s");
	} else {
		$next_update = gmdate("Y-m-d H:i:s", $stats['lastupdatetime'] + $interval_sec);
	}
	$sql = "UPDATE ". $stats['tablename'] . " SET sendcnt=" . $stats['sendcnt'] . ", failsendcnt=" . $stats['failsendcnt'] .
			', reg_date="' . $next_update . '" WHERE id=0';
	if ($conn->query($sql) === FALSE) {
		error_log($sql . $conn->error . "\n");
	}
}

function validate_sensors($conn, $temp, $windspeed) {
	// Determine sensors validity hourly or if wind becomes active after period of inactivity after rain and below freezing temps
	$valid = array("windspeed"=>true, "winddir"=>true, "rain"=>true);
	$sql = "SELECT * FROM SensorsValid WHERE id=0";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		$row = $result->fetch_assoc();
		$valid['windspeed'] = $row["windspeed"];
		$valid['winddir'] = $row["winddir"];
	}
	$result->close();
	if ('00' == date('i') && (date('s') < 20)) { // top of the hour - give ~time for unstuck sensors to fully unstick?
		$sql = "SELECT SUM(windspeedmph), STDDEV(winddir) FROM ws1400 WHERE reg_date>date_sub(now(), interval 2 hour)";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
			if ($windspeed > 0) {
				$valid['windspeed'] = true;
			}
			if ($row['STDDEV(winddir)'] > 0.0) {
				$valid['winddir'] = true;
			}
			if($valid['windspeed'] || $valid['winddir']) {
				$sql = "SELECT MIN(tempf), SUM(rainin) FROM ws1400 WHERE reg_date>date_sub(now(), interval 7 hour)";
				$result1 = $conn->query($sql);
				if ($result1->num_rows > 0) {
					$row1 = $result1->fetch_assoc();
					// invalidate if valid, stuck, and potential freeze event
					if ($valid['windspeed'] && $row['SUM(windspeedmph)'] == 0 && $row1['MIN(tempf)'] < 32 && $row1['SUM(rainin)'] > 0) {
						$valid['windspeed'] = false;
					}
					if ($valid['winddir'] && $row['STDDEV(winddir)'] == 0.0 && $row1['MIN(tempf)'] < 32 && $row['SUM(windspeedmph)'] > 0) {
						$valid['winddir'] = false;
					}
				}
				$result1->close();
			}
			$sql = "UPDATE SensorsValid SET windspeed=" . ($valid['windspeed']?1:0) . ", winddir=" . ($valid['winddir']?1:0) . " WHERE id=0";
			if ($conn->query($sql) === FALSE) {
				error_log($sql . $conn->error . "\n");
			}
		}
		$result->close();
	}
	return $valid;
}

$headers = 'From: Cloudcam <miker_nomon@gmx.com>' . PHP_EOL .
    'Reply-To: Cloudcam <miker_nomon@gmx.com>' . PHP_EOL .
    'X-Mailer: PHP/' . phpversion();

// Make sure the ID and PASSWORD fields match what we think it should match. If it does, this update is valid and continue. 
if (($_GET["ID"] == $wu_id) && ($_GET["PASSWORD"] == $wu_pwd) &&
		($_GET['baromin'] > 26) && ($_GET['baromin'] < 32) && ($_GET['tempf'] > -50)) { // sanity check indoor and outdoor sensor

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
		if ((($soilbattvolt < 3.25 ) || ($_SERVER['REQUEST_TIME'] - $soilupdatetime > 2*60*60 )) &&
			('00' == date('i') && (date('s') < 20))) {
			mail('miker_nomon@gmx.com', 'Soil Sensor Fail', "Voltage " . $soilbattvolt, $headers);
			error_log("soil sensor fail\n");
		}
	}
	$result->close();
	$conn->close();

	// - Correct Relative Humidity which drifts low at low temperatures
	$humidity = $_GET['humidity'];
	$tempf = $_GET['tempf'];
	$dewpointf = $_GET['dewptf'];
	if ( $tempf > 50.0 ) {
		$humidity_temp_adj = $humidity + 6;
	} else {
		$correction = round( $tempf*$tempf*$tempf*0.000314 + $tempf*$tempf*-0.0213 + $tempf*-0.1732 + 28.7 );
		//error_log($correction . " cor\n");
		$humidity_temp_adj = $humidity + $correction;
	}
	$humidity_gain = $humidity_temp_adj*-0.2 + 20; // gain adjust - see humidity_qv_kmci_kojc.ods
	$humidity_adj = $humidity_temp_adj + $humidity_gain;
	if ( $humidity_adj > 99 ) { // 12/31 7:45am trying 99 as top to chk WU gold star, search 99 to change back
		$humidity_adj = 99;
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
		error_log( $sql . $conn->error . "\n" );
	}

	$sensors_valid = validate_sensors($conn, $_GET['tempf'], $_GET['windspeedmph']);
	$invalid_wind = !$sensors_valid['windspeed'] || !$sensors_valid['winddir'];
	
	$dur1 = time() - $_SERVER['REQUEST_TIME'];
	
	$winddir_10min_avg = $_GET['winddir'];
	$sql = "SELECT winddir FROM ws1400 WHERE reg_date>date_sub(now(), interval 10 minute)";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		while($row = $result->fetch_array(MYSQLI_NUM)) {
		    $rows[] = $row[0];
		}
		$winddir_10min_avg = mean_of_angles( $rows );
		//error_log( count($rows) . " " . $winddir_10min_avg . "\n" );
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
	if ($humidity_avg == 99) { // seeing some rounding error so set to temp
		$dewpointf_avg = $tempf;
		$dewpointc_avg = $tempc;
	}
	//error_log( "baron_avg " . $baromin_avg . "\n" );
	
	// - Weather Underground
	// example WU string
	// ID=id&PASSWORD=pwd&tempf=29.3&humidity=74&dewptf=22.1&windchillf=24.3&winddir=337&windspeedmph=4.70&windgustmph=7.61
	//&rainin=0.00&dailyrainin=0.00&weeklyrainin=0.05&monthlyrainin=0.60&yearlyrainin=0.60&solarradiation=26.32&UV=1&indoortempf=75.2
	//&indoorhumidity=20&baromin=30.14&lowbatt=0&dateutc=2018-1-23%2014:33:30&softwaretype=Weather%20logger%20V3.1.2&action=updateraw&realtime=1&rtfreq=5

	$dur2 = -1;
	//error_log( $dewpointf_avg . " " . $dewpointf . "\n" );
	$stats = get_send_stats($conn, "WxLastSend");
	if ($stats['secondssincelastupdate'] > 15) { // limit WU updates to no more frequent than 15 sec
		//$fp = fopen("wu_times.txt", "a");
		//fwrite($fp, $_SERVER['REQUEST_TIME']." ".$stats['secondssincelastupdate']."\n");
		//fclose($fp);
		
		$humidity_str = "humidity=" . $humidity . "&dewptf=" . $dewpointf;
		$humidity_avg_str = "humidity=" . $humidity_avg . "&dewptf=" . round($dewpointf_avg, 1);
		$query_str_adjusted = str_replace( $humidity_str, $humidity_avg_str, $_SERVER['QUERY_STRING'] );

		$winddir_str = "winddir=" . $_GET['winddir'];
		$winddir_adj_str = "winddir=" . $winddir_10min_avg;
		$query_str_adjusted1 = str_replace( $winddir_str, $winddir_adj_str, $query_str_adjusted );

		$baro_str = "baromin=" . $_GET['baromin'];
		$baro_adj_str = "baromin=" . round($baromin_avg, 2);
		$query_str_adjusted2 = str_replace( $baro_str, $baro_adj_str, $query_str_adjusted1 );
		$query_str = $query_str_adjusted2;
		
		if ($invalid_wind) { // remove wind data
			$query_str_adjusted3 = str_replace( $winddir_adj_str, "", $query_str_adjusted2 );
			$query_str_adjusted4 = str_replace( "windspeedmph=".$_GET['windspeedmph'], "", $query_str_adjusted3 );
			$query_str_adjusted5 = str_replace( "windgustmph=".$_GET['windgustmph'], "", $query_str_adjusted4 );
			$query_str = $query_str_adjusted5;
		}

		$wuget = "http://rtupdate.wunderground.com/weatherstation/updateweatherstation.php?" . $query_str;
		// If valid and somewhat recent then concatenate
		if ( $soiltempf > -55.0 && $_SERVER['REQUEST_TIME'] - $soilupdatetime < 2*60*60) { // valid temp and less than 2 hours old
			$wuget .= "&soiltempf=" . $soiltempf;
			$wuget .= "&soilmoisture=" . $moisture_percent;
		}
		//error_log( $wuget . "\n" );
		$failcnt = senddata($wuget);
		save_send_stats($conn, $stats, $failcnt, 16);
		unset($stats);

		$dur2 = time() - $_SERVER['REQUEST_TIME'];
	}
	
	$dur3 = -1;
	// - Weather Cloud
	$stats = get_send_stats($conn, "WCLastSend");
	if ($stats['secondssincelastupdate'] > 590) { // ws1400 updates at 14 sec so ~10 sec before 10 min WC updates
		// - convert and send to WeatherCloud; build url
		// example http://api.weathercloud.net/set/wid/xxxxxxx/key/xxxxxxxxxxxxx/temp/210/tempin/233/chill/214/heat/247/hum/33/humin/29/wspd/30/wspdhi/30/wspdavg/21/wdir/271/wdiravg/256/bar/10175/rain/0/solarrad/1630/uvi/ 5/ver/1.2/type/201
		
		$wcurl = "http://api.weathercloud.net/v01/set/ver/3.7.1/type/251/wid/$wc_id/key/$wc_key";
		//$wcurl = "http://api.weathercloud.net/set/ver/1.2/type/201/wid/xxxx/key/xxxx";
		// time record here?
		$metric10x = round(($_GET['tempf'] - 32) * 5 * 10 / 9);
		$wcurl .= "/temp/" . $metric10x;
		$wcurl .= "/hum/" . $humidity_avg;
		if (!$invalid_wind) {
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
		}
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
		$failcnt = senddata($wcurl);
		save_send_stats($conn, $stats, $failcnt, 10*60);
		unset($stats);
		//error_log($wcurl . "\n");
		$dur3 = time() - $_SERVER['REQUEST_TIME'];

	}

	$dur4 = -1;
	// - PWS
	$stats = get_send_stats($conn, "PWSLastSend");
	if ($stats['secondssincelastupdate'] > 290) { // ws1400 updates at 14 sec so 10 sec before 5 min PWS updates
		$date = new DateTime('now');
		if (!$invalid_wind) {
			$pwsurl = "http://www.pwsweather.com/pwsupdate/pwsupdate.php?ID=$pws_id&PASSWORD=$pws_pwd&dateutc=" . $date->format('Y-m-d+H:i:s') .
					"&winddir=" . $winddir_10min_avg . "&windspeedmph=" . $_GET['windspeedmph'] . "&windgustmph=". $_GET['windgustmph'] .  
					"&tempf=" . $_GET['tempf'] . "&rainin=" . $_GET['rainin'] . "&dailyrainin=" . $_GET['dailyrainin'] . 
					"&baromin=" . round($baromin_avg, 2) . "&dewptf=" . round($dewpointf_avg, 1) . "&humidity=" . $humidity_avg . 
					"&softwaretype=ebviaphpV0.3&action=updateraw";
		} else {
			$pwsurl = "http://www.pwsweather.com/pwsupdate/pwsupdate.php?ID=$pws_id&PASSWORD=$pws_pwd&dateutc=" . $date->format('Y-m-d+H:i:s') .
					"&tempf=" . $_GET['tempf'] . "&rainin=" . $_GET['rainin'] . "&dailyrainin=" . $_GET['dailyrainin'] . 
					"&baromin=" . round($baromin_avg, 2) . "&dewptf=" . round($dewpointf_avg, 1) . "&humidity=" . $humidity_avg . 
					"&softwaretype=ebviaphpV0.3&action=updateraw";
		}
		$dur4 = time() - $_SERVER['REQUEST_TIME'];
		$failcnt = senddata($pwsurl);
		save_send_stats($conn, $stats, $failcnt, 5*60);
		unset($stats);
	}
	
	$dur5 = -1;	
	// - OpenWeatherMap
	$stats = get_send_stats($conn, "OWMLastSend");
	if ($stats['secondssincelastupdate'] > 590) { // ws1400 updates at 14 sec so ~10 sec before 10 min OWM updates
		if (!$invalid_wind) {
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
		}
		$rain_data = get_rain($conn,$_GET['dailyrainin']);
		if (!$invalid_wind) {
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
		} else {
			$owm_data = array(
				"station_id" => "5b43a940199f030001229f37",
				"dt" => $_SERVER['REQUEST_TIME'],
				"temperature" => round((($_GET['tempf'] - 32) * 5 / 9),1),
				"dew_point" => round($dewpointc_avg,1),
				"heat_index" => round(((heatIndex($_GET["tempf"], $humidity_avg) - 32) * 5 / 9),1),
				"pressure" => round($baromin_avg * 33.86),
				"humidity" => $humidity_avg,
				"rain_1h" => round($rain_data[0] * 25.4),
				"rain_24h" => round($rain_data[1] * 25.4)
			);
		}
		$owm_url = "http://api.openweathermap.org/data/3.0/measurements?appid=$owm_id";
		$failcnt = postjson($owm_url,$owm_data);
		save_send_stats($conn, $stats, $failcnt, 10*60);
		unset($stats);
		$dur5 = time() - $_SERVER['REQUEST_TIME'];
	}
	
	$dur6 = -1;
	// - CWOP
	$stats = get_send_stats($conn, "CWOPLastSend");
	if ($stats['secondssincelastupdate'] > 290) { // ws1400 updates at 14 sec so ~10 sec before 5 min CWOP updates

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
		if (!$invalid_wind) {
			$wind_data = '_' . sprintf('%03d', $winddir_10min_avg) .
						 '/' . sprintf('%03d', $windspeed) .
						 'g' . sprintf('%03d', $windgust);
		} else {
			$wind_data = '_...' . '/...' . 'g...';
		}
		$APRSdata = 'FW3280>APRS,TCPIP*:@' . gmdate("dHi", time()) .
					'z3855.54N/09441.07W' .
					$wind_data .
					't' . sprintf('%03d', round($_GET['tempf'])) .
					'r' . sprintf('%03d', round($rain_data[0] * 100)) .
					//'r...' .
					'p' . sprintf('%03d', round($rain_data[1] * 100)) .
					'P' . sprintf('%03d', round($_GET['dailyrainin'] * 100)) .
					'h' . sprintf('%02d', $hum) .
					'b' . sprintf('%05d', round($baromin_avg * 338.6)) .
					$solar .
					'Ambient Weather WS-1400-IP';
		//error_log($APRSdata . "\n");			

		$fp = @fsockopen("cwop.aprs.net", 14580, $errno, $errstr, 30);
		if (!$fp) {
		   error_log("$errstr ($errno)\n");
		} else {
		   $out = "user $cwop_id pass $cwop_pwd vers linux-1wire 1.00\r\n";
		   fwrite($fp, $out);
		   sleep(3);
		   $out = "$APRSdata\r\n`";
		   // echo "\n".$APRSdata;
		   fwrite($fp, $out);
		   sleep(3);
		   fclose($fp);
		}
		$dur6 = time() - $_SERVER['REQUEST_TIME'];
		$failcnt = 0;
		save_send_stats($conn, $stats, $failcnt, 5*60);
		unset($stats);
	}
	$conn->close();

	$duration = time() - $_SERVER['REQUEST_TIME'];
	if ($duration > 30) {
		error_log("updateweatherstation.php duration " . $duration . " " .
				$dur1 . " " . $dur2 . " " . $dur3 . " " . $dur4 . " " . $dur5 . " " . $dur6 . "\n");
	}
} else {
	header('HTTP/1.0 401 Unauthorized');
    echo 'failure';
    error_log("WX station WU pwd or reasonability fail\n");
}

?>
