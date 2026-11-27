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
$rated_count = 0;
$present_count = 0;
$patient_name = '';
$clinician_name = '';
$formatted_date = '—';
$notes = '';

if ($record) {
    $saved_scores = json_decode((string) $record['score_data'], true);
    $saved_scores = is_array($saved_scores) ? $saved_scores : [];
    foreach ($items as $field => $label) {
        $is_rated = isset($saved_scores[$field]) && is_numeric($saved_scores[$field]);
        $score = $is_rated ? max(0, min(7, (int) $saved_scores[$field])) : 0;
        if ($score > 0) {
            $rated_count++;
        }
        if ($score > 1) {
            $present_count++;
        }
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
}

$clamped_total = max(0, min(126, $total_score));
$chart_remaining = 126 - $clamped_total;
$score_pct = (int) round(($clamped_total / 126) * 100);
$total_items_count = count($items);

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
        padding: 24px 18px 52px;
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

    /* Executive Hero Banner (with Integrated 2-Column Demographics) */
    .bprs-hero {
        position: relative;
        overflow: hidden;
        padding: 26px 28px;
        border-radius: 16px;
        background: linear-gradient(135deg, #0f3f3c 0%, #0f766e 55%, #0891b2 100%);
        color: #fff;
        box-shadow: 0 14px 32px -8px rgba(15, 118, 110, 0.32);
    }

    .bprs-hero::after {
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
        background: rgba(255, 255, 255, 0.14);
        padding: 4px 11px;
        border-radius: 999px;
        margin-bottom: 8px;
    }

    .bprs-hero h1 {
        margin: 0;
        font-size: clamp(24px, 3.5vw, 31px);
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #fff;
    }

    .bprs-hero-sub {
        margin: 5px 0 0;
        font-size: 13.5px;
        color: #ccfbf1;
    }

    .bprs-hero-badge {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }

    .bprs-date-pill {
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
    .bprs-hero-demo-wrap {
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid rgba(255, 255, 255, 0.18);
        position: relative;
        z-index: 1;
    }

    .bprs-hero-demo-title {
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.09em;
        color: #99f6e4;
        margin-bottom: 11px;
    }

    .bprs-hero-demo-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 14px;
    }

    .bprs-hero-demo-item {
        background: rgba(255, 255, 255, 0.10);
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 9px;
        padding: 8px 13px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .bprs-hero-demo-item span {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #ccfbf1;
        opacity: 0.9;
    }

    .bprs-hero-demo-item strong {
        display: block;
        margin-top: 2px;
        font-size: 13.5px;
        font-weight: 700;
        color: #fff;
        overflow-wrap: anywhere;
    }

    /* 4-Column KPI Strip */
    .bprs-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-top: 18px;
    }

    .bprs-kpi {
        background: #fff;
        border: 1px solid var(--br-border);
        border-radius: 12px;
        padding: 15px 17px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        border-top: 3px solid var(--kpi-accent, var(--br-primary));
    }

    .bprs-kpi-label {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--br-muted);
    }

    .bprs-kpi-val {
        display: flex;
        align-items: baseline;
        gap: 4px;
        margin-top: 6px;
    }

    .bprs-kpi-val strong {
        font-size: 24px;
        font-weight: 800;
        line-height: 1.1;
        color: var(--br-text);
    }

    .bprs-kpi-val span {
        font-size: 13px;
        font-weight: 700;
        color: #94a3b8;
    }

    .bprs-kpi-sub {
        display: block;
        margin-top: 4px;
        font-size: 11px;
        color: var(--br-muted);
    }

    /* Standard Section Card */
    .bprs-card {
        background: #fff;
        border: 1px solid var(--br-border);
        border-radius: 14px;
        box-shadow: var(--br-shadow);
        overflow: hidden;
        margin-top: 18px;
    }

    .bprs-card-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--br-border);
        border-left: 4px solid var(--br-primary);
        padding: 12px 18px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .bprs-card-head h2 {
        margin: 0;
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--br-text);
    }

    .bprs-card-pill {
        background: var(--br-primary-soft);
        color: var(--br-primary);
        border: 1px solid #99f6e4;
        padding: 3px 11px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 800;
        white-space: nowrap;
    }

    .bprs-card-body {
        padding: 18px 20px;
    }

    /* Full-Width Overall Score Row */
    .bprs-overview-inner {
        display: grid;
        grid-template-columns: 440px minmax(0, 1fr);
        gap: 26px;
        align-items: center;
    }

    .bprs-chart-wrap {
        position: relative;
        width: 100%;
        height: 250px;
        margin: 0 auto;
    }

    .bprs-stats-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .bprs-stat-box {
        background: #f8fafc;
        border: 1px solid var(--br-border-soft);
        border-radius: 10px;
        padding: 12px 15px;
    }

    .bprs-stat-box span {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--br-muted);
    }

    .bprs-stat-box strong {
        display: block;
        margin-top: 4px;
        font-size: 19px;
        font-weight: 800;
        color: var(--br-text);
    }

    /* Summary Highlight Box inside Overall Score */
    .bprs-summary-box {
        margin-top: 12px;
        padding: 12px 15px;
        border-radius: 11px;
        background: #f8fafc;
        border: 1px solid var(--br-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .bprs-summary-left {
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .bprs-summary-icon {
        width: 36px;
        height: 36px;
        border-radius: 9px;
        background: var(--br-primary-soft);
        color: var(--br-primary);
        display: grid;
        place-items: center;
        font-size: 16px;
        flex-shrink: 0;
    }

    .bprs-summary-title {
        font-size: 13px;
        font-weight: 800;
        color: var(--br-text);
    }

    .bprs-summary-sub {
        font-size: 11.5px;
        color: var(--br-muted);
    }

    .bprs-summary-val {
        padding: 5px 12px;
        border-radius: 9px;
        background: var(--br-primary-soft);
        border: 1px solid #99f6e4;
        color: var(--br-primary-dark);
        font-size: 18px;
        font-weight: 900;
        white-space: nowrap;
    }

    .bprs-summary-val span {
        font-size: 12px;
        font-weight: 700;
        color: #0f766e;
    }

    /* Section Divider for Page 2 */
    .bprs-section-divider {
        margin: 26px 0 14px;
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 12px;
    }

    .bprs-section-divider h2 {
        margin: 0;
        font-size: 17px;
        font-weight: 800;
        color: var(--br-text);
    }

    .bprs-section-divider span {
        font-size: 12.5px;
        color: var(--br-muted);
        font-weight: 600;
    }

    .bprs-section-divider + .bprs-card {
        margin-top: 0;
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
        padding: 11px 14px;
        border-bottom: 1px solid var(--br-border);
        text-align: left;
    }

    .bprs-qa-table tbody td {
        padding: 11px 14px;
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
        font-size: 13.5px;
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

    .bprs-empty {
        padding: 36px 24px;
        text-align: center;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 14px;
        color: #991b1b;
        font-weight: 600;
    }

    @media (max-width: 1024px) {
        .bprs-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .bprs-overview-inner {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 700px) {
        .bprs-report {
            padding: 16px 12px 32px;
        }
        .bprs-hero {
            padding: 20px 18px;
        }
        .bprs-hero-badge {
            align-items: flex-start;
        }
        .bprs-hero-demo-grid,
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

    @media (max-width: 480px) {
        .bprs-kpi-grid {
            grid-template-columns: 1fr;
        }
    }

    /* PRINT LAYOUT: Strictly 2 Pages (Page 1 = Header + 2-Col Demographics + KPI + Overall Score, Page 2 = 18 Questions + Notes + Signature) */
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
        .bprs-report-top,
        .admin-sidebar,
        .admin-navbar,
        .sidebar-backdrop {
            display: none !important;
        }
        .bprs-report {
            max-width: 100% !important;
            padding: 9mm 10mm !important;
            margin: 0 !important;
        }

        /* ========== PAGE 1: FULL-PAGE EXECUTIVE FIT ========== */
        .bprs-hero {
            padding: 16px 20px !important;
            border-radius: 12px !important;
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .bprs-hero-eyebrow {
            margin-bottom: 5px !important;
            font-size: 9px !important;
            padding: 2px 9px !important;
        }
        .bprs-hero h1 {
            font-size: 20px !important;
        }
        .bprs-hero-sub {
            font-size: 11px !important;
        }
        .bprs-hero-demo-wrap {
            margin-top: 12px !important;
            padding-top: 10px !important;
        }
        .bprs-hero-demo-title {
            margin-bottom: 7px !important;
            font-size: 9.5px !important;
        }
        .bprs-hero-demo-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 6px 12px !important;
        }
        .bprs-hero-demo-item {
            padding: 5px 10px !important;
        }
        .bprs-hero-demo-item span {
            font-size: 8.5px !important;
        }
        .bprs-hero-demo-item strong {
            font-size: 11.5px !important;
        }

        /* 4 KPI cards in 1 row */
        .bprs-kpi-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 10px !important;
            margin-top: 14px !important;
        }
        .bprs-kpi {
            padding: 11px 13px !important;
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .bprs-kpi-label {
            font-size: 9px !important;
        }
        .bprs-kpi-val strong {
            font-size: 19px !important;
        }
        .bprs-kpi-sub {
            font-size: 9.5px !important;
            margin-top: 4px !important;
        }

        /* Overall Score Card on Page 1 */
        .bprs-overview-card {
            margin-top: 14px !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .bprs-card-head {
            padding: 9px 15px !important;
            break-after: avoid;
            page-break-after: avoid;
        }
        .bprs-card-head h2 {
            font-size: 11.5px !important;
        }
        .bprs-card-body {
            padding: 14px 16px !important;
        }
        .bprs-overview-inner {
            display: flex !important;
            flex-direction: column !important;
            gap: 14px !important;
            align-items: stretch !important;
        }
        .bprs-chart-wrap {
            width: 560px !important;
            max-width: 100% !important;
            height: 245px !important;
            margin: 0 auto !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
        }
        .bprs-chart-wrap canvas {
            margin: 0 auto !important;
            display: block !important;
        }
        .bprs-stats-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 9px !important;
        }
        .bprs-stat-box {
            padding: 8px 11px !important;
        }
        .bprs-stat-box span {
            font-size: 8.5px !important;
        }
        .bprs-stat-box strong {
            font-size: 14px !important;
            margin-top: 2px !important;
        }
        .bprs-summary-box {
            margin-top: 10px !important;
            padding: 9px 13px !important;
        }
        .bprs-summary-title {
            font-size: 12px !important;
        }
        .bprs-summary-sub {
            font-size: 10.5px !important;
        }
        .bprs-summary-val {
            font-size: 16px !important;
            padding: 4px 10px !important;
        }

        /* ========== PAGE 2: 18 QUESTION DETAILS + NOTES + SIGNATURE ========== */
        .print-page-break {
            break-before: page !important;
            page-break-before: always !important;
            margin-top: 0 !important;
            padding-top: 8mm !important;
            margin-bottom: 10px !important;
        }
        .print-page-break h2 {
            font-size: 14.5px !important;
        }

        .bprs-qa-table thead th {
            padding: 6px 10px !important;
            font-size: 9.5px !important;
        }
        .bprs-qa-table tbody td {
            padding: 5.5px 10px !important;
            font-size: 10.5px !important;
        }
        .bprs-qa-table tbody tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .bprs-item-num {
            width: 20px !important;
            height: 20px !important;
            font-size: 9.5px !important;
        }
        .bprs-item-title {
            font-size: 10.5px !important;
        }
        .bprs-track {
            margin-top: 3px !important;
            height: 4px !important;
        }
        .bprs-item-score {
            padding: 2px 8px !important;
            font-size: 9.5px !important;
        }
        .bprs-notes-card {
            margin-top: 10px !important;
        }
        .bprs-notes-text {
            padding: 8px 11px !important;
            font-size: 10.5px !important;
            line-height: 1.4 !important;
        }
        .report-sign-footer {
            margin-top: 18px !important;
            padding-top: 10px !important;
        }
        .report-sign-line {
            margin-top: 32px !important;
        }
        .bprs-card {
            box-shadow: none !important;
            overflow: visible !important;
            break-inside: auto !important;
            page-break-inside: auto !important;
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
        <!-- HERO HEADER WITH 2-COLUMN CLIENT & CLINICAL DETAILS -->
        <header class="bprs-hero">
            <div class="bprs-hero-top">
                <div>
                    <div class="bprs-hero-eyebrow">Clinical Assessment Report</div>
                    <h1>BPRS Score Overview</h1>
                    <p class="bprs-hero-sub">Brief Psychiatric Rating Scale &middot; 18-Item Clinician-Rated Assessment</p>
                </div>
                <div class="bprs-hero-badge">
                    <span class="bprs-date-pill">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        Date: <?php echo bprs_score_escape($formatted_date); ?>
                    </span>
                </div>
            </div>

            <div class="bprs-hero-demo-wrap">
                <div class="bprs-hero-demo-title">Patient &amp; Clinical Details</div>
                <div class="bprs-hero-demo-grid">
                    <?php foreach ($demographics as $demo_label => $demo_val): ?>
                        <div class="bprs-hero-demo-item">
                            <span><?php echo bprs_score_escape($demo_label); ?></span>
                            <strong><?php echo bprs_score_escape($demo_val); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <!-- 4-COLUMN KPI STRIP -->
        <div class="bprs-kpi-grid">
            <div class="bprs-kpi" style="--kpi-accent: #0f766e;">
                <span class="bprs-kpi-label">Total BPRS Score</span>
                <div class="bprs-kpi-val">
                    <strong><?php echo (int) $total_score; ?></strong>
                    <span>/ 126</span>
                </div>
                <span class="bprs-kpi-sub"><?php echo $total_items_count; ?> clinician-rated items</span>
            </div>
            <div class="bprs-kpi" style="--kpi-accent: #0ea5e9;">
                <span class="bprs-kpi-label">Score Percentage</span>
                <div class="bprs-kpi-val">
                    <strong><?php echo $score_pct; ?>%</strong>
                </div>
                <span class="bprs-kpi-sub">Of maximum scale score (126)</span>
            </div>
            <div class="bprs-kpi" style="--kpi-accent: #4f46e5;">
                <span class="bprs-kpi-label">Items Evaluated</span>
                <div class="bprs-kpi-val">
                    <strong><?php echo $rated_count; ?></strong>
                    <span>/ <?php echo $total_items_count; ?></span>
                </div>
                <span class="bprs-kpi-sub"><?php echo $present_count; ?> symptoms rated present (&gt;1)</span>
            </div>
            <div class="bprs-kpi" style="--kpi-accent: #10b981;">
                <span class="bprs-kpi-label">Item Rating Scale</span>
                <div class="bprs-kpi-val">
                    <strong>1 – 7</strong>
                </div>
                <span class="bprs-kpi-sub">Not present to extremely severe</span>
            </div>
        </div>

        <!-- FULL-WIDTH OVERALL SCORE & SUMMARY (STAYS ON PAGE 1) -->
        <section class="bprs-card bprs-overview-card">
            <div class="bprs-card-head">
                <h2>Overall Score &amp; Summary</h2>
                <span class="bprs-card-pill">Total: <?php echo (int) $total_score; ?> / 126</span>
            </div>
            <div class="bprs-card-body">
                <div class="bprs-overview-inner">
                    <div class="bprs-chart-wrap">
                        <canvas id="bprsScoreChart" role="img" aria-label="BPRS total score <?php echo $total_score; ?> out of 126"></canvas>
                    </div>

                    <div>
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
                                <span>Items Evaluated</span>
                                <strong><?php echo $rated_count; ?> / <?php echo $total_items_count; ?> Items</strong>
                            </div>
                            <div class="bprs-stat-box">
                                <span>Symptoms Present</span>
                                <strong><?php echo $present_count; ?> Items</strong>
                            </div>
                        </div>

                        <div class="bprs-summary-box">
                            <div class="bprs-summary-left">
                                <span class="bprs-summary-icon" aria-hidden="true"><i class="bi bi-clipboard2-pulse"></i></span>
                                <div>
                                    <div class="bprs-summary-title">Total Assessment Score</div>
                                    <div class="bprs-summary-sub">Sum of all <?php echo $total_items_count; ?> BPRS clinical items (<?php echo $score_pct; ?>% of max)</div>
                                </div>
                            </div>
                            <div class="bprs-summary-val">
                                <?php echo (int) $total_score; ?><span> / 126</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- QUESTION-WISE ANSWERS & SCORES (STARTS ON PAGE 2 IN PRINT) -->
        <div class="bprs-section-divider print-page-break">
            <h2>Question-Wise Answers &amp; Scores</h2>
            <span>18 Items &middot; Clinical Rating Breakdown</span>
        </div>

        <section class="bprs-card">
            <div class="bprs-card-head">
                <h2>Item-Level Symptom Ratings (<?php echo count($scores); ?>)</h2>
                <span class="bprs-card-pill"><?php echo (int) $total_score; ?> / 126</span>
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
            <section class="bprs-card bprs-notes-card">
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

    var bprsChart = new Chart(canvas, {
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
                padding: { top: 20, bottom: 20, left: 115, right: 115 }
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

    window.addEventListener('beforeprint', function () {
        bprsChart.resize(560, 245);
    });
    window.addEventListener('afterprint', function () {
        bprsChart.resize();
    });
});
</script>
<?php require 'layout/footer.php'; ?>