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
$records = [];
$load_error = '';
$action_success = '';
$action_error = '';

if (!isset($_SESSION['bprs_csrf_token'])) {
    $_SESSION['bprs_csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Delete Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assessment'])) {
    $posted_token = $_POST['csrf_token'] ?? '';
    $assessment_id = filter_var($_POST['assessment_id'] ?? null, FILTER_VALIDATE_INT);

    if (!is_string($posted_token) || !hash_equals($_SESSION['bprs_csrf_token'], $posted_token)) {
        $action_error = 'Your session token expired. Please try again.';
    } elseif ($assessment_id === false || $assessment_id < 1) {
        $action_error = 'Invalid assessment reference.';
    } else {
        $del_stmt = $conn->prepare("DELETE FROM bprs_assessments WHERE id = ? LIMIT 1");
        if ($del_stmt) {
            $del_stmt->bind_param('i', $assessment_id);
            if ($del_stmt->execute()) {
                $del_stmt->close();
                $_SESSION['bprs_action_success'] = 'BPRS assessment record deleted successfully.';
                $_SESSION['bprs_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
            $del_stmt->close();
        }
        $action_error = 'Failed to delete assessment record. Please try again.';
    }
}

if (isset($_SESSION['bprs_action_success'])) {
    $action_success = $_SESSION['bprs_action_success'];
    unset($_SESSION['bprs_action_success']);
}

function bprs_records_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$records_query = "SELECT b.id, b.patient_id, b.clinician_id, b.assessment_date, b.total_score,
        b.score_data, b.clinical_notes,
        TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(NULLIF(p.last_name, '.'), ''))) AS patient_name,
        TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
    FROM bprs_assessments b
    INNER JOIN users p ON p.id = b.patient_id
        AND p.role_id = 2
        AND p.patient_id = " . PATIENT_SCOPE_ID . "
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
    .view-score-button { display: inline-flex; align-items: center; padding: 6px 10px; border: 1px solid #c7d2fe; border-radius: 7px; background: #fff; color: #4338ca; font-size: 12px; font-weight: 700; }
    .view-score-button:hover { background: #eef2ff; }
    .delete-score-button { padding: 6px 10px; border: 1px solid #fecaca; border-radius: 7px; background: #fff; color: #dc3545; font-size: 12px; font-weight: 700; }
    .delete-score-button:hover { background: #fef2f2; }
    .records-empty, .records-error { padding: 36px 20px; color: #64748b; font-size: 13px; text-align: center; }
    .records-error { color: #991b1b; }
    .score-modal-total { display: inline-flex; padding: 6px 11px; border-radius: 7px; background: #eef2ff; color: #4338ca; font-weight: 800; }
    .score-modal-table th { color: #64748b; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; }
    .score-modal-table td, .score-modal-table th { padding: 9px 12px; vertical-align: middle; }
    .score-chart-section { margin-bottom: 20px; padding: 16px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
    .score-chart-section h3 { margin: 0 0 14px; color: #0f172a; font-size: 14px; font-weight: 800; }
    .score-chart-content { display: flex; flex-wrap: wrap; align-items: center; gap: 20px; }
    .score-pie { width: 150px; height: 150px; flex: 0 0 150px; border-radius: 50%; }
    .score-chart-legend { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
    .score-chart-legend li { display: flex; align-items: center; gap: 9px; color: #334155; font-size: 13px; }
    .score-chart-swatch { width: 12px; height: 12px; flex: 0 0 12px; border-radius: 3px; }
    @media (max-width: 600px) { .bprs-records-page { padding: 20px 12px 30px; } .records-header { align-items: flex-start; flex-direction: column; } .records-action { width: 100%; justify-content: center; } .records-table-wrap { padding: 4px 12px 14px; } }
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="bprs-records-page">
        <?php if ($action_success !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo bprs_records_escape($action_success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($action_error !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo bprs_records_escape($action_error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

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
                            <tr><th>Date</th><th>Client</th><th>BPRS total score</th><th>Doctor name</th><th class="no-sort">Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <?php
                                $client_name = trim($record['patient_name']) ?: 'Unknown client';
                                $clinician = trim($record['clinician_name']) ?: 'Unknown doctor';
                                ?>
                                <tr>
                                    <td data-order="<?php echo bprs_records_escape($record['assessment_date']); ?>"><?php echo bprs_records_escape(date('d M Y', strtotime($record['assessment_date']))); ?></td>
                                    <td><span class="records-client-name"><?php echo bprs_records_escape($client_name); ?></span></td>
                                    <td><span class="records-score">BPRS: <?php echo (int) $record['total_score']; ?> / 126</span></td>
                                    <td><?php echo bprs_records_escape($clinician); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <a class="view-score-button text-decoration-none" href="bprs-assessment-score.php?id=<?php echo (int) $record['id']; ?>">
                                                <i class="bi bi-graph-up me-1" aria-hidden="true"></i> View report
                                            </a>
                                            <form action="" method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this assessment record?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo bprs_records_escape($_SESSION['bprs_csrf_token']); ?>">
                                                <input type="hidden" name="assessment_id" value="<?php echo (int) $record['id']; ?>">
                                                <button type="submit" name="delete_assessment" value="1" class="delete-score-button">
                                                    <i class="bi bi-trash me-1" aria-hidden="true"></i> Delete
                                                </button>
                                            </form>
                                        </div>
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
                <section class="score-chart-section" aria-labelledby="bprsScoreChartTitle">
                    <h3 id="bprsScoreChartTitle">Score breakdown</h3>
                    <div class="score-chart-content">
                        <div id="bprsScorePie" class="score-pie" role="img"></div>
                        <ul id="bprsScoreLegend" class="score-chart-legend" aria-label="Score breakdown legend"></ul>
                    </div>
                </section>
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

        var totalScore = Number(button.dataset.total);
        var maxScore = 126;
        var scored = Number.isFinite(totalScore) ? Math.min(maxScore, Math.max(0, totalScore)) : 0;
        var remaining = maxScore - scored;
        var scoredPercent = (scored / maxScore) * 100;
        var scorePie = document.getElementById('bprsScorePie');
        scorePie.style.background = 'conic-gradient(#4f46e5 0 ' + scoredPercent + '%, #cbd5e1 ' + scoredPercent + '% 100%)';
        scorePie.setAttribute('aria-label', 'BPRS score: ' + scored + ' points; remaining: ' + remaining + ' points, out of 126');

        var legend = document.getElementById('bprsScoreLegend');
        legend.replaceChildren();
        [
            { label: 'Score', value: scored, color: '#4f46e5' },
            { label: 'Remaining', value: remaining, color: '#cbd5e1' }
        ].forEach(function (item) {
            var entry = document.createElement('li');
            var swatch = document.createElement('span');
            var text = document.createElement('span');
            swatch.className = 'score-chart-swatch';
            swatch.style.backgroundColor = item.color;
            swatch.setAttribute('aria-hidden', 'true');
            text.textContent = item.label + ': ' + item.value;
            entry.append(swatch, text);
            legend.appendChild(entry);
        });

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