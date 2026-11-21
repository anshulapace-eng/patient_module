<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';
require 'ace-iii-assessment-schema.php';
require 'assessment-demographics.php';

$current_role = (int) ($_SESSION['role'] ?? 0);
$is_admin = $current_role === 1;
$is_patient = $current_role === 2;
$show_score_inputs = $is_admin;
$edit_record_id = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
$existing_record = null;
$error_msg = '';
$success_msg = '';

if (!isset($_SESSION['user_login']) || !in_array($current_role, [1, 2], true)) {
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        (isset($_POST['autosave_ace_iii']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
    ) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Session expired. Please login again.']);
        exit;
    }
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['ace_iii_csrf_token'])) {
    $_SESSION['ace_iii_csrf_token'] = bin2hex(random_bytes(32));
}

if ($edit_record_id > 0) {
    $existing_stmt = $conn->prepare(
        'SELECT * FROM ace_iii_assessments WHERE id = ? LIMIT 1'
    );
    if ($existing_stmt) {
        $existing_stmt->bind_param('i', $edit_record_id);
        $existing_stmt->execute();
        $existing_result = $existing_stmt->get_result();
        if ($existing_result && $existing_result->num_rows > 0) {
            $existing_record = $existing_result->fetch_assoc();
            if (!$is_admin && (int) $existing_record['patient_id'] !== (int) ($_SESSION['user_id'] ?? 0)) {
                $existing_record = null;
                $edit_record_id = 0;
                $error_msg = 'You do not have access to edit this record.';
            }
        }
        $existing_stmt->close();
    }
}

$page_title = "ACE-III Assessment - Indian English";
$sections = [
    'attention' => ['title' => 'Attention', 'max' => 18],
    'memory' => ['title' => 'Memory', 'max' => 26],
    'fluency' => ['title' => 'Fluency', 'max' => 14],
    'language' => ['title' => 'Language', 'max' => 26],
    'visuospatial' => ['title' => 'Visuospatial', 'max' => 16],
];
$score_fields = [
    'attention' => [
        'orientation_day' => 1,
        'orientation_date' => 1,
        'orientation_month' => 1,
        'orientation_year' => 1,
        'orientation_season' => 1,
        'orientation_floor' => 1,
        'orientation_street' => 1,
        'orientation_city' => 1,
        'orientation_state' => 1,
        'orientation_country' => 1,
        'registration' => 3,
        'subtraction' => 5,
    ],
    'memory' => [
        'word_recall' => 3,
        'address_learning' => 7,
        'address_recall' => 7,
        'address_recognition' => 5,
        'memory_chief_minister' => 1,
        'memory_prime_minister' => 1,
        'memory_actor' => 1,
        'memory_father_nation' => 1,
    ],
    'fluency' => ['letter_fluency' => 7, 'animal_fluency' => 7],
    'language' => [
        'command_1' => 1,
        'command_2' => 1,
        'command_3' => 1,
        'writing' => 2,
        'multisyllabic_repetition' => 2,
        'proverb_1' => 1,
        'proverb_2' => 1,
        'naming_1' => 1,
        'naming_2' => 1,
        'naming_3' => 1,
        'naming_4' => 1,
        'naming_5' => 1,
        'naming_6' => 1,
        'naming_7' => 1,
        'naming_8' => 1,
        'naming_9' => 1,
        'naming_10' => 1,
        'naming_11' => 1,
        'naming_12' => 1,
        'comprehension_1' => 1,
        'comprehension_2' => 1,
        'comprehension_3' => 1,
        'comprehension_4' => 1,
        'reading' => 1,
    ],
    'visuospatial' => [
        'infinity' => 1,
        'cube' => 2,
        'clock' => 5,
        'dots_1' => 1,
        'dots_2' => 1,
        'dots_3' => 1,
        'dots_4' => 1,
        'letters_1' => 1,
        'letters_2' => 1,
        'letters_3' => 1,
        'letters_4' => 1,
    ],
];

$patients = [];
$submitted_scores = (isset($_POST['scores']) && is_array($_POST['scores'])) ? $_POST['scores'] : [];
$raw_patient_id = isset($_POST['patient_id']) ? $_POST['patient_id'] : '';
$patient_id = filter_var($raw_patient_id, FILTER_VALIDATE_INT);
$patient_id = ($patient_id === false) ? 0 : (int) $patient_id;
if ($is_patient) {
    $patient_id = (int) ($_SESSION['user_id'] ?? 0);
}
$date_input = isset($_POST['assessment_date']) ? $_POST['assessment_date'] : date('Y-m-d');
$form_date = is_string($date_input) ? $date_input : date('Y-m-d');
$details = (isset($_POST['patient_details']) && is_array($_POST['patient_details'])) ? $_POST['patient_details'] : [];
$answers = (isset($_POST['answers']) && is_array($_POST['answers'])) ? $_POST['answers'] : [];
$notes = (isset($_POST['clinical_notes']) && is_string($_POST['clinical_notes'])) ? trim($_POST['clinical_notes']) : '';

