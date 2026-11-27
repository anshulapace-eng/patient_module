<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
    'bprs' => ['total' => null, 'recent' => 0, 'patients' => null, 'avg_score' => null],
    'panss' => ['total' => null, 'recent' => 0, 'patients' => null, 'avg_score' => null],
    'ymrs' => ['total' => null, 'recent' => 0, 'patients' => null, 'avg_score' => null],
    'ace_iii' => ['total' => null, 'recent' => 0, 'patients' => null, 'avg_score' => null],
];
$module_pie_data = [
    'bprs' => [
        'title' => 'BPRS Severity Breakdown',
        'subtitle' => 'Distribution of saved ratings by clinical severity band',
        'labels' => ['Normal (≤30)', 'Mild (31–40)', 'Moderate (41–52)', 'Severe (53+)'],
        'values' => [0, 0, 0, 0],
        'colors' => ['#0f766e', '#f59e0b', '#f97316', '#e11d48'],
        'unit' => 'records',
    ],
    'panss' => [
        'title' => 'PANSS Subscale Share',
        'subtitle' => 'Average points across Positive, Negative & General scales',
        'labels' => ['Positive (P)', 'Negative (N)', 'General (G)'],
        'values' => [0, 0, 0],
        'colors' => ['#4f46e5', '#0ea5e9', '#0f766e'],
        'unit' => 'avg pts',
    ],
    'ymrs' => [
        'title' => 'YMRS Severity Breakdown',
        'subtitle' => 'Distribution of saved mania assessments by severity',
        'labels' => ['Normal (0–12)', 'Mild (13–19)', 'Moderate (20–25)', 'Severe (26+)'],
        'values' => [0, 0, 0, 0],
        'colors' => ['#0f766e', '#f59e0b', '#f97316', '#e11d48'],
        'unit' => 'records',
    ],
    'ace_iii' => [
        'title' => 'ACE-III Cognitive Breakdown',
        'subtitle' => 'Completed cognitive interpretations vs pending/draft',
        'labels' => ['Normal (88–100)', 'Borderline (83–87)', 'Abnormal (≤82)', 'Pending / Draft'],
        'values' => [0, 0, 0, 0],
        'colors' => ['#0f766e', '#f59e0b', '#e11d48', '#94a3b8'],
        'unit' => 'records',
    ],
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
    // 1. Overall patient & assignment stats
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

    // 2. Recent assignments
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

    // 3. BPRS summary + severity pie breakdown
    $bprs_check = $conn->query("SHOW TABLES LIKE 'bprs_assessments'");
    if ($bprs_check && $bprs_check->num_rows > 0) {
        $bprs_sql = "SELECT COUNT(*) AS total,
                COALESCE(SUM(a.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)), 0) AS recent,
                COUNT(DISTINCT a.patient_id) AS patients,
                ROUND(AVG(a.total_score), 1) AS avg_score,
                COALESCE(SUM(a.total_score <= 30), 0) AS band_normal,
                COALESCE(SUM(a.total_score BETWEEN 31 AND 40), 0) AS band_mild,
                COALESCE(SUM(a.total_score BETWEEN 41 AND 52), 0) AS band_mod,
                COALESCE(SUM(a.total_score >= 53), 0) AS band_sev
            FROM bprs_assessments a
            INNER JOIN users u ON u.id = a.patient_id AND u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID;
        if ($res = $conn->query($bprs_sql)) {
            $row = $res->fetch_assoc();
            $module_stats['bprs'] = [
                'total' => (int) ($row['total'] ?? 0),
                'recent' => (int) ($row['recent'] ?? 0),
                'patients' => (int) ($row['patients'] ?? 0),
                'avg_score' => $row['avg_score'] !== null ? (float) $row['avg_score'] : null,
            ];
            $module_pie_data['bprs']['values'] = [
                (int) ($row['band_normal'] ?? 0),
                (int) ($row['band_mild'] ?? 0),
                (int) ($row['band_mod'] ?? 0),
                (int) ($row['band_sev'] ?? 0),
            ];
        }
    }

    // 4. PANSS summary + subscale pie breakdown
    $panss_check = $conn->query("SHOW TABLES LIKE 'panss_assessments'");
    if ($panss_check && $panss_check->num_rows > 0) {
        $panss_sql = "SELECT COUNT(*) AS total,
                COALESCE(SUM(a.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)), 0) AS recent,
                COUNT(DISTINCT a.patient_id) AS patients,
                ROUND(AVG(a.composite_score), 1) AS avg_comp,
                ROUND(COALESCE(AVG(a.positive_score), 0), 1) AS avg_p,
                ROUND(COALESCE(AVG(a.negative_score), 0), 1) AS avg_n,
                ROUND(COALESCE(AVG(a.general_score), 0), 1) AS avg_g
            FROM panss_assessments a
            INNER JOIN users u ON u.id = a.patient_id AND u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID;
        if ($res = $conn->query($panss_sql)) {
            $row = $res->fetch_assoc();
            $module_stats['panss'] = [
                'total' => (int) ($row['total'] ?? 0),
                'recent' => (int) ($row['recent'] ?? 0),
                'patients' => (int) ($row['patients'] ?? 0),
                'avg_score' => $row['avg_comp'] !== null ? (float) $row['avg_comp'] : null,
            ];
            if ((int) ($row['total'] ?? 0) > 0) {
                $module_pie_data['panss']['values'] = [
                    (float) ($row['avg_p'] ?? 0),
                    (float) ($row['avg_n'] ?? 0),
                    (float) ($row['avg_g'] ?? 0),
                ];
            }
        }
    }

    // 5. YMRS summary + severity pie breakdown
    $ymrs_check = $conn->query("SHOW TABLES LIKE 'ymrs_assessments'");
    if ($ymrs_check && $ymrs_check->num_rows > 0) {
        $ymrs_sql = "SELECT COUNT(*) AS total,
                COALESCE(SUM(a.assessment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)), 0) AS recent,
                COUNT(DISTINCT a.patient_id) AS patients,
                ROUND(AVG(a.total_score), 1) AS avg_score,
                COALESCE(SUM(a.total_score <= 12), 0) AS band_normal,
                COALESCE(SUM(a.total_score BETWEEN 13 AND 19), 0) AS band_mild,
                COALESCE(SUM(a.total_score BETWEEN 20 AND 25), 0) AS band_mod,
                COALESCE(SUM(a.total_score >= 26), 0) AS band_sev
            FROM ymrs_assessments a
            INNER JOIN users u ON u.id = a.patient_id AND u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID;
        if ($res = $conn->query($ymrs_sql)) {
            $row = $res->fetch_assoc();
            $module_stats['ymrs'] = [
                'total' => (int) ($row['total'] ?? 0),
                'recent' => (int) ($row['recent'] ?? 0),
                'patients' => (int) ($row['patients'] ?? 0),
                'avg_score' => $row['avg_score'] !== null ? (float) $row['avg_score'] : null,
            ];
            $module_pie_data['ymrs']['values'] = [
                (int) ($row['band_normal'] ?? 0),
                (int) ($row['band_mild'] ?? 0),
                (int) ($row['band_mod'] ?? 0),
                (int) ($row['band_sev'] ?? 0),
            ];
        }
    }

    // 6. ACE-III summary + cognitive pie breakdown
    $ace_check = $conn->query("SHOW TABLES LIKE 'ace_iii_assessments'");
    if ($ace_check && $ace_check->num_rows > 0) {
        $ace_sql = "SELECT COUNT(*) AS total,
                COALESCE(SUM(a.assessment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)), 0) AS recent,
                COUNT(DISTINCT a.patient_id) AS patients,
                ROUND(AVG(CASE WHEN LOWER(COALESCE(a.status, '')) = 'success' THEN a.total_score END), 1) AS avg_score,
                COALESCE(SUM(LOWER(COALESCE(a.status, '')) = 'success' AND a.total_score >= 88), 0) AS band_normal,
                COALESCE(SUM(LOWER(COALESCE(a.status, '')) = 'success' AND a.total_score BETWEEN 83 AND 87), 0) AS band_border,
                COALESCE(SUM(LOWER(COALESCE(a.status, '')) = 'success' AND a.total_score <= 82), 0) AS band_abn,
                COALESCE(SUM(LOWER(COALESCE(a.status, '')) <> 'success'), 0) AS band_pending
            FROM ace_iii_assessments a
            INNER JOIN users u ON u.id = a.patient_id AND u.role_id = 2 AND u.patient_id = " . PATIENT_SCOPE_ID;
        if ($res = $conn->query($ace_sql)) {
            $row = $res->fetch_assoc();
            $module_stats['ace_iii'] = [
                'total' => (int) ($row['total'] ?? 0),
                'recent' => (int) ($row['recent'] ?? 0),
                'patients' => (int) ($row['patients'] ?? 0),
                'avg_score' => $row['avg_score'] !== null ? (float) $row['avg_score'] : null,
            ];
            $module_pie_data['ace_iii']['values'] = [
                (int) ($row['band_normal'] ?? 0),
                (int) ($row['band_border'] ?? 0),
                (int) ($row['band_abn'] ?? 0),
                (int) ($row['band_pending'] ?? 0),
            ];
        }
    }

    // 7. Workspace stats
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

    // 8. BAI & BDI stats
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
    ? (int) round(((int) $stats['completed'] / (int) $stats['assigned']) * 100)
    : 0;

