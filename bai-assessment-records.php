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

$assessment_id = 2;
$page_title = 'BAI Assessment Records - Vimhans Academic';
$records = [];
$load_error = '';
$flash_success = '';
$flash_error = '';

if (!isset($_SESSION['bai_records_csrf'])) {
    $_SESSION['bai_records_csrf'] = bin2hex(random_bytes(32));
}

if (isset($_SESSION['bai_records_success'])) {
    $flash_success = (string) $_SESSION['bai_records_success'];
    unset($_SESSION['bai_records_success']);
}
if (isset($_SESSION['bai_records_error'])) {
    $flash_error = (string) $_SESSION['bai_records_error'];
    unset($_SESSION['bai_records_error']);
}

// Handle Secure Delete (deletes completed response record)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_response_id']) && isset($conn)) {
    $posted_token = $_POST['csrf_token'] ?? '';
    $del_id = filter_var($_POST['delete_response_id'], FILTER_VALIDATE_INT);

    if (!is_string($posted_token) || !hash_equals($_SESSION['bai_records_csrf'], $posted_token)) {
        $_SESSION['bai_records_error'] = 'Session expired. Please reload and try again.';
    } elseif ($del_id === false || $del_id < 1) {
        $_SESSION['bai_records_error'] = 'Invalid record selected.';
    } else {
        $check_stmt = $conn->prepare(
            "SELECT ar.id
             FROM assessment_responses ar
             INNER JOIN assigned_assessments aa ON aa.id = ar.assignment_id AND aa.assessment_id = ?
             INNER JOIN users u ON u.id = ar.patient_id
                 AND u.role_id = 2
                 AND u.patient_id = " . PATIENT_SCOPE_ID . "
             WHERE ar.id = ?
             LIMIT 1"
        );
        $valid_record = false;
        if ($check_stmt) {
            $check_stmt->bind_param('ii', $assessment_id, $del_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            $valid_record = ($check_stmt->num_rows === 1);
            $check_stmt->close();
        }

        if (!$valid_record) {
            $_SESSION['bai_records_error'] = 'Record not found or access denied.';
        } else {
            $del_stmt = $conn->prepare('DELETE FROM assessment_responses WHERE id = ? LIMIT 1');
            if ($del_stmt) {
                $del_stmt->bind_param('i', $del_id);
                if ($del_stmt->execute()) {
                    $_SESSION['bai_records_csrf'] = bin2hex(random_bytes(32));
                    $_SESSION['bai_records_success'] = 'BAI assessment record deleted successfully.';
                } else {
                    $_SESSION['bai_records_error'] = 'Could not delete the record. Please try again.';
                }
                $del_stmt->close();
            } else {
                $_SESSION['bai_records_error'] = 'Could not delete the record. Please try again.';
            }
        }
    }

    header('Location: ' . basename($_SERVER['PHP_SELF']));
    exit;
}

// Fetch Only Completed Records
if (isset($conn)) {
    $query = "SELECT
        ar.id AS response_id,
        aa.id AS assignment_id,
        aa.patient_id,
        aa.assessment_id,
        aa.assigned_date,
        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(NULLIF(u.last_name, '.'), ''))) AS patient_name,
        u.phone,
        u.email,
        a.title AS assessment_title,
        ar.total_score,
        ar.status_text AS result_status,
        ar.assessment_date AS completed_date
    FROM assessment_responses ar
    INNER JOIN assigned_assessments aa ON aa.id = ar.assignment_id AND aa.assessment_id = ?
    INNER JOIN users u ON u.id = ar.patient_id
        AND u.role_id = 2
        AND u.patient_id = " . PATIENT_SCOPE_ID . "
    INNER JOIN assessments a ON a.id = aa.assessment_id
    ORDER BY ar.id DESC";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('i', $assessment_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $records[] = $row;
            }
        } else {
            $load_error = 'BAI assessment records could not be loaded. Please contact the administrator.';
        }
        $stmt->close();
    } else {
        $load_error = 'BAI assessment records could not be loaded. Please contact the administrator.';
    }
}

