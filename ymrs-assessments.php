<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();
require 'config.php';
require 'ymrs-assessment-items.php';
require 'ymrs-assessment-schema.php';
require 'assessment-demographics.php';

if (!isset($_SESSION['user_login']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'YMRS Assessment - Vimhans Academic';
$patients = [];
$success_msg = '';
$error_msg = '';
$score_errors = [];
$submitted_scores = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
$form_patient_input = $_POST['patient_id'] ?? '';
$form_patient_id = filter_var($form_patient_input, FILTER_VALIDATE_INT);
$form_patient_id = $form_patient_id === false ? 0 : (int)$form_patient_id;
$form_date = is_string($_POST['assessment_date'] ?? null) ? $_POST['assessment_date'] : date('Y-m-d');
$form_notes = is_string($_POST['clinical_notes'] ?? null) ? $_POST['clinical_notes'] : '';
$clinician_name = trim((string)($_SESSION['name'] ?? ''));
$demographics = assessment_demographics_normalize($_POST['demographics'] ?? []);
$demographics['date'] = $demographics['date'] ?: $form_date;

if (!isset($_SESSION['ymrs_csrf_token'])) {
    $_SESSION['ymrs_csrf_token'] = bin2hex(random_bytes(32));
}

if ($ymrs_schema_ready) {
    $result = $conn->query('SELECT id, first_name, last_name, email FROM users WHERE role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . ' ORDER BY first_name, last_name');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $patients[] = $row;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ymrs']) && $ymrs_schema_ready) {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['ymrs_csrf_token'], $token)) {
        $error_msg = 'Your form session expired. Reload and submit again.';
    } elseif ($form_patient_id < 1) {
        $error_msg = 'Please select a valid patient.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_date) || !checkdate((int)substr($form_date, 5, 2), (int)substr($form_date, 8, 2), (int)substr($form_date, 0, 4))) {
        $error_msg = 'Please enter a valid assessment date.';
    } elseif (strlen($form_notes) > 5000) {
        $error_msg = 'Clinical notes must be 5,000 characters or fewer.';
    } else {
        $scores = [];
        $total = 0;
        foreach ($ymrs_items as $field => $item) {
            $raw = $submitted_scores[$field] ?? null;
            $score = is_string($raw) || is_int($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false;
            if ($score === false || !array_key_exists((int)$score, $item['ratings'])) {
                $score_errors[$field] = 'Please select a rating.';
            } else {
                $scores[$field] = (int)$score;
                $total += (int)$score;
            }
        }
        if (!empty($score_errors)) {
            $error_msg = 'Please select a rating for each highlighted item.';
        }

        if ($error_msg === '') {
            $stmt = $conn->prepare('SELECT id FROM users WHERE id = ? AND role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . ' LIMIT 1');
            $stmt->bind_param('i', $form_patient_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows !== 1) {
                $error_msg = 'The selected patient could not be found.';
            }
            $stmt->close();
        }

        if ($error_msg === '') {
            $demographics = assessment_demographics_normalize($_POST['demographics'] ?? []);
            $demographics['date'] = $demographics['date'] ?: $form_date;
            $demographics['filled_by'] = $demographics['filled_by'] ?: $clinician_name;
            $stmt = $conn->prepare('INSERT INTO ymrs_assessments (patient_id, clinician_id, assessment_date, total_score, score_data, clinical_notes, demographic_data) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $score_json = json_encode($scores);
            $demographic_json = json_encode($demographics, JSON_UNESCAPED_UNICODE);
            $clinician_id = (int)($_SESSION['user_id'] ?? 0);
            $stmt->bind_param('iisisss', $form_patient_id, $clinician_id, $form_date, $total, $score_json, $form_notes, $demographic_json);
            if ($stmt->execute()) {
                $_SESSION['ymrs_saved_score'] = $total;
                $_SESSION['ymrs_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ymrs-assessments.php?saved=1');
                exit;
            }
            $error_msg = 'The assessment could not be saved. Please try again.';
        }
    }
}

if (isset($_GET['saved'])) {
    $saved = filter_var($_SESSION['ymrs_saved_score'] ?? null, FILTER_VALIDATE_INT);
    $success_msg = 'YMRS assessment saved successfully. Total score: ' . ($saved === false ? 0 : $saved) . ' / 60.';
    unset($_SESSION['ymrs_saved_score']);
}

function ymrs_escape($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$total_items_count = count($ymrs_items);
require 'layout/base.php';
?>
<style>
    :root {
        --ymrs-primary: #0f766e;
        --ymrs-primary-dark: #115e59;
        --ymrs-primary-soft: #f0fdfa;
        --ymrs-primary-border: #99f6e4;
        --ymrs-border: #e2e8f0;
        --ymrs-border-soft: #f1f5f9;
        --ymrs-text: #0f172a;
        --ymrs-muted: #64748b;
        --ymrs-bg: #ffffff;
        --ymrs-surface: #f8fafc;
        --ymrs-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
        --ymrs-shadow-sm: 0 2px 8px rgba(15, 23, 42, 0.04);
    }

    .ymrs-page {
        max-width: 1180px;
        margin: 0 auto;
        padding: 24px 18px 44px;
        color: var(--ymrs-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Header Banner */
    .ymrs-header {
        background: var(--ymrs-bg);
        border: 1px solid var(--ymrs-border);
        border-top: 4px solid var(--ymrs-primary);
        border-radius: 14px;
        padding: 22px 26px;
        margin-bottom: 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        box-shadow: var(--ymrs-shadow);
    }

    .ymrs-header-info {
        min-width: 0;
    }

    .ymrs-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--ymrs-primary);
        background: var(--ymrs-primary-soft);
        border: 1px solid var(--ymrs-primary-border);
        padding: 4px 10px;
        border-radius: 999px;
        margin-bottom: 10px;
    }

    .ymrs-header h1 {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        letter-spacing: -0.01em;
        color: var(--ymrs-text);
    }

    .ymrs-header p {
        margin: 6px 0 0;
        font-size: 13.5px;
        color: var(--ymrs-muted);
    }

    .ymrs-score-widget {
        display: flex;
        align-items: center;
        gap: 16px;
        background: var(--ymrs-surface);
        border: 1px solid var(--ymrs-border);
        border-radius: 12px;
        padding: 12px 18px;
        flex-shrink: 0;
    }

    .ymrs-score-metric {
        text-align: center;
        padding-right: 16px;
        border-right: 1px solid var(--ymrs-border);
    }

    .ymrs-score-metric small,
    .ymrs-score-meta small {
        display: block;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--ymrs-muted);
        margin-bottom: 4px;
    }

    .ymrs-score-line {
        display: flex;
        align-items: baseline;
        justify-content: center;
        gap: 2px;
        white-space: nowrap;
    }

    .ymrs-score-line strong {
        font-size: 28px;
        font-weight: 800;
        line-height: 1;
        color: var(--ymrs-primary);
    }

    .ymrs-score-line span {
        font-size: 16px;
        font-weight: 700;
        color: #94a3b8;
    }

    .ymrs-score-meta {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 135px;
    }

    .ymrs-severity-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 700;
        white-space: nowrap;
    }

    .ymrs-severity-pill.normal { background: #dcfce7; color: #166534; }
    .ymrs-severity-pill.mild { background: #fef3c7; color: #92400e; }
    .ymrs-severity-pill.moderate { background: #ffedd5; color: #9a3412; }
    .ymrs-severity-pill.severe { background: #fee2e2; color: #991b1b; }

    .ymrs-progress-text {
        font-size: 12px;
        font-weight: 600;
        color: #475569;
    }

    /* Section Cards */
    .ymrs-section {
        background: var(--ymrs-bg);
        border: 1px solid var(--ymrs-border);
        border-radius: 14px;
        margin-bottom: 22px;
        box-shadow: var(--ymrs-shadow);
        overflow: hidden;
    }

    .ymrs-section-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--ymrs-border);
        border-left: 4px solid var(--ymrs-primary);
        padding: 13px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .ymrs-section-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--ymrs-text);
    }

    .ymrs-section-body {
        padding: 20px 22px;
    }

    .ymrs-section-body .form-label {
        font-size: 12.5px;
        font-weight: 700;
        color: #334155;
        margin-bottom: 6px;
    }

    .ymrs-section-body .form-control,
    .ymrs-section-body .form-select {
        border-color: #cbd5e1;
        border-radius: 8px;
        font-size: 13.5px;
        padding: 9px 12px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .ymrs-section-body .form-control:focus,
    .ymrs-section-body .form-select:focus {
        border-color: var(--ymrs-primary);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14);
    }

    /* Progress Bar */
    .ymrs-progress-track {
        width: 100%;
        height: 4px;
        background: #e2e8f0;
    }

    .ymrs-progress-fill {
        height: 100%;
        width: 0%;
        background: var(--ymrs-primary);
        transition: width 0.25s ease;
    }

    /* Assessment Item Row */
    .ymrs-row {
        padding: 20px 22px;
        border-bottom: 1px solid var(--ymrs-border-soft);
        transition: background-color 0.2s ease;
    }

    .ymrs-row:last-child {
        border-bottom: 0;
    }

    .ymrs-row.is-answered {
        background: #fcfefd;
    }

    .ymrs-row.error {
        background: #fef2f2;
        border-left: 4px solid #dc2626;
    }

    .ymrs-item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 14px;
    }

    .ymrs-item-title-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .ymrs-item-num {
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
        transition: all 0.2s ease;
    }

    .ymrs-row.is-answered .ymrs-item-num {
        background: var(--ymrs-primary);
        color: #fff;
    }

    .ymrs-item-title {
        font-size: 15px;
        font-weight: 800;
        color: var(--ymrs-text);
    }

    .ymrs-item-badge {
        font-size: 12px;
        font-weight: 700;
        padding: 4px 11px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #64748b;
        border: 1px solid #e2e8f0;
        white-space: nowrap;
        transition: all 0.2s ease;
    }

    .ymrs-row.is-answered .ymrs-item-badge {
        background: var(--ymrs-primary-soft);
        color: var(--ymrs-primary);
        border-color: var(--ymrs-primary-border);
    }

    /* Radio Option Cards */
    .ymrs-options {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .ymrs-option {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 14px;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        cursor: pointer;
        transition: all 0.16s ease;
        margin: 0;
    }

    .ymrs-option:hover {
        border-color: #94a3b8;
        background: #f8fafc;
        transform: translateY(-1px);
        box-shadow: var(--ymrs-shadow-sm);
    }

    .ymrs-option:has(input:checked) {
        border-color: var(--ymrs-primary);
        background: var(--ymrs-primary-soft);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1);
    }

    .ymrs-option input[type="radio"] {
        margin-top: 3px;
        width: 16px;
        height: 16px;
        accent-color: var(--ymrs-primary);
        flex-shrink: 0;
        cursor: pointer;
    }

    .ymrs-option-score {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        height: 24px;
        padding: 0 6px;
        border-radius: 6px;
        background: #f1f5f9;
        color: #334155;
        font-size: 12px;
        font-weight: 800;
        flex-shrink: 0;
        transition: all 0.16s ease;
    }

    .ymrs-option:has(input:checked) .ymrs-option-score {
        background: var(--ymrs-primary);
        color: #fff;
    }

    .ymrs-option-text {
        font-size: 13.5px;
        line-height: 1.45;
        color: #334155;
        font-weight: 500;
    }

    .ymrs-option:has(input:checked) .ymrs-option-text {
        color: #0f172a;
        font-weight: 600;
    }

    .ymrs-score-error {
        display: none;
        color: #dc2626;
        font-size: 12px;
        margin: 10px 0 0;
        font-weight: 700;
    }

    .ymrs-row.error .ymrs-score-error {
        display: block;
    }

    /* Notes Counter */
    .ymrs-notes-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }

    .ymrs-char-count {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--ymrs-muted);
    }

    /* Sticky Bottom Action Bar */
    .ymrs-sticky-footer {
        position: sticky;
        bottom: 14px;
        z-index: 50;
        background: rgba(15, 23, 42, 0.96);
        backdrop-filter: blur(8px);
        color: #fff;
        border-radius: 12px;
        padding: 14px 22px;
        box-shadow: 0 12px 28px -6px rgba(15, 23, 42, 0.28);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }

    .ymrs-footer-left {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }

    .ymrs-footer-total {
        background: var(--ymrs-primary);
        color: #fff;
        padding: 7px 14px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 800;
    }

    .ymrs-footer-meta {
        font-size: 12.5px;
        color: #cbd5e1;
        font-weight: 600;
    }

    .ymrs-footer-meta strong {
        color: #5eead4;
    }

    .ymrs-save-btn {
        background: #10b981;
        border: none;
        color: #fff;
        font-size: 13.5px;
        font-weight: 700;
        padding: 10px 22px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .ymrs-save-btn:hover {
        background: #059669;
        color: #fff;
        transform: translateY(-1px);
    }

    /* Alert Toast */
    .ymrs-toast {
        position: fixed;
        right: 20px;
        top: 20px;
        z-index: 1100;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 13px 18px;
        border-radius: 10px;
        color: #fff;
        font-size: 13.5px;
        font-weight: 600;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.2);
        max-width: 420px;
    }

    .ymrs-toast.success { background: #0f766e; }
    .ymrs-toast.error { background: #dc2626; }

    .ymrs-toast-close {
        background: transparent;
        border: 0;
        color: rgba(255, 255, 255, 0.8);
        font-size: 18px;
        line-height: 1;
        cursor: pointer;
        padding: 0 2px;
    }

    .ymrs-toast-close:hover {
        color: #fff;
    }

    @media (max-width: 900px) {
        .ymrs-header {
            flex-direction: column;
            align-items: stretch;
        }
        .ymrs-score-widget {
            justify-content: space-between;
        }
    }

    @media (max-width: 767px) {
        .ymrs-options {
            grid-template-columns: 1fr;
        }
        .ymrs-page {
            padding: 16px 12px 32px;
        }
        .ymrs-row {
            padding: 16px;
        }
        .ymrs-sticky-footer {
            flex-direction: column;
            align-items: stretch;
        }
        .ymrs-save-btn {
            width: 100%;
        }
    }
</style>

<div class="ymrs-page">
    <?php if ($success_msg): ?>
        <div class="ymrs-toast success" role="status" id="ymrsToast">
            <span><strong>Success!</strong> <?php echo ymrs_escape($success_msg); ?></span>
            <button type="button" class="ymrs-toast-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="ymrs-toast error" role="alert" id="ymrsToast">
            <span><strong>Error!</strong> <?php echo ymrs_escape($error_msg); ?></span>
            <button type="button" class="ymrs-toast-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Header Banner -->
    <header class="ymrs-header">
        <div class="ymrs-header-info">
            <div class="ymrs-eyebrow">Clinical Rating Scale</div>
            <h1>Young Mania Rating Scale (YMRS)</h1>
            <p>11-item clinician-rated mania assessment using the standard YMRS scoring guide.</p>
        </div>
        <div class="ymrs-score-widget" aria-live="polite">
            <div class="ymrs-score-metric">
                <small>Live Total</small>
                <div class="ymrs-score-line">
                    <strong id="liveTotal">0</strong>
                    <span>/60</span>
                </div>
            </div>
            <div class="ymrs-score-meta">
                <small>Severity &amp; Progress</small>
                <span class="ymrs-severity-pill normal" id="liveSeverity">Normal / Remission</span>
                <span class="ymrs-progress-text"><span id="ratedCount">0</span> of <?php echo (int)$total_items_count; ?> items rated</span>
            </div>
        </div>
    </header>

    <form method="post" action="ymrs-assessments.php" id="ymrsForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo ymrs_escape($_SESSION['ymrs_csrf_token']); ?>">

        <!-- Patient & Demographics Card -->
        <section class="ymrs-section">
            <div class="ymrs-section-head">
                <h2>Patient &amp; Assessment Details</h2>
            </div>
            <div class="ymrs-section-body">
                <div class="row g-3 mb-2">
                    <div class="col-md-4">
                        <label class="form-label" for="ymrs_patient_id">Patient *</label>
                        <select name="patient_id" id="ymrs_patient_id" class="form-select" required>
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($patients as $p):
                                $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: ($p['email'] ?? 'Patient');
                            ?>
                                <option value="<?php echo (int)$p['id']; ?>" <?php echo $form_patient_id == (int)$p['id'] ? 'selected' : ''; ?>>
                                    <?php echo ymrs_escape($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="ymrs_assessment_date">Assessment Date *</label>
                        <input type="date" name="assessment_date" id="ymrs_assessment_date" class="form-control" value="<?php echo ymrs_escape($form_date); ?>" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="ymrs_clinician">Clinician</label>
                        <input type="text" id="ymrs_clinician" class="form-control bg-light" value="<?php echo ymrs_escape($clinician_name); ?>" readonly>
                    </div>
                </div>
                <?php
                $demographics['date'] = $demographics['date'] ?: $form_date;
                $demographics['filled_by'] = $demographics['filled_by'] ?: $clinician_name;
                echo assessment_demographics_html('demographics', $demographics, ['hide_fields' => ['date', 'client_name']]);
                ?>
            </div>
        </section>

        <!-- 11 Assessment Items Card -->
        <section class="ymrs-section">
            <div class="ymrs-section-head">
                <h2>Assessment Items (<?php echo (int)$total_items_count; ?>)</h2>
                <span class="ymrs-item-badge" id="cardProgressBadge">0 / <?php echo (int)$total_items_count; ?> Completed</span>
            </div>
            <div class="ymrs-progress-track">
                <div class="ymrs-progress-fill" id="progressFill"></div>
            </div>

            <?php
            $item_index = 0;
            foreach ($ymrs_items as $field => $item):
                $item_index++;
                $has_error = isset($score_errors[$field]);
                $selected_val = (string)($submitted_scores[$field] ?? '');
            ?>
                <div class="ymrs-row<?php echo $has_error ? ' error' : ''; ?>" data-field="<?php echo ymrs_escape($field); ?>">
                    <div class="ymrs-item-header">
                        <div class="ymrs-item-title-wrap">
                            <span class="ymrs-item-num"><?php echo str_pad((string)$item_index, 2, '0', STR_PAD_LEFT); ?></span>
                            <span class="ymrs-item-title"><?php echo ymrs_escape($item['title']); ?></span>
                        </div>
                        <span class="ymrs-item-badge" id="badge_<?php echo ymrs_escape($field); ?>">Not rated</span>
                    </div>

                    <div class="ymrs-options">
                        <?php foreach ($item['ratings'] as $value => $label): ?>
                            <label class="ymrs-option">
                                <input
                                    type="radio"
                                    name="scores[<?php echo ymrs_escape($field); ?>]"
                                    value="<?php echo (int)$value; ?>"
                                    <?php echo ($selected_val !== '' && $selected_val === (string)$value) ? 'checked' : ''; ?>
                                    required
                                >
                                <span class="ymrs-option-score"><?php echo (int)$value; ?></span>
                                <span class="ymrs-option-text"><?php echo ymrs_escape($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <p class="ymrs-score-error">Please select a rating for this item.</p>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- Clinical Notes Card -->
        <section class="ymrs-section">
            <div class="ymrs-section-head">
                <h2>Clinical Notes &amp; Observations</h2>
            </div>
            <div class="ymrs-section-body">
                <div class="ymrs-notes-head">
                    <label class="form-label mb-0" for="ymrs_clinical_notes">Examiner Notes (Optional)</label>
                    <span class="ymrs-char-count"><span id="notesCharCount">0</span> / 5,000</span>
                </div>
                <textarea
                    name="clinical_notes"
                    id="ymrs_clinical_notes"
                    class="form-control"
                    rows="4"
                    maxlength="5000"
                    placeholder="Enter clinical observations, behavioral notes, or context for the rating..."
                ><?php echo ymrs_escape($form_notes); ?></textarea>
            </div>
        </section>

        <!-- Sticky Footer Action Bar -->
        <div class="ymrs-sticky-footer" aria-live="polite">
            <div class="ymrs-footer-left">
                <div class="ymrs-footer-total">Total: <span id="footerTotal">0</span> / 60</div>
                <div class="ymrs-footer-meta">
                    Severity: <strong id="footerSeverity">Normal / Remission</strong>
                    &nbsp;&bull;&nbsp;
                    Rated: <strong id="footerRated">0</strong> / <?php echo (int)$total_items_count; ?>
                </div>
            </div>
            <button type="submit" name="submit_ymrs" value="1" class="ymrs-save-btn">
                Save YMRS Assessment
            </button>
        </div>
    </form>
</div>

<script>
    (function() {
        var fields = <?php echo json_encode(array_keys($ymrs_items)); ?>;
        var totalItems = fields.length;

        function getSeverity(total) {
            if (total >= 26) return { label: 'Severe Mania', cls: 'severe' };
            if (total >= 20) return { label: 'Moderate Mania', cls: 'moderate' };
            if (total >= 13) return { label: 'Minimal / Mild', cls: 'mild' };
            return { label: 'Normal / Remission', cls: 'normal' };
        }

        function update() {
            var total = 0;
            var rated = 0;

            fields.forEach(function(field) {
                var checked = document.querySelector('input[name="scores[' + field + ']"]:checked');
                var row = document.querySelector('.ymrs-row[data-field="' + field + '"]');
                var badge = document.getElementById('badge_' + field);

                if (checked) {
                    var value = Number(checked.value);
                    total += value;
                    rated++;
                    if (badge) badge.textContent = 'Score: ' + value;
                    if (row) {
                        row.classList.add('is-answered');
                        row.classList.remove('error');
                    }
                } else {
                    if (badge) badge.textContent = 'Not rated';
                    if (row) row.classList.remove('is-answered');
                }
            });

            var severity = getSeverity(total);
            var pct = totalItems > 0 ? Math.round((rated / totalItems) * 100) : 0;

            document.getElementById('liveTotal').textContent = total;
            document.getElementById('footerTotal').textContent = total;
            document.getElementById('ratedCount').textContent = rated;
            document.getElementById('footerRated').textContent = rated;

            var cardBadge = document.getElementById('cardProgressBadge');
            if (cardBadge) cardBadge.textContent = rated + ' / ' + totalItems + ' Completed';

            var progressFill = document.getElementById('progressFill');
            if (progressFill) progressFill.style.width = pct + '%';

            var liveSeverity = document.getElementById('liveSeverity');
            if (liveSeverity) {
                liveSeverity.textContent = severity.label;
                liveSeverity.className = 'ymrs-severity-pill ' + severity.cls;
            }

            var footerSeverity = document.getElementById('footerSeverity');
            if (footerSeverity) footerSeverity.textContent = severity.label;
        }

        document.querySelectorAll('#ymrsForm input[type="radio"]').forEach(function(radio) {
            radio.addEventListener('change', update);
        });

        // Clinical notes character counter
        var notesEl = document.getElementById('ymrs_clinical_notes');
        var notesCountEl = document.getElementById('notesCharCount');
        function updateNotesCount() {
            if (notesEl && notesCountEl) {
                notesCountEl.textContent = notesEl.value.length.toLocaleString();
            }
        }
        if (notesEl) {
            notesEl.addEventListener('input', updateNotesCount);
            updateNotesCount();
        }

        // Auto-hide toast after 5 seconds
        var toast = document.getElementById('ymrsToast');
        if (toast) {
            setTimeout(function() {
                toast.style.transition = 'opacity 0.3s ease';
                toast.style.opacity = '0';
                setTimeout(function() { toast.remove(); }, 300);
            }, 5000);
        }

        // Smooth scroll to first error row if validation failed
        var firstError = document.querySelector('.ymrs-row.error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        update();
    })();
</script>
<?php require 'layout/footer.php'; ?>