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
$module_stats = [
    'bprs' => ['total' => null, 'recent' => 0, 'patients' => null],
    'panss' => ['total' => null, 'recent' => 0, 'patients' => null],
];
$beck_stats = [
    'bai' => ['assigned' => 0, 'completed' => 0, 'pending' => 0, 'records' => 0, 'recent' => 0],
    'bdi' => ['assigned' => 0, 'completed' => 0, 'pending' => 0, 'records' => 0, 'recent' => 0],
];
$workspace_stats = [
    'assessment_catalog' => null,
    'questions' => null,
    'responses' => null,
];
$dashboard_errors = [];
$beck_stats_loaded = false;

if (isset($conn)) {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role_id = 2 AND patient_id = " . PATIENT_SCOPE_ID . ") AS patients,
        (SELECT COUNT(*) FROM assigned_assessments aa INNER JOIN users u ON u.id = aa.patient_id WHERE u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . ") AS assigned,
        (SELECT COUNT(*) FROM assigned_assessments aa INNER JOIN users u ON u.id = aa.patient_id WHERE u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . " AND aa.status = 'completed') AS completed,
        (SELECT COUNT(*) FROM assigned_assessments aa INNER JOIN users u ON u.id = aa.patient_id WHERE u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID . " AND (aa.status IS NULL OR aa.status <> 'completed')) AS pending";
    if ($stats_result = $conn->query($stats_query)) {
        $stats = $stats_result->fetch_assoc() ?: $stats;
    } else {
        error_log('Dashboard summary query failed: ' . $conn->error);
        $dashboard_errors[] = 'The assignment summary could not be loaded.';
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
    } else {
        error_log('Dashboard recent assignments query failed: ' . $conn->error);
        $dashboard_errors[] = 'Recent assignments could not be loaded.';
    }

    foreach (['bprs' => 'bprs_assessments', 'panss' => 'panss_assessments'] as $module => $table) {
        $table_result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if (!$table_result) {
            error_log('Dashboard ' . strtoupper($module) . ' table check failed: ' . $conn->error);
            $dashboard_errors[] = strtoupper($module) . ' summary could not be loaded.';
            continue;
        }
        if ($table_result->num_rows === 0) {
            continue;
        }

        $module_query = "SELECT COUNT(*) AS total,
                COALESCE(SUM(created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)), 0) AS recent,
                COUNT(DISTINCT a.patient_id) AS patients
            FROM " . $table . " a
            INNER JOIN users u ON u.id = a.patient_id
                AND u.role_id = 2
                AND u.patient_id = " . PATIENT_SCOPE_ID;
        $module_result = $conn->query($module_query);
        if ($module_result) {
            $module_stats[$module] = $module_result->fetch_assoc() ?: $module_stats[$module];
        } else {
            error_log('Dashboard ' . strtoupper($module) . ' summary query failed: ' . $conn->error);
            $dashboard_errors[] = strtoupper($module) . ' summary could not be loaded.';
        }
    }

    $workspace_query = "SELECT
        (SELECT COUNT(*) FROM assessments) AS assessment_catalog,
        (SELECT COUNT(*) FROM assessment_questions) AS questions,
        (SELECT COUNT(*) FROM assessment_responses ar
            INNER JOIN assigned_assessments aa ON aa.id = ar.assignment_id
            INNER JOIN users u ON u.id = aa.patient_id
                AND u.role_id = 2
                AND u.patient_id = " . PATIENT_SCOPE_ID . ") AS responses";
    if ($workspace_result = $conn->query($workspace_query)) {
        $workspace_stats = $workspace_result->fetch_assoc() ?: $workspace_stats;
    } else {
        error_log('Dashboard workspace overview query failed: ' . $conn->error);
        $dashboard_errors[] = 'Some workspace module summaries could not be loaded.';
    }

    $beck_query = "SELECT aa.assessment_id,
            COUNT(DISTINCT aa.id) AS assigned,
            COUNT(DISTINCT CASE WHEN aa.status = 'completed' OR ar.id IS NOT NULL THEN aa.id END) AS completed,
            COUNT(DISTINCT CASE WHEN (aa.status IS NULL OR aa.status <> 'completed') AND ar.id IS NULL THEN aa.id END) AS pending,
            COUNT(ar.id) AS records,
            COALESCE(SUM(ar.assessment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)), 0) AS recent
        FROM assigned_assessments aa
        INNER JOIN users u ON u.id = aa.patient_id
            AND u.role_id = 2
            AND u.patient_id = " . PATIENT_SCOPE_ID . "
        LEFT JOIN assessment_responses ar ON ar.assignment_id = aa.id
        WHERE aa.assessment_id IN (1, 2)
        GROUP BY aa.assessment_id";
    if ($beck_result = $conn->query($beck_query)) {
        $beck_stats_loaded = true;
        while ($row = $beck_result->fetch_assoc()) {
            $scale = (int) $row['assessment_id'] === 2 ? 'bai' : 'bdi';
            $beck_stats[$scale] = [
                'assigned' => (int) $row['assigned'],
                'completed' => (int) $row['completed'],
                'pending' => (int) $row['pending'],
                'records' => (int) $row['records'],
                'recent' => (int) $row['recent'],
            ];
        }
    } else {
        error_log('Dashboard BAI/BDI summary query failed: ' . $conn->error);
        $dashboard_errors[] = 'BAI and BDI summaries could not be loaded.';
    }
}

