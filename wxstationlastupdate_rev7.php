<?php

// Returns seconds since last update, upload cnt, failed upload cnt  OR failure
// Mike Russell, 8/10/2017  Rev 0
// Rev1 - returns last wx station values of:
// seconds since last update, temp, humid, windspd, gust, dir, baro, rain, UV
// Rev2 12/14/17 - add solar radiation after UV
// Rev3 3/4/2018 - switch to 4 minute average for wind direction
// Rev4 3/10/2018 - add rates
// Rev5 overwritten
// Rev6 12/4/2018 - add 1 hour rain
// Rev7 12/26 - add lightning distance

require "wx_keys.php";
require "wx_dirs.php";
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
  //error_log( count($angles) . " " . $mean. "\r\n" );
  return $mean;
}

function get_lightning_stats($servername, $username, $password) {
	$tablename = "last";
	$stats = array( "data_age"=>"9999", "dist_miles"=>"9999", "addr"=>"addr" );
	$conn = new mysqli($servername, $username, $password, "lightningdata");
	if ($conn->connect_error) {
		echo "Connect failed: " . $conn->error;
		return;
	}
	$sql = "SELECT * FROM $tablename WHERE id=0";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		$row = $result->fetch_assoc();
		$lastupdate = strtotime($row["reg_date"]." UTC");
		$stats['data_age'] = time() - $lastupdate;
		$stats['dist_miles'] = $row["dist_miles"];
		$stats['addr'] = $row["addr_list"];
	}
	$result->close();
	$conn->close();
	return $stats;
}

function get_wind_valid($conn) {
	$windspeed_valid = true;
	$winddir_valid = true;
	$sql = "SELECT * FROM " . "SensorsValid WHERE id=0";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		$row = $result->fetch_assoc();
		$windspeed_valid = $row["windspeed"];
		$winddir_valid = $row["winddir"];
	}
	$result->close();
	return $windspeed_valid && $winddir_valid;	
}



$conn = new mysqli($servername, $username, $password, "wxdata");
if ($conn->connect_error) {
	die("failure\n " . $conn->error);
}

// Get wx station data
$sql = "SELECT winddir FROM ws1400 WHERE reg_date>date_sub(now(), interval 4 minute)";
$avg_windir_result = $conn->query($sql);

$sql = "SELECT * FROM ws1400 ORDER BY id DESC LIMIT 80,1";
$result_20_min_old = $conn->query($sql);

$sql = "SELECT * FROM ws1400 ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);

$updatetime = 0;
if ($result->num_rows > 0) {
	$row = $result->fetch_assoc();
	$updatetime = strtotime($row["reg_date"]." UTC");
	$result->close();
} else {
	$result->close();
	$conn->close();
	die("failure\nws1400 table not accessible");
}

if ($result_20_min_old->num_rows > 0) {
	$row_20_min_old = $result_20_min_old->fetch_assoc();
} else {
	$result->close();
	$conn->close();
	die("failure\nws1400 table not accessible");
}

if ($avg_windir_result->num_rows > 0) {
	while($row_winddir = $avg_windir_result->fetch_array(MYSQLI_NUM)) {
		$rows[] = $row_winddir[0];
	}
	$winddir_4min_avg = mean_of_angles( $rows );
	//error_log( count($rows) . " " . $winddir_4min_avg . "\r\n" );
} else {
	$winddir_4min_avg = $row["winddir"];
	error_log( "wxstationlastupdate.php  no average" . "\r\n" );
}
$avg_windir_result->close();	

$secondssincelastupdate = $_SERVER['REQUEST_TIME'] - $updatetime;
$temp_chg = $row["tempf"] - $row_20_min_old["tempf"];
$temp_chg_prcnt = round( $temp_chg * 100 / 20 );
if ( $temp_chg_prcnt < -100 ) {
	$temp_chg_prcnt = -100;
}
if ( $temp_chg_prcnt > 100 ) {
	$temp_chg_prcnt = 100;
}
$humidity_chg = $row["humidity"] - $row_20_min_old["humidity"];
$humidity_chg_prcnt = round( $humidity_chg * 100 / 40);
if ( $humidity_chg_prcnt < -100 ) {
	$humidity_chg_prcnt = -100;
}
if ( $humidity_chg_prcnt > 100 ) {
	$humidity_chg_prcnt = 100;
}
$baro_chg = $row["baromin"] - $row_20_min_old["baromin"];
$baro_chg_prcnt = round( $baro_chg * 100 / 0.1 );
if ( $baro_chg_prcnt < -100 ) {
	$baro_chg_prcnt = -100;
}
if ( $baro_chg_prcnt > 100 ) {
	$baro_chg_prcnt = 100;
}
$result_20_min_old->close();

$rain_data = get_rain($conn,$row["dailyrainin"]);
$lightning_stats = get_lightning_stats($servername, $username, $password);
$wind_valid = get_wind_valid($conn);

$out_str =
// 1
$secondssincelastupdate.",". 
$row["tempf"].",".
$row["humidity"].",".
number_format($row["windspeedmph"], 1, '.', '').",".
// 5
number_format($row["windgustmph"], 1, '.', '').",".
$winddir_4min_avg.",".
number_format($row["baromin"], 2, '.', '').",".
number_format($row["dailyrainin"], 2, '.', '').",".
$row["UV"].",".
// 10
$row["solarradiation"].",".
$rain_data[0].",". // rain 1hr
$lightning_stats['data_age'].",".
$lightning_stats['dist_miles'].",".
$lightning_stats['addr'].",".
// 15 rates percent
$temp_chg_prcnt.",".
$humidity_chg_prcnt.",".
$baro_chg_prcnt.",".
",".
",".
($wind_valid?1:0);

//error_log( $out_str . "\r\n" );
echo $out_str;

$conn->close();

?>
