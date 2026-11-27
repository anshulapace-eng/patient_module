<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'assessment-demographics.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

function bprs_score_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$page_title = 'BPRS Score Report - Vimhans Academic';
$record = null;
$load_error = '';
$assessment_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

$items = [
    'q1_somatic_concern' => 'Somatic concern',
    'q2_anxiety' => 'Anxiety',
    'q3_emotional_withdrawal' => 'Emotional withdrawal',
    'q4_conceptual_disorganization' => 'Conceptual disorganization',
    'q5_guilt_feelings' => 'Guilt feelings',
    'q6_tension' => 'Tension',
    'q7_mannerisms_posturing' => 'Mannerisms and posturing',
    'q8_grandiosity' => 'Grandiosity',
    'q9_depressive_mood' => 'Depressive mood',
    'q10_hostility' => 'Hostility',
    'q11_suspiciousness' => 'Suspiciousness',
    'q12_hallucinatory_behavior' => 'Hallucinatory behavior',
    'q13_motor_retardation' => 'Motor retardation',
    'q14_uncooperativeness' => 'Uncooperativeness',
    'q15_unusual_thought_content' => 'Unusual thought content',
    'q16_blunted_affect' => 'Blunted affect',
    'q17_excitement' => 'Excitement',
    'q18_disorientation' => 'Disorientation',
];

$rating_labels = [
    0 => 'Not assessed',
    1 => 'Not present',
    2 => 'Very mild',
    3 => 'Mild',
    4 => 'Moderate',
    5 => 'Moderately severe',
    6 => 'Severe',
    7 => 'Extremely severe',
];