$completion_rate = (int) $stats['assigned'] > 0
    ? round(((int) $stats['completed'] / (int) $stats['assigned']) * 100)
    : 0;
$assignment_chart_data = [
    (int) $stats['completed'],
    (int) $stats['pending'],
];
$assessment_chart_data = [
    'labels' => ['BAI', 'BDI', 'BPRS', 'PANSS'],
    'total' => [
        $beck_stats_loaded ? $beck_stats['bai']['records'] : null,
        $beck_stats_loaded ? $beck_stats['bdi']['records'] : null,
        $module_stats['bprs']['total'] === null ? null : (int) $module_stats['bprs']['total'],
        $module_stats['panss']['total'] === null ? null : (int) $module_stats['panss']['total'],
    ],
    'recent' => [
        $beck_stats_loaded ? $beck_stats['bai']['recent'] : null,
        $beck_stats_loaded ? $beck_stats['bdi']['recent'] : null,
        $module_stats['bprs']['total'] === null ? null : (int) $module_stats['bprs']['recent'],
        $module_stats['panss']['total'] === null ? null : (int) $module_stats['panss']['recent'],
    ],
];
$scale_tabs = [
    [
        'id' => 'bai',
        'title' => 'Beck Anxiety Inventory',
        'icon' => 'bi-heart-pulse',
        'description' => 'Review BAI assessment assignments and submitted responses. This dashboard shows workflow activity, not a clinical interpretation.',
        'badge' => $beck_stats_loaded ? $beck_stats['bai']['records'] . ' responses' : 'Data unavailable',
        'metrics' => [
            ['Assigned', $beck_stats_loaded ? $beck_stats['bai']['assigned'] : null, 'bi-send'],
            ['Completed', $beck_stats_loaded ? $beck_stats['bai']['completed'] : null, 'bi-check2-circle'],
            ['Awaiting', $beck_stats_loaded ? $beck_stats['bai']['pending'] : null, 'bi-hourglass-split'],
            ['Submitted responses', $beck_stats_loaded ? $beck_stats['bai']['records'] : null, 'bi-file-earmark-check'],
        ],
        'summary_title' => 'About this section',
        'summary' => $beck_stats_loaded
            ? $beck_stats['bai']['recent'] . ' responses submitted in the last 7 days. Use assignment records to follow response status and patient assessments to open the workflow.'
            : 'BAI response activity could not be loaded. Please try again or review the assignment records.',
        'actions' => [
            ['Patient assessments', 'public-assessment-form.php', 'bi-person-vcard'],
            ['Assignment records', 'assigned-assessments.php', 'bi-table'],
        ],
        'three_metrics' => false,
    ],
    [
        'id' => 'bdi',
        'title' => 'Beck Depression Inventory',
        'icon' => 'bi-clipboard2-heart',
        'description' => 'Review BDI assessment assignments and submitted responses. This dashboard shows workflow activity, not a clinical interpretation.',
        'badge' => $beck_stats_loaded ? $beck_stats['bdi']['records'] . ' responses' : 'Data unavailable',
        'metrics' => [
            ['Assigned', $beck_stats_loaded ? $beck_stats['bdi']['assigned'] : null, 'bi-send'],
            ['Completed', $beck_stats_loaded ? $beck_stats['bdi']['completed'] : null, 'bi-check2-circle'],
            ['Awaiting', $beck_stats_loaded ? $beck_stats['bdi']['pending'] : null, 'bi-hourglass-split'],
            ['Submitted responses', $beck_stats_loaded ? $beck_stats['bdi']['records'] : null, 'bi-file-earmark-check'],
        ],
        'summary_title' => 'About this section',
        'summary' => $beck_stats_loaded
            ? $beck_stats['bdi']['recent'] . ' responses submitted in the last 7 days. Use assignment records to follow response status and patient assessments to open the workflow.'
            : 'BDI response activity could not be loaded. Please try again or review the assignment records.',
        'actions' => [
            ['Patient assessments', 'public-assessment-form.php', 'bi-person-vcard'],
            ['Assignment records', 'assigned-assessments.php', 'bi-table'],
        ],
        'three_metrics' => false,
    ],
    [
        'id' => 'bprs',
        'title' => 'Brief Psychiatric Rating Scale',
        'icon' => 'bi-clipboard2-pulse',
        'description' => 'A workspace for clinician-entered BPRS ratings, saved scale scores, and record review.',
        'badge' => $module_stats['bprs']['total'] === null ? 'Data unavailable' : (int) $module_stats['bprs']['total'] . ' saved ratings',
        'metrics' => [
            ['Saved ratings', $module_stats['bprs']['total'] === null ? null : (int) $module_stats['bprs']['total'], 'bi-file-earmark-check'],
            ['Added in 7 days', $module_stats['bprs']['total'] === null ? null : (int) $module_stats['bprs']['recent'], 'bi-calendar2-week'],
            ['Patients rated', $module_stats['bprs']['patients'] === null ? null : (int) $module_stats['bprs']['patients'], 'bi-people'],
        ],
        'summary_title' => 'About this section',
        'summary' => $module_stats['bprs']['total'] === null
            ? 'BPRS records are not available right now. The assessment table may not have been created yet.'
            : 'Review saved BPRS totals and item-level scores. Clinical interpretation should be made by a qualified professional using the full assessment context.',
        'actions' => [
            ['New BPRS rating', 'bprs-assessments.php', 'bi-plus-circle'],
            ['BPRS records', 'bprs-assessment-records.php', 'bi-table'],
        ],
        'three_metrics' => true,
    ],
    [
        'id' => 'panss',
        'title' => 'Positive and Negative Syndrome Scale',
        'icon' => 'bi-activity',
        'description' => 'A workspace for clinician-entered PANSS ratings, saved scale scores, and record review.',
        'badge' => $module_stats['panss']['total'] === null ? 'Data unavailable' : (int) $module_stats['panss']['total'] . ' saved ratings',
        'metrics' => [
            ['Saved ratings', $module_stats['panss']['total'] === null ? null : (int) $module_stats['panss']['total'], 'bi-file-earmark-check'],
            ['Added in 7 days', $module_stats['panss']['total'] === null ? null : (int) $module_stats['panss']['recent'], 'bi-calendar2-week'],
            ['Patients rated', $module_stats['panss']['patients'] === null ? null : (int) $module_stats['panss']['patients'], 'bi-people'],
        ],
        'summary_title' => 'About this section',
        'summary' => $module_stats['panss']['total'] === null
            ? 'PANSS records are not available right now. The assessment table may not have been created yet.'
            : 'Review Positive, Negative, General Psychopathology, and composite scores in saved records. Clinical interpretation should use the full assessment context.',
        'actions' => [
            ['New PANSS rating', 'panss-assessments.php', 'bi-plus-circle'],
            ['PANSS records', 'panss-assessment-records.php', 'bi-table'],
        ],
        'three_metrics' => true,
    ],
];

