<?php
// register.php - safe, no output before header, absolute redirect
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';    // must set $conn
if (!isset($conn) || !($conn instanceof mysqli)) {
    // DB connection failed — show a message for debugging, remove later
    die('DB connection problem: ' . ($conn->connect_error ?? 'unknown'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /sm/register.html");
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header("Location: /sm/register.html?error=empty");
    exit;
}

// Hash password before storing
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert using prepared statement
$stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
if ($stmt === false) {
    // DB prepare error — debug only
    // log error server-side and redirect
    error_log("Prepare failed: " . $conn->error);
    header("Location: /sm/register.html?error=server");
    exit;
}
$stmt->bind_param("ss", $username, $hashed_password);

if ($stmt->execute()) {
    $stmt->close();
    // success: redirect to login page (use absolute path)
    header("Location: /sm/login3.html?registered=1");
    exit;
} else {
    // insertion failed — likely duplicate username or other DB error
    $err = $stmt->errno;
    $msg = $stmt->error;
    $stmt->close();
    error_log("Register execute failed ($err): $msg");
    // if duplicate key (1062) redirect with exists flag
    if ($err == 1062) {
        header("Location: /sm/register.html?error=exists");
        exit;
    }
    header("Location: /sm/register.html?error=server");
    exit;
}