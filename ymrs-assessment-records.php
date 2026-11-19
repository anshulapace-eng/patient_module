<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require 'config.php';
require 'ymrs-assessment-schema.php';

if (!isset($_SESSION['user_login']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = 'YMRS Assessment Records - Vimhans Academic';
if (!isset($_SESSION['ymrs_csrf_token'])) {
    $_SESSION['ymrs_csrf_token'] = bin2hex(random_bytes(32));
}

$action_success = '';
$action_error = '';
$records = [];
$load_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assessment'])) {
    $token = $_POST['csrf_token'] ?? '';
    $id = filter_var($_POST['assessment_id'] ?? null, FILTER_VALIDATE_INT);

    if (!is_string($token) || !hash_equals($_SESSION['ymrs_csrf_token'], $token)) {
        $action_error = 'Your session token expired.';
    } elseif ($id === false || $id < 1) {
        $action_error = 'Invalid assessment reference.';
    } else {
        $stmt = $conn->prepare('DELETE FROM ymrs_assessments WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['ymrs_action_success'] = 'YMRS assessment record deleted successfully.';
            $_SESSION['ymrs_csrf_token'] = bin2hex(random_bytes(32));
            header('Location: ymrs-assessment-records.php');
            exit;
        }
        $action_error = 'Failed to delete assessment record.';
    }
}

if (isset($_SESSION['ymrs_action_success'])) {
    $action_success = $_SESSION['ymrs_action_success'];
    unset($_SESSION['ymrs_action_success']);
}

function ymrs_records_escape($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if ($ymrs_schema_ready) {
    $sql = "SELECT y.id, y.assessment_date, y.total_score, y.score_data, y.clinical_notes,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(NULLIF(u.last_name,'.'),''))) AS patient_name,
                   TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(NULLIF(c.last_name,'.'),''))) AS clinician_name
            FROM ymrs_assessments y
            INNER JOIN users u ON u.id = y.patient_id AND u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . "
            LEFT JOIN users c ON c.id = y.clinician_id
            ORDER BY y.assessment_date DESC, y.id DESC";
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $records[] = $r;
        }
    } else {
        $load_error = 'Assessment records could not be loaded.';
    }
} else {
    $load_error = 'Assessment records are unavailable.';
}

// Summary KPIs
$total_count = count($records);
$normal_count = 0;
$elevated_count = 0;
$score_sum = 0;

foreach ($records as $r) {
    $sc = (int)$r['total_score'];
    $score_sum += $sc;
    if ($sc < 13) {
        $normal_count++;
    } elseif ($sc >= 20) {
        $elevated_count++;
    }
}
$avg_score = $total_count > 0 ? round($score_sum / $total_count, 1) : 0;

