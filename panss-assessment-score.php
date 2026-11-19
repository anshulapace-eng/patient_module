<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'panss-assessment-items.php';
require 'panss-assessment-schema.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

function panss_score_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function panss_rating_class($rating)
{
    if ($rating >= 5) return 'is-high';
    if ($rating >= 3) return 'is-mid';
    if ($rating >= 1) return 'is-low';
    return 'is-empty';
}

$page_title = 'PANSS Score Report - Vimhans Academic';
$record = null;
$load_error = '';
$assessment_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($assessment_id === false || $assessment_id < 1) {
    http_response_code(400);
    $load_error = 'The assessment link is invalid.';
} elseif (!$panss_schema_ready) {
    http_response_code(500);
    $load_error = 'Assessment records are unavailable. Please try again later.';
} else {
    $stmt = $conn->prepare(
        'SELECT p.id, p.assessment_date, p.positive_score, p.negative_score, p.general_score,
                p.composite_score, p.score_data, p.clinical_notes,
                TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(NULLIF(u.last_name, \'.\'), \'\'))) AS patient_name,
                TRIM(CONCAT(COALESCE(c.first_name, \'\'), \' \', COALESCE(NULLIF(c.last_name, \'.\'), \'\'))) AS clinician_name
         FROM panss_assessments p
         INNER JOIN users u ON u.id = p.patient_id
             AND u.role_id = 2
             AND u.patient_id = ' . PATIENT_SCOPE_ID . '
         LEFT JOIN users c ON c.id = p.clinician_id
         WHERE p.id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        error_log('PANSS score report prepare failed: ' . $conn->error);
        http_response_code(500);
        $load_error = 'The score report could not be loaded. Please try again later.';
    } else {
        $stmt->bind_param('i', $assessment_id);
        if (!$stmt->execute()) {
            error_log('PANSS score report query failed: ' . $stmt->error);
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

$patient_name = '';
$clinician_name = '';
$notes = '';
$domain_scores = [];
$all_scores = [];
$score_data = [];
$composite_score = 0;
$combined_total = 0;
$chart_colors = ['#4f46e5', '#0ea5e9', '#0f766e'];

if ($record) {
    $decoded_scores = json_decode($record['score_data'], true);
    $score_data = is_array($decoded_scores) ? $decoded_scores : [];
    $patient_name = trim($record['patient_name']) ?: 'Unknown patient';
    $clinician_name = trim($record['clinician_name']) ?: 'Unknown clinician';
    $notes = (string) ($record['clinical_notes'] ?? '');
    $composite_score = (int) $record['composite_score'];

    foreach ($panss_sections as $prefix => $section) {
        if ($prefix === 'P') {
            $score = (int) $record['positive_score'];
            $max_score = 49;
        } elseif ($prefix === 'N') {
            $score = (int) $record['negative_score'];
            $max_score = 49;
        } else {
            $score = (int) $record['general_score'];
            $max_score = 112;
        }

        $safe_score = max(0, $score);
        $combined_total += $safe_score;

        $domain_scores[] = [
            'prefix' => $prefix,
            'title' => $section['title'],
            'score' => $safe_score,
            'max' => $max_score,
        ];

        foreach ($panss_items[$prefix] as $code => $label) {
            $rating = isset($score_data[$code]) && is_numeric($score_data[$code])
                ? max(1, min(7, (int) $score_data[$code]))
                : 0;
            $all_scores[$prefix][] = [
                'code' => $code,
                'label' => $label,
                'rating' => $rating,
                'rating_label' => $rating > 0 ? ($panss_rating_labels[$rating] ?? 'Rated') : 'Not rated',
            ];
        }
    }
}

require 'layout/base.php';
?>
<style>
    :root {
        --pr-primary: #0f766e;
        --pr-primary-dark: #115e59;
        --pr-primary-soft: #f0fdfa;
        --pr-border: #e2e8f0;
        --pr-border-soft: #f1f5f9;
        --pr-text: #0f172a;
        --pr-muted: #64748b;
        --pr-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .panss-report {
        max-width: 1200px;
        margin: 0 auto;
        padding: 24px 18px 52px;
        color: var(--pr-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Top Action Bar */
    .report-topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 18px;
    }

    .report-back,
    .report-print {
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

    .report-back {
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
    }

    .report-back:hover {
        background: #f8fafc;
        color: var(--pr-text);
        border-color: #94a3b8;
    }

    .report-print {
        border: 1px solid var(--pr-primary);
        background: var(--pr-primary);
        color: #fff;
        box-shadow: 0 2px 8px rgba(15, 118, 110, 0.22);
    }

    .report-print:hover {
        background: var(--pr-primary-dark);
        border-color: var(--pr-primary-dark);
        transform: translateY(-1px);
    }

    /* Executive Hero Banner */
    .report-hero {
        position: relative;
        overflow: hidden;
        padding: 28px 30px;
        border-radius: 16px;
        background: linear-gradient(135deg, #0f3f3c 0%, #0f766e 55%, #0891b2 100%);
        color: #fff;
        box-shadow: 0 14px 32px -8px rgba(15, 118, 110, 0.32);
    }

    .report-hero::after {
        content: "";
        position: absolute;
        top: -70px;
        right: -50px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.14) 0%, rgba(255, 255, 255, 0) 70%);
        pointer-events: none;
    }

    .hero-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }

    .report-eyebrow {
        display: inline-flex;
        padding: 4px 11px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        color: #ccfbf1;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        margin-bottom: 10px;
    }

    .report-hero h1 {
        margin: 0;
        font-size: clamp(24px, 3.5vw, 32px);
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .report-subtitle {
        margin: 6px 0 0;
        color: #ccfbf1;
        font-size: 13.5px;
    }

    .hero-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }

    .hero-record-id {
        font-size: 12px;
        font-weight: 700;
        color: #ccfbf1;
    }

    .hero-total-pill {
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

    .report-meta {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-top: 22px;
        position: relative;
        z-index: 1;
    }

    .meta-box {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 10px;
        padding: 11px 14px;
    }

    .meta-box span {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #ccfbf1;
    }

    .meta-box strong {
        display: block;
        margin-top: 4px;
        font-size: 14.5px;
        font-weight: 700;
        color: #fff;
    }

    /* 4-Column Subscale KPI Strip */
    .panss-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-top: 20px;
    }

    .panss-kpi {
        background: #fff;
        border: 1px solid var(--pr-border);
        border-radius: 12px;
        padding: 16px 18px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        border-top: 3px solid var(--kpi-accent, var(--pr-primary));
    }

    .panss-kpi-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--pr-muted);
    }

    .panss-kpi-val {
        display: flex;
        align-items: baseline;
        gap: 4px;
        margin-top: 6px;
    }

    .panss-kpi-val strong {
        font-size: 26px;
        font-weight: 800;
        line-height: 1;
        color: var(--pr-text);
    }

    .panss-kpi-val span {
        font-size: 13px;
        font-weight: 700;
        color: #94a3b8;
    }

    .panss-kpi-sub {
        display: block;
        margin-top: 5px;
        font-size: 11.5px;
        color: var(--pr-muted);
    }

    /* Standard Section Card */
    .report-card {
        background: #fff;
        border: 1px solid var(--pr-border);
        border-radius: 14px;
        box-shadow: var(--pr-shadow);
        overflow: hidden;
    }

    .card-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--pr-border);
        border-left: 4px solid var(--pr-primary);
        padding: 13px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .card-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--pr-text);
    }

    .card-head-pill {
        background: var(--pr-primary-soft);
        color: var(--pr-primary);
        border: 1px solid #99f6e4;
        padding: 3px 11px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 800;
        white-space: nowrap;
    }

    .card-body-pad {
        padding: 20px 22px;
    }

    /* Overview Grid (Chart + Domain Bars) */
    .overview-grid {
        display: grid;
        grid-template-columns: 360px minmax(0, 1fr);
        gap: 20px;
        margin-top: 20px;
        align-items: stretch;
    }

    .score-chart-wrap {
        position: relative;
        width: 100%;
        max-width: 260px;
        height: 220px;
        margin: 0 auto 12px;
    }

    .chart-center {
        position: absolute;
        top: 43%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        pointer-events: none;
    }

    .chart-center strong {
        display: block;
        font-size: 32px;
        font-weight: 900;
        line-height: 1;
        color: var(--pr-text);
    }

    .chart-center span {
        font-size: 12px;
        font-weight: 700;
        color: var(--pr-muted);
    }

    /* Domain Distribution Bars */
    .domain-bars {
        display: grid;
        gap: 16px;
    }

    .domain-bar-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
        font-size: 13px;
    }

    .domain-bar-name {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
        color: #334155;
    }

    .domain-swatch {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        flex-shrink: 0;
    }

    .domain-track {
        height: 9px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
    }

    .domain-fill {
        display: block;
        height: 100%;
        border-radius: 999px;
    }

    /* Composite Highlight Box */
    .composite-box {
        margin-top: 20px;
        padding: 15px 18px;
        border-radius: 12px;
        background: #f8fafc;
        border: 1px solid var(--pr-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }

    .composite-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .composite-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: var(--pr-primary-soft);
        color: var(--pr-primary);
        display: grid;
        place-items: center;
        font-size: 18px;
        flex-shrink: 0;
    }

    .composite-title {
        font-size: 14px;
        font-weight: 800;
        color: var(--pr-text);
    }

    .composite-formula {
        font-size: 12px;
        color: var(--pr-muted);
    }

    .composite-val {
        min-width: 76px;
        padding: 7px 14px;
        border-radius: 10px;
        background: #fff;
        border: 1px solid #cbd5e1;
        font-size: 24px;
        font-weight: 900;
        text-align: center;
        color: var(--pr-text);
    }

    .composite-val.is-positive {
        background: #ecfdf5;
        border-color: #a7f3d0;
        color: #047857;
    }

    .composite-val.is-negative {
        background: #fef2f2;
        border-color: #fecaca;
        color: #b91c1c;
    }

    /* Item Breakdown Section */
    .section-divider {
        margin: 28px 0 14px;
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 12px;
    }

    .section-divider h2 {
        margin: 0;
        font-size: 17px;
        font-weight: 800;
        color: var(--pr-text);
    }

    .section-divider span {
        font-size: 12.5px;
        color: var(--pr-muted);
        font-weight: 600;
    }

    .pn-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .item-list {
        display: grid;
        gap: 11px;
    }

    .item-list-2col {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px 24px;
    }

    .item-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 90px auto;
        align-items: center;
        gap: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--pr-border-soft);
    }

    .item-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .item-info {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .item-code {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 30px;
        height: 24px;
        padding: 0 6px;
        border-radius: 6px;
        background: #f1f5f9;
        color: #334155;
        font-size: 11px;
        font-weight: 800;
        flex-shrink: 0;
    }

    .item-label {
        font-size: 13px;
        font-weight: 600;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .item-track {
        height: 6px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
    }

    .item-fill {
        display: block;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #0f766e, #14b8a6);
    }

    .item-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 2px;
        min-width: 82px;
    }

    .rating-pill {
        display: inline-flex;
        padding: 2px 9px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 800;
        white-space: nowrap;
    }

    .rating-pill.is-low { background: #ecfdf5; color: #047857; }
    .rating-pill.is-mid { background: #fef3c7; color: #92400e; }
    .rating-pill.is-high { background: #fee2e2; color: #b91c1c; }
    .rating-pill.is-empty { background: #f1f5f9; color: #64748b; }

    .rating-text {
        font-size: 10.5px;
        font-weight: 600;
        color: var(--pr-muted);
        white-space: nowrap;
    }

    /* Clinical Notes */
    .notes-card {
        margin-top: 20px;
    }

    .clinical-notes {
        margin: 0;
        padding: 14px 16px;
        background: #f8fafc;
        border: 1px solid var(--pr-border-soft);
        border-left: 3px solid var(--pr-primary);
        border-radius: 8px;
        color: #334155;
        font-size: 13.5px;
        line-height: 1.65;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .report-empty {
        margin: 28px auto;
        max-width: 640px;
        padding: 32px;
        border: 1px solid #fecaca;
        border-radius: 14px;
        background: #fef2f2;
        color: #991b1b;
        text-align: center;
    }

    @media (max-width: 1024px) {
        .panss-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .overview-grid,
        .pn-grid,
        .item-list-2col { grid-template-columns: 1fr; }
    }

    @media (max-width: 700px) {
        .panss-report { padding: 16px 12px 32px; }
        .report-hero { padding: 20px 18px; }
        .report-meta { grid-template-columns: 1fr; }
        .hero-right { align-items: flex-start; }
        .item-row { grid-template-columns: minmax(0, 1fr) auto; }
        .item-track { display: none; }
    }

    @media (max-width: 480px) {
        .panss-kpi-grid { grid-template-columns: 1fr; }
    }

    @media print {
        body {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            background: #fff !important;
        }
        .report-topbar,
        .admin-sidebar,
        .admin-navbar,
        .sidebar-backdrop {
            display: none !important;
        }
        .panss-report {
            max-width: none;
            padding: 0;
        }
        .report-hero,
        .report-card,
        .panss-kpi {
            box-shadow: none !important;
            break-inside: avoid;
        }
    }
</style>

<div class="panss-report">
    <div class="report-topbar">
        <a class="report-back" href="panss-assessment-records.php">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Records
        </a>
        <?php if ($record): ?>
            <button class="report-print" type="button" onclick="window.print()">
                <i class="bi bi-printer" aria-hidden="true"></i> Print Report
            </button>
        <?php endif; ?>
    </div>

    <?php if (!$record): ?>
        <section class="report-empty" role="alert">
            <h1 style="font-size:20px;margin:0 0 8px;">Report Unavailable</h1>
            <p class="mb-0"><?php echo panss_score_escape($load_error); ?></p>
        </section>
    <?php else: ?>
        <!-- HERO HEADER -->
        <header class="report-hero">
            <div class="hero-top">
                <div>
                    <div class="report-eyebrow">Clinical Assessment Report</div>
                    <h1>PANSS Score Overview</h1>
                    <p class="report-subtitle">Positive and Negative Syndrome Scale &middot; 30-Item Clinician-Rated Assessment</p>
                </div>
                <div class="hero-right">
                    <span class="hero-record-id">Record #<?php echo (int) $record['id']; ?></span>
                    <span class="hero-total-pill">Combined Score: <?php echo (int) $combined_total; ?> / 210</span>
                </div>
            </div>

            <div class="report-meta">
                <div class="meta-box">
                    <span>Patient Name</span>
                    <strong><?php echo panss_score_escape($patient_name); ?></strong>
                </div>
                <div class="meta-box">
                    <span>Assessment Date</span>
                    <strong><?php echo panss_score_escape(date('d M Y', strtotime($record['assessment_date']))); ?></strong>
                </div>
                <div class="meta-box">
                    <span>Evaluating Clinician</span>
                    <strong><?php echo panss_score_escape($clinician_name); ?></strong>
                </div>
            </div>
        </header>

        <!-- 4-COLUMN SUBSCALE KPI STRIP -->
        <div class="panss-kpi-grid">
            <div class="panss-kpi" style="--kpi-accent: #4f46e5;">
                <span class="panss-kpi-label">Positive Scale (P)</span>
                <div class="panss-kpi-val">
                    <strong><?php echo (int) $record['positive_score']; ?></strong>
                    <span>/ 49</span>
                </div>
                <span class="panss-kpi-sub">7 items (range 7–49)</span>
            </div>
            <div class="panss-kpi" style="--kpi-accent: #0ea5e9;">
                <span class="panss-kpi-label">Negative Scale (N)</span>
                <div class="panss-kpi-val">
                    <strong><?php echo (int) $record['negative_score']; ?></strong>
                    <span>/ 49</span>
                </div>
                <span class="panss-kpi-sub">7 items (range 7–49)</span>
            </div>
            <div class="panss-kpi" style="--kpi-accent: #0f766e;">
                <span class="panss-kpi-label">General Psychopathology (G)</span>
                <div class="panss-kpi-val">
                    <strong><?php echo (int) $record['general_score']; ?></strong>
                    <span>/ 112</span>
                </div>
                <span class="panss-kpi-sub">16 items (range 16–112)</span>
            </div>
            <div class="panss-kpi" style="--kpi-accent: <?php echo $composite_score < 0 ? '#dc2626' : '#10b981'; ?>;">
                <span class="panss-kpi-label">Composite Score (P − N)</span>
                <div class="panss-kpi-val">
                    <strong><?php echo $composite_score > 0 ? '+' : ''; ?><?php echo $composite_score; ?></strong>
                </div>
                <span class="panss-kpi-sub"><?php echo $composite_score > 0 ? 'Positive-predominant' : ($composite_score < 0 ? 'Negative-predominant' : 'Balanced profile'); ?></span>
            </div>
        </div>

        <!-- OVERVIEW: CHART + DOMAIN BREAKDOWN -->
        <div class="overview-grid">
            <section class="report-card" aria-labelledby="panssSummaryTitle">
                <div class="card-head">
                    <h2 id="panssSummaryTitle">Score Distribution</h2>
                </div>
                <div class="card-body-pad">
                    <div class="score-chart-wrap">
                        <canvas id="panssScoreChart" role="img" aria-label="PANSS Positive, Negative and General scale scores"></canvas>
                        <div class="chart-center">
                            <strong><?php echo (int) $combined_total; ?></strong>
                            <span>/ 210</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="report-card">
                <div class="card-head">
                    <h2>Subscale Performance &amp; Composite Index</h2>
                </div>
                <div class="card-body-pad">
                    <div class="domain-bars">
                        <?php foreach ($domain_scores as $index => $domain):
                            $pct = $domain['max'] > 0 ? min(100, round(($domain['score'] / $domain['max']) * 100)) : 0;
                        ?>
                            <div>
                                <div class="domain-bar-top">
                                    <span class="domain-bar-name">
                                        <span class="domain-swatch" style="background: <?php echo $chart_colors[$index]; ?>;"></span>
                                        <?php echo panss_score_escape($domain['title']); ?>
                                    </span>
                                    <strong><?php echo $domain['score']; ?> / <?php echo $domain['max']; ?> <span class="text-muted fw-normal">(<?php echo $pct; ?>%)</span></strong>
                                </div>
                                <div class="domain-track">
                                    <span class="domain-fill" style="width: <?php echo $pct; ?>%; background: <?php echo $chart_colors[$index]; ?>;"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="composite-box" role="group" aria-label="Composite score <?php echo $composite_score; ?>">
                        <div class="composite-left">
                            <span class="composite-icon" aria-hidden="true"><i class="bi bi-calculator"></i></span>
                            <div>
                                <div class="composite-title">Composite Index</div>
                                <div class="composite-formula">Positive Scale (<?php echo (int) $record['positive_score']; ?>) &minus; Negative Scale (<?php echo (int) $record['negative_score']; ?>)</div>
                            </div>
                        </div>
                        <div class="composite-val <?php echo $composite_score < 0 ? 'is-negative' : ($composite_score > 0 ? 'is-positive' : ''); ?>">
                            <?php echo $composite_score > 0 ? '+' : ''; ?><?php echo $composite_score; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- DETAILED ITEM SCORES -->
        <div class="section-divider">
            <h2>Item-Level Symptom Ratings</h2>
            <span>Rated from 1 (Absent) to 7 (Extreme)</span>
        </div>

        <!-- Positive & Negative Side-by-Side -->
        <div class="pn-grid">
            <?php foreach (['P', 'N'] as $prefix):
                if (!isset($panss_sections[$prefix])) continue;
                $sec_score = $prefix === 'P' ? (int) $record['positive_score'] : (int) $record['negative_score'];
            ?>
                <section class="report-card">
                    <div class="card-head">
                        <h2><?php echo panss_score_escape($panss_sections[$prefix]['title']); ?> (<?php echo count($all_scores[$prefix]); ?>)</h2>
                        <span class="card-head-pill"><?php echo $sec_score; ?> / 49</span>
                    </div>
                    <div class="card-body-pad">
                        <div class="item-list">
                            <?php foreach ($all_scores[$prefix] as $item): ?>
                                <div class="item-row">
                                    <div class="item-info">
                                        <span class="item-code"><?php echo panss_score_escape($item['code']); ?></span>
                                        <span class="item-label" title="<?php echo panss_score_escape($item['label']); ?>"><?php echo panss_score_escape($item['label']); ?></span>
                                    </div>
                                    <div class="item-track" aria-hidden="true">
                                        <span class="item-fill" style="width: <?php echo ($item['rating'] / 7) * 100; ?>%;"></span>
                                    </div>
                                    <div class="item-right">
                                        <span class="rating-pill <?php echo panss_rating_class($item['rating']); ?>"><?php echo $item['rating']; ?> / 7</span>
                                        <span class="rating-text"><?php echo panss_score_escape($item['rating_label']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <!-- General Psychopathology (16 Items in 2 Clean Columns) -->
        <?php if (isset($all_scores['G'])): ?>
            <section class="report-card">
                <div class="card-head">
                    <h2><?php echo panss_score_escape($panss_sections['G']['title'] ?? 'General Psychopathology Scale'); ?> (<?php echo count($all_scores['G']); ?>)</h2>
                    <span class="card-head-pill"><?php echo (int) $record['general_score']; ?> / 112</span>
                </div>
                <div class="card-body-pad">
                    <div class="item-list-2col">
                        <?php foreach ($all_scores['G'] as $item): ?>
                            <div class="item-row">
                                <div class="item-info">
                                    <span class="item-code"><?php echo panss_score_escape($item['code']); ?></span>
                                    <span class="item-label" title="<?php echo panss_score_escape($item['label']); ?>"><?php echo panss_score_escape($item['label']); ?></span>
                                </div>
                                <div class="item-track" aria-hidden="true">
                                    <span class="item-fill" style="width: <?php echo ($item['rating'] / 7) * 100; ?>%;"></span>
                                </div>
                                <div class="item-right">
                                    <span class="rating-pill <?php echo panss_rating_class($item['rating']); ?>"><?php echo $item['rating']; ?> / 7</span>
                                    <span class="rating-text"><?php echo panss_score_escape($item['rating_label']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- CLINICAL NOTES -->
        <?php if (trim($notes) !== ''): ?>
            <section class="report-card notes-card" aria-labelledby="panssNotesTitle">
                <div class="card-head">
                    <h2 id="panssNotesTitle">Clinical Notes &amp; Examiner Observations</h2>
                </div>
                <div class="card-body-pad">
                    <p class="clinical-notes"><?php echo panss_score_escape($notes); ?></p>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var canvas = document.getElementById('panssScoreChart');
        if (!canvas) return;

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($domain_scores, 'title'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($domain_scores, 'score')); ?>,
                    maximums: <?php echo json_encode(array_column($domain_scores, 'max')); ?>,
                    backgroundColor: <?php echo json_encode($chart_colors); ?>,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 12,
                            color: '#475569',
                            font: { size: 11.5, weight: '600' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.label + ': ' + context.raw + ' / ' + context.dataset.maximums[context.dataIndex];
                            }
                        }
                    }
                }
            }
        });
    });
</script>
<?php require 'layout/footer.php'; ?>