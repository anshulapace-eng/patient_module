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

// Summary KPIs
$total_count = count($records);
$completed_count = 0;
$pending_draft_count = 0;
$completed_score_sum = 0;

foreach ($records as $r) {
    $st = strtolower(trim((string) ($r['status'] ?? 'Pending')));
    if ($st === 'success') {
        $completed_count++;
        $completed_score_sum += (int) $r['total_score'];
    } else {
        $pending_draft_count++;
    }
}
$avg_completed_score = $completed_count > 0 ? round($completed_score_sum / $completed_count, 1) : 0;

function ace_iii_records_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

require 'layout/base.php';
?>
<style>
    :root {
        --ar-primary: #0f766e;
        --ar-primary-dark: #115e59;
        --ar-primary-soft: #f0fdfa;
        --ar-primary-border: #99f6e4;
        --ar-border: #e2e8f0;
        --ar-border-soft: #f1f5f9;
        --ar-text: #0f172a;
        --ar-muted: #64748b;
        --ar-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .ace-records {
        max-width: 1420px;
        margin: 0 auto;
        padding: 24px 20px 48px;
        color: var(--ar-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Header Banner */
    .ace-records-head {
        background: #fff;
        border: 1px solid var(--ar-border);
        border-top: 4px solid var(--ar-primary);
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
        box-shadow: var(--ar-shadow);
    }

    .ace-head-eyebrow {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--ar-primary-soft);
        color: var(--ar-primary);
        border: 1px solid var(--ar-primary-border);
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        margin-bottom: 8px;
    }

    .ace-records-head h1 {
        margin: 0;
        color: var(--ar-text);
        font-size: 22px;
        font-weight: 800;
        letter-spacing: -0.01em;
    }

    .ace-records-head p {
        margin: 4px 0 0;
        color: var(--ar-muted);
        font-size: 13px;
    }

    .ace-new-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        border-radius: 9px;
        background: var(--ar-primary);
        border: 1px solid var(--ar-primary);
        color: #fff;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(15, 118, 110, 0.22);
        transition: all 0.16s ease;
    }

    .ace-new-btn:hover {
        background: var(--ar-primary-dark);
        border-color: var(--ar-primary-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    /* Summary KPI Cards */
    .ace-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }

    .ace-kpi-card {
        background: #fff;
        border: 1px solid var(--ar-border);
        border-radius: 12px;
        padding: 15px 18px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .ace-kpi-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ar-muted);
    }

    .ace-kpi-value {
        font-size: 24px;
        font-weight: 800;
        line-height: 1.15;
        color: var(--ar-text);
    }

    .ace-kpi-value.is-teal { color: var(--ar-primary); }
    .ace-kpi-value.is-amber { color: #b45309; }

    /* Toast Alert */
    .ace-alert-success {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        margin-bottom: 18px;
        border-radius: 10px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        font-size: 13.5px;
        font-weight: 600;
    }

    /* Table Panel */
    .ace-records-panel {
        overflow: hidden;
        border: 1px solid var(--ar-border);
        border-radius: 14px;
        background: #fff;
        box-shadow: var(--ar-shadow);
    }

    .ace-panel-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--ar-border);
        border-left: 4px solid var(--ar-primary);
        padding: 14px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .ace-panel-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--ar-text);
    }

    .ace-count-pill {
        background: var(--ar-primary-soft);
        color: var(--ar-primary);
        border: 1px solid var(--ar-primary-border);
        padding: 3px 11px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 700;
    }

    .ace-table-wrap {
        padding: 14px 20px 20px;
    }

    .ace-records-table {
        width: 100% !important;
        border-collapse: collapse;
        border-spacing: 0;
    }

    .ace-records-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 12px 10px;
        border-bottom: 1px solid var(--ar-border);
        white-space: nowrap;
        text-align: left;
    }

    .ace-records-table tbody td {
        padding: 12px 10px;
        border-bottom: 1px solid var(--ar-border-soft);
        font-size: 12.5px;
        color: #334155;
        vertical-align: middle;
    }

    .ace-records-table tbody tr {
        transition: background-color 0.15s ease;
    }

    .ace-records-table tbody tr:hover {
        background: #f8fafc;
    }

    .ace-patient-name {
        font-weight: 700;
        color: var(--ar-text);
    }

    .ace-domain-num {
        font-weight: 600;
        color: #475569;
        text-align: center;
    }

    .ace-records-total {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 999px;
        background: var(--ar-primary-soft);
        color: var(--ar-primary);
        border: 1px solid var(--ar-primary-border);
        font-weight: 800;
        font-size: 12px;
        white-space: nowrap;
    }

    .ace-records-interpretation {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 7px;
        font-weight: 700;
        font-size: 11.5px;
        white-space: nowrap;
    }

    .ace-records-interpretation.normal { background: #dcfce7; color: #166534; }
    .ace-records-interpretation.borderline { background: #fef3c7; color: #92400e; }
    .ace-records-interpretation.abnormal { background: #fee2e2; color: #991b1b; }

    .ace-records-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 11.5px;
        white-space: nowrap;
    }

    .ace-records-status::before {
        content: "";
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    .ace-records-status.success { background: #dcfce7; color: #166534; }
    .ace-records-status.pending { background: #fef3c7; color: #92400e; }
    .ace-records-status.draft { background: #e2e8f0; color: #475569; }

    .ace-records-actions {
        display: flex;
        align-items: center;
        gap: 7px;
        white-space: nowrap;
    }

    .ace-records-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 6px 11px;
        border-radius: 7px;
        background: var(--ar-primary);
        color: #fff;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        border: 1px solid var(--ar-primary);
        transition: all 0.16s ease;
        line-height: 1.2;
    }

    .ace-records-action:hover {
        background: var(--ar-primary-dark);
        border-color: var(--ar-primary-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    .ace-records-action.secondary {
        background: #fff;
        color: var(--ar-primary);
        border-color: #cbd5e1;
    }

    .ace-records-action.secondary:hover {
        background: var(--ar-primary);
        color: #fff;
        border-color: var(--ar-primary);
    }

    .ace-records-empty {
        padding: 44px 20px;
        color: var(--ar-muted);
        text-align: center;
        font-size: 13.5px;
    }

    @media (max-width: 1024px) {
        .ace-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .ace-records { padding: 16px 12px 32px; }
        .ace-records-head {
            flex-direction: column;
            align-items: flex-start;
        }
        .ace-new-btn { width: 100%; justify-content: center; }
        .ace-table-wrap { padding: 10px 12px 16px; }
    }

    @media (max-width: 480px) {
        .ace-kpi-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="ace-records">
    <!-- Header Banner -->
    <header class="ace-records-head">
        <div>
            <div class="ace-head-eyebrow">Cognitive Assessments</div>
            <h1>ACE-III Assessment Records</h1>
            <p>Saved Addenbrooke's Cognitive Examination scores by patient and examination date.</p>
        </div>
        <a class="ace-new-btn" href="ace-iii-assessments.php">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>New Assessment</span>
        </a>
    </header>

    <?php if ($success_msg !== ''): ?>
        <div class="ace-alert-success" role="status">
            <span><?php echo ace_iii_records_escape($success_msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- KPI Summary Row -->
    <?php if ($load_error === '' && !empty($records)): ?>
        <div class="ace-kpi-grid">
            <div class="ace-kpi-card">
                <span class="ace-kpi-label">Total Assessments</span>
                <span class="ace-kpi-value"><?php echo (int) $total_count; ?></span>
            </div>
            <div class="ace-kpi-card">
                <span class="ace-kpi-label">Completed (Success)</span>
                <span class="ace-kpi-value is-teal"><?php echo (int) $completed_count; ?></span>
            </div>
            <div class="ace-kpi-card">
                <span class="ace-kpi-label">Pending / Draft</span>
                <span class="ace-kpi-value is-amber"><?php echo (int) $pending_draft_count; ?></span>
            </div>
            <div class="ace-kpi-card">
                <span class="ace-kpi-label">Avg. Completed Score</span>
                <span class="ace-kpi-value is-teal">
                    <?php echo $completed_count > 0 ? ace_iii_records_escape($avg_completed_score . ' / 100') : '—'; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Records Table Panel -->
    <section class="ace-records-panel">
        <div class="ace-panel-head">
            <h2>Assessment History</h2>
            <span class="ace-count-pill"><?php echo (int) $total_count; ?> Records</span>
        </div>

        <?php if ($load_error !== ''): ?>
            <div class="ace-records-empty text-danger" role="alert"><?php echo ace_iii_records_escape($load_error); ?></div>
        <?php elseif (empty($records)): ?>
            <div class="ace-records-empty">No ACE-III assessments have been saved yet.</div>
        <?php else: ?>
            <div class="table-responsive ace-table-wrap">
                <table class="ace-records-table custom-datatable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Patient</th>
                            <th class="text-center">Attn /18</th>
                            <th class="text-center">Mem /26</th>
                            <th class="text-center">Flu /14</th>
                            <th class="text-center">Lang /26</th>
                            <th class="text-center">Visuo /16</th>
                            <th>Total /100</th>
                            <th>Interpretation</th>
                            <th>Status</th>
                            <th>Clinician</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record):
                            $record_status = strtolower(trim((string) ($record['status'] ?? 'Pending')));
                            if ($record_status === 'success') {
                                $status_label = 'Success';
                                $status_class = 'success';
                            } elseif ($record_status === 'draft') {
                                $status_label = 'Draft';
                                $status_class = 'draft';
                            } else {
                                $status_label = 'Pending';
                                $status_class = 'pending';
                            }
                            $can_edit_record = $current_role === 1 || ($current_role === 2 && (int) $record['patient_id'] === (int) ($_SESSION['user_id'] ?? 0));
                            $can_view_report = $record_status === 'success';
                            $record_total = (int) $record['total_score'];
                            if ($record_total >= 88) {
                                $interpretation_label = '88–100 · Normal';
                                $interpretation_class = 'normal';
                            } elseif ($record_total >= 83) {
                                $interpretation_label = '83–87 · Borderline / MCI';
                                $interpretation_class = 'borderline';
                            } else {
                                $interpretation_label = '≤82 · Abnormal';
                                $interpretation_class = 'abnormal';
                            }
                        ?>
                            <tr>
                                <td data-order="<?php echo ace_iii_records_escape($record['assessment_date']); ?>" style="white-space:nowrap;">
                                    <?php echo ace_iii_records_escape(date('d M Y', strtotime($record['assessment_date']))); ?>
                                </td>
                                <td class="ace-patient-name">
                                    <?php echo ace_iii_records_escape(trim($record['patient_name']) ?: 'Unknown patient'); ?>
                                </td>
                                <td class="ace-domain-num"><?php echo (int) $record['attention_score']; ?></td>
                                <td class="ace-domain-num"><?php echo (int) $record['memory_score']; ?></td>
                                <td class="ace-domain-num"><?php echo (int) $record['fluency_score']; ?></td>
                                <td class="ace-domain-num"><?php echo (int) $record['language_score']; ?></td>
                                <td class="ace-domain-num"><?php echo (int) $record['visuospatial_score']; ?></td>
                                <td>
                                    <span class="ace-records-total"><?php echo $record_total; ?> / 100</span>
                                </td>
                                <td>
                                    <span class="ace-records-interpretation <?php echo $interpretation_class; ?>">
                                        <?php echo ace_iii_records_escape($interpretation_label); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="ace-records-status <?php echo $status_class; ?>">
                                        <?php echo ace_iii_records_escape($status_label); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo ace_iii_records_escape(trim($record['clinician_name']) ?: 'Waiting for scorer'); ?>
                                </td>
                                <td>
                                    <div class="ace-records-actions">
                                        <?php if ($can_view_report): ?>
                                            <a class="ace-records-action" href="ace-iii-assessment-score.php?id=<?php echo (int) $record['id']; ?>">
                                                <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i>
                                                <span>Report</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($can_edit_record): ?>
                                            <a class="ace-records-action secondary" href="ace-iii-assessments.php?edit_id=<?php echo (int) $record['id']; ?>">
                                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                <span>Edit</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!$can_view_report && !$can_edit_record): ?>
                                            <span class="text-muted">—</span>
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
<?php require 'layout/footer.php'; ?>