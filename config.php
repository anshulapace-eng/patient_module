<?php
// $mysqli = new mysqli("localhost", "vim1_acadmic", "iJHfsKMzafP84MD2", "vim_acadmic");
$mysqli = new mysqli("localhost", "root", "", "vim_acadmic");


if ($mysqli->connect_errno) {
    die("DB Connection failed: " . $mysqli->connect_error);
}

$conn = $mysqli;

if (!defined('PATIENT_SCOPE_ID')) {
    define('PATIENT_SCOPE_ID', 23);
}
?>