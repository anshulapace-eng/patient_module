<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();
require 'config.php';
require 'assessment-demographics.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'BDI Assessment - Vimhans Academic';
$assessment_id = 1;
$patients = [];
$questions = [];
$error_msg = '';
$success_msg = '';
$form_patient_id = filter_var($_POST['patient_id'] ?? ($_GET['patient_id'] ?? 0), FILTER_VALIDATE_INT);
$form_patient_id = $form_patient_id === false ? 0 : (int) $form_patient_id;
$form_date = is_string($_POST['assessment_date'] ?? null) ? $_POST['assessment_date'] : date('Y-m-d');
$clinician_name = trim((string) ($_SESSION['name'] ?? ''));
$demographics = assessment_demographics_normalize($_POST['demographics'] ?? []);
$demographics['date'] = $demographics['date'] ?: $form_date;
$demographics['filled_by'] = $demographics['filled_by'] ?: $clinician_name;

if (!isset($_SESSION['bdi_csrf_token'])) $_SESSION['bdi_csrf_token'] = bin2hex(random_bytes(32));
assessment_demographics_ensure_column($conn, 'assessment_responses', 'status_text');

$result = $conn->query("SELECT id, first_name, last_name, phone, email FROM users WHERE role_id = 2 AND patient_id = " . PATIENT_SCOPE_ID . " ORDER BY first_name, last_name");
if ($result) while ($row = $result->fetch_assoc()) $patients[] = $row;

