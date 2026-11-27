<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require 'config.php';
require 'ymrs-assessment-items.php';
require 'ymrs-assessment-schema.php';
require 'assessment-demographics.php';

if (!isset($_SESSION['user_login']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'YMRS Score Report - Vimhans Academic';
$record = null;
$load_error = '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($id === false || $id < 1) {
    http_response_code(400);
    $load_error = 'The assessment link is invalid.';
} elseif (!$ymrs_schema_ready) {
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
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$record) {
        http_response_code(404);
        $load_error = 'This assessment record was not found.';
    }
}

$scores = [];
$demographics = [];
$total = 0;
$patient_name = '';
$clinician_name = '';
$notes = '';
$severity_label = 'Normal / Remission';
$severity_class = 'normal';
$severity_color = '#0f766e';

if ($record) {
    $decoded = json_decode((string)$record['score_data'], true);
    $decoded = is_array($decoded) ? $decoded : [];
    $total = (int)$record['total_score'];
    $patient_name = trim($record['patient_name']) ?: 'Unknown patient';
    $clinician_name = trim($record['clinician_name']) ?: 'Unknown clinician';
    $notes = (string)($record['clinical_notes'] ?? '');

    $demo_decoded = json_decode((string)($record['demographic_data'] ?? ''), true);
    $normalized_demo = assessment_demographics_normalize(is_array($demo_decoded) ? $demo_decoded : []);
    foreach ($normalized_demo as $dk => $dv) {
        if (in_array($dk, ['date', 'client_name'], true)) {
            continue;
        }
        $val_str = is_array($dv) ? implode(', ', array_filter($dv)) : trim((string)$dv);
        $demographics[ucwords(str_replace('_', ' ', (string)$dk))] = $val_str !== '' ? $val_str : '—';
    }

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
        $value = isset($decoded[$field]) && is_numeric($decoded[$field]) ? (int)$decoded[$field] : 0;
        $scores[] = [
            'title' => $item['title'],
            'value' => $value,
            'label' => $item['ratings'][$value] ?? 'Not rated',
            'max' => max(array_keys($item['ratings'])),
        ];
    }
}

$clamped_total = max(0, min(60, $total));
$score_pct = (int)round(($clamped_total / 60) * 100);

