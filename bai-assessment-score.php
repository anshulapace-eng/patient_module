<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require 'config.php';
require 'assessment-demographics.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

function bai_score_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bai_item_status_meta($score)
{
    $s = max(0, min(3, (int) $score));
    if ($s === 0) {
        return ['label' => 'Not at all', 'cls' => 'is-none', 'pct' => 0];
    }
    if ($s === 1) {
        return ['label' => 'Mildly', 'cls' => 'is-mild', 'pct' => 33];
    }
    if ($s === 2) {
        return ['label' => 'Moderately', 'cls' => 'is-mod', 'pct' => 67];
    }
    return ['label' => 'Severely', 'cls' => 'is-sev', 'pct' => 100];
}

$page_title = 'BAI Score Report - Vimhans Academic';
$record = null;
$load_error = '';
$response_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($response_id === false || $response_id < 1) {
    http_response_code(400);
    $load_error = 'The assessment link is invalid.';
} else {
    $stmt = $conn->prepare(
        'SELECT ar.id, ar.assessment_date, ar.total_score, ar.status_text, ar.responses_data, ar.demographic_data,
                TRIM(CONCAT(COALESCE(p.first_name, ""), " ", COALESCE(NULLIF(p.last_name, "."), ""))) AS patient_name
         FROM assessment_responses ar
         INNER JOIN assigned_assessments aa ON aa.id = ar.assignment_id AND aa.assessment_id = 2
         INNER JOIN users p ON p.id = ar.patient_id AND p.role_id = 2 AND p.patient_id = ' . PATIENT_SCOPE_ID . '
         WHERE ar.id = ? LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('i', $response_id);
        if ($stmt->execute()) {
            $record = $stmt->get_result()->fetch_assoc() ?: null;
            if (!$record) {
                http_response_code(404);
                $load_error = 'This assessment record was not found.';
            }
        } else {
            http_response_code(500);
            $load_error = 'The score report could not be loaded.';
        }
        $stmt->close();
    } else {
        $load_error = 'The score report could not be loaded.';
    }
}

$responses = [];
$demographics = [];
$total_score = 0;
$patient_name = 'Unknown patient';
$status_text = 'Completed';
$assessment_date = date('Y-m-d');
$formatted_date = '—';

if ($record) {
    $total_score = (int) $record['total_score'];
    $patient_name = trim((string) ($record['patient_name'] ?? '')) ?: 'Unknown patient';
    $status_text = (string) ($record['status_text'] ?? 'Completed');
    $assessment_date = (string) ($record['assessment_date'] ?? date('Y-m-d'));

    $decoded = json_decode((string) ($record['responses_data'] ?? ''), true);
    $responses = is_array($decoded) ? $decoded : [];

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
        : ($assessment_date !== '' && strtotime($assessment_date) !== false ? date('d M Y', strtotime($assessment_date)) : '—');

    $age_val = $get_demo(['age']);
    $gender_val = $get_demo(['gender', 'sex']);
    $age_gender_combined = $get_demo(['age_gender', 'age_/_gender']);
    if ($age_gender_combined === '—') {
        $parts = array_filter([$age_val !== '—' ? $age_val : '', $gender_val !== '—' ? $gender_val : '']);
        $age_gender_combined = !empty($parts) ? implode(' / ', $parts) : '—';
    }

    $evaluating_prof = $get_demo(['evaluating_professional', 'clinician_name', 'evaluating_clinician']);

    // Exact 2-Column Header Order (Client Name first from dropdown, followed by exact sequence)
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
        'Evaluating Professional' => $evaluating_prof,
    ];
}

if ($total_score <= 21) {
    $severity = 'Low anxiety';
    $status_color = '#22c55e';
    $active_band_index = 0;
} elseif ($total_score <= 35) {
    $severity = 'Moderate anxiety';
    $status_color = '#eab308';
    $active_band_index = 1;
} else {
    $severity = 'Concerning levels of anxiety';
    $status_color = '#ef4444';
    $active_band_index = 2;
}

$answered_count = count($responses);
$clamped_total = max(0, min(63, $total_score));
$score_pct = (int) round(($clamped_total / 63) * 100);

