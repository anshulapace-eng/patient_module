<?php
session_start();
ob_start();

require 'config.php';

if (!isset($_SESSION['user_login']) || ($_SESSION['role'] ?? 0) != 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'Dashboard - Vimhans Academic';
$stats = [
    'patients' => 0,
    'assigned' => 0,
    'pending' => 0,
    'completed' => 0,
];
$recent_assignments = [];

if (isset($conn)) {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role_id = 2 AND patient_id = " . PATIENT_SCOPE_ID . ") AS patients,
        (SELECT COUNT(*) FROM assigned_assessments aa INNER JOIN users u ON u.id = aa.patient_id WHERE u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . ") AS assigned,
        (SELECT COUNT(*) FROM assigned_assessments aa INNER JOIN users u ON u.id = aa.patient_id WHERE u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . " AND aa.status = 'completed') AS completed,
        (SELECT COUNT(*) FROM assigned_assessments aa INNER JOIN users u ON u.id = aa.patient_id WHERE u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . " AND (aa.status IS NULL OR aa.status <> 'completed')) AS pending";
    if ($stats_result = $conn->query($stats_query)) {
        $stats = $stats_result->fetch_assoc() ?: $stats;
    }

    $recent_query = "SELECT aa.id, aa.status, aa.assigned_date,
        CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(NULLIF(u.last_name, '.'), '')) AS patient_name,
        u.phone, a.title
        FROM assigned_assessments aa
        INNER JOIN users u ON u.id = aa.patient_id
            AND u.role_id = 2
            AND u.patient_id = " . PATIENT_SCOPE_ID . "
        INNER JOIN assessments a ON a.id = aa.assessment_id
        ORDER BY aa.id DESC
        LIMIT 8";
    if ($recent_result = $conn->query($recent_query)) {
        while ($row = $recent_result->fetch_assoc()) {
            $recent_assignments[] = $row;
        }
    }
}

$completion_rate = (int) $stats['assigned'] > 0
    ? round(((int) $stats['completed'] / (int) $stats['assigned']) * 100)
    : 0;

require 'layout/base.php';
?>

<style>
    .dashboard-wrap { max-width: 1180px; margin: 0 auto; padding: 28px 20px 40px; }
    .dashboard-hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin-bottom: 24px; }
    .dashboard-hero h1 { margin: 0 0 6px; font-size: 28px; font-weight: 800; color: #0f172a; }
    .dashboard-hero p { margin: 0; color: #64748b; font-size: 14px; }
    .dashboard-action { display: inline-flex; align-items: center; gap: 8px; padding: 11px 16px; background: #0f766e; color: #fff; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 700; }
    .dashboard-action:hover { background: #115e59; color: #fff; }
    .metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 24px; }
    .metric-card, .dashboard-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 14px rgba(15, 23, 42, .04); }
    .metric-card { padding: 20px; position: relative; overflow: hidden; }
    .metric-card::after { content: ''; position: absolute; right: -22px; bottom: -28px; width: 90px; height: 90px; border-radius: 50%; background: #f0fdfa; }
    .metric-label { display: block; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .metric-value { display: block; margin-top: 10px; color: #0f172a; font-size: 30px; font-weight: 800; }
    .metric-icon { color: #0f766e; font-size: 20px; }
    .dashboard-content-grid { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(280px, .7fr); gap: 20px; }
    .panel-heading { display: flex; justify-content: space-between; align-items: center; padding: 18px 20px; border-bottom: 1px solid #e2e8f0; }
    .panel-heading h2 { margin: 0; color: #0f172a; font-size: 16px; font-weight: 800; }
    .panel-heading span { color: #94a3b8; font-size: 12px; }
    .assignment-table { width: 100%; border-collapse: collapse; }
    .assignment-table th, .assignment-table td { padding: 14px 20px; border-bottom: 1px solid #f1f5f9; text-align: left; font-size: 13px; }
    .assignment-table th { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    .assignment-table td { color: #334155; }
    .patient-name { color: #0f172a; font-weight: 700; }
    .status { display: inline-flex; padding: 5px 9px; border-radius: 6px; font-size: 11px; font-weight: 700; }
    .status.completed { background: #dcfce7; color: #15803d; }
    .status.pending { background: #fef3c7; color: #a16207; }
    .empty-state { padding: 38px 20px; text-align: center; color: #64748b; font-size: 13px; }
    .progress-panel { padding: 22px 20px; }
    .progress-ring { width: 142px; height: 142px; margin: 8px auto 20px; border-radius: 50%; display: grid; place-items: center; background: conic-gradient(#0f766e <?php echo $completion_rate; ?>%, #e2e8f0 0); position: relative; }
    .progress-ring::before { content: ''; position: absolute; inset: 12px; background: #fff; border-radius: 50%; }
    .progress-ring strong { position: relative; z-index: 1; font-size: 28px; color: #0f172a; }
    .progress-copy { text-align: center; color: #64748b; font-size: 13px; line-height: 1.6; }
    .quick-links { display: grid; gap: 10px; margin-top: 22px; }
    .quick-link { display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; color: #334155; text-decoration: none; font-size: 13px; font-weight: 700; }
    .quick-link:hover { border-color: #99f6e4; color: #0f766e; background: #f0fdfa; }
    @media (max-width: 900px) { .metric-grid { grid-template-columns: repeat(2, 1fr); } .dashboard-content-grid { grid-template-columns: 1fr; } }
    @media (max-width: 560px) { .dashboard-wrap { padding: 20px 12px 30px; } .dashboard-hero { align-items: flex-start; flex-direction: column; } .metric-grid { gap: 10px; } .metric-card { padding: 15px; } .assignment-table th:nth-child(3), .assignment-table td:nth-child(3) { display: none; } }
</style>

<div class="dashboard-wrap">
    <div class="dashboard-hero">
        <div>
            <h1>Good day, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h1>
            <p>Track patient assessments, assignments and completion progress.</p>
        </div>
        <a class="dashboard-action" href="assign-assessments.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Assign assessment</a>
    </div>

    <div class="metric-grid">
        <div class="metric-card"><i class="bi bi-people metric-icon"></i><span class="metric-label">Registered patients</span><strong class="metric-value"><?php echo (int) $stats['patients']; ?></strong></div>
        <div class="metric-card"><i class="bi bi-clipboard2-check metric-icon"></i><span class="metric-label">Total assignments</span><strong class="metric-value"><?php echo (int) $stats['assigned']; ?></strong></div>
        <div class="metric-card"><i class="bi bi-hourglass-split metric-icon"></i><span class="metric-label">Awaiting response</span><strong class="metric-value"><?php echo (int) $stats['pending']; ?></strong></div>
        <div class="metric-card"><i class="bi bi-check2-circle metric-icon"></i><span class="metric-label">Completed</span><strong class="metric-value"><?php echo (int) $stats['completed']; ?></strong></div>
    </div>

    <div class="dashboard-content-grid">
        <section class="dashboard-panel">
            <div class="panel-heading"><h2>Recent assignments</h2><span>Latest 8 records</span></div>
            <?php if (empty($recent_assignments)): ?>
                <div class="empty-state">No assessment assignments have been created yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="assignment-table">
                        <thead><tr><th>Patient</th><th>Assessment</th><th>Assigned</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent_assignments as $assignment): ?>
                            <tr>
                                <td><span class="patient-name"><?php echo htmlspecialchars(trim($assignment['patient_name']) ?: 'Unnamed patient'); ?></span><br><small><?php echo htmlspecialchars($assignment['phone'] ?? ''); ?></small></td>
                                <td><?php echo htmlspecialchars($assignment['title']); ?></td>
                                <td><?php echo !empty($assignment['assigned_date']) ? date('d M Y', strtotime($assignment['assigned_date'])) : 'Recently'; ?></td>
                                <td><span class="status <?php echo $assignment['status'] === 'completed' ? 'completed' : 'pending'; ?>"><?php echo $assignment['status'] === 'completed' ? 'Completed' : 'Pending'; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <aside class="dashboard-panel">
            <div class="panel-heading"><h2>Completion overview</h2></div>
            <div class="progress-panel">
                <div class="progress-ring"><strong><?php echo $completion_rate; ?>%</strong></div>
                <p class="progress-copy"><?php echo (int) $stats['completed']; ?> of <?php echo (int) $stats['assigned']; ?> assignments completed.</p>
                <div class="quick-links">
                    <a class="quick-link" href="public-assessment-form.php"><i class="bi bi-person-vcard"></i> Open patient assessments</a>
                    <a class="quick-link" href="assigned-assessments.php"><i class="bi bi-table"></i> View all assignment records</a>
                    <a class="quick-link" href="view-assessment-questions.php"><i class="bi bi-journal-text"></i> Manage question bank</a>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require 'layout/footer.php'; ob_end_flush(); ?>
