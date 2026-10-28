<?php
include 'config.php';

$search = trim($_GET['q'] ?? '');
$assessment_id = isset($_GET['assessment_id']) ? intval($_GET['assessment_id']) : 0;
$results = [];

if (isset($conn)) {
    // Sirf tabhi search karein jab input EXACTLY 10 digit ka number ho
    if (preg_match('/^[0-9]{10}$/', $search)) {
        
        if ($assessment_id > 0) {
            // Agar assessment ID hai, toh specific assessment filter karein
            $query = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.phone, u.email 
                      FROM users u 
                      JOIN assigned_assessments aa ON u.id = aa.patient_id 
                      WHERE u.role_id = 2
                      AND u.patient_id = " . PATIENT_SCOPE_ID . "
                      AND aa.assessment_id = ?
                      AND u.phone = ?
                      LIMIT 10";

            if ($stmt = $conn->prepare($query)) {
                $stmt->bind_param("is", $assessment_id, $search);
                $stmt->execute();
                $res = $stmt->get_result();

                while ($row = $res->fetch_assoc()) {
                    $fname = $row['first_name'] ?? '';
                    $lname = $row['last_name'] ?? '';
                    $name = trim($fname . ' ' . ($lname !== '.' ? $lname : ''));
                    
                    $results[] = [
                        'id' => $row['id'],
                        'text' => $name . " (Phone: " . $row['phone'] . ")"
                    ];
                }
                $stmt->close();
            }
        } else {
            // General assigned patient search ONLY by Phone
            $query = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.phone, u.email 
                      FROM users u 
                      JOIN assigned_assessments aa ON u.id = aa.patient_id 
                      WHERE u.role_id = 2
                      AND u.patient_id = " . PATIENT_SCOPE_ID . "
                      AND u.phone = ?
                      LIMIT 10";

            if ($stmt = $conn->prepare($query)) {
                $stmt->bind_param("s", $search);
                $stmt->execute();
                $res = $stmt->get_result();

                while ($row = $res->fetch_assoc()) {
                    $fname = $row['first_name'] ?? '';
                    $lname = $row['last_name'] ?? '';
                    $name = trim($fname . ' ' . ($lname !== '.' ? $lname : ''));
                    
                    $results[] = [
                        'id' => $row['id'],
                        'text' => $name . " (Phone: " . $row['phone'] . ")"
                    ];
                }
                $stmt->close();
            }
        }
    }
}

header('Content-Type: application/json');
echo json_encode($results);
?>