<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';
require 'panss-assessment-items.php';
require 'panss-assessment-schema.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'PANSS Assessment Records - Vimhans Academic';
$records = [];
$load_error = '';
$action_success = '';
$action_error = '';

if (!isset($_SESSION['panss_csrf_token'])) {
    $_SESSION['panss_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assessment'])) {
    $posted_token = $_POST['csrf_token'] ?? '';
    $assessment_id = filter_var($_POST['assessment_id'] ?? null, FILTER_VALIDATE_INT);

    if (!is_string($posted_token) || !hash_equals($_SESSION['panss_csrf_token'], $posted_token)) {
        $action_error = 'Your session token expired. Please try again.';
    } elseif ($assessment_id === false || $assessment_id < 1) {
        $action_error = 'Invalid assessment reference.';
    } elseif (!$panss_schema_ready) {
        $action_error = 'Assessment records are unavailable. Please try again later.';
    } else {
        $delete_stmt = $conn->prepare(
            'DELETE p FROM panss_assessments p
             INNER JOIN users u ON u.id = p.patient_id
                 AND u.role_id = 2
                 AND u.patient_id = ' . PATIENT_SCOPE_ID . '
             WHERE p.id = ?'
        );
        if (!$delete_stmt) {
            error_log('PANSS delete prepare failed: ' . $conn->error);
            $action_error = 'The assessment record could not be deleted. Please try again.';
        } else {
            $delete_stmt->bind_param('i', $assessment_id);
            if ($delete_stmt->execute() && $delete_stmt->affected_rows === 1) {
                $delete_stmt->close();
                $_SESSION['panss_action_success'] = 'Assessment record deleted successfully.';
                $_SESSION['panss_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
            if ($delete_stmt->error !== '') {
                error_log('PANSS delete failed: ' . $delete_stmt->error);
            }
            $delete_stmt->close();
            $action_error = 'The assessment record could not be deleted. It may have already been removed.';
        }
    }
}

if (isset($_SESSION['panss_action_success'])) {
    $action_success = (string) $_SESSION['panss_action_success'];
    unset($_SESSION['panss_action_success']);
}

function panss_records_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$records_query = "SELECT p.id, p.patient_id, p.clinician_id, p.assessment_date,
        p.positive_score, p.negative_score, p.general_score, p.composite_score,
        p.score_data, p.clinical_notes,
        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(NULLIF(u.last_name, '.'), ''))) AS patient_name,
        TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
    FROM panss_assessments p
    INNER JOIN users u ON u.id = p.patient_id
        AND u.role_id = 2
        AND u.patient_id = " . PATIENT_SCOPE_ID . "
    LEFT JOIN users c ON c.id = p.clinician_id
    ORDER BY p.assessment_date DESC, p.id DESC";
if (!$panss_schema_ready) {
    $load_error = 'Assessment records could not be loaded. Please contact the system administrator.';
} else {
    $records_result = $conn->query($records_query);
    if ($records_result) {
        while ($record = $records_result->fetch_assoc()) {
            $records[] = $record;
        }
    } else {
        error_log('PANSS records query failed: ' . $conn->error);
        $load_error = 'Assessment records could not be loaded. Please contact the system administrator.';
    }
}

require 'layout/base.php';
?>

