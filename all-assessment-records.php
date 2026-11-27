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

$page_title = 'All Assessment Records - Vimhans Academic';

$allowed_scales = ['all', 'bdi', 'bai', 'bprs', 'panss', 'ymrs', 'ace_iii'];
$filter_scale = strtolower(trim((string) ($_GET['scale'] ?? 'all')));
if (!in_array($filter_scale, $allowed_scales, true)) {
    $filter_scale = 'all';
}

$filter_from = is_string($_GET['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '';
$filter_to = is_string($_GET['to'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : '';

$all_records = [];
$scale_counts = [
    'all' => 0,
    'bdi' => 0,
    'bai' => 0,
    'bprs' => 0,
    'panss' => 0,
    'ymrs' => 0,
    'ace_iii' => 0,
];

$scale_meta = [
    'bdi'     => ['code' => 'BDI',     'name' => 'Beck Depression Inventory',  'color' => '#4f46e5', 'bg' => '#eef2ff'],
    'bai'     => ['code' => 'BAI',     'name' => 'Beck Anxiety Inventory',     'color' => '#0f766e', 'bg' => '#f0fdfa'],
    'bprs'    => ['code' => 'BPRS',    'name' => 'Brief Psychiatric Rating',   'color' => '#0284c7', 'bg' => '#e0f2fe'],
    'panss'   => ['code' => 'PANSS',   'name' => 'Positive & Negative Synd.',  'color' => '#7c3aed', 'bg' => '#f5f3ff'],
    'ymrs'    => ['code' => 'YMRS',    'name' => 'Young Mania Rating Scale',   'color' => '#d97706', 'bg' => '#fffbeb'],
    'ace_iii' => ['code' => 'ACE-III', 'name' => "Addenbrooke's Cognitive",    'color' => '#059669', 'bg' => '#ecfdf5'],
];

if (isset($conn)) {
    $scope = (int) PATIENT_SCOPE_ID;

    // 1. BDI (1) & BAI (2)
    $beck_sql = "SELECT ar.id, aa.assessment_id, ar.patient_id, ar.assessment_date, ar.total_score, ar.status_text,
                        TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(NULLIF(p.last_name, '.'), ''))) AS patient_name,
                        p.phone, p.email
                 FROM assessment_responses ar
                 INNER JOIN assigned_assessments aa ON aa.id = ar.assignment_id AND aa.assessment_id IN (1, 2)
                 INNER JOIN users p ON p.id = ar.patient_id AND p.role_id = 2 AND p.patient_id = {$scope}
                 ORDER BY ar.id DESC";
    if ($res = $conn->query($beck_sql)) {
        while ($row = $res->fetch_assoc()) {
            $is_bai = ((int) $row['assessment_id'] === 2);
            $key = $is_bai ? 'bai' : 'bdi';
            $score = (int) $row['total_score'];
            $all_records[] = [
                'scale' => $key,
                'id' => (int) $row['id'],
                'patient_id' => (int) $row['patient_id'],
                'patient_name' => trim((string) $row['patient_name']) ?: 'Unnamed patient',
                'contact' => $row['phone'] ?: ($row['email'] ?: 'No contact'),
                'clinician_name' => 'Self / Clinician',
                'date' => (string) ($row['assessment_date'] ?: ''),
                'score_display' => $score . ' / 63',
                'severity' => (string) ($row['status_text'] ?: 'Completed'),
                'report_url' => ($is_bai ? 'bai-assessment-score.php?id=' : 'bdi-assessment-score.php?id=') . (int) $row['id'],
            ];
            $scale_counts[$key]++;
        }
    }

    // 2. BPRS
    $chk = $conn->query("SHOW TABLES LIKE 'bprs_assessments'");
    if ($chk && $chk->num_rows > 0) {
        $bprs_sql = "SELECT b.id, b.patient_id, b.assessment_date, b.total_score,
                            TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(NULLIF(p.last_name, '.'), ''))) AS patient_name,
                            p.phone, p.email,
                            TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
                     FROM bprs_assessments b
                     INNER JOIN users p ON p.id = b.patient_id AND p.role_id = 2 AND p.patient_id = {$scope}
                     LEFT JOIN users c ON c.id = b.clinician_id
                     ORDER BY b.id DESC";
        if ($res = $conn->query($bprs_sql)) {
            while ($row = $res->fetch_assoc()) {
                $score = (int) $row['total_score'];
                $all_records[] = [
                    'scale' => 'bprs',
                    'id' => (int) $row['id'],
                    'patient_id' => (int) $row['patient_id'],
                    'patient_name' => trim((string) $row['patient_name']) ?: 'Unnamed patient',
                    'contact' => $row['phone'] ?: ($row['email'] ?: 'No contact'),
                    'clinician_name' => trim((string) $row['clinician_name']) ?: 'Clinician',
                    'date' => (string) ($row['assessment_date'] ?: ''),
                    'score_display' => $score . ' / 126',
                    'severity' => 'Completed',
                    'report_url' => 'bprs-assessment-score.php?id=' . (int) $row['id'],
                ];
                $scale_counts['bprs']++;
            }
        }
    }

    // 3. PANSS
    $chk = $conn->query("SHOW TABLES LIKE 'panss_assessments'");
    if ($chk && $chk->num_rows > 0) {
        $panss_sql = "SELECT a.id, a.patient_id, a.assessment_date, a.positive_score, a.negative_score, a.general_score, a.composite_score,
                             TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(NULLIF(p.last_name, '.'), ''))) AS patient_name,
                             p.phone, p.email,
                             TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
                      FROM panss_assessments a
                      INNER JOIN users p ON p.id = a.patient_id AND p.role_id = 2 AND p.patient_id = {$scope}
                      LEFT JOIN users c ON c.id = a.clinician_id
                      ORDER BY a.id DESC";
        if ($res = $conn->query($panss_sql)) {
            while ($row = $res->fetch_assoc()) {
                $total = (int) $row['positive_score'] + (int) $row['negative_score'] + (int) $row['general_score'];
                $comp = (int) $row['composite_score'];
                $all_records[] = [
                    'scale' => 'panss',
                    'id' => (int) $row['id'],
                    'patient_id' => (int) $row['patient_id'],
                    'patient_name' => trim((string) $row['patient_name']) ?: 'Unnamed patient',
                    'contact' => $row['phone'] ?: ($row['email'] ?: 'No contact'),
                    'clinician_name' => trim((string) $row['clinician_name']) ?: 'Clinician',
                    'date' => (string) ($row['assessment_date'] ?: ''),
                    'score_display' => $total . ' / 210',
                    'severity' => 'Composite: ' . ($comp > 0 ? '+' : '') . $comp,
                    'report_url' => 'panss-assessment-score.php?id=' . (int) $row['id'],
                ];
                $scale_counts['panss']++;
            }
        }
    }

    // 4. YMRS
    $chk = $conn->query("SHOW TABLES LIKE 'ymrs_assessments'");
    if ($chk && $chk->num_rows > 0) {
        $ymrs_sql = "SELECT y.id, y.patient_id, y.assessment_date, y.total_score,
                            TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(NULLIF(p.last_name, '.'), ''))) AS patient_name,
                            p.phone, p.email,
                            TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
                     FROM ymrs_assessments y
                     INNER JOIN users p ON p.id = y.patient_id AND p.role_id = 2 AND p.patient_id = {$scope}
                     LEFT JOIN users c ON c.id = y.clinician_id
                     ORDER BY y.id DESC";
        if ($res = $conn->query($ymrs_sql)) {
            while ($row = $res->fetch_assoc()) {
                $score = (int) $row['total_score'];
                $sev = $score <= 12 ? 'Normal / Remission' : ($score <= 19 ? 'Minimal / Mild' : ($score <= 25 ? 'Moderate Mania' : 'Severe Mania'));
                $all_records[] = [
                    'scale' => 'ymrs',
                    'id' => (int) $row['id'],
                    'patient_id' => (int) $row['patient_id'],
                    'patient_name' => trim((string) $row['patient_name']) ?: 'Unnamed patient',
                    'contact' => $row['phone'] ?: ($row['email'] ?: 'No contact'),
                    'clinician_name' => trim((string) $row['clinician_name']) ?: 'Clinician',
                    'date' => (string) ($row['assessment_date'] ?: ''),
                    'score_display' => $score . ' / 60',
                    'severity' => $sev,
                    'report_url' => 'ymrs-assessment-score.php?id=' . (int) $row['id'],
                ];
                $scale_counts['ymrs']++;
            }
        }
    }

    // 5. ACE-III
    $chk = $conn->query("SHOW TABLES LIKE 'ace_iii_assessments'");
    if ($chk && $chk->num_rows > 0) {
        $ace_sql = "SELECT a.id, a.patient_id, a.assessment_date, a.total_score, a.status,
                           TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(NULLIF(p.last_name, '.'), ''))) AS patient_name,
                           p.phone, p.email,
                           TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(NULLIF(c.last_name, '.'), ''))) AS clinician_name
                    FROM ace_iii_assessments a
                    INNER JOIN users p ON p.id = a.patient_id AND p.role_id = 2 AND p.patient_id = {$scope}
                    LEFT JOIN users c ON c.id = a.clinician_id
                    ORDER BY a.id DESC";
        if ($res = $conn->query($ace_sql)) {
            while ($row = $res->fetch_assoc()) {
                $score = (int) $row['total_score'];
                $is_done = strtolower((string) ($row['status'] ?? '')) === 'success';
                $sev = !$is_done ? 'Pending Scoring' : ($score >= 88 ? 'Normal Cognitive' : ($score >= 83 ? 'Borderline / MCI' : 'Abnormal Cognitive'));
                $all_records[] = [
                    'scale' => 'ace_iii',
                    'id' => (int) $row['id'],
                    'patient_id' => (int) $row['patient_id'],
                    'patient_name' => trim((string) $row['patient_name']) ?: 'Unnamed patient',
                    'contact' => $row['phone'] ?: ($row['email'] ?: 'No contact'),
                    'clinician_name' => trim((string) $row['clinician_name']) ?: 'Waiting for scorer',
                    'date' => (string) ($row['assessment_date'] ?: ''),
                    'score_display' => $score . ' / 100',
                    'severity' => $sev,
                    'report_url' => 'ace-iii-assessment-score.php?id=' . (int) $row['id'],
                ];
                $scale_counts['ace_iii']++;
            }
        }
    }
}

