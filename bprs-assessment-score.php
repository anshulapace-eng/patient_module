<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';

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
        'SELECT b.id, b.assessment_date, b.total_score, b.score_data, b.clinical_notes,
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
$total_score = 0;
$patient_name = '';
$clinician_name = '';
$notes = '';
$chart_score = 0;
$chart_percent = 0;

if ($record) {
    $saved_scores = json_decode($record['score_data'], true);
    $saved_scores = is_array($saved_scores) ? $saved_scores : [];
    foreach ($items as $field => $label) {
        $score = isset($saved_scores[$field]) && is_numeric($saved_scores[$field])
            ? max(0, min(7, (int) $saved_scores[$field]))
            : 0;
        $scores[] = [
            'label' => $label,
            'score' => $score,
            'rating_label' => $rating_labels[$score],
        ];
    }
    $total_score = (int) $record['total_score'];
    $patient_name = trim($record['patient_name']) ?: 'Unknown patient';
    $clinician_name = trim($record['clinician_name']) ?: 'Unknown clinician';
    $notes = (string) ($record['clinical_notes'] ?? '');
    $chart_score = max(0, min(126, $total_score));
    $chart_percent = ($chart_score / 126) * 100;
}

require 'layout/base.php';
?>

<style>
    .score-report { max-width: 1180px; margin: 0 auto; padding: 28px 20px 48px; color: #172033; }
    .report-topbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 22px; }
    .report-back, .report-print { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; color: #334155; font-size: 13px; font-weight: 700; text-decoration: none; }
    .report-back:hover, .report-print:hover { background: #f8fafc; color: #4338ca; }
    .report-hero { position: relative; overflow: hidden; padding: 30px; border-radius: 20px; background: linear-gradient(120deg, #312e81, #4f46e5 60%, #818cf8); color: #fff; box-shadow: 0 16px 35px rgba(79,70,229,.2); }
    .report-hero:after { position: absolute; top: -110px; right: -80px; width: 300px; height: 300px; border: 1px solid rgba(255,255,255,.16); border-radius: 50%; box-shadow: 0 0 0 35px rgba(255,255,255,.05), 0 0 0 75px rgba(255,255,255,.04); content: ""; }
    .report-eyebrow { margin-bottom: 10px; color: #c7d2fe; font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
    .report-hero h1 { margin: 0; font-size: clamp(26px, 4vw, 36px); font-weight: 800; letter-spacing: -.03em; }
    .report-subtitle { margin: 8px 0 0; color: #e0e7ff; font-size: 14px; }
    .report-meta { display: flex; flex-wrap: wrap; gap: 18px 28px; margin-top: 24px; }
    .report-meta span { color: #e0e7ff; font-size: 13px; }
    .report-meta strong { display: block; margin-top: 4px; color: #fff; font-size: 14px; }
    .report-grid { display: grid; grid-template-columns: minmax(260px, .75fr) minmax(0, 1.5fr); gap: 18px; margin-top: 18px; }
    .report-card { min-width: 0; padding: 22px; border: 1px solid #e7ebf2; border-radius: 16px; background: #fff; box-shadow: 0 5px 18px rgba(15,23,42,.035); }
    .report-card h2 { margin: 0; color: #172033; font-size: 17px; font-weight: 800; }
    .card-caption { margin: 6px 0 18px; color: #758197; font-size: 12px; }
    .score-summary { display: grid; justify-items: center; padding: 10px 0 14px; text-align: center; }
    .score-ring { display: grid; width: 190px; height: 190px; place-items: center; border-radius: 50%; background: conic-gradient(#4f46e5 0 <?php echo $chart_percent; ?>%, #e8ecf4 <?php echo $chart_percent; ?>% 100%); }
    .score-ring-inner { display: grid; width: 148px; height: 148px; align-content: center; justify-items: center; border-radius: 50%; background: #fff; }
    .score-ring-value { color: #172033; font-size: 39px; font-weight: 850; line-height: 1; }
    .score-ring-total { margin-top: 7px; color: #8490a3; font-size: 12px; font-weight: 700; }
    .score-summary-label { margin-top: 16px; color: #64748b; font-size: 13px; }
    .summary-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; width: 100%; margin-top: 18px; text-align: left; }
    .summary-stat { padding: 12px; border-radius: 10px; background: #f7f8fc; }
    .summary-stat span { display: block; color: #8490a3; font-size: 11px; }
    .summary-stat strong { display: block; margin-top: 3px; color: #28334a; font-size: 14px; }
    .item-list { display: grid; gap: 13px; }
    .item-row { display: grid; grid-template-columns: minmax(120px, 1fr) minmax(80px, 1.4fr) minmax(110px, auto); align-items: center; gap: 12px; }
    .item-name { color: #475569; font-size: 12px; }
    .item-track { overflow: hidden; height: 8px; border-radius: 99px; background: #edf0f6; }
    .item-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #6366f1, #8b5cf6); }
    .item-value { display: grid; justify-items: end; gap: 2px; color: #3730a3; text-align: right; }
    .item-value strong { font-size: 12px; font-weight: 800; }
    .item-rating-name { color: #64748b; font-size: 10px; font-weight: 600; line-height: 1.25; }
    .notes-card { margin-top: 18px; }
    .clinical-notes { margin: 10px 0 0; color: #526078; font-size: 13px; line-height: 1.7; white-space: pre-wrap; overflow-wrap: anywhere; }
    .report-empty { margin: 28px auto; max-width: 640px; padding: 30px; border: 1px solid #fee2e2; border-radius: 16px; background: #fff; color: #991b1b; text-align: center; }
    .report-empty h1 { margin: 0 0 8px; font-size: 21px; }
    @media (max-width: 760px) { .score-report { padding: 20px 12px 32px; } .report-hero { padding: 24px 20px; } .report-grid { grid-template-columns: 1fr; } .report-card { padding: 18px; } }
    @media (max-width: 460px) { .report-topbar { align-items: stretch; } .report-back, .report-print { justify-content: center; padding: 9px 10px; } .item-row { grid-template-columns: minmax(90px, 1fr) minmax(40px, .7fr) minmax(88px, auto); gap: 8px; } }
    @media print { .report-topbar, .admin-sidebar, .admin-navbar, .sidebar-backdrop { display: none !important; } .score-report { max-width: none; padding: 0; } .report-hero, .report-card { box-shadow: none; break-inside: avoid; } .report-grid { grid-template-columns: .75fr 1.5fr; } body { background: #fff !important; } }
</style>

<div class="score-report">
    <div class="report-topbar">
        <a class="report-back" href="bprs-assessment-records.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to records</a>
        <?php if ($record): ?><button class="report-print" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print report</button><?php endif; ?>
    </div>
    <?php if (!$record): ?>
        <section class="report-empty" role="alert">
            <h1>Report unavailable</h1>
            <p class="mb-0"><?php echo bprs_score_escape($load_error); ?></p>
        </section>
    <?php else: ?>
        <header class="report-hero">
            <div class="report-eyebrow">Clinical assessment report</div>
            <h1>BPRS Score Overview</h1>
            <p class="report-subtitle">Brief Psychiatric Rating Scale · 18-item assessment</p>
            <div class="report-meta">
                <span>Patient<strong><?php echo bprs_score_escape($patient_name); ?></strong></span>
                <span>Assessment date<strong><?php echo bprs_score_escape(date('d M Y', strtotime($record['assessment_date']))); ?></strong></span>
                <span>Clinician<strong><?php echo bprs_score_escape($clinician_name); ?></strong></span>
            </div>
        </header>
        <div class="report-grid">
            <section class="report-card" aria-labelledby="bprsSummaryTitle">
                <h2 id="bprsSummaryTitle">Overall score</h2>
                <p class="card-caption">Total points compared with the maximum possible score.</p>
                <div class="score-summary">
                    <div class="score-ring" role="img" aria-label="BPRS total score: <?php echo $total_score; ?> out of 126">
                        <div class="score-ring-inner">
                            <span class="score-ring-value"><?php echo $total_score; ?></span>
                            <span class="score-ring-total">of 126 points</span>
                        </div>
                    </div>
                    <div class="score-summary-label">BPRS total score</div>
                    <div class="summary-stats">
                        <div class="summary-stat"><span>Items rated</span><strong>18</strong></div>
                        <div class="summary-stat"><span>Scale range</span><strong>1–7 per item</strong></div>
                    </div>
                </div>
            </section>
            <section class="report-card" aria-labelledby="bprsItemsTitle">
                <h2 id="bprsItemsTitle">Item score breakdown</h2>
                <p class="card-caption">Individual item scores on a 1–7 scale.</p>
                <div class="item-list">
                    <?php foreach ($scores as $item): ?>
                        <div class="item-row">
                            <span class="item-name"><?php echo bprs_score_escape($item['label']); ?></span>
                            <span class="item-track" aria-hidden="true"><span class="item-fill" style="display:block;width:<?php echo ($item['score'] / 7) * 100; ?>%"></span></span>
                            <span class="item-value">
                                <strong><?php echo $item['score']; ?>/7</strong>
                                <small class="item-rating-name"><?php echo bprs_score_escape($item['rating_label']); ?></small>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php if (trim($notes) !== ''): ?>
            <section class="report-card notes-card" aria-labelledby="bprsNotesTitle">
                <h2 id="bprsNotesTitle">Clinical notes</h2>
                <p class="clinical-notes"><?php echo bprs_score_escape($notes); ?></p>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require 'layout/footer.php'; ?>
