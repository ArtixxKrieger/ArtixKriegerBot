<?php
$host = '163.44.242.17'; // Replace if needed
$dbname = 'ebvaxqhn_ArtixKriegerBot'; // Replace with your database name
$username = 'ebvaxqhn_ArtixKriegerBot'; // Replace with your username
$password = 'Ke$ylh16OlzQ'; // Replace with your password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connection successful!";
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>