$total_completed = count($records);
$unique_patients = count(array_unique(array_column($records, 'patient_id')));

function bai_rec_escape($val)
{
    return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
}

require 'layout/base.php';
?>

<style>
    :root {
        --br-primary: #0f766e;
        --br-primary-dark: #115e59;
        --br-primary-soft: #f0fdfa;
        --br-primary-border: #99f6e4;
        --br-border: #e2e8f0;
        --br-border-soft: #f1f5f9;
        --br-text: #0f172a;
        --br-muted: #64748b;
        --br-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .records-wrap {
        max-width: 1240px;
        margin: 0 auto;
        padding: 24px 18px 48px;
        color: var(--br-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Executive Header */
    .records-header {
        background: #fff;
        border: 1px solid var(--br-border);
        border-top: 4px solid var(--br-primary);
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
        box-shadow: var(--br-shadow);
    }

    .records-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--br-primary-soft);
        color: var(--br-primary);
        border: 1px solid var(--br-primary-border);
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        margin-bottom: 8px;
    }

    .records-header h1 {
        margin: 0;
        font-size: 23px;
        font-weight: 800;
        letter-spacing: -0.015em;
        color: var(--br-text);
    }

    .records-header p {
        margin: 4px 0 0;
        color: var(--br-muted);
        font-size: 13px;
    }

    .records-action {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        border-radius: 9px;
        background: var(--br-primary);
        color: #fff;
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
        white-space: nowrap;
        box-shadow: 0 3px 10px rgba(15, 118, 110, 0.22);
        transition: all 0.16s ease;
    }

    .records-action:hover {
        background: var(--br-primary-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    /* Flash Banners */
    .records-alert {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 18px;
    }

    .records-alert.is-success {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        border-left: 4px solid #059669;
        color: #065f46;
    }

    .records-alert.is-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        color: #991b1b;
    }

    /* 2-Column KPI Strip */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }

    .summary-card {
        background: #fff;
        border: 1px solid var(--br-border);
        border-top: 3px solid var(--kpi-color, var(--br-primary));
        border-radius: 12px;
        padding: 16px 20px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .summary-label {
        display: block;
        color: var(--br-muted);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .summary-value {
        display: block;
        margin-top: 6px;
        color: var(--br-text);
        font-size: 26px;
        font-weight: 800;
        line-height: 1;
    }

    .summary-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        font-size: 18px;
        background: var(--kpi-bg, var(--br-primary-soft));
        color: var(--kpi-color, var(--br-primary));
        flex-shrink: 0;
    }

    /* Main Table Card */
    .records-panel {
        background: #fff;
        border: 1px solid var(--br-border);
        border-radius: 14px;
        box-shadow: var(--br-shadow);
        overflow: hidden;
    }

    .panel-top {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--br-border);
        border-left: 4px solid var(--br-primary);
        padding: 14px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
    }

    .panel-top h2 {
        margin: 0;
        color: var(--br-text);
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .panel-count-pill {
        padding: 3px 11px;
        border-radius: 999px;
        background: var(--br-primary-soft);
        color: var(--br-primary);
        border: 1px solid var(--br-primary-border);
        font-size: 11.5px;
        font-weight: 800;
    }

    .records-table {
        width: 100%;
        border-collapse: collapse;
    }

    .records-table th,
    .records-table td {
        padding: 13px 16px;
        border-bottom: 1px solid var(--br-border-soft);
        text-align: left;
        vertical-align: middle;
        font-size: 13px;
    }

    .records-table th {
        color: #475569;
        background: #f8fafc;
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        white-space: nowrap;
        border-bottom: 1px solid var(--br-border);
    }

    .records-table tbody tr:hover {
        background: #fcfefd;
    }

    .patient-name {
        display: block;
        color: var(--br-text);
        font-weight: 700;
        font-size: 13.5px;
    }

    .patient-meta {
        display: block;
        margin-top: 2px;
        color: var(--br-muted);
        font-size: 11.5px;
    }

    .assessment-title {
        color: #1e293b;
        font-weight: 700;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
        background: #dcfce7;
        color: #166534;
    }

    .result-text {
        color: var(--br-primary);
        font-weight: 700;
    }

    .result-score {
        display: inline-block;
        margin-top: 2px;
        font-size: 11.5px;
        font-weight: 600;
        color: var(--br-muted);
    }

    .muted {
        color: #94a3b8;
    }

    /* Action Buttons (Report + Delete Only) */
    .action-links {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
    }

    .btn-report,
    .btn-delete {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.16s ease;
    }

    .btn-report {
        background: var(--br-primary-soft);
        color: var(--br-primary);
        border: 1px solid var(--br-primary-border);
    }

    .btn-report:hover {
        background: var(--br-primary);
        color: #fff;
        border-color: var(--br-primary);
    }

    .btn-delete {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    .btn-delete:hover {
        background: #dc2626;
        color: #fff;
        border-color: #dc2626;
    }

    .empty-state {
        padding: 52px 20px;
        text-align: center;
        color: var(--br-muted);
        font-size: 13.5px;
    }

    /* DataTables Overrides */
    #assignmentTable_wrapper {
        padding: 0 20px 20px;
        overflow: visible;
    }

    #assignmentTable_wrapper > .row {
        margin-right: 0;
        margin-left: 0;
    }

    #assignmentTable_wrapper .dataTables_length,
    #assignmentTable_wrapper .dataTables_filter {
        padding-top: 15px;
        padding-bottom: 8px;
        font-size: 13px;
        color: #475569;
    }

    #assignmentTable_wrapper .dataTables_filter {
        text-align: right;
    }

    #assignmentTable_wrapper .dataTables_filter input {
        width: 230px;
        margin-left: 8px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        padding: 5px 10px;
    }

    #assignmentTable_wrapper .dataTables_info {
        padding-top: 14px;
        color: var(--br-muted);
        font-size: 12.5px;
    }

    #assignmentTable_wrapper .dataTables_paginate {
        padding-top: 10px;
    }

    #assignmentTable_wrapper .pagination {
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 3px;
        margin: 0;
    }

    #assignmentTable_wrapper .page-link {
        min-width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px !important;
        border: 1px solid var(--br-border);
        color: #475569;
        font-size: 12.5px;
        font-weight: 600;
    }

    #assignmentTable_wrapper .page-item.active .page-link {
        color: #fff;
        background: var(--br-primary);
        border-color: var(--br-primary);
    }

    #assignmentTable_wrapper .page-item.disabled .page-link {
        color: #94a3b8;
        background: #f8fafc;
    }

    @media (max-width: 900px) {
        .summary-grid {
            grid-template-columns: 1fr;
        }
        .records-table th:nth-child(3),
        .records-table td:nth-child(3),
        .records-table th:nth-child(6),
        .records-table td:nth-child(6) {
            display: none;
        }
    }

    @media (max-width: 640px) {
        .records-wrap {
            padding: 16px 12px 32px;
        }
        .records-header {
            flex-direction: column;
            align-items: flex-start;
        }
        #assignmentTable_wrapper {
            padding: 0 12px 16px;
        }
        #assignmentTable_wrapper .dataTables_filter {
            text-align: left;
        }
        #assignmentTable_wrapper .dataTables_filter input {
            width: 100%;
            margin: 6px 0 0;
        }
        #assignmentTable_wrapper .dataTables_info {
            text-align: center;
        }
        #assignmentTable_wrapper .pagination {
            justify-content: center;
        }
    }
