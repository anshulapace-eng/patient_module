<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';
require 'ace-iii-assessment-schema.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = "ACE-III Assessment - Vimhans Academic";
$sections = [
    'attention' => ['title' => 'Attention', 'max' => 18],
    'memory' => ['title' => 'Memory', 'max' => 26],
    'fluency' => ['title' => 'Fluency', 'max' => 14],
    'language' => ['title' => 'Language', 'max' => 26],
    'visuospatial' => ['title' => 'Visuospatial Abilities', 'max' => 16],
];
$score_fields = [
    'attention' => [
        'orientation_day' => 1, 'orientation_date' => 1, 'orientation_month' => 1,
        'orientation_year' => 1, 'orientation_season' => 1, 'orientation_floor' => 1,
        'orientation_street' => 1, 'orientation_city' => 1, 'orientation_state' => 1,
        'orientation_country' => 1, 'registration' => 3, 'subtraction' => 5,
    ],
    'memory' => [
        'word_recall' => 3, 'address_learning' => 7, 'address_recall' => 7,
        'address_recognition' => 5, 'memory_chief_minister' => 1,
        'memory_prime_minister' => 1, 'memory_actor' => 1, 'memory_father_nation' => 1,
    ],
    'fluency' => ['letter_fluency' => 7, 'animal_fluency' => 7],
    'language' => [
        'command_1' => 1, 'command_2' => 1, 'command_3' => 1, 'writing' => 2,
        'multisyllabic_repetition' => 2, 'proverb_1' => 1, 'proverb_2' => 1,
        'naming_1' => 1, 'naming_2' => 1, 'naming_3' => 1, 'naming_4' => 1,
        'naming_5' => 1, 'naming_6' => 1, 'naming_7' => 1, 'naming_8' => 1,
        'naming_9' => 1, 'naming_10' => 1, 'naming_11' => 1, 'naming_12' => 1,
        'comprehension_1' => 1, 'comprehension_2' => 1, 'comprehension_3' => 1,
        'comprehension_4' => 1, 'reading' => 1,
    ],
    'visuospatial' => [
        'infinity' => 1, 'cube' => 2, 'clock' => 5, 'dots_1' => 1, 'dots_2' => 1,
        'dots_3' => 1, 'dots_4' => 1, 'letters_1' => 1, 'letters_2' => 1,
        'letters_3' => 1, 'letters_4' => 1,
    ],
];

$patients = [];
$error_msg = '';
$success_msg = '';
$submitted_scores = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
$patient_id = filter_var($_POST['patient_id'] ?? '', FILTER_VALIDATE_INT);
$patient_id = $patient_id === false ? 0 : (int) $patient_id;
$date_input = $_POST['assessment_date'] ?? date('Y-m-d');
$form_date = is_string($date_input) ? $date_input : date('Y-m-d');
$details = is_array($_POST['patient_details'] ?? null) ? $_POST['patient_details'] : [];
$answers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
$notes = is_string($_POST['clinical_notes'] ?? '') ? $_POST['clinical_notes'] : '';

if (!isset($_SESSION['ace_iii_csrf_token'])) {
    $_SESSION['ace_iii_csrf_token'] = bin2hex(random_bytes(32));
}

