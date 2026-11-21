<?php

session_start();

include 'config.php';

if (isset($_SESSION['user_id']) && isset($_SESSION['user_token'])) {

    $user_id = $_SESSION['user_id'];
    $current_token = $_SESSION['user_token'];

    $sql = "SELECT sessions FROM users WHERE id = $user_id LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        
        $user = mysqli_fetch_assoc($result);


        $session_array = json_decode($user['sessions'], true);

        if (is_array($session_array)) {


            $key = array_search($current_token, $session_array);

            if ($key !== false) {

                unset($session_array[$key]);

                $session_array = array_values($session_array);
                
                $updated_sessions_json = json_encode($session_array);

                $updated_sessions_json_safe = mysqli_real_escape_string($conn, $updated_sessions_json);

                mysqli_query($conn, "UPDATE users SET sessions = '$updated_sessions_json_safe' WHERE id = $user_id");
            }
        }
    }
}

// 5. Ab finally server se bhi session ko khali aur destroy kar do
session_unset();
session_destroy();

// 6. Main Patient Module login page par bhej do
header("Location: https://media.academic.vimhans.com/patient_module/login.php");
exit();