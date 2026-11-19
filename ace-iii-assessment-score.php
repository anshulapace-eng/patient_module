<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'ace-iii-assessment-schema.php';

if (!isset($_SESSION['user_login']) || !in_array((int) ($_SESSION['role'] ?? 0), [1, 2], true)) {
    header('Location: login.php');
    exit;
}

function ace_iii_report_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
                a.visuospatial_score, a.response_data, a.clinical_notes, a.status,
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
            }
        }
        $stmt->close();
    }
}

$patient_name = '';
$clinician_name = '';
$notes = '';
$section_scores = [
    'attention' => ['label' => 'Attention', 'score' => 0, 'max' => 18],
    'memory' => ['label' => 'Memory', 'score' => 0, 'max' => 26],
    'fluency' => ['label' => 'Fluency', 'score' => 0, 'max' => 14],
    'language' => ['label' => 'Language', 'score' => 0, 'max' => 26],
    'visuospatial' => ['label' => 'Visuospatial', 'score' => 0, 'max' => 16],
];
$score_data = [];
$chart_labels = [];
$chart_values = [];
$chart_colours = ['#0ea5e9', '#8b5cf6', '#14b8a6', '#f59e0b', '#ef4444'];

if ($record) {
    $patient_name = trim($record['patient_name']) ?: 'Unknown patient';
    $clinician_name = trim($record['clinician_name']) ?: 'Waiting for scorer';
    $notes = (string) ($record['clinical_notes'] ?? '');

    foreach ($section_scores as $key => &$section) {
        $db_key = $key . '_score';
        $section['score'] = (int) ($record[$db_key] ?? 0);
    }
    unset($section);

    $decoded = json_decode((string) ($record['response_data'] ?? ''), true);
    $score_data = is_array($decoded['scores'] ?? null) ? $decoded['scores'] : [];
    foreach ($section_scores as $key => $section) {
        $chart_labels[] = $section['label'];
        $chart_values[] = $section['score'];
    }
}

$total_score = $record ? (int) $record['total_score'] : 0;
$status_text = $total_score >= 88
    ? '88-100 - Normal Cognitive Function'
    : ($total_score >= 83
        ? '83-87 - Borderline/ Mild Cognitive Impairment'
        : '82-Below - Abnormal Cognitive Function');
$status_color = $total_score >= 88 ? '#10b981' : ($total_score >= 83 ? '#f59e0b' : '#ef4444');

