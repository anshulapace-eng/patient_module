<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require 'config.php';
require 'assessment-demographics.php';

$patientId = filter_var($_GET['patient_id'] ?? 0, FILTER_VALIDATE_INT);
$patientId = $patientId === false ? 0 : (int) $patientId;

if ($patientId < 1) {
    echo json_encode(['success' => false, 'data' => assessment_demographics_normalize([])]);
    exit;
}

$data = assessment_demographics_load_for_patient($conn, $patientId);
echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
