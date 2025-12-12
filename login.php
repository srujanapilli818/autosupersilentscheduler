<?php
// login.php 
session_start();
include 'db.php';   // uses $conn from db.php

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login3.html");
    exit;
}

$user = trim($_POST['username'] ?? '');
$pass = trim($_POST['password'] ?? '');

if ($user === '' || $pass === '') {
    header("Location: login3.html?error=empty");
    exit;
}

// prepared statement to fetch user by username
$stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
if ($stmt === false) {
    // debug: show DB error in development; remove in production
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("s", $user);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();
    // If you stored hashed passwords (recommended)
    if (password_verify($pass, $row['password'])) {
        $_SESSION['username'] = $row['username'];
        // redirect to main page
        header("Location: silent_mode.php");
        exit;
    } else {
        header("Location: login3.html?error=invalid");
        exit;
    }
} else {
    header("Location: login3.html?error=invalid");
    exit;
}