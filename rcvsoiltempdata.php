<?php
// rev2 change lastdata to temperature; add soil moisture
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

$sql = "UPDATE " . $tablename . 
" SET temperature='" . $soiltempf . "', moisture='" . $moisture . "', battvolt='" . $battvoltage .
"' WHERE id=0";
if ($conn->query($sql) === TRUE) {
//    echo "update<br>";
    echo "success<br>";
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}

$conn->close();

?>