$q_result = $conn->prepare('SELECT id, question_text, sort_order FROM assessment_questions WHERE assessment_id = ? ORDER BY sort_order ASC, id ASC');
$q_result->bind_param('i', $assessment_id);
$q_result->execute();
$q_rows = $q_result->get_result();
while ($question = $q_rows->fetch_assoc()) {
    $options = [];
    $o_stmt = $conn->prepare('SELECT option_text, score_value FROM assessment_options WHERE question_id = ? ORDER BY id ASC');
    $o_stmt->bind_param('i', $question['id']);
    $o_stmt->execute();
    $o_rows = $o_stmt->get_result();
    while ($option = $o_rows->fetch_assoc()) $options[] = $option;
    $o_stmt->close();
    $question['options'] = $options;
    $questions[] = $question;
}
$q_result->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['bdi_csrf_token'], $token)) {
        $error_msg = 'Your form session expired. Reload and submit again.';
    } elseif ($form_patient_id < 1) {
        $error_msg = 'Please select a patient.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_date)) {
        $error_msg = 'Please enter a valid assessment date.';
    } else {
        $patient_check = $conn->prepare('SELECT id FROM users WHERE id = ? AND role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . ' LIMIT 1');
        $patient_check->bind_param('i', $form_patient_id);
        $patient_check->execute();
        $patient_check->store_result();
        if ($patient_check->num_rows !== 1) $error_msg = 'The selected patient could not be found.';
        $patient_check->close();
    }

    if ($error_msg === '') {
        $total = 0;
        $responses = [];
        foreach ($questions as $question) {
            $raw = $_POST['q_' . $question['id']] ?? null;
            $score = filter_var($raw, FILTER_VALIDATE_INT);
            if ($score === false) {
                $error_msg = 'Please answer every question.';
                break;
            }
            $selected_text = '';
            foreach ($question['options'] as $option) {
                if ((int) $option['score_value'] === (int) $score) $selected_text = $option['option_text'];
            }
            $total += (int) $score;
            $responses[$question['id']] = ['question' => $question['question_text'], 'selected_score' => (int) $score, 'selected_text' => $selected_text];
        }

        if ($error_msg === '') {
            $status = $total <= 10 ? 'Normal' : ($total <= 16 ? 'Mild' : ($total <= 20 ? 'Borderline' : ($total <= 30 ? 'Moderate' : ($total <= 40 ? 'Severe' : 'Extremely Severe'))));
            $assignment_id = 0;
            $a_find = $conn->prepare("SELECT id FROM assigned_assessments WHERE patient_id = ? AND assessment_id = ? ORDER BY CASE WHEN status = 'Pending' THEN 0 ELSE 1 END, id DESC LIMIT 1");
            $a_find->bind_param('ii', $form_patient_id, $assessment_id);
            $a_find->execute();
            $a_row = $a_find->get_result()->fetch_assoc();
            $a_find->close();
            if ($a_row) {
                $assignment_id = (int) $a_row['id'];
            } else {
                $a_insert = $conn->prepare("INSERT INTO assigned_assessments (patient_id, assessment_id, status, assigned_date) VALUES (?, ?, 'Pending', NOW())");
                $a_insert->bind_param('ii', $form_patient_id, $assessment_id);
                $a_insert->execute();
                $assignment_id = (int) $conn->insert_id;
                $a_insert->close();
            }

            $patient_name = '';
            $patient_phone = '';
            $patient_email = '';
            $p_stmt = $conn->prepare('SELECT first_name, last_name, phone, email FROM users WHERE id = ? LIMIT 1');
            $p_stmt->bind_param('i', $form_patient_id);
            $p_stmt->execute();
            $p_row = $p_stmt->get_result()->fetch_assoc();
            $p_stmt->close();
            if ($p_row) {
                $patient_name = trim(($p_row['first_name'] ?? '') . ' ' . (($p_row['last_name'] ?? '.') === '.' ? '' : ($p_row['last_name'] ?? '')));
                $patient_phone = (string) ($p_row['phone'] ?? '');
                $patient_email = (string) ($p_row['email'] ?? '');
            }

            $demographics = assessment_demographics_normalize($_POST['demographics'] ?? []);
            $demographics['date'] = $demographics['date'] ?: $form_date;
            $demographics['client_name'] = $demographics['client_name'] ?: $patient_name;
            $demographics['contact_no'] = $demographics['contact_no'] ?: $patient_phone;
            $demographics['email_id'] = $demographics['email_id'] ?: $patient_email;
            $demographics['filled_by'] = $demographics['filled_by'] ?: $clinician_name;

            $json = json_encode($responses, JSON_UNESCAPED_UNICODE);
            $demographic_json = json_encode($demographics, JSON_UNESCAPED_UNICODE);
            $insert = $conn->prepare('INSERT INTO assessment_responses (patient_id, assignment_id, user_name, user_email, user_mobile, assessment_date, total_score, status_text, responses_data, demographic_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->bind_param('iissssisss', $form_patient_id, $assignment_id, $patient_name, $patient_email, $patient_phone, $form_date, $total, $status, $json, $demographic_json);
            if ($insert->execute()) {
                $update = $conn->prepare("UPDATE assigned_assessments SET status = 'completed' WHERE id = ? LIMIT 1");
                $update->bind_param('i', $assignment_id);
                $update->execute();
                $update->close();
                $_SESSION['bdi_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: assessment-report.php?assessment_id=1&patient_id=' . $form_patient_id . '&assignment_id=' . $assignment_id);
                exit;
            }
            $error_msg = 'The assessment could not be saved. Please try again.';
        }
    }
}

$total_questions = count($questions);
function bdi_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
require 'layout/base.php';
?>
<style>
    :root {
        --bdi-primary: #0f766e;
        --bdi-primary-dark: #115e59;
        --bdi-primary-soft: #f0fdfa;
        --bdi-primary-border: #99f6e4;
        --bdi-border: #e2e8f0;
        --bdi-border-soft: #f1f5f9;
        --bdi-text: #0f172a;
        --bdi-muted: #64748b;
        --bdi-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .bdi-page {
        max-width: 1160px;
        margin: 0 auto;
        padding: 24px 16px 56px;
        color: var(--bdi-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Executive Header */
    .bdi-header {
        background: #fff;
        border: 1px solid var(--bdi-border);
        border-top: 4px solid var(--bdi-primary);
        border-radius: 14px;
        padding: 22px 26px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
        box-shadow: var(--bdi-shadow);
    }

    .bdi-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--bdi-primary-soft);
        color: var(--bdi-primary);
        border: 1px solid var(--bdi-primary-border);
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        margin-bottom: 8px;
    }

    .bdi-header h1 {
        margin: 0;
        font-size: 23px;
        font-weight: 800;
        letter-spacing: -0.015em;
        color: var(--bdi-text);
    }

    .bdi-header p {
        margin: 4px 0 0;
        color: var(--bdi-muted);
        font-size: 13px;
    }

    .bdi-progress-pill {
        padding: 9px 16px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid var(--bdi-border);
        font-size: 13px;
        font-weight: 700;
        color: #334155;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .bdi-progress-pill strong {
        color: var(--bdi-primary);
    }

    /* Alert Banner */
    .bdi-error {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 13px 16px;
        border-radius: 10px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        color: #991b1b;
        font-size: 13.5px;
        font-weight: 600;
        margin-bottom: 18px;
    }

    /* Info Strip */
    .bdi-info-banner {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border: 1px solid var(--bdi-primary-border);
        border-left: 4px solid var(--bdi-primary);
        border-radius: 10px;
        background: var(--bdi-primary-soft);
        color: #115e59;
        font-size: 12.5px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    /* Standard Section Card */
    .bdi-card {
        background: #fff;
        border: 1px solid var(--bdi-border);
        border-radius: 14px;
        margin-bottom: 20px;
        box-shadow: var(--bdi-shadow);
        overflow: hidden;
    }

    .bdi-card-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--bdi-border);
        border-left: 4px solid var(--bdi-primary);
        padding: 13px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .bdi-card-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--bdi-text);
    }

    .bdi-card-badge {
        padding: 4px 11px;
        border-radius: 999px;
        background: var(--bdi-primary-soft);
        color: var(--bdi-primary);
        border: 1px solid var(--bdi-primary-border);
        font-size: 11.5px;
        font-weight: 800;
        white-space: nowrap;
    }

    .bdi-card-body {
        padding: 20px 22px;
    }

    /* Top Progress Bar */
    .bdi-progress-track {
        height: 4px;
        background: #e2e8f0;
        width: 100%;
    }

    .bdi-progress-fill {
        height: 100%;
        width: 0%;
        background: var(--bdi-primary);
        transition: width 0.25s ease;
    }

    /* Patient Grid */
    .bdi-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 12px;
    }

    .bdi-field label {
        display: block;
        margin-bottom: 6px;
        color: #334155;
        font-size: 12.5px;
        font-weight: 700;
    }

    .bdi-field .form-control,
    .bdi-field .form-select {
        min-height: 40px;
        border-color: #cbd5e1;
        border-radius: 8px;
        font-size: 13.5px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .bdi-field .form-control:focus,
    .bdi-field .form-select:focus {
        border-color: var(--bdi-primary);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14);
    }

    /* Question Cards */
    .bdi-question-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .bdi-question {
        border: 1px solid var(--bdi-border);
        border-radius: 12px;
        padding: 18px 20px;
        background: #fff;
        transition: border-color 0.16s ease, box-shadow 0.16s ease, background-color 0.16s ease;
    }

    .bdi-question.is-answered {
        border-color: #cbd5e1;
        background: #fcfefd;
    }

    .bdi-question.is-missing {
        border-color: #dc2626 !important;
        background: #fef2f2 !important;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
    }

    .bdi-q-head {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
    }

    .bdi-q-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 30px;
        height: 28px;
        padding: 0 8px;
        border-radius: 8px;
        background: #f1f5f9;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
        flex-shrink: 0;
        transition: all 0.16s ease;
    }

    .bdi-question.is-answered .bdi-q-num {
        background: var(--bdi-primary);
        color: #fff;
    }

    .bdi-q-title {
        margin: 2px 0 0;
        font-size: 14.5px;
        font-weight: 700;
        color: var(--bdi-text);
        line-height: 1.45;
    }

    /* Selectable Option Cards */
    .bdi-options {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .bdi-option {
        position: relative;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        border: 1.5px solid var(--bdi-border);
        border-radius: 10px;
        padding: 12px 14px;
        background: #fff;
        cursor: pointer;
        transition: all 0.15s ease;
        margin: 0;
    }

    .bdi-option:hover {
        border-color: #94a3b8;
        background: #f8fafc;
    }

    .bdi-option.is-selected {
        border-color: var(--bdi-primary);
        background: var(--bdi-primary-soft);
        box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.12);
    }

    .bdi-option-left {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        min-width: 0;
    }

    .bdi-option input[type="radio"] {
        margin-top: 3px;
        accent-color: var(--bdi-primary);
        width: 16px;
        height: 16px;
        flex-shrink: 0;
        cursor: pointer;
    }

    .bdi-option-text {
        font-size: 13px;
        font-weight: 500;
        color: #334155;
        line-height: 1.45;
    }

    .bdi-option.is-selected .bdi-option-text {
        color: var(--bdi-text);
        font-weight: 600;
    }

    .bdi-option-score {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        height: 22px;
        padding: 0 6px;
        border-radius: 6px;
        background: #f1f5f9;
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
        flex-shrink: 0;
    }

    .bdi-option.is-selected .bdi-option-score {
        background: var(--bdi-primary);
        color: #fff;
    }

    /* Form Actions */
    .bdi-form-footer {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        margin-top: 22px;
        padding-top: 18px;
        border-top: 1px solid var(--bdi-border-soft);
    }

    .bdi-submit {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--bdi-primary);
        color: #fff;
        border: 1px solid var(--bdi-primary);
        border-radius: 9px;
        padding: 11px 24px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(15, 118, 110, 0.24);
        transition: all 0.16s ease;
    }

    .bdi-submit:hover {
        background: var(--bdi-primary-dark);
        border-color: var(--bdi-primary-dark);
        transform: translateY(-1px);
    }

    /* Sticky Live Score Bar */
    .bdi-sticky-bar {
        position: sticky;
        bottom: 12px;
        z-index: 40;
        background: rgba(15, 23, 42, 0.96);
        backdrop-filter: blur(8px);
        color: #fff;
        border-radius: 12px;
        padding: 12px 20px;
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.28);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        margin-top: 12px;
    }

    .bdi-sticky-metrics {
        display: flex;
        align-items: center;
        gap: 18px;
        flex-wrap: wrap;
        font-size: 13px;
    }

    .bdi-sticky-metrics span {
        color: #94a3b8;
    }

    .bdi-sticky-metrics strong {
        color: #5eead4;
        font-weight: 800;
        margin-left: 5px;
    }

    .bdi-sticky-status {
        background: var(--bdi-primary);
        color: #fff;
        padding: 5px 13px;
        border-radius: 8px;
        font-size: 12.5px;
        font-weight: 800;
    }

    @media (max-width: 900px) {
        .bdi-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 720px) {
        .bdi-page {
            padding: 16px 12px 40px;
        }
        .bdi-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .bdi-options {
            grid-template-columns: 1fr;
        }
        .bdi-question {
            padding: 14px 14px;
        }
    }
