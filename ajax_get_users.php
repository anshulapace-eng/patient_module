<?php
// ajax_get_users.php
session_start();
include 'config.php'; // Database connection ($conn hona chahiye)

// Header set karein taaki browser ko pata chale ki response JSON format mein hai
header('Content-Type: application/json');

$response = [];

if (isset($conn)) {

    $search = trim($_GET['q'] ?? '');

    if (!empty($search)) {
        $searchTerm = "%" . $search . "%";

        $stmt = $conn->prepare("SELECT id, first_name, last_name, phone FROM users WHERE role_id = 2 AND (phone LIKE ? OR first_name LIKE ? OR last_name LIKE ?) LIMIT 20");
        
        if ($stmt) {
            $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $fullName = trim($row['first_name'] . ' ' . $row['last_name']);
                $phone = $row['phone'];
                
                $response[] = [
                    'id' => $row['id'],
                    'text' => "$fullName - $phone"
                ];
            }
            $stmt->close();
        }
    }
}

// JSON format mein data output karein
echo json_encode($response);
exit;
?>