$assignment_chart_data = [
    (int) $stats['completed'],
    (int) $stats['pending'],
];

$assessment_chart_data = [
    'labels' => ['BAI', 'BDI', 'BPRS', 'PANSS', 'YMRS', 'ACE-III'],
    'total' => [
        $beck_stats_loaded ? $beck_stats['bai']['records'] : 0,
        $beck_stats_loaded ? $beck_stats['bdi']['records'] : 0,
        (int) ($module_stats['bprs']['total'] ?? 0),
        (int) ($module_stats['panss']['total'] ?? 0),
        (int) ($module_stats['ymrs']['total'] ?? 0),
        (int) ($module_stats['ace_iii']['total'] ?? 0),
    ],
    'recent' => [
        $beck_stats_loaded ? $beck_stats['bai']['recent'] : 0,
        $beck_stats_loaded ? $beck_stats['bdi']['recent'] : 0,
        (int) ($module_stats['bprs']['recent'] ?? 0),
        (int) ($module_stats['panss']['recent'] ?? 0),
        (int) ($module_stats['ymrs']['recent'] ?? 0),
        (int) ($module_stats['ace_iii']['recent'] ?? 0),
    ],
];

$tab_charts_config = [
    'bai' => [
        'title' => 'BAI Assignment Completion',
        'subtitle' => 'Completed vs awaiting patient responses',
        'labels' => ['Completed', 'Awaiting'],
        'values' => $beck_stats_loaded ? [$beck_stats['bai']['completed'], $beck_stats['bai']['pending']] : [0, 0],
        'colors' => ['#0f766e', '#f59e0b'],
        'unit' => 'assignments',
    ],
    'bdi' => [
        'title' => 'BDI Assignment Completion',
        'subtitle' => 'Completed vs awaiting patient responses',
        'labels' => ['Completed', 'Awaiting'],
        'values' => $beck_stats_loaded ? [$beck_stats['bdi']['completed'], $beck_stats['bdi']['pending']] : [0, 0],
        'colors' => ['#4f46e5', '#f59e0b'],
        'unit' => 'assignments',
    ],
    'bprs' => $module_pie_data['bprs'],
    'panss' => $module_pie_data['panss'],
    'ymrs' => $module_pie_data['ymrs'],
    'ace_iii' => $module_pie_data['ace_iii'],
];

