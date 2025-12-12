<?php
$host = "sql100.infinityfree.com";     // MySQL Hostname
$user = "if0_40648272";                // MySQL Username
$password = "srujana0937";   // Your database password
$dbname = "if0_40648272_silent_mode";  // Database name on InfinityFree

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
