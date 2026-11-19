<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';
require 'ace-iii-assessment-schema.php';

$current_role = (int) ($_SESSION['role'] ?? 0);
if (!isset($_SESSION['user_login']) || !in_array($current_role, [1, 2], true)) {
    header('Location: login.php');
    exit;
}

$page_title = 'ACE-III Assessment Records - Vimhans Academic';
$records = [];
$load_error = '';
$success_msg = (string) ($_SESSION['ace_iii_saved'] ?? '');
unset($_SESSION['ace_iii_saved']);
if (!$ace_iii_schema_ready) {
    $load_error = 'Assessment records are unavailable. Please contact the system administrator.';
} else {
    $query = "SELECT a.id, a.patient_id, a.assessment_date, a.total_score,
                a.attention_score, a.memory_score, a.fluency_score, a.language_score,
                a.visuospatial_score, a.status,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(NULLIF(u.last_name, '.'), ''))) AS patient_name,
                TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
         FROM ace_iii_assessments a
         INNER JOIN users u ON u.id = a.patient_id AND u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . "
         LEFT JOIN users c ON c.id = a.clinician_id";
    if ($current_role === 2) {
        $query .= ' WHERE a.patient_id = ' . (int) ($_SESSION['user_id'] ?? 0);
    }
    $query .= ' ORDER BY a.assessment_date DESC, a.id DESC';

    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
    } else {
        error_log('ACE-III records query failed: ' . $conn->error);
        $load_error = 'Assessment records could not be loaded. Please try again later.';
    }
}

function ace_iii_records_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