require 'layout/base.php';
?>
<style>
    :root {
        --yr-primary: #0f766e;
        --yr-primary-dark: #115e59;
        --yr-primary-soft: #f0fdfa;
        --yr-primary-border: #99f6e4;
        --yr-border: #e2e8f0;
        --yr-border-soft: #f1f5f9;
        --yr-text: #0f172a;
        --yr-muted: #64748b;
        --yr-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .ymrs-records {
        max-width: 1420px;
        margin: 0 auto;
        padding: 24px 20px 48px;
        color: var(--yr-text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Header Banner */
    .ymrs-records-head {
        background: #fff;
        border: 1px solid var(--yr-border);
        border-top: 4px solid var(--yr-primary);
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
        box-shadow: var(--yr-shadow);
    }

    .ymrs-head-eyebrow {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--yr-primary-soft);
        color: var(--yr-primary);
        border: 1px solid var(--yr-primary-border);
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        margin-bottom: 8px;
    }

    .ymrs-records-head h1 {
        margin: 0;
        color: var(--yr-text);
        font-size: 22px;
        font-weight: 800;
        letter-spacing: -0.01em;
    }

    .ymrs-records-head p {
        margin: 4px 0 0;
        color: var(--yr-muted);
        font-size: 13px;
    }

    .ymrs-new {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        border-radius: 9px;
        background: var(--yr-primary);
        border: 1px solid var(--yr-primary);
        color: #fff;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(15, 118, 110, 0.22);
        transition: all 0.16s ease;
    }

    .ymrs-new:hover {
        background: var(--yr-primary-dark);
        border-color: var(--yr-primary-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    /* Summary KPI Grid */
    .ymrs-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }

    .ymrs-kpi-card {
        background: #fff;
        border: 1px solid var(--yr-border);
        border-radius: 12px;
        padding: 15px 18px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .ymrs-kpi-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--yr-muted);
    }

    .ymrs-kpi-value {
        font-size: 24px;
        font-weight: 800;
        line-height: 1.15;
        color: var(--yr-text);
    }

    .ymrs-kpi-value.is-teal { color: var(--yr-primary); }
    .ymrs-kpi-value.is-red { color: #dc2626; }

    /* Alerts */
    .ymrs-alert {
        padding: 12px 16px;
        margin-bottom: 18px;
        border-radius: 10px;
        font-size: 13.5px;
        font-weight: 600;
    }

    .ymrs-alert.success {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
    }

    .ymrs-alert.error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }

    /* Table Panel */
    .ymrs-panel {
        overflow: hidden;
        border: 1px solid var(--yr-border);
        border-radius: 14px;
        background: #fff;
        box-shadow: var(--yr-shadow);
    }

    .ymrs-panel-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--yr-border);
        border-left: 4px solid var(--yr-primary);
        padding: 14px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .ymrs-panel-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--yr-text);
    }

    .ymrs-count-pill {
        background: var(--yr-primary-soft);
        color: var(--yr-primary);
        border: 1px solid var(--yr-primary-border);
        padding: 3px 11px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 700;
    }

    .ymrs-table-wrap {
        padding: 14px 20px 20px;
    }

    .ymrs-table {
        width: 100% !important;
        border-collapse: collapse;
        border-spacing: 0;
    }

    .ymrs-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 12px 12px;
        border-bottom: 1px solid var(--yr-border);
        white-space: nowrap;
        text-align: left;
    }

    .ymrs-table tbody td {
        padding: 13px 12px;
        border-bottom: 1px solid var(--yr-border-soft);
        font-size: 13px;
        color: #334155;
        vertical-align: middle;
    }

    .ymrs-table tbody tr {
        transition: background-color 0.15s ease;
    }

    .ymrs-table tbody tr:hover {
        background: #f8fafc;
    }

    .ymrs-patient-name {
        font-weight: 700;
        color: var(--yr-text);
    }

    .ymrs-score {
        display: inline-flex;
        align-items: center;
        padding: 4px 11px;
        border-radius: 999px;
        background: var(--yr-primary-soft);
        color: var(--yr-primary);
        border: 1px solid var(--yr-primary-border);
        font-weight: 800;
        font-size: 12px;
        white-space: nowrap;
    }

    .ymrs-severity {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 700;
        white-space: nowrap;
    }

    .ymrs-severity::before {
        content: "";
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    .ymrs-severity.normal { background: #dcfce7; color: #166534; }
    .ymrs-severity.mild { background: #fef3c7; color: #92400e; }
    .ymrs-severity.moderate { background: #ffedd5; color: #9a3412; }
    .ymrs-severity.severe { background: #fee2e2; color: #991b1b; }

    .ymrs-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
    }

    .ymrs-view,
    .ymrs-delete {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 6px 12px;
        border-radius: 7px;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.16s ease;
        line-height: 1.2;
    }

    .ymrs-view {
        background: var(--yr-primary);
        border: 1px solid var(--yr-primary);
        color: #fff;
    }

    .ymrs-view:hover {
        background: var(--yr-primary-dark);
        border-color: var(--yr-primary-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    .ymrs-delete {
        background: #fff;
        border: 1px solid #fecaca;
        color: #dc2626;
    }

    .ymrs-delete:hover {
        background: #dc2626;
        border-color: #dc2626;
        color: #fff;
    }

    .ymrs-empty {
        padding: 44px 20px;
        text-align: center;
        color: var(--yr-muted);
        font-size: 13.5px;
    }

    @media (max-width: 1024px) {
        .ymrs-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .ymrs-records { padding: 16px 12px 32px; }
        .ymrs-records-head {
            flex-direction: column;
            align-items: flex-start;
        }
        .ymrs-new { width: 100%; justify-content: center; }
        .ymrs-table-wrap { padding: 10px 12px 16px; }
    }

    @media (max-width: 480px) {
        .ymrs-kpi-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="ymrs-records">
    <!-- Header Banner -->
    <header class="ymrs-records-head">
        <div>
            <div class="ymrs-head-eyebrow">Clinical Rating Scale</div>
            <h1>YMRS Assessment Records</h1>
            <p>Saved Young Mania Rating Scale evaluations by patient and assessment date.</p>
        </div>
        <a class="ymrs-new" href="ymrs-assessments.php">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>New Assessment</span>
        </a>
    </header>

    <?php if ($action_success): ?>
        <div class="ymrs-alert success" role="status"><?php echo ymrs_records_escape($action_success); ?></div>
    <?php endif; ?>
    <?php if ($action_error): ?>
        <div class="ymrs-alert error" role="alert"><?php echo ymrs_records_escape($action_error); ?></div>
    <?php endif; ?>

    <!-- KPI Summary Row -->
    <?php if (!$load_error && !empty($records)): ?>
        <div class="ymrs-kpi-grid">
            <div class="ymrs-kpi-card">
                <span class="ymrs-kpi-label">Total Assessments</span>
                <span class="ymrs-kpi-value"><?php echo (int)$total_count; ?></span>
            </div>
            <div class="ymrs-kpi-card">
                <span class="ymrs-kpi-label">Normal / Remission (&lt;13)</span>
                <span class="ymrs-kpi-value is-teal"><?php echo (int)$normal_count; ?></span>
            </div>
            <div class="ymrs-kpi-card">
                <span class="ymrs-kpi-label">Moderate / Severe (≥20)</span>
                <span class="ymrs-kpi-value is-red"><?php echo (int)$elevated_count; ?></span>
            </div>
            <div class="ymrs-kpi-card">
                <span class="ymrs-kpi-label">Avg. YMRS Score</span>
                <span class="ymrs-kpi-value is-teal"><?php echo ymrs_records_escape($avg_score . ' / 60'); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Records Table Panel -->
    <section class="ymrs-panel">
        <div class="ymrs-panel-head">
            <h2>Assessment History</h2>
            <span class="ymrs-count-pill"><?php echo (int)$total_count; ?> Record<?php echo $total_count === 1 ? '' : 's'; ?></span>
        </div>

        <?php if ($load_error): ?>
            <div class="ymrs-empty text-danger" role="alert"><?php echo ymrs_records_escape($load_error); ?></div>
        <?php elseif (empty($records)): ?>
            <div class="ymrs-empty">No YMRS assessments have been saved yet.</div>
        <?php else: ?>
            <div class="table-responsive ymrs-table-wrap">
                <table class="ymrs-table custom-datatable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Patient</th>
                            <th>YMRS Total</th>
                            <th>Severity</th>
                            <th>Clinician</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r):
                            $sc = (int)$r['total_score'];
                            if ($sc >= 26) {
                                $sev_label = 'Severe Mania';
                                $sev_class = 'severe';
                            } elseif ($sc >= 20) {
                                $sev_label = 'Moderate Mania';
                                $sev_class = 'moderate';
                            } elseif ($sc >= 13) {
                                $sev_label = 'Minimal / Mild';
                                $sev_class = 'mild';
                            } else {
                                $sev_label = 'Normal / Remission';
                                $sev_class = 'normal';
                            }
                        ?>
                            <tr>
                                <td data-order="<?php echo ymrs_records_escape($r['assessment_date']); ?>" style="white-space:nowrap;">
                                    <?php echo ymrs_records_escape(date('d M Y', strtotime($r['assessment_date']))); ?>
                                </td>
                                <td class="ymrs-patient-name">
                                    <?php echo ymrs_records_escape(trim($r['patient_name']) ?: 'Unknown patient'); ?>
                                </td>
                                <td>
                                    <span class="ymrs-score"><?php echo $sc; ?> / 60</span>
                                </td>
                                <td>
                                    <span class="ymrs-severity <?php echo $sev_class; ?>">
                                        <?php echo ymrs_records_escape($sev_label); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo ymrs_records_escape(trim($r['clinician_name']) ?: 'Unknown clinician'); ?>
                                </td>
                                <td>
                                    <div class="ymrs-actions">
                                        <a class="ymrs-view" href="ymrs-assessment-score.php?id=<?php echo (int)$r['id']; ?>">
                                            <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i>
                                            <span>View Report</span>
                                        </a>
                                        <form method="post" class="m-0" onsubmit="return confirm('Delete this YMRS assessment record?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo ymrs_records_escape($_SESSION['ymrs_csrf_token']); ?>">
                                            <input type="hidden" name="assessment_id" value="<?php echo (int)$r['id']; ?>">
                                            <button type="submit" name="delete_assessment" class="ymrs-delete">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                                <span>Delete</span>
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
<?php require 'layout/footer.php'; ?>