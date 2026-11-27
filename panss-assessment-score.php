<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'panss-assessment-items.php';
require 'panss-assessment-schema.php';
require 'assessment-demographics.php';

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
                p.composite_score, p.score_data, p.clinical_notes, p.demographic_data,
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
$formatted_date = '—';
$notes = '';
$domain_scores = [];
$all_scores = [];
$score_data = [];
$demographics_list = [];
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

    $decoded_demo = json_decode((string) ($record['demographic_data'] ?? ''), true);
    $normalized_demo = assessment_demographics_normalize(is_array($decoded_demo) ? $decoded_demo : []);

    $get_demo = static function (array $keys) use ($normalized_demo): string {
        foreach ($keys as $k) {
            if (!isset($normalized_demo[$k])) {
                continue;
            }
            $v = $normalized_demo[$k];
            $str = is_array($v) ? implode(', ', array_filter($v)) : trim((string) $v);
            if ($str !== '') {
                return $str;
            }
        }
        return '—';
    };

    $raw_date = $get_demo(['date']);
    $formatted_date = $raw_date !== '—' && strtotime($raw_date) !== false
        ? date('d M Y', strtotime($raw_date))
        : (!empty($record['assessment_date']) ? date('d M Y', strtotime($record['assessment_date'])) : '—');

    $age_val = $get_demo(['age']);
    $gender_val = $get_demo(['gender', 'sex']);
    $age_gender_combined = $get_demo(['age_gender', 'age_/_gender']);
    if ($age_gender_combined === '—') {
        $parts = array_filter([$age_val !== '—' ? $age_val : '', $gender_val !== '—' ? $gender_val : '']);
        $age_gender_combined = !empty($parts) ? implode(' / ', $parts) : '—';
    }

    // Exact 2-Column Header Order (Client Name first from dropdown, followed by client's exact sequence)
    $demographics_list = [
        'Client Name'             => $patient_name,
        'Date'                    => $formatted_date,
        'UHID'                    => $get_demo(['uhid']),
        'Age / Gender'            => $age_gender_combined,
        'Contact No'              => $get_demo(['contact_no', 'contact_number', 'phone']),
        'Email ID'                => $get_demo(['email_id', 'email']),
        'Caregiver Name'          => $get_demo(['caregiver_name']),
        'Contact No of Caregiver' => $get_demo(['contact_no_of_caregiver', 'caregiver_contact', 'caregiver_contact_no']),
        'Consultant'              => $get_demo(['consultant']),
        'Evaluating Professional' => $clinician_name,
    ];

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
        max-width: 1180px;
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

    /* Executive Hero Banner (with Integrated 2-Column Demographics) */
    .report-hero {
        position: relative;
        overflow: hidden;
        padding: 26px 28px;
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
        margin-bottom: 8px;
    }

    .report-hero h1 {
        margin: 0;
        font-size: clamp(24px, 3.5vw, 31px);
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .report-subtitle {
        margin: 5px 0 0;
        color: #ccfbf1;
        font-size: 13.5px;
    }

    .hero-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }

    .hero-total-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 15px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        border: 1px solid rgba(255, 255, 255, 0.28);
        color: #fff;
        font-size: 13px;
        font-weight: 800;
        white-space: nowrap;
    }

    /* Integrated 2-Column Demographics Inside Hero */
    .hero-demo-wrap {
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid rgba(255, 255, 255, 0.18);
        position: relative;
        z-index: 1;
    }

    .hero-demo-title {
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.09em;
        color: #99f6e4;
        margin-bottom: 11px;
    }

    .hero-demo-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 14px;
    }

    .hero-demo-item {
        background: rgba(255, 255, 255, 0.10);
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 9px;
        padding: 8px 13px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .hero-demo-item span {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #ccfbf1;
        opacity: 0.9;
    }

    .hero-demo-item strong {
        display: block;
        margin-top: 2px;
        font-size: 13.5px;
        font-weight: 700;
        color: #fff;
        overflow-wrap: anywhere;
    }

    /* 4-Column Subscale KPI Strip */
    .panss-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-top: 18px;
    }

    .panss-kpi {
        background: #fff;
        border: 1px solid var(--pr-border);
        border-radius: 12px;
        padding: 15px 17px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        border-top: 3px solid var(--kpi-accent, var(--pr-primary));
    }

    .panss-kpi-label {
        display: block;
        font-size: 10.5px;
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
        font-size: 25px;
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
        margin-top: 4px;
        font-size: 11px;
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
        padding: 12px 18px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .card-head h2 {
        margin: 0;
        font-size: 13px;
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
        padding: 18px 20px;
    }

    /* Overview Grid */
    .overview-stack {
        display: grid;
        grid-template-columns: 480px minmax(0, 1fr);
        gap: 18px;
        margin-top: 18px;
        align-items: stretch;
    }

    .score-chart-wrap {
        position: relative;
        width: 100%;
        height: 245px;
        margin: 0 auto;
    }

    .chart-legend-custom {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 8px 20px;
        margin-top: 10px;
        font-size: 12px;
        font-weight: 600;
        color: #475569;
    }

    .chart-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .chart-legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    /* Domain Distribution Bars */
    .domain-bars {
        display: grid;
        grid-template-columns: 1fr;
        gap: 14px;
    }

    .domain-bar-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
        font-size: 12.5px;
        gap: 8px;
    }

    .domain-bar-name {
        display: inline-flex;
        align-items: center;
        gap: 7px;
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
        height: 8px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
    }

    .domain-fill {
        display: block;
        height: 100%;
        border-radius: 999px;
    }

    /* Composite & Combined Score Highlight Boxes */
    .index-summary-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
        margin-top: 16px;
    }

    .composite-box {
        padding: 13px 16px;
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
        min-width: 0;
    }

    .composite-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: var(--pr-primary-soft);
        color: var(--pr-primary);
        display: grid;
        place-items: center;
        font-size: 17px;
        flex-shrink: 0;
    }

    .composite-title {
        font-size: 13.5px;
        font-weight: 800;
        color: var(--pr-text);
    }

    .composite-formula {
        font-size: 11.5px;
        color: var(--pr-muted);
    }

    .composite-val {
        min-width: 72px;
        padding: 6px 12px;
        border-radius: 10px;
        background: #fff;
        border: 1px solid #cbd5e1;
        font-size: 22px;
        font-weight: 900;
        text-align: center;
        color: var(--pr-text);
        flex-shrink: 0;
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

    .composite-val.is-combined {
        background: var(--pr-primary-soft);
        border-color: #99f6e4;
        color: var(--pr-primary-dark);
        white-space: nowrap;
    }

    .composite-val.is-combined span {
        font-size: 13px;
        font-weight: 700;
        color: #0f766e;
    }

    /* Item Breakdown Section (Starts on Page 2 when printed) */
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
        gap: 18px;
        margin-bottom: 18px;
    }

    .item-list {
        display: grid;
        gap: 10px;
    }

    .item-list-2col {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 24px;
    }

    .item-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 85px auto;
        align-items: center;
        gap: 10px;
        padding-bottom: 9px;
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
        height: 23px;
        padding: 0 6px;
        border-radius: 6px;
        background: #f1f5f9;
        color: #334155;
        font-size: 11px;
        font-weight: 800;
        flex-shrink: 0;
    }

    .item-label {
        font-size: 12.5px;
        font-weight: 600;
        color: #1e293b;
        line-height: 1.3;
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
        min-width: 78px;
    }

    .rating-pill {
        display: inline-flex;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
    }

    .rating-pill.is-low { background: #ecfdf5; color: #047857; }
    .rating-pill.is-mid { background: #fef3c7; color: #92400e; }
    .rating-pill.is-high { background: #fee2e2; color: #b91c1c; }
    .rating-pill.is-empty { background: #f1f5f9; color: #64748b; }

    .rating-text {
        font-size: 10px;
        font-weight: 600;
        color: var(--pr-muted);
        white-space: nowrap;
    }

    /* Clinical Notes */
    .notes-card {
        margin-top: 18px;
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

    /* Simple Invoice-Style Signature Footer */
    .report-sign-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 40px;
        margin-top: 32px;
        padding: 20px 8px 8px;
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
        margin-top: 44px;
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
        .panss-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .overview-stack,
        .pn-grid,
        .item-list-2col { grid-template-columns: 1fr; }
    }

    @media (max-width: 700px) {
        .panss-report { padding: 16px 12px 32px; }
        .report-hero { padding: 20px 18px; }
        .hero-right { align-items: flex-start; }
        .hero-demo-grid { grid-template-columns: 1fr; }
        .item-row { grid-template-columns: minmax(0, 1fr) auto; }
        .item-track { display: none; }
        .report-sign-footer {
            flex-direction: column;
            align-items: stretch;
            gap: 28px;
        }
        .report-sign-block {
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .panss-kpi-grid { grid-template-columns: 1fr; }
    }

    /* PRINT LAYOUT: Strictly 2 Pages (Page 1 = Overview + 2-Col Demographics, Page 2 = Item Ratings + Signature) */
    @page {
        size: A4 portrait;
        margin: 0;
    }

    @media print {
        html,
        body {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            background: #fff !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .report-topbar,
        .admin-sidebar,
        .admin-navbar,
        .sidebar-backdrop {
            display: none !important;
        }
        .panss-report {
            max-width: 100% !important;
            padding: 8mm 10mm !important;
            margin: 0 !important;
        }

        /* ========== PAGE 1: BALANCED FULL FIT WITHOUT OVERFLOW ========== */
        .report-hero {
            padding: 15px 19px !important;
            border-radius: 12px !important;
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .report-eyebrow {
            margin-bottom: 5px !important;
            font-size: 9px !important;
            padding: 2px 9px !important;
        }
        .report-hero h1 {
            font-size: 20px !important;
        }
        .report-subtitle {
            font-size: 11px !important;
        }
        .hero-demo-wrap {
            margin-top: 11px !important;
            padding-top: 9px !important;
        }
        .hero-demo-title {
            margin-bottom: 7px !important;
            font-size: 9.5px !important;
        }
        .hero-demo-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 6px 12px !important;
        }
        .hero-demo-item {
            padding: 5px 10px !important;
        }
        .hero-demo-item span {
            font-size: 8.5px !important;
        }
        .hero-demo-item strong {
            font-size: 11.5px !important;
        }

        /* 4 KPI cards in 1 single row */
        .panss-kpi-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 10px !important;
            margin-top: 12px !important;
        }
        .panss-kpi {
            padding: 10px 12px !important;
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .panss-kpi-label {
            font-size: 9px !important;
        }
        .panss-kpi-val strong {
            font-size: 19px !important;
        }
        .panss-kpi-sub {
            font-size: 9.5px !important;
            margin-top: 4px !important;
        }

        /* 100% Width Stacked Overview Cards on Page 1 */
        .overview-stack {
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
            margin-top: 12px !important;
        }
        .score-chart-wrap {
            width: 560px !important;
            max-width: 100% !important;
            height: 235px !important;
            margin: 0 auto !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
        }
        .score-chart-wrap canvas {
            margin: 0 auto !important;
            display: block !important;
        }
        .chart-legend-custom {
            margin-top: 8px !important;
            font-size: 11px !important;
        }

        /* 2 Scales in Row 1 + 3rd Scale Full Width Below */
        .domain-bars {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px 18px !important;
        }
        .domain-bars > div:last-child {
            grid-column: 1 / -1 !important;
        }
        .domain-bar-top {
            font-size: 11px !important;
            margin-bottom: 5px !important;
        }
        .card-head {
            padding: 8px 14px !important;
            break-after: avoid;
            page-break-after: avoid;
        }
        .card-head h2 {
            font-size: 11.5px !important;
        }
        .card-body-pad {
            padding: 11px 16px !important;
        }

        /* Composite Index & Combined Score Side-by-Side on Page 1 */
        .index-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
            margin-top: 11px !important;
        }
        .composite-box {
            padding: 9px 12px !important;
            gap: 8px !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .composite-icon {
            width: 32px !important;
            height: 32px !important;
            font-size: 15px !important;
        }
        .composite-title {
            font-size: 12px !important;
        }
        .composite-formula {
            font-size: 10px !important;
        }
        .composite-val {
            min-width: 60px !important;
            font-size: 17px !important;
            padding: 4px 9px !important;
        }
        .composite-val.is-combined span {
            font-size: 11px !important;
        }

        /* ========== PAGE 2: ITEM-LEVEL RATINGS + NOTES + SIGNATURE ========== */
        .print-page-break {
            break-before: page !important;
            page-break-before: always !important;
            margin-top: 0 !important;
            padding-top: 8mm !important;
            margin-bottom: 12px !important;
        }
        .print-page-break h2 {
            font-size: 15px !important;
        }

        .pn-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px !important;
            margin-bottom: 12px !important;
        }
        .item-list {
            gap: 6px !important;
        }
        .item-list-2col {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 6px 18px !important;
        }
        .item-row {
            grid-template-columns: minmax(0, 1fr) 58px auto !important;
            gap: 8px !important;
            padding-bottom: 6px !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .item-code {
            min-width: 25px !important;
            height: 20px !important;
            font-size: 9.5px !important;
        }
        .item-label {
            font-size: 11px !important;
            white-space: normal !important;
        }
        .rating-pill {
            font-size: 9.5px !important;
            padding: 1.5px 6px !important;
        }
        .rating-text {
            font-size: 9px !important;
        }
        .notes-card {
            margin-top: 12px !important;
        }
        .clinical-notes {
            padding: 10px 12px !important;
            font-size: 11px !important;
            line-height: 1.45 !important;
        }
        .report-sign-footer {
            margin-top: 22px !important;
            padding-top: 12px !important;
        }
        .report-sign-line {
            margin-top: 34px !important;
        }
        .report-card {
            box-shadow: none !important;
            overflow: visible !important;
            break-inside: auto !important;
            page-break-inside: auto !important;
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
        <!-- HERO HEADER WITH 2-COLUMN CLIENT & CLINICAL DETAILS -->
        <header class="report-hero">
            <div class="hero-top">
                <div>
                    <div class="report-eyebrow">Clinical Assessment Report</div>
                    <h1>PANSS Score Overview</h1>
                    <p class="report-subtitle">Positive and Negative Syndrome Scale &middot; 30-Item Clinician-Rated Assessment</p>
                </div>
                <div class="hero-right">
                    <span class="hero-total-pill">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        Date: <?php echo panss_score_escape($formatted_date); ?>
                    </span>
                </div>
            </div>

            <div class="hero-demo-wrap">
                <div class="hero-demo-title">Patient &amp; Clinical Details</div>
                <div class="hero-demo-grid">
                    <?php foreach ($demographics_list as $demo_label => $demo_value): ?>
                        <div class="hero-demo-item">
                            <span><?php echo panss_score_escape($demo_label); ?></span>
                            <strong><?php echo panss_score_escape($demo_value); ?></strong>
                        </div>
                    <?php endforeach; ?>
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

        <!-- OVERVIEW: GRAPH & SUBSCALE PERFORMANCE -->
        <div class="overview-stack">
            <section class="report-card" aria-labelledby="panssSummaryTitle">
                <div class="card-head">
                    <h2 id="panssSummaryTitle">Score Distribution</h2>
                </div>
                <div class="card-body-pad">
                    <div class="score-chart-wrap">
                        <canvas id="panssScoreChart" role="img" aria-label="PANSS Positive, Negative and General scale scores"></canvas>
                    </div>
                    <div class="chart-legend-custom">
                        <?php foreach ($domain_scores as $index => $domain): ?>
                            <span class="chart-legend-item">
                                <span class="chart-legend-dot" style="background: <?php echo $chart_colors[$index]; ?>;"></span>
                                <?php echo panss_score_escape($domain['title']); ?>
                            </span>
                        <?php endforeach; ?>
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

                    <div class="index-summary-grid">
                        <div class="composite-box" role="group" aria-label="Composite score <?php echo $composite_score; ?>">
                            <div class="composite-left">
                                <span class="composite-icon" aria-hidden="true"><i class="bi bi-calculator"></i></span>
                                <div>
                                    <div class="composite-title">Composite Index</div>
                                    <div class="composite-formula">Positive (<?php echo (int) $record['positive_score']; ?>) &minus; Negative (<?php echo (int) $record['negative_score']; ?>)</div>
                                </div>
                            </div>
                            <div class="composite-val <?php echo $composite_score < 0 ? 'is-negative' : ($composite_score > 0 ? 'is-positive' : ''); ?>">
                                <?php echo $composite_score > 0 ? '+' : ''; ?><?php echo $composite_score; ?>
                            </div>
                        </div>

                        <div class="composite-box" role="group" aria-label="Combined score <?php echo (int) $combined_total; ?> out of 210">
                            <div class="composite-left">
                                <span class="composite-icon" aria-hidden="true"><i class="bi bi-clipboard2-pulse"></i></span>
                                <div>
                                    <div class="composite-title">Combined Score</div>
                                    <div class="composite-formula">P (<?php echo (int) $record['positive_score']; ?>) + N (<?php echo (int) $record['negative_score']; ?>) + G (<?php echo (int) $record['general_score']; ?>)</div>
                                </div>
                            </div>
                            <div class="composite-val is-combined">
                                <?php echo (int) $combined_total; ?><span> / 210</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- DETAILED ITEM SCORES (STARTS ON PAGE 2 IN PRINT) -->
        <div class="section-divider print-page-break">
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

        <!-- Simple Invoice-Style Signature Footer -->
        <div class="report-sign-footer">
            <div class="report-sign-block">
                <div class="report-sign-line">
                    <div class="report-sign-title"><?php echo panss_score_escape($clinician_name); ?></div>
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
        var canvas = document.getElementById('panssScoreChart');
        if (!canvas) return;

        var combinedTotal = <?php echo (int) $combined_total; ?>;

        var sliceCalloutAndCenter = {
            id: 'sliceCalloutAndCenter',
            afterDatasetsDraw: function (chart) {
                var ctx = chart.ctx;
                var meta = chart.getDatasetMeta(0);
                var data = chart.data.datasets[0].data;
                var labels = chart.data.labels;

                if (!meta.data || !meta.data.length) return;

                ctx.save();

                // Center Total Score
                var centerX = meta.data[0].x;
                var centerY = meta.data[0].y;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = '#0f172a';
                ctx.font = '900 24px system-ui, -apple-system, sans-serif';
                ctx.fillText(String(combinedTotal), centerX, centerY - 8);
                ctx.fillStyle = '#64748b';
                ctx.font = '700 11px system-ui, -apple-system, sans-serif';
                ctx.fillText('/ 210', centerX, centerY + 12);

                // Callout Labels
                ctx.font = '700 10.5px system-ui, -apple-system, sans-serif';
                ctx.textBaseline = 'middle';

                meta.data.forEach(function (arc, index) {
                    var value = data[index];
                    if (!value || value <= 0) return;

                    var angle = (arc.startAngle + arc.endAngle) / 2;
                    var cos = Math.cos(angle);
                    var sin = Math.sin(angle);

                    var x1 = arc.x + cos * (arc.outerRadius + 2);
                    var y1 = arc.y + sin * (arc.outerRadius + 2);
                    var x2 = arc.x + cos * (arc.outerRadius + 10);
                    var y2 = arc.y + sin * (arc.outerRadius + 10);
                    var isRight = cos >= 0;
                    var x3 = x2 + (isRight ? 10 : -10);

                    ctx.beginPath();
                    ctx.moveTo(x1, y1);
                    ctx.lineTo(x2, y2);
                    ctx.lineTo(x3, y2);
                    ctx.strokeStyle = '#334155';
                    ctx.lineWidth = 1.2;
                    ctx.stroke();

                    var fullLabel = String(labels[index] || '');
                    var words = fullLabel.split(' ');
                    var lines = [];
                    if (words.length >= 3) {
                        lines.push(words[0]);
                        lines.push(words[1]);
                        lines.push(words.slice(2).join(' ') + ' (' + value + ')');
                    } else {
                        lines.push(fullLabel + ' (' + value + ')');
                    }

                    ctx.fillStyle = '#0f172a';
                    ctx.textAlign = isRight ? 'left' : 'right';
                    var textX = x3 + (isRight ? 5 : -5);
                    var lineHeight = 13;
                    var startY = y2 - ((lines.length - 1) * lineHeight) / 2;

                    lines.forEach(function (line, i) {
                        ctx.fillText(line, textX, startY + i * lineHeight);
                    });
                });

                ctx.restore();
            }
        };

        var panssChart = new Chart(canvas, {
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
                cutout: '64%',
                layout: {
                    padding: { top: 20, bottom: 20, left: 135, right: 135 }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.label + ': ' + context.raw + ' / ' + context.dataset.maximums[context.dataIndex];
                            }
                        }
                    }
                }
            },
            plugins: [sliceCalloutAndCenter]
        });

        window.addEventListener('beforeprint', function () {
            panssChart.resize(560, 235);
        });
        window.addEventListener('afterprint', function () {
            panssChart.resize();
        });
    });
</script>
<?php require 'layout/footer.php'; ?>