require 'layout/base.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--br-primary:#0f766e;--br-primary-dark:#115e59;--br-primary-soft:#f0fdfa;--br-border:#e2e8f0;--br-border-soft:#f1f5f9;--br-text:#0f172a;--br-muted:#64748b;--br-shadow:0 4px 20px -2px rgba(15,23,42,.06)}
.bai-report{max-width:1180px;margin:auto;padding:24px 18px 48px;color:var(--br-text);font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
.bai-report-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.bai-btn-back,.bai-btn-print{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}
.bai-btn-back{background:#fff;color:#334155;border:1px solid #cbd5e1}
.bai-btn-print{background:var(--br-primary);color:#fff;border:1px solid var(--br-primary)}

.bai-hero{position:relative;overflow:hidden;padding:26px 28px;border-radius:16px;background:linear-gradient(135deg,#0f3f3c,#0f766e 55%,#0891b2);color:#fff;box-shadow:0 12px 30px -8px rgba(15,118,110,.35)}
.bai-hero::after{content:"";position:absolute;right:-50px;top:-70px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.14),rgba(255,255,255,0) 70%);pointer-events:none}
.bai-hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;position:relative;z-index:1}
.bai-eyebrow{display:inline-flex;padding:4px 11px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);color:#ccfbf1;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}
.bai-hero h1{margin:0;font-size:clamp(24px,3.5vw,31px);font-weight:800;letter-spacing:-0.02em;color:#fff}
.bai-hero-sub{margin:5px 0 0;color:#ccfbf1;font-size:13.5px}
.bai-date-pill{display:inline-flex;align-items:center;gap:7px;padding:7px 15px;border-radius:999px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.28);color:#fff;font-size:13px;font-weight:800;white-space:nowrap}

/* Integrated 2-Column Demographics Inside Hero */
.bai-hero-demo-wrap{margin-top:18px;padding-top:16px;border-top:1px solid rgba(255,255,255,.18);position:relative;z-index:1}
.bai-hero-demo-title{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:#99f6e4;margin-bottom:11px}
.bai-hero-demo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 14px}
.bai-hero-demo-item{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16);border-radius:9px;padding:8px 13px;display:flex;flex-direction:column;justify-content:center}
.bai-hero-demo-item span{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#ccfbf1;opacity:.9}
.bai-hero-demo-item strong{display:block;margin-top:2px;font-size:13.5px;font-weight:700;color:#fff;overflow-wrap:anywhere}

.bai-section-stack{display:flex;flex-direction:column;gap:20px;margin-top:20px}
.bai-card{background:#fff;border:1px solid var(--br-border);border-radius:14px;box-shadow:var(--br-shadow);overflow:hidden}
.bai-card-head{background:linear-gradient(to right,#f8fafc,#f1f5f9);border-bottom:1px solid var(--br-border);border-left:4px solid var(--br-primary);padding:13px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.bai-card-head h2{margin:0;font-size:13.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.bai-head-pill{padding:3px 11px;border-radius:999px;background:var(--br-primary-soft);color:var(--br-primary);border:1px solid #99f6e4;font-size:11.5px;font-weight:800;white-space:nowrap}
.bai-card-body{padding:20px 22px}
.bai-overview-inner{display:grid;grid-template-columns:460px minmax(0,1fr);gap:28px;align-items:center}
.bai-chart-wrap{position:relative;width:100%;height:285px;margin:0 auto}
.bai-stats-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.bai-stat-box{background:#f8fafc;border:1px solid var(--br-border-soft);border-radius:10px;padding:14px 16px}
.bai-stat-box span{display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--br-muted)}
.bai-stat-box strong{display:block;margin-top:4px;font-size:20px;font-weight:800}
.bai-scale-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.bai-scale-item{background:#f8fafc;border:1.5px solid transparent;border-radius:8px;text-align:center;padding:12px 8px}
.bai-scale-item.active{background:#fff;border-color:var(--bai-active-color,var(--br-primary));box-shadow:0 4px 10px rgba(0,0,0,.05)}
.bai-scale-bar{height:8px;border-radius:6px;margin-bottom:8px}
.bai-scale-range{display:block;font-size:11px;font-weight:800;margin-top:2px}
.bai-scale-title{display:block;font-size:11px;font-weight:700}

/* Enhanced Question-Wise Section with Status Bar */
.bai-qa-table{width:100%;border-collapse:collapse;font-size:13px}
.bai-qa-table thead th{background:#f8fafc;color:#475569;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:12px 16px;border-bottom:1px solid var(--br-border);text-align:left}
.bai-qa-table tbody td{padding:14px 16px;border-bottom:1px solid var(--br-border-soft);vertical-align:middle}
.bai-qa-table tbody tr:last-child td{border-bottom:0}
.bai-qa-table tbody tr:hover{background:#fcfefd}
.bai-item-num{display:inline-flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:8px;background:#f1f5f9;color:#334155;font-size:11.5px;font-weight:800}
.bai-q-main{font-size:13.5px;font-weight:700;color:var(--br-text);line-height:1.4}
.bai-q-ans{display:inline-block;margin-top:4px;font-size:12.5px;color:#475569;line-height:1.4}

.bai-status-cell{min-width:170px}
.bai-status-top{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px}
.bai-status-tag{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:800;letter-spacing:.02em;white-space:nowrap}
.bai-status-tag.is-none{background:#f1f5f9;color:#64748b}
.bai-status-tag.is-mild{background:#dcfce7;color:#166534}
.bai-status-tag.is-mod{background:#fef3c7;color:#92400e}
.bai-status-tag.is-sev{background:#fee2e2;color:#991b1b}

.bai-status-pts{font-size:11px;font-weight:700;color:#64748b;white-space:nowrap}
.bai-status-track{width:100%;height:7px;border-radius:999px;background:#edf2f7;overflow:hidden}
.bai-status-fill{display:block;height:100%;border-radius:999px;transition:width .25s ease}
.bai-status-fill.is-none{background:#cbd5e1}
.bai-status-fill.is-mild{background:#22c55e}
.bai-status-fill.is-mod{background:#eab308}
.bai-status-fill.is-sev{background:#ef4444}

.bai-item-score{display:inline-flex;align-items:center;justify-content:center;min-width:44px;padding:5px 11px;border-radius:999px;background:var(--br-primary-soft);color:var(--br-primary);border:1px solid #99f6e4;font-size:12px;font-weight:800;white-space:nowrap}
.bai-item-score.is-zero{background:#f8fafc;color:#64748b;border-color:#e2e8f0}

.bai-empty{padding:36px;text-align:center;background:#fef2f2;border:1px solid #fecaca;border-radius:14px;color:#991b1b;font-weight:600}
.report-sign-footer{display:flex;justify-content:space-between;gap:40px;margin-top:32px;padding:24px 8px 8px;border-top:1px solid #e2e8f0;break-inside:avoid;page-break-inside:avoid}
.report-sign-block{width:240px;text-align:center}
.report-sign-line{border-top:1px solid #334155;margin-top:52px;padding-top:8px}
.report-sign-title{font-size:13px;font-weight:700}
.report-sign-sub{font-size:11.5px;color:#64748b}

@media(max-width:960px){.bai-overview-inner{grid-template-columns:1fr}}
@media(max-width:700px){.bai-scale-grid{grid-template-columns:1fr}.bai-hero-demo-grid{grid-template-columns:1fr}}
@media(max-width:600px){.bai-report{padding:16px 12px 32px}.bai-hero{padding:20px 18px}.bai-hero h1{font-size:22px}.bai-stats-grid{grid-template-columns:1fr}.report-sign-footer{flex-direction:column}.report-sign-block{width:100%}}

/* PRINT RESPONSIVE LAYOUT (Removes browser URL/date & fits cleanly on A4) */
@page{size:A4 portrait;margin:0}
@media print{
    html,body{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#fff!important;margin:0!important;padding:0!important}
    .bai-report-top,.admin-sidebar,.admin-navbar,.sidebar-backdrop{display:none!important}
    .bai-report{max-width:100%!important;padding:10mm 10mm!important;margin:0!important}

    /* Page 1 Full-Height Fit (Graph & Severity Design Unchanged) */
    .bai-hero{padding:19px 22px!important;border-radius:12px!important;box-shadow:none!important;break-inside:avoid;page-break-inside:avoid}
    .bai-eyebrow{margin-bottom:6px!important;font-size:9px!important;padding:2px 9px!important}
    .bai-hero h1{font-size:21px!important}
    .bai-hero-sub{font-size:11.5px!important}
    .bai-date-pill{padding:6px 14px!important;font-size:12px!important}
    .bai-hero-demo-wrap{margin-top:14px!important;padding-top:12px!important}
    .bai-hero-demo-title{margin-bottom:8px!important;font-size:9.5px!important}
    .bai-hero-demo-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:7px 14px!important}
    .bai-hero-demo-item{padding:6px 12px!important}
    .bai-hero-demo-item span{font-size:8.5px!important}
    .bai-hero-demo-item strong{font-size:12px!important}

    .bai-section-stack{gap:18px!important;margin-top:18px!important}
    .bai-card{box-shadow:none!important;overflow:visible!important;margin-top:0!important;break-inside:auto!important;page-break-inside:auto!important}
    .bai-card-head{padding:11px 16px!important;break-after:avoid;page-break-after:avoid}
    .bai-card-head h2{font-size:12px!important}
    .bai-card-body{padding:20px 18px!important}

    .bai-overview-inner{grid-template-columns:410px minmax(0,1fr)!important;gap:22px!important}
    .bai-chart-wrap{height:330px!important;display:flex!important;justify-content:center!important;align-items:center!important}
    .bai-chart-wrap canvas{margin:0 auto!important;display:block!important}
    .bai-stats-grid{gap:14px!important}
    .bai-stat-box{padding:16px 16px!important}
    .bai-stat-box span{font-size:9.5px!important}
    .bai-stat-box strong{font-size:19px!important;margin-top:6px!important}

    .bai-scale-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:10px!important}
    .bai-scale-item{padding:12px 9px!important;break-inside:avoid;page-break-inside:avoid}
    .bai-scale-bar{height:8px!important;margin-bottom:8px!important}
    .bai-scale-range{font-size:10.5px!important}
    .bai-scale-title{font-size:10.5px!important;margin-top:3px!important}

    /* Page 2 Fit for 21 Questions + Signature */
    .bai-qa-card{break-before:page!important;page-break-before:always!important;margin-top:0!important}
    .bai-qa-table thead th{padding:6px 10px!important;font-size:9.5px!important}
    .bai-qa-table tbody td{padding:5px 10px!important;font-size:10px!important}
    .bai-qa-table tbody tr{break-inside:avoid;page-break-inside:avoid}
    .bai-item-num{width:19px!important;height:19px!important;font-size:9px!important}
    .bdi-q-main,.bai-q-main{font-size:10px!important;line-height:1.25!important}
    .bai-q-ans{font-size:9.5px!important;line-height:1.25!important;margin-top:1px!important}
    .bai-status-cell{min-width:120px!important}
    .bai-status-top{margin-bottom:3px!important}
    .bai-status-tag{font-size:8.5px!important;padding:1px 6px!important}
    .bai-status-pts{font-size:9px!important}
    .bai-status-track{height:4px!important}
    .bai-item-score{padding:2px 7px!important;font-size:9px!important;min-width:38px!important}

    .report-sign-footer{margin-top:16px!important;padding-top:10px!important}
    .report-sign-line{margin-top:30px!important}
}
</style>

<div class="bai-report">
    <div class="bai-report-top">
        <a class="bai-btn-back" href="bai-assessment-records.php"><i class="bi bi-arrow-left"></i> Back to Records</a>
        <?php if ($record): ?><button type="button" class="bai-btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Print Report</button><?php endif; ?>
    </div>

    <?php if (!$record): ?>
        <div class="bai-empty"><?php echo bai_score_escape($load_error); ?></div>
    <?php else: ?>
        <!-- HERO HEADER WITH DATE PILL & 2-COLUMN CLIENT DETAILS -->
        <header class="bai-hero">
            <div class="bai-hero-top">
                <div>
                    <div class="bai-eyebrow">Clinical Assessment Report</div>
                    <h1>BAI Score Overview</h1>
                    <p class="bai-hero-sub">Beck Anxiety Inventory &middot; 21-Item Self-Rated Assessment</p>
                </div>
                <span class="bai-date-pill">
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    Date: <?php echo bai_score_escape($formatted_date); ?>
                </span>
            </div>

            <?php if (!empty($demographics)): ?>
                <div class="bai-hero-demo-wrap">
                    <div class="bai-hero-demo-title">Patient &amp; Clinical Details</div>
                    <div class="bai-hero-demo-grid">
                        <?php foreach ($demographics as $label => $value): ?>
                            <div class="bai-hero-demo-item">
                                <span><?php echo bai_score_escape($label); ?></span>
                                <strong><?php echo bai_score_escape($value); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </header>

        <div class="bai-section-stack">
            <section class="bai-card" style="--bai-active-color: <?php echo $status_color; ?>;">
                <div class="bai-card-head">
                    <h2>Overall Score</h2>
                    <span class="bai-head-pill">Total Score: <?php echo $total_score; ?> / 63</span>
                </div>
                <div class="bai-card-body">
                    <div class="bai-overview-inner">
                        <div class="bai-chart-wrap">
                            <canvas id="baiScoreChart" role="img" aria-label="BAI anxiety severity score distribution chart"></canvas>
                        </div>
                        <div class="bai-stats-grid">
                            <div class="bai-stat-box"><span>Total BAI Score</span><strong><?php echo $total_score; ?> / 63</strong></div>
                            <div class="bai-stat-box"><span>Score Percentage</span><strong><?php echo $score_pct; ?>%</strong></div>
                            <div class="bai-stat-box"><span>Severity</span><strong style="color:<?php echo $status_color; ?>"><?php echo bai_score_escape($severity); ?></strong></div>
                            <div class="bai-stat-box"><span>Questions Answered</span><strong><?php echo $answered_count; ?> / 21</strong></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bai-card" style="--bai-active-color: <?php echo $status_color; ?>;">
                <div class="bai-card-head"><h2>BAI Severity Scale</h2></div>
                <div class="bai-card-body">
                    <div class="bai-scale-grid">
                        <div class="bai-scale-item <?php echo $active_band_index === 0 ? 'active' : ''; ?>"><div class="bai-scale-bar" style="background:#22c55e"></div><span class="bai-scale-title">Low anxiety</span><span class="bai-scale-range">0 - 21</span></div>
                        <div class="bai-scale-item <?php echo $active_band_index === 1 ? 'active' : ''; ?>"><div class="bai-scale-bar" style="background:#eab308"></div><span class="bai-scale-title">Moderate anxiety</span><span class="bai-scale-range">22 - 35</span></div>
                        <div class="bai-scale-item <?php echo $active_band_index === 2 ? 'active' : ''; ?>"><div class="bai-scale-bar" style="background:#ef4444"></div><span class="bai-scale-title">Concerning levels of anxiety</span><span class="bai-scale-range">36 - 63</span></div>
                    </div>
                </div>
            </section>

            <section class="bai-card bai-qa-card">
                <div class="bai-card-head">
                    <h2>Question-Wise Answers &amp; Scores (<?php echo $answered_count; ?>)</h2>
                    <span class="bai-head-pill">Scale: 0 – 3</span>
                </div>
                <div class="table-responsive">
                    <table class="bai-qa-table">
                        <thead>
                            <tr>
                                <th style="width: 56px;">#</th>
                                <th style="width: 46%;">Question &amp; Response</th>
                                <th style="width: 28%;">Severity Status Bar</th>
                                <th style="width: 110px; text-align: right;">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($responses as $response):
                                $item_score = max(0, min(3, (int) ($response['selected_score'] ?? 0)));
                                $meta = bai_item_status_meta($item_score);
                            ?>
                                <tr>
                                    <td><span class="bai-item-num"><?php echo str_pad((string) ($i++), 2, '0', STR_PAD_LEFT); ?></span></td>
                                    <td>
                                        <div class="bai-q-main"><?php echo bai_score_escape($response['question'] ?? 'Question'); ?></div>
                                        <span class="bai-q-ans"><?php echo bai_score_escape($response['selected_text'] ?? '—'); ?></span>
                                    </td>
                                    <td class="bai-status-cell">
                                        <div class="bai-status-top">
                                            <span class="bai-status-tag <?php echo $meta['cls']; ?>"><?php echo bai_score_escape($meta['label']); ?></span>
                                            <span class="bai-status-pts"><?php echo $meta['pct']; ?>%</span>
                                        </div>
                                        <div class="bai-status-track" aria-hidden="true">
                                            <span class="bai-status-fill <?php echo $meta['cls']; ?>" style="width: <?php echo $meta['pct']; ?>%;"></span>
                                        </div>
                                    </td>
                                    <td style="text-align: right;">
                                        <span class="bai-item-score<?php echo $item_score === 0 ? ' is-zero' : ''; ?>">+<?php echo $item_score; ?> / 3</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="report-sign-footer">
            <div class="report-sign-block"><div class="report-sign-line"><div class="report-sign-title">Patient / Guardian</div><div class="report-sign-sub">Signature</div></div></div>
            <div class="report-sign-block"><div class="report-sign-line"><div class="report-sign-title">Clinician</div><div class="report-sign-sub">Signature &amp; Stamp</div></div></div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('baiScoreChart');
    if (!canvas) return;

    var totalScore = <?php echo $clamped_total; ?>;
    var scorePct = <?php echo $score_pct; ?>;
    var activeIndex = <?php echo $active_band_index; ?>;

    var baiBands = [
        { lines: ['Low anxiety'],                 range: '0–21',  size: 21, color: '#22c55e' },
        { lines: ['Moderate anxiety'],            range: '22–35', size: 14, color: '#eab308' },
        { lines: ['Concerning levels', 'of anxiety'], range: '36–63', size: 28, color: '#ef4444' }
    ];

    var baiCalloutAndCenter = {
        id: 'baiCalloutAndCenter',
        afterDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            var meta = chart.getDatasetMeta(0);
            if (!meta.data || !meta.data.length) return;

            ctx.save();

            var centerX = meta.data[0].x;
            var centerY = meta.data[0].y;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            ctx.fillStyle = '#0f172a';
            ctx.font = '900 28px system-ui, -apple-system, sans-serif';
            ctx.fillText(String(totalScore), centerX, centerY - 12);

            ctx.fillStyle = '#64748b';
            ctx.font = '700 11.5px system-ui, -apple-system, sans-serif';
            ctx.fillText('out of 63', centerX, centerY + 9);

            ctx.fillStyle = baiBands[activeIndex].color;
            ctx.font = '800 11px system-ui, -apple-system, sans-serif';
            ctx.fillText(scorePct + '%', centerX, centerY + 25);

            var rightItems = [];
            var leftItems = [];

            meta.data.forEach(function (arc, index) {
                var band = baiBands[index];
                var angle = (arc.startAngle + arc.endAngle) / 2;
                var cos = Math.cos(angle);
                var sin = Math.sin(angle);
                var isRight = cos >= 0;

                var item = {
                    index: index,
                    lines: band.lines,
                    range: band.range,
                    color: band.color,
                    x1: arc.x + cos * (arc.outerRadius + 2),
                    y1: arc.y + sin * (arc.outerRadius + 2),
                    x2: arc.x + cos * (arc.outerRadius + 10),
                    y2: arc.y + sin * (arc.outerRadius + 10),
                    isRight: isRight
                };

                if (isRight) {
                    rightItems.push(item);
                } else {
                    leftItems.push(item);
                }
            });

            function drawSide(items) {
                items.sort(function (a, b) { return a.y2 - b.y2; });
                var minGap = 36;
                for (var i = 1; i < items.length; i++) {
                    if (items[i].y2 - items[i - 1].y2 < minGap) {
                        items[i].y2 = items[i - 1].y2 + minGap;
                    }
                }

                ctx.textBaseline = 'middle';
                items.forEach(function (item) {
                    var isActive = item.index === activeIndex;
                    var x3 = item.x2 + (item.isRight ? 8 : -8);

                    ctx.beginPath();
                    ctx.moveTo(item.x1, item.y1);
                    ctx.lineTo(item.x2, item.y2);
                    ctx.lineTo(x3, item.y2);
                    ctx.strokeStyle = isActive ? item.color : '#64748b';
                    ctx.lineWidth = isActive ? 1.8 : 1.1;
                    ctx.stroke();

                    var textX = x3 + (item.isRight ? 4 : -4);
                    ctx.textAlign = item.isRight ? 'left' : 'right';
                    ctx.fillStyle = isActive ? '#0f172a' : '#334155';
                    ctx.font = (isActive ? '800' : '700') + ' 10.5px system-ui, -apple-system, sans-serif';

                    var lineHeight = 12;
                    var totalTextLines = item.lines.length + 1;
                    var startY = item.y2 - ((totalTextLines - 1) * lineHeight) / 2;

                    item.lines.forEach(function (line, idx) {
                        ctx.fillText(line, textX, startY + idx * lineHeight);
                    });

                    ctx.fillStyle = '#64748b';
                    ctx.font = '600 10px system-ui, -apple-system, sans-serif';
                    ctx.fillText('(' + item.range + ')', textX, startY + item.lines.length * lineHeight);
                });
            }

            drawSide(rightItems);
            drawSide(leftItems);

            ctx.restore();
        }
    };

    var baiChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: baiBands.map(function (b) { return b.lines.join(' '); }),
            datasets: [{
                data: baiBands.map(function (b) { return b.size; }),
                backgroundColor: baiBands.map(function (b) { return b.color; }),
                borderColor: '#ffffff',
                borderWidth: 2,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            layout: {
                padding: { top: 24, bottom: 24, left: 122, right: 122 }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var b = baiBands[ctx.dataIndex];
                            return ' ' + b.lines.join(' ') + ' (' + b.range + ')';
                        }
                    }
                }
            }
        },
        plugins: [baiCalloutAndCenter]
    });

    window.addEventListener('beforeprint', function () {
        baiChart.resize(410, 330);
    });
    window.addEventListener('afterprint', function () {
        baiChart.resize();
    });
});
</script>
<?php require 'layout/footer.php'; ?>