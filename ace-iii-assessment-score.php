<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'ace-iii-assessment-schema.php';
require 'assessment-demographics.php';

$current_role = (int) ($_SESSION['role'] ?? 0);
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);

if (!isset($_SESSION['user_login']) || !in_array($current_role, [1, 2], true)) {
    header('Location: login.php');
    exit;
}

function ace_iii_report_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ace_iii_ans($answers, $key, $fallback = 'Not recorded')
{
    $val = isset($answers[$key]) ? trim((string) $answers[$key]) : '';
    if ($val === '') {
        return '<span class="ace-muted-val">' . ace_iii_report_escape($fallback) . '</span>';
    }
    return nl2br(ace_iii_report_escape($val));
}

function ace_iii_score_pill($scores, $key, $max)
{
    if (!isset($scores[$key]) || $scores[$key] === '' || !is_numeric($scores[$key])) {
        return '<span class="ace-item-pill is-empty">— / ' . (int) $max . '</span>';
    }
    $val = (int) $scores[$key];
    $cls = $val >= $max ? 'is-full' : ($val > 0 ? 'is-partial' : 'is-zero');
    return '<span class="ace-item-pill ' . $cls . '">' . $val . ' / ' . (int) $max . '</span>';
}

function ace_iii_valid_drawing($answers, $key)
{
    $val = isset($answers[$key]) && is_string($answers[$key]) ? trim($answers[$key]) : '';
    if ($val !== '' && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $val)) {
        return $val;
    }
    return '';
}

$page_title = 'ACE-III Score Report - Vimhans Academic';
$record = null;
$load_error = '';
$assessment_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($assessment_id === false || $assessment_id < 1) {
    http_response_code(400);
    $load_error = 'The assessment link is invalid.';
} elseif (!$ace_iii_schema_ready) {
    http_response_code(500);
    $load_error = 'Assessment records are unavailable. Please try again later.';
} else {
    $stmt = $conn->prepare(
        "SELECT a.id, a.patient_id, a.assessment_date, a.total_score,
                a.attention_score, a.memory_score, a.fluency_score, a.language_score,
                a.visuospatial_score, a.patient_details, a.response_data, a.clinical_notes,
                a.status, a.demographic_data,
                TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(NULLIF(p.last_name, '.'), ''))) AS patient_name,
                TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
         FROM ace_iii_assessments a
         INNER JOIN users p ON p.id = a.patient_id AND p.role_id = 2 AND p.patient_id = " . PATIENT_SCOPE_ID . "
         LEFT JOIN users c ON c.id = a.clinician_id
         WHERE a.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        error_log('ACE-III score report prepare failed: ' . $conn->error);
        http_response_code(500);
        $load_error = 'The score report could not be loaded. Please try again later.';
    } else {
        $stmt->bind_param('i', $assessment_id);
        if (!$stmt->execute()) {
            error_log('ACE-III score report query failed: ' . $stmt->error);
            http_response_code(500);
            $load_error = 'The score report could not be loaded. Please try again later.';
        } else {
            $result = $stmt->get_result();
            $record = $result->fetch_assoc() ?: null;
            if (!$record) {
                http_response_code(404);
                $load_error = 'This ACE-III assessment record was not found.';
            } elseif ($current_role === 2 && (int) $record['patient_id'] !== $current_user_id) {
                http_response_code(403);
                $record = null;
                $load_error = 'You do not have permission to view this assessment report.';
            }
        }
        $stmt->close();
    }
}

$patient_name = '';
$clinician_name = '';
$notes = '';
$answers = [];
$score_data = [];
$demographics = [];
$section_scores = [
    'attention' => ['label' => 'Attention', 'score' => 0, 'max' => 18],
    'memory' => ['label' => 'Memory', 'score' => 0, 'max' => 26],
    'fluency' => ['label' => 'Fluency', 'score' => 0, 'max' => 14],
    'language' => ['label' => 'Language', 'score' => 0, 'max' => 26],
    'visuospatial' => ['label' => 'Visuospatial', 'score' => 0, 'max' => 16],
];
$chart_labels = [];
$chart_values = [];
$chart_maxes = [];
$chart_colours = ['#0ea5e9', '#8b5cf6', '#14b8a6', '#f59e0b', '#ef4444'];