// All 6 Scales Live Matrix + Individual Tab Details
$scale_tabs = [
    [
        'id' => 'bai',
        'code' => 'BAI',
        'title' => 'Beck Anxiety Inventory (BAI)',
        'short_name' => 'Beck Anxiety Inventory',
        'icon' => 'bi-heart-pulse',
        'accent' => '#0f766e',
        'description' => '21-item self-rated anxiety assessment tracking assignment completion, pending follow-ups, and submitted clinical reports.',
        'badge' => $beck_stats_loaded ? $beck_stats['bai']['records'] . ' responses' : 'Data unavailable',
        'tab_count' => $beck_stats_loaded ? (int) $beck_stats['bai']['records'] : null,
        'recent' => $beck_stats_loaded ? (int) $beck_stats['bai']['recent'] : 0,
        'meta_label' => 'Assigned / Pending',
        'meta_val' => $beck_stats_loaded ? ($beck_stats['bai']['assigned'] . ' / ' . $beck_stats['bai']['pending']) : '—',
        'metrics' => [
            ['Assigned', $beck_stats_loaded ? $beck_stats['bai']['assigned'] : null, 'bi-send', 'Total sent'],
            ['Completed', $beck_stats_loaded ? $beck_stats['bai']['completed'] : null, 'bi-check2-circle', 'Responses done'],
            ['Awaiting', $beck_stats_loaded ? $beck_stats['bai']['pending'] : null, 'bi-hourglass-split', 'Pending follow-up'],
            ['Last 7 Days', $beck_stats_loaded ? $beck_stats['bai']['recent'] : null, 'bi-calendar2-week', 'Recent intake'],
        ],
        'summary_title' => 'Workflow Summary',
        'summary' => $beck_stats_loaded
            ? $beck_stats['bai']['recent'] . ' BAI responses submitted in the last 7 days. Open BAI Records to review severity reports or start a new assessment.'
            : 'BAI response activity could not be loaded right now.',
        'new_url' => 'bai-assessments.php',
        'rec_url' => 'bai-assessment-records.php',
        'new_label' => 'New BAI Assessment',
        'rec_label' => 'BAI Records',
    ],
    [
        'id' => 'bdi',
        'code' => 'BDI',
        'title' => 'Beck Depression Inventory (BDI)',
        'short_name' => 'Beck Depression Inventory',
        'icon' => 'bi-clipboard2-heart',
        'accent' => '#4f46e5',
        'description' => '21-item depression severity inventory tracking patient assignments, completion status, and historical score reports.',
        'badge' => $beck_stats_loaded ? $beck_stats['bdi']['records'] . ' responses' : 'Data unavailable',
        'tab_count' => $beck_stats_loaded ? (int) $beck_stats['bdi']['records'] : null,
        'recent' => $beck_stats_loaded ? (int) $beck_stats['bdi']['recent'] : 0,
        'meta_label' => 'Assigned / Pending',
        'meta_val' => $beck_stats_loaded ? ($beck_stats['bdi']['assigned'] . ' / ' . $beck_stats['bdi']['pending']) : '—',
        'metrics' => [
            ['Assigned', $beck_stats_loaded ? $beck_stats['bdi']['assigned'] : null, 'bi-send', 'Total sent'],
            ['Completed', $beck_stats_loaded ? $beck_stats['bdi']['completed'] : null, 'bi-check2-circle', 'Responses done'],
            ['Awaiting', $beck_stats_loaded ? $beck_stats['bdi']['pending'] : null, 'bi-hourglass-split', 'Pending follow-up'],
            ['Last 7 Days', $beck_stats_loaded ? $beck_stats['bdi']['recent'] : null, 'bi-calendar2-week', 'Recent intake'],
        ],
        'summary_title' => 'Workflow Summary',
        'summary' => $beck_stats_loaded
            ? $beck_stats['bdi']['recent'] . ' BDI responses submitted in the last 7 days. Use BDI Records to inspect item-wise depression scores and print reports.'
            : 'BDI response activity could not be loaded right now.',
        'new_url' => 'bdi-assessments.php',
        'rec_url' => 'bdi-assessment-records.php',
        'new_label' => 'New BDI Assessment',
        'rec_label' => 'BDI Records',
    ],
    [
        'id' => 'bprs',
        'code' => 'BPRS',
        'title' => 'Brief Psychiatric Rating Scale (BPRS)',
        'short_name' => 'Brief Psychiatric Rating',
        'icon' => 'bi-clipboard2-pulse',
        'accent' => '#0284c7',
        'description' => '18-item clinician-rated psychiatric scale with severity distribution and item-level score reports.',
        'badge' => $module_stats['bprs']['total'] === null ? 'Data unavailable' : (int) $module_stats['bprs']['total'] . ' saved ratings',
        'tab_count' => $module_stats['bprs']['total'] === null ? null : (int) $module_stats['bprs']['total'],
        'recent' => (int) $module_stats['bprs']['recent'],
        'meta_label' => 'Avg. Score',
        'meta_val' => $module_stats['bprs']['avg_score'] !== null ? $module_stats['bprs']['avg_score'] . ' / 126' : '—',
        'metrics' => [
            ['Saved Ratings', $module_stats['bprs']['total'], 'bi-file-earmark-check', 'Total records'],
            ['Last 7 Days', $module_stats['bprs']['total'] === null ? null : $module_stats['bprs']['recent'], 'bi-calendar2-week', 'Recent ratings'],
            ['Patients Rated', $module_stats['bprs']['patients'], 'bi-people', 'Unique patients'],
            ['Avg. BPRS Score', $module_stats['bprs']['avg_score'] !== null ? $module_stats['bprs']['avg_score'] . '/126' : null, 'bi-bar-chart', 'Mean total score'],
        ],
        'summary_title' => 'Clinical Overview',
        'summary' => $module_stats['bprs']['total'] === null
            ? 'BPRS records are not available right now.'
            : 'The chart and breakdown bars show how saved BPRS ratings distribute across Normal, Mild, Moderate, and Severe bands.',
        'new_url' => 'bprs-assessments.php',
        'rec_url' => 'bprs-assessment-records.php',
        'new_label' => 'New BPRS Rating',
        'rec_label' => 'BPRS Records',
    ],
    [
        'id' => 'panss',
        'code' => 'PANSS',
        'title' => 'Positive and Negative Syndrome Scale (PANSS)',
        'short_name' => 'Positive & Negative Syndrome',
        'icon' => 'bi-activity',
        'accent' => '#7c3aed',
        'description' => '30-item clinician-rated scale tracking Positive (P), Negative (N), General Psychopathology (G), and Composite scores.',
        'badge' => $module_stats['panss']['total'] === null ? 'Data unavailable' : (int) $module_stats['panss']['total'] . ' saved ratings',
        'tab_count' => $module_stats['panss']['total'] === null ? null : (int) $module_stats['panss']['total'],
        'recent' => (int) $module_stats['panss']['recent'],
        'meta_label' => 'Avg. Composite',
        'meta_val' => $module_stats['panss']['avg_score'] !== null ? (($module_stats['panss']['avg_score'] > 0 ? '+' : '') . $module_stats['panss']['avg_score']) : '—',
        'metrics' => [
            ['Saved Ratings', $module_stats['panss']['total'], 'bi-file-earmark-check', 'Total records'],
            ['Last 7 Days', $module_stats['panss']['total'] === null ? null : $module_stats['panss']['recent'], 'bi-calendar2-week', 'Recent ratings'],
            ['Patients Rated', $module_stats['panss']['patients'], 'bi-people', 'Unique patients'],
            ['Avg. Composite', $module_stats['panss']['avg_score'] !== null ? ($module_stats['panss']['avg_score'] > 0 ? '+' : '') . $module_stats['panss']['avg_score'] : null, 'bi-calculator', 'P − N index'],
        ],
        'summary_title' => 'Clinical Overview',
        'summary' => $module_stats['panss']['total'] === null
            ? 'PANSS records are not available right now.'
            : 'Compares mean subscale points across Positive (P), Negative (N), and General Psychopathology (G) domains.',
        'new_url' => 'panss-assessments.php',
        'rec_url' => 'panss-assessment-records.php',
        'new_label' => 'New PANSS Rating',
        'rec_label' => 'PANSS Records',
    ],
    [
        'id' => 'ymrs',
        'code' => 'YMRS',
        'title' => 'Young Mania Rating Scale (YMRS)',
        'short_name' => 'Young Mania Rating Scale',
        'icon' => 'bi-speedometer2',
        'accent' => '#d97706',
        'description' => '11-item clinician-rated mania evaluation with live severity categorization across a 0–60 clinical scale.',
        'badge' => $module_stats['ymrs']['total'] === null ? 'Data unavailable' : (int) $module_stats['ymrs']['total'] . ' saved ratings',
        'tab_count' => $module_stats['ymrs']['total'] === null ? null : (int) $module_stats['ymrs']['total'],
        'recent' => (int) $module_stats['ymrs']['recent'],
        'meta_label' => 'Avg. Score',
        'meta_val' => $module_stats['ymrs']['avg_score'] !== null ? $module_stats['ymrs']['avg_score'] . ' / 60' : '—',
        'metrics' => [
            ['Saved Ratings', $module_stats['ymrs']['total'], 'bi-file-earmark-check', 'Total records'],
            ['Last 7 Days', $module_stats['ymrs']['total'] === null ? null : $module_stats['ymrs']['recent'], 'bi-calendar2-week', 'Recent ratings'],
            ['Patients Rated', $module_stats['ymrs']['patients'], 'bi-people', 'Unique patients'],
            ['Avg. YMRS Score', $module_stats['ymrs']['avg_score'] !== null ? $module_stats['ymrs']['avg_score'] . '/60' : null, 'bi-bar-chart', 'Mean total score'],
        ],
        'summary_title' => 'Clinical Overview',
        'summary' => $module_stats['ymrs']['total'] === null
            ? 'YMRS records are not available right now.'
            : 'Breaks down saved YMRS evaluations into Normal/Remission, Minimal/Mild, Moderate, and Severe Mania severity bands.',
        'new_url' => 'ymrs-assessments.php',
        'rec_url' => 'ymrs-assessment-records.php',
        'new_label' => 'New YMRS Rating',
        'rec_label' => 'YMRS Records',
    ],
    [
        'id' => 'ace_iii',
        'code' => 'ACE-III',
        'title' => "Addenbrooke's Cognitive Examination (ACE-III)",
        'short_name' => "Addenbrooke's Cognitive",
        'icon' => 'bi-cpu',
        'accent' => '#059669',
        'description' => '100-point cognitive examination evaluating Attention, Memory, Fluency, Language, and Visuospatial domains.',
        'badge' => $module_stats['ace_iii']['total'] === null ? 'Data unavailable' : (int) $module_stats['ace_iii']['total'] . ' saved records',
        'tab_count' => $module_stats['ace_iii']['total'] === null ? null : (int) $module_stats['ace_iii']['total'],
        'recent' => (int) $module_stats['ace_iii']['recent'],
        'meta_label' => 'Avg. Completed',
        'meta_val' => $module_stats['ace_iii']['avg_score'] !== null ? $module_stats['ace_iii']['avg_score'] . ' / 100' : '—',
        'metrics' => [
            ['Saved Records', $module_stats['ace_iii']['total'], 'bi-file-earmark-check', 'Total records'],
            ['Last 7 Days', $module_stats['ace_iii']['total'] === null ? null : $module_stats['ace_iii']['recent'], 'bi-calendar2-week', 'Recent exams'],
            ['Patients Assessed', $module_stats['ace_iii']['patients'], 'bi-people', 'Unique patients'],
            ['Avg. Completed', $module_stats['ace_iii']['avg_score'] !== null ? $module_stats['ace_iii']['avg_score'] . '/100' : null, 'bi-award', 'Mean score'],
        ],
        'summary_title' => 'Clinical Overview',
        'summary' => $module_stats['ace_iii']['total'] === null
            ? 'ACE-III records are not available right now.'
            : 'Displays completed cognitive interpretations (Normal, Borderline/MCI, Abnormal) alongside Pending/Draft assessments.',
        'new_url' => 'ace-iii-assessments.php',
        'rec_url' => 'ace-iii-assessment-records.php',
        'new_label' => 'New ACE-III Exam',
        'rec_label' => 'ACE-III Records',
    ],
];

function dash_e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function dash_initials($name)
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $initials .= strtoupper(substr($p, 0, 1));
        }
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: 'PT';
}

require 'layout/base.php';
?>

