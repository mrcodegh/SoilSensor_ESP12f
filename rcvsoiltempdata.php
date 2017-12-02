<?php
// rev2 change lastdata to temperature; add soil moisture
// rev3 add initial soil percent calculation
// rev5 add fail count
$servername = "localhost";
$username = "yourbitwebname";
$password = "yourpwd";
$dbname = "soildata";
$tablename = "SoilDataTemp";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
	die("Connect failed: " . $conn->error);
}

$sql = "SELECT * FROM " . "SoilDataTemp";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
	// only row 0 is updated
	$row = $result->fetch_assoc();
	$moisture_min = $row["moisture_min"];
	$moisture_max = $row["moisture_max"];
}

// Force timestamp update by writing -55.0 invalid/different first
$sql = "UPDATE " . $tablename . " SET temperature='-55.0' WHERE id=0";
if ($conn->query($sql) === TRUE) {
//    echo "New record created successfully";
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}

$soiltempf = $_POST['temperature'] * 9 / 5 + 32; // Farenheight
$soiltempf = number_format((float)$soiltempf, 1, '.', ''); // limit resolution
$moisture = $_POST['moisture'];
$battvoltage = $_POST['batt'];
$failcnt = $_POST['fail'];

if ($moisture < $moisture_min) {
	$moisture_min = $moisture;
} else if ($moisture > $moisture_max) {
	$moisture_max = $moisture;
}
$moisture_percent = 100.0 - (100 * ($moisture - $moisture_min)/($moisture_max - $moisture_min));
$moisture_percent = number_format($moisture_percent, 1, '.', ''); // limit to one decimal point

$sql = "INSERT INTO SoilDataLog " . 
"(temperature, moisture, battvolt, moisture_min, moisture_max, moisture_percent, failcnt) VALUES (" .
$soiltempf . ", " . $moisture . ", " . $battvoltage . ", " . $moisture_min . ", " . $moisture_max . ", " .
$moisture_percent . ", " . $failcnt . ")";
if ($conn->query($sql) === TRUE) {
//    echo "update<br>";
//    echo "success<br>";
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}

$sql = "UPDATE " . $tablename . 
" SET temperature='" . $soiltempf . "', moisture='" . $moisture . "', battvolt='" . $battvoltage .
"', moisture_min='" . $moisture_min . "', moisture_max='" . $moisture_max .
"', moisture_percent='" . $moisture_percent . "', failcnt='" . $failcnt .
"' WHERE id=0";
if ($conn->query($sql) === TRUE) {
//    echo "update<br>";
    echo "success<br>";
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}

$conn->close();

?>