require 'layout/base.php';
?>
<style>
    .score-report { max-width: 1180px; margin: 0 auto; padding: 28px 20px 48px; color: #172033; }
    .report-topbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 22px; }
    .report-back, .report-print { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; color: #334155; font-size: 13px; font-weight: 700; text-decoration: none; }
    .report-back:hover, .report-print:hover { background: #f8fafc; color: #4338ca; }
    .report-hero { position: relative; overflow: hidden; padding: 30px; border-radius: 20px; background: linear-gradient(120deg, #0f766e, #0ea5e9 55%, #2563eb); color: #fff; box-shadow: 0 16px 35px rgba(37,99,235,.18); }
    .report-hero:after { position: absolute; top: -110px; right: -80px; width: 300px; height: 300px; border: 1px solid rgba(255,255,255,.16); border-radius: 50%; box-shadow: 0 0 0 35px rgba(255,255,255,.05), 0 0 0 75px rgba(255,255,255,.04); content: ""; }
    .report-eyebrow { margin-bottom: 10px; color: #d8f7ff; font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
    .report-hero h1 { margin: 0; font-size: clamp(26px, 4vw, 36px); font-weight: 800; letter-spacing: -.03em; }
    .report-subtitle { margin: 8px 0 0; color: #d8f7ff; font-size: 14px; }
    .report-meta { display: flex; flex-wrap: wrap; gap: 18px 28px; margin-top: 24px; }
    .report-meta span { color: #d8f7ff; font-size: 13px; }
    .report-meta strong { display: block; margin-top: 4px; color: #fff; font-size: 14px; }
    .report-grid { display: grid; grid-template-columns: minmax(260px, .8fr) minmax(0, 1.4fr); gap: 18px; margin-top: 18px; }
    .report-card { min-width: 0; padding: 22px; border: 1px solid #e7ebf2; border-radius: 16px; background: #fff; box-shadow: 0 5px 18px rgba(15,23,42,.035); }
    .report-card h2 { margin: 0; color: #172033; font-size: 17px; font-weight: 800; }
    .card-caption { margin: 6px 0 18px; color: #758197; font-size: 12px; }
    .score-summary { display: grid; justify-items: center; padding: 10px 0 14px; text-align: center; }
    .score-chart-wrap { position: relative; width: 100%; max-width: 360px; height: 260px; margin: 0 auto; }
    .score-summary-label { margin-top: 16px; color: #64748b; font-size: 13px; }
    .summary-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; width: 100%; margin-top: 18px; text-align: left; }
    .summary-stat { padding: 12px; border-radius: 10px; background: #f7f8fc; }
    .summary-stat span { display: block; color: #8490a3; font-size: 11px; }
    .summary-stat strong { display: block; margin-top: 3px; color: #28334a; font-size: 14px; }
    .section-list { display: grid; gap: 14px; }
    .section-row { display: grid; grid-template-columns: minmax(120px, 1fr) minmax(80px, 1.2fr) minmax(100px, auto); align-items: center; gap: 12px; }
    .section-name { color: #475569; font-size: 12px; }
    .section-track { overflow: hidden; height: 8px; border-radius: 99px; background: #edf0f6; }
    .section-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0ea5e9, #14b8a6); }
    .section-value { display: grid; justify-items: end; gap: 2px; color: #0f766e; text-align: right; }
    .section-value strong { font-size: 12px; font-weight: 800; }
    .section-judge { color: #64748b; font-size: 10px; font-weight: 600; line-height: 1.25; }
    .report-empty { margin: 28px auto; max-width: 640px; padding: 30px; border: 1px solid #fee2e2; border-radius: 16px; background: #fff; color: #991b1b; text-align: center; }
    .report-empty h1 { margin: 0 0 8px; font-size: 21px; }
    .assessment-status { display: inline-block; padding: 8px 12px; border-radius: 999px; font-weight: 800; font-size: 12px; margin-top: 12px; }
    .notes-card { margin-top: 18px; }
    .clinical-notes { margin: 10px 0 0; color: #526078; font-size: 13px; line-height: 1.7; white-space: pre-wrap; overflow-wrap: anywhere; }
    @media (max-width: 760px) { .score-report { padding: 20px 12px 32px; } .report-hero { padding: 24px 20px; } .report-grid { grid-template-columns: 1fr; } .report-card { padding: 18px; } }
    @media (max-width: 460px) { .score-chart-wrap { height: 230px; } }
    @media print { .report-topbar, .admin-sidebar, .admin-navbar, .sidebar-backdrop { display: none !important; } .score-report { max-width: none; padding: 0; } .report-hero, .report-card { box-shadow: none; break-inside: avoid; } body { background: #fff !important; } }
</style>

<div class="score-report">
    <div class="report-topbar">
        <a class="report-back" href="ace-iii-assessment-records.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to records</a>
        <?php if ($record): ?><button class="report-print" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print report</button><?php endif; ?>
    </div>
    <?php if (!$record): ?>
        <section class="report-empty" role="alert">
            <h1>Report unavailable</h1>
            <p class="mb-0"><?php echo ace_iii_report_escape($load_error); ?></p>
        </section>
    <?php else: ?>
        <header class="report-hero">
            <div class="report-eyebrow">Clinical assessment report</div>
            <h1>ACE-III Score Overview</h1>
            <p class="report-subtitle">Addenbrooke's Cognitive Examination · 100-point assessment</p>
            <div class="report-meta">
                <span>Patient<strong><?php echo ace_iii_report_escape($patient_name); ?></strong></span>
                <span>Assessment date<strong><?php echo ace_iii_report_escape(date('d M Y', strtotime($record['assessment_date']))); ?></strong></span>
                <span>Clinician<strong><?php echo ace_iii_report_escape($clinician_name); ?></strong></span>
            </div>
        </header>

        <div class="report-grid">
            <section class="report-card" aria-labelledby="aceSummaryTitle">
                <h2 id="aceSummaryTitle">Overall cognitive score</h2>
                <p class="card-caption">Total points across attention, memory, fluency, language, and visuospatial domains.</p>
                <div class="score-summary">
                    <div class="score-chart-wrap">
                        <canvas id="ace3ScoreChart" role="img" aria-label="ACE-III total score chart"></canvas>
                    </div>
                    <div class="score-summary-label">Total ACE-III score</div>
                    <div class="summary-stats">
                        <div class="summary-stat"><span>Final score</span><strong><?php echo (int) $total_score; ?> / 100</strong></div>
                        <div class="summary-stat"><span>Assessment status</span><strong><?php echo ace_iii_report_escape($status_text); ?></strong></div>
                    </div>
                    <div class="assessment-status" style="background: <?php echo $status_color; ?>1A; color: <?php echo $status_color; ?>; border: 1px solid <?php echo $status_color; ?>55;">
                        <?php echo ace_iii_report_escape($status_text); ?>
                    </div>
                </div>
            </section>

            <section class="report-card" aria-labelledby="aceDomainTitle">
                <h2 id="aceDomainTitle">Domain score breakdown</h2>
                <p class="card-caption">Higher values indicate better performance in that cognitive domain.</p>
                <div class="section-list">
                    <?php foreach ($section_scores as $section): ?>
                        <?php $section_percent = $section['max'] > 0 ? ($section['score'] / $section['max']) * 100 : 0; ?>
                        <div class="section-row">
                            <span class="section-name"><?php echo ace_iii_report_escape($section['label']); ?></span>
                            <span class="section-track" aria-hidden="true"><span class="section-fill" style="display:block;width:<?php echo max(0, min(100, $section_percent)); ?>%"></span></span>
                            <span class="section-value">
                                <strong><?php echo (int) $section['score']; ?>/<?php echo (int) $section['max']; ?></strong>
                                <small class="section-judge"><?php echo $section['score'] >= ($section['max'] * 0.7) ? 'Strong' : ($section['score'] >= ($section['max'] * 0.4) ? 'Moderate' : 'Needs attention'); ?></small>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <?php if (trim($notes) !== ''): ?>
            <section class="report-card notes-card" aria-labelledby="aceNotesTitle">
                <h2 id="aceNotesTitle">Clinical notes</h2>
                <p class="clinical-notes"><?php echo ace_iii_report_escape($notes); ?></p>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const canvas = document.getElementById('ace3ScoreChart');
        if (!canvas) {
            return;
        }

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($chart_values, JSON_UNESCAPED_UNICODE); ?>,
                    backgroundColor: <?php echo json_encode($chart_colours, JSON_UNESCAPED_UNICODE); ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '55%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16, color: '#475569', font: { size: 12 } } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.label + ': ' + context.raw + ' points';
                            }
                        }
                    }
                }
            }
        });
    });
</script>
