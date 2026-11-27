<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'ymrs-assessment-items.php';
require 'ymrs-assessment-schema.php';
require 'assessment-demographics.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

function ymrs_score_escape($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$page_title = 'YMRS Score Report - Vimhans Academic';
$record = null;
$load_error = '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($id === false || $id < 1) {
    http_response_code(400);
    $load_error = 'The assessment link is invalid.';
} elseif (!$ymrs_schema_ready) {
    http_response_code(500);
    $load_error = 'Assessment records are unavailable.';
} else {
    $stmt = $conn->prepare(
        'SELECT y.id, y.assessment_date, y.total_score, y.score_data, y.clinical_notes, y.demographic_data,
                TRIM(CONCAT(COALESCE(u.first_name,"")," ",COALESCE(NULLIF(u.last_name,"."),""))) AS patient_name,
                TRIM(CONCAT(COALESCE(c.first_name,"")," ",COALESCE(NULLIF(c.last_name,"."),""))) AS clinician_name
         FROM ymrs_assessments y
         INNER JOIN users u ON u.id = y.patient_id AND u.role_id = 2 AND u.patient_id = ' . PATIENT_SCOPE_ID . '
         LEFT JOIN users c ON c.id = y.clinician_id
         WHERE y.id = ? LIMIT 1'
    );
    if (!$stmt) {
        error_log('YMRS score report prepare failed: ' . $conn->error);
        http_response_code(500);
        $load_error = 'The score report could not be loaded. Please try again later.';
    } else {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$record) {
            http_response_code(404);
            $load_error = 'This assessment record was not found.';
        }
    }
}

$scores = [];
$demographics = [];
$total = 0;
$rated_count = 0;
$symptomatic_count = 0;
$patient_name = '';
$clinician_name = '';
$formatted_date = '—';
$notes = '';
$severity_label = 'Normal / Remission';
$severity_class = 'normal';
$severity_color = '#0f766e';