/* =========================================================
   AJAX AUTOSAVE ENDPOINT (MUST RUN BEFORE ANY HTML OUTPUT)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autosave_ace_iii'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    if (!$ace_iii_schema_ready) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'Assessment table is not ready.']);
        exit;
    }

    $posted_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!is_string($posted_token) || !hash_equals($_SESSION['ace_iii_csrf_token'], $posted_token)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'Form session expired. Reload the page.']);
        exit;
    }

    if ($patient_id < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Select a patient before autosave.']);
        exit;
    }

    // Validate patient exists
    $patient_check = $conn->prepare(
        'SELECT id FROM users WHERE id = ? AND role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . ' LIMIT 1'
    );
    if (!$patient_check) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Could not verify patient.']);
        exit;
    }
    $patient_check->bind_param('i', $patient_id);
    $patient_check->execute();
    $patient_check->store_result();
    $patient_exists = $patient_check->num_rows === 1;
    $patient_check->close();
    if (!$patient_exists) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Selected patient not found.']);
        exit;
    }

    // Ensure valid date for SQL
    $safe_date = date('Y-m-d');
    if (is_string($form_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_date)) {
        [$y, $m, $d] = array_map('intval', explode('-', $form_date));
        if (checkdate($m, $d, $y)) {
            $safe_date = $form_date;
        }
    }

    // Truncate notes safely for draft
    $draft_notes = mb_substr($notes, 0, 5000);

    // Sanitize answers/details slightly so invalid non-string inputs don't break JSON
    $draft_answers = [];
    foreach ($answers as $k => $v) {
        if (!is_string($v)) {
            continue;
        }
        $is_drawing = strpos((string) $k, 'drawing_') === 0;
        if ($is_drawing) {
            if ($v === '' || (strlen($v) <= 500000 && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $v))) {
                $draft_answers[$k] = $v;
            }
        } else {
            $draft_answers[$k] = mb_substr($v, 0, 5000);
        }
    }

    $draft_scores = [];
    $draft_totals = [
        'attention' => 0,
        'memory' => 0,
        'fluency' => 0,
        'language' => 0,
        'visuospatial' => 0,
    ];

    if ($is_admin) {
        foreach ($score_fields as $section => $fields) {
            foreach ($fields as $field => $maximum) {
                $raw_score = $submitted_scores[$field] ?? null;
                $score = (is_string($raw_score) || is_int($raw_score))
                    ? filter_var($raw_score, FILTER_VALIDATE_INT)
                    : false;
                if ($score !== false && $score >= 0 && $score <= $maximum) {
                    $draft_scores[$field] = (int) $score;
                    $draft_totals[$section] += (int) $score;
                }
            }
        }
    }

    $draft_demographics = assessment_demographics_normalize($_POST['demographics'] ?? []);
    $draft_demographics['date'] = $draft_demographics['date'] ?: $safe_date;
    $draft_details = [];
    foreach ($details as $k => $v) {
        if (is_string($v)) {
            $draft_details[$k] = mb_substr($v, 0, 5000);
        }
    }
    $draft_details['_demographics'] = $draft_demographics;

    $draft_details_json = json_encode($draft_details, JSON_UNESCAPED_UNICODE);
    $draft_response_json = json_encode(['answers' => $draft_answers, 'scores' => $draft_scores], JSON_UNESCAPED_UNICODE);
    $draft_demographic_json = json_encode($draft_demographics, JSON_UNESCAPED_UNICODE);

    if ($draft_details_json === false || $draft_response_json === false || $draft_demographic_json === false) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Unable to prepare draft data.']);
        exit;
    }

    $draft_total = array_sum($draft_totals);
    $draft_clinician = $is_admin ? (int) ($_SESSION['user_id'] ?? 0) : 0;
    $draft_id_input = $_POST['draft_record_id'] ?? 0;
    $draft_id = filter_var($draft_id_input, FILTER_VALIDATE_INT);
    $draft_id = ($draft_id === false || $draft_id < 1) ? 0 : (int) $draft_id;
    $draft_status = 'Draft';

    // Verify existing draft ownership & preserve non-draft status if editing a saved record
    if ($draft_id > 0) {
        $check_draft = $conn->prepare('SELECT patient_id, status FROM ace_iii_assessments WHERE id = ? LIMIT 1');
        if ($check_draft) {
            $check_draft->bind_param('i', $draft_id);
            $check_draft->execute();
            $check_res = $check_draft->get_result();
            if ($check_res && $check_res->num_rows === 1) {
                $existing_row = $check_res->fetch_assoc();
                if ($is_patient && (int) $existing_row['patient_id'] !== (int) ($_SESSION['user_id'] ?? 0)) {
                    $check_draft->close();
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'message' => 'Unauthorized draft access.']);
                    exit;
                }
                if (!empty($existing_row['status']) && $existing_row['status'] !== 'Draft') {
                    $draft_status = (string) $existing_row['status'];
                }
            } else {
                $draft_id = 0; // Record no longer exists; insert new draft
            }
            $check_draft->close();
        }
    }

    if ($draft_id > 0) {
        $draft_stmt = $conn->prepare(
            'UPDATE ace_iii_assessments
             SET patient_id = ?, clinician_id = ?, assessment_date = ?, total_score = ?, attention_score = ?,
                 memory_score = ?, fluency_score = ?, language_score = ?, visuospatial_score = ?,
                 patient_details = ?, response_data = ?, clinical_notes = ?, status = ?, demographic_data = ?
             WHERE id = ? LIMIT 1'
        );
        if (!$draft_stmt) {
            error_log('ACE-III draft autosave update prepare failed: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Draft update could not be prepared.']);
            exit;
        }
        $draft_types = 'iis' . str_repeat('i', 6) . str_repeat('s', 5) . 'i';
        $draft_params = [
            $patient_id,
            $draft_clinician,
            $safe_date,
            $draft_total,
            $draft_totals['attention'],
            $draft_totals['memory'],
            $draft_totals['fluency'],
            $draft_totals['language'],
            $draft_totals['visuospatial'],
            $draft_details_json,
            $draft_response_json,
            $draft_notes,
            $draft_status,
            $draft_demographic_json,
            $draft_id,
        ];
        $draft_stmt->bind_param($draft_types, ...$draft_params);
    } else {
        $draft_stmt = $conn->prepare(
            'INSERT INTO ace_iii_assessments
                (patient_id, clinician_id, assessment_date, total_score, attention_score,
                 memory_score, fluency_score, language_score, visuospatial_score,
                 patient_details, response_data, clinical_notes, status, demographic_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$draft_stmt) {
            error_log('ACE-III draft autosave insert prepare failed: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Draft save could not be prepared.']);
            exit;
        }
        $draft_types = 'iis' . str_repeat('i', 6) . str_repeat('s', 5);
        $draft_params = [
            $patient_id,
            $draft_clinician,
            $safe_date,
            $draft_total,
            $draft_totals['attention'],
            $draft_totals['memory'],
            $draft_totals['fluency'],
            $draft_totals['language'],
            $draft_totals['visuospatial'],
            $draft_details_json,
            $draft_response_json,
            $draft_notes,
            $draft_status,
            $draft_demographic_json,
        ];
        $draft_stmt->bind_param($draft_types, ...$draft_params);
    }

    if (!$draft_stmt->execute()) {
        $draft_error = $draft_stmt->error;
        $draft_stmt->close();
        error_log('ACE-III draft autosave failed: ' . $draft_error);
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Draft could not be saved.']);
        exit;
    }

    if ($draft_id < 1) {
        $draft_id = (int) $draft_stmt->insert_id;
    }
    $draft_stmt->close();

    echo json_encode([
        'ok' => true,
        'draft_id' => $draft_id,
        'status' => $draft_status,
        'saved_at' => date('H:i:s'),
    ]);
    exit;
}

if ($existing_record && empty($_POST)) {
    $patient_id = (int) $existing_record['patient_id'];
    $form_date = $existing_record['assessment_date'] ?: $form_date;
    $details = json_decode((string) $existing_record['patient_details'], true);
    if (!is_array($details)) {
        $details = [];
    }
    $response_payload = json_decode((string) $existing_record['response_data'], true);
    $answers = is_array($response_payload['answers'] ?? null) ? $response_payload['answers'] : [];
    $submitted_scores = is_array($response_payload['scores'] ?? null) ? $response_payload['scores'] : [];
    $notes = (string) $existing_record['clinical_notes'];
}

if (!$ace_iii_schema_ready) {
    $error_msg = 'The assessment table could not be initialized. Please contact the system administrator.';
} else {
    if ($is_patient) {
        $patient_stmt = $conn->prepare(
            'SELECT id, first_name, last_name, email
             FROM users
             WHERE id = ? AND role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . ' LIMIT 1'
        );
        if ($patient_stmt) {
            $current_patient_id = (int) ($_SESSION['user_id'] ?? 0);
            $patient_stmt->bind_param('i', $current_patient_id);
            $patient_stmt->execute();
            $patient_result = $patient_stmt->get_result();
            while ($patient = $patient_result->fetch_assoc()) {
                $patients[] = $patient;
            }
            $patient_stmt->close();
        }
    } else {
        $patients_result = $conn->query(
            'SELECT id, first_name, last_name, email
             FROM users
             WHERE role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . '
             ORDER BY first_name, last_name'
        );
        if ($patients_result) {
            while ($patient = $patients_result->fetch_assoc()) {
                $patients[] = $patient;
            }
        } else {
            error_log('ACE-III patient list query failed: ' . $conn->error);
            $error_msg = 'The patient list could not be loaded. Please contact the system administrator.';
        }
    }
}

if ($patient_id < 1 && !$existing_record && !empty($patients)) {
    $patient_id = (int) $patients[0]['id'];
}
$ace_filled_by = trim((string) ($_SESSION['name'] ?? ''));
if ($ace_filled_by === '') {
    $ace_filled_by = 'Clinician';
}

/* =========================================================
   FINAL FORM SUBMIT HANDLER
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ace_iii']) && $ace_iii_schema_ready) {
    $posted_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!is_string($posted_token) || !hash_equals($_SESSION['ace_iii_csrf_token'], $posted_token)) {
        $error_msg = 'Your form session expired. Reload the page and submit the assessment again.';
    } elseif ($patient_id < 1) {
        $error_msg = 'Please select a valid patient.';
    }

    if ($error_msg === '') {
        if (!is_string($date_input) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_input)) {
            $error_msg = 'Please enter a valid assessment date.';
        } else {
            [$year, $month, $day] = array_map('intval', explode('-', $date_input));
            if (!checkdate($month, $day, $year)) {
                $error_msg = 'Please enter a valid assessment date.';
            }
        }
    }

    if ($error_msg === '' && strlen($notes) > 5000) {
        $error_msg = 'Clinical notes must be 5,000 characters or fewer.';
    }

    $scores = [];
    $section_totals = [];
    foreach ($score_fields as $section => $fields) {
        $section_totals[$section] = 0;
    }

    if ($show_score_inputs) {
        foreach ($score_fields as $section => $fields) {
            foreach ($fields as $field => $maximum) {
                $raw_score = isset($submitted_scores[$field]) ? $submitted_scores[$field] : null;
                $score = (is_string($raw_score) || is_int($raw_score))
                    ? filter_var($raw_score, FILTER_VALIDATE_INT)
                    : false;
                if ($score === false || $score < 0 || $score > $maximum) {
                    $error_msg = 'Enter a valid score for every item (within its displayed range).';
                    continue;
                }
                $scores[$field] = $score;
                $section_totals[$section] += $score;
            }
        }
        if (count($scores) !== array_sum(array_map('count', $score_fields)) && $error_msg === '') {
            $error_msg = 'Please complete every score field before saving.';
        }
    }

    foreach ([$details, $answers] as $posted_data) {
        foreach ($posted_data as $key => $value) {
            $is_drawing = strpos((string) $key, 'drawing_') === 0;
            $max_length = $is_drawing ? 500000 : 5000;
            if (
                !is_string($value) || strlen($value) > $max_length ||
                ($is_drawing && $value !== '' && !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $value))
            ) {
                $error_msg = 'Please enter valid responses (up to 5,000 characters each).';
                break 2;
            }
        }
    }

    if ($error_msg === '') {
        $patient_stmt = $conn->prepare(
            'SELECT id FROM users WHERE id = ? AND role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . ' LIMIT 1'
        );
        if (!$patient_stmt) {
            error_log('ACE-III patient validation prepare failed: ' . $conn->error);
            $error_msg = 'The selected patient could not be validated. Please try again.';
        } else {
            $patient_stmt->bind_param('i', $patient_id);
            $patient_stmt->execute();
            $patient_stmt->store_result();
            $patient_exists = $patient_stmt->num_rows === 1;
            $patient_stmt->close();
            if (!$patient_exists) {
                $error_msg = 'The selected patient could not be found.';
            }
        }
    }

    if ($error_msg === '') {
        if ($is_patient && empty($scores)) {
            $scores = [];
        }

        $demographics = assessment_demographics_normalize($_POST['demographics'] ?? []);
        $demographics['date'] = $demographics['date'] ?: $form_date;
        $details['_demographics'] = $demographics;
        $demographic_json = json_encode($demographics, JSON_UNESCAPED_UNICODE);
        $total_score = array_sum($section_totals);
        $patient_details_json = json_encode($details, JSON_UNESCAPED_UNICODE);
        $response_data_json = json_encode(
            ['answers' => $answers, 'scores' => $scores],
            JSON_UNESCAPED_UNICODE
        );

        if ($patient_details_json === false || $response_data_json === false || $demographic_json === false) {
            $error_msg = 'The assessment responses could not be prepared for saving.';
        } else {
            $current_user_id = (int) ($_SESSION['user_id'] ?? 0);
            $status_value = $show_score_inputs ? 'Success' : 'Pending';
            $clinician_id = $show_score_inputs ? $current_user_id : 0;

            // Check if updating an existing record OR upgrading an autosaved draft
            $posted_draft_id = filter_var($_POST['draft_record_id'] ?? 0, FILTER_VALIDATE_INT);
            $posted_draft_id = ($posted_draft_id === false || $posted_draft_id < 1) ? 0 : (int) $posted_draft_id;
            $target_record_id = ($edit_record_id > 0 && $existing_record) ? $edit_record_id : $posted_draft_id;

            if ($target_record_id > 0 && !$existing_record) {
                $verify_stmt = $conn->prepare('SELECT patient_id FROM ace_iii_assessments WHERE id = ? LIMIT 1');
                if ($verify_stmt) {
                    $verify_stmt->bind_param('i', $target_record_id);
                    $verify_stmt->execute();
                    $verify_res = $verify_stmt->get_result();
                    if ($verify_res && $verify_res->num_rows === 1) {
                        $row = $verify_res->fetch_assoc();
                        if ($is_patient && (int) $row['patient_id'] !== $current_user_id) {
                            $target_record_id = 0;
                        }
                    } else {
                        $target_record_id = 0;
                    }
                    $verify_stmt->close();
                } else {
                    $target_record_id = 0;
                }
            }

            if ($target_record_id > 0) {
                $update = $conn->prepare(
                    'UPDATE ace_iii_assessments
                     SET patient_id = ?, clinician_id = ?, assessment_date = ?, total_score = ?, attention_score = ?,
                         memory_score = ?, fluency_score = ?, language_score = ?, visuospatial_score = ?,
                         patient_details = ?, response_data = ?, clinical_notes = ?, status = ?, demographic_data = ?
                     WHERE id = ? LIMIT 1'
                );
                if (!$update) {
                    error_log('ACE-III update prepare failed: ' . $conn->error);
                    $error_msg = 'The assessment could not be updated. Please try again.';
                } else {
                    $update_types = 'iis' . str_repeat('i', 6) . str_repeat('s', 5) . 'i';
                    $update_params = [
                        $patient_id,
                        $clinician_id,
                        $form_date,
                        $total_score,
                        $section_totals['attention'],
                        $section_totals['memory'],
                        $section_totals['fluency'],
                        $section_totals['language'],
                        $section_totals['visuospatial'],
                        $patient_details_json,
                        $response_data_json,
                        $notes,
                        $status_value,
                        $demographic_json,
                        $target_record_id,
                    ];
                    $update->bind_param($update_types, ...$update_params);
                    if ($update->execute()) {
                        $update->close();
                        $_SESSION['ace_iii_csrf_token'] = bin2hex(random_bytes(32));
                        $_SESSION['ace_iii_saved'] = ($edit_record_id > 0)
                            ? 'ACE-III assessment updated successfully.'
                            : 'ACE-III assessment saved successfully.';
                        header('Location: ace-iii-assessment-records.php');
                        exit;
                    }
                    error_log('ACE-III update failed: ' . $update->error);
                    $update->close();
                    $error_msg = 'The assessment could not be updated. Please try again.';
                }
            } else {
                $insert = $conn->prepare(
                    'INSERT INTO ace_iii_assessments
                        (patient_id, clinician_id, assessment_date, total_score, attention_score,
                         memory_score, fluency_score, language_score, visuospatial_score,
                         patient_details, response_data, clinical_notes, status, demographic_data)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$insert) {
                    error_log('ACE-III insert prepare failed: ' . $conn->error);
                    $error_msg = 'The assessment could not be saved. Please try again.';
                } else {
                    $insert_types = 'iis' . str_repeat('i', 6) . str_repeat('s', 5);
                    $insert_params = [
                        $patient_id,
                        $clinician_id,
                        $form_date,
                        $total_score,
                        $section_totals['attention'],
                        $section_totals['memory'],
                        $section_totals['fluency'],
                        $section_totals['language'],
                        $section_totals['visuospatial'],
                        $patient_details_json,
                        $response_data_json,
                        $notes,
                        $status_value,
                        $demographic_json,
                    ];
                    $insert->bind_param($insert_types, ...$insert_params);
                    if ($insert->execute()) {
                        $insert->close();
                        $_SESSION['ace_iii_csrf_token'] = bin2hex(random_bytes(32));
                        $_SESSION['ace_iii_saved'] = 'ACE-III assessment saved successfully.';
                        header('Location: ace-iii-assessment-records.php');
                        exit;
                    }
                    error_log('ACE-III insert failed: ' . $insert->error);
                    $insert->close();
                    $error_msg = 'The assessment could not be saved. Please try again.';
                }
            }
        }
    }
}

if (isset($_SESSION['ace_iii_saved'])) {
    $success_msg = (string) $_SESSION['ace_iii_saved'];
    unset($_SESSION['ace_iii_saved']);
}

function ace_iii_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ace_iii_score_input($field, $maximum, $submitted_scores, $domain_label = 'Score')
{
    $value = isset($submitted_scores[$field]) ? $submitted_scores[$field] : '';
    return '<div class="ace-score-box">' .
        '<label class="ace-score-label" for="score_' . ace_iii_escape($field) . '">' .
        '<span class="ace-score-title">' . ace_iii_escape($domain_label) . '</span>' .
        '<span class="ace-score-range">[Score 0–' . (int) $maximum . ']</span>' .
        '</label>' .
        '<input class="form-control ace-score" type="number" id="score_' . ace_iii_escape($field) . '" ' .
        'name="scores[' . ace_iii_escape($field) . ']" min="0" max="' . (int) $maximum . '" step="1" ' .
        'value="' . ace_iii_escape($value) . '" placeholder="0" required ' .
        'aria-label="Score for ' . ace_iii_escape(str_replace('_', ' ', $field)) . '">' .
        '</div>';
}

function ace_iii_render_diagram($filename_base, $fallback_svg, $alt = 'ACE-III Diagram', $img_class = 'ace-model-img')
{
    foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
        $relative_path = 'assets/ace-iii/' . $filename_base . '.' . $ext;
        $full_path = __DIR__ . '/' . $relative_path;
        if (file_exists($full_path)) {
            $version = @filemtime($full_path);
            $src = $relative_path . ($version ? '?v=' . $version : '');
            return '<img src="' . ace_iii_escape($src) . '" alt="' . ace_iii_escape($alt) . '" class="' . ace_iii_escape($img_class) . '">';
        }
    }
    return $fallback_svg;
}

function ace_iii_get_picture_svg($index)
{
    $idx = (int) $index;
    $labels = [
        1 => 'Book',
        2 => 'Spoon',
        3 => 'Goat',
        4 => 'Candle',
        5 => 'Flag',
        6 => 'Camel',
        7 => 'Sickle',
        8 => 'Giraffe',
        9 => 'Drum',
        10 => 'Umbrella',
        11 => 'Pig',
        12 => 'Crocodile',
    ];
    $label = $labels[$idx] ?? ('ACE-III Picture ' . $idx);

    $svgs = [
        1 => '<svg viewBox="0 0 180 130" role="img" aria-label="Book">
            <path d="M44 26 L124 16 L138 26 L58 36 Z" fill="#f8fafc"/>
            <path d="M48 29 L127 19 M52 32 L131 22" stroke-width="1.2" opacity="0.7"/>
            <path d="M44 26 C38 28 38 34 44 36 L44 106 C38 104 38 98 44 96" fill="#e2e8f0"/>
            <path d="M44 26 L58 36 L58 114 L44 104 Z" fill="#cbd5e1"/>
            <polygon points="58,36 138,26 138,104 58,114" fill="#ffffff"/>
            <line x1="67" y1="35" x2="67" y2="113" stroke-width="1.6"/>
            <rect x="78" y="48" width="44" height="16" rx="1" transform="skewY(-7)" stroke-width="1.4" fill="#f8fafc"/>
            <line x1="85" y1="45" x2="114" y2="41.5" stroke-width="1.3"/>
            <line x1="88" y1="51" x2="111" y2="48" stroke-width="1.3"/>
        </svg>',
        2 => '<svg viewBox="0 0 180 130" role="img" aria-label="Spoon">
            <path d="M108 32 C126 20 152 22 154 36 C156 50 132 62 112 56 C104 54 98 52 92 57 L36 102 C29 107 21 100 27 93 L84 48 C92 42 100 37 108 32 Z" fill="#ffffff"/>
            <ellipse cx="131" cy="39" rx="18" ry="10" transform="rotate(-18 131 39)" fill="#f1f5f9" stroke-width="1.4"/>
            <path d="M118 39 C126 32 138 31 144 35" stroke-width="1.2" opacity="0.6"/>
            <path d="M35 95 L86 54" stroke-width="1.1" opacity="0.45"/>
        </svg>',
        3 => '<svg viewBox="0 0 180 130" role="img" aria-label="Goat">
            <path d="M44 60 C62 54 88 54 106 58 L120 34 L132 34 L144 43 L135 49 L126 47 L116 66 C118 74 114 82 106 84 L110 112 L103 112 L98 85 C82 87 64 85 54 82 L48 112 L41 112 L46 80 L36 110 L30 110 L38 72 C36 66 38 62 44 60 Z" fill="#ffffff"/>
            <path d="M106 84 L115 110 L109 112" fill="none"/>
            <path d="M44 60 C36 53 31 48 28 43 C35 45 41 50 46 58" fill="#e2e8f0"/>
            <path d="M122 34 C115 20 102 17 94 22 C104 21 112 26 118 35" fill="#cbd5e1"/>
            <path d="M126 34 C120 22 109 18 102 22" fill="none"/>
            <path d="M122 40 C119 48 121 55 125 57 C127 52 126 45 124 40" fill="#e2e8f0"/>
            <path d="M135 49 L137 59 L131 55 L129 48" fill="#f1f5f9"/>
            <circle cx="130" cy="40" r="1.8" fill="#0f172a" stroke="none"/>
            <line x1="41" y1="108" x2="48" y2="108" stroke-width="2"/>
            <line x1="103" y1="108" x2="110" y2="108" stroke-width="2"/>
        </svg>',
        4 => '<svg viewBox="0 0 180 130" role="img" aria-label="Candle">
            <ellipse cx="90" cy="112" rx="34" ry="7" fill="#f1f5f9" stroke-width="1.6"/>
            <path d="M68 50 L68 108 C68 113 112 113 112 108 L112 50" fill="#ffffff"/>
            <ellipse cx="90" cy="50" rx="22" ry="6" fill="#f8fafc"/>
            <path d="M68 50 C72 62 77 58 80 54 C83 66 90 66 93 55 C97 62 105 60 112 50" fill="#e2e8f0"/>
            <path d="M90 49 Q91 44 90 40" stroke-width="2.4"/>
            <path d="M90 12 C79 25 78 38 90 42 C102 38 101 25 90 12 Z" fill="#fef08a"/>
            <path d="M90 22 C85 29 85 37 90 40 C95 37 95 29 90 22 Z" fill="#fde047" stroke-width="1.2"/>
        </svg>',
        5 => '<svg viewBox="0 0 180 130" role="img" aria-label="Flag">
            <rect x="40" y="16" width="5" height="100" rx="2" fill="#cbd5e1"/>
            <circle cx="42.5" cy="14" r="4" fill="#94a3b8"/>
            <path d="M45 22 Q78 16 112 24 Q130 28 144 24 L144 74 Q130 78 112 74 Q78 66 45 72 Z" fill="#ffffff"/>
            <path d="M45 38 Q78 32 112 40 Q130 44 144 40" stroke-width="1.7"/>
            <path d="M45 55 Q78 49 112 57 Q130 61 144 57" stroke-width="1.7"/>
            <circle cx="94" cy="46" r="7.5" stroke-width="1.5"/>
            <circle cx="94" cy="46" r="1.6" fill="#0f172a" stroke="none"/>
            <line x1="94" y1="38.5" x2="94" y2="53.5" stroke-width="1.1"/>
            <line x1="86.5" y1="46" x2="101.5" y2="46" stroke-width="1.1"/>
            <line x1="88.7" y1="40.7" x2="99.3" y2="51.3" stroke-width="1.1"/>
            <line x1="99.3" y1="40.7" x2="88.7" y2="51.3" stroke-width="1.1"/>
            <path d="M32 116 L53 116" stroke-width="2.5"/>
        </svg>',
        6 => '<svg viewBox="0 0 180 130" role="img" aria-label="Camel">
            <path d="M44 62 C48 28 80 26 92 50 C99 58 106 58 113 44 C118 32 121 20 128 18 L142 18 C146 21 144 27 137 28 L130 29 C125 42 122 58 115 69 C112 76 109 80 104 82 L105 114 L99 114 L97 84 L90 114 L84 114 L88 82 C74 84 62 82 54 79 L50 114 L44 114 L47 81 L39 114 L33 114 L39 74 C37 68 39 64 44 62 Z" fill="#ffffff"/>
            <path d="M44 62 L34 80 L37 82 L43 68" fill="#e2e8f0"/>
            <circle cx="134" cy="22" r="1.6" fill="#0f172a" stroke="none"/>
            <path d="M126 18 L124 13 L129 16" fill="none"/>
            <path d="M56 44 C64 36 76 36 82 44" stroke-width="1.2" opacity="0.5"/>
        </svg>',
        7 => '<svg viewBox="0 0 180 130" role="img" aria-label="Sickle">
            <path d="M32 98 L68 72 C73 68 78 74 73 79 L39 106 C33 110 26 103 32 98 Z" fill="#e2e8f0"/>
            <path d="M67 71 L76 64 L81 71 L72 78 Z" fill="#94a3b8"/>
            <path d="M78 66 C100 50 144 50 138 20 C135 8 120 7 109 12 C124 15 127 28 117 40 C107 52 88 54 74 65 Z" fill="#ffffff"/>
            <path d="M84 62 C104 51 131 46 131 24" stroke-width="1.2" opacity="0.5"/>
            <line x1="42" y1="92" x2="48" y2="98" stroke-width="1.2" opacity="0.5"/>
            <line x1="52" y1="84" x2="58" y2="90" stroke-width="1.2" opacity="0.5"/>
        </svg>',
        8 => '<svg viewBox="0 0 180 130" role="img" aria-label="Giraffe">
            <path d="M50 70 L82 58 L112 14 L124 14 L132 20 L122 24 L117 23 L96 63 C98 70 96 77 91 80 L94 116 L88 116 L85 82 L79 116 L73 116 L77 81 C66 82 58 81 53 79 L49 116 L43 116 L47 81 L39 116 L33 116 L43 74 C43 72 46 71 50 70 Z" fill="#ffffff"/>
            <line x1="115" y1="14" x2="114" y2="8"/><circle cx="114" cy="7" r="1.8" fill="#0f172a" stroke="none"/>
            <line x1="120" y1="14" x2="120" y2="8"/><circle cx="120" cy="7" r="1.8" fill="#0f172a" stroke="none"/>
            <path d="M43 74 L35 92" stroke-width="1.6"/>
            <circle cx="122" cy="18" r="1.5" fill="#0f172a" stroke="none"/>
            <polygon points="101,30 107,28 105,35 99,36" fill="#94a3b8" stroke="none"/>
            <polygon points="94,42 100,40 99,48 92,47" fill="#94a3b8" stroke="none"/>
            <polygon points="86,54 93,53 92,61 85,60" fill="#94a3b8" stroke="none"/>
            <polygon points="71,65 79,63 80,71 71,72" fill="#94a3b8" stroke="none"/>
            <polygon points="83,68 91,66 91,75 83,75" fill="#94a3b8" stroke="none"/>
            <polygon points="56,70 65,68 65,76 56,76" fill="#94a3b8" stroke="none"/>
            <polygon points="67,75 75,74 74,80 66,80" fill="#94a3b8" stroke="none"/>
        </svg>',
        9 => '<svg viewBox="0 0 180 130" role="img" aria-label="Drum">
            <path d="M40 54 C42 96 65 110 90 110 C115 110 138 96 140 54" fill="#f8fafc"/>
            <ellipse cx="90" cy="54" rx="50" ry="14" fill="#ffffff"/>
            <ellipse cx="90" cy="54" rx="42" ry="10.5" fill="#f1f5f9" stroke-width="1.3"/>
            <path d="M42 60 L54 94 L66 64 L78 104 L90 66 L102 104 L114 64 L126 94 L138 60" stroke-width="1.7"/>
            <path d="M48 84 Q90 95 132 84" stroke-width="1.5"/>
            <line x1="68" y1="40" x2="122" y2="20" stroke-width="3.2"/>
            <circle cx="67" cy="40" r="3.8" fill="#0f172a" stroke="none"/>
            <line x1="80" y1="48" x2="134" y2="28" stroke-width="3.2"/>
            <circle cx="79" cy="48" r="3.8" fill="#0f172a" stroke="none"/>
        </svg>',
        10 => '<svg viewBox="0 0 180 130" role="img" aria-label="Umbrella">
            <path d="M30 64 C38 20 112 12 138 50 Z" fill="#f8fafc"/>
            <path d="M30 64 Q48 54 66 59 Q84 50 102 56 Q120 46 138 50" fill="#ffffff"/>
            <path d="M78 24 Q60 40 66 59 M78 24 Q92 38 102 56 M78 24 Q44 40 30 64 M78 24 Q114 34 138 50" stroke-width="1.4"/>
            <line x1="76" y1="16" x2="78" y2="24" stroke-width="2.8"/>
            <path d="M84 54 L108 104 C112 112 100 118 95 110 L93 105" stroke-width="3"/>
        </svg>',
        11 => '<svg viewBox="0 0 180 130" role="img" aria-label="Pig">
            <path d="M52 56 C64 38 108 36 130 50 C142 56 142 74 128 82 L126 108 L119 108 L119 84 C105 88 82 88 68 84 L64 108 L57 108 L59 82 L50 106 L43 106 L50 78 C41 75 36 71 29 71 L27 61 L39 59 C42 54 46 52 52 56 Z" fill="#ffffff"/>
            <ellipse cx="28" cy="66" rx="3" ry="5" fill="#e2e8f0"/>
            <path d="M56 52 C49 44 42 46 46 56" fill="#e2e8f0"/>
            <circle cx="43" cy="60" r="1.8" fill="#0f172a" stroke="none"/>
            <path d="M138 59 C149 52 153 62 144 64 C151 66 151 73 143 72" fill="none" stroke-width="2"/>
            <line x1="110" y1="86" x2="108" y2="106" stroke-width="2.2"/>
        </svg>',
        12 => '<svg viewBox="0 0 180 130" role="img" aria-label="Crocodile">
            <path d="M14 72 C26 84 48 82 72 72 L126 68 L166 56 L140 69 L166 72 L130 78 L74 84 C46 92 24 88 14 72 Z" fill="#f8fafc"/>
            <polyline points="142,63 145,66 148,62 151,65 154,61 157,64 160,60" stroke-width="1.2"/>
            <polyline points="142,72 146,69 149,72 153,69 156,72 160,69" stroke-width="1.2"/>
            <path d="M78 83 L71 98 L81 98 L85 83" fill="#e2e8f0"/>
            <path d="M116 78 L111 96 L121 96 L123 78" fill="#e2e8f0"/>
            <path d="M28 76 L33 71 L38 75 L43 70 L48 74 L54 69 L60 73 L66 68 L72 72" stroke-width="1.5"/>
            <line x1="74" y1="75" x2="122" y2="71" stroke-dasharray="5,3" stroke-width="1.4"/>
            <circle cx="131" cy="64" r="1.8" fill="#0f172a" stroke="none"/>
        </svg>',
    ];

    $fallback = $svgs[$idx] ?? '';
    return ace_iii_render_diagram('pic_' . $idx, $fallback, $label, 'ace-real-img');
}

function ace_iii_get_fragmented_letter_svg($index)
{
    $letters = [
        // K
        1 => '<svg viewBox="0 0 120 120" class="ace-frag-svg" role="img" aria-label="Fragmented letter 1">
            <defs>
                <mask id="aceFragMask1">
                    <rect width="120" height="120" fill="#ffffff"/>
                    <circle cx="32" cy="36" r="10" fill="#000"/>
                    <circle cx="34" cy="74" r="11" fill="#000"/>
                    <circle cx="62" cy="48" r="12" fill="#000"/>
                    <circle cx="78" cy="28" r="9" fill="#000"/>
                    <circle cx="68" cy="76" r="11" fill="#000"/>
                    <circle cx="88" cy="96" r="8" fill="#000"/>
                    <rect x="20" y="54" width="24" height="7" fill="#000"/>
                </mask>
            </defs>
            <path d="M26 16 H40 V54 L76 16 H94 L56 58 L96 104 H78 L40 60 V104 H26 Z" fill="#0f172a" mask="url(#aceFragMask1)"/>
        </svg>',
        // M
        2 => '<svg viewBox="0 0 120 120" class="ace-frag-svg" role="img" aria-label="Fragmented letter 2">
            <defs>
                <mask id="aceFragMask2">
                    <rect width="120" height="120" fill="#ffffff"/>
                    <circle cx="22" cy="42" r="11" fill="#000"/>
                    <circle cx="24" cy="82" r="10" fill="#000"/>
                    <circle cx="44" cy="46" r="11" fill="#000"/>
                    <circle cx="60" cy="76" r="10" fill="#000"/>
                    <circle cx="76" cy="44" r="11" fill="#000"/>
                    <circle cx="98" cy="36" r="10" fill="#000"/>
                    <circle cx="96" cy="78" r="11" fill="#000"/>
                </mask>
            </defs>
            <path d="M16 104 V16 H32 L60 66 L88 16 H104 V104 H90 V40 L65 84 H55 L30 40 V104 Z" fill="#0f172a" mask="url(#aceFragMask2)"/>
        </svg>',
        // A
        3 => '<svg viewBox="0 0 120 120" class="ace-frag-svg" role="img" aria-label="Fragmented letter 3">
            <defs>
                <mask id="aceFragMask3">
                    <rect width="120" height="120" fill="#ffffff"/>
                    <circle cx="60" cy="24" r="9" fill="#000"/>
                    <circle cx="44" cy="52" r="11" fill="#000"/>
                    <circle cx="78" cy="56" r="11" fill="#000"/>
                    <circle cx="32" cy="86" r="10" fill="#000"/>
                    <circle cx="88" cy="86" r="10" fill="#000"/>
                    <circle cx="60" cy="74" r="9" fill="#000"/>
                </mask>
            </defs>
            <path d="M52 16 H68 L100 104 H85 L77 80 H43 L35 104 H20 Z M47 68 H73 L60 30 Z" fill="#0f172a" fill-rule="evenodd" mask="url(#aceFragMask3)"/>
        </svg>',
        // T
        4 => '<svg viewBox="0 0 120 120" class="ace-frag-svg" role="img" aria-label="Fragmented letter 4">
            <defs>
                <mask id="aceFragMask4">
                    <rect width="120" height="120" fill="#ffffff"/>
                    <circle cx="38" cy="22" r="10" fill="#000"/>
                    <circle cx="82" cy="22" r="10" fill="#000"/>
                    <circle cx="60" cy="48" r="11" fill="#000"/>
                    <circle cx="60" cy="84" r="11" fill="#000"/>
                    <rect x="50" y="64" width="20" height="8" fill="#000"/>
                </mask>
            </defs>
            <path d="M18 16 H102 V30 H67 V104 H53 V30 H18 Z" fill="#0f172a" mask="url(#aceFragMask4)"/>
        </svg>',
    ];

    $fallback = isset($letters[$index]) ? $letters[$index] : '';
    return ace_iii_render_diagram('letter_' . (int) $index, $fallback, 'Fragmented Letter ' . (int) $index, 'ace-real-img');
}

require 'layout/base.php';
?>
<style>
    :root {
        --ace-border: #e2e8f0;
        --ace-soft-border: #f1f5f9;
        --ace-bg-head: #f8fafc;
        --ace-primary: #0f766e;
        --ace-primary-soft: #f0fdfa;
        --ace-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
        --ace-shadow-sm: 0 2px 8px rgba(15, 23, 42, 0.04);
    }

    .ace-page {
        max-width: 1120px;
        margin: 0 auto;
        padding: 20px 14px 40px;
        color: #1e293b;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    .ace-role-patient .ace-score-box,
    .ace-role-patient .ace-inline-score,
    .ace-role-patient .ace-live-pill,
    .ace-role-patient .ace-score-range,
    .ace-role-patient .ace-score-label,
    .ace-role-patient .ace-clock-reference {
        display: none !important;
    }

    .ace-real-img {
        max-width: 100%;
        max-height: 100%;
        width: auto;
        height: auto;
        object-fit: contain;
        display: block;
    }

    .ace-model-img {
        max-width: 240px;
        max-height: 130px;
        width: auto;
        height: auto;
        object-fit: contain;
        display: block;
    }

    .ace-sheet-banner {
        background: #fff;
        border: 1px solid var(--ace-border);
        border-top: 4px solid var(--ace-primary);
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        box-shadow: var(--ace-shadow);
    }
    .ace-sheet-banner h1 {
        margin: 0;
        font-size: 25px;
        font-weight: 800;
        letter-spacing: 0.2px;
        color: #0f172a;
        text-transform: uppercase;
    }
    .ace-sheet-banner h2 {
        margin: 4px 0 0;
        font-size: 16.5px;
        font-weight: 600;
        color: #64748b;
    }
    .ace-nav a {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 18px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #fff;
        color: #334155;
        font-size: 14.5px;
        font-weight: 700;
        text-decoration: none;
        box-shadow: var(--ace-shadow-sm);
        transition: all 0.15s ease;
    }
    .ace-nav a:hover {
        background: var(--ace-primary);
        border-color: var(--ace-primary);
        color: #fff;
    }

    .ace-paper-box {
        background: #fff;
        border: 1px solid rgba(226, 232, 240, 0.85);
        border-radius: 14px;
        margin-bottom: 24px;
        box-shadow: var(--ace-shadow);
        overflow: hidden;
    }
    .ace-domain-bar {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--ace-border);
        border-left: 4px solid var(--ace-primary);
        padding: 13px 20px;
        font-size: 15.5px;
        font-weight: 800;
        letter-spacing: 0.5px;
        color: #0f172a;
        text-transform: uppercase;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .ace-domain-bar span.ace-live-pill {
        background: var(--ace-primary-soft);
        color: var(--ace-primary);
        border: 1px solid #99f6e4;
        padding: 5px 14px;
        border-radius: 999px;
        font-size: 14px;
        font-weight: 700;
    }

    .ace-demo-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }
    .ace-demo-col {
        padding: 18px 20px;
    }
    .ace-demo-col:first-child {
        border-right: 1px solid var(--ace-soft-border);
    }
    .ace-demo-row {
        display: grid;
        grid-template-columns: 190px 1fr;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
    }
    .ace-demo-row:last-child {
        margin-bottom: 0;
    }
    .ace-demo-row label {
        font-size: 15px;
        font-weight: 600;
        color: #334155;
        margin: 0;
    }

    .ace-page .form-control,
    .ace-page .form-select,
    .ace-page .form-control-sm,
    .ace-page .form-select-sm {
        font-size: 15px !important;
        padding: 7px 11px;
    }

    .ace-split-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 175px;
        border-bottom: 1px solid var(--ace-soft-border);
    }
    .ace-split-row:last-child {
        border-bottom: none;
    }
    .ace-split-left {
        padding: 18px 20px;
        border-right: 1px solid var(--ace-soft-border);
    }
    .ace-split-left p,
    .ace-split-left label,
    .ace-split-left > div.fw-bold,
    .ace-split-left .d-flex > span,
    .ace-split-left .col-md-4.fw-bold {
        font-size: 15.5px !important;
        line-height: 1.55;
    }
    .ace-split-left .text-muted {
        font-size: 14px !important;
    }
    .ace-split-left .text-center.fw-bold {
        font-size: 22px !important;
    }
    .ace-split-right {
        padding: 16px 12px;
        background: #fafaf9;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        gap: 10px;
    }

    .ace-score-box {
        width: 100%;
        text-align: center;
        background: #fff;
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        padding: 10px 8px;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-score-label {
        display: block;
        margin-bottom: 6px;
        line-height: 1.25;
        cursor: pointer;
    }
    .ace-score-title {
        display: block;
        font-size: 13px;
        font-weight: 800;
        color: var(--ace-primary);
        text-transform: uppercase;
    }
    .ace-score-range {
        display: block;
        font-size: 13px;
        font-weight: 500;
        color: #64748b;
    }
    .ace-score {
        width: 82px !important;
        height: 42px !important;
        margin: 0 auto;
        text-align: center;
        font-weight: 800 !important;
        font-size: 18px !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 7px !important;
        background: #f8fafc !important;
        transition: all 0.15s ease;
    }
    .ace-score:focus {
        background: #fff !important;
        border-color: var(--ace-primary) !important;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14) !important;
    }

    .ace-bullet-list {
        margin: 0 0 12px;
        padding-left: 18px;
        font-size: 15.5px;
        line-height: 1.6;
        color: #334155;
    }
    .ace-bullet-list li {
        margin-bottom: 6px;
    }

    .ace-orient-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 8px;
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-orient-table th,
    .ace-orient-table td {
        border-right: 1px solid var(--ace-border);
        border-bottom: 1px solid var(--ace-border);
        padding: 10px;
        vertical-align: top;
    }
    .ace-orient-table th:last-child,
    .ace-orient-table td:last-child {
        border-right: none;
    }
    .ace-orient-table tbody tr:last-child td {
        border-bottom: none;
    }
    .ace-orient-table th {
        background: #f8fafc;
        font-size: 14px;
        font-weight: 700;
        color: #475569;
    }
    .ace-inline-score {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed #e2e8f0;
        font-size: 13px;
        font-weight: 600;
        color: #64748b;
    }
    .ace-inline-score input {
        width: 62px !important;
        height: 36px !important;
        padding: 2px 4px !important;
        text-align: center;
        font-size: 16px !important;
        font-weight: 800;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
    }

    .ace-scale-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        margin-top: 6px;
        background: #fff;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-scale-table td, .ace-scale-table th {
        border: 1px solid var(--ace-border);
        padding: 4px 6px;
        text-align: center;
        color: #475569;
    }

    .ace-address-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 10px;
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-address-table th, .ace-address-table td {
        border-right: 1px solid var(--ace-border);
        border-bottom: 1px solid var(--ace-border);
        padding: 10px 12px;
        vertical-align: top;
        font-size: 15px;
    }
    .ace-address-table th:last-child, .ace-address-table td:last-child {
        border-right: none;
    }
    .ace-address-table tbody tr:last-child td {
        border-bottom: none;
    }
    .ace-address-table th {
        background: #f8fafc;
        font-weight: 700;
        color: #334155;
    }

    .ace-pic-grid-12 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-top: 12px;
    }
    .ace-pic-cell {
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        padding: 12px;
        background: #fff;
        box-shadow: var(--ace-shadow-sm);
        display: flex;
        flex-direction: column;
        gap: 10px;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .ace-pic-cell:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 14px rgba(15, 23, 42, 0.06);
    }
    .ace-pic-top-bar {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .ace-pic-svg-wrap {
        height: 125px;
        padding: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        border: 1px solid var(--ace-soft-border);
        border-radius: 8px;
    }
    .ace-pic-svg-wrap svg {
        width: 155px;
        height: 108px;
        fill: none;
        stroke: #0f172a;
        stroke-width: 2.2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .ace-visuo-grid {
        display: grid;
        grid-template-columns: 270px minmax(0, 1fr);
        gap: 16px;
        align-items: stretch;
        margin-top: 10px;
    }
    .ace-stimulus-card {
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        background: #f8fafc;
        padding: 14px;
        box-shadow: var(--ace-shadow-sm);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
    .ace-stimulus-card span {
        font-size: 13.5px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 8px;
    }
    .ace-model-svg {
        width: 100%;
        max-width: 230px;
        height: 120px;
    }
    .ace-model-svg path, .ace-model-svg line, .ace-model-svg circle, .ace-model-svg rect {
        fill: none;
        stroke: #1e293b;
        stroke-width: 3;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .ace-canvas-card {
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-canvas-bar {
        background: #f8fafc;
        border-bottom: 1px solid var(--ace-border);
        padding: 8px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13.5px;
        font-weight: 600;
        color: #475569;
    }
    .ace-canvas {
        display: block;
        width: 100%;
        height: 190px;
        cursor: crosshair;
        touch-action: none;
        background: #fff;
    }

    .ace-quad-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin-top: 10px;
    }
    .ace-quad-box {
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        padding: 14px;
        background: #fff;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-dot-frame {
        height: 125px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        position: relative;
        background: #f8fafc;
        margin-bottom: 10px;
    }
    .ace-dot-frame i {
        position: absolute;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #1e293b;
    }
    .ace-frag-frame {
        height: 135px;
        padding: 8px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        margin-bottom: 10px;
    }
    .ace-frag-svg {
        width: 115px;
        height: 115px;
    }

    .ace-recog-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 10px;
        font-size: 15px;
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-recog-table th, .ace-recog-table td {
        border-right: 1px solid var(--ace-border);
        border-bottom: 1px solid var(--ace-border);
        padding: 10px 14px;
    }
    .ace-recog-table td:last-child {
        border-right: none;
    }
    .ace-recog-table tbody tr:last-child td {
        border-bottom: none;
    }

    .ace-sticky-footer {
        position: sticky;
        bottom: 12px;
        z-index: 50;
        background: rgba(15, 23, 42, 0.95);
        backdrop-filter: blur(8px);
        color: #fff;
        border-radius: 12px;
        padding: 13px 20px;
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        margin-top: 24px;
    }
    .ace-sticky-scores {
        display: flex;
        align-items: center;
        gap: 18px;
        flex-wrap: wrap;
        font-size: 15px;
    }
    .ace-sticky-scores span {
        color: #94a3b8;
    }
    .ace-sticky-scores strong {
        color: #5eead4;
        font-size: 17px;
        font-weight: 800;
        margin-left: 3px;
    }
    .ace-total-pill {
        background: var(--ace-primary);
        color: #fff;
        padding: 7px 16px;
        border-radius: 8px;
        font-weight: 800;
        font-size: 16px;
    }
    .ace-autosave-badge {
        font-size: 13.5px;
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.1);
        color: #cbd5e1;
        white-space: nowrap;
    }
    .ace-autosave-badge.is-saved {
        background: rgba(16, 185, 129, 0.18);
        color: #6ee7b7;
    }
    .ace-autosave-badge.is-saving {
        background: rgba(56, 189, 248, 0.18);
        color: #7dd3fc;
    }
    .ace-autosave-badge.is-dirty {
        background: rgba(251, 191, 36, 0.18);
        color: #fde68a;
    }
    .ace-autosave-badge.is-error {
        background: rgba(239, 68, 68, 0.22);
        color: #fca5a5;
    }

    .ace-toast {
        padding: 13px 18px;
        border-radius: 10px;
        margin-bottom: 16px;
        font-size: 15px;
        font-weight: 600;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-toast-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .ace-toast-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }

    .ace-recog-choices-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-top: 10px;
    margin-bottom: 14px;
    font-size: 14.5px;
    border: 1px solid var(--ace-border);
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
    box-shadow: var(--ace-shadow-sm);
}
.ace-recog-choices-table td {
    border-right: 1px solid var(--ace-border);
    border-bottom: 1px solid var(--ace-border);
    padding: 8px 12px;
    vertical-align: middle;
}
.ace-recog-choices-table td:last-child {
    border-right: none;
    background: #f1f5f9;
    width: 115px;
    text-align: center;
    font-weight: 700;
    color: #334155;
}
.ace-recog-choices-table tbody tr:last-child td {
    border-bottom: none;
}
.ace-recog-opt {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    cursor: pointer;
    margin: 0;
    font-weight: 600;
    color: #1e293b;
}
.ace-recog-opt input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: var(--ace-primary);
}

    @media (max-width: 960px) {
        .ace-demo-grid { grid-template-columns: 1fr; }
        .ace-demo-col:first-child { border-right: none; border-bottom: 1px solid var(--ace-soft-border); }
        .ace-split-row { grid-template-columns: 1fr; }
        .ace-split-left { border-right: none; border-bottom: 1px solid var(--ace-soft-border); }
        .ace-pic-grid-12 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ace-visuo-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .ace-demo-row { grid-template-columns: 1fr; gap: 4px; }
        .ace-pic-grid-12, .ace-quad-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="ace-page<?php echo !$show_score_inputs ? ' ace-role-patient' : ''; ?>">
    <header class="ace-sheet-banner">
        <div>
            <h1>ADDENBROOKE'S COGNITIVE EXAMINATION - ACE-III</h1>
            <h2>Indian English</h2>
        </div>
        <nav class="ace-nav" aria-label="ACE-III navigation">
            <a href="ace-iii-assessment-records.php">View Saved Records</a>
        </nav>
    </header>

    <?php if ($error_msg !== ''): ?>
        <div class="ace-toast ace-toast-error" role="alert"><?php echo ace_iii_escape($error_msg); ?></div>
    <?php endif; ?>
    <?php if ($success_msg !== ''): ?>
        <div class="ace-toast ace-toast-success" role="status"><?php echo ace_iii_escape($success_msg); ?></div>
    <?php endif; ?>

    <?php if (!$show_score_inputs): ?>
        <div class="ace-toast ace-toast-success" role="status">Response mode enabled: enter the patient response; scoring will be entered by the examiner.</div>
    <?php endif; ?>

    <form method="post" id="aceForm">
        <input type="hidden" name="csrf_token" value="<?php echo ace_iii_escape($_SESSION['ace_iii_csrf_token']); ?>">
        <input type="hidden" name="draft_record_id" id="draft_record_id" value="<?php echo ($edit_record_id > 0 && $existing_record) ? (int) $edit_record_id : (int) ($_POST['draft_record_id'] ?? 0); ?>">

        <!-- TOP DEMOGRAPHICS TABLE (PAGE 1 HEADER) -->
        <section class="ace-paper-box">
            <div class="ace-demo-grid">
                <div class="ace-demo-col">
                    <div class="ace-demo-row">
                        <label for="patient_id">Name (Patient): *</label>
                        <?php if ($is_patient): ?>
                            <input type="hidden" name="patient_id" value="<?php echo (int) $patient_id; ?>">
                            <input class="form-control form-control-sm" type="text" value="<?php echo ace_iii_escape(trim(($patients[0]['first_name'] ?? '') . ' ' . ($patients[0]['last_name'] ?? '')) ?: ($patients[0]['email'] ?? 'Current Patient')); ?>" readonly>
                        <?php else: ?>
                            <select class="form-select form-select-sm" id="patient_id" name="patient_id" required>
                                <option value="">-- Select Patient --</option>
                                <?php foreach ($patients as $patient): ?>
                                    <option value="<?php echo (int) $patient['id']; ?>" <?php echo $patient_id === (int) $patient['id'] ? 'selected' : ''; ?>>
                                        <?php echo ace_iii_escape(trim($patient['first_name'] . ' ' . $patient['last_name']) ?: $patient['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ace-demo-col">
                    <div class="ace-demo-row">
                        <label for="assessment_date">Date: *</label>
                        <input class="form-control form-control-sm" type="date" id="assessment_date" name="assessment_date" value="<?php echo ace_iii_escape($form_date); ?>" required>
                    </div>
                    <?php
                    $ace_demographics = is_array($details['_demographics'] ?? null) ? assessment_demographics_normalize($details['_demographics']) : assessment_demographics_normalize([]);
                    $ace_demographics['date'] = $form_date;
                    $ace_demographics['filled_by'] = $ace_demographics['filled_by'] ?: $ace_filled_by;
                    ?>
                    <div class="ace-demo-row">
                        <label for="demographics_filled_by">FILLED BY:</label>
                        <input class="form-control form-control-sm" id="demographics_filled_by" name="demographics[filled_by]" value="<?php echo ace_iii_escape($ace_demographics['filled_by']); ?>" readonly>
                    </div>
                </div>
            </div>
            <input type="hidden" name="demographics[date]" id="demographics_date_hidden" value="<?php echo ace_iii_escape($form_date); ?>">
            <?php echo assessment_demographics_html('demographics', $ace_demographics, ['hide_fields' => ['date', 'client_name', 'filled_by']]); ?>
        </section>

        <!-- PAGE 1: ATTENTION -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>ATTENTION</span>
                <span class="ace-live-pill">Subtotal: <strong id="badge_attention">0</strong> / 18</span>
            </div>

            <!-- Orientation Row 1 & 2 -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <div class="fw-bold mb-1" style="font-size:13px;">&#10148; Ask: What is the</div>
                    <div class="table-responsive">
                        <table class="ace-orient-table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Date</th>
                                    <th>Month</th>
                                    <th>Year</th>
                                    <th>Season</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <?php foreach (['orientation_day' => 'Day', 'orientation_date' => 'Date', 'orientation_month' => 'Month', 'orientation_year' => 'Year', 'orientation_season' => 'Season'] as $field => $label): ?>
                                        <td>
                                            <input class="form-control form-control-sm" name="answers[<?php echo $field; ?>]" value="<?php echo ace_iii_escape($answers[$field] ?? ''); ?>" placeholder="<?php echo $label; ?>">
                                            <div class="ace-inline-score">
                                                <span>Score (0–1)</span>
                                                <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="fw-bold mt-3 mb-1" style="font-size:13px;">&#10148; Ask: Which</div>
                    <div class="table-responsive">
                        <table class="ace-orient-table">
                            <thead>
                                <tr>
                                    <th>No./Floor</th>
                                    <th>Street/Hospital</th>
                                    <th>City</th>
                                    <th>State</th>
                                    <th>Country</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <?php foreach (['orientation_floor' => 'No./Floor', 'orientation_street' => 'Street/Hospital', 'orientation_city' => 'City', 'orientation_state' => 'State', 'orientation_country' => 'Country'] as $field => $label): ?>
                                        <td>
                                            <input class="form-control form-control-sm" name="answers[<?php echo $field; ?>]" value="<?php echo ace_iii_escape($answers[$field] ?? ''); ?>" placeholder="<?php echo $label; ?>">
                                            <div class="ace-inline-score">
                                                <span>Score (0–1)</span>
                                                <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Attention</span>
                        <span class="ace-score-range">[Score 0–5]</span>
                        <div class="fw-bold mt-1 fs-5" id="orient_time_sub">0</div>
                    </div>
                    <div class="ace-score-box">
                        <span class="ace-score-title">Attention</span>
                        <span class="ace-score-range">[Score 0–5]</span>
                        <div class="fw-bold mt-1 fs-5" id="orient_place_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Word Registration -->
            <div class="ace-domain-bar"><span>ATTENTION</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <ul class="ace-bullet-list">
                        <li><strong>Tell:</strong> "I'm going to give you three words and I'd like you to repeat them after me: <strong>lemon, key and ball</strong>." After subject repeats, say "Try to remember them because I'm going to ask you later".</li>
                        <li>Score <u>only</u> the first trial (repeat 3 times if necessary).</li>
                        <li>Register number of trials:</li>
                    </ul>
                    <input class="form-control form-control-sm" name="answers[registration_trials]" value="<?php echo ace_iii_escape($answers['registration_trials'] ?? ''); ?>" placeholder="Record subject's response &amp; number of trials">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('registration', 3, $submitted_scores, 'Attention'); ?>
                </div>
            </div>

            <!-- Serial 7 Subtraction -->
            <div class="ace-domain-bar"><span>ATTENTION</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <ul class="ace-bullet-list">
                        <li>Ask the subject: "Could you take 7 away from 100? I'd like you to keep taking 7 away from each new number until I tell you to stop."</li>
                        <li>If subject makes a mistake, do not stop them. Let the subject carry on and check subsequent answers (e.g., 93, 84, 77, 70, 63 - score 4).</li>
                        <li>Stop after five subtractions (93, 86, 79, 72, 65):</li>
                    </ul>
                    <div class="row g-2">
                        <?php $sub_hints = [93, 86, 79, 72, 65];
                        for ($i = 1; $i <= 5; $i++): ?>
                            <div class="col">
                                <input class="form-control form-control-sm text-center" name="answers[subtraction_<?php echo $i; ?>]" value="<?php echo ace_iii_escape($answers['subtraction_' . $i] ?? ''); ?>" placeholder="Step <?php echo $i; ?> (<?php echo $sub_hints[$i - 1]; ?>)">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('subtraction', 5, $submitted_scores, 'Attention'); ?>
                </div>
            </div>
        </section>

        <!-- PAGE 1: MEMORY (3 WORDS RECALL) -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>MEMORY</span>
                <span class="ace-live-pill">Memory Total: <strong id="badge_memory">0</strong> / 26</span>
            </div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask: 'Which 3 words did I ask you to repeat and remember?'</p>
                    <input class="form-control form-control-sm" name="answers[word_recall_response]" value="<?php echo ace_iii_escape($answers['word_recall_response'] ?? ''); ?>" placeholder="Record recalled words (lemon, key, ball)">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('word_recall', 3, $submitted_scores, 'Memory'); ?>
                </div>
            </div>
        </section>

        <!-- PAGE 1: FLUENCY -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>FLUENCY</span>
                <span class="ace-live-pill">Subtotal: <strong id="badge_fluency">0</strong> / 14</span>
            </div>

            <!-- Letters (P) -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <div class="fw-bold mb-1" style="font-size:13px;">&#10148; Letters</div>
                    <p class="mb-2" style="font-size:12.5px;"><strong>Say:</strong> "I'm going to give you a letter of the alphabet and I'd like you to generate as many words as you can beginning with that letter, but not names of people or places. For example, if I give you the letter "C", you could give me words like "cat, cry, clock" and so on. But, you can't give me words like Catherine or Canada. Do you understand? Are you ready? You have one minute. The letter I want you to use is the letter "P".</p>
                    <textarea class="form-control form-control-sm" rows="3" name="answers[letter_fluency_response]" placeholder="Record words generated across 0-15 sec, 16-30 sec, 31-45 sec, 46-60 sec..."><?php echo ace_iii_escape($answers['letter_fluency_response'] ?? ''); ?></textarea>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('letter_fluency', 7, $submitted_scores, 'Fluency'); ?>
                    <table class="ace-scale-table">
                        <tr><td>&gt;14</td><td>7</td></tr>
                        <tr><td>11-14</td><td>6</td></tr>
                        <tr><td>8-10</td><td>5</td></tr>
                        <tr><td>6-7</td><td>4</td></tr>
                        <tr><td>3-5</td><td>3</td></tr>
                        <tr><td>2</td><td>2</td></tr>
                        <tr><td>1</td><td>1</td></tr>
                        <tr><td>0</td><td>0</td></tr>
                    </table>
                </div>
            </div>

            <!-- Animals -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <div class="fw-bold mb-1" style="font-size:13px;">&#10148; Animals</div>
                    <p class="mb-2" style="font-size:12.5px;"><strong>Say:</strong> "Now can you name as many animals as possible. It can begin with any letter."</p>
                    <textarea class="form-control form-control-sm" rows="3" name="answers[animal_fluency_response]" placeholder="Record animals named across 0-15 sec, 16-30 sec, 31-45 sec, 46-60 sec..."><?php echo ace_iii_escape($answers['animal_fluency_response'] ?? ''); ?></textarea>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('animal_fluency', 7, $submitted_scores, 'Fluency'); ?>
                    <table class="ace-scale-table">
                        <tr><td>&gt;16</td><td>7</td></tr>
                        <tr><td>14-16</td><td>6</td></tr>
                        <tr><td>11-13</td><td>5</td></tr>
                        <tr><td>8-10</td><td>4</td></tr>
                        <tr><td>6-7</td><td>3</td></tr>
                        <tr><td>3-5</td><td>2</td></tr>
                        <tr><td>1-2</td><td>1</td></tr>
                        <tr><td>0</td><td>0</td></tr>
                    </table>
                </div>
            </div>
        </section>

        <!-- PAGE 2: MEMORY (ADDRESS LEARNING & GENERAL MEMORY) -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar"><span>MEMORY</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-1" style="font-size:12.5px;">&#10148; <strong>Tell:</strong> "I'm going to give you a name and address and I'd like you to repeat the name and address after me. So you have a chance to learn, we'll be doing that 3 times. I'll ask you the name and address later."</p>
                    <p class="mb-2 fw-bold" style="font-size:12.5px;">Score only the third trial.</p>
                    <div class="table-responsive">
                        <table class="ace-address-table">
                            <thead>
                                <tr>
                                    <th style="width:28%;">Target Name &amp; Address</th>
                                    <th>1<sup>st</sup> Trial</th>
                                    <th>2<sup>nd</sup> Trial</th>
                                    <th>3<sup>rd</sup> Trial</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-bold">
                                        Sunil Kumar Singh<br>
                                        52, Station Road,<br>
                                        Gandhinagar,<br>
                                        Allahabad.
                                    </td>
                                    <?php for ($trial = 1; $trial <= 3; $trial++): ?>
                                        <td>
                                            <textarea class="form-control form-control-sm" rows="4" name="answers[address_trial_<?php echo $trial; ?>]" placeholder="Record Trial <?php echo $trial; ?>"><?php echo ace_iii_escape($answers['address_trial_' . $trial] ?? ''); ?></textarea>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('address_learning', 7, $submitted_scores, 'Memory'); ?>
                </div>
            </div>

            <div class="ace-domain-bar"><span>MEMORY</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <?php
                    $memory_questions = [
                        'memory_chief_minister' => 'Name the current Chief Minister',
                        'memory_prime_minister' => 'Name the Prime Minister of India',
                        'memory_actor' => "Name the actor who was hero in the film 'Mera Naam Joker'",
                        'memory_father_nation' => 'Name the Father of our Nation',
                    ];
                    foreach ($memory_questions as $field => $question):
                    ?>
                        <div class="row align-items-center g-2 mb-2">
                            <div class="col-md-6">
                                <label class="fw-bold" style="font-size:13px;" for="<?php echo $field; ?>">&#10148; <?php echo ace_iii_escape($question); ?>:</label>
                            </div>
                            <div class="col-md-4">
                                <input class="form-control form-control-sm" id="<?php echo $field; ?>" name="answers[<?php echo $field; ?>]" value="<?php echo ace_iii_escape($answers[$field] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <div class="ace-inline-score mt-0 pt-0 border-0">
                                    <span>0–1</span>
                                    <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Memory</span>
                        <span class="ace-score-range">[Score 0–4]</span>
                        <div class="fw-bold mt-1 fs-5" id="retro_mem_sub">0</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- PAGE 2, 3, 4: LANGUAGE -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>LANGUAGE</span>
                <span class="ace-live-pill">Subtotal: <strong id="badge_language">0</strong> / 26</span>
            </div>

            <!-- Commands -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:12.5px;">&#10148; Place a pencil and a piece of paper in front of the subject. As a practice trial, ask the subject to <strong>"Pick up the pencil and then the paper."</strong> If incorrect, score 0 and do not continue further.</p>
                    <p class="mb-2" style="font-size:12.5px;">&#10148; If the subject is correct on the practice trial, continue with the following three commands below.</p>
                    <?php
                    $commands = [
                        'command_1' => 'Ask the subject to "Place the paper on top of the pencil"',
                        'command_2' => 'Ask the subject to "Pick up the pencil but not the paper"',
                        'command_3' => 'Ask the subject to "Pass me the pencil after touching the paper"',
                    ];
                    foreach ($commands as $field => $command):
                    ?>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                            <span style="font-size:13px;">&bull; <?php echo ace_iii_escape($command); ?></span>
                            <div class="ace-inline-score mt-0 pt-0 border-0">
                                <span>Score (0–1)</span>
                                <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <p class="mt-2 mb-0 text-muted" style="font-size:12px;">Note: Place the pencil and paper in front of the subject before each command.</p>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Language</span>
                        <span class="ace-score-range">[Score 0–3]</span>
                        <div class="fw-bold mt-1 fs-5" id="lang_cmd_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Writing -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:12.5px;">&#10148; Ask the subject to write two (or more) complete sentences about his/her last holiday/weekend/festival. Write in complete sentences and do not use abbreviations.<br>
                        Give 1 point if there are two (or more) complete sentences about the one topic; and give another 1 point if grammar and spelling are correct.</p>
                    <textarea class="form-control form-control-sm" rows="4" name="answers[writing_response]" placeholder="Write or record the subject's sentences here..."><?php echo ace_iii_escape($answers['writing_response'] ?? ''); ?></textarea>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('writing', 2, $submitted_scores, 'Language'); ?>
                </div>
            </div>

            <!-- Word Repetition -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:13px;">&#10148; Ask the subject to repeat: <strong>'caterpillar'; 'eccentricity'; 'unintelligible'; 'statistician'</strong><br>
                        Score 2 if all are correct; score 1 if 3 are correct; and score 0 if 2 or less are correct.</p>
                    <input class="form-control form-control-sm" name="answers[multisyllabic_response]" value="<?php echo ace_iii_escape($answers['multisyllabic_response'] ?? ''); ?>" placeholder="Record response">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('multisyllabic_repetition', 2, $submitted_scores, 'Language'); ?>
                </div>
            </div>

            <!-- Proverb 1 -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to repeat: 'All that glitters is not gold'</p>
                    <input class="form-control form-control-sm" name="answers[proverb_1_response]" value="<?php echo ace_iii_escape($answers['proverb_1_response'] ?? ''); ?>" placeholder="Record repetition">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('proverb_1', 1, $submitted_scores, 'Language'); ?>
                </div>
            </div>

            <!-- Proverb 2 -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to repeat: 'A stitch in time saves nine'</p>
                    <input class="form-control form-control-sm" name="answers[proverb_2_response]" value="<?php echo ace_iii_escape($answers['proverb_2_response'] ?? ''); ?>" placeholder="Record repetition">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('proverb_2', 1, $submitted_scores, 'Language'); ?>
                </div>
            </div>

            <!-- Picture Naming (Page 3 - Exact Order pic_1 to pic_12) -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to name the following pictures:</p>
                    <div class="ace-pic-grid-12">
                        <?php for ($i = 1; $i <= 12; $i++): $field = 'naming_' . $i; ?>
                            <div class="ace-pic-cell">
                                <div class="ace-pic-top-bar">
                                    <input class="form-control form-control-sm" name="answers[<?php echo $field; ?>_response]" value="<?php echo ace_iii_escape($answers[$field . '_response'] ?? ''); ?>" placeholder="Response <?php echo $i; ?>">
                                    <input class="form-control ace-score" style="width:52px !important;height:30px !important;" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" title="Score 0-1" required>
                                </div>
                                <div class="ace-pic-svg-wrap" aria-label="Picture <?php echo $i; ?>">
                                    <?php echo ace_iii_get_picture_svg($i); ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Language</span>
                        <span class="ace-score-range">[Score 0–12]</span>
                        <div class="fw-bold mt-1 fs-5" id="lang_naming_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Pointing Comprehension (Page 3 Bottom) -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Using the pictures above, ask the subject to:</p>
                    <?php
                    $comprehension_questions = [
                        'comprehension_1' => 'Point to the one which is used in rain',
                        'comprehension_2' => 'Point to the one which emits light',
                        'comprehension_3' => 'Point to the one which is associated with farming',
                        'comprehension_4' => 'Point to the one which is found in desserts',
                    ];
                    foreach ($comprehension_questions as $field => $question):
                    ?>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                            <span style="font-size:13px;">&bull; <?php echo ace_iii_escape($question); ?></span>
                            <div class="ace-inline-score mt-0 pt-0 border-0">
                                <span>Score (0–1)</span>
                                <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Language</span>
                        <span class="ace-score-range">[Score 0–4]</span>
                        <div class="fw-bold mt-1 fs-5" id="lang_comp_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Reading Words (Page 4 Top) -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:13px;">&#10148; Ask the subject to read the following words: (Score 1 only if all correct)</p>
                    <div class="text-center fw-bold my-2" style="font-size:18px;line-height:1.45;">
                        sew<br>pint<br>soot<br>dough<br>height
                    </div>
                    <input class="form-control form-control-sm" name="answers[reading_response]" value="<?php echo ace_iii_escape($answers['reading_response'] ?? ''); ?>" placeholder="Record response">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('reading', 1, $submitted_scores, 'Language'); ?>
                </div>
            </div>
        </section>

        <!-- PAGE 4, 5, 6: VISUOSPATIAL ABILITIES -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>VISUOSPATIAL ABILITIES</span>
                <span class="ace-live-pill">Subtotal: <strong id="badge_visuospatial">0</strong> / 16</span>
            </div>

            <!-- Infinity Diagram (Exact Overlapping Loops Match) -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Infinity Diagram: Ask the subject to copy this diagram.</p>
                    <div class="ace-visuo-grid">
                        <div class="ace-stimulus-card">
                            <span>Infinity Diagram</span>
                            <?php
                            $infinity_svg = '<svg class="ace-model-svg" viewBox="0 0 360 120" role="img" aria-label="Overlapping infinity loops diagram to copy">
                                <path d="M 20,60 C 20,12 76,12 110,60 C 144,108 200,108 200,60 C 200,12 144,12 110,60 C 76,108 20,108 20,60 Z"
                                      fill="none" stroke="#0f172a" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M 160,60 C 160,12 216,12 250,60 C 284,108 340,108 340,60 C 340,12 284,12 250,60 C 216,108 160,108 160,60 Z"
                                      fill="none" stroke="#0f172a" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>';
                            echo ace_iii_render_diagram('infinity', $infinity_svg, 'Overlapping Infinity Diagram');
                            ?>
                        </div>
                        <div class="ace-canvas-card">
                            <div class="ace-canvas-bar">
                                <span>Copy Infinity Diagram Here</span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2 ace-clear" type="button">Clear</button>
                            </div>
                            <canvas class="ace-canvas" data-drawing="infinity" aria-label="Drawing area for infinity diagram"></canvas>
                            <input type="hidden" name="answers[drawing_infinity]" value="<?php echo ace_iii_escape($answers['drawing_infinity'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('infinity', 1, $submitted_scores, 'Visuospatial'); ?>
                </div>
            </div>

            <!-- Wire Cube (Accurate 3D Necker Cube) -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Wire cube: Ask the subject to copy this drawing (for scoring, see instructions guide).</p>
                    <div class="ace-visuo-grid">
                        <div class="ace-stimulus-card">
                            <span>Wire Cube</span>
                            <?php
                            $cube_svg = '<svg class="ace-model-svg" viewBox="0 0 220 160" role="img" aria-label="Wire cube to copy">
                                <g fill="none" stroke="#0f172a" stroke-width="3.5" stroke-linejoin="miter" stroke-linecap="square">
                                    <rect x="42" y="54" width="90" height="90" />
                                    <rect x="88" y="16" width="90" height="90" />
                                    <line x1="42" y1="54" x2="88" y2="16" />
                                    <line x1="132" y1="54" x2="178" y2="16" />
                                    <line x1="42" y1="144" x2="88" y2="106" />
                                    <line x1="132" y1="144" x2="178" y2="106" />
                                </g>
                            </svg>';
                            echo ace_iii_render_diagram('cube', $cube_svg, 'Wire Cube');
                            ?>
                        </div>
                        <div class="ace-canvas-card">
                            <div class="ace-canvas-bar">
                                <span>Copy Wire Cube Here</span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2 ace-clear" type="button">Clear</button>
                            </div>
                            <canvas class="ace-canvas" data-drawing="cube" aria-label="Drawing area for wire cube"></canvas>
                            <input type="hidden" name="answers[drawing_cube]" value="<?php echo ace_iii_escape($answers['drawing_cube'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('cube', 2, $submitted_scores, 'Visuospatial'); ?>
                </div>
            </div>

            <!-- Clock Drawing -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Clock: Ask the subject to draw a clock face with numbers and the hands at ten past five. (For scoring see instruction guide: circle = 1, numbers = 2, hands = 2 if all correct).</p>
                    <div class="ace-visuo-grid">
                        <div class="ace-stimulus-card ace-clock-reference">
                            <span>Reference Clock (10 Past 5)</span>
                            <svg class="ace-model-svg" viewBox="0 0 140 140" role="img" aria-label="Reference clock diagram at ten past five">
                                <circle cx="70" cy="70" r="56" />
                                <circle cx="70" cy="70" r="3" fill="#0f172a" />
                                <text x="70" y="27" text-anchor="middle" font-size="11" font-weight="bold" fill="#0f172a">12</text>
                                <text x="93" y="33" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">1</text>
                                <text x="109" y="49" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">2</text>
                                <text x="116" y="74" text-anchor="middle" font-size="11" font-weight="bold" fill="#0f172a">3</text>
                                <text x="109" y="96" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">4</text>
                                <text x="93" y="113" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">5</text>
                                <text x="70" y="120" text-anchor="middle" font-size="11" font-weight="bold" fill="#0f172a">6</text>
                                <text x="47" y="113" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">7</text>
                                <text x="31" y="96" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">8</text>
                                <text x="24" y="74" text-anchor="middle" font-size="11" font-weight="bold" fill="#0f172a">9</text>
                                <text x="31" y="49" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">10</text>
                                <text x="47" y="33" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">11</text>
                                <line x1="70" y1="70" x2="87" y2="98" stroke-width="4" />
                                <line x1="70" y1="70" x2="104" y2="50" stroke-width="2.5" />
                            </svg>
                        </div>
                        <div class="ace-canvas-card">
                            <div class="ace-canvas-bar">
                                <span>Patient Clock Drawing Surface</span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2 ace-clear" type="button">Clear</button>
                            </div>
                            <canvas class="ace-canvas" data-drawing="clock" aria-label="Drawing area for clock"></canvas>
                            <input type="hidden" name="answers[drawing_clock]" value="<?php echo ace_iii_escape($answers['drawing_clock'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('clock', 5, $submitted_scores, 'Visuospatial'); ?>
                </div>
            </div>

            <!-- Page 5: Dot Counting -->
            <div class="ace-domain-bar"><span>VISUOSPATIAL ABILITIES</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to count the dots without pointing to them</p>
                    <?php
                    $dot_positions = [
                        [[14, 14], [18, 44], [34, 52], [48, 38], [62, 58], [52, 82], [80, 32], [78, 56]],
                        [[22, 42], [28, 30], [48, 40], [38, 60], [52, 72], [58, 82], [68, 68], [68, 24], [82, 22], [86, 86]],
                        [[24, 18], [72, 20], [28, 52], [44, 46], [42, 68], [14, 84], [78, 80]],
                        [[20, 14], [18, 44], [26, 68], [46, 36], [48, 72], [68, 22], [74, 44], [78, 56], [86, 86]],
                    ];
                    ?>
                    <div class="ace-quad-grid">
                        <?php foreach ($dot_positions as $index => $positions):
                            $field = 'dots_' . ($index + 1);
                        ?>
                            <div class="ace-quad-box">
                                <div class="ace-dot-frame" aria-label="Dot counting box <?php echo $index + 1; ?>">
                                    <?php foreach ($positions as $pos): ?>
                                        <i style="left:<?php echo $pos[0]; ?>%;top:<?php echo $pos[1]; ?>%"></i>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <input class="form-control form-control-sm" name="answers[<?php echo $field; ?>_response]" value="<?php echo ace_iii_escape($answers[$field . '_response'] ?? ''); ?>" placeholder="Subject's count">
                                    <div class="ace-inline-score mt-0 pt-0 border-0">
                                        <span>Score (0–1)</span>
                                        <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Visuospatial</span>
                        <span class="ace-score-range">[Score 0–4]</span>
                        <div class="fw-bold mt-1 fs-5" id="visuo_dots_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Page 6: Identify the Fragmented Letters (K, M, A, T) -->
            <div class="ace-domain-bar"><span>VISUOSPATIAL ABILITIES</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to identify the letters</p>
                    <div class="ace-quad-grid">
                        <?php for ($i = 1; $i <= 4; $i++): $field = 'letters_' . $i; ?>
                            <div class="ace-quad-box">
                                <div class="ace-frag-frame" aria-label="Fragmented letter <?php echo $i; ?>">
                                    <?php echo ace_iii_get_fragmented_letter_svg($i); ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <input class="form-control form-control-sm" name="answers[letter_fragment_<?php echo $i; ?>_response]" value="<?php echo ace_iii_escape($answers['letter_fragment_' . $i . '_response'] ?? ''); ?>" placeholder="Identified letter">
                                    <div class="ace-inline-score mt-0 pt-0 border-0">
                                        <span>Score (0–1)</span>
                                        <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Visuospatial</span>
                        <span class="ace-score-range">[Score 0–4]</span>
                        <div class="fw-bold mt-1 fs-5" id="visuo_letters_sub">0</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- PAGE 6: MEMORY (DELAYED ADDRESS RECALL & RECOGNITION) -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar"><span>MEMORY</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask "Now tell me what you remember about that name and address we were repeating at the beginning"</p>
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4 fw-bold" style="font-size:13px;">
                            Sunil Kumar Singh<br>
                            52, Station Road,<br>
                            Gandhinagar,<br>
                            Allahabad.
                        </div>
                        <div class="col-md-8">
                            <textarea class="form-control form-control-sm" name="answers[address_recall_response]" rows="3" placeholder="Record recalled items..."><?php echo ace_iii_escape($answers['address_recall_response'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('address_recall', 7, $submitted_scores, 'Memory'); ?>
                </div>
            </div>

            <div class="ace-domain-bar"><span>MEMORY (RECOGNITION &amp; SUMMARY)</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:12.5px;">&#10148; This test should be done if the subject failed to recall one or more items above. If all items were recalled, skip the test and score 5. If only part was recalled start by ticking items recalled in the shadowed column on the right hand side; and then test not recalled items by telling the subject "ok, I'll give you some hints: was the name X, Y or Z?" and so on. Each recognised item scores one point, which is added to the point gained by recalling.</p>
                    <?php
$recognition_rows = [
    'recog_name'   => ['Sunil Kumar Sharma', 'Sunil Kumar Singh', 'Rakesh Yadav'],
    'recog_number' => ['25', '52', '37'],
    'recog_street' => ['Market Road', 'Sastri Marg', 'Station Road'],
    'recog_area'   => ['Prakash Nagar', 'Gandhi Nagar', 'Patel Nagar'],
    'recog_city'   => ['Allahabad', 'Gwalior', 'Indore'],
];
?>
<div class="table-responsive">
    <table class="ace-recog-choices-table">
        <tbody>
            <?php foreach ($recognition_rows as $r_key => $options): ?>
                <tr>
                    <?php foreach ($options as $o_idx => $opt_label):
                        $opt_name = 'answers[' . $r_key . '_opt_' . ($o_idx + 1) . ']';
                        $is_checked = !empty($answers[$r_key . '_opt_' . ($o_idx + 1)]);
                    ?>
                        <td>
                            <label class="ace-recog-opt">
                                <input type="checkbox" name="<?php echo ace_iii_escape($opt_name); ?>" value="1" <?php echo $is_checked ? 'checked' : ''; ?>>
                                <span><?php echo ace_iii_escape($opt_label); ?></span>
                            </label>
                        </td>
                    <?php endforeach; ?>
                    <td>
                        <?php
                        $rec_name = 'answers[' . $r_key . '_recalled]';
                        $is_recalled = !empty($answers[$r_key . '_recalled']);
                        ?>
                        <label class="ace-recog-opt justify-content-center">
                            <input type="checkbox" name="<?php echo ace_iii_escape($rec_name); ?>" value="1" <?php echo $is_recalled ? 'checked' : ''; ?>>
                            <span>recalled</span>
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
                    <div class="table-responsive mb-3">
                        <table class="ace-recog-table">
                            <tbody>
                                <tr class="table-light fw-bold">
                                    <td>TOTAL ACE-III SCORE</td>
                                    <td class="text-end" style="width:160px;"><span id="total_all" class="fs-5 fw-bold text-success">0</span> / 100</td>
                                </tr>
                                <tr>
                                    <td>Score Interpretation</td>
                                    <td class="text-end"><span id="ace_iii_interpretation" class="fw-bold">82-Below - Abnormal Cognitive Function</span></td>
                                </tr>
                                <tr>
                                    <td>Attention</td>
                                    <td class="text-end fw-bold"><span id="total_attention">0</span> / 18</td>
                                </tr>
                                <tr>
                                    <td>Memory</td>
                                    <td class="text-end fw-bold"><span id="total_memory">0</span> / 26</td>
                                </tr>
                                <tr>
                                    <td>Fluency</td>
                                    <td class="text-end fw-bold"><span id="total_fluency">0</span> / 14</td>
                                </tr>
                                <tr>
                                    <td>Language</td>
                                    <td class="text-end fw-bold"><span id="total_language">0</span> / 26</td>
                                </tr>
                                <tr>
                                    <td>Visuospatial</td>
                                    <td class="text-end fw-bold"><span id="total_visuospatial">0</span> / 16</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold mb-1" style="font-size:13px;" for="clinical_notes">Clinical Observations / Examiner Notes (Optional):</label>
                        <textarea class="form-control form-control-sm" id="clinical_notes" name="clinical_notes" rows="2" maxlength="5000"><?php echo ace_iii_escape($notes); ?></textarea>
                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a class="btn btn-outline-secondary btn-sm" href="ace-iii-assessment-records.php">View Saved Records</a>
                        <button type="submit" class="btn btn-success fw-bold px-4" name="submit_ace_iii" value="1" <?php echo empty($patients) || !$ace_iii_schema_ready ? 'disabled' : ''; ?>>
                            Save ACE-III Assessment
                        </button>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('address_recognition', 5, $submitted_scores, 'Memory'); ?>
                </div>
            </div>
        </section>

        <!-- STICKY FOOTER BAR -->
        <div class="ace-sticky-footer" aria-live="polite">
            <div class="ace-sticky-scores">
                <div><span>Attention:</span><strong id="sticky_attention">0</strong>/18</div>
                <div><span>Memory:</span><strong id="sticky_memory">0</strong>/26</div>
                <div><span>Fluency:</span><strong id="sticky_fluency">0</strong>/14</div>
                <div><span>Language:</span><strong id="sticky_language">0</strong>/26</div>
                <div><span>Visuospatial:</span><strong id="sticky_visuospatial">0</strong>/16</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span id="ace_iii_autosave_status" class="ace-autosave-badge">Autosave ready</span>
                <div class="ace-total-pill">Total Score: <span id="sticky_total">0</span> / 100</div>
                <button type="submit" class="btn btn-sm btn-light fw-bold" name="submit_ace_iii" value="1" <?php echo empty($patients) || !$ace_iii_schema_ready ? 'disabled' : ''; ?>>
                    Save Record
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var isPatient = <?php echo $is_patient ? 'true' : 'false'; ?>;
        var aceForm = document.getElementById('aceForm');
        var draftIdInput = document.getElementById('draft_record_id');
        var statusEl = document.getElementById('ace_iii_autosave_status');
        var dateInput = document.getElementById('assessment_date');
        var hiddenDemoDate = document.getElementById('demographics_date_hidden');

        if (dateInput && hiddenDemoDate) {
            dateInput.addEventListener('change', function() {
                hiddenDemoDate.value = dateInput.value;
            });
        }

        if (isPatient) {
            document.querySelectorAll('.ace-score').forEach(function(input) {
                input.disabled = true;
                input.required = false;
                input.value = '';
                input.setAttribute('tabindex', '-1');
                input.setAttribute('aria-hidden', 'true');
                var wrapper = input.closest('.ace-inline-score');
                if (wrapper) {
                    wrapper.style.display = 'none';
                }
            });

            document.querySelectorAll('.ace-canvas').forEach(function(canvas) {
                canvas.style.pointerEvents = 'none';
                canvas.setAttribute('aria-disabled', 'true');
            });

            document.querySelectorAll('.ace-clear').forEach(function(button) {
                button.disabled = true;
                button.setAttribute('tabindex', '-1');
            });
        }

        var scores = document.querySelectorAll('.ace-score');

        function updateTotals() {
            var totals = {
                attention: 0,
                memory: 0,
                fluency: 0,
                language: 0,
                visuospatial: 0
            };
            var subGroups = {
                orient_time: 0,
                orient_place: 0,
                retro_mem: 0,
                lang_cmd: 0,
                lang_naming: 0,
                lang_comp: 0,
                visuo_dots: 0,
                visuo_letters: 0
            };

            scores.forEach(function(input) {
                var value = Number(input.value);
                var maximum = Number(input.max);
                if (input.value !== '' && Number.isFinite(value) && value >= 0 && value <= maximum) {
                    var name = input.name.match(/^scores\[([^\]]+)\]$/);
                    if (!name) return;
                    var field = name[1];

                    var section = field.indexOf('orientation_') === 0 || field === 'registration' || field === 'subtraction' ? 'attention' :
                        field === 'word_recall' || field.indexOf('address_') === 0 || field.indexOf('memory_') === 0 ? 'memory' :
                        field.indexOf('letter_fluency') === 0 || field.indexOf('animal_fluency') === 0 ? 'fluency' :
                        field.indexOf('dots_') === 0 || field.indexOf('letters_') === 0 || ['infinity', 'cube', 'clock'].indexOf(field) !== -1 ? 'visuospatial' :
                        'language';
                    totals[section] += value;

                    if (['orientation_day', 'orientation_date', 'orientation_month', 'orientation_year', 'orientation_season'].indexOf(field) !== -1) subGroups.orient_time += value;
                    if (['orientation_floor', 'orientation_street', 'orientation_city', 'orientation_state', 'orientation_country'].indexOf(field) !== -1) subGroups.orient_place += value;
                    if (field.indexOf('memory_') === 0) subGroups.retro_mem += value;
                    if (field.indexOf('command_') === 0) subGroups.lang_cmd += value;
                    if (field.indexOf('naming_') === 0) subGroups.lang_naming += value;
                    if (field.indexOf('comprehension_') === 0) subGroups.lang_comp += value;
                    if (field.indexOf('dots_') === 0) subGroups.visuo_dots += value;
                    if (field.indexOf('letters_') === 0) subGroups.visuo_letters += value;
                }
            });

            var grand = 0;
            Object.keys(totals).forEach(function(section) {
                var valStr = String(totals[section]);
                var totalEl = document.getElementById('total_' + section);
                var badgeEl = document.getElementById('badge_' + section);
                var stickyEl = document.getElementById('sticky_' + section);
                if (totalEl) totalEl.textContent = valStr;
                if (badgeEl) badgeEl.textContent = valStr;
                if (stickyEl) stickyEl.textContent = valStr;
                grand += totals[section];
            });

            var subMap = {
                orient_time_sub: subGroups.orient_time,
                orient_place_sub: subGroups.orient_place,
                retro_mem_sub: subGroups.retro_mem,
                lang_cmd_sub: subGroups.lang_cmd,
                lang_naming_sub: subGroups.lang_naming,
                lang_comp_sub: subGroups.lang_comp,
                visuo_dots_sub: subGroups.visuo_dots,
                visuo_letters_sub: subGroups.visuo_letters
            };
            Object.keys(subMap).forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.textContent = String(subMap[id]);
            });

            var grandStr = String(grand);
            var totalAllEl = document.getElementById('total_all');
            var stickyTotalEl = document.getElementById('sticky_total');
            if (totalAllEl) totalAllEl.textContent = grandStr;
            if (stickyTotalEl) stickyTotalEl.textContent = grandStr;

            var interpretation = '82-Below - Abnormal Cognitive Function';
            var interpretationClass = 'text-danger';
            if (grand >= 88) {
                interpretation = '88-100 - Normal Cognitive Function';
                interpretationClass = 'text-success';
            } else if (grand >= 83) {
                interpretation = '83-87 - Borderline/ Mild Cognitive Impairment';
                interpretationClass = 'text-warning';
            }

            var interpretationEl = document.getElementById('ace_iii_interpretation');
            if (interpretationEl) {
                interpretationEl.textContent = interpretation;
                interpretationEl.className = 'fw-bold ' + interpretationClass;
            }
        }

        scores.forEach(function(input) {
            input.addEventListener('input', updateTotals);
        });
        updateTotals();

        /* =========================================================
           ROBUST AUTOSAVE ENGINE
           ========================================================= */
        var autosaveTimeout = null;
        var isAutosaving = false;
        var isDirty = false;
        var pendingAutosave = false;
        var isSubmitting = false;

        function setAutosaveStatus(message, stateClass) {
            if (!statusEl) return;
            statusEl.textContent = message;
            statusEl.className = 'ace-autosave-badge' + (stateClass ? ' ' + stateClass : '');
        }

        function syncEditIdInUrl(draftId) {
            if (!draftId || !window.history || !window.history.replaceState) return;
            try {
                var url = new URL(window.location.href);
                if (url.searchParams.get('edit_id') !== String(draftId)) {
                    url.searchParams.set('edit_id', String(draftId));
                    window.history.replaceState(null, '', url.toString());
                }
            } catch (e) {
                // Ignore URL update error in older browsers
            }
        }

        function autosaveDraft() {
            if (isSubmitting || !isDirty) return;

            if (isAutosaving) {
                pendingAutosave = true;
                return;
            }

            var patient = aceForm.querySelector('[name="patient_id"]');
            if (!patient || !patient.value) {
                setAutosaveStatus('Select patient to autosave', 'is-error');
                return;
            }

            isAutosaving = true;
            isDirty = false;
            setAutosaveStatus('Saving draft...', 'is-saving');

            var formData = new FormData(aceForm);
            formData.append('autosave_ace_iii', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(function(response) {
                return response.text().then(function(text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        throw new Error('Invalid server response (HTTP ' + response.status + ')');
                    }
                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || ('Autosave failed (HTTP ' + response.status + ')'));
                    }
                    return data;
                });
            })
            .then(function(data) {
                if (data.draft_id) {
                    draftIdInput.value = String(data.draft_id);
                    syncEditIdInUrl(data.draft_id);
                }
                if (!pendingAutosave && !isDirty) {
                    setAutosaveStatus('Draft saved at ' + data.saved_at, 'is-saved');
                }
            })
            .catch(function(error) {
                isDirty = true; // Keep dirty so next attempt retries
                setAutosaveStatus(error.message || 'Draft autosave failed', 'is-error');
            })
            .finally(function() {
                isAutosaving = false;
                if (pendingAutosave) {
                    pendingAutosave = false;
                    isDirty = true;
                    autosaveDraft();
                }
            });
        }

        function queueAutosave() {
            if (isSubmitting) return;
            isDirty = true;
            setAutosaveStatus('Unsaved changes...', 'is-dirty');
            clearTimeout(autosaveTimeout);
            autosaveTimeout = setTimeout(autosaveDraft, 1200);
        }

        // Bind input & change events on all form fields
        aceForm.querySelectorAll('input, textarea, select').forEach(function(control) {
            if (control.type === 'hidden') return;
            control.addEventListener('input', queueAutosave);
            control.addEventListener('change', queueAutosave);
        });

        // Canvas Drawing Setup (connected with queueAutosave)
        document.querySelectorAll('.ace-canvas').forEach(function(canvas) {
            var context = canvas.getContext('2d');
            var hidden = document.querySelector('input[name="answers[drawing_' + canvas.dataset.drawing + ']"]');
            var clearBtn = canvas.closest('.ace-canvas-card').querySelector('.ace-clear');

            function fitCanvas() {
                var ratio = window.devicePixelRatio || 1;
                var width = canvas.clientWidth || 380;
                var height = 190;
                var previous = hidden.value;
                canvas.width = width * ratio;
                canvas.height = height * ratio;
                context.setTransform(1, 0, 0, 1, 0, 0);
                context.scale(ratio, ratio);
                context.lineWidth = 3;
                context.lineCap = 'round';
                context.strokeStyle = '#0f172a';
                if (previous) {
                    var image = new Image();
                    image.onload = function() {
                        context.drawImage(image, 0, 0, width, height);
                    };
                    image.src = previous;
                }
            }
            fitCanvas();

            var drawing = false;

            function point(event) {
                var rect = canvas.getBoundingClientRect();
                return {
                    x: event.clientX - rect.left,
                    y: event.clientY - rect.top
                };
            }

            canvas.addEventListener('pointerdown', function(event) {
                drawing = true;
                canvas.setPointerCapture(event.pointerId);
                var p = point(event);
                context.beginPath();
                context.moveTo(p.x, p.y);
            });

            canvas.addEventListener('pointermove', function(event) {
                if (!drawing) return;
                var p = point(event);
                context.lineTo(p.x, p.y);
                context.stroke();
            });

            function finishDrawing() {
                if (!drawing) return;
                drawing = false;
                hidden.value = canvas.toDataURL('image/png');
                queueAutosave();
            }

            canvas.addEventListener('pointerup', finishDrawing);
            canvas.addEventListener('pointercancel', finishDrawing);

            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    context.clearRect(0, 0, canvas.width, canvas.height);
                    hidden.value = '';
                    queueAutosave();
                });
            }
        });

        // Backup interval: only fires if there are unsaved changes
        setInterval(function() {
            if (isDirty && !isAutosaving && !isSubmitting) {
                autosaveDraft();
            }
        }, 20000);

        // Disable autosave & unload warning on final submit
        aceForm.addEventListener('submit', function() {
            isSubmitting = true;
            isDirty = false;
            clearTimeout(autosaveTimeout);
        });

        // Warn user if leaving with unsaved changes
        window.addEventListener('beforeunload', function(event) {
            if (isDirty && !isSubmitting) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    });
</script>
<?php require 'layout/footer.php'; ?>