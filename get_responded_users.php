<?php
include 'config.php';

$search = $_GET['q'] ?? '';
$assessment_id = isset($_GET['assessment_id']) ? intval($_GET['assessment_id']) : 0;
$results = [];

if (isset($conn)) {
    $search_term = "%" . $search . "%";
    
    $query = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.phone, u.email 
              FROM users u 
              JOIN assessment_responses ar ON u.id = ar.patient_id 
              WHERE (u.phone LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?) 
              LIMIT 20";

    if ($stmt = $conn->prepare($query)) {
        $stmt->bind_param("ssss", $search_term, $search_term, $search_term, $search_term);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $fname = $row['first_name'] ?? '';
            $lname = $row['last_name'] ?? '';
            $name = trim($fname . ' ' . ($lname !== '.' ? $lname : ''));
            $phone = $row['phone'] ?? '';
            
            $results[] = [
                'id' => $row['id'],
                'text' => $name . (!empty($phone) ? " (Phone: " . $phone . ")" : "")
            ];
        }
        $stmt->close();
    }
}

header('Content-Type: application/json');
echo json_encode($results);
?>