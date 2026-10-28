<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'BPRS Assessment Records - Vimhans Academic';
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
$records = [];
$load_error = '';

function bprs_records_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$records_query = "SELECT b.id, b.patient_id, b.clinician_id, b.assessment_date, b.total_score,
        b.score_data, b.clinical_notes,
        TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(NULLIF(p.last_name, '.'), ''))) AS patient_name,
        TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
    FROM bprs_assessments b
    LEFT JOIN users p ON p.id = b.patient_id
    LEFT JOIN users c ON c.id = b.clinician_id
    ORDER BY b.assessment_date DESC, b.id DESC";
$records_result = $conn->query($records_query);

if ($records_result) {
    while ($record = $records_result->fetch_assoc()) {
        $records[] = $record;
    }
} else {
    error_log('BPRS records query failed: ' . $conn->error);
    $load_error = 'Assessment records could not be loaded. Please contact the system administrator.';
}

require 'layout/base.php';
?>

<style>
    .bprs-records-page { max-width: 1440px; margin: 0 auto; padding: 28px 20px 42px; }
    .records-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin-bottom: 22px; }
    .records-header h1 { margin: 0 0 6px; color: #0f172a; font-size: 27px; font-weight: 800; }
    .records-header p { margin: 0; color: #64748b; font-size: 14px; }
    .records-action { display: inline-flex; align-items: center; gap: 8px; padding: 11px 15px; border-radius: 8px; background: #4f46e5; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
    .records-action:hover { background: #4338ca; color: #fff; }
    .records-panel { overflow: hidden; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 4px 14px rgba(15,23,42,.04); }
    .records-panel-heading { padding: 18px 20px; border-bottom: 1px solid #e2e8f0; }
    .records-panel-heading h2 { margin: 0; color: #0f172a; font-size: 16px; font-weight: 800; }
    .records-panel-heading p { margin: 5px 0 0; color: #64748b; font-size: 12px; }
    .records-table-wrap { padding: 4px 20px 18px; }
    .records-table { width: 100%; border-collapse: collapse; }
    .records-table th, .records-table td { padding: 13px 12px; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: middle; font-size: 13px; }
    .records-table th { background: #f8fafc; color: #64748b; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; }
    .records-table td { color: #334155; }
    .records-client-name { color: #0f172a; font-weight: 700; }
    .records-score { display: inline-flex; min-width: 68px; justify-content: center; padding: 5px 9px; border-radius: 6px; background: #eef2ff; color: #4338ca; font-weight: 800; white-space: nowrap; }
    .view-score-button { padding: 6px 10px; border: 1px solid #c7d2fe; border-radius: 7px; background: #fff; color: #4338ca; font-size: 12px; font-weight: 700; }
    .view-score-button:hover { background: #eef2ff; }
    .records-empty, .records-error { padding: 36px 20px; color: #64748b; font-size: 13px; text-align: center; }
    .records-error { color: #991b1b; }
    .score-modal-total { display: inline-flex; padding: 6px 11px; border-radius: 7px; background: #eef2ff; color: #4338ca; font-weight: 800; }
    .score-modal-table th { color: #64748b; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; }
    .score-modal-table td, .score-modal-table th { padding: 9px 12px; vertical-align: middle; }
    @media (max-width: 600px) { .bprs-records-page { padding: 20px 12px 30px; } .records-header { align-items: flex-start; flex-direction: column; } .records-action { width: 100%; justify-content: center; } .records-table-wrap { padding: 4px 12px 14px; } }
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="bprs-records-page">
        <div class="records-header">
            <div>
                <h1>BPRS Assessment Records</h1>
                <p>Search saved assessments and open a record to review all 18 item scores.</p>
            </div>
            <a class="records-action" href="bprs-assessments.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> New assessment</a>
        </div>

        <section class="records-panel" aria-labelledby="records-title">
            <div class="records-panel-heading">
                <h2 id="records-title">Assessment listing</h2>
                <p><?php echo count($records); ?> saved record<?php echo count($records) === 1 ? '' : 's'; ?></p>
            </div>
            <?php if ($load_error !== ''): ?>
                <div class="records-error" role="alert"><?php echo bprs_records_escape($load_error); ?></div>
            <?php elseif (empty($records)): ?>
                <div class="records-empty">No BPRS assessments have been saved yet.</div>
            <?php else: ?>
                <div class="table-responsive records-table-wrap">
                    <table class="records-table custom-datatable">
                        <thead>
                            <tr><th>Date</th><th>Client</th><th>Total score</th><th>Doctor name</th><th class="no-sort">Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <?php
                                $record_scores = json_decode($record['score_data'], true);
                                if (!is_array($record_scores)) {
                                    $record_scores = [];
                                }
                                $modal_scores = [];
                                foreach ($items as $field => $title) {
                                    $modal_scores[] = [
                                        'title' => $title,
                                        'score' => isset($record_scores[$field]) && is_numeric($record_scores[$field])
                                            ? (int) $record_scores[$field]
                                            : 0,
                                    ];
                                }
                                $client_name = trim($record['patient_name']) ?: 'Unknown client';
                                $clinician = trim($record['clinician_name']) ?: 'Unknown doctor';
                                ?>
                                <tr>
                                    <td data-order="<?php echo bprs_records_escape($record['assessment_date']); ?>"><?php echo bprs_records_escape(date('d M Y', strtotime($record['assessment_date']))); ?></td>
                                    <td><span class="records-client-name"><?php echo bprs_records_escape($client_name); ?></span></td>
                                    <td><span class="records-score"><?php echo (int) $record['total_score']; ?> / 126</span></td>
                                    <td><?php echo bprs_records_escape($clinician); ?></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="view-score-button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#bprsScoreModal"
                                            data-client="<?php echo bprs_records_escape($client_name); ?>"
                                            data-date="<?php echo bprs_records_escape(date('d M Y', strtotime($record['assessment_date']))); ?>"
                                            data-clinician="<?php echo bprs_records_escape($clinician); ?>"
                                            data-total="<?php echo (int) $record['total_score']; ?>"
                                            data-notes="<?php echo bprs_records_escape($record['clinical_notes'] ?? ''); ?>"
                                            data-scores="<?php echo bprs_records_escape(json_encode($modal_scores, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)); ?>">
                                            <i class="bi bi-eye me-1" aria-hidden="true"></i> View score
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<div class="modal fade" id="bprsScoreModal" tabindex="-1" aria-labelledby="bprsScoreModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5 fw-bold" id="bprsScoreModalLabel">BPRS score details</h2>
                    <div class="small text-muted mt-1" id="bprsModalPatient"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div class="small text-muted">
                        <span id="bprsModalDate"></span>
                        <span class="mx-1">•</span>
                        <span id="bprsModalClinician"></span>
                    </div>
                    <span class="score-modal-total">Total: <span id="bprsModalTotal" class="ms-1"></span> / 126</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped score-modal-table">
                        <thead><tr><th>#</th><th>Assessment item</th><th class="text-end">Score</th></tr></thead>
                        <tbody id="bprsModalScores"></tbody>
                    </table>
                </div>
                <section id="bprsModalNotesWrap" class="mt-3" hidden>
                    <h3 class="fs-6 fw-bold">Clinical notes</h3>
                    <p id="bprsModalNotes" class="mb-0 text-muted" style="white-space:pre-wrap;"></p>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('bprsScoreModal');
    if (!modal) {
        return;
    }

    modal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) {
            return;
        }

                document.getElementById('bprsModalPatient').textContent = button.dataset.client;
                document.getElementById('bprsModalDate').textContent = button.dataset.date;
                document.getElementById('bprsModalClinician').textContent = 'Doctor: ' + button.dataset.clinician;
        document.getElementById('bprsModalTotal').textContent = button.dataset.total;

        var scoreBody = document.getElementById('bprsModalScores');
        scoreBody.replaceChildren();
        var scores;
        try {
            scores = JSON.parse(button.dataset.scores || '[]');
        } catch (error) {
            scores = [];
        }

        scores.forEach(function (item, index) {
            var row = document.createElement('tr');
            var number = document.createElement('td');
            var title = document.createElement('td');
            var score = document.createElement('td');
            number.textContent = String(index + 1);
            title.textContent = item.title;
            score.textContent = String(item.score);
            score.className = 'text-end fw-bold';
            row.append(number, title, score);
            scoreBody.appendChild(row);
        });

        var notes = button.dataset.notes || '';
        document.getElementById('bprsModalNotesWrap').hidden = notes.trim() === '';
        document.getElementById('bprsModalNotes').textContent = notes;
    });
});
</script>

<?php require 'layout/footer.php'; ?>
