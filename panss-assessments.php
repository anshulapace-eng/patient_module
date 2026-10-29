<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';
require 'panss-assessment-items.php';
require 'panss-assessment-schema.php';

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
        $insert_stmt = $conn->prepare(
            'INSERT INTO panss_assessments
                (patient_id, clinician_id, assessment_date, positive_score, negative_score,
                 general_score, composite_score, score_data, clinical_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insert_stmt) {
            error_log('PANSS insert prepare failed: ' . $conn->error);
            $error_msg = 'The assessment could not be saved. Please try again.';
        } else {
            $insert_stmt->bind_param(
                'iisiiiiss',
                $form_patient_id,
                $clinician_id,
                $form_date,
                $positive_score,
                $negative_score,
                $general_score,
                $composite_score,
                $score_data,
                $form_notes
            );
            if ($insert_stmt->execute()) {
                $insert_stmt->close();
                $_SESSION['panss_csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['panss_form_success'] = 'Assessment saved successfully.';
                header('Location: panss-assessments.php?saved=1');
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
        --teal-primary: #0f766e;
        --teal-hover: #115e59;
        --teal-light: #f0fdfa;
        --border-color: #e2e8f0;
        --text-main: #0f172a;
        --text-muted: #64748b;
    }
    .panss-page { max-width: 1140px; margin: 0 auto; padding: 24px 16px 48px; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
    
    /* Header Card */
    .panss-header { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 24px; padding: 24px; border-radius: 12px; background: #fff; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.04); border-top: 4px solid var(--teal-primary); }
    .panss-header h1 { margin: 0 0 4px; color: var(--text-main); font-size: 22px; font-weight: 700; }
    .panss-header p { margin: 0; color: var(--text-muted); font-size: 13.5px; }
    .panss-link { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border: 1px solid var(--border-color); border-radius: 6px; background: #f8fafc; color: var(--text-main); text-decoration: none; font-size: 13px; font-weight: 600; transition: background 0.15s ease; }
    .panss-link:hover { background: #f1f5f9; color: var(--teal-primary); }

    /* Toast */
    .panss-toast { position: fixed; top: 14px; right: 16px; z-index: 1100; display: flex; width: min(360px, calc(100vw - 32px)); align-items: center; gap: 13px; overflow: hidden; padding: 12px 16px; border-radius: 6px; color: #fff; box-shadow: 0 4px 12px rgba(15,23,42,.2); opacity: 1; transform: translateY(0); transition: opacity .25s ease, transform .25s ease; }
    .panss-toast-success { background: var(--teal-primary); }
    .panss-toast-error { background: #dc3545; }
    .panss-toast.is-hiding { opacity: 0; transform: translateY(-8px); }
    .panss-toast-icon { display: grid; width: 36px; height: 36px; flex: 0 0 auto; place-items: center; border: 3px solid rgba(255,255,255,.9); border-radius: 50%; font-size: 16px; }
    .panss-toast-copy { min-width: 0; }
    .panss-toast-title { margin-right: 8px; font-size: 15px; font-weight: 800; }
    .panss-toast-message { font-size: 12.5px; }
    .panss-toast-progress { position: absolute; right: 0; bottom: 0; left: 0; height: 3px; background: rgba(255,255,255,.35); transform: scaleX(0); transform-origin: left; animation: panss-toast-progress 3s linear forwards; }
    @keyframes panss-toast-progress { to { transform: scaleX(1); } }

    /* Step Navigation Tabs */
    .panss-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid var(--border-color); padding-bottom: 0;  }
    .panss-tab-btn { padding: 10px 18px; border: none; background: transparent; color: var(--text-muted); font-size: 14px; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; transition: all 0.2s ease; }
    .panss-tab-btn:hover:not(:disabled) { color: var(--teal-primary); }
    .panss-tab-btn.active { color: var(--teal-primary); border-bottom-color: var(--teal-primary); background: #fff; border-top-left-radius: 6px; border-top-right-radius: 6px; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); }
    .panss-tab-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    /* Panels & Sections */
    .panss-panel, .panss-section { margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 10px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.02); overflow: hidden; }
    .panss-panel { padding: 22px; }
    .panss-panel h2, .panss-section h2 { margin: 0; color: var(--text-main); font-size: 15px; font-weight: 700; }
    .panss-subtitle { margin: 4px 0 0; color: var(--text-muted); font-size: 12.5px; }
    
    .panss-meta { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 16px; }
    .panss-field label { display: block; margin-bottom: 6px; color: var(--text-main); font-size: 12px; font-weight: 600; }
    .panss-field .form-control, .panss-field .form-select { min-height: 40px; border-color: #cbd5e1; border-radius: 6px; font-size: 13.5px; }
    .panss-field .form-control:focus, .panss-field .form-select:focus { border-color: var(--teal-primary); box-shadow: 0 0 0 2px rgba(15,118,110,0.15); }
    .panss-field.has-error .form-control, .panss-field.has-error .form-select, .panss-rating.has-error { border-color: #dc3545 !important; box-shadow: 0 0 0 2px rgba(220,53,69,0.15); }
    .panss-field-error, .panss-item-error { display: block; margin-top: 5px; color: #dc3545; font-size: 11.5px; font-weight: 700; }
    .panss-page [hidden] { display: none !important; }
    
    .panss-item-row.has-error { background: #fef2f2; }
    .panss-section-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border-color); background: #f8fafc; }
    .panss-range { padding: 4px 10px; border-radius: 4px; background: var(--teal-light); color: var(--teal-primary); border: 1px solid #ccfbf1; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
    
    .panss-table-wrap { padding: 0 20px 10px; }
    .panss-table { width: 100%; border-collapse: collapse; }
    .panss-table th, .panss-table td { padding: 11px 12px; border-bottom: 1px solid #f1f5f9; text-align: left; font-size: 13.5px; vertical-align: middle; }
    .panss-table th { color: var(--text-muted); font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; background: #fff; }
    .panss-table td { color: var(--text-main); }
    
    /* Compact Select Dropdown Width */
    .panss-table select { width: 100%; max-width: 220px; height: 36px; padding: 5px 30px 5px 10px; font-size: 13px; border-radius: 6px; border-color: #cbd5e1; }
    .panss-table select:focus { border-color: var(--teal-primary); box-shadow: 0 0 0 2px rgba(15,118,110,0.15); }
    
    .panss-item-code { display: inline-flex; min-width: 36px; justify-content: center; padding: 4px 8px; border-radius: 5px; background: #f1f5f9; color: var(--text-main); font-weight: 700; font-size: 12px; }

    /* Score Summary Card */
    .panss-score-card { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 8px; }
    .panss-score-chip { flex: 1 1 150px; padding: 14px 16px; border: 1px solid var(--border-color); border-radius: 8px; background: #f8fafc; }
    .panss-score-chip span { display: block; color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .panss-score-chip strong { display: block; margin-top: 4px; color: var(--teal-primary); font-size: 22px; font-weight: 700; }

    .panss-actions { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 16px; }
    .panss-actions .btn-primary { background: var(--teal-primary); border-color: var(--teal-primary); padding: 9px 20px; font-weight: 600; font-size: 13.5px; border-radius: 6px; }
    .panss-actions .btn-primary:hover { background: var(--teal-hover); border-color: var(--teal-hover); }
    .panss-actions .btn-secondary { background: #e2e8f0; border-color: #cbd5e1; color: var(--text-main); padding: 9px 20px; font-weight: 600; font-size: 13.5px; border-radius: 6px; }
    .panss-actions .btn-secondary:hover { background: #cbd5e1; }

    .panss-note { padding: 12px 16px; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; border-radius: 6px; background: #f0fdf4; color: #166534; font-size: 12.5px; margin-bottom: 20px; }
    
    @media (max-width: 720px) { 
        .panss-page { padding: 16px 10px 32px; } 
        .panss-header { align-items: flex-start; flex-direction: column; padding: 18px; } 
        .panss-meta { grid-template-columns: 1fr; } 
        .panss-table-wrap { padding: 0 8px 8px; } 
        .panss-table th, .panss-table td { padding: 10px 6px; } 
        .panss-table select { max-width: 100%; }
    }
</style>

<div class="container-fluid py-3 px-2 px-md-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="panss-page">
        <div class="panss-header">
            <div>
                <span class="badge mb-1 px-2.5 py-1" style="background:var(--teal-light);color:var(--teal-primary);border:1px solid #ccfbf1;font-size:10.5px;font-weight:700;">
                    <i class="fa-solid fa-notes-medical me-1" aria-hidden="true"></i> Psychiatric Clinical Evaluation
                </span>
                <h1>PANSS Assessment Module</h1>
                <p>Rate all 30 coded items across Positive, Negative, and General Psychopathology scales.</p>
            </div>
            <a class="panss-link" href="panss-assessment-records.php"><i class="bi bi-table" aria-hidden="true"></i> View records</a>
        </div>

        <?php if ($success_msg !== ''): ?>
            <div class="panss-toast panss-toast-success" id="panssSuccessToast" role="status" aria-live="polite">
                <span class="panss-toast-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span class="panss-toast-copy"><strong class="panss-toast-title">Success!</strong><span class="panss-toast-message"><?php echo panss_form_escape($success_msg); ?></span></span>
                <span class="panss-toast-progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>
        
        <!-- Dynamic Toast Container for JavaScript Error Popups -->
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

            <section class="panss-panel" id="topDetailsPanel">
                <h2>Assessment Details</h2>
                <p class="panss-subtitle">Select the target patient and record the assessment date.</p>
                <div class="panss-meta">
                    <div class="panss-field<?php echo isset($field_errors['patient_id']) ? ' has-error' : ''; ?>" id="patient_idField">
                        <label for="patient_id">Client Name <span class="text-danger">*</span></label>
                        <select class="form-select" id="patient_id" name="patient_id" required aria-describedby="error_patient_id">
                            <option value="">Select a patient...</option>
                            <?php foreach ($patients as $patient): ?>
                                <?php
                                $patient_name = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
                                $patient_name = $patient_name !== '' ? $patient_name : ($patient['email'] ?? 'Patient');
                                ?>
                                <option value="<?php echo (int) $patient['id']; ?>" <?php echo $form_patient_id === (int) $patient['id'] ? 'selected' : ''; ?>>
                                    <?php echo panss_form_escape($patient_name); ?> (ID: <?php echo (int) $patient['id']; ?>)
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
            </section>

            <div class="panss-note">
                <i class="fa-solid fa-circle-info me-1"></i> Item names reflect standard clinical terminology for rating criteria. Ensure proper instrument guidelines are followed.
            </div>

            <!-- Navigation Tabs -->
            <div class="panss-tabs" role="tablist">
                <button type="button" class="panss-tab-btn active" data-tab="tab-P" role="tab" aria-selected="true">1. Positive Scale</button>
                <button type="button" class="panss-tab-btn" data-tab="tab-N" role="tab" aria-selected="false" disabled>2. Negative Scale</button>
                <button type="button" class="panss-tab-btn" data-tab="tab-G" role="tab" aria-selected="false" disabled>3. General Psychopathology</button>
                <button type="button" class="panss-tab-btn" data-tab="tab-summary" role="tab" aria-selected="false" disabled>4. Summary & Submit</button>
            </div>

            <!-- Tab Content Sections -->
            <?php foreach ($panss_sections as $prefix => $section): ?>
                <?php
                $minimum = $section['count'];
                $maximum = $section['count'] * 7;
                $is_first = ($prefix === 'P');
                ?>
                <div class="panss-tab-pane" id="tab-<?php echo panss_form_escape($prefix); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
                    <section class="panss-section" aria-labelledby="section-<?php echo panss_form_escape($prefix); ?>">
                        <div class="panss-section-head">
                            <div>
                                <h2 id="section-<?php echo panss_form_escape($prefix); ?>"><?php echo panss_form_escape($section['title']); ?></h2>
                                <p class="panss-subtitle"><?php echo (int) $section['count']; ?> items total · select one severity score per item</p>
                            </div>
                            <span class="panss-range">Range: <?php echo $minimum; ?>–<?php echo $maximum; ?></span>
                        </div>
                        <div class="table-responsive panss-table-wrap">
                            <table class="panss-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">Code</th>
                                        <th>Item Description</th>
                                        <th style="width: 250px;">Severity Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($panss_items[$prefix] as $code => $label): ?>
                                        <?php
                                        $raw_score = $submitted_scores[$code] ?? '';
                                        $selected_score = is_string($raw_score) || is_int($raw_score) ? (string) $raw_score : '';
                                        ?>
                                        <tr>
                                            <td><span class="panss-item-code"><?php echo panss_form_escape($code); ?></span></td>
                                            <td class="fw-semibold text-dark"><?php echo panss_form_escape($label); ?></td>
                                            <td class="panss-item-row<?php echo isset($score_errors[$code]) ? ' has-error' : ''; ?>" id="row_<?php echo panss_form_escape($code); ?>">
                                                <select class="form-select form-select-sm panss-rating<?php echo isset($score_errors[$code]) ? ' has-error' : ''; ?>" name="scores[<?php echo panss_form_escape($code); ?>]" data-code="<?php echo panss_form_escape($code); ?>" data-section="<?php echo panss_form_escape($prefix); ?>" required aria-label="Rating for <?php echo panss_form_escape($code); ?>" aria-describedby="error_<?php echo panss_form_escape($code); ?>">
                                                    <option value="">Select rating...</option>
                                                    <?php foreach ($panss_rating_labels as $rating => $rating_label): ?>
                                                        <option value="<?php echo $rating; ?>" <?php echo $selected_score === (string) $rating ? 'selected' : ''; ?>><?php echo $rating . ' - ' . panss_form_escape($rating_label); ?></option>
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
                    
                    <!-- Step Nav Buttons -->
                    <div class="panss-actions">
                        <?php if ($prefix !== 'P'): ?>
                            <button type="button" class="btn btn-secondary panss-prev-btn">Previous Step</button>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-primary panss-next-btn">Next Step</button>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Tab 4: Summary & Submit -->
            <div class="panss-tab-pane" id="tab-summary" hidden>
                <section class="panss-panel">
                    <h2>Score Summary</h2>
                    <p class="panss-subtitle mb-3">Subtotals compute dynamically. Composite score represents Positive minus Negative.</p>
                    <div class="panss-score-card">
                        <div class="panss-score-chip"><span>Positive Scale (7–49)</span><strong id="positiveTotal">0</strong></div>
                        <div class="panss-score-chip"><span>Negative Scale (7–49)</span><strong id="negativeTotal">0</strong></div>
                        <div class="panss-score-chip"><span>General Psychopathology (16–112)</span><strong id="generalTotal">0</strong></div>
                        <div class="panss-score-chip"><span>Composite Score (−42 to +42)</span><strong id="compositeTotal">0</strong></div>
                    </div>
                </section>

                <section class="panss-panel">
                    <div class="panss-field<?php echo isset($field_errors['clinical_notes']) ? ' has-error' : ''; ?>" id="clinical_notesField">
                        <label for="clinical_notes">Clinical Observations & Notes <span class="text-muted fw-normal">(Optional)</span></label>
                        <textarea class="form-control" id="clinical_notes" name="clinical_notes" rows="4" maxlength="5000" placeholder="Enter behavioral observations or additional clinical context..." aria-describedby="error_clinical_notes"><?php echo panss_form_escape($form_notes); ?></textarea>
                        <small class="panss-field-error" id="error_clinical_notes" role="alert" <?php echo isset($field_errors['clinical_notes']) ? '' : 'hidden'; ?>><?php echo panss_form_escape($field_errors['clinical_notes'] ?? ''); ?></small>
                    </div>
                    <div class="panss-actions">
                        <button type="button" class="btn btn-secondary panss-prev-btn">Previous Step</button>
                        <button class="btn btn-primary" type="submit" name="submit_panss" value="1" <?php echo empty($patients) || !$schema_ready ? 'disabled' : ''; ?>>
                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Save Assessment
                        </button>
                    </div>
                </section>
            </div>
        </form>
    </div>
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
        toastTimer = window.setTimeout(function() {
            jsErrorToast.classList.add('is-hiding');
            window.setTimeout(function() {
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
    var targets = {
        P: document.getElementById('positiveTotal'),
        N: document.getElementById('negativeTotal'),
        G: document.getElementById('generalTotal')
    };

    function setFieldError(field, message) {
        var fieldContainer = document.getElementById(field + 'Field');
        var error = document.getElementById('error_' + field);
        if (!fieldContainer || !error) {
            return;
        }
        fieldContainer.classList.toggle('has-error', message !== '');
        error.textContent = message;
        error.hidden = message === '';
    }

    function setRatingError(control, message) {
        var row = document.getElementById('row_' + control.dataset.code);
        var error = document.getElementById('error_' + control.dataset.code);
        control.classList.toggle('has-error', message !== '');
        if (row) {
            row.classList.toggle('has-error', message !== '');
        }
        if (error) {
            error.textContent = message;
            error.hidden = message === '';
        }
    }

    function isSectionComplete(sectionPrefix) {
        var sectionControls = document.querySelectorAll('.panss-rating[data-section="' + sectionPrefix + '"]');
        var complete = true;
        sectionControls.forEach(function (ctrl) {
            if (ctrl.value === '') {
                complete = false;
            }
        });
        return complete;
    }

    function validateSectionWithInlineErrors(sectionPrefix) {
        var sectionControls = document.querySelectorAll('.panss-rating[data-section="' + sectionPrefix + '"]');
        var firstMissing = null;
        sectionControls.forEach(function (ctrl) {
            if (ctrl.value === '') {
                setRatingError(ctrl, 'Please select a rating for this item.');
                if (!firstMissing) {
                    firstMissing = ctrl;
                }
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
            if (target === 'tab-N') {
                tab.disabled = !pComplete;
            } else if (target === 'tab-G') {
                tab.disabled = !(pComplete && nComplete);
            } else if (target === 'tab-summary') {
                tab.disabled = !(pComplete && nComplete && gComplete);
            }
        });
    }

    function switchTab(targetId) {
        var targetTabBtn = document.querySelector('.panss-tab-btn[data-tab="' + targetId + '"]');
        if (targetTabBtn) {
            targetTabBtn.disabled = false;
        }

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
            if (!tab.disabled) {
                switchTab(tab.dataset.tab);
            }
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
            if (currentIndex > 0) {
                switchTab(tabOrder[currentIndex - 1]);
            }
        });
    });

    function updateTotals() {
        var totals = { P: 0, N: 0, G: 0 };
        controls.forEach(function (control) {
            var value = Number(control.value);
            if (value >= 1 && value <= 7) {
                totals[control.dataset.section] += value;
            }
        });
        targets.P.textContent = String(totals.P);
        targets.N.textContent = String(totals.N);
        targets.G.textContent = String(totals.G);
        var composite = totals.P - totals.N;
        document.getElementById('compositeTotal').textContent = composite > 0 ? '+' + composite : String(composite);
    }

    controls.forEach(function (control) {
        control.addEventListener('change', function () {
            updateTotals();
            updateTabAccessibility();
            if (control.value !== '') {
                setRatingError(control, '');
            }
        });
    });

    patientSelect.addEventListener('change', function () {
        if (patientSelect.value !== '') {
            setFieldError('patient_id', '');
        }
        updateTabAccessibility();
    });
    assessmentDate.addEventListener('change', function () {
        if (assessmentDate.value !== '') {
            setFieldError('assessment_date', '');
        }
        updateTabAccessibility();
    });
    notesInput.addEventListener('input', function () {
        if (notesInput.value.length <= 5000) {
            setFieldError('clinical_notes', '');
        }
    });

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
            if (!firstTopErrorControl) {
                firstTopErrorControl = assessmentDate;
            }
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
                if (!firstErrorControl) {
                    firstErrorControl = control;
                }
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
                if (pane) {
                    switchTab(pane.id);
                }
                window.setTimeout(function() {
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