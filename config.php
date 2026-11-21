<?php
$mysqli = new mysqli("localhost", "root", "", "vim_academic");



if ($mysqli->connect_errno) {
    die("DB Connection failed: " . $mysqli->connect_error);
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

    if ($role_id === 1 && $has_null_patient) {
        return true;
    }

    return false;
}
?>