$scale_counts['all'] = count($all_records);

// Sort newest date first, then highest ID
usort($all_records, function ($a, $b) {
    $da = strtotime($a['date'] ?: '1970-01-01');
    $db = strtotime($b['date'] ?: '1970-01-01');
    if ($da === $db) {
        return $b['id'] <=> $a['id'];
    }
    return $db <=> $da;
});

// Apply active filters
$filtered_records = array_values(array_filter($all_records, function ($r) use ($filter_scale, $filter_from, $filter_to) {
    if ($filter_scale !== 'all' && $r['scale'] !== $filter_scale) {
        return false;
    }
    $rec_date = $r['date'] ? date('Y-m-d', strtotime($r['date'])) : '';
    if ($filter_from !== '' && ($rec_date === '' || $rec_date < $filter_from)) {
        return false;
    }
    if ($filter_to !== '' && ($rec_date === '' || $rec_date > $filter_to)) {
        return false;
    }
    return true;
}));

$filtered_count = count($filtered_records);
$unique_patients = count(array_unique(array_column($filtered_records, 'patient_id')));
$active_scales_in_view = count(array_unique(array_column($filtered_records, 'scale')));

function all_rec_e($val)
{
    return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
}

function all_rec_initials($name)
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $init = '';
    foreach ($parts as $p) {
        if ($p !== '') $init .= strtoupper(substr($p, 0, 1));
        if (strlen($init) >= 2) break;
    }
    return $init ?: 'PT';
}

