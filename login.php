<?php
session_start();
$host = "localhost";
$username = "root";
$password = "";
$dbname = "silent_mode";

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user = $_POST['username'] ?? '';
$pass = $_POST['password'] ?? '';

$sql = "SELECT * FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $user);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    if (password_verify($pass, $row['password'])) {
        $_SESSION['username'] = $user;
        header("Location: silent_mode.php");
        exit();
    } else {
        header("Location: login3.html?error=1");
        exit();
    }
} else {
    header("Location: login3.html?error=1");
    exit();
}
?>