function ymrs_score_escape($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

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
        padding: 24px 18px 48px;
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

    /* Hero Banner */
    .ymrs-hero {
        position: relative;
        overflow: hidden;
        padding: 28px 30px;
        border-radius: 16px;
        background: linear-gradient(135deg, #0f3f3c 0%, #0f766e 58%, #14b8a6 100%);
        color: #fff;
        box-shadow: 0 12px 30px -8px rgba(15, 118, 110, 0.35);
    }

    .ymrs-hero::after {
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
        background: rgba(255, 255, 255, 0.12);
        padding: 4px 11px;
        border-radius: 999px;
        margin-bottom: 10px;
    }

    .ymrs-hero h1 {
        margin: 0;
        font-size: 28px;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #fff;
    }

    .ymrs-hero-sub {
        margin: 6px 0 0;
        font-size: 13.5px;
        color: #ccfbf1;
    }

    .ymrs-hero-badge {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 6px;
    }

    .ymrs-severity-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 999px;
        font-size: 12.5px;
        font-weight: 800;
        white-space: nowrap;
    }

    .ymrs-severity-pill.normal { background: #dcfce7; color: #166534; }
    .ymrs-severity-pill.mild { background: #fef3c7; color: #92400e; }
    .ymrs-severity-pill.moderate { background: #ffedd5; color: #9a3412; }
    .ymrs-severity-pill.severe { background: #fee2e2; color: #991b1b; }

    .ymrs-meta-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-top: 22px;
        position: relative;
        z-index: 1;
    }

    .ymrs-meta-box {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 10px;
        padding: 11px 14px;
        backdrop-filter: blur(4px);
    }

    .ymrs-meta-box span {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #ccfbf1;
    }

    .ymrs-meta-box strong {
        display: block;
        margin-top: 4px;
        font-size: 14.5px;
        font-weight: 700;
        color: #fff;
    }

    /* Stacked Full-Width Sections */
    .ymrs-section-stack {
        display: flex;
        flex-direction: column;
        gap: 20px;
        margin-top: 20px;
    }

    .ymrs-card {
        background: #fff;
        border: 1px solid var(--yr-border);
        border-radius: 14px;
        box-shadow: var(--yr-shadow);
        overflow: hidden;
    }

    .ymrs-card-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--yr-border);
        border-left: 4px solid var(--yr-primary);
        padding: 13px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ymrs-card-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--yr-text);
    }

    .ymrs-card-body {
        padding: 20px 22px;
    }

    /* Demographics Grid */
    .ymrs-demo-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .ymrs-demo-item {
        background: #f8fafc;
        border: 1px solid var(--yr-border-soft);
        border-radius: 10px;
        padding: 10px 14px;
    }

    .ymrs-demo-item span {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--yr-muted);
    }

    .ymrs-demo-item strong {
        display: block;
        margin-top: 3px;
        font-size: 13.5px;
        color: var(--yr-text);
        overflow-wrap: anywhere;
    }

    /* Full-Width Overall Score Row */
    .ymrs-overview-inner {
        display: grid;
        grid-template-columns: 440px minmax(0, 1fr);
        gap: 28px;
        align-items: center;
    }

    .ymrs-chart-wrap {
        position: relative;
        width: 100%;
        height: 260px;
        margin: 0 auto;
    }

    /* Severity Scale Legend */
    .ymrs-scale-guide {
        padding: 4px 0;
    }

    .ymrs-scale-title {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--yr-muted);
        margin-bottom: 10px;
    }

    .ymrs-scale-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 9px 14px;
        border-radius: 8px;
        font-size: 13px;
        color: #475569;
        margin-bottom: 6px;
        background: #f8fafc;
        border: 1px solid transparent;
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
        padding: 12px 14px;
        border-bottom: 1px solid var(--yr-border);
        text-align: left;
    }

    .ymrs-qa-table tbody td {
        padding: 13px 14px;
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
        font-size: 14px;
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

    @media (max-width: 960px) {
        .ymrs-meta-grid,
        .ymrs-overview-inner {
            grid-template-columns: 1fr;
        }
        .ymrs-demo-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 600px) {
        .ymrs-report {
            padding: 16px 12px 32px;
        }
        .ymrs-hero {
            padding: 22px 18px;
        }
        .ymrs-hero h1 {
            font-size: 22px;
        }
        .ymrs-hero-badge {
            align-items: flex-start;
        }
        .ymrs-demo-grid {
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
        .ymrs-report {
            max-width: 100%;
            padding: 0;
        }
        .ymrs-report-top,
        .admin-sidebar,
        .admin-navbar,
        .sidebar-backdrop {
            display: none !important;
        }
        .ymrs-hero,
        .ymrs-overview-card,
        .ymrs-qa-table tbody tr {
            box-shadow: none !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .ymrs-card {
            box-shadow: none !important;
            overflow: visible !important;
            break-inside: auto !important;
            page-break-inside: auto !important;
        }
        .ymrs-card-head {
            break-after: avoid;
            page-break-after: avoid;
        }
    }
</style>

<div class="ymrs-report">
    <div class="ymrs-report-top">
        <a class="ymrs-btn-back" href="ymrs-assessment-records.php">&larr; Back to Records</a>
        <?php if ($record): ?>
            <button type="button" class="ymrs-btn-print" onclick="window.print()">Print Report</button>
        <?php endif; ?>
    </div>

    <?php if (!$record): ?>
        <div class="ymrs-empty" role="alert"><?php echo ymrs_score_escape($load_error); ?></div>
    <?php else: ?>
        <!-- Hero Header -->
        <header class="ymrs-hero">
            <div class="ymrs-hero-top">
                <div>
                    <div class="ymrs-hero-eyebrow">Clinical Assessment Report</div>
                    <h1>YMRS Score Overview</h1>
                    <p class="ymrs-hero-sub">Young Mania Rating Scale &middot; 11-Item Clinician-Rated Assessment</p>
                </div>
                <div class="ymrs-hero-badge">
                    <span class="ymrs-severity-pill <?php echo ymrs_score_escape($severity_class); ?>">
                        <?php echo ymrs_score_escape($severity_label); ?>
                    </span>
                </div>
            </div>

            <div class="ymrs-meta-grid">
                <div class="ymrs-meta-box">
                    <span>Patient Name</span>
                    <strong><?php echo ymrs_score_escape($patient_name); ?></strong>
                </div>
                <div class="ymrs-meta-box">
                    <span>Assessment Date</span>
                    <strong><?php echo ymrs_score_escape(date('d M Y', strtotime($record['assessment_date']))); ?></strong>
                </div>
                <div class="ymrs-meta-box">
                    <span>Evaluating Clinician</span>
                    <strong><?php echo ymrs_score_escape($clinician_name); ?></strong>
                </div>
            </div>
        </header>

        <div class="ymrs-section-stack">
            <!-- Patient Demographics -->
            <?php if (!empty($demographics)): ?>
                <section class="ymrs-card">
                    <div class="ymrs-card-head">
                        <h2>Patient Demographic Details</h2>
                    </div>
                    <div class="ymrs-card-body">
                        <div class="ymrs-demo-grid">
                            <?php foreach ($demographics as $demo_label => $demo_val): ?>
                                <div class="ymrs-demo-item">
                                    <span><?php echo ymrs_score_escape($demo_label); ?></span>
                                    <strong><?php echo ymrs_score_escape($demo_val); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Full-Width Overall Score & Scale Guide -->
            <section class="ymrs-card ymrs-overview-card">
                <div class="ymrs-card-head">
                    <h2>Overall Score</h2>
                </div>
                <div class="ymrs-card-body">
                    <div class="ymrs-overview-inner">
                        <div class="ymrs-chart-wrap">
                            <canvas id="ymrsChart"></canvas>
                        </div>

                        <div class="ymrs-scale-guide">
                            <div class="ymrs-scale-title">Severity Reference Guide</div>
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
                    </div>
                </div>
            </section>

            <!-- Full-Width Question-Wise Answers & Scores Table -->
            <section class="ymrs-card">
                <div class="ymrs-card-head">
                    <h2>Question-Wise Answers &amp; Scores (<?php echo count($scores); ?>)</h2>
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
                                        <span class="ymrs-item-num"><?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
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
                                            <?php echo (int)$item['value']; ?> / <?php echo (int)$item['max']; ?>
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
                <section class="ymrs-card">
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
                ctx.font = '900 32px system-ui, -apple-system, sans-serif';
                ctx.fillText(String(totalScore), centerX, centerY - 14);

                ctx.fillStyle = '#64748b';
                ctx.font = '700 12px system-ui, -apple-system, sans-serif';
                ctx.fillText('out of 60', centerX, centerY + 10);

                ctx.fillStyle = ymrsBands[activeIndex].color;
                ctx.font = '800 11px system-ui, -apple-system, sans-serif';
                ctx.fillText(scorePct + '% of max', centerX, centerY + 27);

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

        new Chart(canvas, {
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
                    padding: { top: 24, bottom: 24, left: 118, right: 118 }
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
    });
</script>
<?php require 'layout/footer.php'; ?>