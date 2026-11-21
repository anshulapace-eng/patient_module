<?php

// Load optional deployment configuration from a git-ignored .env file.
$envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || str_starts_with($envLine, '#') || !str_contains($envLine, '=')) {
            continue;
        }
        [$envKey, $envValue] = explode('=', $envLine, 2);
        $envKey = trim($envKey);
        $envValue = trim($envValue);
        if (($envValue[0] ?? '') === '"' || ($envValue[0] ?? '') === "'") {
            $envValue = trim($envValue, "\"'");
        }
        if ($envKey !== '' && getenv($envKey) === false) {
            putenv($envKey . '=' . $envValue);
            $_ENV[$envKey] = $envValue;
        }
    }
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = (int) (getenv('DB_PORT') ?: 3306);
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'vim_academic';

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = @new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

if ($mysqli->connect_errno) {
    http_response_code(500);
    error_log('Database connection failed: ' . $mysqli->connect_error);
    exit('Database connection failed. Please check the server database configuration.');
}

$conn = $mysqli;

if (!defined('PATIENT_SCOPE_ID')) {
    define('PATIENT_SCOPE_ID', 23);
}

function check_super_admin_scope() {
    // Session start ensure karne ke liye
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $role_id = isset($_SESSION['role']) ? (int) $_SESSION['role'] : 0;
    $has_null_patient = !isset($_SESSION['patient_id']) || $_SESSION['patient_id'] === null || $_SESSION['patient_id'] === '';

    return $role_id === 1 && $has_null_patient;
}