if ($record) {
    $patient_name = trim($record['patient_name']) ?: 'Unknown patient';
    $clinician_name = trim($record['clinician_name']) ?: 'Waiting for scorer';
    $notes = (string) ($record['clinical_notes'] ?? '');

    foreach ($section_scores as $key => &$section) {
        $section['score'] = (int) ($record[$key . '_score'] ?? 0);
    }
    unset($section);

    $decoded = json_decode((string) ($record['response_data'] ?? ''), true);
    $answers = is_array($decoded['answers'] ?? null) ? $decoded['answers'] : [];
    $score_data = is_array($decoded['scores'] ?? null) ? $decoded['scores'] : [];

    $demo_decoded = json_decode((string) ($record['demographic_data'] ?? ''), true);
    if (!is_array($demo_decoded) || empty($demo_decoded)) {
        $details_decoded = json_decode((string) ($record['patient_details'] ?? ''), true);
        $demo_decoded = is_array($details_decoded['_demographics'] ?? null) ? $details_decoded['_demographics'] : [];
    }
    $normalized_demo = assessment_demographics_normalize(is_array($demo_decoded) ? $demo_decoded : []);
    foreach ($normalized_demo as $dk => $dv) {
        if (in_array($dk, ['date', 'client_name'], true)) {
            continue;
        }
        $val_str = is_array($dv) ? implode(', ', array_filter($dv)) : trim((string) $dv);
        $label = ucwords(str_replace('_', ' ', (string) $dk));
        $demographics[$label] = $val_str !== '' ? $val_str : '—';
    }

    foreach ($section_scores as $section) {
        $chart_labels[] = $section['label'];
        $chart_values[] = $section['score'];
        $chart_maxes[] = $section['max'];
    }
}

$total_score = $record ? (int) $record['total_score'] : 0;
if ($total_score >= 88) {
    $status_text = '88–100 · Normal Cognitive Function';
    $status_class = 'normal';
} elseif ($total_score >= 83) {
    $status_text = '83–87 · Borderline / Mild Cognitive Impairment';
    $status_class = 'borderline';
} else {
    $status_text = '82 & Below · Abnormal Cognitive Function';
    $status_class = 'abnormal';
}

$picture_names = [
    1 => 'Book', 2 => 'Spoon', 3 => 'Goat', 4 => 'Candle',
    5 => 'Indian Flag', 6 => 'Camel', 7 => 'Sickle', 8 => 'Giraffe',
    9 => 'Kettle Drum', 10 => 'Umbrella', 11 => 'Pig', 12 => 'Crocodile',
];

