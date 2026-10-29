<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';
require 'ace-iii-assessment-schema.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
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
    $result = $conn->query(
        "SELECT a.id, a.patient_id, a.assessment_date, a.total_score,
                a.attention_score, a.memory_score, a.fluency_score, a.language_score,
                a.visuospatial_score,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(NULLIF(u.last_name, '.'), ''))) AS patient_name,
                TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
         FROM ace_iii_assessments a
         INNER JOIN users u ON u.id = a.patient_id AND u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . "
         LEFT JOIN users c ON c.id = a.clinician_id
         ORDER BY a.assessment_date DESC, a.id DESC"
    );
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
    .ace-records-table{width:100%;border-collapse:collapse}.ace-records-table th,.ace-records-table td{padding:11px 9px;border-bottom:1px solid #f1f5f9;text-align:left;font-size:12px;white-space:nowrap}
    .ace-records-table th{background:#f8fafc;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.04em}
    .ace-records-total{display:inline-block;padding:5px 9px;border-radius:7px;background:#ecfdf5;color:#047857;font-weight:800}
    .ace-records-empty{padding:36px 20px;color:#64748b;text-align:center;font-size:13px}
    @media(max-width:900px){.ace-records-head{align-items:flex-start;flex-direction:column}}
</style>
<div class="ace-records">
    <header class="ace-records-head"><div><h1>ACE-III Assessment Records</h1><p>Saved cognitive examination scores by patient and examination date.</p></div><a class="btn btn-primary" href="ace-iii-assessments.php"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i> New assessment</a></header>
    <?php if ($success_msg !== ''): ?><div class="alert alert-success" role="status"><?php echo ace_iii_records_escape($success_msg); ?></div><?php endif; ?>
    <section class="ace-records-panel"><h2>Assessment history</h2>
        <?php if ($load_error !== ''): ?><div class="ace-records-empty text-danger" role="alert"><?php echo ace_iii_records_escape($load_error); ?></div>
        <?php elseif (empty($records)): ?><div class="ace-records-empty">No ACE-III assessments have been saved yet.</div>
        <?php else: ?>
            <div class="table-responsive"><table class="ace-records-table custom-datatable"><thead><tr><th>Date</th><th>Patient</th><th>Attention /18</th><th>Memory /26</th><th>Fluency /14</th><th>Language /26</th><th>Visuospatial /16</th><th>Total /100</th><th>Clinician</th></tr></thead><tbody>
                <?php foreach ($records as $record): ?><tr>
                    <td data-order="<?php echo ace_iii_records_escape($record['assessment_date']); ?>"><?php echo ace_iii_records_escape(date('d M Y', strtotime($record['assessment_date']))); ?></td>
                    <td><?php echo ace_iii_records_escape(trim($record['patient_name']) ?: 'Unknown patient'); ?></td>
                    <td><?php echo (int) $record['attention_score']; ?></td><td><?php echo (int) $record['memory_score']; ?></td>
                    <td><?php echo (int) $record['fluency_score']; ?></td><td><?php echo (int) $record['language_score']; ?></td>
                    <td><?php echo (int) $record['visuospatial_score']; ?></td><td><span class="ace-records-total"><?php echo (int) $record['total_score']; ?> / 100</span></td>
                    <td><?php echo ace_iii_records_escape(trim($record['clinician_name']) ?: 'Unknown clinician'); ?></td>
                </tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
<?php require 'layout/footer.php'; ?>
