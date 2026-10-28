<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'BPRS Assessment - Vimhans Academic';
$bprs_items = [
    1 => [
        'field' => 'q1_somatic_concern',
        'title' => '1. SOMATIC CONCERN',
        'desc' => 'Degree of concern over present bodily health. Rate the degree to which physical health is perceived as a problem by the patient, whether complaints have a realistic basis or not.',
    ],
    2 => [
        'field' => 'q2_anxiety',
        'title' => '2. ANXIETY',
        'desc' => "Worry, fear, or over-concern for present or future. Rate solely on the basis of verbal report of patient's own subjective experiences. Do not infer anxiety from physical signs or from neurotic defense mechanisms.",
    ],
    3 => [
        'field' => 'q3_emotional_withdrawal',
        'title' => '3. EMOTIONAL WITHDRAWAL',
        'desc' => 'Deficiency in relating to the interviewer and to the interviewer situation. Rate only the degree to which the patient gives the impression of failing to be in emotional contact with other people in the interview situation.',
    ],
    4 => [
        'field' => 'q4_conceptual_disorganization',
        'title' => '4. CONCEPTUAL DISORGANIZATION',
        'desc' => "Degree to which the thought processes are confused, disconnected, or disorganized. Rate on the basis of integration of the verbal products of the patient; do not rate on the basis of the patient's subjective impression of their own level of functioning.",
    ],
    5 => [
        'field' => 'q5_guilt_feelings',
        'title' => '5. GUILT FEELINGS',
        'desc' => "Over-concern or remorse for past behavior. Rate on the basis of the patient's subjective experiences of guilt as evidenced by verbal report with appropriate affect; do not infer guilt feelings from depression, anxiety or neurotic defenses.",
    ],
    6 => [
        'field' => 'q6_tension',
        'title' => '6. TENSION',
        'desc' => 'Physical and motor manifestations of tension (nervousness) and heightened activation level. Tension should be rated solely on the basis of physical signs and motor behavior, and not on subjective experiences of tension reported by the patient.',
    ],
    7 => [
        'field' => 'q7_mannerisms_posturing',
        'title' => '7. MANNERISMS AND POSTURING',
        'desc' => 'Unusual and unnatural motor behavior, the type of motor behavior which causes certain mental patients to stand out in a crowd of normal people. Rate only abnormality of movements; do not rate simple heightened motor activity here.',
    ],
    8 => [
        'field' => 'q8_grandiosity',
        'title' => '8. GRANDIOSITY',
        'desc' => "Exaggerated self-opinion, conviction of unusual ability or powers. Rate only on the basis of the patient's statements about themself or self-in-relation-to-others, not on the basis of their demeanor in the interview situation.",
    ],
    9 => [
        'field' => 'q9_depressive_mood',
        'title' => '9. DEPRESSIVE MOOD',
        'desc' => 'Despondency in mood, sadness. Rate only degree of despondency; do not rate on the basis of inferences concerning depression based upon general retardation and somatic complaints.',
    ],
    10 => [
        'field' => 'q10_hostility',
        'title' => '10. HOSTILITY',
        'desc' => 'Animosity, contempt, belligerence, disdain for other people outside the interview situation. Rate solely on the basis of verbal report of feelings and actions toward others; do not infer hostility from neurotic defenses, anxiety, or somatic complaints. Rate attitude toward interviewer under uncooperativeness.',
    ],
    11 => [
        'field' => 'q11_suspiciousness',
        'title' => '11. SUSPICIOUSNESS',
        'desc' => 'Belief (delusional or otherwise) that others have now, or have had in the past, malicious or discriminatory intent toward the patient. On the basis of verbal report, rate only those suspicions which are currently held whether they concern past or present circumstances.',
    ],
    12 => [
        'field' => 'q12_hallucinatory_behavior',
        'title' => '12. HALLUCINATORY BEHAVIOR',
        'desc' => 'Perceptions without normal external stimulus correspondence. Rate only experiences reported to have occurred within the last week and described as distinctly different from the thought and imagery processes of normal people.',
    ],
    13 => [
        'field' => 'q13_motor_retardation',
        'title' => '13. MOTOR RETARDATION',
        'desc' => "Reduction in energy level evidenced in slowed movements. Rate on the basis of observed behavior of the patient only; do not rate on the basis of the patient's subjective impression of their own energy level.",
    ],
    14 => [
        'field' => 'q14_uncooperativeness',
        'title' => '14. UNCOOPERATIVENESS',
        'desc' => "Evidence of resistance, unfriendliness, resentment, and lack of readiness to cooperate with the interviewer. Rate only on the basis of the patient's attitude and responses to the interviewer and interview situation; do not rate on reported resentment or uncooperativeness outside the interview.",
    ],
    15 => [
        'field' => 'q15_unusual_thought_content',
        'title' => '15. UNUSUAL THOUGHT CONTENT',
        'desc' => 'Unusual, odd, strange or bizarre thought content. Rate here the degree of unusualness, not the degree of disorganization of thought processes.',
    ],
    16 => [
        'field' => 'q16_blunted_affect',
        'title' => '16. BLUNTED AFFECT',
        'desc' => 'Reduced emotional tone, apparent lack of normal feeling or involvement.',
    ],
    17 => [
        'field' => 'q17_excitement',
        'title' => '17. EXCITEMENT',
        'desc' => 'Heightened emotional tone, agitation, increased reactivity.',
    ],
    18 => [
        'field' => 'q18_disorientation',
        'title' => '18. DISORIENTATION',
        'desc' => 'Confusion or lack of proper association for person, place or time.',
    ],
];
$rating_scale = [
    0 => 'Not Assessed',
    1 => 'Not Present',
    2 => 'Very Mild',
    3 => 'Mild',
    4 => 'Moderate',
    5 => 'Moderately Severe',
    6 => 'Severe',
    7 => 'Extremely Severe',
];
$patients = [];
$success_msg = '';
$error_msg = '';
$score_errors = [];
$submitted_scores = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
$form_patient_input = $_POST['patient_id'] ?? '';
$form_patient_id = is_string($form_patient_input) || is_int($form_patient_input)
    ? filter_var($form_patient_input, FILTER_VALIDATE_INT)
    : false;
