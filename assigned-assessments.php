<?php
session_start();
ob_start();

require 'config.php';

if (!isset($_SESSION['user_login']) || ($_SESSION['role'] ?? 0) != 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'Assigned Assessments - Vimhans Academic';
$assignments = [];
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
    ORDER BY aa.id DESC";

    if ($result = $conn->query($query)) {
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
    } else {
        $load_error = 'Assignment records could not be loaded. Please check the database tables.';
    }
}

$total_assignments = count($assignments);
$completed_count = 0;
$pending_count = 0;
foreach ($assignments as $assignment) {
    if ($assignment['assignment_status'] === 'completed' || !empty($assignment['completed_date'])) {
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
    .record-link { color: #0f766e; font-weight: 700; text-decoration: none; white-space: nowrap; }
    .record-link:hover { color: #115e59; text-decoration: underline; }
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
            <h1>Assigned Assessments</h1>
            <p>View every patient assessment assignment and its latest response.</p>
        </div>
        <a class="records-action" href="assign-assessments.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> New assignment</a>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><span class="summary-label">All assignments</span><strong class="summary-value"><?php echo $total_assignments; ?></strong></div>
        <div class="summary-card"><span class="summary-label">Pending responses</span><strong class="summary-value"><?php echo $pending_count; ?></strong></div>
        <div class="summary-card"><span class="summary-label">Completed responses</span><strong class="summary-value"><?php echo $completed_count; ?></strong></div>
    </div>

    <section class="records-panel">
        <div class="panel-top">
            <h2>Assignment records</h2>
        </div>

        <?php if ($load_error): ?>
            <div class="error-state"><?php echo htmlspecialchars($load_error); ?></div>
        <?php elseif (empty($assignments)): ?>
            <div class="empty-state">No assessment assignments found.</div>
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
                    <?php foreach ($assignments as $assignment):
                        $is_completed = $assignment['assignment_status'] === 'completed' || !empty($assignment['completed_date']);
                    ?>
                        <tr>
                            <td>
                                <span class="patient-name"><?php echo htmlspecialchars($assignment['patient_name'] ?: 'Unnamed patient'); ?></span>
                                <span class="patient-meta"><?php echo htmlspecialchars($assignment['phone'] ?: ($assignment['email'] ?: 'No contact')); ?></span>
                            </td>
                            <td>
                                <span class="assessment-title"><?php echo htmlspecialchars($assignment['assessment_title']); ?></span>
                               
                            </td>
                            <td><?php echo !empty($assignment['assigned_date']) ? date('d M Y', strtotime($assignment['assigned_date'])) : '<span class="muted">Not available</span>'; ?></td>
                            <td><span class="status-pill <?php echo $is_completed ? 'status-completed' : 'status-pending'; ?>"><i class="bi <?php echo $is_completed ? 'bi-check-circle-fill' : 'bi-clock-fill'; ?>"></i><?php echo $is_completed ? 'Completed' : 'Pending'; ?></span></td>
                            <td>
                                <?php if ($assignment['result_status'] !== null): ?>
                                    <span class="result-text"><?php echo htmlspecialchars($assignment['result_status']); ?></span><br>
                                    <small>Score: <?php echo (int) $assignment['total_score']; ?></small>
                                <?php else: ?>
                                    <span class="muted">Not submitted</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($assignment['completed_date']) ? date('d M Y', strtotime($assignment['completed_date'])) : '<span class="muted">-</span>'; ?></td>
                            <td><a class="record-link" href="public-assessment-form.php?patient_id=<?php echo (int) $assignment['patient_id']; ?>">Open <i class="bi bi-arrow-right"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require 'layout/footer.php'; ob_end_flush(); ?>