if ($assessment_id === false || $assessment_id < 1) {
    http_response_code(400);
    $load_error = 'The assessment link is invalid.';
} else {
    $stmt = $conn->prepare(
        'SELECT b.id, b.assessment_date, b.total_score, b.score_data, b.clinical_notes, b.demographic_data,
                TRIM(CONCAT(COALESCE(p.first_name, \'\'), \' \', COALESCE(NULLIF(p.last_name, \'.\'), \'\'))) AS patient_name,
                TRIM(CONCAT(COALESCE(c.first_name, \'\'), \' \', COALESCE(NULLIF(c.last_name, \'.\'), \'\'))) AS clinician_name
         FROM bprs_assessments b
         INNER JOIN users p ON p.id = b.patient_id
             AND p.role_id = 2
             AND p.patient_id = ' . PATIENT_SCOPE_ID . '
         LEFT JOIN users c ON c.id = b.clinician_id
         WHERE b.id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        error_log('BPRS score report prepare failed: ' . $conn->error);
        http_response_code(500);
        $load_error = 'The score report could not be loaded. Please try again later.';
    } else {
        $stmt->bind_param('i', $assessment_id);
        if (!$stmt->execute()) {
            error_log('BPRS score report query failed: ' . $stmt->error);
            http_response_code(500);
            $load_error = 'The score report could not be loaded. Please try again later.';
        } else {
            $result = $stmt->get_result();
            $record = $result->fetch_assoc() ?: null;
            if (!$record) {
                http_response_code(404);
                $load_error = 'This assessment record was not found.';
            }
        }
        $stmt->close();
    }
}

$scores = [];
$demographics = [];
$total_score = 0;
$patient_name = '';
$clinician_name = '';
$notes = '';

if ($record) {
    $saved_scores = json_decode((string) $record['score_data'], true);
    $saved_scores = is_array($saved_scores) ? $saved_scores : [];
    foreach ($items as $field => $label) {
        $score = isset($saved_scores[$field]) && is_numeric($saved_scores[$field])
            ? max(0, min(7, (int) $saved_scores[$field]))
            : 0;
        $scores[] = [
            'label' => $label,
            'score' => $score,
            'rating_label' => $rating_labels[$score] ?? 'Not assessed',
        ];
    }

    $total_score = (int) $record['total_score'];
    $patient_name = trim($record['patient_name']) ?: 'Unknown patient';
    $clinician_name = trim($record['clinician_name']) ?: 'Unknown clinician';
    $notes = (string) ($record['clinical_notes'] ?? '');

    $demo_decoded = json_decode((string) ($record['demographic_data'] ?? ''), true);
    $normalized_demo = assessment_demographics_normalize(is_array($demo_decoded) ? $demo_decoded : []);
    foreach ($normalized_demo as $dk => $dv) {
        if (in_array($dk, ['date', 'client_name'], true)) {
            continue;
        }
        $val_str = is_array($dv) ? implode(', ', array_filter($dv)) : trim((string) $dv);
        $demographics[ucwords(str_replace('_', ' ', (string) $dk))] = $val_str !== '' ? $val_str : '—';
    }
}

$clamped_total = max(0, min(126, $total_score));
$chart_remaining = 126 - $clamped_total;
$score_pct = (int) round(($clamped_total / 126) * 100);

require 'layout/base.php';
?>
<style>
    :root {
        --br-primary: #0f766e;
        --br-primary-dark: #115e59;
        --br-primary-soft: #f0fdfa;
        --br-border: #e2e8f0;
        --br-border-soft: #f1f5f9;
        --br-text: #0f172a;
        --br-muted: #64748b;
        --br-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .bprs-report {
        max-width: 1180px;
        margin: 0 auto;
        padding: 24px 18px 48px;
        color: var(--br-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Top Action Bar */
    .bprs-report-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 18px;
    }

    .bprs-btn-back,
    .bprs-btn-print {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 16px;
        border-radius: 9px;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.16s ease;
    }

    .bprs-btn-back {
        background: #fff;
        color: #334155;
        border: 1px solid #cbd5e1;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }

    .bprs-btn-back:hover {
        background: #f8fafc;
        color: var(--br-text);
        border-color: #94a3b8;
    }

    .bprs-btn-print {
        background: var(--br-primary);
        color: #fff;
        border: 1px solid var(--br-primary);
        box-shadow: 0 2px 8px rgba(15, 118, 110, 0.22);
    }

    .bprs-btn-print:hover {
        background: var(--br-primary-dark);
        border-color: var(--br-primary-dark);
        transform: translateY(-1px);
    }

    /* Hero Banner */
    .bprs-hero {
        position: relative;
        overflow: hidden;
        padding: 28px 30px;
        border-radius: 16px;
        background: linear-gradient(135deg, #0f3f3c 0%, #0f766e 58%, #14b8a6 100%);
        color: #fff;
        box-shadow: 0 12px 30px -8px rgba(15, 118, 110, 0.35);
    }

    .bprs-hero::after {
        content: "";
        position: absolute;
        right: -60px;
        top: -60px;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.14) 0%, rgba(255, 255, 255, 0) 70%);
        pointer-events: none;
    }

    .bprs-hero-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }

    .bprs-hero-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #ccfbf1;
        background: rgba(255, 255, 255, 0.12);
        padding: 4px 11px;
        border-radius: 999px;
        margin-bottom: 10px;
    }

    .bprs-hero h1 {
        margin: 0;
        font-size: 28px;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #fff;
    }

    .bprs-hero-sub {
        margin: 6px 0 0;
        font-size: 13.5px;
        color: #ccfbf1;
    }

    .bprs-total-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 15px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        border: 1px solid rgba(255, 255, 255, 0.28);
        color: #fff;
        font-size: 13px;
        font-weight: 800;
    }

    .bprs-meta-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-top: 22px;
        position: relative;
        z-index: 1;
    }

    .bprs-meta-box {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 10px;
        padding: 11px 14px;
        backdrop-filter: blur(4px);
    }

    .bprs-meta-box span {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #ccfbf1;
    }

    .bprs-meta-box strong {
        display: block;
        margin-top: 4px;
        font-size: 14.5px;
        font-weight: 700;
        color: #fff;
    }

    /* Stacked Full-Width Sections */
    .bprs-section-stack {
        display: flex;
        flex-direction: column;
        gap: 20px;
        margin-top: 20px;
    }

    .bprs-card {
        background: #fff;
        border: 1px solid var(--br-border);
        border-radius: 14px;
        box-shadow: var(--br-shadow);
        overflow: hidden;
    }

    .bprs-card-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--br-border);
        border-left: 4px solid var(--br-primary);
        padding: 13px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .bprs-card-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--br-text);
    }

    .bprs-card-body {
        padding: 20px 22px;
    }

    /* Demographics Grid */
    .bprs-demo-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .bprs-demo-item {
        background: #f8fafc;
        border: 1px solid var(--br-border-soft);
        border-radius: 10px;
        padding: 10px 14px;
    }

    .bprs-demo-item span {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--br-muted);
    }

    .bprs-demo-item strong {
        display: block;
        margin-top: 3px;
        font-size: 13.5px;
        color: var(--br-text);
        overflow-wrap: anywhere;
    }

    /* Full-Width Overall Score Row */
    .bprs-overview-inner {
        display: grid;
        grid-template-columns: 420px minmax(0, 1fr);
        gap: 28px;
        align-items: center;
    }

    .bprs-chart-wrap {
        position: relative;
        width: 100%;
        height: 245px;
        margin: 0 auto;
    }

    .bprs-stats-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .bprs-stat-box {
        background: #f8fafc;
        border: 1px solid var(--br-border-soft);
        border-radius: 10px;
        padding: 14px 16px;
    }

    .bprs-stat-box span {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--br-muted);
    }

    .bprs-stat-box strong {
        display: block;
        margin-top: 4px;
        font-size: 20px;
        font-weight: 800;
        color: var(--br-text);
    }

    /* Question-Wise Q&A Table */
    .bprs-qa-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .bprs-qa-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 12px 14px;
        border-bottom: 1px solid var(--br-border);
        text-align: left;
    }

    .bprs-qa-table tbody td {
        padding: 12px 14px;
        border-bottom: 1px solid var(--br-border-soft);
        vertical-align: middle;
    }

    .bprs-qa-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .bprs-qa-table tbody tr:hover {
        background: #fcfefd;
    }

    .bprs-item-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 7px;
        background: #f1f5f9;
        color: #475569;
        font-size: 11.5px;
        font-weight: 800;
    }

    .bprs-item-title {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--br-text);
    }

    .bprs-track {
        height: 6px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
        margin-top: 6px;
        max-width: 180px;
    }

    .bprs-fill {
        display: block;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #0f766e, #14b8a6);
    }

    .bprs-item-score {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 11px;
        border-radius: 999px;
        background: var(--br-primary-soft);
        color: var(--br-primary);
        border: 1px solid #99f6e4;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .bprs-item-score.zero {
        background: #f8fafc;
        color: #64748b;
        border-color: #e2e8f0;
    }

    /* Clinical Notes */
    .bprs-notes-text {
        margin: 0;
        white-space: pre-wrap;
        font-size: 14px;
        line-height: 1.65;
        color: #334155;
        background: #f8fafc;
        border: 1px solid var(--br-border-soft);
        border-left: 3px solid var(--br-primary);
        border-radius: 8px;
        padding: 14px 16px;
        overflow-wrap: anywhere;
    }

    /* Simple Invoice-Style Signature Footer */
    .report-sign-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 40px;
        margin-top: 32px;
        padding: 24px 8px 8px;
        border-top: 1px solid #e2e8f0;
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .report-sign-block {
        width: 240px;
        text-align: center;
    }

    .report-sign-line {
        border-top: 1px solid #334155;
        margin-top: 52px;
        padding-top: 8px;
    }

    .report-sign-title {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
    }

    .report-sign-sub {
        font-size: 11.5px;
        color: #64748b;
        margin-top: 2px;
    }

    .bprs-empty {
        padding: 36px 24px;
        text-align: center;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 14px;
        color: #991b1b;
        font-weight: 600;
    }

    @media (max-width: 960px) {
        .bprs-meta-grid,
        .bprs-overview-inner {
            grid-template-columns: 1fr;
        }
        .bprs-demo-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 600px) {
        .bprs-report {
            padding: 16px 12px 32px;
        }
        .bprs-hero {
            padding: 22px 18px;
        }
        .bprs-hero h1 {
            font-size: 22px;
        }
        .bprs-demo-grid,
        .bprs-stats-grid {
            grid-template-columns: 1fr;
        }
        .report-sign-footer {
            flex-direction: column;
            align-items: stretch;
            gap: 28px;
        }
        .report-sign-block {
            width: 100%;
        }
    }

    @media print {
        body {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            background: #fff !important;
        }
        .bprs-report {
            max-width: 100%;
            padding: 0;
        }
        .bprs-report-top,
        .admin-sidebar,
        .admin-navbar,
        .sidebar-backdrop {
            display: none !important;
        }
        .bprs-hero,
        .bprs-overview-card,
        .bprs-qa-table tbody tr {
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .bprs-card {
            box-shadow: none !important;
            overflow: visible !important;
            break-inside: auto !important;
            page-break-inside: auto !important;
        }
        .bprs-card-head {
            break-after: avoid;
            page-break-after: avoid;
        }
    }
</style>

<div class="bprs-report">
    <div class="bprs-report-top">
        <a class="bprs-btn-back" href="bprs-assessment-records.php">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Records
        </a>
        <?php if ($record): ?>
            <button type="button" class="bprs-btn-print" onclick="window.print()">
                <i class="bi bi-printer" aria-hidden="true"></i> Print Report
            </button>
        <?php endif; ?>
    </div>

    <?php if (!$record): ?>
        <div class="bprs-empty" role="alert"><?php echo bprs_score_escape($load_error); ?></div>
    <?php else: ?>
        <!-- Hero Header -->
        <header class="bprs-hero">
            <div class="bprs-hero-top">
                <div>
                    <div class="bprs-hero-eyebrow">Clinical Assessment Report</div>
                    <h1>BPRS Score Overview</h1>
                    <p class="bprs-hero-sub">Brief Psychiatric Rating Scale &middot; 18-Item Clinician-Rated Assessment</p>
                </div>
                <div>
                    <span class="bprs-total-pill">Total Score: <?php echo (int) $total_score; ?> / 126</span>
                </div>
            </div>

            <div class="bprs-meta-grid">
                <div class="bprs-meta-box">
                    <span>Patient Name</span>
                    <strong><?php echo bprs_score_escape($patient_name); ?></strong>
                </div>
                <div class="bprs-meta-box">
                    <span>Assessment Date</span>
                    <strong><?php echo bprs_score_escape(date('d M Y', strtotime($record['assessment_date']))); ?></strong>
                </div>
                <div class="bprs-meta-box">
                    <span>Evaluating Clinician</span>
                    <strong><?php echo bprs_score_escape($clinician_name); ?></strong>
                </div>
            </div>
        </header>

        <div class="bprs-section-stack">
            <!-- Patient Demographics -->
            <?php if (!empty($demographics)): ?>
                <section class="bprs-card">
                    <div class="bprs-card-head">
                        <h2>Patient Demographic Details</h2>
                    </div>
                    <div class="bprs-card-body">
                        <div class="bprs-demo-grid">
                            <?php foreach ($demographics as $demo_label => $demo_val): ?>
                                <div class="bprs-demo-item">
                                    <span><?php echo bprs_score_escape($demo_label); ?></span>
                                    <strong><?php echo bprs_score_escape($demo_val); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Full-Width Overall Score -->
            <section class="bprs-card bprs-overview-card">
                <div class="bprs-card-head">
                    <h2>Overall Score</h2>
                </div>
                <div class="bprs-card-body">
                    <div class="bprs-overview-inner">
                        <div class="bprs-chart-wrap">
                            <canvas id="bprsScoreChart" role="img" aria-label="BPRS total score <?php echo $total_score; ?> out of 126"></canvas>
                        </div>

                        <div class="bprs-stats-grid">
                            <div class="bprs-stat-box">
                                <span>Total BPRS Score</span>
                                <strong><?php echo (int) $total_score; ?> / 126</strong>
                            </div>
                            <div class="bprs-stat-box">
                                <span>Score Percentage</span>
                                <strong><?php echo $score_pct; ?>%</strong>
                            </div>
                            <div class="bprs-stat-box">
                                <span>Items Rated</span>
                                <strong>18 Items</strong>
                            </div>
                            <div class="bprs-stat-box">
                                <span>Rating Scale Range</span>
                                <strong>1 – 7 per item</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Full-Width Question-Wise Answers & Scores Table -->
            <section class="bprs-card">
                <div class="bprs-card-head">
                    <h2>Question-Wise Answers &amp; Scores (<?php echo count($scores); ?>)</h2>
                </div>
                <div class="table-responsive">
                    <table class="bprs-qa-table">
                        <thead>
                            <tr>
                                <th style="width: 56px;">#</th>
                                <th style="width: 38%;">Symptom / Item</th>
                                <th>Selected Rating</th>
                                <th style="width: 105px; text-align: right;">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scores as $index => $item):
                                $item_pct = min(100, round(($item['score'] / 7) * 100));
                            ?>
                                <tr>
                                    <td>
                                        <span class="bprs-item-num"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                                    </td>
                                    <td>
                                        <div class="bprs-item-title"><?php echo bprs_score_escape($item['label']); ?></div>
                                        <div class="bprs-track">
                                            <span class="bprs-fill" style="width: <?php echo $item_pct; ?>%;"></span>
                                        </div>
                                    </td>
                                    <td style="color: #334155; line-height: 1.45;">
                                        <?php echo bprs_score_escape($item['rating_label']); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <span class="bprs-item-score<?php echo $item['score'] <= 1 ? ' zero' : ''; ?>">
                                            <?php echo (int) $item['score']; ?> / 7
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Clinical Notes -->
            <?php if (trim($notes) !== ''): ?>
                <section class="bprs-card">
                    <div class="bprs-card-head">
                        <h2>Clinical Notes &amp; Observations</h2>
                    </div>
                    <div class="bprs-card-body">
                        <p class="bprs-notes-text"><?php echo bprs_score_escape($notes); ?></p>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Simple Invoice-Style Signature Footer -->
            <div class="report-sign-footer">
                <div class="report-sign-block">
                    <div class="report-sign-line">
                        <div class="report-sign-title"><?php echo bprs_score_escape($clinician_name); ?></div>
                        <div class="report-sign-sub">Evaluating Clinician</div>
                    </div>
                </div>
                <div class="report-sign-block">
                    <div class="report-sign-line">
                        <div class="report-sign-title">Authorized Signatory</div>
                        <div class="report-sign-sub">Clinical Supervisor / Stamp</div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('bprsScoreChart');
    if (!canvas) return;

    var totalScore = <?php echo $clamped_total; ?>;
    var remainingScore = <?php echo $chart_remaining; ?>;
    var scorePct = <?php echo $score_pct; ?>;

    var bprsCalloutAndCenter = {
        id: 'bprsCalloutAndCenter',
        afterDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            var meta = chart.getDatasetMeta(0);
            var data = chart.data.datasets[0].data;
            var labels = chart.data.labels;
            if (!meta.data || !meta.data.length) return;

            ctx.save();

            // 1. Exact center me Total Score + Percentage
            var centerX = meta.data[0].x;
            var centerY = meta.data[0].y;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            ctx.fillStyle = '#0f172a';
            ctx.font = '900 32px system-ui, -apple-system, sans-serif';
            ctx.fillText(String(totalScore), centerX, centerY - 14);

            ctx.fillStyle = '#64748b';
            ctx.font = '700 12px system-ui, -apple-system, sans-serif';
            ctx.fillText('out of 126', centerX, centerY + 10);

            ctx.fillStyle = '#0f766e';
            ctx.font = '800 11px system-ui, -apple-system, sans-serif';
            ctx.fillText(scorePct + '% of max', centerX, centerY + 27);

            // 2. Har slice ke bagal me Name + Score
            ctx.font = '700 11px system-ui, -apple-system, sans-serif';
            meta.data.forEach(function (arc, index) {
                var val = data[index];
                if (!val || val <= 0) return;

                var angle = (arc.startAngle + arc.endAngle) / 2;
                var cos = Math.cos(angle);
                var sin = Math.sin(angle);
                var isRight = cos >= 0;

                var x1 = arc.x + cos * (arc.outerRadius + 2);
                var y1 = arc.y + sin * (arc.outerRadius + 2);
                var x2 = arc.x + cos * (arc.outerRadius + 12);
                var y2 = arc.y + sin * (arc.outerRadius + 12);
                var x3 = x2 + (isRight ? 10 : -10);

                ctx.beginPath();
                ctx.moveTo(x1, y1);
                ctx.lineTo(x2, y2);
                ctx.lineTo(x3, y2);
                ctx.strokeStyle = index === 0 ? '#0f766e' : '#64748b';
                ctx.lineWidth = index === 0 ? 1.6 : 1.1;
                ctx.stroke();

                var textX = x3 + (isRight ? 5 : -5);
                ctx.textAlign = isRight ? 'left' : 'right';
                ctx.fillStyle = '#0f172a';
                ctx.fillText(labels[index], textX, y2 - 6);

                ctx.fillStyle = '#64748b';
                ctx.font = '600 10.5px system-ui, -apple-system, sans-serif';
                ctx.fillText('(' + val + ' / 126)', textX, y2 + 7);
                ctx.font = '700 11px system-ui, -apple-system, sans-serif';
            });

            ctx.restore();
        }
    };

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['BPRS Score', 'Remaining'],
            datasets: [{
                data: [totalScore, remainingScore],
                backgroundColor: ['#0f766e', '#e2e8f0'],
                borderColor: '#ffffff',
                borderWidth: 2,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '66%',
            layout: {
                padding: { top: 20, bottom: 20, left: 105, right: 105 }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return ' ' + context.label + ': ' + context.raw + ' / 126';
                        }
                    }
                }
            }
        },
        plugins: [bprsCalloutAndCenter]
    });
});
</script>
<?php require 'layout/footer.php'; ?>