<style>
    .panss-records-page { max-width: 1440px; margin: 0 auto; padding: 28px 20px 42px; }
    .panss-records-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin-bottom: 22px; }
    .panss-records-header h1 { margin: 0 0 6px; color: #0f172a; font-size: 27px; font-weight: 800; }
    .panss-records-header p { margin: 0; color: #64748b; font-size: 14px; }
    .panss-records-action { display: inline-flex; align-items: center; gap: 8px; padding: 11px 15px; border-radius: 8px; background: #4f46e5; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
    .panss-records-action:hover { background: #4338ca; color: #fff; }
    .panss-records-panel { overflow: hidden; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 4px 14px rgba(15,23,42,.04); }
    .panss-records-panel-heading { padding: 18px 20px; border-bottom: 1px solid #e2e8f0; }
    .panss-records-panel-heading h2 { margin: 0; color: #0f172a; font-size: 16px; font-weight: 800; }
    .panss-records-panel-heading p { margin: 5px 0 0; color: #64748b; font-size: 12px; }
    .panss-records-table-wrap { padding: 4px 20px 18px; }
    .panss-records-table { width: 100%; border-collapse: collapse; }
    .panss-records-table th, .panss-records-table td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: middle; font-size: 12px; }
    .panss-records-table th { background: #f8fafc; color: #64748b; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; }
    .panss-records-table td { color: #334155; }
    .panss-records-client { color: #0f172a; font-weight: 700; }
    .panss-score { display: inline-flex; justify-content: center; min-width: 48px; padding: 4px 7px; border-radius: 6px; background: #eef2ff; color: #4338ca; font-weight: 800; white-space: nowrap; }
    .panss-view-button { padding: 6px 10px; border: 1px solid #c7d2fe; border-radius: 7px; background: #fff; color: #4338ca; font-size: 12px; font-weight: 700; }
    .panss-view-button:hover { background: #eef2ff; }
    .panss-delete-button { padding: 6px 10px; border: 1px solid #fecaca; border-radius: 7px; background: #fff; color: #dc3545; font-size: 12px; font-weight: 700; }
    .panss-delete-button:hover { background: #fef2f2; }
    .panss-toast { position: fixed; top: 14px; right: 16px; z-index: 1100; display: flex; width: min(360px, calc(100vw - 32px)); align-items: center; gap: 13px; overflow: hidden; padding: 12px 16px; border-radius: 6px; color: #fff; box-shadow: 0 4px 12px rgba(15,23,42,.2); opacity: 1; transform: translateY(0); transition: opacity .25s ease, transform .25s ease; }
    .panss-toast-success { background: #0f766e; }
    .panss-toast-error { background: #dc3545; }
    .panss-toast.is-hiding { opacity: 0; transform: translateY(-8px); }
    .panss-toast-icon { display: grid; width: 36px; height: 36px; flex: 0 0 auto; place-items: center; border: 3px solid rgba(255,255,255,.9); border-radius: 50%; font-size: 16px; }
    .panss-toast-copy { min-width: 0; }
    .panss-toast-title { margin-right: 8px; font-size: 15px; font-weight: 800; }
    .panss-toast-message { font-size: 12.5px; }
    .panss-toast-progress { position: absolute; right: 0; bottom: 0; left: 0; height: 3px; background: rgba(255,255,255,.35); transform: scaleX(0); transform-origin: left; animation: panss-toast-progress 3s linear forwards; }
    @keyframes panss-toast-progress { to { transform: scaleX(1); } }
    @media (prefers-reduced-motion: reduce) { .panss-toast { transition: none; } .panss-toast-progress { animation: none; } }
    .panss-records-empty, .panss-records-error { padding: 36px 20px; color: #64748b; font-size: 13px; text-align: center; }
    .panss-records-error { color: #991b1b; }
    @media (max-width: 700px) { .panss-records-page { padding: 20px 10px 30px; } .panss-records-header { align-items: flex-start; flex-direction: column; } .panss-records-action { width: 100%; justify-content: center; } .panss-records-table-wrap { padding: 4px 10px 14px; } }
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="panss-records-page">
        <?php if ($action_success !== ''): ?>
            <div class="panss-toast panss-toast-success" id="panssDeleteSuccessToast" role="status" aria-live="polite">
                <span class="panss-toast-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span class="panss-toast-copy"><strong class="panss-toast-title">Success!</strong><span class="panss-toast-message"><?php echo panss_records_escape($action_success); ?></span></span>
                <span class="panss-toast-progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>
        <?php if ($action_error !== ''): ?>
            <div class="panss-toast panss-toast-error" id="panssDeleteErrorToast" role="alert" aria-live="assertive">
                <span class="panss-toast-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <span class="panss-toast-copy"><strong class="panss-toast-title">Error!</strong><span class="panss-toast-message"><?php echo panss_records_escape($action_error); ?></span></span>
                <span class="panss-toast-progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>
        <div class="panss-records-header">
            <div>
                <h1>PANSS Assessment Records</h1>
                <p>Review saved scale totals and all coded item ratings.</p>
            </div>
            <a class="panss-records-action" href="panss-assessments.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> New assessment</a>
        </div>

        <section class="panss-records-panel" aria-labelledby="panss-records-title">
            <div class="panss-records-panel-heading">
                <h2 id="panss-records-title">Assessment listing</h2>
                <p><?php echo count($records); ?> saved record<?php echo count($records) === 1 ? '' : 's'; ?></p>
            </div>
            <?php if ($load_error !== ''): ?>
                <div class="panss-records-error" role="alert"><?php echo panss_records_escape($load_error); ?></div>
            <?php elseif (empty($records)): ?>
                <div class="panss-records-empty">No assessments have been saved yet.</div>
            <?php else: ?>
                <div class="table-responsive panss-records-table-wrap">
                    <table class="panss-records-table custom-datatable">
                        <thead>
                            <tr>
                                <th>Date</th><th>Patient</th><th>Positive</th><th>Negative</th>
                                <th>General</th><th>Composite</th><th>Clinician</th><th class="no-sort">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <?php
                                $patient_name = trim($record['patient_name']) ?: 'Unknown patient';
                                $clinician_name = trim($record['clinician_name']) ?: 'Unknown clinician';
                                $score_data = json_decode($record['score_data'], true);
                                $score_data = is_array($score_data) ? $score_data : [];
                                $detail_scores = [];
                                foreach ($panss_items as $prefix => $items) {
                                    foreach ($items as $code => $label) {
                                        $score = $score_data[$code] ?? null;
                                        $detail_scores[] = [
                                            'code' => $code,
                                            'label' => $label,
                                            'rating' => is_numeric($score) ? (int) $score : null,
                                            'rating_label' => is_numeric($score) && isset($panss_rating_labels[(int) $score])
                                                ? $panss_rating_labels[(int) $score]
                                                : null,
                                        ];
                                    }
                                }
                                $details_json = json_encode($detail_scores, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
                                ?>
                                <tr>
                                    <td data-order="<?php echo panss_records_escape($record['assessment_date']); ?>"><?php echo panss_records_escape(date('d M Y', strtotime($record['assessment_date']))); ?></td>
                                    <td><span class="panss-records-client"><?php echo panss_records_escape($patient_name); ?></span></td>
                                    <td><span class="panss-score"><?php echo (int) $record['positive_score']; ?> / 49</span></td>
                                    <td><span class="panss-score"><?php echo (int) $record['negative_score']; ?> / 49</span></td>
                                    <td><span class="panss-score"><?php echo (int) $record['general_score']; ?> / 112</span></td>
                                    <td><span class="panss-score"><?php echo (int) $record['composite_score'] > 0 ? '+' : ''; ?><?php echo (int) $record['composite_score']; ?></span></td>
                                    <td><?php echo panss_records_escape($clinician_name); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <button
                                                type="button"
                                                class="panss-view-button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#panssDetailsModal"
                                                data-patient="<?php echo panss_records_escape($patient_name); ?>"
                                                data-clinician="<?php echo panss_records_escape($clinician_name); ?>"
                                                data-date="<?php echo panss_records_escape(date('d M Y', strtotime($record['assessment_date']))); ?>"
                                                data-positive="<?php echo (int) $record['positive_score']; ?>"
                                                data-negative="<?php echo (int) $record['negative_score']; ?>"
                                                data-general="<?php echo (int) $record['general_score']; ?>"
                                                data-composite="<?php echo (int) $record['composite_score']; ?>"
                                                data-notes="<?php echo panss_records_escape($record['clinical_notes'] ?? ''); ?>"
                                                data-scores="<?php echo panss_records_escape($details_json); ?>"
                                            >View</button>
                                            <form action="" method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this assessment record?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo panss_records_escape($_SESSION['panss_csrf_token']); ?>">
                                                <input type="hidden" name="assessment_id" value="<?php echo (int) $record['id']; ?>">
                                                <button type="submit" name="delete_assessment" value="1" class="panss-delete-button">
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

<div class="modal fade" id="panssDetailsModal" tabindex="-1" aria-labelledby="panssDetailsTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5 fw-bold" id="panssDetailsTitle">Assessment details</h2>
                    <div class="small text-muted mt-1"><span id="panssModalPatient"></span> · <span id="panssModalDate"></span> · <span id="panssModalClinician"></span></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="panss-score">Positive: <span id="panssModalPositive" class="ms-1"></span> / 49</span>
                    <span class="panss-score">Negative: <span id="panssModalNegative" class="ms-1"></span> / 49</span>
                    <span class="panss-score">General: <span id="panssModalGeneral" class="ms-1"></span> / 112</span>
                    <span class="panss-score">Composite: <span id="panssModalComposite" class="ms-1"></span></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead><tr><th>Code</th><th>Item</th><th>Rating</th></tr></thead>
                        <tbody id="panssModalScores"></tbody>
                    </table>
                </div>
                <section id="panssNotesWrap" class="mt-3" hidden>
                    <h3 class="fs-6 fw-bold">Clinical notes</h3>
                    <p id="panssModalNotes" class="mb-0 text-muted" style="white-space:pre-wrap;"></p>
                </section>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var successToast = document.getElementById('panssDeleteSuccessToast');
    var errorToast = document.getElementById('panssDeleteErrorToast');
    var toast = successToast || errorToast;
    if (toast) {
        window.setTimeout(function () {
            toast.classList.add('is-hiding');
        }, 3000);
    }

    var modal = document.getElementById('panssDetailsModal');
    if (!modal) {
        return;
    }

    modal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) {
            return;
        }

        document.getElementById('panssModalPatient').textContent = button.dataset.patient;
        document.getElementById('panssModalDate').textContent = button.dataset.date;
        document.getElementById('panssModalClinician').textContent = 'Clinician: ' + button.dataset.clinician;
        document.getElementById('panssModalPositive').textContent = button.dataset.positive;
        document.getElementById('panssModalNegative').textContent = button.dataset.negative;
        document.getElementById('panssModalGeneral').textContent = button.dataset.general;
        var composite = Number(button.dataset.composite);
        document.getElementById('panssModalComposite').textContent = composite > 0 ? '+' + composite : String(composite);

        var scoreBody = document.getElementById('panssModalScores');
        scoreBody.replaceChildren();
        var scores;
        try {
            scores = JSON.parse(button.dataset.scores || '[]');
        } catch (error) {
            scores = [];
        }
        scores.forEach(function (item) {
            var row = document.createElement('tr');
            var code = document.createElement('td');
            var label = document.createElement('td');
            var rating = document.createElement('td');
            code.textContent = item.code;
            label.textContent = item.label;
            rating.textContent = item.rating === null ? '-' : String(item.rating) + ' - ' + item.rating_label;
            row.append(code, label, rating);
            scoreBody.appendChild(row);
        });

        var notes = button.dataset.notes || '';
        document.getElementById('panssNotesWrap').hidden = notes.trim() === '';
        document.getElementById('panssModalNotes').textContent = notes;
    });
});
</script>

<?php require 'layout/footer.php'; ?>