</style>

<div class="records-wrap">
    <!-- Executive Header -->
    <header class="records-header">
        <div>
            <div class="records-eyebrow">
                <i class="bi bi-journal-medical" aria-hidden="true"></i> Assessment Registry
            </div>
            <h1>BAI Assessment Records</h1>
            <p>View completed Beck Anxiety Inventory reports and manage saved patient records.</p>
        </div>
        <a class="records-action" href="bai-assessments.php">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> New Assessment
        </a>
    </header>

    <?php if ($flash_success !== ''): ?>
        <div class="records-alert is-success" role="status">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span><?php echo bai_rec_escape($flash_success); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($flash_error !== ''): ?>
        <div class="records-alert is-error" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span><?php echo bai_rec_escape($flash_error); ?></span>
        </div>
    <?php endif; ?>

    <!-- KPI Summary Strip -->
    <div class="summary-grid">
        <div class="summary-card" style="--kpi-color: #059669; --kpi-bg: #ecfdf5;">
            <div>
                <span class="summary-label">Completed Assessments</span>
                <strong class="summary-value"><?php echo $total_completed; ?></strong>
            </div>
            <span class="summary-icon" aria-hidden="true"><i class="bi bi-check2-circle"></i></span>
        </div>
        <div class="summary-card" style="--kpi-color: #0f766e; --kpi-bg: #f0fdfa;">
            <div>
                <span class="summary-label">Patients Assessed</span>
                <strong class="summary-value"><?php echo $unique_patients; ?></strong>
            </div>
            <span class="summary-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
        </div>
    </div>

    <!-- Records Table Card -->
    <section class="records-panel">
        <div class="panel-top">
            <h2>Completed Records</h2>
            <span class="panel-count-pill"><?php echo $total_completed; ?> Completed</span>
        </div>

        <?php if ($load_error): ?>
            <div class="records-alert is-error m-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span><?php echo bai_rec_escape($load_error); ?></span>
            </div>
        <?php elseif (empty($records)): ?>
            <div class="empty-state">No completed BAI assessments found yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 records-table custom-datatable" id="assignmentTable">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Assessment</th>
                            <th>Assessment Date</th>
                            <th>Status</th>
                            <th>Result &amp; Score</th>
                            <th>Completed Date</th>
                            <th class="no-sort">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td>
                                <span class="patient-name"><?php echo bai_rec_escape($record['patient_name'] ?: 'Unnamed patient'); ?></span>
                                <span class="patient-meta"><?php echo bai_rec_escape($record['phone'] ?: ($record['email'] ?: 'No contact')); ?></span>
                            </td>
                            <td>
                                <span class="assessment-title"><?php echo bai_rec_escape($record['assessment_title']); ?></span>
                            </td>
                            <td><?php echo !empty($record['completed_date']) ? date('d M Y', strtotime($record['completed_date'])) : (!empty($record['assigned_date']) ? date('d M Y', strtotime($record['assigned_date'])) : '<span class="muted">—</span>'); ?></td>
                            <td>
                                <span class="status-pill">
                                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Completed
                                </span>
                            </td>
                            <td>
                                <span class="result-text"><?php echo bai_rec_escape($record['result_status'] ?: 'Completed'); ?></span><br>
                                <span class="result-score">Score: <?php echo (int) $record['total_score']; ?> / 63</span>
                            </td>
                            <td><?php echo !empty($record['completed_date']) ? date('d M Y', strtotime($record['completed_date'])) : '<span class="muted">—</span>'; ?></td>
                            <td>
                                <div class="action-links">
                                    <a class="btn-report" href="bai-assessment-score.php?id=<?php echo (int) $record['response_id']; ?>">
                                        <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> Report
                                    </a>
                                    <form method="post" class="d-inline delete-record-form" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo bai_rec_escape($_SESSION['bai_records_csrf']); ?>">
                                        <input type="hidden" name="delete_response_id" value="<?php echo (int) $record['response_id']; ?>">
                                        <button type="submit" class="btn-delete" data-patient="<?php echo bai_rec_escape($record['patient_name'] ?: 'this patient'); ?>">
                                            <i class="bi bi-trash3" aria-hidden="true"></i> Delete
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.delete-record-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('.btn-delete');
            var patientName = btn ? btn.getAttribute('data-patient') : 'this patient';

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Delete Record?',
                    text: 'Are you sure you want to permanently delete the BAI record for ' + patientName + '?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Yes, Delete'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            } else if (window.confirm('Are you sure you want to permanently delete the BAI record for ' + patientName + '?')) {
                form.submit();
            }
        });
    });
});
</script>

<?php require 'layout/footer.php'; ob_end_flush(); ?>