require 'layout/base.php';
?>

<style>
    .dashboard-wrap { --dash-ink: #172b4d; --dash-muted: #68778d; --dash-teal: #087e8b; max-width: 1600px; margin: 0 auto; padding: 28px clamp(18px, 2.4vw, 36px) 48px; color: var(--dash-ink); background: #f4f7fb; font-family: Inter, "Segoe UI", sans-serif; }
    .dashboard-hero { position: relative; isolation: isolate; display: flex; justify-content: space-between; align-items: center; gap: 22px; overflow: hidden; min-height: 194px; margin-bottom: 23px; padding: 30px 36px; border: 1px solid rgba(255,255,255,.12); border-radius: 20px; background: linear-gradient(112deg, #12355b 0%, #145c70 58%, #087e83 100%); box-shadow: 0 14px 34px rgba(20,62,91,.16); }
    .dashboard-hero::before, .dashboard-hero::after { position: absolute; z-index: -1; display: block; border: 1px solid rgba(255,255,255,.11); border-radius: 50%; content: ""; pointer-events: none; }
    .dashboard-hero::before { top: -158px; right: 12%; width: 360px; height: 360px; box-shadow: 0 0 0 34px rgba(255,255,255,.035), 0 0 0 72px rgba(255,255,255,.025); }
    .dashboard-hero::after { right: -66px; bottom: -204px; width: 320px; height: 320px; background: rgba(91,220,200,.12); }
    .dashboard-hero > div, .dashboard-action { position: relative; z-index: 1; }
    .dashboard-eyebrow { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 12px; color: #b7eee8; font-size: 10px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
    .dashboard-eyebrow::before { width: 7px; height: 7px; border-radius: 50%; background: #68e0c3; box-shadow: 0 0 0 4px rgba(104,224,195,.14); content: ""; }
    .dashboard-hero h1 { margin: 0 0 8px; color: #fff; font-size: clamp(28px, 3vw, 38px); font-weight: 750; letter-spacing: -.045em; }
    .dashboard-hero p { margin: 0; color: rgba(239,248,255,.78); font-size: 14px; }
    .dashboard-date { display: inline-flex; align-items: center; gap: 8px; margin-top: 19px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,.17); color: rgba(239,248,255,.78); font-size: 11px; font-weight: 550; }
    .dashboard-date i { color: #8ce2d2; }
    .dashboard-action { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 10px; padding: 13px 17px; border: 1px solid rgba(255,255,255,.72); border-radius: 10px; background: #fff; color: #13536b; text-decoration: none; font-size: 12px; font-weight: 750; box-shadow: 0 5px 16px rgba(8,28,47,.16); transition: background .18s, transform .18s, box-shadow .18s; }
    .dashboard-action:hover { transform: translateY(-2px); background: #e9fbf8; color: #104c62; box-shadow: 0 8px 20px rgba(8,28,47,.2); }
    .dashboard-action i { font-size: 15px; }
    .dashboard-alert { margin-bottom: 18px; padding: 13px 16px; border: 1px solid #f5d69c; border-radius: 11px; background: #fff9eb; color: #875b11; font-size: 12px; }
    .section-kicker { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 2px 12px; }
    .section-kicker strong { display: inline-flex; align-items: center; gap: 8px; color: #344967; font-size: 12px; font-weight: 750; }
    .section-kicker strong i { color: #168a91; font-size: 14px; }
    .section-kicker span { color: #8290a4; font-size: 10px; }
    .dashboard-tabs { display: flex; width: fit-content; max-width: 100%; gap: 4px; overflow-x: auto; margin: 0 0 18px; padding: 5px; border: 1px solid #e2e7ee; border-radius: 10px; background: #e9edf2; scrollbar-width: thin; }
    .dashboard-tab { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 8px; min-height: 38px; padding: 0 15px; border: 0; border-radius: 7px; background: transparent; color: #617087; font: inherit; font-size: 11px; font-weight: 650; cursor: pointer; transition: background .15s, color .15s, box-shadow .15s; }
    .dashboard-tab:hover { background: rgba(255,255,255,.62); color: #263d5c; }
    .dashboard-tab[aria-selected="true"] { background: #17314f; color: #fff; box-shadow: 0 2px 6px rgba(17,37,61,.19); }
    .dashboard-tab:focus-visible { outline: 3px solid rgba(8,126,139,.3); outline-offset: 2px; }
    .dashboard-tab-count { display: inline-grid; min-width: 20px; height: 20px; place-items: center; padding: 0 5px; border-radius: 6px; background: rgba(23,49,79,.07); font-size: 9px; font-weight: 750; }
    .dashboard-tab[aria-selected="true"] .dashboard-tab-count { background: rgba(255,255,255,.14); }
    .dashboard-tab-panel[hidden] { display: none; }
    .dashboard-tab-panel { animation: dashboard-panel-in .18s ease-out; }
    @keyframes dashboard-panel-in { from { opacity: .65; transform: translateY(3px); } to { opacity: 1; transform: translateY(0); } }
    .scale-intro { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin: 0 0 15px; padding: 20px 23px; border: 1px solid #e1e9f0; border-radius: 14px; background: linear-gradient(110deg, #fff 0%, #f7fbfd 100%); }
    .scale-intro-main { display: flex; align-items: center; gap: 14px; }
    .scale-intro-icon { display: grid; width: 48px; height: 48px; flex: 0 0 48px; place-items: center; border: 1px solid #d6efeb; border-radius: 14px; background: #effaf8; color: #087e8b; font-size: 20px; }
    .scale-intro h2 { margin: 0 0 5px; color: #203958; font-size: 17px; font-weight: 750; }
    .scale-intro p { margin: 0; color: #74839a; font-size: 11px; line-height: 1.55; }
    .scale-intro-badge { flex: 0 0 auto; padding: 8px 11px; border: 1px solid #e1e8ef; border-radius: 8px; background: #fff; color: #66778d; font-size: 10px; font-weight: 700; }
    .scale-stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 15px; }
    .scale-stat { padding: 15px 16px; border: 1px solid #e5eaf1; border-radius: 12px; background: #fff; }
    .scale-stat-label { display: flex; align-items: center; gap: 7px; color: #738198; font-size: 10px; font-weight: 650; }
    .scale-stat-label i { color: #168a91; font-size: 13px; }
    .scale-stat-value { display: block; margin-top: 10px; color: #172b4d; font-size: 25px; font-weight: 750; line-height: 1; letter-spacing: -.035em; }
    .scale-stat-grid.three-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .scale-summary-panel { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 18px; padding: 18px 20px; border: 1px solid #e5eaf1; border-radius: 12px; background: #fff; }
    .scale-summary-panel h3 { margin: 0 0 5px; color: #2a405e; font-size: 12px; font-weight: 750; }
    .scale-summary-panel p { margin: 0; color: #77869b; font-size: 10px; line-height: 1.6; }
    .scale-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .scale-action { display: inline-flex; align-items: center; gap: 7px; padding: 9px 12px; border: 1px solid #d9e8e8; border-radius: 8px; background: #f3faf9; color: #087e8b; font-size: 10px; font-weight: 750; text-decoration: none; white-space: nowrap; transition: background .15s, border-color .15s; }
    .scale-action:hover { border-color: #b7ddd7; background: #e7f6f3; color: #075e68; }
    .workspace-summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 11px; margin: -4px 0 16px; }
    .workspace-summary { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 3px 12px; padding: 13px 15px; border: 1px solid #e7ecf2; border-radius: 11px; background: #fff; }
    .workspace-summary > span { display: flex; align-items: center; gap: 7px; color: #68778d; font-size: 10px; font-weight: 650; }
    .workspace-summary > span i { color: #168a91; }
    .workspace-summary > strong { grid-row: span 2; color: #233a5a; font-size: 22px; font-weight: 750; }
    .workspace-summary small { color: #8996a8; font-size: 9px; }
    .workspace-summary small a { color: #087e8b; font-weight: 650; text-decoration: none; }
    .workspace-summary small a:hover { text-decoration: underline; }
    .metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 22px; }
    .metric-card, .dashboard-panel, .chart-card { min-width: 0; border: 1px solid #e5eaf1; border-radius: 15px; background: #fff; box-shadow: 0 3px 12px rgba(30,55,90,.035); }
    .metric-card { position: relative; overflow: hidden; padding: 18px 19px 17px; transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
    .metric-card::before { position: absolute; top: 0; right: 0; left: 0; height: 3px; background: #168a91; content: ""; }
    .metric-card:nth-child(2)::before { background: #4c6fff; }
    .metric-card:nth-child(3)::before { background: #e3a73e; }
    .metric-card:nth-child(4)::before { background: #31a879; }
    .metric-card:hover { transform: translateY(-3px); border-color: #d3dce8; box-shadow: 0 10px 24px rgba(30,55,90,.08); }
    .metric-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .metric-label { color: #68778d; font-size: 11px; font-weight: 650; }
    .metric-icon { display: grid; width: 37px; height: 37px; place-items: center; border: 1px solid #d6f1ed; border-radius: 11px; background: #effaf8; color: #087e8b; font-size: 17px; }
    .metric-card:nth-child(2) .metric-icon { border-color: #e0e6ff; background: #f1f3ff; color: #4c63d2; }
    .metric-card:nth-child(3) .metric-icon { border-color: #f7e9c9; background: #fff9eb; color: #b27816; }
    .metric-card:nth-child(4) .metric-icon { border-color: #d4efdf; background: #effaf3; color: #21865e; }
    .metric-value { display: block; margin-top: 13px; color: #172b4d; font-size: 34px; font-weight: 750; line-height: 1; letter-spacing: -.045em; }
    .metric-foot { display: block; margin-top: 9px; color: #8592a5; font-size: 10px; }
    .analytics-grid { display: grid; grid-template-columns: minmax(0, .9fr) minmax(0, 1.35fr); gap: 16px; margin-bottom: 21px; }
    .chart-card { overflow: hidden; transition: box-shadow .18s ease, border-color .18s ease; }
    .chart-card:hover, .dashboard-panel:hover { border-color: #d9e2ec; box-shadow: 0 8px 22px rgba(30,55,90,.065); }
    .chart-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 18px 20px 12px; border-bottom: 1px solid #f0f3f7; }
    .chart-heading h2 { margin: 0; color: #233a5a; font-size: 13px; font-weight: 750; letter-spacing: -.01em; }
    .chart-heading p { margin: 5px 0 0; color: #8794a7; font-size: 10px; }
    .chart-total { padding: 6px 9px; border: 1px solid #d8efed; border-radius: 8px; background: #f0faf8; color: #087e8b; font-size: 10px; font-weight: 750; white-space: nowrap; }
    .chart-canvas-wrap { position: relative; height: 236px; padding: 8px 16px 0; }
    .chart-canvas-wrap.doughnut-wrap { height: 218px; max-width: 340px; margin: 0 auto; }
    .chart-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; padding: 4px 16px 17px; color: #68778d; font-size: 10px; }
    .legend-item { display: inline-flex; align-items: center; gap: 7px; }
    .legend-dot { width: 9px; height: 9px; border-radius: 50%; }
    .chart-note { min-height: 32px; padding: 0 20px 12px; color: #8794a7; font-size: 10px; line-height: 1.5; }
    .chart-links { display: flex; gap: 9px; padding: 0 20px 17px; }
    .chart-links a { display: inline-flex; align-items: center; gap: 6px; padding: 7px 10px; border: 1px solid #e5edf2; border-radius: 8px; background: #f8fafc; color: #087e8b; font-size: 10px; font-weight: 700; text-decoration: none; transition: background .15s, border-color .15s; }
    .chart-links a:hover { border-color: #b9e2dd; background: #effaf8; color: #075e68; }
    .dashboard-content-grid { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(290px, .75fr); gap: 16px; align-items: start; }
    .dashboard-panel { overflow: hidden; transition: box-shadow .18s ease, border-color .18s ease; }
    .panel-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 16px 19px; border-bottom: 1px solid #edf1f5; }
    .panel-heading h2 { margin: 0; color: #233a5a; font-size: 13px; font-weight: 750; }
    .panel-heading span { color: #8794a7; font-size: 10px; }
    .panel-heading a { display: inline-flex; align-items: center; gap: 6px; color: #087e8b; font-size: 10px; font-weight: 700; text-decoration: none; }
    .panel-heading a:hover { color: #075e68; }
    .assignment-table { width: 100%; border-collapse: collapse; }
    .assignment-table th, .assignment-table td { padding: 12px 17px; border-bottom: 1px solid #f0f3f7; text-align: left; font-size: 11px; }
    .assignment-table th { background: #fafbfd; color: #8190a4; font-size: 9px; font-weight: 750; text-transform: uppercase; letter-spacing: .07em; }
    .assignment-table td { color: #53647b; }
    .assignment-table tbody tr { transition: background .15s; }
    .assignment-table tbody tr:hover { background: #f7fafc; }
    .assignment-table tbody tr:last-child td { border-bottom: 0; }
    .assignment-table td small { color: #8a97a8; font-size: 10px; }
    .patient-name { color: #233a5a; font-weight: 700; }
    .status { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border: 1px solid transparent; border-radius: 20px; font-size: 9px; font-weight: 700; white-space: nowrap; }
    .status::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
    .status.completed { border-color: #cdebd9; background: #f0faf4; color: #21865e; }
    .status.pending { border-color: #f4e3bf; background: #fff9eb; color: #a76e12; }
    .empty-state { padding: 38px 20px; color: #728198; font-size: 12px; text-align: center; }
    .progress-panel { padding: 18px 19px 20px; }
    .progress-heading { display: flex; align-items: center; justify-content: space-between; color: #68778d; font-size: 11px; }
    .progress-heading strong { color: #172b4d; font-size: 16px; font-weight: 750; }
    .progress-track { overflow: hidden; height: 8px; margin: 12px 0 9px; border-radius: 20px; background: #e9eef4; }
    .progress-fill { width: <?php echo $completion_rate; ?>%; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #087e8b, #42c8a9); }
    .progress-copy { margin: 0; color: #8794a7; font-size: 10px; line-height: 1.6; }
    .quick-title { margin: 21px 0 9px; padding-top: 15px; border-top: 1px solid #edf1f5; color: #8190a4; font-size: 9px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .quick-links { display: grid; gap: 8px; }
    .quick-link { display: flex; align-items: center; gap: 10px; padding: 10px 11px; border: 1px solid #e9eef3; border-radius: 9px; background: #fff; color: #42556f; text-decoration: none; font-size: 10px; font-weight: 650; transition: transform .15s, background .15s, border-color .15s, color .15s; }
    .quick-link i { display: grid; width: 27px; height: 27px; place-items: center; border-radius: 7px; background: #f0f8f8; color: #087e8b; font-size: 13px; }
    .quick-link:hover { transform: translateX(2px); border-color: #c4e5e1; background: #f7fcfb; color: #075e68; }
    @media (max-width: 900px) { .scale-stat-grid, .scale-stat-grid.three-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .scale-summary-panel { grid-template-columns: 1fr; } }
    @media (max-width: 1000px) { .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .analytics-grid, .dashboard-content-grid { grid-template-columns: 1fr; } }
    @media (max-width: 620px) { .dashboard-wrap { padding: 17px 12px 28px; } .dashboard-hero { align-items: flex-start; flex-direction: column; min-height: 0; padding: 24px 21px; border-radius: 15px; } .dashboard-hero::before { right: -60px; } .dashboard-action { width: 100%; justify-content: center; } .section-kicker span { display: none; } .dashboard-tabs { width: 100%; margin-bottom: 14px; } .dashboard-tab { flex: 0 0 auto; justify-content: center; padding: 0 12px; } .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .metric-card { padding: 15px 13px 14px; border-radius: 12px; } .metric-label { font-size: 10px; } .metric-icon { width: 32px; height: 32px; font-size: 15px; } .metric-value { margin-top: 11px; font-size: 28px; } .metric-foot { font-size: 9px; } .scale-intro { align-items: flex-start; flex-direction: column; padding: 16px; } .scale-intro-badge { margin-left: 62px; } .scale-stat-grid, .scale-stat-grid.three-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; } .scale-stat { padding: 13px; } .scale-stat-value { font-size: 22px; } .scale-summary-panel { grid-template-columns: 1fr; padding: 16px; } .scale-actions { width: 100%; } .scale-action { flex: 1 1 auto; justify-content: center; } .workspace-summary-grid { grid-template-columns: 1fr; gap: 8px; } .chart-card, .dashboard-panel { border-radius: 12px; } .chart-heading { padding: 15px 14px 10px; } .chart-canvas-wrap { height: 220px; padding: 6px 8px 0; } .chart-canvas-wrap.doughnut-wrap { height: 205px; } .chart-note { padding-right: 14px; padding-left: 14px; } .chart-links { padding-right: 14px; padding-left: 14px; } .assignment-table th, .assignment-table td { padding: 10px 11px; } .assignment-table th:nth-child(3), .assignment-table td:nth-child(3) { display: none; } }
    @media (prefers-reduced-motion: reduce) { .dashboard-tab-panel, .metric-card, .chart-card, .dashboard-panel, .dashboard-action, .quick-link, .scale-action { animation: none; transition: none; } }
</style>

<div class="dashboard-wrap">
    <div class="dashboard-hero">
        <div>
            <div class="dashboard-eyebrow">Clinical assessment workspace</div>
            <h1>Good day, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h1>
            <p>A clear overview of your patients, assessment activity and follow-ups.</p>
            <span class="dashboard-date"><i class="bi bi-calendar3" aria-hidden="true"></i><?php echo date('l, d F Y'); ?></span>
        </div>
        <a class="dashboard-action" href="assign-assessments.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Assign assessment</a>
    </div>

    <?php if (!empty($dashboard_errors)): ?>
        <div class="dashboard-alert" role="alert"><?php echo htmlspecialchars(implode(' ', $dashboard_errors), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="dashboard-tabs" role="tablist" aria-label="Assessment dashboard sections">
        <button class="dashboard-tab" id="tab-overview" type="button" role="tab" aria-selected="true" aria-controls="panel-overview" tabindex="0"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Overview</button>
        <button class="dashboard-tab" id="tab-bai" type="button" role="tab" aria-selected="false" aria-controls="panel-bai" tabindex="-1">BAI <span class="dashboard-tab-count"><?php echo $beck_stats_loaded ? (int) $beck_stats['bai']['records'] : '—'; ?></span></button>
        <button class="dashboard-tab" id="tab-bdi" type="button" role="tab" aria-selected="false" aria-controls="panel-bdi" tabindex="-1">BDI <span class="dashboard-tab-count"><?php echo $beck_stats_loaded ? (int) $beck_stats['bdi']['records'] : '—'; ?></span></button>
        <button class="dashboard-tab" id="tab-bprs" type="button" role="tab" aria-selected="false" aria-controls="panel-bprs" tabindex="-1">BPRS <span class="dashboard-tab-count"><?php echo $module_stats['bprs']['total'] === null ? '—' : (int) $module_stats['bprs']['total']; ?></span></button>
        <button class="dashboard-tab" id="tab-panss" type="button" role="tab" aria-selected="false" aria-controls="panel-panss" tabindex="-1">PANSS <span class="dashboard-tab-count"><?php echo $module_stats['panss']['total'] === null ? '—' : (int) $module_stats['panss']['total']; ?></span></button>
    </div>

    <section class="dashboard-tab-panel" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" tabindex="0">
    <div class="section-kicker">
        <strong><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i> At a glance</strong>
        <span>Workspace performance and patient activity</span>
    </div>

    <div class="metric-grid">
        <div class="metric-card"><div class="metric-top"><span class="metric-label">Registered patients</span><span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span></div><strong class="metric-value"><?php echo (int) $stats['patients']; ?></strong><span class="metric-foot">In your patient workspace</span></div>
        <div class="metric-card"><div class="metric-top"><span class="metric-label">Total assignments</span><span class="metric-icon"><i class="bi bi-clipboard2-check" aria-hidden="true"></i></span></div><strong class="metric-value"><?php echo (int) $stats['assigned']; ?></strong><span class="metric-foot">Across all assessments</span></div>
        <div class="metric-card"><div class="metric-top"><span class="metric-label">Awaiting response</span><span class="metric-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span></div><strong class="metric-value"><?php echo (int) $stats['pending']; ?></strong><span class="metric-foot">Follow-up may be needed</span></div>
        <div class="metric-card"><div class="metric-top"><span class="metric-label">Completed</span><span class="metric-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span></div><strong class="metric-value"><?php echo (int) $stats['completed']; ?></strong><span class="metric-foot"><?php echo $completion_rate; ?>% assignment completion</span></div>
    </div>

    <div class="analytics-grid">
        <section class="chart-card">
            <div class="chart-heading">
                <div><h2>Assignment status</h2><p>Completed versus awaiting response</p></div>
                <span class="chart-total"><?php echo (int) $stats['assigned']; ?> total</span>
            </div>
            <div class="chart-canvas-wrap doughnut-wrap">
                <canvas id="assignmentStatusChart" role="img" aria-label="Doughnut chart showing completed and pending assignments"></canvas>
            </div>
            <div class="chart-legend" aria-hidden="true">
                <span class="legend-item"><span class="legend-dot" style="background:#0f766e"></span>Completed (<?php echo (int) $stats['completed']; ?>)</span>
                <span class="legend-item"><span class="legend-dot" style="background:#f2b84b"></span>Awaiting (<?php echo (int) $stats['pending']; ?>)</span>
            </div>
        </section>
        <section class="chart-card">
            <div class="chart-heading">
                <div><h2>Assessment activity</h2><p>Saved records and activity in the last 7 days</p></div>
                <span class="chart-total">4 assessment scales</span>
            </div>
            <div class="chart-canvas-wrap">
                <canvas id="assessmentActivityChart" role="img" aria-label="Bar chart comparing BAI, BDI, BPRS, and PANSS assessment records and recent activity"></canvas>
            </div>
            <div class="chart-legend" aria-hidden="true">
                <span class="legend-item"><span class="legend-dot" style="background:#4f46e5"></span>Total records</span>
                <span class="legend-item"><span class="legend-dot" style="background:#06b6d4"></span>Last 7 days</span>
            </div>
            <?php if (!$beck_stats_loaded || $module_stats['bprs']['total'] === null || $module_stats['panss']['total'] === null): ?>
                <div class="chart-note">One or more assessment data sources are unavailable; affected values are omitted.</div>
            <?php else: ?>
                <div class="chart-note">Hover over a bar to see the exact record count.</div>
            <?php endif; ?>
            <div class="chart-links">
                <a href="bprs-assessment-records.php">BPRS records <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                <a href="panss-assessment-records.php">PANSS records <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            </div>
        </section>
    </div>

    <div class="workspace-summary-grid">
        <div class="workspace-summary"><span><i class="bi bi-journals" aria-hidden="true"></i> Assessment library</span><strong><?php echo $workspace_stats['assessment_catalog'] === null ? '—' : (int) $workspace_stats['assessment_catalog']; ?></strong><small>available assessments</small></div>
        <div class="workspace-summary"><span><i class="bi bi-file-earmark-medical" aria-hidden="true"></i> Submitted reports</span><strong><?php echo $workspace_stats['responses'] === null ? '—' : (int) $workspace_stats['responses']; ?></strong><small>saved responses</small></div>
        <div class="workspace-summary"><span><i class="bi bi-journal-text" aria-hidden="true"></i> Question bank</span><strong><?php echo $workspace_stats['questions'] === null ? '—' : (int) $workspace_stats['questions']; ?></strong><small><a href="view-assessment-questions.php">manage assessment questions <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></small></div>
    </div>

    <div class="dashboard-content-grid">
        <section class="dashboard-panel">
            <div class="panel-heading"><h2>Recent assignments</h2><a href="assigned-assessments.php">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
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
                <div class="progress-heading"><span>Overall completion</span><strong><?php echo $completion_rate; ?>%</strong></div>
                <div class="progress-track" role="progressbar" aria-valuenow="<?php echo $completion_rate; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Assignment completion">
                    <div class="progress-fill"></div>
                </div>
                <p class="progress-copy"><?php echo (int) $stats['completed']; ?> of <?php echo (int) $stats['assigned']; ?> assignments completed. <?php echo (int) $stats['pending']; ?> still awaiting a response.</p>
                <div class="quick-title">Quick access</div>
                <div class="quick-links">
                    <a class="quick-link" href="public-assessment-form.php"><i class="bi bi-person-vcard"></i> Open patient assessments</a>
                    <a class="quick-link" href="assigned-assessments.php"><i class="bi bi-table"></i> View all assignment records</a>
                    <a class="quick-link" href="panss-assessments.php"><i class="bi bi-activity"></i> New PANSS assessment</a>
                    <a class="quick-link" href="bprs-assessments.php"><i class="bi bi-clipboard2-pulse"></i> New BPRS assessment</a>
                    <a class="quick-link" href="view-assessment-questions.php"><i class="bi bi-journal-text"></i> Manage question bank</a>
                </div>
            </div>
        </aside>
    </div>
    </section>

    <?php foreach ($scale_tabs as $scale): ?>
        <section class="dashboard-tab-panel" id="panel-<?php echo htmlspecialchars($scale['id'], ENT_QUOTES, 'UTF-8'); ?>" role="tabpanel" aria-labelledby="tab-<?php echo htmlspecialchars($scale['id'], ENT_QUOTES, 'UTF-8'); ?>" tabindex="0" hidden>
            <header class="scale-intro">
                <div class="scale-intro-main">
                    <span class="scale-intro-icon"><i class="bi <?php echo htmlspecialchars($scale['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i></span>
                    <div><h2><?php echo htmlspecialchars($scale['title'], ENT_QUOTES, 'UTF-8'); ?></h2><p><?php echo htmlspecialchars($scale['description'], ENT_QUOTES, 'UTF-8'); ?></p></div>
                </div>
                <span class="scale-intro-badge"><?php echo htmlspecialchars((string) $scale['badge'], ENT_QUOTES, 'UTF-8'); ?></span>
            </header>

            <div class="scale-stat-grid<?php echo $scale['three_metrics'] ? ' three-metrics' : ''; ?>">
                <?php foreach ($scale['metrics'] as $metric): ?>
                    <div class="scale-stat">
                        <span class="scale-stat-label"><i class="bi <?php echo htmlspecialchars($metric[2], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i><?php echo htmlspecialchars($metric[0], ENT_QUOTES, 'UTF-8'); ?></span>
                        <strong class="scale-stat-value"><?php echo $metric[1] === null ? '—' : (int) $metric[1]; ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="scale-summary-panel">
                <div><h3><?php echo htmlspecialchars($scale['summary_title'], ENT_QUOTES, 'UTF-8'); ?></h3><p><?php echo htmlspecialchars($scale['summary'], ENT_QUOTES, 'UTF-8'); ?></p></div>
                <div class="scale-actions">
                    <?php foreach ($scale['actions'] as $action): ?>
                        <a class="scale-action" href="<?php echo htmlspecialchars($action[1], ENT_QUOTES, 'UTF-8'); ?>"><i class="bi <?php echo htmlspecialchars($action[2], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i><?php echo htmlspecialchars($action[0], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endforeach; ?>
                </div>
            </section>
        </section>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const assignmentData = <?php echo json_encode($assignment_chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const assessmentData = <?php echo json_encode($assessment_chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const tabs = Array.from(document.querySelectorAll('.dashboard-tab[role="tab"]'));
    const tabPanels = Array.from(document.querySelectorAll('.dashboard-tab-panel[role="tabpanel"]'));
    const assignmentCanvas = document.getElementById('assignmentStatusChart');
    const assessmentCanvas = document.getElementById('assessmentActivityChart');
    function activateTab(tab, moveFocus) {
        const panelId = tab.getAttribute('aria-controls');
        tabs.forEach(function (item) {
            const active = item === tab;
            item.setAttribute('aria-selected', active ? 'true' : 'false');
            item.tabIndex = active ? 0 : -1;
        });
        tabPanels.forEach(function (panel) {
            panel.hidden = panel.id !== panelId;
        });
        if (moveFocus) tab.focus();
        window.dispatchEvent(new Event('resize'));
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            activateTab(tab, false);
        });
        tab.addEventListener('keydown', function (event) {
            let nextIndex = index;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') nextIndex = 0;
            else if (event.key === 'End') nextIndex = tabs.length - 1;
            else return;
            event.preventDefault();
            activateTab(tabs[nextIndex], true);
        });
    });
    const centerLabel = {
        id: 'assignmentCenterLabel',
        afterDraw: function (chart) {
            const area = chart.chartArea;
            if (!area) return;
            const context = chart.ctx;
            const x = (area.left + area.right) / 2;
            const y = (area.top + area.bottom) / 2;
            context.save();
            context.textAlign = 'center';
            context.textBaseline = 'middle';
            context.fillStyle = '#101828';
            context.font = '800 27px system-ui, sans-serif';
            context.fillText('<?php echo $completion_rate; ?>%', x, y - 7);
            context.fillStyle = '#98a2b3';
            context.font = '600 11px system-ui, sans-serif';
            context.fillText('complete', x, y + 16);
            context.restore();
        }
    };

    if (assignmentCanvas && typeof Chart !== 'undefined') {
        new Chart(assignmentCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Awaiting response'],
                datasets: [{
                    data: assignmentData[0] + assignmentData[1] > 0 ? assignmentData : [1, 0],
                    backgroundColor: assignmentData[0] + assignmentData[1] > 0 ? ['#0f766e', '#f2b84b'] : ['#e7ebf2', '#e7ebf2'],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const actualValue = assignmentData[context.dataIndex] || 0;
                                return ' ' + context.label + ': ' + actualValue;
                            }
                        }
                    }
                }
            },
            plugins: [centerLabel]
        });
    }

    if (assessmentCanvas && typeof Chart !== 'undefined') {
        new Chart(assessmentCanvas, {
            type: 'bar',
            data: {
                labels: assessmentData.labels,
                datasets: [
                    {
                        label: 'Total records',
                        data: assessmentData.total,
                        backgroundColor: '#4f46e5',
                        borderRadius: 6,
                        maxBarThickness: 38
                    },
                    {
                        label: 'Last 7 days',
                        data: assessmentData.recent,
                        backgroundColor: '#06b6d4',
                        borderRadius: 6,
                        maxBarThickness: 38
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.dataset.label + ': ' + (context.raw === null ? 'unavailable' : context.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#667085', font: { weight: 700 } },
                        border: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#98a2b3' },
                        grid: { color: '#eef0f4' },
                        border: { display: false }
                    }
                }
            }
        });
    }
});
</script>

<?php require 'layout/footer.php'; ob_end_flush(); ?>