require 'layout/base.php';
?>
<style>
    :root {
        --ar-primary: #0f766e;
        --ar-primary-dark: #115e59;
        --ar-primary-soft: #f0fdfa;
        --ar-border: #e2e8f0;
        --ar-border-soft: #f1f5f9;
        --ar-text: #0f172a;
        --ar-muted: #64748b;
        --ar-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .score-report {
        max-width: 1180px;
        margin: 0 auto;
        padding: 24px 18px 52px;
        color: var(--ar-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Top Bar */
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
        color: var(--ar-text);
        border-color: #94a3b8;
    }

    .report-print {
        border: 1px solid var(--ar-primary);
        background: var(--ar-primary);
        color: #fff;
        box-shadow: 0 2px 8px rgba(15, 118, 110, 0.22);
    }

    .report-print:hover {
        background: var(--ar-primary-dark);
        border-color: var(--ar-primary-dark);
        transform: translateY(-1px);
    }

    /* Hero Banner */
    .report-hero {
        position: relative;
        overflow: hidden;
        padding: 28px 30px;
        border-radius: 16px;
        background: linear-gradient(135deg, #0f3f3c 0%, #0f766e 55%, #0ea5e9 100%);
        color: #fff;
        box-shadow: 0 14px 32px -8px rgba(15, 118, 110, 0.3);
    }

    .report-hero::after {
        content: "";
        position: absolute;
        top: -80px;
        right: -60px;
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
        color: #e0f2fe;
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

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 14px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .status-badge.normal { background: #dcfce7; color: #166534; }
    .status-badge.borderline { background: #fef3c7; color: #92400e; }
    .status-badge.abnormal { background: #fee2e2; color: #991b1b; }

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

    /* Summary Grid */
    .report-grid {
        display: grid;
        grid-template-columns: 450px minmax(0, 1fr);
        gap: 20px;
        margin-top: 20px;
        align-items: stretch;
    }

    .report-card {
        background: #fff;
        border: 1px solid var(--ar-border);
        border-radius: 14px;
        box-shadow: var(--ar-shadow);
        overflow: hidden;
    }

    .card-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--ar-border);
        border-left: 4px solid var(--ar-primary);
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
        color: var(--ar-text);
    }

    .domain-total-pill {
        background: var(--ar-primary-soft);
        color: var(--ar-primary);
        border: 1px solid #99f6e4;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .card-body-pad {
        padding: 20px 22px;
    }

    /* Chart */
    .score-chart-wrap {
        position: relative;
        width: 100%;
        height: 255px;
        margin: 0 auto;
    }

    .chart-legend-custom {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 6px 14px;
        margin-top: 8px;
        font-size: 11.5px;
        font-weight: 600;
        color: #475569;
    }

    .chart-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .chart-legend-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .summary-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 14px;
    }

    .summary-stat {
        padding: 11px 12px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid var(--ar-border-soft);
    }

    .summary-stat span {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: var(--ar-muted);
    }

    .summary-stat strong {
        display: block;
        margin-top: 3px;
        font-size: 14px;
        font-weight: 800;
        color: var(--ar-text);
    }

    /* Domain Bars */
    .section-list {
        display: grid;
        gap: 16px;
    }

    .section-row {
        display: grid;
        grid-template-columns: 125px minmax(0, 1fr) 95px;
        align-items: center;
        gap: 14px;
    }

    .section-name {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 13.5px;
        font-weight: 700;
        color: #334155;
    }

    .section-swatch {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        flex-shrink: 0;
    }

    .section-track {
        overflow: hidden;
        height: 9px;
        border-radius: 99px;
        background: #edf2f7;
    }

    .section-fill {
        display: block;
        height: 100%;
        border-radius: inherit;
    }

    .section-value {
        text-align: right;
    }

    .section-value strong {
        display: block;
        font-size: 13.5px;
        font-weight: 800;
        color: var(--ar-text);
    }

    .section-judge {
        font-size: 11px;
        font-weight: 600;
        color: var(--ar-muted);
    }

    /* Demographics Grid */
    .demo-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .demo-item {
        background: #f8fafc;
        border: 1px solid var(--ar-border-soft);
        border-radius: 10px;
        padding: 10px 14px;
    }

    .demo-item span {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--ar-muted);
    }

    .demo-item strong {
        display: block;
        margin-top: 3px;
        font-size: 13.5px;
        color: var(--ar-text);
        overflow-wrap: anywhere;
    }

    /* Section Divider Title */
    .detail-heading {
        margin: 28px 0 14px;
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .detail-heading h2 {
        margin: 0;
        font-size: 17px;
        font-weight: 800;
        color: var(--ar-text);
    }

    .detail-heading span {
        font-size: 12.5px;
        color: var(--ar-muted);
        font-weight: 600;
    }

    /* Detailed Form Data Blocks */
    .domain-card {
        margin-bottom: 20px;
    }

    .sub-block {
        padding: 16px 22px;
        border-bottom: 1px solid var(--ar-border-soft);
    }

    .sub-block:last-child {
        border-bottom: 0;
    }

    .sub-block-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 10px;
    }

    .sub-block-title {
        font-size: 13.5px;
        font-weight: 800;
        color: #1e293b;
    }

    .ace-item-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 800;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .ace-item-pill.is-full { background: #dcfce7; color: #166534; }
    .ace-item-pill.is-partial { background: #fef3c7; color: #92400e; }
    .ace-item-pill.is-zero { background: #fee2e2; color: #991b1b; }
    .ace-item-pill.is-empty { background: #f1f5f9; color: #64748b; }

    .ace-muted-val {
        color: #94a3b8;
        font-style: italic;
    }

    .ace-response-box {
        background: #f8fafc;
        border: 1px solid var(--ar-border-soft);
        border-radius: 9px;
        padding: 10px 14px;
        font-size: 13.5px;
        line-height: 1.55;
        color: #1e293b;
        overflow-wrap: anywhere;
    }

    /* Compact Data Tables */
    .ace-detail-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        border: 1px solid var(--ar-border);
        border-radius: 10px;
        overflow: hidden;
        font-size: 13px;
    }

    .ace-detail-table th,
    .ace-detail-table td {
        padding: 9px 12px;
        border-right: 1px solid var(--ar-border);
        border-bottom: 1px solid var(--ar-border);
        vertical-align: top;
    }

    .ace-detail-table th:last-child,
    .ace-detail-table td:last-child {
        border-right: 0;
    }

    .ace-detail-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .ace-detail-table th {
        background: #f8fafc;
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #475569;
    }

    /* Key-Value Rows */
    .kv-list {
        display: grid;
        gap: 8px;
    }

    .kv-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        padding: 9px 12px;
        background: #f8fafc;
        border: 1px solid var(--ar-border-soft);
        border-radius: 8px;
        font-size: 13px;
    }

    .kv-label {
        font-weight: 600;
        color: #334155;
    }

    .kv-right {
        display: flex;
        align-items: center;
        gap: 10px;
        text-align: right;
    }

    /* Picture Naming 3x4 Grid */
    .naming-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .naming-cell {
        border: 1px solid var(--ar-border);
        border-radius: 9px;
        padding: 10px 12px;
        background: #f8fafc;
    }

    .naming-cell-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 6px;
        margin-bottom: 6px;
    }

    .naming-cell-title {
        font-size: 11.5px;
        font-weight: 700;
        color: var(--ar-muted);
    }

    .naming-cell-ans {
        font-size: 13px;
        font-weight: 600;
        color: var(--ar-text);
    }

    /* Drawings Grid */
    .drawings-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }

    .drawing-card {
        border: 1px solid var(--ar-border);
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }

    .drawing-card-head {
        padding: 9px 12px;
        background: #f8fafc;
        border-bottom: 1px solid var(--ar-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12.5px;
        font-weight: 700;
    }

    .drawing-preview {
        height: 170px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 8px;
        background: #fff;
    }

    .drawing-preview img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    /* Clinical Notes */
    .clinical-notes {
        margin: 0;
        padding: 14px 16px;
        background: #f8fafc;
        border: 1px solid var(--ar-border-soft);
        border-left: 3px solid var(--ar-primary);
        border-radius: 8px;
        color: #334155;
        font-size: 13.5px;
        line-height: 1.65;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    /* Simple Invoice-Style Signature Footer */
    .report-sign-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 40px;
        margin-top: 40px;
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
        .report-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 900px) {
        .report-meta,
        .drawings-grid {
            grid-template-columns: 1fr;
        }
        .demo-grid,
        .naming-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 600px) {
        .score-report { padding: 16px 12px 32px; }
        .report-hero { padding: 20px 18px; }
        .hero-right { align-items: flex-start; }
        .demo-grid,
        .naming-grid { grid-template-columns: 1fr; }
        .section-row { grid-template-columns: 110px minmax(0, 1fr) 82px; gap: 10px; }
        .kv-row { flex-direction: column; align-items: flex-start; }
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
        .report-topbar,
        .admin-sidebar,
        .admin-navbar,
        .sidebar-backdrop {
            display: none !important;
        }
        .score-report {
            max-width: none;
            padding: 0;
        }
        .report-grid {
            grid-template-columns: 1fr;
        }
        .report-hero,
        .sub-block,
        .drawing-card,
        .naming-cell,
        .report-sign-footer {
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .report-card {
            box-shadow: none !important;
            overflow: visible !important;
            break-inside: auto !important;
            page-break-inside: auto !important;
        }
        .card-head {
            break-after: avoid;
            page-break-after: avoid;
        }
    }
</style>

<div class="score-report">
    <div class="report-topbar">
        <a class="report-back" href="ace-iii-assessment-records.php">
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
            <p class="mb-0"><?php echo ace_iii_report_escape($load_error); ?></p>
        </section>
    <?php else: ?>
        <!-- HERO HEADER -->
        <header class="report-hero">
            <div class="hero-top">
                <div>
                    <div class="report-eyebrow">Clinical Assessment Report</div>
                    <h1>ACE-III Score Overview</h1>
                    <p class="report-subtitle">Addenbrooke's Cognitive Examination (Indian English) &middot; 100-Point Assessment</p>
                </div>
                <div class="hero-right">
                    <span class="status-badge <?php echo $status_class; ?>">
                        <?php echo ace_iii_report_escape($status_text); ?>
                    </span>
                </div>
            </div>

            <div class="report-meta">
                <div class="meta-box">
                    <span>Patient Name</span>
                    <strong><?php echo ace_iii_report_escape($patient_name); ?></strong>
                </div>
                <div class="meta-box">
                    <span>Assessment Date</span>
                    <strong><?php echo ace_iii_report_escape(date('d M Y', strtotime($record['assessment_date']))); ?></strong>
                </div>
                <div class="meta-box">
                    <span>Evaluating Clinician</span>
                    <strong><?php echo ace_iii_report_escape($clinician_name); ?></strong>
                </div>
            </div>
        </header>

        <!-- PATIENT DEMOGRAPHICS -->
        <?php if (!empty($demographics)): ?>
            <section class="report-card" style="margin-top: 20px;">
                <div class="card-head">
                    <h2>Patient Demographic Details</h2>
                </div>
                <div class="card-body-pad">
                    <div class="demo-grid">
                        <?php foreach ($demographics as $demo_label => $demo_val): ?>
                            <div class="demo-item">
                                <span><?php echo ace_iii_report_escape($demo_label); ?></span>
                                <strong><?php echo ace_iii_report_escape($demo_val); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- SUMMARY CHARTS & DOMAIN BREAKDOWN -->
        <div class="report-grid">
            <section class="report-card" aria-labelledby="aceSummaryTitle">
                <div class="card-head">
                    <h2 id="aceSummaryTitle">Overall Cognitive Score</h2>
                </div>
                <div class="card-body-pad">
                    <div class="score-chart-wrap">
                        <canvas id="ace3ScoreChart" role="img" aria-label="ACE-III total score chart"></canvas>
                    </div>
                    <div class="chart-legend-custom">
                        <?php foreach (array_values($section_scores) as $idx => $sec): ?>
                            <span class="chart-legend-item">
                                <span class="chart-legend-dot" style="background: <?php echo $chart_colours[$idx]; ?>;"></span>
                                <?php echo ace_iii_report_escape($sec['label']); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="summary-stats">
                        <div class="summary-stat">
                            <span>Total Score</span>
                            <strong><?php echo (int) $total_score; ?> / 100</strong>
                        </div>
                        <div class="summary-stat">
                            <span>Record Status</span>
                            <strong><?php echo ace_iii_report_escape(ucfirst(strtolower((string) ($record['status'] ?? 'Pending')))); ?></strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="report-card" aria-labelledby="aceDomainTitle">
                <div class="card-head">
                    <h2 id="aceDomainTitle">Domain Score Breakdown</h2>
                    <span class="domain-total-pill">Total: <?php echo (int) $total_score; ?> / 100</span>
                </div>
                <div class="card-body-pad">
                    <div class="section-list">
                        <?php foreach (array_values($section_scores) as $idx => $section): ?>
                            <?php $section_percent = $section['max'] > 0 ? ($section['score'] / $section['max']) * 100 : 0; ?>
                            <div class="section-row">
                                <span class="section-name">
                                    <span class="section-swatch" style="background: <?php echo $chart_colours[$idx]; ?>;"></span>
                                    <?php echo ace_iii_report_escape($section['label']); ?>
                                </span>
                                <span class="section-track" aria-hidden="true">
                                    <span class="section-fill" style="width: <?php echo max(0, min(100, $section_percent)); ?>%; background: <?php echo $chart_colours[$idx]; ?>;"></span>
                                </span>
                                <span class="section-value">
                                    <strong><?php echo (int) $section['score']; ?> / <?php echo (int) $section['max']; ?></strong>
                                    <small class="section-judge">
                                        <?php echo $section['score'] >= ($section['max'] * 0.7) ? 'Strong' : ($section['score'] >= ($section['max'] * 0.4) ? 'Moderate' : 'Needs attention'); ?>
                                    </small>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>

        <!-- DETAILED FORM RESPONSES HEADING -->
        <div class="detail-heading">
            <h2>Detailed Assessment Responses</h2>
            <span>Complete item-by-item record submitted in the ACE-III form</span>
        </div>

        <!-- 1. ATTENTION -->
        <section class="report-card domain-card">
            <div class="card-head">
                <h2>1. Attention</h2>
                <span class="domain-total-pill"><?php echo (int) $section_scores['attention']['score']; ?> / 18</span>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Orientation — Time</span>
                </div>
                <div class="table-responsive">
                    <table class="ace-detail-table">
                        <thead>
                            <tr><th>Day</th><th>Date</th><th>Month</th><th>Year</th><th>Season</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php foreach (['orientation_day', 'orientation_date', 'orientation_month', 'orientation_year', 'orientation_season'] as $f): ?>
                                    <td>
                                        <div class="mb-1"><?php echo ace_iii_ans($answers, $f, '—'); ?></div>
                                        <?php echo ace_iii_score_pill($score_data, $f, 1); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Orientation — Place</span>
                </div>
                <div class="table-responsive">
                    <table class="ace-detail-table">
                        <thead>
                            <tr><th>No. / Floor</th><th>Street / Hospital</th><th>City</th><th>State</th><th>Country</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php foreach (['orientation_floor', 'orientation_street', 'orientation_city', 'orientation_state', 'orientation_country'] as $f): ?>
                                    <td>
                                        <div class="mb-1"><?php echo ace_iii_ans($answers, $f, '—'); ?></div>
                                        <?php echo ace_iii_score_pill($score_data, $f, 1); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Word Registration (Lemon, Key, Ball)</span>
                    <?php echo ace_iii_score_pill($score_data, 'registration', 3); ?>
                </div>
                <div class="ace-response-box"><?php echo ace_iii_ans($answers, 'registration_trials'); ?></div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Serial 7 Subtraction (100 − 7)</span>
                    <?php echo ace_iii_score_pill($score_data, 'subtraction', 5); ?>
                </div>
                <div class="table-responsive">
                    <table class="ace-detail-table">
                        <thead>
                            <tr><th>Step 1 (93)</th><th>Step 2 (86)</th><th>Step 3 (79)</th><th>Step 4 (72)</th><th>Step 5 (65)</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <td class="text-center fw-bold"><?php echo ace_iii_ans($answers, 'subtraction_' . $i, '—'); ?></td>
                                <?php endfor; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 2. MEMORY -->
        <section class="report-card domain-card">
            <div class="card-head">
                <h2>2. Memory</h2>
                <span class="domain-total-pill"><?php echo (int) $section_scores['memory']['score']; ?> / 26</span>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">3-Word Recall (Lemon, Key, Ball)</span>
                    <?php echo ace_iii_score_pill($score_data, 'word_recall', 3); ?>
                </div>
                <div class="ace-response-box"><?php echo ace_iii_ans($answers, 'word_recall_response'); ?></div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Name &amp; Address Learning (Sunil Kumar Singh, 52 Station Road, Gandhinagar, Allahabad)</span>
                    <?php echo ace_iii_score_pill($score_data, 'address_learning', 7); ?>
                </div>
                <div class="table-responsive">
                    <table class="ace-detail-table">
                        <thead>
                            <tr><th>1st Trial</th><th>2nd Trial</th><th>3rd Trial (Scored)</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo ace_iii_ans($answers, 'address_trial_1', '—'); ?></td>
                                <td><?php echo ace_iii_ans($answers, 'address_trial_2', '—'); ?></td>
                                <td><?php echo ace_iii_ans($answers, 'address_trial_3', '—'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">General / Retrograde Memory</span>
                </div>
                <div class="kv-list">
                    <?php
                    $mem_qs = [
                        'memory_chief_minister' => 'Current Chief Minister',
                        'memory_prime_minister' => 'Prime Minister of India',
                        'memory_actor' => "Hero in 'Mera Naam Joker'",
                        'memory_father_nation' => 'Father of our Nation',
                    ];
                    foreach ($mem_qs as $field => $label):
                    ?>
                        <div class="kv-row">
                            <span class="kv-label"><?php echo ace_iii_report_escape($label); ?></span>
                            <div class="kv-right">
                                <span><?php echo ace_iii_ans($answers, $field, '—'); ?></span>
                                <?php echo ace_iii_score_pill($score_data, $field, 1); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Delayed Address Recall</span>
                    <?php echo ace_iii_score_pill($score_data, 'address_recall', 7); ?>
                </div>
                <div class="ace-response-box"><?php echo ace_iii_ans($answers, 'address_recall_response'); ?></div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head mb-0">
                    <span class="sub-block-title">Address Recognition</span>
                    <?php echo ace_iii_score_pill($score_data, 'address_recognition', 5); ?>
                </div>
            </div>
        </section>

        <!-- 3. FLUENCY -->
        <section class="report-card domain-card">
            <div class="card-head">
                <h2>3. Fluency</h2>
                <span class="domain-total-pill"><?php echo (int) $section_scores['fluency']['score']; ?> / 14</span>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Letter Fluency (Letter "P" — 1 Minute)</span>
                    <?php echo ace_iii_score_pill($score_data, 'letter_fluency', 7); ?>
                </div>
                <div class="ace-response-box"><?php echo ace_iii_ans($answers, 'letter_fluency_response'); ?></div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Category Fluency (Animals — 1 Minute)</span>
                    <?php echo ace_iii_score_pill($score_data, 'animal_fluency', 7); ?>
                </div>
                <div class="ace-response-box"><?php echo ace_iii_ans($answers, 'animal_fluency_response'); ?></div>
            </div>
        </section>

        <!-- 4. LANGUAGE -->
        <section class="report-card domain-card">
            <div class="card-head">
                <h2>4. Language</h2>
                <span class="domain-total-pill"><?php echo (int) $section_scores['language']['score']; ?> / 26</span>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Comprehension Commands (Pencil &amp; Paper)</span>
                </div>
                <div class="kv-list">
                    <div class="kv-row">
                        <span class="kv-label">Place the paper on top of the pencil</span>
                        <?php echo ace_iii_score_pill($score_data, 'command_1', 1); ?>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Pick up the pencil but not the paper</span>
                        <?php echo ace_iii_score_pill($score_data, 'command_2', 1); ?>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Pass me the pencil after touching the paper</span>
                        <?php echo ace_iii_score_pill($score_data, 'command_3', 1); ?>
                    </div>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Sentence Writing</span>
                    <?php echo ace_iii_score_pill($score_data, 'writing', 2); ?>
                </div>
                <div class="ace-response-box"><?php echo ace_iii_ans($answers, 'writing_response'); ?></div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Multisyllabic Word Repetition (caterpillar, eccentricity, unintelligible, statistician)</span>
                    <?php echo ace_iii_score_pill($score_data, 'multisyllabic_repetition', 2); ?>
                </div>
                <div class="ace-response-box"><?php echo ace_iii_ans($answers, 'multisyllabic_response'); ?></div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Proverb Repetition</span>
                </div>
                <div class="kv-list">
                    <div class="kv-row">
                        <span class="kv-label">"All that glitters is not gold"</span>
                        <div class="kv-right">
                            <span><?php echo ace_iii_ans($answers, 'proverb_1_response', '—'); ?></span>
                            <?php echo ace_iii_score_pill($score_data, 'proverb_1', 1); ?>
                        </div>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">"A stitch in time saves nine"</span>
                        <div class="kv-right">
                            <span><?php echo ace_iii_ans($answers, 'proverb_2_response', '—'); ?></span>
                            <?php echo ace_iii_score_pill($score_data, 'proverb_2', 1); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Picture Naming (12 Items)</span>
                </div>
                <div class="naming-grid">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <div class="naming-cell">
                            <div class="naming-cell-top">
                                <span class="naming-cell-title">#<?php echo $i; ?> &middot; <?php echo ace_iii_report_escape($picture_names[$i]); ?></span>
                                <?php echo ace_iii_score_pill($score_data, 'naming_' . $i, 1); ?>
                            </div>
                            <div class="naming-cell-ans"><?php echo ace_iii_ans($answers, 'naming_' . $i . '_response', '—'); ?></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Pointing Comprehension</span>
                </div>
                <div class="kv-list">
                    <div class="kv-row">
                        <span class="kv-label">Used in rain</span>
                        <?php echo ace_iii_score_pill($score_data, 'comprehension_1', 1); ?>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Emits light</span>
                        <?php echo ace_iii_score_pill($score_data, 'comprehension_2', 1); ?>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Associated with farming</span>
                        <?php echo ace_iii_score_pill($score_data, 'comprehension_3', 1); ?>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Found in deserts</span>
                        <?php echo ace_iii_score_pill($score_data, 'comprehension_4', 1); ?>
                    </div>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Reading Words (sew, pint, soot, dough, height)</span>
                    <?php echo ace_iii_score_pill($score_data, 'reading', 1); ?>
                </div>
                <div class="ace-response-box"><?php echo ace_iii_ans($answers, 'reading_response'); ?></div>
            </div>
        </section>

        <!-- 5. VISUOSPATIAL ABILITIES -->
        <section class="report-card domain-card">
            <div class="card-head">
                <h2>5. Visuospatial Abilities</h2>
                <span class="domain-total-pill"><?php echo (int) $section_scores['visuospatial']['score']; ?> / 16</span>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Patient Drawings (Infinity Diagram, Wire Cube &amp; Clock)</span>
                </div>
                <div class="drawings-grid">
                    <?php
                    $drawings = [
                        ['title' => 'Infinity Diagram', 'ans' => 'drawing_infinity', 'score' => 'infinity', 'max' => 1],
                        ['title' => 'Wire Cube', 'ans' => 'drawing_cube', 'score' => 'cube', 'max' => 2],
                        ['title' => 'Clock (10 Past 5)', 'ans' => 'drawing_clock', 'score' => 'clock', 'max' => 5],
                    ];
                    foreach ($drawings as $dr):
                        $img_src = ace_iii_valid_drawing($answers, $dr['ans']);
                    ?>
                        <div class="drawing-card">
                            <div class="drawing-card-head">
                                <span><?php echo ace_iii_report_escape($dr['title']); ?></span>
                                <?php echo ace_iii_score_pill($score_data, $dr['score'], $dr['max']); ?>
                            </div>
                            <div class="drawing-preview">
                                <?php if ($img_src !== ''): ?>
                                    <img src="<?php echo ace_iii_report_escape($img_src); ?>" alt="<?php echo ace_iii_report_escape($dr['title']); ?>">
                                <?php else: ?>
                                    <span class="ace-muted-val">No drawing recorded</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Dot Counting</span>
                </div>
                <div class="table-responsive">
                    <table class="ace-detail-table">
                        <thead>
                            <tr><th>Box 1 (8 dots)</th><th>Box 2 (10 dots)</th><th>Box 3 (7 dots)</th><th>Box 4 (9 dots)</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <td>
                                        <div class="mb-1"><?php echo ace_iii_ans($answers, 'dots_' . $i . '_response', '—'); ?></div>
                                        <?php echo ace_iii_score_pill($score_data, 'dots_' . $i, 1); ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sub-block">
                <div class="sub-block-head">
                    <span class="sub-block-title">Fragmented Letters Identification</span>
                </div>
                <div class="table-responsive">
                    <table class="ace-detail-table">
                        <thead>
                            <tr><th>Letter 1 (K)</th><th>Letter 2 (M)</th><th>Letter 3 (A)</th><th>Letter 4 (T)</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <td>
                                        <div class="mb-1"><?php echo ace_iii_ans($answers, 'letter_fragment_' . $i . '_response', '—'); ?></div>
                                        <?php echo ace_iii_score_pill($score_data, 'letters_' . $i, 1); ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- CLINICAL NOTES -->
        <?php if (trim($notes) !== ''): ?>
            <section class="report-card" aria-labelledby="aceNotesTitle">
                <div class="card-head">
                    <h2 id="aceNotesTitle">Clinical Notes &amp; Examiner Observations</h2>
                </div>
                <div class="card-body-pad">
                    <p class="clinical-notes"><?php echo ace_iii_report_escape($notes); ?></p>
                </div>
            </section>
        <?php endif; ?>

        <!-- Simple Invoice-Style Signature Footer -->
        <div class="report-sign-footer">
            <div class="report-sign-block">
                <div class="report-sign-line">
                    <div class="report-sign-title"><?php echo ace_iii_report_escape($clinician_name); ?></div>
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
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var canvas = document.getElementById('ace3ScoreChart');
        if (!canvas) return;

        var totalScore = <?php echo (int) $total_score; ?>;
        var domainMaxes = <?php echo json_encode($chart_maxes); ?>;

        var aceCalloutAndCenter = {
            id: 'aceCalloutAndCenter',
            afterDatasetsDraw: function (chart) {
                var ctx = chart.ctx;
                var meta = chart.getDatasetMeta(0);
                var data = chart.data.datasets[0].data;
                var labels = chart.data.labels;
                var colors = chart.data.datasets[0].backgroundColor;

                if (!meta.data || !meta.data.length) return;

                ctx.save();

                // 1. Exact center me Total Score
                var centerX = meta.data[0].x;
                var centerY = meta.data[0].y;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = '#0f172a';
                ctx.font = '900 30px system-ui, -apple-system, sans-serif';
                ctx.fillText(String(totalScore), centerX, centerY - 8);
                ctx.fillStyle = '#64748b';
                ctx.font = '700 12px system-ui, -apple-system, sans-serif';
                ctx.fillText('/ 100', centerX, centerY + 14);

                // 2. Har slice ke bagal me Name + Score (anti-overlap ke saath)
                var rightItems = [];
                var leftItems = [];

                meta.data.forEach(function (arc, index) {
                    var value = data[index];
                    if (!value || value <= 0) return;

                    var angle = (arc.startAngle + arc.endAngle) / 2;
                    var cos = Math.cos(angle);
                    var sin = Math.sin(angle);
                    var item = {
                        index: index,
                        label: String(labels[index] || ''),
                        value: value,
                        color: colors[index] || '#334155',
                        x1: arc.x + cos * (arc.outerRadius + 2),
                        y1: arc.y + sin * (arc.outerRadius + 2),
                        x2: arc.x + cos * (arc.outerRadius + 11),
                        y2: arc.y + sin * (arc.outerRadius + 11),
                        isRight: cos >= 0
                    };
                    if (item.isRight) {
                        rightItems.push(item);
                    } else {
                        leftItems.push(item);
                    }
                });

                function drawSide(items) {
                    items.sort(function (a, b) { return a.y2 - b.y2; });
                    var minGap = 24;
                    for (var i = 1; i < items.length; i++) {
                        if (items[i].y2 - items[i - 1].y2 < minGap) {
                            items[i].y2 = items[i - 1].y2 + minGap;
                        }
                    }

                    ctx.font = '700 10.5px system-ui, -apple-system, sans-serif';
                    ctx.textBaseline = 'middle';

                    items.forEach(function (item) {
                        var x3 = item.x2 + (item.isRight ? 8 : -8);
                        ctx.beginPath();
                        ctx.moveTo(item.x1, item.y1);
                        ctx.lineTo(item.x2, item.y2);
                        ctx.lineTo(x3, item.y2);
                        ctx.strokeStyle = '#475569';
                        ctx.lineWidth = 1.15;
                        ctx.stroke();

                        ctx.fillStyle = '#0f172a';
                        ctx.textAlign = item.isRight ? 'left' : 'right';
                        ctx.fillText(item.label + ' (' + item.value + ')', x3 + (item.isRight ? 4 : -4), item.y2);
                    });
                }

                drawSide(rightItems);
                drawSide(leftItems);

                ctx.restore();
            }
        };

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($chart_values, JSON_UNESCAPED_UNICODE); ?>,
                    backgroundColor: <?php echo json_encode($chart_colours, JSON_UNESCAPED_UNICODE); ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '64%',
                layout: {
                    padding: { top: 22, bottom: 22, left: 108, right: 108 }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.label + ': ' + context.raw + ' / ' + domainMaxes[context.dataIndex];
                            }
                        }
                    }
                }
            },
            plugins: [aceCalloutAndCenter]
        });
    });
</script>
<?php require 'layout/footer.php'; ?>