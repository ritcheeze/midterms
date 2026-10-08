<?php
$host   = "127.0.0.1";
$user   = "root";
$pass   = "";
$dbname = "lspu_portal";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Database Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>