$form_patient_id = $form_patient_id === false ? 0 : (int) $form_patient_id;
$form_date_input = $_POST['assessment_date'] ?? date('Y-m-d');
$form_date = is_string($form_date_input) ? $form_date_input : date('Y-m-d');
$form_notes_input = $_POST['clinical_notes'] ?? '';
$form_notes = is_string($form_notes_input) ? $form_notes_input : '';
$clinician_name = trim((string) ($_SESSION['name'] ?? ''));

if (!isset($_SESSION['bprs_csrf_token'])) {
    $_SESSION['bprs_csrf_token'] = bin2hex(random_bytes(32));
}

$schema_sql = "CREATE TABLE IF NOT EXISTS bprs_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    clinician_id INT NOT NULL,
    assessment_date DATE NOT NULL,
    total_score SMALLINT UNSIGNED NOT NULL,
    score_data LONGTEXT NOT NULL,
    clinical_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bprs_patient_date (patient_id, assessment_date),
    INDEX idx_bprs_clinician (clinician_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$schema_ready = $conn->query($schema_sql);

if (!$schema_ready) {
    error_log('BPRS table initialization failed: ' . $conn->error);
    $error_msg = 'The BPRS assessment table could not be initialized. Please contact the system administrator.';
}

if ($schema_ready) {
    $patient_result = $conn->query(
        "SELECT id, first_name, last_name, email FROM users WHERE role_id = 2 AND patient_id = 23 ORDER BY first_name, last_name"
    );
    if ($patient_result) {
        while ($patient = $patient_result->fetch_assoc()) {
            $patients[] = $patient;
        }
    } else {
        error_log('BPRS patient list query failed: ' . $conn->error);
        $error_msg = 'The patient list could not be loaded. Please contact the system administrator.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bprs']) && $schema_ready) {
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!is_string($posted_token) || !hash_equals($_SESSION['bprs_csrf_token'], $posted_token)) {
        $error_msg = 'Your form session expired. Reload the page and submit the assessment again.';
    } else {
        $patient_input = $_POST['patient_id'] ?? null;
        $patient_id = is_string($patient_input) || is_int($patient_input)
            ? filter_var($patient_input, FILTER_VALIDATE_INT)
            : false;
        $assessment_date = $_POST['assessment_date'] ?? '';
        $notes_input = $_POST['clinical_notes'] ?? '';
        $clinical_notes = is_string($notes_input) ? trim($notes_input) : '';
        $scores = [];
        $total_score = 0;

        if ($patient_id === false || $patient_id === null || $patient_id < 1) {
            $error_msg = 'Please select a valid patient.';
        } elseif (!is_string($assessment_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assessment_date)) {
            $error_msg = 'Please enter a valid assessment date.';
        } else {
            [$year, $month, $day] = array_map('intval', explode('-', $assessment_date));
            if (!checkdate($month, $day, $year)) {
                $error_msg = 'Please enter a valid assessment date.';
            }
        }

        if ($error_msg === '' && !is_string($notes_input)) {
            $error_msg = 'Please enter valid clinical notes.';
        } elseif ($error_msg === '' && strlen($clinical_notes) > 5000) {
            $error_msg = 'Clinical notes must be 5,000 characters or fewer.';
        }

        $posted_scores = $_POST['scores'] ?? [];
        if (!is_array($posted_scores)) {
            $posted_scores = [];
        }
        foreach ($bprs_items as $item) {
            $field = $item['field'];
            $score_input = $posted_scores[$field] ?? null;
            $score = is_string($score_input) || is_int($score_input)
                ? filter_var($score_input, FILTER_VALIDATE_INT)
                : false;
            if ($score === false || $score === null || $score < 0 || $score > 7) {
                $score_errors[$field] = 'Please select a rating for this question.';
                continue;
            }
            $scores[$field] = $score;
            $total_score += $score;
        }
        if ($error_msg === '' && !empty($score_errors)) {
            $error_msg = 'Please select a rating for each highlighted question.';
        }

        if ($error_msg === '' && empty($score_errors)) {
            $patient_stmt = $conn->prepare(
                'SELECT id FROM users WHERE id = ? AND role_id = 2 LIMIT 1'
            );
            if (!$patient_stmt) {
                error_log('BPRS patient validation prepare failed: ' . $conn->error);
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
            $score_data = json_encode($scores);
            $clinician_id = (int) $_SESSION['user_id'];
            $insert_stmt = $conn->prepare(
                'INSERT INTO bprs_assessments
                    (patient_id, clinician_id, assessment_date, total_score, score_data, clinical_notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$insert_stmt) {
                error_log('BPRS insert prepare failed: ' . $conn->error);
                $error_msg = 'The assessment could not be saved. Please try again.';
            } else {
                $insert_stmt->bind_param(
                    'iisiss',
                    $patient_id,
                    $clinician_id,
                    $assessment_date,
                    $total_score,
                    $score_data,
                    $clinical_notes
                );
                if ($insert_stmt->execute()) {
                    $insert_stmt->close();
                    $_SESSION['bprs_saved_score'] = $total_score;
                    $_SESSION['bprs_csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: bprs-assessments.php?saved=1');
                    exit;
                }
                error_log('BPRS insert failed: ' . $insert_stmt->error);
                $insert_stmt->close();
                $error_msg = 'The assessment could not be saved. Please try again.';
            }
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $saved_score = filter_var($_SESSION['bprs_saved_score'] ?? null, FILTER_VALIDATE_INT);
    $success_msg = 'BPRS assessment saved successfully. Total score: ' . ($saved_score === false ? '0' : $saved_score) . ' / 126.';
    unset($_SESSION['bprs_saved_score']);
}

function bprs_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

require 'layout/base.php';
?>

<style>
    .bprs-page { max-width: 1440px; margin: 0 auto; padding: 28px 20px 42px; }
    .bprs-header-card { position: sticky; top: 72px; z-index: 25; display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 24px; padding: 18px 24px; border-radius: 14px; background: linear-gradient(135deg, #1e293b, #334155); color: #fff; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, .15); }
    .bprs-header-card h1 { margin: 0 0 5px; color: #fff; font-size: 21px; font-weight: 800; }
    .bprs-header-card p { margin: 0; color: rgba(255,255,255,.72); font-size: 12px; }
    .bprs-header-score { min-width: 120px; padding: 8px 13px; border: 1px solid rgba(255,255,255,.14); border-radius: 10px; background: rgba(255,255,255,.08); text-align: center; }
    .bprs-header-score small { display: block; color: rgba(255,255,255,.68); font-size: 11px; }
    .bprs-header-score strong { font-size: 23px; }
    .bprs-toast { position: fixed; top: 14px; right: 16px; z-index: 1100; display: flex; width: min(360px, calc(100vw - 32px)); align-items: center; gap: 13px; overflow: hidden; padding: 12px 16px; border-radius: 6px; color: #fff; box-shadow: 0 4px 12px rgba(15,23,42,.2); opacity: 1; transform: translateY(0); transition: opacity .25s ease, transform .25s ease; }
    .bprs-toast-success { background: #19c99a; }
    .bprs-toast-error { background: #dc3545; }
    .bprs-toast.is-hiding { opacity: 0; transform: translateY(-8px); }
    .bprs-toast-icon { display: grid; width: 40px; height: 40px; flex: 0 0 auto; place-items: center; border: 4px solid rgba(255,255,255,.9); border-radius: 50%; font-size: 20px; }
    .bprs-toast-copy { min-width: 0; }
    .bprs-toast-title { margin-right: 8px; font-size: 17px; font-weight: 800; }
    .bprs-toast-message { font-size: 13px; }
    .bprs-toast-progress { position: absolute; right: 0; bottom: 0; left: 0; height: 4px; background: rgba(255,255,255,.35); transform: scaleX(0); transform-origin: left; animation: bprs-toast-progress 3s linear forwards; }
    @keyframes bprs-toast-progress { to { transform: scaleX(1); } }
    @media (prefers-reduced-motion: reduce) { .bprs-toast { transition: none; } .bprs-toast-progress { animation: none; } }
    .score-question-error { display: none; margin: 8px 0 0; color: #dc3545; font-size: 12px; font-weight: 700; }
    .symptom-card.has-score-error .checkbox-scale-group { border-color: #dc3545; }
    .symptom-card.has-score-error .score-question-error { display: block; }
    .bprs-scale-legend { position: sticky; top: 10px; z-index: 20; margin-bottom: 24px; padding: 15px 18px; border: 1px solid #e2e8f0; border-left: 5px solid #4f46e5; border-radius: 12px; background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,.03); }
    .bprs-scale-legend-title { margin-bottom: 10px; color: #334155; font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
    .bprs-scale-badges { display: flex; flex-wrap: wrap; gap: 7px; }
    .bprs-scale-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 20px; background: #f1f5f9; color: #334155; font-size: 11px; font-weight: 600; }
    .bprs-scale-badge b { display: inline-grid; width: 20px; height: 20px; place-items: center; border-radius: 50%; background: #4f46e5; color: #fff; font-size: 10px; }
    .bprs-alert { margin-bottom: 20px; padding: 13px 16px; border-radius: 9px; font-size: 13px; }
    .bprs-alert.success { border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; }
    .bprs-alert.error { border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; }
    .patient-info-card { margin-bottom: 24px; padding: 22px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.03); }
    .patient-info-card .form-label { margin-bottom: 7px; color: #475569; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .patient-info-card .form-control, .patient-info-card .form-select { min-height: 44px; padding: 9px 13px; border-color: #cbd5e1; border-radius: 8px; font-size: 13px; }
    .patient-info-card .form-control:focus, .patient-info-card .form-select:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
    .symptom-card { display: flex; height: 100%; flex-direction: column; justify-content: space-between; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,.02); transition: border-color .2s ease, box-shadow .2s ease, background .2s ease; }
    .symptom-card:hover { border-color: #cbd5e1; box-shadow: 0 8px 20px rgba(15,23,42,.06); }
    .symptom-card.rated-active { border-color: #818cf8; background: #fafafe; }
    .symptom-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 7px; color: #0f172a; font-size: 13px; font-weight: 800; }
    .symptom-title-text { line-height: 1.5; }
    .selected-score-pill { flex: 0 0 auto; padding: 4px 9px; border-radius: 6px; background: #e0e7ff; color: #4338ca; font-size: 10px; font-weight: 800; white-space: nowrap; }
    .symptom-desc { margin-bottom: 15px; color: #64748b; font-size: 12px; line-height: 1.55; }
    .checkbox-scale-group { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 7px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
    .score-checkbox-item { position: relative; display: flex; min-width: 0; min-height: 39px; align-items: center; padding: 6px 7px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; cursor: pointer; user-select: none; transition: background .15s ease, border-color .15s ease; }
    .score-checkbox-item:hover { border-color: #4f46e5; background: #f5f3ff; }
    .score-checkbox-item input { width: 15px; height: 15px; flex: 0 0 auto; margin: 0 6px 0 0; accent-color: #4f46e5; cursor: pointer; }
    .score-checkbox-item:focus-within { outline: 3px solid rgba(79,70,229,.2); outline-offset: 1px; }
    .score-checkbox-item .score-num { margin-right: 4px; color: #1e293b; font-size: 12px; font-weight: 800; }
    .score-checkbox-item .score-text { overflow: hidden; color: #64748b; font-size: 9px; line-height: 1.15; text-overflow: ellipsis; white-space: nowrap; }
    .score-checkbox-item.active-check { border-color: #4f46e5; background: #eef2ff; box-shadow: 0 0 0 1px #4f46e5; }
    .score-checkbox-item.active-check .score-num, .score-checkbox-item.active-check .score-text { color: #3730a3; font-weight: 700; }
    .bprs-footer-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 15px; margin-top: 28px; padding: 18px 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 -4px 20px rgba(0,0,0,.05); }
    .total-score-box { display: flex; align-items: center; gap: 14px; }
    .total-score-number { min-width: 78px; padding: 6px 15px; border: 1px solid #c7d2fe; border-radius: 10px; background: #eef2ff; color: #4f46e5; font-size: 27px; font-weight: 800; text-align: center; }
    .bprs-save-button { border-color: #4f46e5 !important; background: #4f46e5 !important; }
    .bprs-save-button:hover { border-color: #4338ca !important; background: #4338ca !important; }
    .bprs-disclaimer { margin: 0; padding: 12px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; font-size: 11px; line-height: 1.5; }
    @media (max-width: 991.98px) { .checkbox-scale-group { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575.98px) { .bprs-page { padding: 20px 12px 30px; } .bprs-header-card { align-items: flex-start; flex-direction: column; padding: 16px 18px; } .bprs-header-card h1 { font-size: 19px; } .bprs-header-score { width: 100%; text-align: left; } .bprs-scale-legend { position: static; } .patient-info-card { padding: 16px; } .bprs-footer-bar { align-items: stretch; flex-direction: column; padding: 16px; } .bprs-footer-bar .action-buttons { display: grid !important; grid-template-columns: 1fr; width: 100%; } }
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="bprs-page">
        <?php if ($success_msg !== ''): ?>
            <div class="bprs-toast bprs-toast-success" id="bprsSuccessToast" role="status" aria-live="polite">
                <span class="bprs-toast-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span class="bprs-toast-copy"><strong class="bprs-toast-title">Success!</strong><span class="bprs-toast-message"><?php echo bprs_escape($success_msg); ?></span></span>
                <span class="bprs-toast-progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>
        <?php if ($error_msg !== ''): ?>
            <div class="bprs-toast bprs-toast-error" id="bprsErrorToast" role="alert" aria-live="assertive">
                <span class="bprs-toast-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <span class="bprs-toast-copy"><strong class="bprs-toast-title">Error!</strong><span class="bprs-toast-message"><?php echo bprs_escape($error_msg); ?></span></span>
                <span class="bprs-toast-progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>

        <div class="bprs-header-card">
            <div>
                <span class="badge mb-2 px-3 py-2" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2);">
                    <i class="fa-solid fa-notes-medical me-1" aria-hidden="true"></i> Psychiatric Clinical Scale
                </span>
                <h1>BRIEF PSYCHIATRIC RATING SCALE (BPRS)</h1>
                <p>Select the rating that best describes the patient's condition for each item.</p>
            </div>
            <div class="bprs-header-score">
                <small>Live total score</small>
                <strong id="headerLiveScore">0</strong><span class="text-white-50"> / 126</span>
            </div>
        </div>

        <div class="bprs-scale-legend">
            <div class="bprs-scale-legend-title"><i class="fa-solid fa-scale-balanced text-primary me-1" aria-hidden="true"></i> Rating scale key</div>
            <div class="bprs-scale-badges">
                <?php foreach ($rating_scale as $num => $label): ?>
                    <span class="bprs-scale-badge"><b><?php echo (int) $num; ?></b><?php echo bprs_escape($label); ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <form action="bprs-assessments.php" method="post" id="bprsForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo bprs_escape($_SESSION['bprs_csrf_token']); ?>">
            <section class="patient-info-card" aria-label="Assessment details">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="patient_id"><i class="fa-regular fa-user me-1 text-primary" aria-hidden="true"></i> Client name <span class="text-danger">*</span></label>
                        <select id="patient_id" name="patient_id" class="form-select" required>
                            <option value="" disabled selected>Select a patient</option>
                            <?php foreach ($patients as $patient): ?>
                                <?php
                                $patient_name = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
                                $patient_label = $patient_name !== '' ? $patient_name : ($patient['email'] ?? 'Patient');
                                ?>
                                <option value="<?php echo (int) $patient['id']; ?>" data-client-name="<?php echo bprs_escape($patient_label); ?>" <?php echo $form_patient_id === (int) $patient['id'] ? 'selected' : ''; ?>>
                                    <?php echo bprs_escape($patient_label); ?> 
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="assessment_date"><i class="fa-regular fa-calendar me-1 text-primary" aria-hidden="true"></i> Date <span class="text-danger">*</span></label>
                        <input type="date" id="assessment_date" name="assessment_date" class="form-control" value="<?php echo bprs_escape($form_date); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="md_name"><i class="fa-solid fa-user-doctor me-1 text-primary" aria-hidden="true"></i> MD / Clinician</label>
                        <input type="text" id="md_name" class="form-control" value="<?php echo bprs_escape($clinician_name); ?>" placeholder="Signed-in clinician" readonly>
                    </div>
                </div>
            </section>

            <div class="row g-4">
                <?php foreach ($bprs_items as $index => $item): ?>
                    <?php $field = $item['field']; ?>
                    <div class="col-12">
                        <fieldset class="symptom-card<?php echo !empty($score_errors[$field]) ? ' has-score-error' : ''; ?>" id="card_<?php echo bprs_escape($field); ?>">
                            <legend class="symptom-title">
                                <span class="symptom-title-text"><?php echo bprs_escape($item['title']); ?></span>
                                <span class="selected-score-pill" id="badge_<?php echo bprs_escape($field); ?>">Score: 0</span>
                            </legend>
                            <p class="symptom-desc"><?php echo bprs_escape($item['desc']); ?></p>
                            <div class="checkbox-scale-group" data-field="<?php echo bprs_escape($field); ?>">
                                <?php foreach ($rating_scale as $score_val => $score_text): ?>
                                    <?php
                                    $submitted_score = $submitted_scores[$field] ?? null;
                                    $submitted_score = is_string($submitted_score) || is_int($submitted_score)
                                        ? filter_var($submitted_score, FILTER_VALIDATE_INT)
                                        : false;
                                    $checked = $submitted_score === $score_val;
                                    ?>
                                    <label class="score-checkbox-item<?php echo $checked ? ' active-check' : ''; ?>">
                                        <input
                                            type="checkbox"
                                            name="scores[<?php echo bprs_escape($field); ?>]"
                                            value="<?php echo (int) $score_val; ?>"
                                            class="bprs-checkbox"
                                            data-field="<?php echo bprs_escape($field); ?>"
                                            <?php echo $checked ? 'checked' : ''; ?>
                                            aria-label="<?php echo bprs_escape($item['title'] . ': ' . $score_val . ' ' . $score_text); ?>">
                                        <span class="score-num"><?php echo (int) $score_val; ?></span>
                                        <span class="score-text" title="<?php echo bprs_escape($score_text); ?>"><?php echo bprs_escape($score_text); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="score-question-error" id="error_<?php echo bprs_escape($field); ?>" role="alert"><?php echo bprs_escape($score_errors[$field] ?? ''); ?></p>
                        </fieldset>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="patient-info-card mt-4 mb-0">
                <label class="form-label" for="clinical_notes"><i class="fa-regular fa-note-sticky me-1 text-primary" aria-hidden="true"></i> Clinical notes <span class="text-muted fw-normal">(optional)</span></label>
                <textarea id="clinical_notes" name="clinical_notes" class="form-control" rows="3" maxlength="5000" placeholder="Add relevant clinician notes..."><?php echo bprs_escape($form_notes); ?></textarea>
            </section>

            <div class="bprs-footer-bar">
                <div class="total-score-box">
                    <div>
                        <span class="text-uppercase fw-bold text-secondary small d-block">Total BPRS score</span>
                        <small class="text-muted">Sum of all 18 parameters</small>
                    </div>
                    <div class="total-score-number" id="footerLiveScore">0</div>
                </div>
                <div class="d-flex gap-2 action-buttons">
                    <button type="reset" class="btn btn-outline-secondary px-4 py-2 fw-semibold" id="resetBprsBtn">
                        <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i> Reset form
                    </button>
                    <button type="submit" name="submit_bprs" value="1" class="btn btn-primary px-4 py-2 fw-semibold bprs-save-button" <?php echo empty($patients) || !$schema_ready ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> Save BPRS assessment
                    </button>
                </div>
            </div>
        </form>

        <p class="bprs-disclaimer">A score of 0 means “Not Assessed.” The total is a recorded score only and is not an automated diagnosis or substitute for clinical judgment.</p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var successToast = document.getElementById('bprsSuccessToast');
    var errorToast = document.getElementById('bprsErrorToast');
    var toast = successToast || errorToast;
    var toastTimer;
    if (toast) {
        window.setTimeout(function () {
            toast.classList.add('is-hiding');
            window.setTimeout(function () {
                if (successToast) {
                    window.location.href = 'bprs-assessment-records.php';
                }
            }, 250);
        }, 3000);
    }

    var checkboxes = document.querySelectorAll('.bprs-checkbox');
    var fields = <?php echo json_encode(array_values(array_column($bprs_items, 'field'))); ?>;
    var form = document.getElementById('bprsForm');
    var patientSelect = document.getElementById('patient_id');
    var assessmentDate = document.getElementById('assessment_date');

    function showErrorToast(message) {
        var currentToast = document.getElementById('bprsErrorToast');
        if (!currentToast) {
            currentToast = document.createElement('div');
            currentToast.id = 'bprsErrorToast';
            currentToast.className = 'bprs-toast bprs-toast-error';
            currentToast.setAttribute('role', 'alert');
            currentToast.setAttribute('aria-live', 'assertive');
            var icon = document.createElement('span');
            icon.className = 'bprs-toast-icon';
            icon.setAttribute('aria-hidden', 'true');
            var iconGlyph = document.createElement('i');
            iconGlyph.className = 'fa-solid fa-triangle-exclamation';
            icon.appendChild(iconGlyph);
            var copy = document.createElement('span');
            copy.className = 'bprs-toast-copy';
            var title = document.createElement('strong');
            title.className = 'bprs-toast-title';
            title.textContent = 'Error!';
            var text = document.createElement('span');
            text.className = 'bprs-toast-message';
            copy.append(title, text);
            var progress = document.createElement('span');
            progress.className = 'bprs-toast-progress';
            progress.setAttribute('aria-hidden', 'true');
            currentToast.append(icon, copy, progress);
            document.body.appendChild(currentToast);
        }
        currentToast.querySelector('.bprs-toast-message').textContent = message;
        currentToast.classList.remove('is-hiding');
        var progressBar = currentToast.querySelector('.bprs-toast-progress');
        progressBar.style.animation = 'none';
        void progressBar.offsetWidth;
        progressBar.style.animation = '';
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            currentToast.classList.add('is-hiding');
        }, 3000);
    }

    function setQuestionError(field, message) {
        var card = document.getElementById('card_' + field);
        var error = document.getElementById('error_' + field);
        if (!card || !error) {
            return;
        }
        card.classList.toggle('has-score-error', message !== '');
        error.textContent = message;
    }

    function updateTotal() {
        var total = 0;
        fields.forEach(function (field) {
            var selected = document.querySelector('.bprs-checkbox[data-field="' + field + '"]:checked');
            var score = selected ? Number(selected.value) : 0;
            total += score;
            var badge = document.getElementById('badge_' + field);
            var card = document.getElementById('card_' + field);
            if (badge) {
                badge.textContent = 'Score: ' + score;
            }
            if (card) {
                card.classList.toggle('rated-active', selected !== null);
            }
        });
        document.getElementById('headerLiveScore').textContent = String(total);
        document.getElementById('footerLiveScore').textContent = String(total);
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var group = document.querySelectorAll('.bprs-checkbox[data-field="' + this.dataset.field + '"]');
            if (this.checked) {
                group.forEach(function (option) {
                    if (option !== checkbox) {
                        option.checked = false;
                    }
                });
            }
            setQuestionError(this.dataset.field, '');
            group.forEach(function (option) {
                option.closest('.score-checkbox-item').classList.toggle('active-check', option.checked);
            });
            updateTotal();
        });
    });

    form.addEventListener('submit', function (event) {
        var missingFields = [];
        fields.forEach(function (field) {
            var selected = document.querySelector('.bprs-checkbox[data-field="' + field + '"]:checked');
            if (!selected) {
                missingFields.push(field);
                setQuestionError(field, 'Please select a rating for this question.');
            } else {
                setQuestionError(field, '');
            }
        });

        if (missingFields.length > 0) {
            event.preventDefault();
            showErrorToast('Please select a rating for each highlighted question.');
            var firstMissing = document.getElementById('card_' + missingFields[0]);
            if (firstMissing) {
                firstMissing.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        if (!patientSelect.value || !assessmentDate.value || !assessmentDate.checkValidity()) {
            event.preventDefault();
            showErrorToast(!patientSelect.value
                ? 'Please select a client.'
                : 'Please enter a valid assessment date.');
            var invalidControl = !patientSelect.value ? patientSelect : assessmentDate;
            invalidControl.focus();
            invalidControl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    form.addEventListener('reset', function () {
        window.setTimeout(function () {
            fields.forEach(function (field) {
                setQuestionError(field, '');
            });
            document.querySelectorAll('.score-checkbox-item').forEach(function (label) {
                label.classList.toggle('active-check', label.querySelector('.bprs-checkbox').checked);
            });
            updateTotal();
        }, 0);
    });

    updateTotal();
});
</script>

<?php require 'layout/footer.php'; ?>