function all_rec_filter_url($scale, $from, $to)
{
    $params = [];
    if ($scale !== 'all') $params['scale'] = $scale;
    if ($from !== '') $params['from'] = $from;
    if ($to !== '') $params['to'] = $to;
    return basename($_SERVER['PHP_SELF']) . ($params ? '?' . http_build_query($params) : '');
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
        --ar-shadow: 0 6px 24px -6px rgba(15, 23, 42, 0.08), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
    }

    .all-rec-wrap {
        max-width: 1380px;
        margin: 0 auto;
        padding: 24px 18px 52px;
        color: var(--ar-text);
        font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Executive Header */
    .ar-header {
        background: #fff;
        border-top: 4px solid var(--ar-primary);
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 18px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
        flex-wrap: wrap;
        box-shadow: var(--ar-shadow);
    }

    .ar-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 6px;
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

    .ar-header h1 {
        margin: 0;
        font-size: 23px;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .ar-header p {
        margin: 4px 0 0;
        color: var(--ar-muted);
        font-size: 13px;
    }

    .ar-header-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .ar-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 16px;
        border-radius: 9px;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.16s ease;
    }

    .ar-btn-primary {
        background: var(--ar-primary);
        color: #fff;
        box-shadow: 0 3px 10px rgba(15, 118, 110, 0.24);
    }

    .ar-btn-primary:hover {
        background: var(--ar-primary-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    .ar-btn-light {
        background: #f1f5f9;
        color: #334155;
    }

    .ar-btn-light:hover {
        background: #e2e8f0;
        color: var(--ar-text);
    }

    /* Filter Bar Card */
    .ar-filter-card {
        background: #fff;
        border-radius: 14px;
        padding: 16px 20px;
        margin-bottom: 18px;
        box-shadow: var(--ar-shadow);
    }

    .ar-pills-row {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        padding-bottom: 14px;
        border-bottom: 1px solid var(--ar-border-soft);
    }

    .ar-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 13px;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid var(--ar-border);
        color: #475569;
        font-size: 12.5px;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.15s ease;
    }

    .ar-pill:hover {
        background: #f1f5f9;
        color: var(--ar-text);
        border-color: #cbd5e1;
    }

    .ar-pill.is-active {
        background: var(--ar-primary);
        border-color: var(--ar-primary);
        color: #fff;
        box-shadow: 0 3px 10px rgba(15, 118, 110, 0.22);
    }

    .ar-pill-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 22px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.08);
        font-size: 10.5px;
        font-weight: 800;
    }

    .ar-pill.is-active .ar-pill-count {
        background: rgba(255, 255, 255, 0.22);
        color: #fff;
    }

    .ar-date-form {
        display: flex;
        align-items: flex-end;
        gap: 12px;
        flex-wrap: wrap;
        padding-top: 14px;
    }

    .ar-field {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .ar-field label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--ar-muted);
    }

    .ar-field select,
    .ar-field input {
        height: 36px;
        padding: 0 11px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 13px;
        color: var(--ar-text);
        background: #fff;
    }

    .ar-field select:focus,
    .ar-field input:focus {
        outline: none;
        border-color: var(--ar-primary);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14);
    }

    .ar-filter-btn {
        height: 36px;
        padding: 0 16px;
        border: 0;
        border-radius: 8px;
        background: var(--ar-primary);
        color: #fff;
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
    }

    .ar-filter-btn:hover {
        background: var(--ar-primary-dark);
    }

    .ar-reset-link {
        height: 36px;
        display: inline-flex;
        align-items: center;
        padding: 0 12px;
        border-radius: 8px;
        background: #f1f5f9;
        color: #475569;
        font-size: 12.5px;
        font-weight: 700;
        text-decoration: none;
    }

    /* 3-Column KPI Strip */
    .ar-kpi-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }

    .ar-kpi {
        background: #fff;
        border-top: 3px solid var(--kpi-c, var(--ar-primary));
        border-radius: 12px;
        padding: 16px 20px;
        box-shadow: var(--ar-shadow);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ar-kpi span {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ar-muted);
    }

    .ar-kpi strong {
        display: block;
        margin-top: 5px;
        font-size: 26px;
        font-weight: 800;
        line-height: 1;
        color: var(--ar-text);
    }

    .ar-kpi-ic {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        font-size: 18px;
        background: var(--kpi-bg, var(--ar-primary-soft));
        color: var(--kpi-c, var(--ar-primary));
    }

    /* Table Card */
    .ar-table-card {
        background: #fff;
        border-radius: 14px;
        box-shadow: var(--ar-shadow);
        overflow: hidden;
    }

    .ar-table-head {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--ar-border);
        border-left: 4px solid var(--ar-primary);
        padding: 14px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .ar-table-head h2 {
        margin: 0;
        font-size: 13.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .ar-count-badge {
        padding: 3px 11px;
        border-radius: 999px;
        background: var(--ar-primary-soft);
        color: var(--ar-primary);
        border: 1px solid var(--ar-primary-border);
        font-size: 11.5px;
        font-weight: 800;
    }

    .ar-table {
        width: 100%;
        border-collapse: collapse;
    }

    .ar-table th {
        background: #f8fafc;
        color: #475569;
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 12px 16px;
        border-bottom: 1px solid var(--ar-border);
        text-align: left;
        white-space: nowrap;
    }

    .ar-table td {
        padding: 13px 16px;
        border-bottom: 1px solid var(--ar-border-soft);
        font-size: 13px;
        vertical-align: middle;
    }

    .ar-table tbody tr:hover {
        background: #fcfefd;
    }

    .ar-pt-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .ar-avatar {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: var(--ar-primary-soft);
        color: var(--ar-primary);
        font-size: 11.5px;
        font-weight: 800;
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }

    .ar-pt-name {
        display: block;
        font-weight: 700;
        color: var(--ar-text);
    }

    .ar-pt-sub {
        display: block;
        font-size: 11.5px;
        color: var(--ar-muted);
    }

    .ar-scale-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 7px;
        font-size: 11.5px;
        font-weight: 800;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }

    .ar-score-main {
        display: block;
        font-weight: 800;
        color: var(--ar-text);
    }

    .ar-score-sev {
        display: block;
        font-size: 11.5px;
        font-weight: 600;
        color: var(--ar-primary);
        margin-top: 2px;
    }

    .ar-report-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 13px;
        border-radius: 8px;
        background: var(--ar-primary-soft);
        color: var(--ar-primary);
        border: 1px solid var(--ar-primary-border);
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.15s ease;
    }

    .ar-report-btn:hover {
        background: var(--ar-primary);
        color: #fff;
        border-color: var(--ar-primary);
    }

    .ar-empty {
        padding: 52px 20px;
        text-align: center;
        color: var(--ar-muted);
        font-size: 14px;
    }

    /* DataTables Overrides */
    #allRecordsTable_wrapper {
        padding: 0 20px 20px;
    }

    #allRecordsTable_wrapper .dataTables_length,
    #allRecordsTable_wrapper .dataTables_filter {
        padding-top: 14px;
        padding-bottom: 8px;
        font-size: 13px;
    }

    #allRecordsTable_wrapper .dataTables_filter {
        text-align: right;
    }

    #allRecordsTable_wrapper .dataTables_filter input {
        width: 240px;
        margin-left: 8px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        padding: 5px 10px;
    }

    #allRecordsTable_wrapper .page-item.active .page-link {
        background: var(--ar-primary);
        border-color: var(--ar-primary);
        color: #fff;
    }

    @media (max-width: 900px) {
        .ar-kpi-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 640px) {
        .all-rec-wrap { padding: 16px 12px 32px; }
        #allRecordsTable_wrapper { padding: 0 12px 16px; }
        #allRecordsTable_wrapper .dataTables_filter { text-align: left; }
        #allRecordsTable_wrapper .dataTables_filter input { width: 100%; margin: 6px 0 0; }
    }
