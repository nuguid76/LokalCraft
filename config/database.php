<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$user = "root";
$password = "1106((//@Ben)";
$database = "lokalcraft_db";

try {

    $conn = new mysqli(
        $host,
        $user,
        $password,
        $database
    );

    $conn->set_charset("utf8mb4");

} catch (mysqli_sql_exception $e) {

    error_log("Database connection failed: " . $e->getMessage());

    http_response_code(500);

    exit("Database temporarily unavailable. Please try again later.");

}

?>