if (!$ace_iii_schema_ready) {
    $error_msg = 'The assessment table could not be initialized. Please contact the system administrator.';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ace_iii']) && $ace_iii_schema_ready) {
    $posted_token = $_POST['csrf_token'] ?? '';
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
        foreach ($fields as $field => $maximum) {
            $raw_score = $submitted_scores[$field] ?? null;
            $score = is_string($raw_score) || is_int($raw_score)
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

    foreach ([$details, $answers] as $posted_data) {
        foreach ($posted_data as $key => $value) {
            $max_length = strpos((string) $key, 'drawing_') === 0 ? 500000 : 5000;
            $is_drawing = strpos((string) $key, 'drawing_') === 0;
            if (!is_string($value) || strlen($value) > $max_length ||
                ($is_drawing && $value !== '' && !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $value))) {
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
        $total_score = array_sum($section_totals);
        $patient_details_json = json_encode($details, JSON_UNESCAPED_UNICODE);
        $response_data_json = json_encode(
            ['answers' => $answers, 'scores' => $scores],
            JSON_UNESCAPED_UNICODE
        );
        if ($patient_details_json === false || $response_data_json === false) {
            $error_msg = 'The assessment responses could not be prepared for saving.';
        } else {
            $clinician_id = (int) ($_SESSION['user_id'] ?? 0);
            $insert = $conn->prepare(
                'INSERT INTO ace_iii_assessments
                    (patient_id, clinician_id, assessment_date, total_score, attention_score,
                     memory_score, fluency_score, language_score, visuospatial_score,
                     patient_details, response_data, clinical_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                error_log('ACE-III insert prepare failed: ' . $conn->error);
                $error_msg = 'The assessment could not be saved. Please try again.';
            } else {
                $insert->bind_param(
                    'iisiiiiiisss',
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
                    $notes
                );
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

if (isset($_SESSION['ace_iii_saved'])) {
    $success_msg = (string) $_SESSION['ace_iii_saved'];
    unset($_SESSION['ace_iii_saved']);
}

function ace_iii_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ace_iii_score_input($field, $maximum, $submitted_scores)
{
    $value = $submitted_scores[$field] ?? '';
    return '<label class="ace-score-field"><span class="ace-score-heading">Score <span>0–' . (int) $maximum . '</span></span>' .
        '<input class="form-control ace-score" type="number" name="scores[' . ace_iii_escape($field) .
        ']" min="0" max="' . (int) $maximum . '" step="1" value="' . ace_iii_escape($value) .
        '" data-section="' . ace_iii_escape(strtok($field, '_')) . '" required aria-label="Score for ' .
        ace_iii_escape(str_replace('_', ' ', $field)) . '">' .
        '</label>';
}

require 'layout/base.php';
?>

<style>
    .ace-page{max-width:1180px;margin:0 auto;padding:24px 16px 48px;color:#172033}
    .ace-header,.ace-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 4px 16px rgba(15,23,42,.04)}
    .ace-header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:24px;margin-bottom:18px;border-top:4px solid #0f766e}
    .ace-header h1{margin:0 0 5px;font-size:23px;font-weight:800}.ace-header p{margin:0;color:#64748b;font-size:13px}
    .ace-nav{display:flex;gap:8px;flex-wrap:wrap}.ace-nav a{padding:9px 13px;border:1px solid #cbd5e1;border-radius:8px;color:#334155;text-decoration:none;font-size:12px;font-weight:700}
    .ace-card{padding:20px;margin-bottom:16px}.ace-card h2{margin:0 0 4px;font-size:16px;font-weight:800}.ace-card .ace-sub{margin:0 0 17px;color:#64748b;font-size:12px}
    .ace-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.ace-field label,.ace-answer label{display:block;margin-bottom:5px;font-size:12px;font-weight:700;color:#334155}
    .ace-field .form-control,.ace-field .form-select,.ace-answer .form-control{min-height:40px;border-color:#cbd5e1;font-size:13px}
    .ace-row{display:grid;grid-template-columns:minmax(0,1fr) 150px;align-items:center;gap:18px;padding:12px 0;border-top:1px solid #f1f5f9}
    .ace-prompt{font-size:13px;font-weight:650;color:#253247}.ace-instruction{margin:4px 0 0;color:#64748b;font-size:12px}
    .ace-score-field{display:flex;align-items:center;gap:7px;justify-content:flex-end;margin:0;color:#334155;font-size:11px;font-weight:700}
    .ace-score-field span{color:#64748b;font-weight:500;white-space:nowrap}.ace-score{width:72px;min-height:36px;padding:5px 8px}
    .ace-orientation-grid{display:grid;width:100%;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .ace-orientation-item{display:grid;grid-template-columns:minmax(0,1fr) 78px;align-items:end;gap:12px;min-width:0;padding:15px;border:1px solid #e2e8f0;border-radius:11px;background:linear-gradient(145deg,#fff 0%,#f8fbff 100%);box-shadow:0 1px 2px rgba(15,23,42,.025);transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease}
    .ace-orientation-item:hover{border-color:#cbd5e1;box-shadow:0 5px 14px rgba(15,23,42,.055);transform:translateY(-1px)}
    .ace-orientation-item:focus-within{border-color:#5b9cf5;box-shadow:0 0 0 3px rgba(37,99,235,.10)}
    .ace-orientation-item .ace-answer{min-width:0}
    .ace-orientation-item .ace-answer label{min-height:34px;margin-bottom:7px;color:#334155;font-size:12px;font-weight:700;line-height:1.4}
    .ace-orientation-item .ace-answer .form-control{min-width:0;min-height:40px;border-color:#cbd5e1;border-radius:7px;background:#fff}
    .ace-orientation-item .ace-answer .form-control:focus,.ace-orientation-item .ace-score:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
    .ace-orientation-item .ace-score-field{display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-end;gap:7px;margin:0 0 1px;white-space:nowrap}
    .ace-orientation-item .ace-score-heading{display:flex;gap:4px;color:#334155;font-size:11px;font-weight:700}
    .ace-orientation-item .ace-score-heading span{color:#64748b;font-weight:500}
    .ace-orientation-item .ace-score{box-sizing:border-box;flex:0 0 40px;width:64px;height:40px;min-height:40px;max-height:40px;padding:5px 8px;border-color:#cbd5e1;border-radius:7px;background:#fff}
    .ace-prompt-answer{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:16px;padding:12px 0;border-top:1px solid #f1f5f9}
    .ace-task{padding:12px 0;border-top:1px solid #f1f5f9}.ace-task-title{font-size:13px;font-weight:700}
    .ace-task .form-control{margin-top:8px}.ace-mini-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .ace-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.ace-summary div{padding:12px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}
    .ace-summary span{display:block;color:#64748b;font-size:10px;font-weight:700;text-transform:uppercase}.ace-summary strong{display:block;margin-top:3px;color:#0f766e;font-size:20px}
    .ace-note{margin-top:12px;color:#64748b;font-size:11px}.ace-dots{display:flex;align-items:center;justify-content:center;min-height:100px;border:1px solid #cbd5e1;background:#fff}
    .ace-dot-grid{position:relative;width:210px;height:80px}.ace-dot-grid i{position:absolute;width:12px;height:12px;border-radius:50%;background:#172033}
    .ace-picture-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}
    .ace-picture{display:grid;min-height:84px;place-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;font-size:38px}
    .ace-picture-number{display:block;margin-top:4px;color:#64748b;font-size:10px;text-align:center}
    .ace-model{display:block;width:min(100%,360px);height:100px;margin:9px auto;padding:10px;border:1px solid #e2e8f0;border-radius:7px;background:#fff}
    .ace-model path,.ace-model line{fill:none;stroke:#172033;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}
    .ace-canvas-wrap{overflow:auto;margin-top:8px}.ace-canvas{display:block;width:100%;height:220px;border:1px solid #cbd5e1;border-radius:6px;touch-action:none}
    .ace-clear{margin-top:6px}.ace-submit{display:flex;justify-content:space-between;align-items:center;gap:12px}
    .ace-toast{padding:12px 15px;border-radius:8px;margin-bottom:14px;font-size:13px}.ace-toast-error{background:#fef2f2;color:#991b1b}.ace-toast-success{background:#ecfdf5;color:#065f46}
    @media(max-width:1100px){.ace-orientation-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:760px){.ace-header{align-items:flex-start;flex-direction:column}.ace-meta{grid-template-columns:1fr 1fr}.ace-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.ace-row{grid-template-columns:minmax(0,1fr) 132px;gap:8px}.ace-score-field{flex-wrap:wrap}.ace-mini-grid{grid-template-columns:1fr}}
    @media(max-width:560px){.ace-meta{grid-template-columns:1fr}.ace-row{grid-template-columns:1fr}.ace-score-field{justify-content:flex-start}.ace-submit{align-items:flex-start;flex-direction:column}.ace-orientation-grid{grid-template-columns:1fr}}
    @media(prefers-reduced-motion:reduce){.ace-orientation-item{transition:none}}
</style>

<div class="ace-page">
    <header class="ace-header">
        <div><h1>ACE-III Cognitive Examination</h1><p>Professional assessment form · complete each section in the order shown.</p></div>
        <nav class="ace-nav" aria-label="ACE-III navigation">
            <a href="ace-iii-assessment-records.php">Assessment records</a>
        </nav>
    </header>
    <?php if ($error_msg !== ''): ?><div class="ace-toast ace-toast-error" role="alert"><?php echo ace_iii_escape($error_msg); ?></div><?php endif; ?>
    <?php if ($success_msg !== ''): ?><div class="ace-toast ace-toast-success" role="status"><?php echo ace_iii_escape($success_msg); ?></div><?php endif; ?>

    <form method="post" id="aceForm">
        <input type="hidden" name="csrf_token" value="<?php echo ace_iii_escape($_SESSION['ace_iii_csrf_token']); ?>">
        <section class="ace-card" aria-labelledby="patientDetailsHeading">
            <h2 id="patientDetailsHeading">Patient and examination details</h2>
            <p class="ace-sub">Record the identifying and examination information shown at the top of the paper form.</p>
            <div class="ace-meta">
                <div class="ace-field"><label for="patient_id">Patient *</label><select class="form-select" id="patient_id" name="patient_id" required><option value="">Select patient</option><?php foreach ($patients as $patient): ?><option value="<?php echo (int) $patient['id']; ?>" <?php echo $patient_id === (int) $patient['id'] ? 'selected' : ''; ?>><?php echo ace_iii_escape(trim($patient['first_name'] . ' ' . $patient['last_name']) ?: $patient['email']); ?></option><?php endforeach; ?></select></div>
                <div class="ace-field"><label for="assessment_date">Date of testing *</label><input class="form-control" type="date" id="assessment_date" name="assessment_date" value="<?php echo ace_iii_escape($form_date); ?>" required></div>
                <?php
                $patient_detail_fields = [
                    'date_of_birth' => 'Date of birth',
                    'hospital_address' => 'Hospital / address',
                    'languages_known' => 'Languages known',
                    'native_language' => 'Native language',
                    'tester_name' => "Tester’s name",
                    'education_age' => 'Age at leaving full-time education',
                    'occupation' => 'Occupation',
                    'handedness' => 'Handedness',
                ];
                foreach ($patient_detail_fields as $key => $label):
                ?>
                    <div class="ace-field"><label for="detail_<?php echo ace_iii_escape($key); ?>"><?php echo ace_iii_escape($label); ?></label><input class="form-control" id="detail_<?php echo ace_iii_escape($key); ?>" name="patient_details[<?php echo ace_iii_escape($key); ?>]" value="<?php echo ace_iii_escape($details[$key] ?? ''); ?>" maxlength="250"></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ace-card">
            <h2>Attention</h2><p class="ace-sub">Orientation, word registration and serial subtraction · section maximum 18.</p>
            <?php
            $orientation = [
                'orientation_day' => 'What is the day?',
                'orientation_date' => 'What is the date?',
                'orientation_month' => 'What is the month?',
                'orientation_year' => 'What is the year?',
                'orientation_season' => 'What is the season?',
                'orientation_floor' => 'Which floor?',
                'orientation_street' => 'Which street / hospital?',
                'orientation_city' => 'Which city?',
                'orientation_state' => 'Which state?',
                'orientation_country' => 'Which country?',
            ];
            ?>
            <div class="ace-orientation-grid">
            <?php
            foreach ($orientation as $field => $prompt):
            ?>
                <div class="ace-orientation-item"><div class="ace-answer"><label for="answer_<?php echo $field; ?>"><?php echo ace_iii_escape($prompt); ?></label><input class="form-control" id="answer_<?php echo $field; ?>" name="answers[<?php echo $field; ?>]" value="<?php echo ace_iii_escape($answers[$field] ?? ''); ?>"></div><?php echo ace_iii_score_input($field, 1, $submitted_scores); ?></div>
            <?php endforeach; ?>
            </div>
            <div class="ace-task"><div class="ace-task-title">Word registration</div><p class="ace-instruction">Say: “I’m going to give you three words and I’d like you to repeat them after me: lemon, key and ball.” Repeat up to three times if necessary. Score only the first trial.</p><input class="form-control" name="answers[registration_trials]" aria-label="Record registration trials" placeholder="Record the subject's first and repeat trials" value="<?php echo ace_iii_escape($answers['registration_trials'] ?? ''); ?>"><div class="mt-2"><?php echo ace_iii_score_input('registration', 3, $submitted_scores); ?></div></div>
            <div class="ace-task"><div class="ace-task-title">Attention · serial subtraction</div><p class="ace-instruction">Ask the subject to keep subtracting 7 from 100. Continue after a mistake; stop after five subtractions.</p><div class="ace-mini-grid"><?php for ($i = 1; $i <= 5; $i++): ?><div class="ace-field"><label for="subtraction_<?php echo $i; ?>">Answer <?php echo $i; ?></label><input class="form-control" id="subtraction_<?php echo $i; ?>" name="answers[subtraction_<?php echo $i; ?>]" value="<?php echo ace_iii_escape($answers['subtraction_' . $i] ?? ''); ?>"></div><?php endfor; ?></div><div class="mt-2"><?php echo ace_iii_score_input('subtraction', 5, $submitted_scores); ?></div></div>
        </section>

        <section class="ace-card">
            <h2>Memory</h2><p class="ace-sub">Delayed word recall · maximum 3.</p>
            <div class="ace-task-title">Ask: “Which 3 words did I ask you to repeat and remember?”</div>
            <input class="form-control mt-2" name="answers[word_recall_response]" placeholder="Record recalled words" value="<?php echo ace_iii_escape($answers['word_recall_response'] ?? ''); ?>">
            <div class="mt-2"><?php echo ace_iii_score_input('word_recall', 3, $submitted_scores); ?></div>
        </section>

        <section class="ace-card">
            <h2>Fluency</h2><p class="ace-sub">Record responses produced in one minute and enter the corresponding score.</p>
            <div class="ace-task"><div class="ace-task-title">Letters · use the letter P</div><p class="ace-instruction">Generate as many words as possible beginning with P, without names of people or places. Allow one minute.</p><textarea class="form-control" rows="3" name="answers[letter_fluency_response]" placeholder="Record words separated by commas"><?php echo ace_iii_escape($answers['letter_fluency_response'] ?? ''); ?></textarea><div class="mt-2"><?php echo ace_iii_score_input('letter_fluency', 7, $submitted_scores); ?></div><p class="ace-instruction">Score: &gt;14 = 7; 11–14 = 6; 8–10 = 5; 6–7 = 4; 3–5 = 3; 2 = 2; 1 = 1; 0 = 0.</p></div>
            <div class="ace-task"><div class="ace-task-title">Animals</div><p class="ace-instruction">Name as many animals as possible. Allow one minute.</p><textarea class="form-control" rows="3" name="answers[animal_fluency_response]" placeholder="Record animals separated by commas"><?php echo ace_iii_escape($answers['animal_fluency_response'] ?? ''); ?></textarea><div class="mt-2"><?php echo ace_iii_score_input('animal_fluency', 7, $submitted_scores); ?></div><p class="ace-instruction">Score: &gt;16 = 7; 14–16 = 6; 11–13 = 5; 8–10 = 4; 6–7 = 3; 3–5 = 2; 1–2 = 1; 0 = 0.</p></div>
        </section>

        <section class="ace-card">
            <h2>Memory</h2><p class="ace-sub">Name-and-address learning, retrograde memory and recognition.</p>
            <div class="ace-task"><div class="ace-task-title">Name-and-address learning</div><p class="ace-instruction">Read the name and address. Ask the subject to repeat it after you. Repeat up to three times; score only the third trial.</p><strong>Sunil Kumar Singh · 52, Station Road · Gandhinagar · Allahabad</strong>
                <?php for ($trial = 1; $trial <= 3; $trial++): ?><div class="ace-field mt-2"><label for="address_trial_<?php echo $trial; ?>"><?php echo $trial; ?><?php echo $trial === 1 ? 'st' : ($trial === 2 ? 'nd' : 'rd'); ?> trial</label><textarea class="form-control" rows="2" id="address_trial_<?php echo $trial; ?>" name="answers[address_trial_<?php echo $trial; ?>]"><?php echo ace_iii_escape($answers['address_trial_' . $trial] ?? ''); ?></textarea></div><?php endfor; ?>
                <div class="mt-2"><?php echo ace_iii_score_input('address_learning', 7, $submitted_scores); ?></div>
            </div>
            <div class="ace-task"><div class="ace-task-title">General memory</div><p class="ace-instruction">Ask the following questions and record the answers.</p>
                <?php
                $memory_questions = [
                    'memory_chief_minister' => 'Name the current Chief Minister.',
                    'memory_prime_minister' => 'Name the Prime Minister of India.',
                    'memory_actor' => 'Name the actor who was hero in the film “Mera Naam Joker”.',
                    'memory_father_nation' => 'Name the Father of our Nation.',
                ];
                foreach ($memory_questions as $field => $question):
                ?>
                    <div class="ace-prompt-answer"><div class="ace-answer"><label for="<?php echo $field; ?>"><?php echo ace_iii_escape($question); ?></label><input class="form-control" id="<?php echo $field; ?>" name="answers[<?php echo $field; ?>]" value="<?php echo ace_iii_escape($answers[$field] ?? ''); ?>"></div><?php echo ace_iii_score_input($field, 1, $submitted_scores); ?></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ace-card">
            <h2>Language</h2><p class="ace-sub">Follow the command instructions in the sequence shown. Maximum 3.</p>
            <p class="ace-instruction">Place a pencil and a piece of paper in front of the subject. As a practice trial, ask them to pick up the pencil and then the paper. If correct, continue with each command below, replacing the objects before every command.</p>
            <?php
            $commands = [
                'command_1' => 'Place the paper on top of the pencil.',
                'command_2' => 'Pick up the pencil but not the paper.',
                'command_3' => 'Pass me the pencil after touching the paper.',
            ];
            foreach ($commands as $field => $command):
            ?>
                <div class="ace-prompt-answer"><div class="ace-prompt"><?php echo ace_iii_escape($command); ?></div><?php echo ace_iii_score_input($field, 1, $submitted_scores); ?></div>
            <?php endforeach; ?>
            <div class="ace-task"><div class="ace-task-title">Writing</div><p class="ace-instruction">Ask the subject to write two or more complete sentences about their last holiday, weekend or festival. Give one point for at least two complete sentences on one topic, and one further point if grammar and spelling are correct.</p><textarea class="form-control" rows="4" name="answers[writing_response]"><?php echo ace_iii_escape($answers['writing_response'] ?? ''); ?></textarea><div class="mt-2"><?php echo ace_iii_score_input('writing', 2, $submitted_scores); ?></div></div>
            <div class="ace-task"><div class="ace-task-title">Word repetition</div><p class="ace-instruction">Ask the subject to repeat: “caterpillar”; “eccentricity”; “unintelligible”; “statistician”. Score 2 if all are correct, 1 if 3 are correct, and 0 if 2 or fewer are correct.</p><input class="form-control" name="answers[multisyllabic_response]" value="<?php echo ace_iii_escape($answers['multisyllabic_response'] ?? ''); ?>" placeholder="Record the response"><div class="mt-2"><?php echo ace_iii_score_input('multisyllabic_repetition', 2, $submitted_scores); ?></div></div>
            <div class="ace-task"><div class="ace-task-title">Sentence repetition</div>
                <?php foreach (['proverb_1' => 'All that glitters is not gold.', 'proverb_2' => 'A stitch in time saves nine.'] as $field => $phrase): ?>
                    <div class="ace-prompt-answer"><div class="ace-answer"><label for="answer_<?php echo $field; ?>">Ask the subject to repeat: “<?php echo ace_iii_escape($phrase); ?>”</label><input class="form-control" id="answer_<?php echo $field; ?>" name="answers[<?php echo $field; ?>_response]" value="<?php echo ace_iii_escape($answers[$field . '_response'] ?? ''); ?>"></div><?php echo ace_iii_score_input($field, 1, $submitted_scores); ?></div>
                <?php endforeach; ?>
            </div>
            <div class="ace-task"><div class="ace-task-title">Picture naming</div><p class="ace-instruction">Ask the subject to name each picture, in order from left to right and top to bottom.</p>
                <?php
                $pictures = ['📕', '🥄', '🐐', '🕯️', '🇮🇳', '🐪', '🔪', '🦒', '🥁', '☂️', '🐖', '🐊'];
                ?>
                <div class="ace-picture-grid">
                <?php
                foreach ($pictures as $i => $picture):
                    $field = 'naming_' . ($i + 1);
                ?>
                    <div><div class="ace-picture" aria-hidden="true"><?php echo $picture; ?></div><span class="ace-picture-number">Picture <?php echo $i + 1; ?></span><input class="form-control form-control-sm mt-2" id="<?php echo $field; ?>" name="answers[<?php echo $field; ?>_response]" value="<?php echo ace_iii_escape($answers[$field . '_response'] ?? ''); ?>" aria-label="Record the subject's answer for picture <?php echo $i + 1; ?>"><?php echo ace_iii_score_input($field, 1, $submitted_scores); ?></div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="ace-task"><div class="ace-task-title">Comprehension</div><p class="ace-instruction">Using the pictures above, ask the subject to point to the one which:</p>
                <?php
                $comprehension_questions = [
                    'comprehension_1' => 'is used in rain',
                    'comprehension_2' => 'emits light',
                    'comprehension_3' => 'is associated with farming',
                    'comprehension_4' => 'is found in deserts',
                ];
                foreach ($comprehension_questions as $field => $question):
                ?>
                    <div class="ace-prompt-answer"><div class="ace-prompt"><?php echo ucfirst(ace_iii_escape($question)); ?></div><?php echo ace_iii_score_input($field, 1, $submitted_scores); ?></div>
                <?php endforeach; ?>
            </div>
            <div class="ace-task"><div class="ace-task-title">Reading</div><p class="ace-instruction">Ask the subject to read all five words. Score 1 only if all are correct.</p><strong>sew · pint · soot · dough · height</strong><input class="form-control mt-2" name="answers[reading_response]" value="<?php echo ace_iii_escape($answers['reading_response'] ?? ''); ?>" placeholder="Record response"><div class="mt-2"><?php echo ace_iii_score_input('reading', 1, $submitted_scores); ?></div></div>
        </section>

        <section class="ace-card">
            <h2>Visuospatial Abilities</h2><p class="ace-sub">Copy the figures, draw the clock, count dots and identify letters.</p>
            <div class="ace-task"><div class="ace-task-title">Infinity diagram · copy the figure</div><svg class="ace-model" viewBox="0 0 360 100" role="img" aria-label="Infinity diagram to copy"><path d="M12 50 C12 8 68 8 112 50 C156 92 212 92 212 50 C212 8 156 8 112 50 C68 92 12 92 12 50 C12 8 68 8 112 50 C156 92 212 92 212 50 C212 8 268 8 312 50 C356 92 356 8 312 50" /></svg><div class="ace-canvas-wrap"><canvas class="ace-canvas" data-drawing="infinity" aria-label="Drawing area for infinity diagram"></canvas></div><button class="btn btn-sm btn-outline-secondary ace-clear" type="button">Clear drawing</button><input type="hidden" name="answers[drawing_infinity]" value="<?php echo ace_iii_escape($answers['drawing_infinity'] ?? ''); ?>"><?php echo ace_iii_score_input('infinity', 1, $submitted_scores); ?></div>
            <div class="ace-task"><div class="ace-task-title">Wire cube · copy the drawing</div><svg class="ace-model" viewBox="0 0 220 110" role="img" aria-label="Wire cube to copy"><path d="M36 32 L105 12 L178 32 L108 53 Z M36 32 L36 86 L108 105 L108 53 M108 53 L178 32 L178 85 L108 105 M105 12 L105 66 M105 66 L178 85 M36 86 L105 66" /></svg><div class="ace-canvas-wrap"><canvas class="ace-canvas" data-drawing="cube" aria-label="Drawing area for wire cube"></canvas></div><button class="btn btn-sm btn-outline-secondary ace-clear" type="button">Clear drawing</button><input type="hidden" name="answers[drawing_cube]" value="<?php echo ace_iii_escape($answers['drawing_cube'] ?? ''); ?>"><?php echo ace_iii_score_input('cube', 2, $submitted_scores); ?></div>
            <div class="ace-task"><div class="ace-task-title">Clock drawing</div><p class="ace-instruction">Draw a clock face with numbers and the hands at ten past five. Circle = 1, numbers = 2, hands = 2 if all correct.</p><div class="ace-canvas-wrap"><canvas class="ace-canvas" data-drawing="clock" aria-label="Drawing area for clock"></canvas></div><button class="btn btn-sm btn-outline-secondary ace-clear" type="button">Clear drawing</button><input type="hidden" name="answers[drawing_clock]" value="<?php echo ace_iii_escape($answers['drawing_clock'] ?? ''); ?>"><?php echo ace_iii_score_input('clock', 5, $submitted_scores); ?></div>
            <div class="ace-task"><div class="ace-task-title">Dot counting</div><p class="ace-instruction">Ask the subject to count the dots without pointing to them.</p>
                <?php
                $dot_positions = [
                    [[10, 7], [15, 42], [36, 54], [54, 35], [68, 72], [85, 26], [84, 66], [52, 94]],
                    [[22, 38], [28, 17], [48, 34], [69, 12], [86, 13], [36, 65], [61, 80], [65, 93], [79, 63], [91, 87]],
                    [[17, 15], [61, 18], [36, 46], [46, 37], [42, 64], [8, 92], [74, 85]],
                    [[18, 12], [68, 22], [45, 37], [17, 52], [75, 52], [81, 64], [28, 78], [48, 82], [91, 96]],
                ];
                foreach ($dot_positions as $index => $positions):
                    $field = 'dots_' . ($index + 1);
                ?>
                    <div class="ace-prompt-answer"><div><div class="ace-dots" aria-label="Dot counting figure <?php echo $index + 1; ?>"><div class="ace-dot-grid"><?php foreach ($positions as $position): ?><i style="left:<?php echo $position[0]; ?>%;top:<?php echo $position[1]; ?>%"></i><?php endforeach; ?></div></div><p class="ace-instruction">Figure <?php echo $index + 1; ?> · record count: <input class="form-control form-control-sm d-inline-block" style="width:80px" name="answers[<?php echo $field; ?>_response]" value="<?php echo ace_iii_escape($answers[$field . '_response'] ?? ''); ?>" aria-label="Recorded dot count for figure <?php echo $index + 1; ?>"></p></div><?php echo ace_iii_score_input($field, 1, $submitted_scores); ?></div>
                <?php endforeach; ?>
            </div>
            <div class="ace-task"><div class="ace-task-title">Identify the fragmented letters</div><p class="ace-instruction">Ask the subject to identify each fragmented letter. Enter one point per correctly identified letter.</p>
                <?php for ($i = 1; $i <= 4; $i++): ?><div class="ace-prompt-answer"><div class="ace-answer"><label for="letter_fragment_<?php echo $i; ?>">Letter <?php echo $i; ?></label><input class="form-control" id="letter_fragment_<?php echo $i; ?>" name="answers[letter_fragment_<?php echo $i; ?>_response]" value="<?php echo ace_iii_escape($answers['letter_fragment_' . $i . '_response'] ?? ''); ?>"></div><?php echo ace_iii_score_input('letters_' . $i, 1, $submitted_scores); ?></div><?php endfor; ?>
            </div>
            <div class="ace-task"><div class="ace-task-title">Delayed name-and-address recall</div><p class="ace-instruction">Ask: “Now tell me what you remember about that name and address we were repeating at the beginning.”</p><textarea class="form-control" name="answers[address_recall_response]" rows="2"><?php echo ace_iii_escape($answers['address_recall_response'] ?? ''); ?></textarea><div class="mt-2"><?php echo ace_iii_score_input('address_recall', 7, $submitted_scores); ?></div></div>
            <div class="ace-task"><div class="ace-task-title">Recognition (if required)</div><p class="ace-instruction">Use when one or more items were not recalled. Record any prompts or recognised details and score according to the form instructions.</p><textarea class="form-control" name="answers[address_recognition_response]" rows="2"><?php echo ace_iii_escape($answers['address_recognition_response'] ?? ''); ?></textarea><div class="mt-2"><?php echo ace_iii_score_input('address_recognition', 5, $submitted_scores); ?></div></div>
        </section>

        <section class="ace-card">
            <h2>Score summary</h2><p class="ace-sub">Scores update as you enter them. Maximum total: 100 points.</p>
            <div class="ace-summary">
                <?php foreach ($sections as $section => $section_data): ?>
                    <div><span><?php echo ace_iii_escape($section_data['title']); ?> / <?php echo $section_data['max']; ?></span><strong id="total_<?php echo $section; ?>">0</strong></div>
                <?php endforeach; ?>
                <div><span>Total / 100</span><strong id="total_all">0</strong></div>
            </div>
            <label class="ace-field d-block mt-3" for="clinical_notes"><span class="d-block mb-1">Clinical observations / notes (optional)</span><textarea class="form-control" id="clinical_notes" name="clinical_notes" rows="3" maxlength="5000"><?php echo ace_iii_escape($notes); ?></textarea></label>
            <p class="ace-note">The examiner should enter item scores in accordance with the scoring instructions supplied with the assessment.</p>
            <div class="ace-submit mt-3"><a class="btn btn-outline-secondary" href="ace-iii-assessment-records.php">View saved records</a><button type="submit" class="btn btn-primary" name="submit_ace_iii" value="1" <?php echo empty($patients) || !$ace_iii_schema_ready ? 'disabled' : ''; ?>><i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Save assessment</button></div>
        </section>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var scores = document.querySelectorAll('.ace-score');
    function updateTotals() {
        var totals = {attention: 0, memory: 0, fluency: 0, language: 0, visuospatial: 0};
        scores.forEach(function (input) {
            var value = Number(input.value);
            var maximum = Number(input.max);
            if (input.value !== '' && Number.isFinite(value) && value >= 0 && value <= maximum) {
                var name = input.name.match(/^scores\[([^\]]+)\]$/);
                if (!name) return;
                var field = name[1];
                var section = field.indexOf('orientation_') === 0 || field === 'registration' || field === 'subtraction' ? 'attention'
                    : field === 'word_recall' || field.indexOf('address_') === 0 || field.indexOf('memory_') === 0 ? 'memory'
                    : field.indexOf('letter_fluency') === 0 || field.indexOf('animal_fluency') === 0 ? 'fluency'
                    : field.indexOf('dots_') === 0 || field.indexOf('letters_') === 0 || ['infinity','cube','clock'].indexOf(field) !== -1 ? 'visuospatial'
                    : 'language';
                totals[section] += value;
            }
        });
        var grand = 0;
        Object.keys(totals).forEach(function (section) {
            document.getElementById('total_' + section).textContent = String(totals[section]);
            grand += totals[section];
        });
        document.getElementById('total_all').textContent = String(grand);
    }
    scores.forEach(function (input) { input.addEventListener('input', updateTotals); });
    updateTotals();

    document.querySelectorAll('.ace-canvas').forEach(function (canvas) {
        var context = canvas.getContext('2d');
        var hidden = document.querySelector('input[name="answers[drawing_' + canvas.dataset.drawing + ']"]');
        function fitCanvas() {
            var ratio = window.devicePixelRatio || 1;
            var width = canvas.clientWidth;
            var height = 220;
            var previous = hidden.value;
            canvas.width = width * ratio;
            canvas.height = height * ratio;
            context.scale(ratio, ratio);
            context.lineWidth = 3;
            context.lineCap = 'round';
            context.strokeStyle = '#172033';
            if (previous) {
                var image = new Image();
                image.onload = function () { context.drawImage(image, 0, 0, width, height); };
                image.src = previous;
            }
        }
        fitCanvas();
        var drawing = false;
        function point(event) {
            var rect = canvas.getBoundingClientRect();
            return {x: event.clientX - rect.left, y: event.clientY - rect.top};
        }
        canvas.addEventListener('pointerdown', function (event) {
            drawing = true;
            canvas.setPointerCapture(event.pointerId);
            var p = point(event);
            context.beginPath();
            context.moveTo(p.x, p.y);
        });
        canvas.addEventListener('pointermove', function (event) {
            if (!drawing) return;
            var p = point(event);
            context.lineTo(p.x, p.y);
            context.stroke();
        });
        function finishDrawing() {
            if (!drawing) return;
            drawing = false;
            hidden.value = canvas.toDataURL('image/png');
        }
        canvas.addEventListener('pointerup', finishDrawing);
        canvas.addEventListener('pointercancel', finishDrawing);
        canvas.nextElementSibling.addEventListener('click', function () {
            context.clearRect(0, 0, canvas.width, canvas.height);
            hidden.value = '';
        });
    });
});
</script>
<?php require 'layout/footer.php'; ?>
