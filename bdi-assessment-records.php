<?php
session_start();
ob_start();

require 'config.php';

if (!isset($_SESSION['user_login']) || ($_SESSION['role'] ?? 0) != 1) {
    header('Location: login.php');
    exit;
}

$assessment_id = 1;
$page_title = 'BDI Assessment Records - Vimhans Academic';
$records = [];
$load_error = '';

if (isset($conn)) {
    $query = "SELECT
        aa.id AS assignment_id,
        aa.patient_id,
        aa.assessment_id,
        aa.status AS assignment_status,
        aa.assigned_date,
        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(NULLIF(u.last_name, '.'), ''))) AS patient_name,
        u.phone,
        u.email,
        a.title AS assessment_title,
        a.description AS assessment_description,
        ar.total_score,
        ar.status_text AS result_status,
        ar.assessment_date AS completed_date
    FROM assigned_assessments aa
    INNER JOIN users u ON u.id = aa.patient_id
        AND u.role_id = 2
        AND u.patient_id = " . PATIENT_SCOPE_ID . "
    INNER JOIN assessments a ON a.id = aa.assessment_id
    LEFT JOIN assessment_responses ar ON ar.id = (
        SELECT response.id
        FROM assessment_responses response
                WHERE response.assignment_id = aa.id
        ORDER BY response.id DESC
        LIMIT 1
    )
    WHERE aa.assessment_id = ?
    ORDER BY aa.id DESC";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('i', $assessment_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $records[] = $row;
            }
        } else {
            $load_error = 'BDI assessment records could not be loaded. Please contact the administrator.';
        }
        $stmt->close();
    } else {
        $load_error = 'BDI assessment records could not be loaded. Please contact the administrator.';
    }
}

$total_records = count($records);
$completed_count = 0;
$pending_count = 0;
foreach ($records as $record) {
    if ($record['assignment_status'] === 'completed' || !empty($record['completed_date'])) {
        $completed_count++;
    } else {
        $pending_count++;
    }
}

require 'layout/base.php';
?>