</style>

<div class="all-rec-wrap">
    <!-- Header -->
    <header class="ar-header">
        <div>
            <div class="ar-eyebrow"><i class="bi bi-collection" aria-hidden="true"></i> Unified Clinical Registry</div>
            <h1>All Assessment Records</h1>
            <p>View and filter completed patient reports across BDI, BAI, BPRS, PANSS, YMRS, and ACE-III in one place.</p>
        </div>
        <div class="ar-header-actions">
            <a class="ar-btn ar-btn-light" href="dashboard.php"><i class="bi bi-speedometer2" aria-hidden="true"></i> Dashboard</a>
            <a class="ar-btn ar-btn-primary" href="assign-assessments.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Assign Assessment</a>
        </div>
    </header>

    <!-- Filter Bar (Quick Scale Pills + Date Range) -->
    <section class="ar-filter-card">
        <div class="ar-pills-row" aria-label="Filter by assessment scale">
            <a class="ar-pill <?php echo $filter_scale === 'all' ? 'is-active' : ''; ?>" href="<?php echo all_rec_e(all_rec_filter_url('all', $filter_from, $filter_to)); ?>">
                All Scales <span class="ar-pill-count"><?php echo $scale_counts['all']; ?></span>
            </a>
            <?php foreach ($scale_meta as $s_key => $s_info): ?>
                <a class="ar-pill <?php echo $filter_scale === $s_key ? 'is-active' : ''; ?>" href="<?php echo all_rec_e(all_rec_filter_url($s_key, $filter_from, $filter_to)); ?>">
                    <?php echo all_rec_e($s_info['code']); ?>
                    <span class="ar-pill-count"><?php echo $scale_counts[$s_key]; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" class="ar-date-form">
            <div class="ar-field">
                <label for="scaleSelect">Assessment Type</label>
                <select name="scale" id="scaleSelect">
                    <option value="all" <?php echo $filter_scale === 'all' ? 'selected' : ''; ?>>All Assessments</option>
                    <?php foreach ($scale_meta as $s_key => $s_info): ?>
                        <option value="<?php echo all_rec_e($s_key); ?>" <?php echo $filter_scale === $s_key ? 'selected' : ''; ?>>
                            <?php echo all_rec_e($s_info['code'] . ' — ' . $s_info['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ar-field">
                <label for="fromDate">From Date</label>
                <input type="date" name="from" id="fromDate" value="<?php echo all_rec_e($filter_from); ?>">
            </div>
            <div class="ar-field">
                <label for="toDate">To Date</label>
                <input type="date" name="to" id="toDate" value="<?php echo all_rec_e($filter_to); ?>">
            </div>
            <button type="submit" class="ar-filter-btn"><i class="bi bi-funnel" aria-hidden="true"></i> Apply Filter</button>
            <?php if ($filter_scale !== 'all' || $filter_from !== '' || $filter_to !== ''): ?>
                <a class="ar-reset-link" href="<?php echo all_rec_e(basename($_SERVER['PHP_SELF'])); ?>">Reset</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- KPI Strip -->
    <div class="ar-kpi-grid">
        <div class="ar-kpi" style="--kpi-c:#0f766e; --kpi-bg:#f0fdfa;">
            <div>
                <span>Matching Records</span>
                <strong><?php echo $filtered_count; ?></strong>
            </div>
            <span class="ar-kpi-ic"><i class="bi bi-file-earmark-medical"></i></span>
        </div>
        <div class="ar-kpi" style="--kpi-c:#4f46e5; --kpi-bg:#eef2ff;">
            <div>
                <span>Unique Patients</span>
                <strong><?php echo $unique_patients; ?></strong>
            </div>
            <span class="ar-kpi-ic"><i class="bi bi-people"></i></span>
        </div>
        <div class="ar-kpi" style="--kpi-c:#059669; --kpi-bg:#ecfdf5;">
            <div>
                <span>Scales Included</span>
                <strong><?php echo $active_scales_in_view; ?> / 6</strong>
            </div>
            <span class="ar-kpi-ic"><i class="bi bi-layers"></i></span>
        </div>
    </div>

    <!-- Unified Records Table -->
    <section class="ar-table-card">
        <div class="ar-table-head">
            <h2>
                <?php echo $filter_scale === 'all' ? 'All Completed Records' : all_rec_e($scale_meta[$filter_scale]['code'] . ' — ' . $scale_meta[$filter_scale]['name']); ?>
            </h2>
            <span class="ar-count-badge"><?php echo $filtered_count; ?> Records</span>
        </div>

        <?php if (empty($filtered_records)): ?>
            <div class="ar-empty">No assessment records match the selected filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 ar-table custom-datatable" id="allRecordsTable">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Assessment</th>
                            <th>Score &amp; Interpretation</th>
                            <th>Clinician</th>
                            <th>Assessment Date</th>
                            <th class="no-sort" style="text-align:right;">Report</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filtered_records as $rec):
                            $meta = $scale_meta[$rec['scale']];
                        ?>
                            <tr>
                                <td>
                                    <div class="ar-pt-cell">
                                        <span class="ar-avatar"><?php echo all_rec_e(all_rec_initials($rec['patient_name'])); ?></span>
                                        <div>
                                            <span class="ar-pt-name"><?php echo all_rec_e($rec['patient_name']); ?></span>
                                            <span class="ar-pt-sub"><?php echo all_rec_e($rec['contact']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="ar-scale-badge" style="background: <?php echo all_rec_e($meta['bg']); ?>; color: <?php echo all_rec_e($meta['color']); ?>;">
                                        <?php echo all_rec_e($meta['code']); ?>
                                    </span>
                                    <div style="font-size:11.5px;color:#64748b;margin-top:3px;"><?php echo all_rec_e($meta['name']); ?></div>
                                </td>
                                <td>
                                    <span class="ar-score-main"><?php echo all_rec_e($rec['score_display']); ?></span>
                                    <span class="ar-score-sev"><?php echo all_rec_e($rec['severity']); ?></span>
                                </td>
                                <td><?php echo all_rec_e($rec['clinician_name']); ?></td>
                                <td data-order="<?php echo all_rec_e($rec['date']); ?>">
                                    <?php echo $rec['date'] ? date('d M Y', strtotime($rec['date'])) : '—'; ?>
                                </td>
                                <td style="text-align:right;">
                                    <a class="ar-report-btn" href="<?php echo all_rec_e($rec['report_url']); ?>">
                                        <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> View Report
                                    </a>
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