</style>

<div class="bdi-page">
    <!-- Header Banner -->
    <header class="bdi-header">
        <div>
            <div class="bdi-eyebrow">
                <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i> Clinical Psychiatric Evaluation
            </div>
            <h1>Beck Depression Inventory (BDI)</h1>
            <p>Select the statement in each group that best describes how the patient has been feeling over the past two weeks.</p>
        </div>
        <div class="bdi-progress-pill" aria-live="polite">
            Answered: <strong id="bdiAnsweredTop">0</strong> / <?php echo $total_questions; ?>
        </div>
    </header>

    <?php if ($error_msg): ?>
        <div class="bdi-error" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span><?php echo bdi_escape($error_msg); ?></span>
        </div>
    <?php endif; ?>

    <form method="post" id="bdiForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo bdi_escape($_SESSION['bdi_csrf_token']); ?>">

        <!-- Patient & Demographic Details -->
        <section class="bdi-card">
            <div class="bdi-card-head">
                <h2>Patient &amp; Assessment Details</h2>
            </div>
            <div class="bdi-card-body">
                <div class="bdi-grid">
                    <div class="bdi-field">
                        <label for="patient_id">Client Name <span class="text-danger">*</span></label>
                        <select name="patient_id" id="patient_id" class="form-select" required>
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($patients as $patient):
                                $name = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')) ?: ($patient['email'] ?? 'Patient');
                            ?>
                                <option value="<?php echo (int) $patient['id']; ?>" <?php echo $form_patient_id == (int) $patient['id'] ? 'selected' : ''; ?>>
                                    <?php echo bdi_escape($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bdi-field">
                        <label for="assessment_date">Assessment Date <span class="text-danger">*</span></label>
                        <input type="date" name="assessment_date" id="assessment_date" class="form-control" value="<?php echo bdi_escape($form_date); ?>" required>
                    </div>
                    <div class="bdi-field">
                        <label for="clinician_name">Evaluating Clinician</label>
                        <input type="text" id="clinician_name" class="form-control bg-light" value="<?php echo bdi_escape($clinician_name); ?>" readonly>
                    </div>
                </div>
                <?php
                $demographics['date'] = $demographics['date'] ?: $form_date;
                $demographics['filled_by'] = $demographics['filled_by'] ?: $clinician_name;
                echo assessment_demographics_html('demographics', $demographics, ['hide_fields' => ['date', 'client_name']]);
                ?>
            </div>
        </section>

        <div class="bdi-info-banner">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <span>Each item is scored from 0 to 3. Please ensure all <?php echo $total_questions; ?> items are answered before submitting.</span>
        </div>

        <!-- Questions Card -->
        <section class="bdi-card">
            <div class="bdi-card-head">
                <h2>BDI Questionnaire (<?php echo $total_questions; ?> Items)</h2>
                <span class="bdi-card-badge">Scale: 0 – 3 per item</span>
            </div>
            <div class="bdi-progress-track">
                <div class="bdi-progress-fill" id="bdiProgressFill"></div>
            </div>
            <div class="bdi-card-body">
                <div class="bdi-question-list">
                    <?php $counter = 1; foreach ($questions as $question):
                        $q_key = 'q_' . (int) $question['id'];
                        $posted_val = isset($_POST[$q_key]) ? (string) $_POST[$q_key] : '';
                    ?>
                        <div class="bdi-question<?php echo $posted_val !== '' ? ' is-answered' : ''; ?>" data-question-id="<?php echo (int) $question['id']; ?>">
                            <div class="bdi-q-head">
                                <span class="bdi-q-num"><?php echo str_pad((string) $counter++, 2, '0', STR_PAD_LEFT); ?></span>
                                <p class="bdi-q-title"><?php echo bdi_escape($question['question_text']); ?></p>
                            </div>
                            <div class="bdi-options">
                                <?php foreach ($question['options'] as $option):
                                    $opt_val = (string) (int) $option['score_value'];
                                    $is_checked = ($posted_val !== '' && $posted_val === $opt_val);
                                ?>
                                    <label class="bdi-option<?php echo $is_checked ? ' is-selected' : ''; ?>">
                                        <span class="bdi-option-left">
                                            <input type="radio" class="bdi-radio" name="<?php echo $q_key; ?>" value="<?php echo (int) $option['score_value']; ?>" <?php echo $is_checked ? 'checked' : ''; ?> required>
                                            <span class="bdi-option-text"><?php echo bdi_escape($option['option_text']); ?></span>
                                        </span>
                                        <span class="bdi-option-score"><?php echo (int) $option['score_value']; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="bdi-form-footer">
                    <button type="submit" class="bdi-submit">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Submit BDI Assessment
                    </button>
                </div>
            </div>
        </section>

        <!-- Sticky Live Score Bar -->
        <div class="bdi-sticky-bar" aria-live="polite">
            <div class="bdi-sticky-metrics">
                <div><span>Answered:</span><strong id="bdiAnsweredSticky">0</strong> / <?php echo $total_questions; ?></div>
                <div><span>Total Score:</span><strong id="bdiLiveScore">0</strong> / 63</div>
            </div>
            <div class="bdi-sticky-status" id="bdiLiveStatus">Normal</div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('bdiForm');
    var questionCards = document.querySelectorAll('.bdi-question');
    var totalQuestions = questionCards.length;
    var answeredTop = document.getElementById('bdiAnsweredTop');
    var answeredSticky = document.getElementById('bdiAnsweredSticky');
    var progressFill = document.getElementById('bdiProgressFill');
    var liveScoreEl = document.getElementById('bdiLiveScore');
    var liveStatusEl = document.getElementById('bdiLiveStatus');

    function getBdiStatus(score) {
        if (score <= 10) return 'Normal';
        if (score <= 16) return 'Mild';
        if (score <= 20) return 'Borderline';
        if (score <= 30) return 'Moderate';
        if (score <= 40) return 'Severe';
        return 'Extremely Severe';
    }

    function updateBdiState() {
        var answered = 0;
        var totalScore = 0;

        questionCards.forEach(function (card) {
            var checked = card.querySelector('.bdi-radio:checked');
            card.querySelectorAll('.bdi-option').forEach(function (opt) {
                var input = opt.querySelector('.bdi-radio');
                opt.classList.toggle('is-selected', !!(input && input.checked));
            });

            if (checked) {
                answered++;
                totalScore += Number(checked.value) || 0;
                card.classList.add('is-answered');
                card.classList.remove('is-missing');
            } else {
                card.classList.remove('is-answered');
            }
        });

        if (answeredTop) answeredTop.textContent = String(answered);
        if (answeredSticky) answeredSticky.textContent = String(answered);
        if (liveScoreEl) liveScoreEl.textContent = String(totalScore);
        if (liveStatusEl) liveStatusEl.textContent = getBdiStatus(totalScore);
        if (progressFill && totalQuestions > 0) {
            progressFill.style.width = Math.round((answered / totalQuestions) * 100) + '%';
        }
    }

    document.querySelectorAll('.bdi-radio').forEach(function (radio) {
        radio.addEventListener('change', updateBdiState);
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            var patientSelect = document.getElementById('patient_id');
            if (patientSelect && patientSelect.value === '') {
                e.preventDefault();
                patientSelect.focus();
                patientSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            var firstMissing = null;
            questionCards.forEach(function (card) {
                var checked = card.querySelector('.bdi-radio:checked');
                if (!checked) {
                    card.classList.add('is-missing');
                    if (!firstMissing) firstMissing = card;
                } else {
                    card.classList.remove('is-missing');
                }
            });

            if (firstMissing) {
                e.preventDefault();
                firstMissing.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }

    updateBdiState();
});
</script>
<?php require 'layout/footer.php'; ob_end_flush(); ?>