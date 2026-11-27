<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';
require 'panss-assessment-items.php';
require 'panss-assessment-schema.php';
require 'assessment-demographics.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'PANSS Assessment - Vimhans Academic';
$patients = [];
$success_msg = '';
$error_msg = '';
$field_errors = [];
$score_errors = [];
$submitted_scores = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
$patient_input = $_POST['patient_id'] ?? '';
$form_patient_id = is_string($patient_input) || is_int($patient_input)
    ? filter_var($patient_input, FILTER_VALIDATE_INT)
    : false;
$form_patient_id = $form_patient_id === false ? 0 : (int) $form_patient_id;
$date_input = $_POST['assessment_date'] ?? date('Y-m-d');
$form_date = is_string($date_input) ? $date_input : date('Y-m-d');
$notes_input = $_POST['clinical_notes'] ?? '';
$form_notes = is_string($notes_input) ? $notes_input : '';
$clinician_name = trim((string) ($_SESSION['name'] ?? ''));
$demographics = assessment_demographics_normalize($_POST['demographics'] ?? []);
$demographics['date'] = $demographics['date'] ?: $form_date;

if (!isset($_SESSION['panss_csrf_token'])) {
    $_SESSION['panss_csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_SESSION['panss_form_success'])) {
    $success_msg = (string) $_SESSION['panss_form_success'];
    unset($_SESSION['panss_form_success']);
}

$schema_ready = $panss_schema_ready;
if (!$schema_ready) {
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
        error_log('PANSS patient list query failed: ' . $conn->error);
        $error_msg = 'The patient list could not be loaded. Please contact the system administrator.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_panss']) && $schema_ready) {
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!is_string($posted_token) || !hash_equals($_SESSION['panss_csrf_token'], $posted_token)) {
        $error_msg = 'Your form session expired. Reload the page and submit the assessment again.';
    } elseif ($form_patient_id < 1) {
        $error_msg = 'Please select a valid patient.';
        $field_errors['patient_id'] = 'Please select a valid patient.';
    }

    if ($error_msg === '') {
        if (!is_string($date_input) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_input)) {
            $error_msg = 'Please enter a valid assessment date.';
            $field_errors['assessment_date'] = $error_msg;
        } else {
            [$year, $month, $day] = array_map('intval', explode('-', $date_input));
            if (!checkdate($month, $day, $year)) {
                $error_msg = 'Please enter a valid assessment date.';
                $field_errors['assessment_date'] = $error_msg;
            }
        }
    }

    if ($error_msg === '' && !is_string($notes_input)) {
        $error_msg = 'Please enter valid clinical notes.';
        $field_errors['clinical_notes'] = $error_msg;
    } elseif ($error_msg === '' && strlen($form_notes) > 5000) {
        $error_msg = 'Clinical notes must be 5,000 characters or fewer.';
        $field_errors['clinical_notes'] = $error_msg;
    }

    $scores = [];
    $section_totals = ['P' => 0, 'N' => 0, 'G' => 0];
    if (!is_array($_POST['scores'] ?? null)) {
        $submitted_scores = [];
    }
    foreach ($panss_items as $prefix => $items) {
        foreach ($items as $code => $label) {
            $raw_score = $submitted_scores[$code] ?? null;
            $score = is_string($raw_score) || is_int($raw_score)
                ? filter_var($raw_score, FILTER_VALIDATE_INT)
                : false;
            if ($score === false || $score === null || $score < 1 || $score > 7) {
                $score_errors[$code] = 'Please select a rating for this item.';
                continue;
            }
            $scores[$code] = $score;
            $section_totals[$prefix] += $score;
        }
    }
    if ($error_msg === '' && !empty($score_errors)) {
        $error_msg = 'Please rate every item from 1 to 7.';
    }

    if ($error_msg === '' && empty($score_errors)) {
        $patient_stmt = $conn->prepare(
            'SELECT id FROM users
             WHERE id = ? AND role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . '
             LIMIT 1'
        );
        if (!$patient_stmt) {
            error_log('PANSS patient validation prepare failed: ' . $conn->error);
            $error_msg = 'The selected patient could not be validated. Please try again.';
        } else {
            $patient_stmt->bind_param('i', $form_patient_id);
            $patient_stmt->execute();
            $patient_stmt->store_result();
            $patient_exists = $patient_stmt->num_rows === 1;
            $patient_stmt->close();
            if (!$patient_exists) {
                $error_msg = 'The selected patient could not be found.';
                $field_errors['patient_id'] = $error_msg;
            }
        }
    }

    if ($error_msg === '') {
        $positive_score = $section_totals['P'];
        $negative_score = $section_totals['N'];
        $general_score = $section_totals['G'];
        $composite_score = $positive_score - $negative_score;
        $score_data = json_encode($scores);
        $clinician_id = (int) ($_SESSION['user_id'] ?? 0);
        $demographic_json = json_encode($demographics, JSON_UNESCAPED_UNICODE);
        $insert_stmt = $conn->prepare(
            'INSERT INTO panss_assessments
                (patient_id, clinician_id, assessment_date, positive_score, negative_score,
                 general_score, composite_score, score_data, clinical_notes, demographic_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insert_stmt) {
            error_log('PANSS insert prepare failed: ' . $conn->error);
            $error_msg = 'The assessment could not be saved. Please try again.';
        } else {
            $insert_stmt->bind_param(
                'iisiiiisss',
                $form_patient_id,
                $clinician_id,
                $form_date,
                $positive_score,
                $negative_score,
                $general_score,
                $composite_score,
                $score_data,
                $form_notes,
                $demographic_json
            );
            if ($insert_stmt->execute()) {
                $saved_record_id = (int) $insert_stmt->insert_id;
                $insert_stmt->close();
                $_SESSION['panss_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: panss-assessment-score.php?id=' . $saved_record_id);
                exit;
            }
            error_log('PANSS insert failed: ' . $insert_stmt->error);
            $insert_stmt->close();
            $error_msg = 'The assessment could not be saved. Please try again.';
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success_msg = $success_msg !== '' ? $success_msg : 'Assessment saved successfully.';
}

function panss_form_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

require 'layout/base.php';
?>
<style>
    :root {
        --pf-primary: #0f766e;
        --pf-primary-dark: #115e59;
        --pf-primary-soft: #f0fdfa;
        --pf-primary-border: #99f6e4;
        --pf-border: #e2e8f0;
        --pf-border-soft: #f1f5f9;
        --pf-text: #0f172a;
        --pf-muted: #64748b;
        --pf-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .panss-page {
        max-width: 1160px;
        margin: 0 auto;
        padding: 24px 16px 48px;
        color: var(--pf-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    .panss-page [hidden] {
        display: none !important;
    }

    /* Header Banner */
    .panss-header {
        background: #fff;
        border: 1px solid var(--pf-border);
        border-top: 4px solid var(--pf-primary);
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
        box-shadow: var(--pf-shadow);
    }

    .panss-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--pf-primary-soft);
        color: var(--pf-primary);
        border: 1px solid var(--pf-primary-border);
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        margin-bottom: 8px;
    }

    .panss-header h1 {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        letter-spacing: -0.01em;
        color: var(--pf-text);
    }

    .panss-header p {
        margin: 4px 0 0;
        color: var(--pf-muted);
        font-size: 13px;
    }

    .panss-header-right {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .panss-progress-pill {
        padding: 8px 14px;
        border-radius: 9px;
        background: #f8fafc;
        border: 1px solid var(--pf-border);
        font-size: 12.5px;
        font-weight: 700;
        color: #334155;
        white-space: nowrap;
    }

    .panss-progress-pill strong {
        color: var(--pf-primary);
    }

    .panss-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 15px;
        border: 1px solid #cbd5e1;
        border-radius: 9px;
        background: #fff;
        color: #334155;
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
        transition: all 0.16s ease;
        white-space: nowrap;
    }

    .panss-link:hover {
        background: var(--pf-primary);
        border-color: var(--pf-primary);
        color: #fff;
    }

    /* Toast */
    .panss-toast {
        position: fixed;
        top: 18px;
        right: 18px;
        z-index: 1100;
        display: flex;
        width: min(380px, calc(100vw - 36px));
        align-items: center;
        gap: 12px;
        overflow: hidden;
        padding: 13px 16px;
        border-radius: 10px;
        color: #fff;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.22);
        opacity: 1;
        transform: translateY(0);
        transition: opacity 0.25s ease, transform 0.25s ease;
    }

    .panss-toast-success { background: var(--pf-primary); }
    .panss-toast-error { background: #dc2626; }
    .panss-toast.is-hiding { opacity: 0; transform: translateY(-8px); }

    .panss-toast-icon {
        display: grid;
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        place-items: center;
        border: 2px solid rgba(255, 255, 255, 0.85);
        border-radius: 50%;
        font-size: 15px;
    }

    .panss-toast-copy { min-width: 0; }
    .panss-toast-title { margin-right: 6px; font-size: 14px; font-weight: 800; }
    .panss-toast-message { font-size: 12.5px; }

    .panss-toast-progress {
        position: absolute;
        right: 0;
        bottom: 0;
        left: 0;
        height: 3px;
        background: rgba(255, 255, 255, 0.4);
        transform: scaleX(0);
        transform-origin: left;
        animation: panss-toast-progress 3s linear forwards;
    }

    @keyframes panss-toast-progress {
        to { transform: scaleX(1); }
    }

    /* Section Cards */
    .panss-card {
        background: #fff;
        border: 1px solid var(--pf-border);
        border-radius: 14px;
        margin-bottom: 20px;
        box-shadow: var(--pf-shadow);
        overflow: hidden;
    }

    .panss-card-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--pf-border);
        border-left: 4px solid var(--pf-primary);
        padding: 13px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .panss-card-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--pf-text);
    }

    .panss-card-body {
        padding: 20px 22px;
    }

    .panss-range {
        padding: 4px 11px;
        border-radius: 999px;
        background: var(--pf-primary-soft);
        color: var(--pf-primary);
        border: 1px solid var(--pf-primary-border);
        font-size: 11.5px;
        font-weight: 800;
        white-space: nowrap;
    }

    /* Meta Form Grid */
    .panss-meta {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 10px;
    }

    .panss-field label {
        display: block;
        margin-bottom: 6px;
        color: #334155;
        font-size: 12.5px;
        font-weight: 700;
    }

    .panss-field .form-control,
    .panss-field .form-select {
        min-height: 40px;
        border-color: #cbd5e1;
        border-radius: 8px;
        font-size: 13.5px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .panss-field .form-control:focus,
    .panss-field .form-select:focus {
        border-color: var(--pf-primary);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14);
    }

    .panss-field.has-error .form-control,
    .panss-field.has-error .form-select,
    .panss-rating.has-error {
        border-color: #dc2626 !important;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.14) !important;
    }

    .panss-field-error,
    .panss-item-error {
        display: block;
        margin-top: 5px;
        color: #dc2626;
        font-size: 11.5px;
        font-weight: 700;
    }

    /* Info Banner */
    .panss-note {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border: 1px solid var(--pf-primary-border);
        border-left: 4px solid var(--pf-primary);
        border-radius: 10px;
        background: var(--pf-primary-soft);
        color: #115e59;
        font-size: 12.5px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    /* 4-Step Interactive Stepper */
    .panss-tabs {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 20px;
    }

    .panss-tab-btn {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
        border: 1.5px solid var(--pf-border);
        border-radius: 12px;
        background: #fff;
        color: #475569;
        text-align: left;
        cursor: pointer;
        transition: all 0.16s ease;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    }

    .panss-tab-btn:hover:not(:disabled) {
        border-color: #94a3b8;
        transform: translateY(-1px);
    }

    .panss-tab-btn.active {
        border-color: var(--pf-primary);
        background: var(--pf-primary-soft);
        color: var(--pf-text);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1);
    }

    .panss-tab-btn:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        background: #f8fafc;
    }

    .step-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: #f1f5f9;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
        flex-shrink: 0;
    }

    .panss-tab-btn.active .step-num,
    .panss-tab-btn.is-done .step-num {
        background: var(--pf-primary);
        color: #fff;
    }

    .step-copy {
        min-width: 0;
        display: flex;
        flex-direction: column;
    }

    .step-title {
        font-size: 13px;
        font-weight: 800;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .step-sub {
        font-size: 11px;
        font-weight: 600;
        color: var(--pf-muted);
        margin-top: 2px;
    }

    /* Section Progress Strip */
    .section-progress-track {
        height: 4px;
        background: #e2e8f0;
        width: 100%;
    }

    .section-progress-fill {
        height: 100%;
        width: 0%;
        background: var(--pf-primary);
        transition: width 0.25s ease;
    }

    /* Rating Table */
    .panss-table {
        width: 100%;
        border-collapse: collapse;
    }

    .panss-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        padding: 12px 18px;
        border-bottom: 1px solid var(--pf-border);
        text-align: left;
    }

    .panss-table tbody tr {
        transition: background-color 0.15s ease;
    }

    .panss-table tbody tr:hover {
        background: #f8fafc;
    }

    .panss-table tbody tr.is-rated {
        background: #fcfefd;
    }

    .panss-table tbody td {
        padding: 12px 18px;
        border-bottom: 1px solid var(--pf-border-soft);
        font-size: 13.5px;
        vertical-align: middle;
    }

    .panss-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .panss-item-row.has-error {
        background: #fef2f2;
    }

    .panss-item-code {
        display: inline-flex;
        min-width: 38px;
        justify-content: center;
        padding: 4px 8px;
        border-radius: 7px;
        background: #f1f5f9;
        color: #334155;
        font-weight: 800;
        font-size: 12px;
    }

    tr.is-rated .panss-item-code {
        background: var(--pf-primary);
        color: #fff;
    }

    .panss-table select {
        width: 100%;
        max-width: 240px;
        height: 38px;
        padding: 6px 32px 6px 12px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 8px;
        border: 1.5px solid #cbd5e1;
        background-color: #fff;
        transition: all 0.15s ease;
    }

    .panss-table select:focus {
        border-color: var(--pf-primary);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14);
    }

    tr.is-rated select {
        border-color: #5eead4;
        background-color: var(--pf-primary-soft);
        color: #0f766e;
    }

    /* Summary KPI Cards */
    .panss-score-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    .panss-score-chip {
        padding: 16px 18px;
        border: 1px solid var(--pf-border);
        border-top: 3px solid var(--chip-accent, var(--pf-primary));
        border-radius: 12px;
        background: #f8fafc;
    }

    .panss-score-chip span {
        display: block;
        color: var(--pf-muted);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .panss-score-chip strong {
        display: block;
        margin-top: 6px;
        color: var(--pf-text);
        font-size: 26px;
        font-weight: 800;
        line-height: 1;
    }

    .panss-score-chip small {
        display: block;
        margin-top: 5px;
        font-size: 11px;
        color: var(--pf-muted);
    }

    /* Step Actions */
    .panss-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-top: 16px;
        margin-bottom: 20px;
    }

    .panss-btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: var(--pf-primary);
        border: 1px solid var(--pf-primary);
        color: #fff;
        padding: 10px 22px;
        font-weight: 700;
        font-size: 13.5px;
        border-radius: 9px;
        cursor: pointer;
        transition: all 0.16s ease;
    }

    .panss-btn-primary:hover:not(:disabled) {
        background: var(--pf-primary-dark);
        border-color: var(--pf-primary-dark);
        transform: translateY(-1px);
    }

    .panss-btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: #fff;
        border: 1px solid #cbd5e1;
        color: #334155;
        padding: 10px 20px;
        font-weight: 700;
        font-size: 13.5px;
        border-radius: 9px;
        cursor: pointer;
        transition: all 0.16s ease;
    }

    .panss-btn-secondary:hover {
        background: #f8fafc;
        border-color: #94a3b8;
    }

    /* Sticky Live Score Bar */
    .panss-sticky-bar {
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
        margin-top: 10px;
    }

    .panss-sticky-scores {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        font-size: 12.5px;
    }

    .panss-sticky-scores span {
        color: #94a3b8;
    }

    .panss-sticky-scores strong {
        color: #5eead4;
        font-weight: 800;
        margin-left: 4px;
    }

    .panss-sticky-comp {
        background: var(--pf-primary);
        color: #fff;
        padding: 5px 12px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 800;
    }

    .notes-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }

    .notes-counter {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--pf-muted);
    }

    @media (max-width: 960px) {
        .panss-tabs,
        .panss-score-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .panss-meta {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 700px) {
        .panss-page { padding: 16px 12px 32px; }
        .panss-header { flex-direction: column; align-items: flex-start; }
        .panss-header-right { width: 100%; justify-content: space-between; }
        .panss-table thead th,
        .panss-table tbody td { padding: 10px 10px; }
        .panss-table select { max-width: 100%; }
    }

    @media (max-width: 520px) {
        .panss-tabs,
        .panss-score-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="panss-page">
    <!-- Header Banner -->
    <header class="panss-header">
        <div>
            <div class="panss-eyebrow">
                <i class="fa-solid fa-notes-medical" aria-hidden="true"></i> Psychiatric Clinical Evaluation
            </div>
            <h1>PANSS Assessment Module</h1>
            <p>Rate all 30 coded items across Positive, Negative, and General Psychopathology scales.</p>
        </div>
        <div class="panss-header-right">
            <div class="panss-progress-pill" aria-live="polite">
                Progress: <strong id="overallRatedCount">0</strong> / 30
            </div>
            <a class="panss-link" href="panss-assessment-records.php">
                <i class="bi bi-table" aria-hidden="true"></i> View Records
            </a>
        </div>
    </header>

    <?php if ($success_msg !== ''): ?>
        <div class="panss-toast panss-toast-success" id="panssSuccessToast" role="status" aria-live="polite">
            <span class="panss-toast-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
            <span class="panss-toast-copy"><strong class="panss-toast-title">Success!</strong><span class="panss-toast-message"><?php echo panss_form_escape($success_msg); ?></span></span>
            <span class="panss-toast-progress" aria-hidden="true"></span>
        </div>
    <?php endif; ?>

    <div class="panss-toast panss-toast-error" id="panssJsErrorToast" role="alert" aria-live="assertive" style="display: none;">
        <span class="panss-toast-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <span class="panss-toast-copy"><strong class="panss-toast-title">Error!</strong><span class="panss-toast-message" id="panssJsErrorMessage"></span></span>
        <span class="panss-toast-progress" id="panssJsErrorProgress" aria-hidden="true"></span>
    </div>

    <?php if ($error_msg !== ''): ?>
        <div class="panss-toast panss-toast-error" id="panssErrorToast" role="alert" aria-live="assertive">
            <span class="panss-toast-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <span class="panss-toast-copy"><strong class="panss-toast-title">Error!</strong><span class="panss-toast-message"><?php echo panss_form_escape($error_msg); ?></span></span>
            <span class="panss-toast-progress" aria-hidden="true"></span>
        </div>
    <?php endif; ?>

    <form method="post" action="panss-assessments.php" id="panssForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo panss_form_escape($_SESSION['panss_csrf_token']); ?>">

        <!-- Patient & Assessment Details -->
        <section class="panss-card" id="topDetailsPanel">
            <div class="panss-card-head">
                <h2>Patient &amp; Assessment Details</h2>
            </div>
            <div class="panss-card-body">
                <div class="panss-meta">
                    <div class="panss-field<?php echo isset($field_errors['patient_id']) ? ' has-error' : ''; ?>" id="patient_idField">
                        <label for="patient_id">Client Name <span class="text-danger">*</span></label>
                        <select class="form-select" id="patient_id" name="patient_id" required aria-describedby="error_patient_id">
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($patients as $patient): ?>
                                <?php
                                $patient_name = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
                                $patient_name = $patient_name !== '' ? $patient_name : ($patient['email'] ?? 'Patient');
                                ?>
                                <option value="<?php echo (int) $patient['id']; ?>" <?php echo $form_patient_id === (int) $patient['id'] ? 'selected' : ''; ?>>
                                    <?php echo panss_form_escape($patient_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="panss-field-error" id="error_patient_id" role="alert" <?php echo isset($field_errors['patient_id']) ? '' : 'hidden'; ?>><?php echo panss_form_escape($field_errors['patient_id'] ?? ''); ?></small>
                    </div>
                    <div class="panss-field<?php echo isset($field_errors['assessment_date']) ? ' has-error' : ''; ?>" id="assessment_dateField">
                        <label for="assessment_date">Assessment Date <span class="text-danger">*</span></label>
                        <input class="form-control" type="date" id="assessment_date" name="assessment_date" value="<?php echo panss_form_escape($form_date); ?>" required aria-describedby="error_assessment_date">
                        <small class="panss-field-error" id="error_assessment_date" role="alert" <?php echo isset($field_errors['assessment_date']) ? '' : 'hidden'; ?>><?php echo panss_form_escape($field_errors['assessment_date'] ?? ''); ?></small>
                    </div>
                    <div class="panss-field">
                        <label for="clinician_name">Evaluating Clinician</label>
                        <input class="form-control bg-light" type="text" id="clinician_name" value="<?php echo panss_form_escape($clinician_name); ?>" readonly>
                    </div>
                </div>

                <?php echo assessment_demographics_html('demographics', $demographics, ['hide_fields' => ['date', 'client_name']]); ?>
            </div>
        </section>

        <div class="panss-note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>Item names reflect standard clinical terminology for rating criteria. Rate each item from 1 (Absent) to 7 (Extreme).</span>
        </div>

        <!-- 4-Step Navigation Stepper -->
        <div class="panss-tabs" role="tablist">
            <button type="button" class="panss-tab-btn active" data-tab="tab-P" role="tab" aria-selected="true">
                <span class="step-num">01</span>
                <span class="step-copy">
                    <span class="step-title">Positive Scale</span>
                    <span class="step-sub" id="stepSubP">0 / 7 rated</span>
                </span>
            </button>
            <button type="button" class="panss-tab-btn" data-tab="tab-N" role="tab" aria-selected="false" disabled>
                <span class="step-num">02</span>
                <span class="step-copy">
                    <span class="step-title">Negative Scale</span>
                    <span class="step-sub" id="stepSubN">0 / 7 rated</span>
                </span>
            </button>
            <button type="button" class="panss-tab-btn" data-tab="tab-G" role="tab" aria-selected="false" disabled>
                <span class="step-num">03</span>
                <span class="step-copy">
                    <span class="step-title">General Scale</span>
                    <span class="step-sub" id="stepSubG">0 / 16 rated</span>
                </span>
            </button>
            <button type="button" class="panss-tab-btn" data-tab="tab-summary" role="tab" aria-selected="false" disabled>
                <span class="step-num">04</span>
                <span class="step-copy">
                    <span class="step-title">Summary &amp; Save</span>
                    <span class="step-sub">Review &amp; submit</span>
                </span>
            </button>
        </div>

        <!-- Tab Content Sections -->
        <?php foreach ($panss_sections as $prefix => $section): ?>
            <?php
            $minimum = $section['count'];
            $maximum = $section['count'] * 7;
            $is_first = ($prefix === 'P');
            ?>
            <div class="panss-tab-pane" id="tab-<?php echo panss_form_escape($prefix); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
                <section class="panss-card" aria-labelledby="section-<?php echo panss_form_escape($prefix); ?>">
                    <div class="panss-card-head">
                        <h2 id="section-<?php echo panss_form_escape($prefix); ?>"><?php echo panss_form_escape($section['title']); ?> (<?php echo (int) $section['count']; ?> Items)</h2>
                        <span class="panss-range">Range: <?php echo $minimum; ?>–<?php echo $maximum; ?></span>
                    </div>
                    <div class="section-progress-track">
                        <div class="section-progress-fill" id="progressFill_<?php echo panss_form_escape($prefix); ?>"></div>
                    </div>
                    <div class="table-responsive">
                        <table class="panss-table">
                            <thead>
                                <tr>
                                    <th style="width: 90px;">Code</th>
                                    <th>Symptom / Item Description</th>
                                    <th style="width: 270px;">Severity Rating (1–7)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($panss_items[$prefix] as $code => $label): ?>
                                    <?php
                                    $raw_score = $submitted_scores[$code] ?? '';
                                    $selected_score = is_string($raw_score) || is_int($raw_score) ? (string) $raw_score : '';
                                    ?>
                                    <tr class="<?php echo $selected_score !== '' ? 'is-rated' : ''; ?>">
                                        <td><span class="panss-item-code"><?php echo panss_form_escape($code); ?></span></td>
                                        <td class="fw-semibold text-dark"><?php echo panss_form_escape($label); ?></td>
                                        <td class="panss-item-row<?php echo isset($score_errors[$code]) ? ' has-error' : ''; ?>" id="row_<?php echo panss_form_escape($code); ?>">
                                            <select class="form-select form-select-sm panss-rating<?php echo isset($score_errors[$code]) ? ' has-error' : ''; ?>" name="scores[<?php echo panss_form_escape($code); ?>]" data-code="<?php echo panss_form_escape($code); ?>" data-section="<?php echo panss_form_escape($prefix); ?>" required aria-label="Rating for <?php echo panss_form_escape($code); ?>" aria-describedby="error_<?php echo panss_form_escape($code); ?>">
                                                <option value="">Select rating...</option>
                                                <?php foreach ($panss_rating_labels as $rating => $rating_label): ?>
                                                    <option value="<?php echo $rating; ?>" <?php echo $selected_score === (string) $rating ? 'selected' : ''; ?>><?php echo $rating . ' — ' . panss_form_escape($rating_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (isset($score_errors[$code])): ?>
                                                <small class="panss-item-error" id="error_<?php echo panss_form_escape($code); ?>" role="alert"><?php echo panss_form_escape($score_errors[$code]); ?></small>
                                            <?php else: ?>
                                                <small class="panss-item-error" id="error_<?php echo panss_form_escape($code); ?>" role="alert" hidden></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="panss-actions">
                    <?php if ($prefix !== 'P'): ?>
                        <button type="button" class="panss-btn-secondary panss-prev-btn">&larr; Previous Step</button>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>
                    <button type="button" class="panss-btn-primary panss-next-btn">Next Step &rarr;</button>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Tab 4: Summary & Submit -->
        <div class="panss-tab-pane" id="tab-summary" hidden>
            <section class="panss-card">
                <div class="panss-card-head">
                    <h2>Score Summary</h2>
                    <span class="panss-range">Auto-Calculated</span>
                </div>
                <div class="panss-card-body">
                    <div class="panss-score-grid">
                        <div class="panss-score-chip" style="--chip-accent: #4f46e5;">
                            <span>Positive Scale</span>
                            <strong id="positiveTotal">0</strong>
                            <small>Range: 7 – 49</small>
                        </div>
                        <div class="panss-score-chip" style="--chip-accent: #0ea5e9;">
                            <span>Negative Scale</span>
                            <strong id="negativeTotal">0</strong>
                            <small>Range: 7 – 49</small>
                        </div>
                        <div class="panss-score-chip" style="--chip-accent: #0f766e;">
                            <span>General Psychopathology</span>
                            <strong id="generalTotal">0</strong>
                            <small>Range: 16 – 112</small>
                        </div>
                        <div class="panss-score-chip" style="--chip-accent: #10b981;">
                            <span>Composite Score (P − N)</span>
                            <strong id="compositeTotal">0</strong>
                            <small>Range: −42 to +42</small>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panss-card">
                <div class="panss-card-head">
                    <h2>Clinical Observations &amp; Notes</h2>
                </div>
                <div class="panss-card-body">
                    <div class="panss-field<?php echo isset($field_errors['clinical_notes']) ? ' has-error' : ''; ?>" id="clinical_notesField">
                        <div class="notes-head">
                            <label for="clinical_notes" class="mb-0">Examiner Notes <span class="text-muted fw-normal">(Optional)</span></label>
                            <span class="notes-counter"><span id="notesCharCount">0</span> / 5,000</span>
                        </div>
                        <textarea class="form-control" id="clinical_notes" name="clinical_notes" rows="4" maxlength="5000" placeholder="Enter behavioral observations or additional clinical context..." aria-describedby="error_clinical_notes"><?php echo panss_form_escape($form_notes); ?></textarea>
                        <small class="panss-field-error" id="error_clinical_notes" role="alert" <?php echo isset($field_errors['clinical_notes']) ? '' : 'hidden'; ?>><?php echo panss_form_escape($field_errors['clinical_notes'] ?? ''); ?></small>
                    </div>
                </div>
            </section>

            <div class="panss-actions">
                <button type="button" class="panss-btn-secondary panss-prev-btn">&larr; Previous Step</button>
                <button class="panss-btn-primary" type="submit" name="submit_panss" value="1" <?php echo empty($patients) || !$schema_ready ? 'disabled' : ''; ?>>
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Save PANSS Assessment
                </button>
            </div>
        </div>

        <!-- Sticky Live Score Footer -->
        <div class="panss-sticky-bar" aria-live="polite">
            <div class="panss-sticky-scores">
                <div><span>Positive:</span><strong id="stickyP">0</strong>/49</div>
                <div><span>Negative:</span><strong id="stickyN">0</strong>/49</div>
                <div><span>General:</span><strong id="stickyG">0</strong>/112</div>
            </div>
            <div class="panss-sticky-comp">Composite: <span id="stickyComp">0</span></div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var successToast = document.getElementById('panssSuccessToast');
    var errorToast = document.getElementById('panssErrorToast');
    var jsErrorToast = document.getElementById('panssJsErrorToast');
    var jsErrorMessage = document.getElementById('panssJsErrorMessage');
    var jsErrorProgress = document.getElementById('panssJsErrorProgress');
    var toastTimer = null;
    var toast = successToast || errorToast;

    if (toast) {
        window.setTimeout(function () {
            toast.classList.add('is-hiding');
            window.setTimeout(function () {
                if (successToast) {
                    window.location.href = 'panss-assessment-records.php';
                }
            }, 250);
        }, 3000);
    }

    function showJsError(message) {
        if (!jsErrorToast || !jsErrorMessage) return;
        jsErrorMessage.textContent = message;
        jsErrorToast.style.display = 'flex';
        jsErrorToast.classList.remove('is-hiding');

        if (jsErrorProgress) {
            jsErrorProgress.style.animation = 'none';
            void jsErrorProgress.offsetWidth;
            jsErrorProgress.style.animation = '';
        }

        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            jsErrorToast.classList.add('is-hiding');
            window.setTimeout(function () {
                jsErrorToast.style.display = 'none';
            }, 250);
        }, 3500);
    }

    var tabs = document.querySelectorAll('.panss-tab-btn');
    var panes = document.querySelectorAll('.panss-tab-pane');
    var tabOrder = ['tab-P', 'tab-N', 'tab-G', 'tab-summary'];
    var controls = document.querySelectorAll('.panss-rating');
    var form = document.getElementById('panssForm');
    var patientSelect = document.getElementById('patient_id');
    var assessmentDate = document.getElementById('assessment_date');
    var notesInput = document.getElementById('clinical_notes');
    var notesCountEl = document.getElementById('notesCharCount');
    var targets = {
        P: document.getElementById('positiveTotal'),
        N: document.getElementById('negativeTotal'),
        G: document.getElementById('generalTotal')
    };

    function setFieldError(field, message) {
        var fieldContainer = document.getElementById(field + 'Field');
        var error = document.getElementById('error_' + field);
        if (!fieldContainer || !error) return;
        fieldContainer.classList.toggle('has-error', message !== '');
        error.textContent = message;
        error.hidden = message === '';
    }

    function setRatingError(control, message) {
        var row = document.getElementById('row_' + control.dataset.code);
        var error = document.getElementById('error_' + control.dataset.code);
        control.classList.toggle('has-error', message !== '');
        if (row) row.classList.toggle('has-error', message !== '');
        if (error) {
            error.textContent = message;
            error.hidden = message === '';
        }
    }

    function isSectionComplete(sectionPrefix) {
        var sectionControls = document.querySelectorAll('.panss-rating[data-section="' + sectionPrefix + '"]');
        var complete = true;
        sectionControls.forEach(function (ctrl) {
            if (ctrl.value === '') complete = false;
        });
        return complete;
    }

    function validateSectionWithInlineErrors(sectionPrefix) {
        var sectionControls = document.querySelectorAll('.panss-rating[data-section="' + sectionPrefix + '"]');
        var firstMissing = null;
        sectionControls.forEach(function (ctrl) {
            if (ctrl.value === '') {
                setRatingError(ctrl, 'Please select a rating for this item.');
                if (!firstMissing) firstMissing = ctrl;
            } else {
                setRatingError(ctrl, '');
            }
        });
        return firstMissing;
    }

    function updateTabAccessibility() {
        var pComplete = isSectionComplete('P');
        var nComplete = isSectionComplete('N');
        var gComplete = isSectionComplete('G');

        tabs.forEach(function (tab) {
            var target = tab.dataset.tab;
            if (target === 'tab-P') {
                tab.classList.toggle('is-done', pComplete);
            } else if (target === 'tab-N') {
                tab.disabled = !pComplete;
                tab.classList.toggle('is-done', nComplete);
            } else if (target === 'tab-G') {
                tab.disabled = !(pComplete && nComplete);
                tab.classList.toggle('is-done', gComplete);
            } else if (target === 'tab-summary') {
                tab.disabled = !(pComplete && nComplete && gComplete);
            }
        });
    }

    function switchTab(targetId) {
        var targetTabBtn = document.querySelector('.panss-tab-btn[data-tab="' + targetId + '"]');
        if (targetTabBtn) targetTabBtn.disabled = false;

        tabs.forEach(function (t) {
            var active = t.dataset.tab === targetId;
            t.classList.toggle('active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panes.forEach(function (p) {
            p.hidden = p.id !== targetId;
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            if (!tab.disabled) switchTab(tab.dataset.tab);
        });
    });

    document.querySelectorAll('.panss-next-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var currentPane = btn.closest('.panss-tab-pane');
            var currentIndex = tabOrder.indexOf(currentPane.id);

            var sectionMap = {
                'tab-P': { code: 'P', name: 'Positive Scale' },
                'tab-N': { code: 'N', name: 'Negative Scale' },
                'tab-G': { code: 'G', name: 'General Psychopathology' }
            };

            if (sectionMap[currentPane.id]) {
                var sec = sectionMap[currentPane.id];
                var firstMissing = validateSectionWithInlineErrors(sec.code);
                if (firstMissing) {
                    showJsError('Please rate all items in the ' + sec.name + ' before proceeding.');
                    firstMissing.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstMissing.focus();
                    return;
                }
            }

            updateTabAccessibility();
            if (currentIndex !== -1 && currentIndex < tabOrder.length - 1) {
                switchTab(tabOrder[currentIndex + 1]);
            }
        });
    });

    document.querySelectorAll('.panss-prev-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var currentPane = btn.closest('.panss-tab-pane');
            var currentIndex = tabOrder.indexOf(currentPane.id);
            if (currentIndex > 0) switchTab(tabOrder[currentIndex - 1]);
        });
    });

    function updateTotals() {
        var totals = { P: 0, N: 0, G: 0 };
        var counts = { P: 0, N: 0, G: 0 };
        var maxCounts = { P: 7, N: 7, G: 16 };
        var totalRated = 0;

        controls.forEach(function (control) {
            var sec = control.dataset.section;
            var value = Number(control.value);
            var tr = control.closest('tr');
            if (value >= 1 && value <= 7) {
                totals[sec] += value;
                counts[sec]++;
                totalRated++;
                if (tr) tr.classList.add('is-rated');
            } else {
                if (tr) tr.classList.remove('is-rated');
            }
        });

        targets.P.textContent = String(totals.P);
        targets.N.textContent = String(totals.N);
        targets.G.textContent = String(totals.G);

        var composite = totals.P - totals.N;
        var compStr = composite > 0 ? '+' + composite : String(composite);
        document.getElementById('compositeTotal').textContent = compStr;

        // Update sticky bar & progress indicators
        var stickyP = document.getElementById('stickyP');
        var stickyN = document.getElementById('stickyN');
        var stickyG = document.getElementById('stickyG');
        var stickyComp = document.getElementById('stickyComp');
        var overallRated = document.getElementById('overallRatedCount');

        if (stickyP) stickyP.textContent = String(totals.P);
        if (stickyN) stickyN.textContent = String(totals.N);
        if (stickyG) stickyG.textContent = String(totals.G);
        if (stickyComp) stickyComp.textContent = compStr;
        if (overallRated) overallRated.textContent = String(totalRated);

        ['P', 'N', 'G'].forEach(function (sec) {
            var subEl = document.getElementById('stepSub' + sec);
            if (subEl) subEl.textContent = counts[sec] + ' / ' + maxCounts[sec] + ' rated';
            var fillEl = document.getElementById('progressFill_' + sec);
            if (fillEl) fillEl.style.width = Math.round((counts[sec] / maxCounts[sec]) * 100) + '%';
        });
    }

    controls.forEach(function (control) {
        control.addEventListener('change', function () {
            updateTotals();
            updateTabAccessibility();
            if (control.value !== '') setRatingError(control, '');
        });
    });

    patientSelect.addEventListener('change', function () {
        if (patientSelect.value !== '') setFieldError('patient_id', '');
        updateTabAccessibility();
    });
    assessmentDate.addEventListener('change', function () {
        if (assessmentDate.value !== '') setFieldError('assessment_date', '');
        updateTabAccessibility();
    });

    function updateNotesCount() {
        if (notesInput && notesCountEl) {
            notesCountEl.textContent = notesInput.value.length.toLocaleString();
        }
    }
    if (notesInput) {
        notesInput.addEventListener('input', function () {
            updateNotesCount();
            if (notesInput.value.length <= 5000) setFieldError('clinical_notes', '');
        });
        updateNotesCount();
    }

    form.addEventListener('submit', function (event) {
        var hasErrors = false;
        var firstTopErrorControl = null;

        if (patientSelect.value === '') {
            setFieldError('patient_id', 'Please select a patient.');
            hasErrors = true;
            firstTopErrorControl = patientSelect;
        } else {
            setFieldError('patient_id', '');
        }

        if (assessmentDate.value === '' || !assessmentDate.validity.valid) {
            setFieldError('assessment_date', 'Please enter a valid assessment date.');
            hasErrors = true;
            if (!firstTopErrorControl) firstTopErrorControl = assessmentDate;
        } else {
            setFieldError('assessment_date', '');
        }

        if (notesInput.value.length > 5000) {
            setFieldError('clinical_notes', 'Clinical notes must be 5,000 characters or fewer.');
            hasErrors = true;
        } else {
            setFieldError('clinical_notes', '');
        }

        var firstErrorControl = null;
        controls.forEach(function (control) {
            if (control.value === '') {
                setRatingError(control, 'Please select a rating for this item.');
                hasErrors = true;
                if (!firstErrorControl) firstErrorControl = control;
            } else {
                setRatingError(control, '');
            }
        });

        if (hasErrors) {
            event.preventDefault();
            if (firstTopErrorControl) {
                showJsError('Please select the client name and assessment date.');
                firstTopErrorControl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstTopErrorControl.focus();
                return;
            }
            if (firstErrorControl) {
                showJsError('Please complete all required ratings before submitting.');
                var pane = firstErrorControl.closest('.panss-tab-pane');
                if (pane) switchTab(pane.id);
                window.setTimeout(function () {
                    firstErrorControl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstErrorControl.focus();
                }, 150);
            }
        }
    });

    updateTotals();
    updateTabAccessibility();
});
</script>
<?php require 'layout/footer.php'; ?>