<style>
    .records-wrap { max-width: 1240px; margin: 0 auto; padding: 28px 20px 42px; }
    .records-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 18px; margin-bottom: 22px; }
    .records-header h1 { margin: 0 0 6px; font-size: 27px; font-weight: 800; color: #0f172a; }
    .records-header p { margin: 0; color: #64748b; font-size: 14px; }
    .records-action { display: inline-flex; align-items: center; gap: 8px; padding: 11px 15px; border-radius: 8px; background: #0f766e; color: #fff; text-decoration: none; font-size: 13px; font-weight: 700; }
    .records-action:hover { background: #115e59; color: #fff; }
    .summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 20px; }
    .summary-card, .records-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 14px rgba(15, 23, 42, .04); }
    .summary-card { padding: 17px 18px; }
    .summary-label { display: block; color: #64748b; font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
    .summary-value { display: block; margin-top: 7px; color: #0f172a; font-size: 25px; font-weight: 800; }
    .records-panel { overflow: hidden; }
    .panel-top { display: flex; justify-content: space-between; align-items: center; gap: 15px; padding: 17px 20px; border-bottom: 1px solid #e2e8f0; }
    .panel-top h2 { margin: 0; color: #0f172a; font-size: 16px; font-weight: 800; }
    .records-table { width: 100%; border-collapse: collapse; }
    .records-table th, .records-table td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: middle; font-size: 13px; }
    .records-table th { color: #64748b; background: #f8fafc; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
    .records-table td { color: #334155; }
    .patient-name { display: block; color: #0f172a; font-weight: 700; }
    .patient-meta, .assessment-meta { display: block; margin-top: 3px; color: #94a3b8; font-size: 11px; }
    .assessment-title { color: #0f172a; font-weight: 700; }
    .status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 5px 9px; border-radius: 6px; font-size: 10px; font-weight: 800; white-space: nowrap; }
    .status-completed { background: #dcfce7; color: #15803d; }
    .status-pending { background: #fef3c7; color: #a16207; }
    .result-text { color: #0f766e; font-weight: 700; }
    .muted { color: #94a3b8; }
    .action-links { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .record-link {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700;
        text-decoration: none; white-space: nowrap;
        transition: all 0.2s ease;
        border: 1.5px solid #dfe7f0; background: #fff; color: #0f766e;
    }
    .record-link:hover { background: #0f766e; color: #fff; border-color: #0f766e; text-decoration: none; }
    .record-link.report-link { background: #fff; color: #0f766e; border-color: #cbd5e1; }
    .record-link.report-link:hover { background: #0f766e; color: #fff; border-color: #0f766e; }
    .empty-state { padding: 46px 20px; text-align: center; color: #64748b; font-size: 13px; }
    .error-state { margin: 20px; padding: 13px 15px; border-radius: 8px; color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; font-size: 13px; }
    #assignmentTable_wrapper { padding: 0 20px 20px; overflow: visible; }
    #assignmentTable_wrapper > .row { margin-right: 0; margin-left: 0; }
    #assignmentTable_wrapper .dataTables_length,
    #assignmentTable_wrapper .dataTables_filter { padding-top: 15px; padding-bottom: 5px; }
    #assignmentTable_wrapper .dataTables_filter { text-align: right; }
    #assignmentTable_wrapper .dataTables_filter input { width: 220px; margin-left: 8px; }
    #assignmentTable_wrapper .dataTables_info { padding-top: 14px; color: #64748b; font-size: 13px; }
    #assignmentTable_wrapper .dataTables_paginate { padding-top: 8px; }
    #assignmentTable_wrapper .pagination { justify-content: flex-end; flex-wrap: wrap; gap: 2px; margin: 0; }
    #assignmentTable_wrapper .page-link { min-width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; margin: 0; border: 1px solid #e2e8f0; color: #475569; }
    #assignmentTable_wrapper .page-item:first-child .page-link { border-radius: 8px 0 0 8px; }
    #assignmentTable_wrapper .page-item:last-child .page-link { border-radius: 0 8px 8px 0; }
    #assignmentTable_wrapper .page-item.active .page-link { color: #fff; background: #4f46e5; border-color: #4f46e5; }
    #assignmentTable_wrapper .page-item.disabled .page-link { color: #94a3b8; background: #f8fafc; }
    @media (max-width: 900px) { .records-table th:nth-child(3), .records-table td:nth-child(3), .records-table th:nth-child(6), .records-table td:nth-child(6) { display: none; } }
    @media (max-width: 600px) { #assignmentTable_wrapper { padding: 0 12px 15px; } #assignmentTable_wrapper .dataTables_filter { text-align: left; } #assignmentTable_wrapper .dataTables_filter input { width: 100%; margin: 5px 0 0; } #assignmentTable_wrapper .dataTables_info { text-align: center; } #assignmentTable_wrapper .dataTables_paginate { display: flex; justify-content: center; } #assignmentTable_wrapper .pagination { justify-content: center; } .records-wrap { padding: 20px 12px 30px; } .records-header { align-items: flex-start; flex-direction: column; } .summary-grid { gap: 8px; } .summary-card { padding: 13px; } .summary-value { font-size: 21px; } .panel-top { align-items: stretch; flex-direction: column; } .records-table th, .records-table td { padding: 12px 10px; } .records-table th:nth-child(4), .records-table td:nth-child(4), .records-table th:nth-child(5), .records-table td:nth-child(5) { display: none; } }
</style>

<div class="records-wrap">
    <div class="records-header">
        <div>
            <h1>BDI Assessment Records</h1>
            <p>View every patient assessment assignment and its latest response.</p>
        </div>
        <a class="records-action" href="public-assessment-form.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> New assessment</a>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><span class="summary-label">All assignments</span><strong class="summary-value"><?php echo $total_records; ?></strong></div>
        <div class="summary-card"><span class="summary-label">Pending responses</span><strong class="summary-value"><?php echo $pending_count; ?></strong></div>
        <div class="summary-card"><span class="summary-label">Completed responses</span><strong class="summary-value"><?php echo $completed_count; ?></strong></div>
    </div>

    <section class="records-panel">
        <div class="panel-top">
            <h2>Assignment records</h2>
        </div>

        <?php if ($load_error): ?>
            <div class="error-state"><?php echo htmlspecialchars($load_error); ?></div>
        <?php elseif (empty($records)): ?>
            <div class="empty-state">No BDI assessments have been saved yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0 records-table custom-datatable" id="assignmentTable">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Assessment</th>
                            <th>Assigned date</th>
                            <th>Assignment</th>
                            <th>Latest result</th>
                            <th>Completed date</th>
                            <th class="no-sort">View</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($records as $record):
                        $is_completed = $record['assignment_status'] === 'completed' || !empty($record['completed_date']);
                    ?>
                        <tr>
                            <td>
                                <span class="patient-name"><?php echo htmlspecialchars($record['patient_name'] ?: 'Unnamed patient'); ?></span>
                                <span class="patient-meta"><?php echo htmlspecialchars($record['phone'] ?: ($record['email'] ?: 'No contact')); ?></span>
                            </td>
                            <td>
                                <span class="assessment-title"><?php echo htmlspecialchars($record['assessment_title']); ?></span>
                            </td>
                            <td><?php echo !empty($record['assigned_date']) ? date('d M Y', strtotime($record['assigned_date'])) : '<span class="muted">Not available</span>'; ?></td>
                            <td><span class="status-pill <?php echo $is_completed ? 'status-completed' : 'status-pending'; ?>"><i class="bi <?php echo $is_completed ? 'bi-check-circle-fill' : 'bi-clock-fill'; ?>"></i><?php echo $is_completed ? 'Completed' : 'Pending'; ?></span></td>
                            <td>
                                <?php if ($record['result_status'] !== null): ?>
                                    <span class="result-text"><?php echo htmlspecialchars($record['result_status']); ?></span><br>
                                    <small>Score: <?php echo (int) $record['total_score']; ?></small>
                                <?php else: ?>
                                    <span class="muted">Not submitted</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($record['completed_date']) ? date('d M Y', strtotime($record['completed_date'])) : '<span class="muted">-</span>'; ?></td>
                            <td>
                                <div class="action-links">
                                    <a class="record-link" href="public-assessment-form.php?patient_id=<?php echo (int) $record['patient_id']; ?>">Open <i class="bi bi-arrow-right"></i></a>
                                    <?php if ($is_completed): ?>
                                        <a class="record-link report-link" href="assessment-report.php?assessment_id=<?php echo (int) $record['assessment_id']; ?>&patient_id=<?php echo (int) $record['patient_id']; ?>">Report <i class="bi bi-arrow-right"></i></a>
                                    <?php endif; ?>
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

<?php require 'layout/footer.php'; ob_end_flush(); ?>