<style>
    :root {
        --cc-bg: #f3f6fa;
        --cc-surface: #ffffff;
        --cc-ink: #0f172a;
        --cc-ink-2: #334155;
        --cc-muted: #64748b;
        --cc-primary: #0f766e;
        --cc-primary-dark: #115e59;
        --cc-primary-soft: #f0fdfa;
        --cc-shadow: 0 8px 26px -6px rgba(15, 23, 42, 0.08), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
        --cc-shadow-hover: 0 14px 32px -8px rgba(15, 23, 42, 0.13);
    }

    .cc-wrap {
        max-width: 1560px;
        margin: 0 auto;
        padding: 20px clamp(14px, 2vw, 28px) 48px;
        background: var(--cc-bg);
        color: var(--cc-ink);
        font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* 1. Executive Command Bar */
    .cc-topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        padding: 16px 22px;
        margin-bottom: 16px;
        border-radius: 14px;
        background: var(--cc-surface);
        box-shadow: var(--cc-shadow);
    }

    .cc-top-left {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .cc-brand-badge {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #0f766e, #14b8a6);
        color: #fff;
        display: grid;
        place-items: center;
        font-size: 20px;
        box-shadow: 0 6px 14px rgba(15, 118, 110, 0.28);
        flex-shrink: 0;
    }

    .cc-top-title h1 {
        margin: 0;
        font-size: 20px;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--cc-ink);
    }

    .cc-top-sub {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 3px;
        font-size: 12px;
        color: var(--cc-muted);
        font-weight: 600;
        flex-wrap: wrap;
    }

    .cc-live-dot {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #059669;
        font-weight: 700;
    }

    .cc-live-dot::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.22);
    }

    .cc-top-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .cc-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 16px;
        border-radius: 9px;
        font-size: 12.5px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.16s ease;
    }

    .cc-btn-primary {
        background: var(--cc-primary);
        color: #fff;
        box-shadow: 0 4px 12px rgba(15, 118, 110, 0.24);
    }

    .cc-btn-primary:hover {
        background: var(--cc-primary-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    .cc-btn-light {
        background: #f1f5f9;
        color: var(--cc-ink-2);
    }

    .cc-btn-light:hover {
        background: #e2e8f0;
        color: var(--cc-ink);
    }

    /* Integrated Navigation Tabs */
    .cc-nav-tabs {
        display: flex;
        align-items: center;
        gap: 6px;
        overflow-x: auto;
        padding: 6px;
        margin-bottom: 20px;
        border-radius: 12px;
        background: #e2e8f0;
        width: fit-content;
        max-width: 100%;
        scrollbar-width: thin;
    }

    .cc-nav-tab {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        gap: 8px;
        height: 38px;
        padding: 0 15px;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #475569;
        font: inherit;
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.16s ease;
    }

    .cc-nav-tab:hover {
        color: var(--cc-ink);
        background: rgba(255, 255, 255, 0.65);
    }

    .cc-nav-tab[aria-selected="true"] {
        background: var(--cc-surface);
        color: var(--cc-primary);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.1);
    }

    .cc-tab-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 22px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.08);
        color: #475569;
        font-size: 10.5px;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }

    .cc-nav-tab[aria-selected="true"] .cc-tab-pill {
        background: var(--cc-primary-soft);
        color: var(--cc-primary);
    }

    .cc-tab-panel[hidden] {
        display: none;
    }

    .cc-tab-panel {
        animation: ccFade 0.18s ease-out;
    }

    @keyframes ccFade {
        from { opacity: 0.45; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .cc-alert {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        margin-bottom: 18px;
        border-radius: 10px;
        background: #fffbeb;
        border-left: 4px solid #f59e0b;
        color: #92400e;
        font-size: 13px;
        font-weight: 600;
    }

    /* Section Header */
    .cc-sec-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 0 2px 12px;
    }

    .cc-sec-head h2 {
        margin: 0;
        font-size: 12.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #475569;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }

    .cc-sec-head h2 i {
        color: var(--cc-primary);
    }

    .cc-sec-head span {
        font-size: 11.5px;
        color: var(--cc-muted);
    }

    /* Executive Bento Strip */
    .cc-bento-top {
        display: grid;
        grid-template-columns: 420px minmax(0, 1fr);
        gap: 18px;
        margin-bottom: 24px;
    }

    .cc-card {
        background: var(--cc-surface);
        border-radius: 14px;
        box-shadow: var(--cc-shadow);
        overflow: hidden;
        min-width: 0;
    }

    .cc-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 18px;
        border-bottom: 1px solid #f1f5f9;
    }

    .cc-card-head h3 {
        margin: 0;
        font-size: 14px;
        font-weight: 800;
        color: var(--cc-ink);
    }

    .cc-card-head p {
        margin: 2px 0 0;
        font-size: 11.5px;
        color: var(--cc-muted);
    }

    .cc-pill {
        padding: 4px 10px;
        border-radius: 999px;
        background: var(--cc-primary-soft);
        color: var(--cc-primary);
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
    }

    .cc-ring-body {
        padding: 14px 16px 18px;
    }

    .cc-ring-canvas {
        position: relative;
        width: 100%;
        height: 215px;
    }

    .cc-ring-foot {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin-top: 8px;
        padding-top: 12px;
        border-top: 1px solid #f1f5f9;
        text-align: center;
    }

    .cc-ring-stat span {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--cc-muted);
    }

    .cc-ring-stat strong {
        display: block;
        margin-top: 2px;
        font-size: 17px;
        font-weight: 800;
        color: var(--cc-ink);
        font-variant-numeric: tabular-nums;
    }

    .cc-bento-right {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .cc-kpi-4 {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    .cc-kpi {
        background: var(--cc-surface);
        border-radius: 14px;
        padding: 16px 18px;
        box-shadow: var(--cc-shadow);
        position: relative;
        overflow: hidden;
        transition: transform 0.16s ease, box-shadow 0.16s ease;
    }

    .cc-kpi:hover {
        transform: translateY(-2px);
        box-shadow: var(--cc-shadow-hover);
    }

    .cc-kpi::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--kpi-c, var(--cc-primary));
    }

    .cc-kpi-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .cc-kpi-lbl {
        font-size: 11.5px;
        font-weight: 700;
        color: var(--cc-muted);
    }

    .cc-kpi-ic {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        background: var(--kpi-bg, #f0fdfa);
        color: var(--kpi-c, var(--cc-primary));
        display: grid;
        place-items: center;
        font-size: 15px;
    }

    .cc-kpi-val {
        display: block;
        margin-top: 10px;
        font-size: 28px;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -0.03em;
        font-variant-numeric: tabular-nums;
    }

    .cc-kpi-sub {
        display: block;
        margin-top: 6px;
        font-size: 11px;
        color: var(--cc-muted);
    }

    .cc-health-card {
        background: var(--cc-surface);
        border-radius: 14px;
        padding: 16px 20px;
        box-shadow: var(--cc-shadow);
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        flex: 1;
        align-items: center;
    }

    .cc-health-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding-right: 12px;
        border-right: 1px solid #f1f5f9;
    }

    .cc-health-item:last-child {
        border-right: 0;
        padding-right: 0;
    }

    .cc-health-ic {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: #f8fafc;
        color: var(--cc-primary);
        display: grid;
        place-items: center;
        font-size: 18px;
        flex-shrink: 0;
    }

    .cc-health-item span {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--cc-muted);
    }

    .cc-health-item strong {
        display: block;
        font-size: 20px;
        font-weight: 800;
        color: var(--cc-ink);
        line-height: 1.2;
    }

    .cc-health-item small a {
        color: var(--cc-primary);
        font-weight: 700;
        text-decoration: none;
        font-size: 11px;
    }

    /* All 6 Clinical Scales Live Matrix */
    .cc-scales-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .cc-scale-tile {
        background: var(--cc-surface);
        border-radius: 14px;
        padding: 15px 16px;
        box-shadow: var(--cc-shadow);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        border-top: 3px solid var(--sc-accent, var(--cc-primary));
        transition: transform 0.16s ease, box-shadow 0.16s ease;
    }

    .cc-scale-tile:hover {
        transform: translateY(-2px);
        box-shadow: var(--cc-shadow-hover);
    }

    .cc-scale-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
    }

    .cc-scale-code {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        font-weight: 800;
        color: var(--cc-ink);
    }

    .cc-scale-code i {
        color: var(--sc-accent, var(--cc-primary));
        font-size: 15px;
    }

    .cc-scale-7d {
        padding: 2px 7px;
        border-radius: 999px;
        background: #ecfdf5;
        color: #047857;
        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
    }

    .cc-scale-name {
        font-size: 11px;
        color: var(--cc-muted);
        margin-bottom: 10px;
        line-height: 1.3;
    }

    .cc-scale-num {
        font-size: 26px;
        font-weight: 800;
        color: var(--cc-ink);
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    .cc-scale-meta {
        margin-top: 6px;
        font-size: 11px;
        color: var(--cc-muted);
        display: flex;
        justify-content: space-between;
    }

    .cc-scale-meta strong {
        color: var(--cc-ink-2);
    }

    .cc-scale-btns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid #f1f5f9;
    }

    .cc-scale-btns a,
    .cc-scale-btns button {
        text-align: center;
        padding: 5px 6px;
        border-radius: 7px;
        font-size: 11px;
        font-weight: 700;
        text-decoration: none;
        border: 0;
        cursor: pointer;
        transition: all 0.14s ease;
    }

    .cc-scale-btns .is-new {
        background: var(--cc-primary-soft);
        color: var(--cc-primary);
    }

    .cc-scale-btns .is-new:hover {
        background: var(--cc-primary);
        color: #fff;
    }

    .cc-scale-btns .is-rec {
        background: #f8fafc;
        color: #475569;
    }

    .cc-scale-btns .is-rec:hover {
        background: #e2e8f0;
        color: var(--cc-ink);
    }

    /* Interactive Analytics Row */
    .cc-analytics-row {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
        gap: 18px;
        margin-bottom: 24px;
    }

    .cc-bar-box {
        position: relative;
        height: 290px;
        padding: 16px 18px 12px;
    }

    .cc-switcher {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        background: #f1f5f9;
        padding: 4px;
        border-radius: 9px;
    }

    .cc-switch-btn {
        border: 0;
        background: transparent;
        color: #475569;
        font-size: 11px;
        font-weight: 800;
        padding: 5px 9px;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .cc-switch-btn:hover {
        color: var(--cc-ink);
    }

    .cc-switch-btn.is-active {
        background: #fff;
        color: var(--cc-primary);
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
    }

    .cc-donut-box {
        position: relative;
        height: 290px;
        padding: 10px 14px 14px;
    }

    /* Individual Scale Tab Dedicated Workspace */
    .cc-inst-hero {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        flex-wrap: wrap;
        padding: 20px 24px;
        margin-bottom: 18px;
        border-radius: 14px;
        background: var(--cc-surface);
        border-left: 5px solid var(--inst-accent, var(--cc-primary));
        box-shadow: var(--cc-shadow);
    }

    .cc-inst-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .cc-inst-icon {
        width: 50px;
        height: 50px;
        border-radius: 13px;
        background: var(--cc-primary-soft);
        color: var(--inst-accent, var(--cc-primary));
        display: grid;
        place-items: center;
        font-size: 22px;
        flex-shrink: 0;
    }

    .cc-inst-hero h2 {
        margin: 0 0 4px;
        font-size: 18px;
        font-weight: 800;
        color: var(--cc-ink);
    }

    .cc-inst-hero p {
        margin: 0;
        font-size: 12.5px;
        color: var(--cc-muted);
    }

    .cc-inst-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .cc-inst-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.08fr) minmax(0, 0.92fr);
        gap: 18px;
        margin-top: 18px;
    }

    .cc-dist-body {
        padding: 18px 22px 22px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 18px;
    }

    .cc-dist-list {
        display: grid;
        gap: 13px;
    }

    .cc-dist-row-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12.5px;
        margin-bottom: 5px;
    }

    .cc-dist-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
        color: var(--cc-ink-2);
    }

    .cc-dist-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
    }

    .cc-dist-val {
        font-weight: 800;
        color: var(--cc-ink);
        font-variant-numeric: tabular-nums;
    }

    .cc-dist-track {
        height: 8px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
    }

    .cc-dist-fill {
        display: block;
        height: 100%;
        border-radius: 999px;
    }

    .cc-clinical-note {
        padding: 14px 16px;
        border-radius: 10px;
        background: #f8fafc;
        border-left: 3px solid var(--inst-accent, var(--cc-primary));
        font-size: 12.5px;
        line-height: 1.6;
        color: var(--cc-muted);
    }

    /* Bottom Operational Grid */
    .cc-ops-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.55fr) minmax(300px, 0.85fr);
        gap: 18px;
        align-items: start;
    }

    .cc-table {
        width: 100%;
        border-collapse: collapse;
    }

    .cc-table th {
        padding: 11px 16px;
        background: #f8fafc;
        color: #64748b;
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
    }

    .cc-table td {
        padding: 11px 16px;
        font-size: 12.5px;
        color: var(--cc-ink-2);
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .cc-table tbody tr:hover {
        background: #f8fafc;
    }

    .cc-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .cc-pt-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .cc-avatar {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        background: var(--cc-primary-soft);
        color: var(--cc-primary);
        font-size: 11px;
        font-weight: 800;
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }

    .cc-pt-name {
        display: block;
        font-weight: 700;
        color: var(--cc-ink);
        line-height: 1.25;
    }

    .cc-pt-phone {
        display: block;
        font-size: 11px;
        color: var(--cc-muted);
    }

    .cc-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .cc-status::before {
        content: "";
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    .cc-status.is-done {
        background: #ecfdf5;
        color: #047857;
    }

    .cc-status.is-pend {
        background: #fffbeb;
        color: #b45309;
    }

    .cc-launch-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        padding: 16px 18px 18px;
    }

    .cc-launch-tile {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 12px;
        border-radius: 10px;
        background: #f8fafc;
        color: var(--cc-ink-2);
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        transition: all 0.15s ease;
    }

    .cc-launch-tile i {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        background: #fff;
        color: var(--cc-primary);
        display: grid;
        place-items: center;
        font-size: 14px;
        box-shadow: 0 2px 5px rgba(15, 23, 42, 0.05);
        flex-shrink: 0;
    }

    .cc-launch-tile:hover {
        background: var(--cc-primary-soft);
        color: var(--cc-primary);
        transform: translateY(-1px);
    }

    .cc-empty {
        padding: 36px 18px;
        text-align: center;
        color: var(--cc-muted);
        font-size: 13px;
    }

    @media (max-width: 1280px) {
        .cc-scales-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .cc-bento-top { grid-template-columns: 1fr; }
    }

    @media (max-width: 960px) {
        .cc-kpi-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .cc-analytics-row, .cc-ops-grid, .cc-inst-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 640px) {
        .cc-wrap { padding: 14px 12px 32px; }
        .cc-scales-grid, .cc-health-card, .cc-launch-grid { grid-template-columns: 1fr; }
        .cc-health-item { border-right: 0; padding-right: 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; }
        .cc-health-item:last-child { border-bottom: 0; padding-bottom: 0; }
        .cc-table th:nth-child(3), .cc-table td:nth-child(3) { display: none; }
    }
</style>

<div class="cc-wrap">
    <!-- 1. Executive Command Bar -->
    <header class="cc-topbar">
        <div class="cc-top-left">
            <div class="cc-brand-badge"><i class="bi bi-hospital" aria-hidden="true"></i></div>
            <div class="cc-top-title">
                <h1>Clinical Command Center &mdash; <?php echo dash_e($_SESSION['name'] ?? 'Admin'); ?></h1>
                <div class="cc-top-sub">
                    <span class="cc-live-dot">Workspace Active</span>
                    <span><i class="bi bi-calendar3" aria-hidden="true"></i> <?php echo date('l, d F Y'); ?></span>
                    <span><i class="bi bi-layers" aria-hidden="true"></i> 6 Psychiatric Scales</span>
                </div>
            </div>
        </div>
        <div class="cc-top-actions">
            <a class="cc-btn cc-btn-light" href="public-assessment-form.php"><i class="bi bi-person-vcard" aria-hidden="true"></i> Patient Portal</a>
            <a class="cc-btn cc-btn-light" href="all-assessment-records.php"><i class="bi bi-table" aria-hidden="true"></i> All Assignments</a>
            <a class="cc-btn cc-btn-primary" href="assign-assessments.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Assign Assessment</a>
        </div>
    </header>

    <?php if (!empty($dashboard_errors)): ?>
        <div class="cc-alert" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span><?php echo dash_e(implode(' ', $dashboard_errors)); ?></span>
        </div>
    <?php endif; ?>

    <!-- 2. Integrated Navigation Tabs (Overview + 6 Individual Scale Workspaces) -->
    <nav class="cc-nav-tabs" role="tablist" aria-label="Dashboard sections">
        <button class="cc-nav-tab" id="tab-overview" type="button" role="tab" aria-selected="true" aria-controls="panel-overview" tabindex="0">
            <i class="bi bi-grid-1x2-fill" aria-hidden="true"></i> Overview
        </button>
        <?php foreach ($scale_tabs as $st): ?>
            <button class="cc-nav-tab" id="tab-<?php echo dash_e($st['id']); ?>" type="button" role="tab" aria-selected="false" aria-controls="panel-<?php echo dash_e($st['id']); ?>" tabindex="-1">
                <?php echo dash_e($st['code']); ?>
                <span class="cc-tab-pill"><?php echo $st['tab_count'] === null ? '—' : (int) $st['tab_count']; ?></span>
            </button>
        <?php endforeach; ?>
    </nav>

    <!-- ==================== OVERVIEW TAB PANEL ==================== -->
    <section class="cc-tab-panel" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" tabindex="0">
        <!-- Executive Bento Strip -->
        <div class="cc-bento-top">
            <!-- Left: Assignment Completion Ring with Callout Labels -->
            <section class="cc-card">
                <div class="cc-card-head">
                    <div>
                        <h3>Assignment Completion</h3>
                        <p>Completed vs awaiting responses</p>
                    </div>
                    <span class="cc-pill"><?php echo $completion_rate; ?>% Done</span>
                </div>
                <div class="cc-ring-body">
                    <div class="cc-ring-canvas">
                        <canvas id="ccCompletionRing" role="img" aria-label="Assignment completion doughnut chart"></canvas>
                    </div>
                    <div class="cc-ring-foot">
                        <div class="cc-ring-stat">
                            <span>Total</span>
                            <strong><?php echo (int) $stats['assigned']; ?></strong>
                        </div>
                        <div class="cc-ring-stat">
                            <span>Completed</span>
                            <strong style="color:#0f766e;"><?php echo (int) $stats['completed']; ?></strong>
                        </div>
                        <div class="cc-ring-stat">
                            <span>Pending</span>
                            <strong style="color:#d97706;"><?php echo (int) $stats['pending']; ?></strong>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Right: 4 KPIs + Workspace Health Strip -->
            <div class="cc-bento-right">
                <div class="cc-kpi-4">
                    <div class="cc-kpi" style="--kpi-c:#0f766e; --kpi-bg:#f0fdfa;">
                        <div class="cc-kpi-row">
                            <span class="cc-kpi-lbl">Patients</span>
                            <span class="cc-kpi-ic"><i class="bi bi-people-fill"></i></span>
                        </div>
                        <strong class="cc-kpi-val"><?php echo (int) $stats['patients']; ?></strong>
                        <span class="cc-kpi-sub">Registered in workspace</span>
                    </div>

                    <div class="cc-kpi" style="--kpi-c:#4f46e5; --kpi-bg:#eef2ff;">
                        <div class="cc-kpi-row">
                            <span class="cc-kpi-lbl">Assigned</span>
                            <span class="cc-kpi-ic"><i class="bi bi-clipboard2-check-fill"></i></span>
                        </div>
                        <strong class="cc-kpi-val"><?php echo (int) $stats['assigned']; ?></strong>
                        <span class="cc-kpi-sub">Total assessments</span>
                    </div>

                    <div class="cc-kpi" style="--kpi-c:#d97706; --kpi-bg:#fffbeb;">
                        <div class="cc-kpi-row">
                            <span class="cc-kpi-lbl">Awaiting</span>
                            <span class="cc-kpi-ic"><i class="bi bi-hourglass-split"></i></span>
                        </div>
                        <strong class="cc-kpi-val"><?php echo (int) $stats['pending']; ?></strong>
                        <span class="cc-kpi-sub">Follow-up needed</span>
                    </div>

                    <div class="cc-kpi" style="--kpi-c:#059669; --kpi-bg:#ecfdf5;">
                        <div class="cc-kpi-row">
                            <span class="cc-kpi-lbl">Completed</span>
                            <span class="cc-kpi-ic"><i class="bi bi-check-circle-fill"></i></span>
                        </div>
                        <strong class="cc-kpi-val"><?php echo (int) $stats['completed']; ?></strong>
                        <span class="cc-kpi-sub"><?php echo $completion_rate; ?>% rate</span>
                    </div>
                </div>

                <div class="cc-health-card">
                    <div class="cc-health-item">
                        <span class="cc-health-ic"><i class="bi bi-journals"></i></span>
                        <div>
                            <span>Assessment Library</span>
                            <strong><?php echo $workspace_stats['assessment_catalog'] === null ? '—' : (int) $workspace_stats['assessment_catalog']; ?></strong>
                            <small style="color:var(--cc-muted);font-size:11px;">Configured scales</small>
                        </div>
                    </div>
                    <div class="cc-health-item">
                        <span class="cc-health-ic"><i class="bi bi-file-earmark-medical"></i></span>
                        <div>
                            <span>Submitted Reports</span>
                            <strong><?php echo $workspace_stats['responses'] === null ? '—' : (int) $workspace_stats['responses']; ?></strong>
                            <small style="color:var(--cc-muted);font-size:11px;">Saved clinical responses</small>
                        </div>
                    </div>
                    <div class="cc-health-item">
                        <span class="cc-health-ic"><i class="bi bi-journal-text"></i></span>
                        <div>
                            <span>Question Bank</span>
                            <strong><?php echo $workspace_stats['questions'] === null ? '—' : (int) $workspace_stats['questions']; ?></strong>
                            <small><a href="view-assessment-questions.php">Manage questions <i class="bi bi-arrow-up-right"></i></a></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- All 6 Clinical Scales Live Matrix -->
        <div class="cc-sec-head">
            <h2><i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> Clinical Scales Matrix (All 6 Instruments)</h2>
            <span>Click Details to open any scale's dedicated workspace</span>
        </div>

        <div class="cc-scales-grid">
            <?php foreach ($scale_tabs as $sc): ?>
                <article class="cc-scale-tile" style="--sc-accent: <?php echo dash_e($sc['accent']); ?>;">
                    <div>
                        <div class="cc-scale-top">
                            <span class="cc-scale-code">
                                <i class="bi <?php echo dash_e($sc['icon']); ?>" aria-hidden="true"></i>
                                <?php echo dash_e($sc['code']); ?>
                            </span>
                            <span class="cc-scale-7d">+<?php echo (int) $sc['recent']; ?> (7d)</span>
                        </div>
                        <div class="cc-scale-name"><?php echo dash_e($sc['short_name']); ?></div>
                        <div class="cc-scale-num"><?php echo $sc['tab_count'] === null ? '—' : (int) $sc['tab_count']; ?></div>
                        <div class="cc-scale-meta">
                            <span><?php echo dash_e($sc['meta_label']); ?></span>
                            <strong><?php echo dash_e($sc['meta_val']); ?></strong>
                        </div>
                    </div>
                    <div class="cc-scale-btns">
                        <button type="button" class="is-new cc-open-tab-btn" data-target-tab="tab-<?php echo dash_e($sc['id']); ?>">Details</button>
                        <a class="is-rec" href="<?php echo dash_e($sc['rec_url']); ?>">Records</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Interactive Analytics Row -->
        <div class="cc-sec-head">
            <h2><i class="bi bi-pie-chart-fill" aria-hidden="true"></i> Clinical Analytics &amp; Severity Distribution</h2>
            <span>Switch any scale to inspect slice-by-slice breakdown</span>
        </div>

        <div class="cc-analytics-row">
            <section class="cc-card">
                <div class="cc-card-head">
                    <div>
                        <h3>Assessment Volume vs Last 7 Days</h3>
                        <p>Comparison across BAI, BDI, BPRS, PANSS, YMRS, and ACE-III</p>
                    </div>
                    <span class="cc-pill">6 Scales</span>
                </div>
                <div class="cc-bar-box">
                    <canvas id="ccVolumeBarChart" role="img" aria-label="Assessment volume comparison bar chart"></canvas>
                </div>
            </section>

            <section class="cc-card">
                <div class="cc-card-head" style="flex-wrap: wrap;">
                    <div>
                        <h3 id="ccScalePieTitle">BPRS Severity Breakdown</h3>
                        <p id="ccScalePieSub">Distribution of saved ratings by clinical severity band</p>
                    </div>
                    <div class="cc-switcher" role="tablist" aria-label="Select scale chart">
                        <button type="button" class="cc-switch-btn" data-scale="bai">BAI</button>
                        <button type="button" class="cc-switch-btn" data-scale="bdi">BDI</button>
                        <button type="button" class="cc-switch-btn is-active" data-scale="bprs">BPRS</button>
                        <button type="button" class="cc-switch-btn" data-scale="panss">PANSS</button>
                        <button type="button" class="cc-switch-btn" data-scale="ymrs">YMRS</button>
                        <button type="button" class="cc-switch-btn" data-scale="ace_iii">ACE-III</button>
                    </div>
                </div>
                <div class="cc-donut-box">
                    <canvas id="ccScalePieChart" role="img" aria-label="Scale breakdown doughnut chart with callout labels"></canvas>
                </div>
            </section>
        </div>

        <!-- Operational Grid: Recent Assignments + Quick Launchpad -->
        <div class="cc-ops-grid">
            <section class="cc-card">
                <div class="cc-card-head">
                    <div>
                        <h3>Recent Patient Assignments</h3>
                        <p>Latest 8 assigned assessments in your workspace</p>
                    </div>
                    <a href="assigned-assessments.php" style="font-size:12px;font-weight:700;color:var(--cc-primary);text-decoration:none;">View all <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if (empty($recent_assignments)): ?>
                    <div class="cc-empty">No assessment assignments have been created yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="cc-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Assessment</th>
                                    <th>Assigned Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_assignments as $row):
                                    $p_name = trim($row['patient_name']) ?: 'Unnamed patient';
                                    $is_done = ($row['status'] === 'completed');
                                ?>
                                    <tr>
                                        <td>
                                            <div class="cc-pt-cell">
                                                <span class="cc-avatar"><?php echo dash_e(dash_initials($p_name)); ?></span>
                                                <div>
                                                    <span class="cc-pt-name"><?php echo dash_e($p_name); ?></span>
                                                    <span class="cc-pt-phone"><?php echo dash_e($row['phone'] ?? ''); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-weight:600;color:#1e293b;"><?php echo dash_e($row['title']); ?></td>
                                        <td><?php echo !empty($row['assigned_date']) ? date('d M Y', strtotime($row['assigned_date'])) : 'Recently'; ?></td>
                                        <td>
                                            <span class="cc-status <?php echo $is_done ? 'is-done' : 'is-pend'; ?>">
                                                <?php echo $is_done ? 'Completed' : 'Pending'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="cc-card">
                <div class="cc-card-head">
                    <div>
                        <h3>Clinical Launchpad</h3>
                        <p>Instant access to forms &amp; registries</p>
                    </div>
                </div>
                <div class="cc-launch-grid">
                    <a class="cc-launch-tile" href="bdi-assessments.php"><i class="bi bi-clipboard2-heart"></i> New BDI</a>
                    <a class="cc-launch-tile" href="bai-assessments.php"><i class="bi bi-heart-pulse"></i> New BAI</a>
                    <a class="cc-launch-tile" href="bprs-assessments.php"><i class="bi bi-clipboard2-pulse"></i> New BPRS</a>
                    <a class="cc-launch-tile" href="panss-assessments.php"><i class="bi bi-activity"></i> New PANSS</a>
                    <a class="cc-launch-tile" href="ymrs-assessments.php"><i class="bi bi-speedometer2"></i> New YMRS</a>
                    <a class="cc-launch-tile" href="ace-iii-assessments.php"><i class="bi bi-cpu"></i> New ACE-III</a>
                    <a class="cc-launch-tile" href="assigned-assessments.php"><i class="bi bi-table"></i> All Records</a>
                    <a class="cc-launch-tile" href="view-assessment-questions.php"><i class="bi bi-journal-text"></i> Question Bank</a>
                </div>
            </aside>
        </div>
    </section>

    <!-- ==================== INDIVIDUAL SCALE TAB PANELS ==================== -->
    <?php foreach ($scale_tabs as $scale):
        $sid = $scale['id'];
        $pie_cfg = $tab_charts_config[$sid];
        $dist_total = array_sum($pie_cfg['values']);
    ?>
        <section class="cc-tab-panel" id="panel-<?php echo dash_e($sid); ?>" role="tabpanel" aria-labelledby="tab-<?php echo dash_e($sid); ?>" tabindex="0" hidden>
            <!-- Instrument Hero -->
            <header class="cc-inst-hero" style="--inst-accent: <?php echo dash_e($scale['accent']); ?>;">
                <div class="cc-inst-left">
                    <span class="cc-inst-icon"><i class="bi <?php echo dash_e($scale['icon']); ?>" aria-hidden="true"></i></span>
                    <div>
                        <h2><?php echo dash_e($scale['title']); ?></h2>
                        <p><?php echo dash_e($scale['description']); ?></p>
                    </div>
                </div>
                <div class="cc-inst-actions">
                    <a class="cc-btn cc-btn-primary" href="<?php echo dash_e($scale['new_url']); ?>">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i> <?php echo dash_e($scale['new_label']); ?>
                    </a>
                    <a class="cc-btn cc-btn-light" href="<?php echo dash_e($scale['rec_url']); ?>">
                        <i class="bi bi-table" aria-hidden="true"></i> <?php echo dash_e($scale['rec_label']); ?>
                    </a>
                </div>
            </header>

            <!-- 4-Column Scale KPIs -->
            <div class="cc-kpi-4">
                <?php foreach ($scale['metrics'] as $metric): ?>
                    <div class="cc-kpi" style="--kpi-c: <?php echo dash_e($scale['accent']); ?>; --kpi-bg: #f8fafc;">
                        <div class="cc-kpi-row">
                            <span class="cc-kpi-lbl"><?php echo dash_e($metric[0]); ?></span>
                            <span class="cc-kpi-ic"><i class="bi <?php echo dash_e($metric[2]); ?>" aria-hidden="true"></i></span>
                        </div>
                        <strong class="cc-kpi-val"><?php echo $metric[1] === null ? '—' : dash_e($metric[1]); ?></strong>
                        <span class="cc-kpi-sub"><?php echo dash_e($metric[3]); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 2-Column Deep-Dive: Callout Doughnut + Distribution Bars -->
            <div class="cc-inst-grid" style="--inst-accent: <?php echo dash_e($scale['accent']); ?>;">
                <section class="cc-card">
                    <div class="cc-card-head">
                        <div>
                            <h3><?php echo dash_e($pie_cfg['title']); ?></h3>
                            <p><?php echo dash_e($pie_cfg['subtitle']); ?></p>
                        </div>
                        <span class="cc-pill"><?php echo dash_e($scale['badge']); ?></span>
                    </div>
                    <div class="cc-donut-box">
                        <canvas id="tabPieChart_<?php echo dash_e($sid); ?>" role="img" aria-label="<?php echo dash_e($pie_cfg['title']); ?>"></canvas>
                    </div>
                </section>

                <section class="cc-card">
                    <div class="cc-card-head">
                        <div>
                            <h3>Detailed Distribution &amp; Clinical Notes</h3>
                            <p>Percentage share across each category</p>
                        </div>
                    </div>
                    <div class="cc-dist-body">
                        <div class="cc-dist-list">
                            <?php foreach ($pie_cfg['labels'] as $idx => $lbl):
                                $val = (float) ($pie_cfg['values'][$idx] ?? 0);
                                $pct = $dist_total > 0 ? round(($val / $dist_total) * 100) : 0;
                                $clr = $pie_cfg['colors'][$idx] ?? '#0f766e';
                            ?>
                                <div>
                                    <div class="cc-dist-row-top">
                                        <span class="cc-dist-label">
                                            <span class="cc-dist-dot" style="background: <?php echo dash_e($clr); ?>;"></span>
                                            <?php echo dash_e($lbl); ?>
                                        </span>
                                        <span class="cc-dist-val"><?php echo dash_e($val); ?> (<?php echo $pct; ?>%)</span>
                                    </div>
                                    <div class="cc-dist-track">
                                        <span class="cc-dist-fill" style="width: <?php echo $pct; ?>%; background: <?php echo dash_e($clr); ?>;"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="cc-clinical-note">
                            <strong style="display:block;color:var(--cc-ink);margin-bottom:4px;"><?php echo dash_e($scale['summary_title']); ?></strong>
                            <?php echo dash_e($scale['summary']); ?>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    const assignmentData = <?php echo json_encode($assignment_chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const assessmentData = <?php echo json_encode($assessment_chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const tabChartsConfig = <?php echo json_encode($tab_charts_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const completionRate = <?php echo (int) $completion_rate; ?>;
    const renderedTabCharts = {};

    // Reusable Callout Labels + Center Text Plugin
    function createCalloutPlugin(getCenterText) {
        return {
            id: 'calloutLabelsPlugin',
            afterDatasetsDraw: function (chart) {
                const ctx = chart.ctx;
                const meta = chart.getDatasetMeta(0);
                const data = chart.data.datasets[0].data;
                const labels = chart.data.labels;
                const colors = chart.data.datasets[0].backgroundColor;
                if (!meta.data || !meta.data.length) return;

                ctx.save();

                const cx = meta.data[0].x;
                const cy = meta.data[0].y;
                const center = getCenterText(chart);
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = '#0f172a';
                ctx.font = '800 24px Inter, system-ui, sans-serif';
                ctx.fillText(center.main, cx, cy - 8);
                ctx.fillStyle = '#64748b';
                ctx.font = '600 10.5px Inter, system-ui, sans-serif';
                ctx.fillText(center.sub, cx, cy + 12);

                const rightItems = [];
                const leftItems = [];
                meta.data.forEach(function (arc, idx) {
                    const val = Number(data[idx] || 0);
                    if (val <= 0) return;
                    const angle = (arc.startAngle + arc.endAngle) / 2;
                    const cos = Math.cos(angle);
                    const sin = Math.sin(angle);
                    const item = {
                        label: String(labels[idx] || ''),
                        val: val,
                        color: Array.isArray(colors) ? colors[idx] : '#0f766e',
                        x1: arc.x + cos * (arc.outerRadius + 2),
                        y1: arc.y + sin * (arc.outerRadius + 2),
                        x2: arc.x + cos * (arc.outerRadius + 11),
                        y2: arc.y + sin * (arc.outerRadius + 11),
                        isRight: cos >= 0
                    };
                    if (item.isRight) rightItems.push(item);
                    else leftItems.push(item);
                });

                function drawSide(items) {
                    items.sort(function (a, b) { return a.y2 - b.y2; });
                    const minGap = 26;
                    for (let i = 1; i < items.length; i++) {
                        if (items[i].y2 - items[i - 1].y2 < minGap) {
                            items[i].y2 = items[i - 1].y2 + minGap;
                        }
                    }

                    ctx.textBaseline = 'middle';
                    items.forEach(function (item) {
                        const x3 = item.x2 + (item.isRight ? 8 : -8);
                        ctx.beginPath();
                        ctx.moveTo(item.x1, item.y1);
                        ctx.lineTo(item.x2, item.y2);
                        ctx.lineTo(x3, item.y2);
                        ctx.strokeStyle = item.color;
                        ctx.lineWidth = 1.4;
                        ctx.stroke();

                        const tx = x3 + (item.isRight ? 4 : -4);
                        ctx.textAlign = item.isRight ? 'left' : 'right';
                        ctx.fillStyle = '#0f172a';
                        ctx.font = '700 10.5px Inter, system-ui, sans-serif';
                        ctx.fillText(item.label, tx, item.y2 - 5);

                        ctx.fillStyle = '#64748b';
                        ctx.font = '600 10px Inter, system-ui, sans-serif';
                        ctx.fillText('(' + item.val + ')', tx, item.y2 + 6);
                    });
                }

                drawSide(rightItems);
                drawSide(leftItems);
                ctx.restore();
            }
        };
    }

    // 1. Overview Completion Ring Chart
    const ringCanvas = document.getElementById('ccCompletionRing');
    if (ringCanvas) {
        const hasAssign = (assignmentData[0] + assignmentData[1]) > 0;
        new Chart(ringCanvas, {
            type: 'doughnut',
            data: {
                labels: hasAssign ? ['Completed', 'Pending'] : ['No Data'],
                datasets: [{
                    data: hasAssign ? assignmentData : [1],
                    backgroundColor: hasAssign ? ['#0f766e', '#f59e0b'] : ['#e2e8f0'],
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: hasAssign ? 5 : 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '66%',
                layout: { padding: { top: 18, bottom: 18, left: 82, right: 82 } },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: hasAssign }
                }
            },
            plugins: [createCalloutPlugin(function () {
                return { main: completionRate + '%', sub: 'Completed' };
            })]
        });
    }

    // 2. Overview Volume Bar Chart
    const barCanvas = document.getElementById('ccVolumeBarChart');
    if (barCanvas) {
        new Chart(barCanvas, {
            type: 'bar',
            data: {
                labels: assessmentData.labels,
                datasets: [
                    {
                        label: 'Total Records',
                        data: assessmentData.total,
                        backgroundColor: '#0f766e',
                        borderRadius: 6,
                        maxBarThickness: 28
                    },
                    {
                        label: 'Last 7 Days',
                        data: assessmentData.recent,
                        backgroundColor: '#38bdf8',
                        borderRadius: 6,
                        maxBarThickness: 28
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { usePointStyle: true, boxWidth: 8, font: { size: 11, weight: '600' }, color: '#475569' }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#334155', font: { size: 11.5, weight: '700' } },
                        border: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#94a3b8', font: { size: 11 } },
                        grid: { color: '#f1f5f9' },
                        border: { display: false }
                    }
                }
            }
        });
    }

    // 3. Overview Interactive Scale Breakdown Doughnut
    const pieCanvas = document.getElementById('ccScalePieChart');
    const pieTitleEl = document.getElementById('ccScalePieTitle');
    const pieSubEl = document.getElementById('ccScalePieSub');
    let scalePieInstance = null;

    function buildScaleDoughnut(canvas, scaleKey) {
        const cfg = tabChartsConfig[scaleKey];
        if (!canvas || !cfg) return null;
        const sum = cfg.values.reduce(function (acc, v) { return acc + Number(v || 0); }, 0);
        const hasData = sum > 0;

        return new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: hasData ? cfg.labels : ['No records yet'],
                datasets: [{
                    data: hasData ? cfg.values : [1],
                    backgroundColor: hasData ? cfg.colors : ['#e2e8f0'],
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: hasData ? 5 : 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                layout: { padding: { top: 24, bottom: 24, left: 118, right: 118 } },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: hasData,
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.label + ': ' + ctx.raw + ' ' + cfg.unit;
                            }
                        }
                    }
                }
            },
            plugins: [createCalloutPlugin(function () {
                return {
                    main: hasData ? String(Math.round(sum * 10) / 10) : '0',
                    sub: cfg.unit
                };
            })]
        });
    }

    function renderOverviewScaleBreakdown(scaleKey) {
        const cfg = tabChartsConfig[scaleKey];
        if (!cfg) return;
        if (pieTitleEl) pieTitleEl.textContent = cfg.title;
        if (pieSubEl) pieSubEl.textContent = cfg.subtitle;
        if (scalePieInstance) scalePieInstance.destroy();
        scalePieInstance = buildScaleDoughnut(pieCanvas, scaleKey);
    }

    document.querySelectorAll('.cc-switch-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.cc-switch-btn').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            renderOverviewScaleBreakdown(btn.getAttribute('data-scale'));
        });
    });

    renderOverviewScaleBreakdown('bprs');

    // 4. Main Tab Switching (Overview + Individual Scale Tabs)
    const navTabs = Array.from(document.querySelectorAll('.cc-nav-tab[role="tab"]'));
    const tabPanels = Array.from(document.querySelectorAll('.cc-tab-panel[role="tabpanel"]'));

    function activateTab(tab, moveFocus) {
        const panelId = tab.getAttribute('aria-controls');
        navTabs.forEach(function (t) {
            const active = t === tab;
            t.setAttribute('aria-selected', active ? 'true' : 'false');
            t.tabIndex = active ? 0 : -1;
        });
        tabPanels.forEach(function (panel) {
            panel.hidden = panel.id !== panelId;
        });

        const scaleId = panelId.replace('panel-', '');
        if (tabChartsConfig[scaleId] && !renderedTabCharts[scaleId]) {
            const tabCanvas = document.getElementById('tabPieChart_' + scaleId);
            renderedTabCharts[scaleId] = buildScaleDoughnut(tabCanvas, scaleId);
        }

        if (moveFocus) tab.focus();
        window.dispatchEvent(new Event('resize'));
    }

    navTabs.forEach(function (tab, idx) {
        tab.addEventListener('click', function () {
            activateTab(tab, false);
        });
        tab.addEventListener('keydown', function (e) {
            let next = idx;
            if (e.key === 'ArrowRight') next = (idx + 1) % navTabs.length;
            else if (e.key === 'ArrowLeft') next = (idx - 1 + navTabs.length) % navTabs.length;
            else if (e.key === 'Home') next = 0;
            else if (e.key === 'End') next = navTabs.length - 1;
            else return;
            e.preventDefault();
            activateTab(navTabs[next], true);
        });
    });

    // Matrix "Details" buttons jump directly to that scale's tab
    document.querySelectorAll('.cc-open-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('data-target-tab');
            const targetTab = document.getElementById(targetId);
            if (targetTab) {
                activateTab(targetTab, false);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });
});
</script>

<?php require 'layout/footer.php'; ob_end_flush(); ?>