if ($record) {
    $decoded = json_decode((string) $record['score_data'], true);
    $decoded = is_array($decoded) ? $decoded : [];
    $total = (int) $record['total_score'];
    $patient_name = trim($record['patient_name']) ?: 'Unknown patient';
    $clinician_name = trim($record['clinician_name']) ?: 'Unknown clinician';
    $notes = (string) ($record['clinical_notes'] ?? '');

    $demo_decoded = json_decode((string) ($record['demographic_data'] ?? ''), true);
    $normalized_demo = assessment_demographics_normalize(is_array($demo_decoded) ? $demo_decoded : []);

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
    $demographics = [
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

    if ($total >= 26) {
        $severity_label = 'Severe Mania';
        $severity_class = 'severe';
        $severity_color = '#dc2626';
    } elseif ($total >= 20) {
        $severity_label = 'Moderate Mania';
        $severity_class = 'moderate';
        $severity_color = '#ea580c';
    } elseif ($total >= 13) {
        $severity_label = 'Minimal / Mild';
        $severity_class = 'mild';
        $severity_color = '#d97706';
    } else {
        $severity_label = 'Normal / Remission';
        $severity_class = 'normal';
        $severity_color = '#0f766e';
    }

    foreach ($ymrs_items as $field => $item) {
        $is_rated = isset($decoded[$field]) && is_numeric($decoded[$field]);
        $value = $is_rated ? (int) $decoded[$field] : 0;
        if ($is_rated) {
            $rated_count++;
        }
        if ($value > 0) {
            $symptomatic_count++;
        }
        $scores[] = [
            'title' => $item['title'],
            'value' => $value,
            'label' => $item['ratings'][$value] ?? 'Not rated',
            'max' => max(array_keys($item['ratings'])),
        ];
    }
}

$clamped_total = max(0, min(60, $total));
$score_pct = (int) round(($clamped_total / 60) * 100);
$total_items_count = count($ymrs_items);

require 'layout/base.php';
?>
<style>
    :root {
        --yr-primary: #0f766e;
        --yr-primary-dark: #115e59;
        --yr-primary-soft: #f0fdfa;
        --yr-border: #e2e8f0;
        --yr-border-soft: #f1f5f9;
        --yr-text: #0f172a;
        --yr-muted: #64748b;
        --yr-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .ymrs-report {
        max-width: 1180px;
        margin: 0 auto;
        padding: 24px 18px 52px;
        color: var(--yr-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Top Action Bar */
    .ymrs-report-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 18px;
    }

    .ymrs-btn-back,
    .ymrs-btn-print {
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

    .ymrs-btn-back {
        background: #fff;
        color: #334155;
        border: 1px solid #cbd5e1;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }

    .ymrs-btn-back:hover {
        background: #f8fafc;
        color: var(--yr-text);
        border-color: #94a3b8;
    }

    .ymrs-btn-print {
        background: var(--yr-primary);
        color: #fff;
        border: 1px solid var(--yr-primary);
        box-shadow: 0 2px 8px rgba(15, 118, 110, 0.22);
    }

    .ymrs-btn-print:hover {
        background: var(--yr-primary-dark);
        border-color: var(--yr-primary-dark);
        transform: translateY(-1px);
    }

    /* Executive Hero Banner (with Integrated 2-Column Demographics) */
    .ymrs-hero {
        position: relative;
        overflow: hidden;
        padding: 26px 28px;
        border-radius: 16px;
        background: linear-gradient(135deg, #0f3f3c 0%, #0f766e 55%, #0891b2 100%);
        color: #fff;
        box-shadow: 0 14px 32px -8px rgba(15, 118, 110, 0.32);
    }

    .ymrs-hero::after {
        content: "";
        position: absolute;
        right: -50px;
        top: -70px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.14) 0%, rgba(255, 255, 255, 0) 70%);
        pointer-events: none;
    }

    .ymrs-hero-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }

    .ymrs-hero-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #ccfbf1;
        background: rgba(255, 255, 255, 0.14);
        padding: 4px 11px;
        border-radius: 999px;
        margin-bottom: 8px;
    }

    .ymrs-hero h1 {
        margin: 0;
        font-size: clamp(24px, 3.5vw, 31px);
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #fff;
    }

    .ymrs-hero-sub {
        margin: 5px 0 0;
        font-size: 13.5px;
        color: #ccfbf1;
    }

    .ymrs-hero-badge {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }

    .ymrs-date-pill {
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
    .ymrs-hero-demo-wrap {
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid rgba(255, 255, 255, 0.18);
        position: relative;
        z-index: 1;
    }

    .ymrs-hero-demo-title {
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.09em;
        color: #99f6e4;
        margin-bottom: 11px;
    }

    .ymrs-hero-demo-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 14px;
    }

    .ymrs-hero-demo-item {
        background: rgba(255, 255, 255, 0.10);
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 9px;
        padding: 8px 13px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .ymrs-hero-demo-item span {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #ccfbf1;
        opacity: 0.9;
    }

    .ymrs-hero-demo-item strong {
        display: block;
        margin-top: 2px;
        font-size: 13.5px;
        font-weight: 700;
        color: #fff;
        overflow-wrap: anywhere;
    }

    /* 4-Column KPI Strip */
    .ymrs-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-top: 18px;
    }

    .ymrs-kpi {
        background: #fff;
        border: 1px solid var(--yr-border);
        border-radius: 12px;
        padding: 15px 17px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        border-top: 3px solid var(--kpi-accent, var(--yr-primary));
    }

    .ymrs-kpi-label {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--yr-muted);
    }

    .ymrs-kpi-val {
        display: flex;
        align-items: baseline;
        gap: 4px;
        margin-top: 6px;
    }

    .ymrs-kpi-val strong {
        font-size: 24px;
        font-weight: 800;
        line-height: 1.1;
        color: var(--yr-text);
    }

    .ymrs-kpi-val strong.is-severity-text {
        font-size: 18px;
    }

    .ymrs-kpi-val span {
        font-size: 13px;
        font-weight: 700;
        color: #94a3b8;
    }

    .ymrs-kpi-sub {
        display: block;
        margin-top: 4px;
        font-size: 11px;
        color: var(--yr-muted);
    }

    /* Standard Section Card */
    .ymrs-card {
        background: #fff;
        border: 1px solid var(--yr-border);
        border-radius: 14px;
        box-shadow: var(--yr-shadow);
        overflow: hidden;
        margin-top: 18px;
    }

    .ymrs-card-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--yr-border);
        border-left: 4px solid var(--yr-primary);
        padding: 12px 18px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .ymrs-card-head h2 {
        margin: 0;
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--yr-text);
    }

    .ymrs-card-pill {
        background: var(--yr-primary-soft);
        color: var(--yr-primary);
        border: 1px solid #99f6e4;
        padding: 3px 11px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 800;
        white-space: nowrap;
    }

    .ymrs-card-body {
        padding: 18px 20px;
    }

    /* Full-Width Overall Score Row */
    .ymrs-overview-inner {
        display: grid;
        grid-template-columns: 460px minmax(0, 1fr);
        gap: 26px;
        align-items: center;
    }

    .ymrs-chart-wrap {
        position: relative;
        width: 100%;
        height: 255px;
        margin: 0 auto;
    }

    /* Severity Scale Legend */
    .ymrs-scale-guide {
        padding: 2px 0;
    }

    .ymrs-scale-title {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--yr-muted);
        margin-bottom: 10px;
    }

    .ymrs-scale-list {
        display: grid;
        grid-template-columns: 1fr;
        gap: 7px;
    }

    .ymrs-scale-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 9px 14px;
        border-radius: 8px;
        font-size: 13px;
        color: #475569;
        background: #f8fafc;
        border: 1px solid var(--yr-border-soft);
    }

    .ymrs-scale-item.active {
        background: #fff;
        border-color: #94a3b8;
        color: var(--yr-text);
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
    }

    .ymrs-dot {
        display: inline-block;
        width: 9px;
        height: 9px;
        border-radius: 50%;
        margin-right: 8px;
    }

    /* Summary Highlight Box inside Overall Score */
    .ymrs-summary-box {
        margin-top: 12px;
        padding: 12px 15px;
        border-radius: 11px;
        background: #f8fafc;
        border: 1px solid var(--yr-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .ymrs-summary-left {
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .ymrs-summary-icon {
        width: 36px;
        height: 36px;
        border-radius: 9px;
        background: var(--yr-primary-soft);
        color: var(--yr-primary);
        display: grid;
        place-items: center;
        font-size: 16px;
        flex-shrink: 0;
    }

    .ymrs-summary-title {
        font-size: 13px;
        font-weight: 800;
        color: var(--yr-text);
    }

    .ymrs-summary-sub {
        font-size: 11.5px;
        color: var(--yr-muted);
    }

    .ymrs-summary-val {
        padding: 5px 12px;
        border-radius: 9px;
        background: var(--yr-primary-soft);
        border: 1px solid #99f6e4;
        color: var(--yr-primary-dark);
        font-size: 18px;
        font-weight: 900;
        white-space: nowrap;
    }

    .ymrs-summary-val span {
        font-size: 12px;
        font-weight: 700;
        color: #0f766e;
    }

    /* Section Divider for Page 2 */
    .ymrs-section-divider {
        margin: 26px 0 14px;
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 12px;
    }

    .ymrs-section-divider h2 {
        margin: 0;
        font-size: 17px;
        font-weight: 800;
        color: var(--yr-text);
    }

    .ymrs-section-divider span {
        font-size: 12.5px;
        color: var(--yr-muted);
        font-weight: 600;
    }

    .ymrs-section-divider + .ymrs-card {
        margin-top: 0;
    }

    /* Question-Wise Q&A Table */
    .ymrs-qa-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .ymrs-qa-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 11px 14px;
        border-bottom: 1px solid var(--yr-border);
        text-align: left;
    }

    .ymrs-qa-table tbody td {
        padding: 12px 14px;
        border-bottom: 1px solid var(--yr-border-soft);
        vertical-align: middle;
    }

    .ymrs-qa-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .ymrs-qa-table tbody tr:hover {
        background: #fcfefd;
    }

    .ymrs-item-num {
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

    .ymrs-item-title {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--yr-text);
    }

    .ymrs-track {
        height: 6px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
        margin-top: 6px;
        max-width: 180px;
    }

    .ymrs-fill {
        display: block;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #0f766e, #14b8a6);
    }

    .ymrs-item-score {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 11px;
        border-radius: 999px;
        background: var(--yr-primary-soft);
        color: var(--yr-primary);
        border: 1px solid #99f6e4;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .ymrs-item-score.zero {
        background: #f8fafc;
        color: #64748b;
        border-color: #e2e8f0;
    }

    /* Clinical Notes Card */
    .ymrs-notes-text {
        margin: 0;
        white-space: pre-wrap;
        font-size: 13.5px;
        line-height: 1.65;
        color: #334155;
        background: #f8fafc;
        border: 1px solid var(--yr-border-soft);
        border-left: 3px solid var(--yr-primary);
        border-radius: 8px;
        padding: 14px 16px;
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

    /* Error / Empty State */
    .ymrs-empty {
        padding: 36px 24px;
        text-align: center;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 14px;
        color: #991b1b;
        font-weight: 600;
    }

    @media (max-width: 1024px) {
        .ymrs-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .ymrs-overview-inner {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 700px) {
        .ymrs-report {
            padding: 16px 12px 32px;
        }
        .ymrs-hero {
            padding: 20px 18px;
        }
        .ymrs-hero-badge {
            align-items: flex-start;
        }
        .ymrs-hero-demo-grid {
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

    @media (max-width: 480px) {
        .ymrs-kpi-grid {
            grid-template-columns: 1fr;
        }
    }

    /* PRINT LAYOUT: Strictly 2 Pages (Page 1 = Header + 2-Col Demographics + KPI + Overall Score, Page 2 = 11 Questions + Notes + Signature) */
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
        .ymrs-report-top,
        .admin-sidebar,
        .admin-navbar,
        .sidebar-backdrop {
            display: none !important;
        }
        .ymrs-report {
            max-width: 100% !important;
            padding: 9mm 10mm !important;
            margin: 0 !important;
        }

        /* ========== PAGE 1: FULL-PAGE EXECUTIVE FIT ========== */
        .ymrs-hero {
            padding: 16px 20px !important;
            border-radius: 12px !important;
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .ymrs-hero-eyebrow {
            margin-bottom: 5px !important;
            font-size: 9px !important;
            padding: 2px 9px !important;
        }
        .ymrs-hero h1 {
            font-size: 20px !important;
        }
        .ymrs-hero-sub {
            font-size: 11px !important;
        }
        .ymrs-hero-demo-wrap {
            margin-top: 12px !important;
            padding-top: 10px !important;
        }
        .ymrs-hero-demo-title {
            margin-bottom: 7px !important;
            font-size: 9.5px !important;
        }
        .ymrs-hero-demo-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 6px 12px !important;
        }
        .ymrs-hero-demo-item {
            padding: 5px 10px !important;
        }
        .ymrs-hero-demo-item span {
            font-size: 8.5px !important;
        }
        .ymrs-hero-demo-item strong {
            font-size: 11.5px !important;
        }

        /* 4 KPI cards in 1 row */
        .ymrs-kpi-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 10px !important;
            margin-top: 14px !important;
        }
        .ymrs-kpi {
            padding: 11px 13px !important;
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .ymrs-kpi-label {
            font-size: 9px !important;
        }
        .ymrs-kpi-val strong {
            font-size: 19px !important;
        }
        .ymrs-kpi-val strong.is-severity-text {
            font-size: 14.5px !important;
        }
        .ymrs-kpi-sub {
            font-size: 9.5px !important;
            margin-top: 4px !important;
        }

        /* Overall Score Card on Page 1 */
        .ymrs-overview-card {
            margin-top: 14px !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .ymrs-card-head {
            padding: 9px 15px !important;
            break-after: avoid;
            page-break-after: avoid;
        }
        .ymrs-card-head h2 {
            font-size: 11.5px !important;
        }
        .ymrs-card-body {
            padding: 14px 16px !important;
        }
        .ymrs-overview-inner {
            display: flex !important;
            flex-direction: column !important;
            gap: 14px !important;
            align-items: stretch !important;
        }
        .ymrs-chart-wrap {
            width: 560px !important;
            max-width: 100% !important;
            height: 245px !important;
            margin: 0 auto !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
        }
        .ymrs-chart-wrap canvas {
            margin: 0 auto !important;
            display: block !important;
        }
        .ymrs-scale-title {
            margin-bottom: 8px !important;
        }
        .ymrs-scale-list {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 8px 12px !important;
        }
        .ymrs-scale-item {
            padding: 7px 12px !important;
            font-size: 11.5px !important;
        }
        .ymrs-summary-box {
            margin-top: 10px !important;
            padding: 9px 13px !important;
        }
        .ymrs-summary-title {
            font-size: 12px !important;
        }
        .ymrs-summary-sub {
            font-size: 10.5px !important;
        }
        .ymrs-summary-val {
            font-size: 16px !important;
            padding: 4px 10px !important;
        }

        /* ========== PAGE 2: QUESTION DETAILS + NOTES + SIGNATURE ========== */
        .print-page-break {
            break-before: page !important;
            page-break-before: always !important;
            margin-top: 0 !important;
            padding-top: 9mm !important;
            margin-bottom: 12px !important;
        }
        .print-page-break h2 {
            font-size: 15px !important;
        }

        .ymrs-qa-table thead th {
            padding: 8px 12px !important;
            font-size: 10px !important;
        }
        .ymrs-qa-table tbody td {
            padding: 8px 12px !important;
            font-size: 11.5px !important;
        }
        .ymrs-qa-table tbody tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .ymrs-item-num {
            width: 22px !important;
            height: 22px !important;
            font-size: 10px !important;
        }
        .ymrs-item-title {
            font-size: 11.5px !important;
        }
        .ymrs-track {
            margin-top: 4px !important;
            height: 5px !important;
        }
        .ymrs-item-score {
            padding: 2.5px 9px !important;
            font-size: 10.5px !important;
        }
        .ymrs-notes-card {
            margin-top: 12px !important;
        }
        .ymrs-notes-text {
            padding: 10px 12px !important;
            font-size: 11px !important;
            line-height: 1.45 !important;
        }
        .report-sign-footer {
            margin-top: 26px !important;
            padding-top: 14px !important;
        }
        .report-sign-line {
            margin-top: 38px !important;
        }
        .ymrs-card {
            box-shadow: none !important;
            overflow: visible !important;
            break-inside: auto !important;
            page-break-inside: auto !important;
        }
    }
</style>

<div class="ymrs-report">
    <div class="ymrs-report-top">
        <a class="ymrs-btn-back" href="ymrs-assessment-records.php">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Records
        </a>
        <?php if ($record): ?>
            <button type="button" class="ymrs-btn-print" onclick="window.print()">
                <i class="bi bi-printer" aria-hidden="true"></i> Print Report
            </button>
        <?php endif; ?>
    </div>

    <?php if (!$record): ?>
        <div class="ymrs-empty" role="alert"><?php echo ymrs_score_escape($load_error); ?></div>
    <?php else: ?>
        <!-- HERO HEADER WITH 2-COLUMN CLIENT & CLINICAL DETAILS -->
        <header class="ymrs-hero">
            <div class="ymrs-hero-top">
                <div>
                    <div class="ymrs-hero-eyebrow">Clinical Assessment Report</div>
                    <h1>YMRS Score Overview</h1>
                    <p class="ymrs-hero-sub">Young Mania Rating Scale &middot; 11-Item Clinician-Rated Assessment</p>
                </div>
                <div class="ymrs-hero-badge">
                    <span class="ymrs-date-pill">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        Date: <?php echo ymrs_score_escape($formatted_date); ?>
                    </span>
                </div>
            </div>

            <div class="ymrs-hero-demo-wrap">
                <div class="ymrs-hero-demo-title">Patient &amp; Clinical Details</div>
                <div class="ymrs-hero-demo-grid">
                    <?php foreach ($demographics as $demo_label => $demo_val): ?>
                        <div class="ymrs-hero-demo-item">
                            <span><?php echo ymrs_score_escape($demo_label); ?></span>
                            <strong><?php echo ymrs_score_escape($demo_val); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <!-- 4-COLUMN KPI STRIP -->
        <div class="ymrs-kpi-grid">
            <div class="ymrs-kpi" style="--kpi-accent: #0f766e;">
                <span class="ymrs-kpi-label">Total YMRS Score</span>
                <div class="ymrs-kpi-val">
                    <strong><?php echo (int) $total; ?></strong>
                    <span>/ 60</span>
                </div>
                <span class="ymrs-kpi-sub"><?php echo $total_items_count; ?> clinician-rated items</span>
            </div>
            <div class="ymrs-kpi" style="--kpi-accent: #0ea5e9;">
                <span class="ymrs-kpi-label">Severity Percentage</span>
                <div class="ymrs-kpi-val">
                    <strong><?php echo $score_pct; ?>%</strong>
                </div>
                <span class="ymrs-kpi-sub">Of maximum scale score (60)</span>
            </div>
            <div class="ymrs-kpi" style="--kpi-accent: <?php echo ymrs_score_escape($severity_color); ?>;">
                <span class="ymrs-kpi-label">Clinical Severity</span>
                <div class="ymrs-kpi-val">
                    <strong class="is-severity-text" style="color: <?php echo ymrs_score_escape($severity_color); ?>;">
                        <?php echo ymrs_score_escape($severity_label); ?>
                    </strong>
                </div>
                <span class="ymrs-kpi-sub">Mania severity classification</span>
            </div>
            <div class="ymrs-kpi" style="--kpi-accent: #4f46e5;">
                <span class="ymrs-kpi-label">Items Evaluated</span>
                <div class="ymrs-kpi-val">
                    <strong><?php echo $rated_count; ?></strong>
                    <span>/ <?php echo $total_items_count; ?></span>
                </div>
                <span class="ymrs-kpi-sub"><?php echo $symptomatic_count; ?> items rated above 0</span>
            </div>
        </div>

        <!-- FULL-WIDTH OVERALL SCORE & SEVERITY GUIDE (STAYS ON PAGE 1) -->
        <section class="ymrs-card ymrs-overview-card">
            <div class="ymrs-card-head">
                <h2>Overall Score &amp; Severity Classification</h2>
                <span class="ymrs-card-pill"><?php echo ymrs_score_escape($severity_label); ?></span>
            </div>
            <div class="ymrs-card-body">
                <div class="ymrs-overview-inner">
                    <div class="ymrs-chart-wrap">
                        <canvas id="ymrsChart"></canvas>
                    </div>

                    <div class="ymrs-scale-guide">
                        <div class="ymrs-scale-title">Severity Reference Guide</div>
                        <div class="ymrs-scale-list">
                            <div class="ymrs-scale-item<?php echo $severity_class === 'normal' ? ' active' : ''; ?>">
                                <span><i class="ymrs-dot" style="background:#0f766e;"></i>Normal / Remission</span>
                                <span>0 – 12</span>
                            </div>
                            <div class="ymrs-scale-item<?php echo $severity_class === 'mild' ? ' active' : ''; ?>">
                                <span><i class="ymrs-dot" style="background:#d97706;"></i>Minimal / Mild</span>
                                <span>13 – 19</span>
                            </div>
                            <div class="ymrs-scale-item<?php echo $severity_class === 'moderate' ? ' active' : ''; ?>">
                                <span><i class="ymrs-dot" style="background:#ea580c;"></i>Moderate Mania</span>
                                <span>20 – 25</span>
                            </div>
                            <div class="ymrs-scale-item<?php echo $severity_class === 'severe' ? ' active' : ''; ?>">
                                <span><i class="ymrs-dot" style="background:#dc2626;"></i>Severe Mania</span>
                                <span>26 – 60</span>
                            </div>
                        </div>

                        <div class="ymrs-summary-box">
                            <div class="ymrs-summary-left">
                                <span class="ymrs-summary-icon" aria-hidden="true"><i class="bi bi-clipboard2-pulse"></i></span>
                                <div>
                                    <div class="ymrs-summary-title">Total Assessment Score</div>
                                    <div class="ymrs-summary-sub">Sum of all <?php echo $total_items_count; ?> YMRS clinical items (<?php echo $score_pct; ?>%)</div>
                                </div>
                            </div>
                            <div class="ymrs-summary-val">
                                <?php echo (int) $total; ?><span> / 60</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- QUESTION-WISE ANSWERS & SCORES (STARTS ON PAGE 2 IN PRINT) -->
        <div class="ymrs-section-divider print-page-break">
            <h2>Question-Wise Answers &amp; Scores</h2>
            <span>11 Items &middot; Clinical Rating Breakdown</span>
        </div>

        <section class="ymrs-card">
            <div class="ymrs-card-head">
                <h2>Item-Level Symptom Ratings (<?php echo count($scores); ?>)</h2>
                <span class="ymrs-card-pill"><?php echo (int) $total; ?> / 60</span>
            </div>
            <div class="table-responsive">
                <table class="ymrs-qa-table">
                    <thead>
                        <tr>
                            <th style="width: 56px;">#</th>
                            <th style="width: 34%;">Question / Item</th>
                            <th>Selected Answer</th>
                            <th style="width: 105px; text-align: right;">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scores as $index => $item):
                            $item_pct = $item['max'] > 0 ? min(100, round(($item['value'] / $item['max']) * 100)) : 0;
                        ?>
                            <tr>
                                <td>
                                    <span class="ymrs-item-num"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                                </td>
                                <td>
                                    <div class="ymrs-item-title"><?php echo ymrs_score_escape($item['title']); ?></div>
                                    <div class="ymrs-track">
                                        <span class="ymrs-fill" style="width: <?php echo $item_pct; ?>%;"></span>
                                    </div>
                                </td>
                                <td style="color: #334155; line-height: 1.45;">
                                    <?php echo ymrs_score_escape($item['label']); ?>
                                </td>
                                <td style="text-align: right;">
                                    <span class="ymrs-item-score<?php echo $item['value'] === 0 ? ' zero' : ''; ?>">
                                        <?php echo (int) $item['value']; ?> / <?php echo (int) $item['max']; ?>
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
            <section class="ymrs-card ymrs-notes-card">
                <div class="ymrs-card-head">
                    <h2>Clinical Notes &amp; Observations</h2>
                </div>
                <div class="ymrs-card-body">
                    <p class="ymrs-notes-text"><?php echo ymrs_score_escape($notes); ?></p>
                </div>
            </section>
        <?php endif; ?>

        <!-- Simple Invoice-Style Signature Footer -->
        <div class="report-sign-footer">
            <div class="report-sign-block">
                <div class="report-sign-line">
                    <div class="report-sign-title"><?php echo ymrs_score_escape($clinician_name); ?></div>
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
    document.addEventListener('DOMContentLoaded', function() {
        var canvas = document.getElementById('ymrsChart');
        if (!canvas) return;

        var totalScore = <?php echo $clamped_total; ?>;
        var scorePct = <?php echo $score_pct; ?>;
        var activeIndex = <?php echo $total >= 26 ? 3 : ($total >= 20 ? 2 : ($total >= 13 ? 1 : 0)); ?>;

        var ymrsBands = [
            { label: 'Normal / Remission', range: '0–12', size: 12, color: '#0f766e' },
            { label: 'Minimal / Mild',     range: '13–19', size: 7,  color: '#d97706' },
            { label: 'Moderate Mania',     range: '20–25', size: 6,  color: '#ea580c' },
            { label: 'Severe Mania',       range: '26–60', size: 35, color: '#dc2626' }
        ];

        var ymrsCalloutAndCenter = {
            id: 'ymrsCalloutAndCenter',
            afterDatasetsDraw: function(chart) {
                var ctx = chart.ctx;
                var meta = chart.getDatasetMeta(0);
                if (!meta.data || !meta.data.length) return;

                ctx.save();

                // 1. Exact center me Total Score + Percentage
                var centerX = meta.data[0].x;
                var centerY = meta.data[0].y;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                ctx.fillStyle = '#0f172a';
                ctx.font = '900 30px system-ui, -apple-system, sans-serif';
                ctx.fillText(String(totalScore), centerX, centerY - 14);

                ctx.fillStyle = '#64748b';
                ctx.font = '700 11.5px system-ui, -apple-system, sans-serif';
                ctx.fillText('out of 60', centerX, centerY + 9);

                ctx.fillStyle = ymrsBands[activeIndex].color;
                ctx.font = '800 11px system-ui, -apple-system, sans-serif';
                ctx.fillText(scorePct + '% of max', centerX, centerY + 25);

                // 2. Har color slice ke bagal me Name + Range (anti-overlap ke saath)
                var lastRightY = -999;
                var lastLeftY = -999;

                meta.data.forEach(function(arc, index) {
                    var band = ymrsBands[index];
                    var angle = (arc.startAngle + arc.endAngle) / 2;
                    var cos = Math.cos(angle);
                    var sin = Math.sin(angle);
                    var isRight = cos >= 0;

                    var x1 = arc.x + cos * (arc.outerRadius + 2);
                    var y1 = arc.y + sin * (arc.outerRadius + 2);
                    var x2 = arc.x + cos * (arc.outerRadius + 12);
                    var y2 = arc.y + sin * (arc.outerRadius + 12);

                    var minGap = 32;
                    if (isRight) {
                        if (y2 - lastRightY < minGap) y2 = lastRightY + minGap;
                        lastRightY = y2;
                    } else {
                        if (y2 - lastLeftY < minGap) y2 = lastLeftY + minGap;
                        lastLeftY = y2;
                    }

                    var x3 = x2 + (isRight ? 10 : -10);

                    ctx.beginPath();
                    ctx.moveTo(x1, y1);
                    ctx.lineTo(x2, y2);
                    ctx.lineTo(x3, y2);
                    ctx.strokeStyle = index === activeIndex ? band.color : '#64748b';
                    ctx.lineWidth = index === activeIndex ? 1.8 : 1.1;
                    ctx.stroke();

                    var textX = x3 + (isRight ? 5 : -5);
                    ctx.textAlign = isRight ? 'left' : 'right';
                    ctx.fillStyle = index === activeIndex ? '#0f172a' : '#334155';
                    ctx.font = (index === activeIndex ? '800' : '700') + ' 10.5px system-ui, -apple-system, sans-serif';
                    ctx.fillText(band.label, textX, y2 - 6);

                    ctx.fillStyle = '#64748b';
                    ctx.font = '600 10px system-ui, -apple-system, sans-serif';
                    ctx.fillText('(' + band.range + ')', textX, y2 + 7);
                });

                ctx.restore();
            }
        };

        var ymrsChart = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: ymrsBands.map(function(b) { return b.label; }),
                datasets: [{
                    data: ymrsBands.map(function(b) { return b.size; }),
                    backgroundColor: ymrsBands.map(function(b) { return b.color; }),
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
                    padding: { top: 24, bottom: 24, left: 135, right: 135 }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var b = ymrsBands[ctx.dataIndex];
                                return ' ' + b.label + ' (' + b.range + ')';
                            }
                        }
                    }
                }
            },
            plugins: [ymrsCalloutAndCenter]
        });

        window.addEventListener('beforeprint', function() {
            ymrsChart.resize(560, 245);
        });
        window.addEventListener('afterprint', function() {
            ymrsChart.resize();
        });
    });
</script>
<?php require 'layout/footer.php'; ?>