require 'layout/base.php';
?>
<style>
    .ace-records{max-width:1400px;margin:0 auto;padding:28px 20px 44px}
    .ace-records-head{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:20px}
    .ace-records-head h1{margin:0 0 5px;color:#172033;font-size:25px;font-weight:800}
    .ace-records-head p{margin:0;color:#64748b;font-size:13px}
    .ace-records-panel{overflow:hidden;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 4px 16px rgba(15,23,42,.04)}
    .ace-records-panel h2{margin:0;padding:17px 20px;border-bottom:1px solid #e2e8f0;font-size:15px;font-weight:800}
    .ace-records-table{width:100%;border-collapse:collapse;border-spacing:0}
    .ace-records-table th,.ace-records-table td{padding:12px 10px;border-bottom:1px solid #f1f5f9;text-align:left;font-size:12px;vertical-align:middle}
    .ace-records-table tbody tr{transition:background-color .2s ease}
    .ace-records-table tbody tr:hover{background:#f8fafc}
    .ace-records-table th{background:#f8fafc;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
    .ace-records-table td{color:#334155}
    .ace-records-total{display:inline-block;padding:5px 9px;border-radius:7px;background:#ecfdf5;color:#047857;font-weight:800}
    .ace-records-status{display:inline-block;padding:5px 9px;border-radius:7px;font-weight:800}
    .ace-records-status.pending{background:#fef3c7;color:#92400e}
    .ace-records-status.success{background:#dcfce7;color:#166534}
    .ace-records-interpretation{display:inline-block;padding:5px 9px;border-radius:7px;font-weight:800;white-space:nowrap}
    .ace-records-interpretation.normal{background:#dcfce7;color:#166534}
    .ace-records-interpretation.borderline{background:#fef3c7;color:#92400e}
    .ace-records-interpretation.abnormal{background:#fee2e2;color:#991b1b}
    .ace-records-actions{display:flex;align-items:center;gap:8px;white-space:nowrap;flex-wrap:nowrap}
    .ace-records-action{display:inline-flex;align-items:center;justify-content:center;padding:7px 12px;border-radius:8px;background:#0f766e;color:#fff;text-decoration:none;font-size:12px;font-weight:700;border:1px solid #0f766e;transition:all .2s ease;line-height:1.2}
    .ace-records-action:hover{background:#115e59;border-color:#115e59;color:#fff;text-decoration:none;transform:translateY(-1px)}
    .ace-records-action.secondary{background:#fff;color:#0f766e;border-color:#dfe7f0}
    .ace-records-action.secondary:hover{background:#0f766e;color:#fff;border-color:#0f766e}
    .ace-records-action span{display:inline-flex;align-items:center}
    .ace-records-empty{padding:36px 20px;color:#64748b;text-align:center;font-size:13px}
    @media(max-width:900px){.ace-records-head{align-items:flex-start;flex-direction:column}.ace-records-actions{flex-wrap:wrap}}
</style>
<div class="ace-records">
    <header class="ace-records-head"><div><h1>ACE-III Assessment Records</h1><p>Saved cognitive examination scores by patient and examination date.</p></div><a class="btn btn-primary" href="ace-iii-assessments.php"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i> New assessment</a></header>
    <?php if ($success_msg !== ''): ?><div class="alert alert-success" role="status"><?php echo ace_iii_records_escape($success_msg); ?></div><?php endif; ?>
    <section class="ace-records-panel"><h2>Assessment history</h2>
        <?php if ($load_error !== ''): ?><div class="ace-records-empty text-danger" role="alert"><?php echo ace_iii_records_escape($load_error); ?></div>
        <?php elseif (empty($records)): ?><div class="ace-records-empty">No ACE-III assessments have been saved yet.</div>
        <?php else: ?>
            <div class="table-responsive" style="padding: 4px 20px 18px"><table class="ace-records-table custom-datatable"><thead><tr><th>Date</th><th>Patient</th><th>Attention /18</th><th>Memory /26</th><th>Fluency /14</th><th>Language /26</th><th>Visuospatial /16</th><th>Total /100</th><th>Interpretation</th><th>Status</th><th>Clinician</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($records as $record): $record_status = strtolower((string) ($record['status'] ?? 'Pending')); $status_label = $record_status === 'success' ? 'Success' : 'Pending'; $can_edit_record = $current_role === 1 || ($current_role === 2 && (int) $record['patient_id'] === (int) ($_SESSION['user_id'] ?? 0)); $record_total = (int) $record['total_score']; if ($record_total >= 88) { $interpretation_label = '88-100 - Normal Cognitive Function'; $interpretation_class = 'normal'; } elseif ($record_total >= 83) { $interpretation_label = '83-87 - Borderline/ Mild Cognitive Impairment'; $interpretation_class = 'borderline'; } else { $interpretation_label = '82-Below - Abnormal Cognitive Function'; $interpretation_class = 'abnormal'; } ?>
                    <tr>
                        <td data-order="<?php echo ace_iii_records_escape($record['assessment_date']); ?>"><?php echo ace_iii_records_escape(date('d M Y', strtotime($record['assessment_date']))); ?></td>
                        <td><?php echo ace_iii_records_escape(trim($record['patient_name']) ?: 'Unknown patient'); ?></td>
                        <td><?php echo (int) $record['attention_score']; ?></td><td><?php echo (int) $record['memory_score']; ?></td>
                        <td><?php echo (int) $record['fluency_score']; ?></td><td><?php echo (int) $record['language_score']; ?></td>
                        <td><?php echo (int) $record['visuospatial_score']; ?></td><td><span class="ace-records-total"><?php echo (int) $record['total_score']; ?> / 100</span></td>
                        <td><span class="ace-records-interpretation <?php echo $interpretation_class; ?>"><?php echo ace_iii_records_escape($interpretation_label); ?></span></td>
                        <td><span class="ace-records-status <?php echo $record_status === 'success' ? 'success' : 'pending'; ?>"><?php echo ace_iii_records_escape($status_label); ?></span></td>
                        <td><?php echo ace_iii_records_escape(trim($record['clinician_name']) ?: 'Waiting for scorer'); ?></td>
                        <td>
                            <div class="ace-records-actions">
                                <a class="ace-records-action" href="ace-iii-assessment-score.php?id=<?php echo (int) $record['id']; ?>">Report</a>
                                <?php if ($can_edit_record): ?><a class="ace-records-action secondary" href="ace-iii-assessments.php?edit_id=<?php echo (int) $record['id']; ?>">Edit</a><?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
<?php require 'layout/footer.php'; ?>
