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
$chart_colors = ['#4f46e5', '#0ea5e9', '#14b8a6'];

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
        $domain_scores[] = [
            'prefix' => $prefix,
            'title' => $section['title'],
            'score' => max(0, $score),
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
                'rating_label' => $rating > 0 ? $panss_rating_labels[$rating] : 'Not rated',
            ];
        }
    }

}

require 'layout/base.php';
?>

<style>
    .score-report { max-width: 1180px; margin: 0 auto; padding: 28px 20px 48px; color: #172033; }
    .report-topbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 22px; }
    .report-back, .report-print { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; color: #334155; font-size: 13px; font-weight: 700; text-decoration: none; }
    .report-back:hover, .report-print:hover { background: #f8fafc; color: #4338ca; }
    .report-hero { position: relative; overflow: hidden; padding: 30px; border-radius: 20px; background: linear-gradient(120deg, #0f766e, #0891b2 58%, #38bdf8); color: #fff; box-shadow: 0 16px 35px rgba(8,145,178,.2); }
    .report-hero:after { position: absolute; top: -110px; right: -80px; width: 300px; height: 300px; border: 1px solid rgba(255,255,255,.16); border-radius: 50%; box-shadow: 0 0 0 35px rgba(255,255,255,.05), 0 0 0 75px rgba(255,255,255,.04); content: ""; }
    .report-eyebrow { margin-bottom: 10px; color: #cffafe; font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
    .report-hero h1 { margin: 0; font-size: clamp(26px, 4vw, 36px); font-weight: 800; letter-spacing: -.03em; }
    .report-subtitle { margin: 8px 0 0; color: #cffafe; font-size: 14px; }
    .report-meta { display: flex; flex-wrap: wrap; gap: 18px 28px; margin-top: 24px; }
    .report-meta span { color: #cffafe; font-size: 13px; }
    .report-meta strong { display: block; margin-top: 4px; color: #fff; font-size: 14px; }
    .report-grid { display: grid; grid-template-columns: minmax(270px, .8fr) minmax(0, 1.4fr); gap: 18px; margin-top: 18px; }
    .report-card { min-width: 0; padding: 22px; border: 1px solid #e7ebf2; border-radius: 16px; background: #fff; box-shadow: 0 5px 18px rgba(15,23,42,.035); }
    .report-card h2 { margin: 0; color: #172033; font-size: 17px; font-weight: 800; }
    .card-caption { margin: 6px 0 18px; color: #758197; font-size: 12px; }
    .score-summary { display: grid; justify-items: center; padding: 10px 0 14px; text-align: center; }
    .score-chart-wrap { position: relative; width: 100%; max-width: 340px; height: 260px; margin: 0 auto; }
    .score-summary-label { margin-top: 16px; color: #64748b; font-size: 13px; }
    .domain-legend { display: grid; width: 100%; gap: 11px; margin: 18px 0 0; padding: 0; list-style: none; text-align: left; }
    .domain-legend li { display: grid; grid-template-columns: 12px 1fr auto; align-items: center; gap: 9px; color: #526078; font-size: 12px; }
    .domain-swatch { width: 11px; height: 11px; border-radius: 3px; }
    .domain-legend strong { color: #28334a; }
    .composite-card { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-top: 16px; padding: 18px 20px; border: 1px solid #c7d2fe; border-radius: 15px; background: linear-gradient(110deg, #eef2ff, #f8faff); }
    .composite-copy { display: flex; align-items: flex-start; gap: 13px; }
    .composite-icon { display: grid; width: 42px; height: 42px; flex: 0 0 42px; place-items: center; border-radius: 12px; background: #e0e7ff; color: #4338ca; font-size: 19px; }
    .composite-label { color: #312e81; font-size: 15px; font-weight: 850; }
    .composite-formula { margin-top: 4px; color: #64748b; font-size: 12px; }
    .composite-value { min-width: 90px; padding: 8px 16px; border: 1px solid #c7d2fe; border-radius: 12px; background: #fff; color: #3730a3; font-size: 30px; font-weight: 900; line-height: 1.1; text-align: center; box-shadow: 0 4px 12px rgba(67,56,202,.08); }
    .composite-value.is-negative { border-color: #fecaca; background: #fff7f7; color: #b91c1c; }
    .composite-value.is-positive { border-color: #bbf7d0; background: #f0fdf4; color: #15803d; }
    .domain-progress-list { display: grid; gap: 15px; }
    .domain-progress-top { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 7px; color: #475569; font-size: 12px; }
    .domain-progress-top strong { color: #28334a; }
    .domain-track, .item-track { overflow: hidden; height: 8px; border-radius: 99px; background: #edf0f6; }
    .domain-fill { height: 100%; border-radius: inherit; }
    .item-groups { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 18px; }
    .item-group { padding: 17px; border: 1px solid #e7ebf2; border-radius: 14px; background: #fff; }
    .item-group h3 { margin: 0 0 14px; color: #172033; font-size: 14px; font-weight: 800; }
    .item-list { display: grid; gap: 12px; }
    .item-row { display: grid; grid-template-columns: minmax(115px, 1fr) minmax(50px, .8fr) minmax(110px, auto); align-items: center; gap: 9px; }
    .item-name { color: #526078; font-size: 11px; }
    .item-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0891b2, #14b8a6); }
    .item-value { display: grid; justify-items: end; gap: 2px; color: #0f766e; text-align: right; }
    .item-value strong { font-size: 11px; font-weight: 800; }
    .item-rating-name { color: #64748b; font-size: 10px; font-weight: 600; line-height: 1.25; }
    .notes-card { margin-top: 18px; }
    .clinical-notes { margin: 10px 0 0; color: #526078; font-size: 13px; line-height: 1.7; white-space: pre-wrap; overflow-wrap: anywhere; }
    .report-empty { margin: 28px auto; max-width: 640px; padding: 30px; border: 1px solid #fee2e2; border-radius: 16px; background: #fff; color: #991b1b; text-align: center; }
    .report-empty h1 { margin: 0 0 8px; font-size: 21px; }
    @media (max-width: 800px) { .report-grid { grid-template-columns: 1fr; } .item-groups { grid-template-columns: 1fr; } }
    @media (max-width: 760px) { .score-report { padding: 20px 12px 32px; } .report-hero { padding: 24px 20px; } .report-card { padding: 18px; } }
    @media (max-width: 460px) { .score-chart-wrap { height: 230px; } }
    @media (max-width: 460px) { .report-topbar { align-items: stretch; } .report-back, .report-print { justify-content: center; padding: 9px 10px; } .item-row { grid-template-columns: minmax(90px, 1fr) minmax(35px, .6fr) minmax(88px, auto); gap: 7px; } }
    @media (max-width: 460px) { .composite-card { align-items: flex-start; padding: 15px; } .composite-copy { gap: 10px; } .composite-value { min-width: 70px; padding: 8px 10px; font-size: 25px; } }
    @media print { .report-topbar, .admin-sidebar, .admin-navbar, .sidebar-backdrop { display: none !important; } .score-report { max-width: none; padding: 0; } .report-hero, .report-card, .item-group { box-shadow: none; break-inside: avoid; } body { background: #fff !important; } }
</style>

<div class="score-report">
    <div class="report-topbar">
        <a class="report-back" href="panss-assessment-records.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to records</a>
        <?php if ($record): ?><button class="report-print" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print report</button><?php endif; ?>
    </div>
    <?php if (!$record): ?>
        <section class="report-empty" role="alert">
            <h1>Report unavailable</h1>
            <p class="mb-0"><?php echo panss_score_escape($load_error); ?></p>
        </section>
    <?php else: ?>
        <header class="report-hero">
            <div class="report-eyebrow">Clinical assessment report</div>
            <h1>PANSS Score Overview</h1>
            <p class="report-subtitle">Positive and Negative Syndrome Scale · 30-item assessment</p>
            <div class="report-meta">
                <span>Patient<strong><?php echo panss_score_escape($patient_name); ?></strong></span>
                <span>Assessment date<strong><?php echo panss_score_escape(date('d M Y', strtotime($record['assessment_date']))); ?></strong></span>
                <span>Clinician<strong><?php echo panss_score_escape($clinician_name); ?></strong></span>
            </div>
        </header>
        <div class="report-grid">
            <section class="report-card" aria-labelledby="panssSummaryTitle">
                <h2 id="panssSummaryTitle">Scale score distribution</h2>
                <p class="card-caption">Share of the summed positive, negative and general scores.</p>
                <div class="score-summary">
                    <div class="score-chart-wrap">
                        <canvas id="panssScoreChart" role="img" aria-label="Pie chart showing PANSS Positive, Negative and General scale scores"></canvas>
                    </div>
                    <div class="score-summary-label">Combined score: <?php echo array_sum(array_column($domain_scores, 'score')); ?> points</div>
                    <ul class="domain-legend" aria-label="Score distribution legend">
                        <?php foreach ($domain_scores as $index => $domain): ?>
                            <li>
                                <span class="domain-swatch" style="background:<?php echo $chart_colors[$index]; ?>" aria-hidden="true"></span>
                                <span><?php echo panss_score_escape($domain['title']); ?></span>
                                <strong><?php echo $domain['score']; ?> / <?php echo $domain['max']; ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="composite-card" role="group" aria-label="Composite score <?php echo $composite_score; ?>">
                    <div class="composite-copy">
                        <span class="composite-icon" aria-hidden="true"><i class="bi bi-calculator"></i></span>
                        <div>
                            <div class="composite-label">Composite score</div>
                            <div class="composite-formula">Positive scale − Negative scale</div>
                        </div>
                    </div>
                    <strong class="composite-value <?php echo $composite_score < 0 ? 'is-negative' : ($composite_score > 0 ? 'is-positive' : ''); ?>">
                        <?php echo $composite_score > 0 ? '+' : ''; ?><?php echo $composite_score; ?>
                    </strong>
                </div>
            </section>
            <section class="report-card" aria-labelledby="panssDomainsTitle">
                <h2 id="panssDomainsTitle">Scale comparison</h2>
                <p class="card-caption">Each scale shown against its own maximum possible score.</p>
                <div class="domain-progress-list">
                    <?php foreach ($domain_scores as $index => $domain): ?>
                        <div>
                            <div class="domain-progress-top">
                                <span><?php echo panss_score_escape($domain['title']); ?></span>
                                <strong><?php echo $domain['score']; ?> / <?php echo $domain['max']; ?></strong>
                            </div>
                            <div class="domain-track" role="img" aria-label="<?php echo panss_score_escape($domain['title']); ?> score <?php echo $domain['score']; ?> out of <?php echo $domain['max']; ?>">
                                <div class="domain-fill" style="width:<?php echo min(100, ($domain['score'] / $domain['max']) * 100); ?>%;background:<?php echo $chart_colors[$index]; ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="card-caption mt-4 mb-0">Each PANSS item is rated from 1 (absent) to 7 (extreme).</p>
            </section>
        </div>
        <section class="item-groups" aria-label="Individual PANSS item scores">
            <?php foreach ($panss_sections as $prefix => $section): ?>
                <div class="item-group">
                    <h3><?php echo panss_score_escape($section['title']); ?> <span class="text-muted fw-normal">(<?php echo count($panss_items[$prefix]); ?> items)</span></h3>
                    <div class="item-list">
                        <?php foreach ($all_scores[$prefix] as $item): ?>
                            <div class="item-row">
                                <span class="item-name"><strong><?php echo panss_score_escape($item['code']); ?></strong> · <?php echo panss_score_escape($item['label']); ?></span>
                                <span class="item-track" aria-hidden="true"><span class="item-fill" style="display:block;width:<?php echo ($item['rating'] / 7) * 100; ?>%"></span></span>
                                <span class="item-value">
                                    <strong><?php echo $item['rating']; ?>/7</strong>
                                    <small class="item-rating-name"><?php echo panss_score_escape($item['rating_label']); ?></small>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <?php if (trim($notes) !== ''): ?>
            <section class="report-card notes-card" aria-labelledby="panssNotesTitle">
                <h2 id="panssNotesTitle">Clinical notes</h2>
                <p class="clinical-notes"><?php echo panss_score_escape($notes); ?></p>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('panssScoreChart');
    if (!canvas) {
        return;
    }

    new Chart(canvas, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_column($domain_scores, 'title'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($domain_scores, 'score')); ?>,
                maximums: <?php echo json_encode(array_column($domain_scores, 'max')); ?>,
                backgroundColor: <?php echo json_encode($chart_colors); ?>,
                borderColor: '#ffffff',
                borderWidth: 2,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16, color: '#475569', font: { size: 12 } } },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return 'Score: ' + context.raw + ' / ' + context.dataset.maximums[context.dataIndex];
                        }
                    }
                }
            }
        }
    });
});
</script>

<?php require 'layout/footer.php'; ?>
