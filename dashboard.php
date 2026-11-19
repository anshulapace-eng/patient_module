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
    'bprs' => ['total' => null, 'recent' => 0, 'patients' => null, 'avg_score' => null],
    'panss' => ['total' => null, 'recent' => 0, 'patients' => null, 'avg_score' => null],
    'ymrs' => ['total' => null, 'recent' => 0, 'patients' => null, 'avg_score' => null],
    'ace_iii' => ['total' => null, 'recent' => 0, 'patients' => null, 'avg_score' => null],
];
$module_pie_data = [
    'bprs' => [
        'title' => 'BPRS Severity Breakdown',
        'subtitle' => 'Distribution of saved ratings by clinical severity band',
        'labels' => ['Normal / Minimal (≤30)', 'Mildly Ill (31–40)', 'Moderately Ill (41–52)', 'Severely Ill (53+)'],
        'values' => [0, 0, 0, 0],
        'colors' => ['#0f766e', '#f59e0b', '#f97316', '#dc2626'],
        'unit' => 'records',
    ],
    'panss' => [
        'title' => 'PANSS Subscale Share',
        'subtitle' => 'Average points across Positive, Negative & General scales',
        'labels' => ['Positive Scale (P)', 'Negative Scale (N)', 'General Psychopathology (G)'],
        'values' => [0, 0, 0],
        'colors' => ['#4f46e5', '#0ea5e9', '#0f766e'],
        'unit' => 'avg pts',
    ],
    'ymrs' => [
        'title' => 'YMRS Severity Breakdown',
        'subtitle' => 'Distribution of saved mania assessments by severity',
        'labels' => ['Normal / Remission (0–12)', 'Minimal / Mild (13–19)', 'Moderate Mania (20–25)', 'Severe Mania (26+)'],
        'values' => [0, 0, 0, 0],
        'colors' => ['#0f766e', '#f59e0b', '#f97316', '#dc2626'],
        'unit' => 'records',
    ],
    'ace_iii' => [
        'title' => 'ACE-III Cognitive Breakdown',
        'subtitle' => 'Completed cognitive interpretations vs pending/draft',
        'labels' => ['Normal (88–100)', 'Borderline / MCI (83–87)', 'Abnormal (≤82)', 'Pending / Draft'],
        'values' => [0, 0, 0, 0],
        'colors' => ['#0f766e', '#f59e0b', '#dc2626', '#94a3b8'],
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
    ? round(((int) $stats['completed'] / (int) $stats['assigned']) * 100)
    : 0;
$assignment_chart_data = [
    (int) $stats['completed'],
    (int) $stats['pending'],
];
$assessment_chart_data = [
    'labels' => ['BAI', 'BDI', 'BPRS', 'PANSS', 'YMRS', 'ACE-III'],
    'total' => [
        $beck_stats_loaded ? $beck_stats['bai']['records'] : null,
        $beck_stats_loaded ? $beck_stats['bdi']['records'] : null,
        $module_stats['bprs']['total'] === null ? null : (int) $module_stats['bprs']['total'],
        $module_stats['panss']['total'] === null ? null : (int) $module_stats['panss']['total'],
        $module_stats['ymrs']['total'] === null ? null : (int) $module_stats['ymrs']['total'],
        $module_stats['ace_iii']['total'] === null ? null : (int) $module_stats['ace_iii']['total'],
    ],
    'recent' => [
        $beck_stats_loaded ? $beck_stats['bai']['recent'] : null,
        $beck_stats_loaded ? $beck_stats['bdi']['recent'] : null,
        $module_stats['bprs']['total'] === null ? null : (int) $module_stats['bprs']['recent'],
        $module_stats['panss']['total'] === null ? null : (int) $module_stats['panss']['recent'],
        $module_stats['ymrs']['total'] === null ? null : (int) $module_stats['ymrs']['recent'],
        $module_stats['ace_iii']['total'] === null ? null : (int) $module_stats['ace_iii']['recent'],
    ],
];

// Build per-tab pie chart config
$tab_charts_config = [
    'bai' => [
        'title' => 'BAI Assignment Completion',
        'subtitle' => 'Completed vs awaiting patient responses',
        'labels' => ['Completed', 'Awaiting Response'],
        'values' => $beck_stats_loaded ? [$beck_stats['bai']['completed'], $beck_stats['bai']['pending']] : [0, 0],
        'colors' => ['#0f766e', '#f59e0b'],
        'unit' => 'assignments',
    ],
    'bdi' => [
        'title' => 'BDI Assignment Completion',
        'subtitle' => 'Completed vs awaiting patient responses',
        'labels' => ['Completed', 'Awaiting Response'],
        'values' => $beck_stats_loaded ? [$beck_stats['bdi']['completed'], $beck_stats['bdi']['pending']] : [0, 0],
        'colors' => ['#4f46e5', '#f59e0b'],
        'unit' => 'assignments',
    ],
    'bprs' => $module_pie_data['bprs'],
    'panss' => $module_pie_data['panss'],
    'ymrs' => $module_pie_data['ymrs'],
    'ace_iii' => $module_pie_data['ace_iii'],
];

$scale_tabs = [
    [
        'id' => 'bai',
        'title' => 'Beck Anxiety Inventory (BAI)',
        'icon' => 'bi-heart-pulse',
        'description' => 'Review BAI assessment assignments and submitted responses. Tracks workflow completion and recent activity.',
        'badge' => $beck_stats_loaded ? $beck_stats['bai']['records'] . ' responses' : 'Data unavailable',
        'metrics' => [
            ['Assigned', $beck_stats_loaded ? $beck_stats['bai']['assigned'] : null, 'bi-send'],
            ['Completed', $beck_stats_loaded ? $beck_stats['bai']['completed'] : null, 'bi-check2-circle'],
            ['Awaiting', $beck_stats_loaded ? $beck_stats['bai']['pending'] : null, 'bi-hourglass-split'],
            ['Submitted responses', $beck_stats_loaded ? $beck_stats['bai']['records'] : null, 'bi-file-earmark-check'],
        ],
        'summary_title' => 'Workflow Summary',
        'summary' => $beck_stats_loaded
            ? $beck_stats['bai']['recent'] . ' responses submitted in the last 7 days. Use assignment records to follow up on pending patients or open the patient assessment workflow.'
            : 'BAI response activity could not be loaded right now.',
        'actions' => [
            ['Patient assessments', 'public-assessment-form.php', 'bi-person-vcard'],
            ['Assignment records', 'assigned-assessments.php', 'bi-table'],
        ],
    ],
    [
        'id' => 'bdi',
        'title' => 'Beck Depression Inventory (BDI)',
        'icon' => 'bi-clipboard2-heart',
        'description' => 'Review BDI assessment assignments and submitted responses. Tracks workflow completion and recent activity.',
        'badge' => $beck_stats_loaded ? $beck_stats['bdi']['records'] . ' responses' : 'Data unavailable',
        'metrics' => [
            ['Assigned', $beck_stats_loaded ? $beck_stats['bdi']['assigned'] : null, 'bi-send'],
            ['Completed', $beck_stats_loaded ? $beck_stats['bdi']['completed'] : null, 'bi-check2-circle'],
            ['Awaiting', $beck_stats_loaded ? $beck_stats['bdi']['pending'] : null, 'bi-hourglass-split'],
            ['Submitted responses', $beck_stats_loaded ? $beck_stats['bdi']['records'] : null, 'bi-file-earmark-check'],
        ],
        'summary_title' => 'Workflow Summary',
        'summary' => $beck_stats_loaded
            ? $beck_stats['bdi']['recent'] . ' responses submitted in the last 7 days. Use assignment records to track response status and follow-ups.'
            : 'BDI response activity could not be loaded right now.',
        'actions' => [
            ['Patient assessments', 'public-assessment-form.php', 'bi-person-vcard'],
            ['Assignment records', 'assigned-assessments.php', 'bi-table'],
        ],
    ],
    [
        'id' => 'bprs',
        'title' => 'Brief Psychiatric Rating Scale (BPRS)',
        'icon' => 'bi-clipboard2-pulse',
        'description' => '18-item clinician-rated psychiatric scale with severity distribution and item-level score reports.',
        'badge' => $module_stats['bprs']['total'] === null ? 'Data unavailable' : (int) $module_stats['bprs']['total'] . ' saved ratings',
        'metrics' => [
            ['Saved ratings', $module_stats['bprs']['total'], 'bi-file-earmark-check'],
            ['Added in 7 days', $module_stats['bprs']['total'] === null ? null : $module_stats['bprs']['recent'], 'bi-calendar2-week'],
            ['Patients rated', $module_stats['bprs']['patients'], 'bi-people'],
            ['Avg. BPRS score', $module_stats['bprs']['avg_score'] !== null ? $module_stats['bprs']['avg_score'] . '/126' : null, 'bi-bar-chart'],
        ],
        'summary_title' => 'Clinical Overview',
        'summary' => $module_stats['bprs']['total'] === null
            ? 'BPRS records are not available right now.'
            : 'The pie chart shows how saved BPRS ratings fall across clinical severity bands (Normal, Mild, Moderate, Severe). Open records for full 18-item reports.',
        'actions' => [
            ['New BPRS rating', 'bprs-assessments.php', 'bi-plus-circle'],
            ['BPRS records', 'bprs-assessment-records.php', 'bi-table'],
        ],
    ],
    [
        'id' => 'panss',
        'title' => 'Positive and Negative Syndrome Scale (PANSS)',
        'icon' => 'bi-activity',
        'description' => '30-item clinician-rated scale tracking Positive, Negative, General Psychopathology, and Composite scores.',
        'badge' => $module_stats['panss']['total'] === null ? 'Data unavailable' : (int) $module_stats['panss']['total'] . ' saved ratings',
        'metrics' => [
            ['Saved ratings', $module_stats['panss']['total'], 'bi-file-earmark-check'],
            ['Added in 7 days', $module_stats['panss']['total'] === null ? null : $module_stats['panss']['recent'], 'bi-calendar2-week'],
            ['Patients rated', $module_stats['panss']['patients'], 'bi-people'],
            ['Avg. Composite', $module_stats['panss']['avg_score'] !== null ? ($module_stats['panss']['avg_score'] > 0 ? '+' : '') . $module_stats['panss']['avg_score'] : null, 'bi-calculator'],
        ],
        'summary_title' => 'Clinical Overview',
        'summary' => $module_stats['panss']['total'] === null
            ? 'PANSS records are not available right now.'
            : 'The pie chart compares average scores across Positive (P), Negative (N), and General Psychopathology (G) subscales. Open records for detailed item ratings.',
        'actions' => [
            ['New PANSS rating', 'panss-assessments.php', 'bi-plus-circle'],
            ['PANSS records', 'panss-assessment-records.php', 'bi-table'],
        ],
    ],
    [
        'id' => 'ymrs',
        'title' => 'Young Mania Rating Scale (YMRS)',
        'icon' => 'bi-speedometer2',
        'description' => '11-item clinician-rated mania evaluation with live severity categorization (0–60 scale).',
        'badge' => $module_stats['ymrs']['total'] === null ? 'Data unavailable' : (int) $module_stats['ymrs']['total'] . ' saved ratings',
        'metrics' => [
            ['Saved ratings', $module_stats['ymrs']['total'], 'bi-file-earmark-check'],
            ['Added in 7 days', $module_stats['ymrs']['total'] === null ? null : $module_stats['ymrs']['recent'], 'bi-calendar2-week'],
            ['Patients rated', $module_stats['ymrs']['patients'], 'bi-people'],
            ['Avg. YMRS score', $module_stats['ymrs']['avg_score'] !== null ? $module_stats['ymrs']['avg_score'] . '/60' : null, 'bi-bar-chart'],
        ],
        'summary_title' => 'Clinical Overview',
        'summary' => $module_stats['ymrs']['total'] === null
            ? 'YMRS records are not available right now.'
            : 'The pie chart breaks down saved YMRS assessments into Normal/Remission, Minimal/Mild, Moderate, and Severe Mania bands.',
        'actions' => [
            ['New YMRS rating', 'ymrs-assessments.php', 'bi-plus-circle'],
            ['YMRS records', 'ymrs-assessment-records.php', 'bi-table'],
        ],
    ],
    [
        'id' => 'ace_iii',
        'title' => "Addenbrooke's Cognitive Examination (ACE-III)",
        'icon' => 'bi-cpu',
        'description' => '100-point cognitive examination across Attention, Memory, Fluency, Language, and Visuospatial domains.',
        'badge' => $module_stats['ace_iii']['total'] === null ? 'Data unavailable' : (int) $module_stats['ace_iii']['total'] . ' saved records',
        'metrics' => [
            ['Saved records', $module_stats['ace_iii']['total'], 'bi-file-earmark-check'],
            ['Added in 7 days', $module_stats['ace_iii']['total'] === null ? null : $module_stats['ace_iii']['recent'], 'bi-calendar2-week'],
            ['Patients assessed', $module_stats['ace_iii']['patients'], 'bi-people'],
            ['Avg. Completed', $module_stats['ace_iii']['avg_score'] !== null ? $module_stats['ace_iii']['avg_score'] . '/100' : null, 'bi-award'],
        ],
        'summary_title' => 'Clinical Overview',
        'summary' => $module_stats['ace_iii']['total'] === null
            ? 'ACE-III records are not available right now.'
            : 'The pie chart displays completed cognitive function bands (Normal, Borderline/MCI, Abnormal) alongside Pending/Draft assessments awaiting clinician scoring.',
        'actions' => [
            ['New ACE-III assessment', 'ace-iii-assessments.php', 'bi-plus-circle'],
            ['ACE-III records', 'ace-iii-assessment-records.php', 'bi-table'],
        ],
    ],
];

require 'layout/base.php';
?>

<style>
    .dashboard-wrap { --dash-ink: #172b4d; --dash-muted: #68778d; --dash-teal: #0f766e; max-width: 1600px; margin: 0 auto; padding: 28px clamp(18px, 2.4vw, 36px) 48px; color: var(--dash-ink); background: #f4f7fb; font-family: Inter, "Segoe UI", system-ui, sans-serif; }
    .dashboard-hero { position: relative; isolation: isolate; display: flex; justify-content: space-between; align-items: center; gap: 22px; overflow: hidden; min-height: 186px; margin-bottom: 22px; padding: 28px 34px; border: 1px solid rgba(255,255,255,.12); border-radius: 18px; background: linear-gradient(112deg, #12355b 0%, #145c70 58%, #0f766e 100%); box-shadow: 0 14px 34px rgba(20,62,91,.16); }
    .dashboard-hero::before, .dashboard-hero::after { position: absolute; z-index: -1; display: block; border: 1px solid rgba(255,255,255,.11); border-radius: 50%; content: ""; pointer-events: none; }
    .dashboard-hero::before { top: -158px; right: 12%; width: 360px; height: 360px; box-shadow: 0 0 0 34px rgba(255,255,255,.035), 0 0 0 72px rgba(255,255,255,.025); }
    .dashboard-hero::after { right: -66px; bottom: -204px; width: 320px; height: 320px; background: rgba(91,220,200,.12); }
    .dashboard-hero > div, .dashboard-action { position: relative; z-index: 1; }
    .dashboard-eyebrow { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 10px; color: #b7eee8; font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
    .dashboard-eyebrow::before { width: 7px; height: 7px; border-radius: 50%; background: #68e0c3; box-shadow: 0 0 0 4px rgba(104,224,195,.14); content: ""; }
    .dashboard-hero h1 { margin: 0 0 8px; color: #fff; font-size: clamp(26px, 3vw, 36px); font-weight: 800; letter-spacing: -.03em; }
    .dashboard-hero p { margin: 0; color: rgba(239,248,255,.82); font-size: 14px; }
    .dashboard-date { display: inline-flex; align-items: center; gap: 8px; margin-top: 16px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,.17); color: rgba(239,248,255,.82); font-size: 12px; font-weight: 600; }
    .dashboard-date i { color: #8ce2d2; }
    .dashboard-action { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 8px; padding: 12px 18px; border: 1px solid rgba(255,255,255,.72); border-radius: 10px; background: #fff; color: #13536b; text-decoration: none; font-size: 13px; font-weight: 700; box-shadow: 0 5px 16px rgba(8,28,47,.16); transition: background .18s, transform .18s, box-shadow .18s; }
    .dashboard-action:hover { transform: translateY(-2px); background: #e9fbf8; color: #104c62; box-shadow: 0 8px 20px rgba(8,28,47,.2); }
    .dashboard-alert { margin-bottom: 18px; padding: 13px 16px; border: 1px solid #f5d69c; border-radius: 11px; background: #fff9eb; color: #875b11; font-size: 13px; }
    .section-kicker { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 2px 12px; }
    .section-kicker strong { display: inline-flex; align-items: center; gap: 8px; color: #344967; font-size: 13px; font-weight: 800; }
    .section-kicker strong i { color: #0f766e; font-size: 15px; }
    .section-kicker span { color: #7b8a9f; font-size: 11.5px; }

    /* Tabs */
    .dashboard-tabs { display: flex; width: fit-content; max-width: 100%; gap: 5px; overflow-x: auto; margin: 0 0 20px; padding: 5px; border: 1px solid #dde4ee; border-radius: 12px; background: #e9eef5; scrollbar-width: thin; }
    .dashboard-tab { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 8px; min-height: 40px; padding: 0 16px; border: 0; border-radius: 8px; background: transparent; color: #4e5f77; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; transition: all .15s ease; }
    .dashboard-tab:hover { background: rgba(255,255,255,.72); color: #172b4d; }
    .dashboard-tab[aria-selected="true"] { background: #17314f; color: #fff; box-shadow: 0 3px 8px rgba(17,37,61,.2); }
    .dashboard-tab-count { display: inline-grid; min-width: 22px; height: 22px; place-items: center; padding: 0 6px; border-radius: 6px; background: rgba(23,49,79,.08); font-size: 10.5px; font-weight: 800; }
    .dashboard-tab[aria-selected="true"] .dashboard-tab-count { background: rgba(255,255,255,.18); color: #fff; }
    .dashboard-tab-panel[hidden] { display: none; }
    .dashboard-tab-panel { animation: dashboard-panel-in .18s ease-out; }
    @keyframes dashboard-panel-in { from { opacity: .6; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

    /* Individual Scale Tab Layout */
    .scale-intro { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin: 0 0 16px; padding: 20px 24px; border: 1px solid #e1e9f0; border-left: 4px solid #0f766e; border-radius: 14px; background: #fff; box-shadow: 0 3px 12px rgba(30,55,90,.035); }
    .scale-intro-main { display: flex; align-items: center; gap: 15px; }
    .scale-intro-icon { display: grid; width: 50px; height: 50px; flex: 0 0 50px; place-items: center; border: 1px solid #ccfbf1; border-radius: 14px; background: #f0fdfa; color: #0f766e; font-size: 22px; }
    .scale-intro h2 { margin: 0 0 4px; color: #172b4d; font-size: 18px; font-weight: 800; }
    .scale-intro p { margin: 0; color: #64748b; font-size: 12.5px; line-height: 1.5; }
    .scale-intro-badge { flex: 0 0 auto; padding: 7px 13px; border: 1px solid #99f6e4; border-radius: 999px; background: #f0fdfa; color: #0f766e; font-size: 12px; font-weight: 800; white-space: nowrap; }

    .scale-stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
    .scale-stat { padding: 16px 18px; border: 1px solid #e5eaf1; border-radius: 13px; background: #fff; box-shadow: 0 2px 8px rgba(30,55,90,.03); }
    .scale-stat-label { display: flex; align-items: center; gap: 7px; color: #64748b; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .scale-stat-label i { color: #0f766e; font-size: 14px; }
    .scale-stat-value { display: block; margin-top: 10px; color: #172b4d; font-size: 26px; font-weight: 800; line-height: 1; letter-spacing: -.03em; }

    /* Scale Tab 2-Column Analytics (Pie Chart + Summary/Breakdown) */
    .scale-analytics-grid { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: 16px; align-items: stretch; }
    .scale-pie-card { border: 1px solid #e5eaf1; border-radius: 14px; background: #fff; box-shadow: 0 3px 12px rgba(30,55,90,.035); overflow: hidden; display: flex; flex-direction: column; }
    .scale-pie-body { padding: 18px 20px 20px; display: flex; flex-direction: column; align-items: center; flex: 1; justify-content: center; }
    .scale-pie-wrap { position: relative; width: 100%; max-width: 260px; height: 220px; margin: 0 auto 14px; }
    .scale-legend-list { width: 100%; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 14px; margin-top: 4px; }
    .scale-legend-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 7px 10px; border-radius: 8px; background: #f8fafc; border: 1px solid #f1f5f9; font-size: 12px; }
    .scale-legend-left { display: inline-flex; align-items: center; gap: 7px; color: #334155; font-weight: 600; min-width: 0; }
    .scale-legend-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .scale-legend-val { font-weight: 800; color: #0f172a; white-space: nowrap; }

    .scale-summary-card { border: 1px solid #e5eaf1; border-radius: 14px; background: #fff; box-shadow: 0 3px 12px rgba(30,55,90,.035); overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; }
    .scale-summary-body { padding: 20px 22px; }
    .scale-summary-body h3 { margin: 0 0 8px; color: #172b4d; font-size: 15px; font-weight: 800; }
    .scale-summary-body p { margin: 0 0 18px; color: #64748b; font-size: 13px; line-height: 1.65; }
    .scale-actions { display: flex; flex-wrap: wrap; gap: 10px; }
    .scale-action { display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; border: 1px solid #0f766e; border-radius: 9px; background: #0f766e; color: #fff; font-size: 12.5px; font-weight: 700; text-decoration: none; white-space: nowrap; transition: all .15s ease; }
    .scale-action:hover { background: #115e59; border-color: #115e59; color: #fff; transform: translateY(-1px); }
    .scale-action.secondary { background: #f0fdfa; color: #0f766e; border-color: #99f6e4; }
    .scale-action.secondary:hover { background: #0f766e; color: #fff; border-color: #0f766e; }

    /* Overview Cards & Charts */
    .workspace-summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: -2px 0 18px; }
    .workspace-summary { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 3px 12px; padding: 14px 16px; border: 1px solid #e7ecf2; border-radius: 12px; background: #fff; }
    .workspace-summary > span { display: flex; align-items: center; gap: 7px; color: #64748b; font-size: 11.5px; font-weight: 700; }
    .workspace-summary > span i { color: #0f766e; }
    .workspace-summary > strong { grid-row: span 2; color: #172b4d; font-size: 24px; font-weight: 800; }
    .workspace-summary small { color: #8996a8; font-size: 10.5px; }
    .workspace-summary small a { color: #0f766e; font-weight: 700; text-decoration: none; }
    .workspace-summary small a:hover { text-decoration: underline; }

    .metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 22px; }
    .metric-card, .dashboard-panel, .chart-card { min-width: 0; border: 1px solid #e5eaf1; border-radius: 15px; background: #fff; box-shadow: 0 3px 12px rgba(30,55,90,.035); }
    .metric-card { position: relative; overflow: hidden; padding: 18px 20px; transition: transform .18s ease, box-shadow .18s ease; }
    .metric-card::before { position: absolute; top: 0; right: 0; left: 0; height: 3px; background: #0f766e; content: ""; }
    .metric-card:nth-child(2)::before { background: #4c6fff; }
    .metric-card:nth-child(3)::before { background: #e3a73e; }
    .metric-card:nth-child(4)::before { background: #10b981; }
    .metric-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(30,55,90,.08); }
    .metric-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .metric-label { color: #64748b; font-size: 12px; font-weight: 700; }
    .metric-icon { display: grid; width: 38px; height: 38px; place-items: center; border: 1px solid #ccfbf1; border-radius: 11px; background: #f0fdfa; color: #0f766e; font-size: 17px; }
    .metric-card:nth-child(2) .metric-icon { border-color: #e0e6ff; background: #f1f3ff; color: #4c63d2; }
    .metric-card:nth-child(3) .metric-icon { border-color: #f7e9c9; background: #fff9eb; color: #b27816; }
    .metric-card:nth-child(4) .metric-icon { border-color: #d4efdf; background: #effaf3; color: #059669; }
    .metric-value { display: block; margin-top: 12px; color: #172b4d; font-size: 34px; font-weight: 800; line-height: 1; letter-spacing: -.04em; }
    .metric-foot { display: block; margin-top: 8px; color: #8592a5; font-size: 11px; }

    .analytics-grid { display: grid; grid-template-columns: minmax(0, .9fr) minmax(0, 1.35fr); gap: 16px; margin-bottom: 20px; }
    .chart-card { overflow: hidden; }
    .chart-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 16px 20px 12px; border-bottom: 1px solid #f0f3f7; }
    .chart-heading h2 { margin: 0; color: #172b4d; font-size: 14px; font-weight: 800; }
    .chart-heading p { margin: 4px 0 0; color: #7b8a9f; font-size: 11.5px; }
    .chart-total { padding: 5px 10px; border: 1px solid #99f6e4; border-radius: 8px; background: #f0fdfa; color: #0f766e; font-size: 11px; font-weight: 800; white-space: nowrap; }
    .chart-canvas-wrap { position: relative; height: 236px; padding: 10px 16px 0; }
    .chart-canvas-wrap.doughnut-wrap { height: 218px; max-width: 340px; margin: 0 auto; }
    .chart-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; padding: 6px 16px 16px; color: #64748b; font-size: 11.5px; font-weight: 600; }
    .legend-item { display: inline-flex; align-items: center; gap: 7px; }
    .legend-dot { width: 9px; height: 9px; border-radius: 50%; }
    .chart-note { padding: 0 20px 10px; color: #7b8a9f; font-size: 11px; }
    .chart-links { display: flex; flex-wrap: wrap; gap: 8px; padding: 0 20px 16px; }
    .chart-links a { display: inline-flex; align-items: center; gap: 5px; padding: 6px 11px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #0f766e; font-size: 11px; font-weight: 700; text-decoration: none; transition: all .15s ease; }
    .chart-links a:hover { border-color: #99f6e4; background: #f0fdfa; color: #115e59; }

    .dashboard-content-grid { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(290px, .75fr); gap: 16px; align-items: start; }
    .dashboard-panel { overflow: hidden; }
    .panel-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid #edf1f5; }
    .panel-heading h2 { margin: 0; color: #172b4d; font-size: 14px; font-weight: 800; }
    .panel-heading a { display: inline-flex; align-items: center; gap: 5px; color: #0f766e; font-size: 11.5px; font-weight: 700; text-decoration: none; }
    .assignment-table { width: 100%; border-collapse: collapse; }
    .assignment-table th, .assignment-table td { padding: 12px 18px; border-bottom: 1px solid #f0f3f7; text-align: left; font-size: 12.5px; }
    .assignment-table th { background: #fafbfd; color: #64748b; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
    .assignment-table td { color: #334155; }
    .assignment-table tbody tr:hover { background: #f8fafc; }
    .assignment-table tbody tr:last-child td { border-bottom: 0; }
    .assignment-table td small { color: #8a97a8; font-size: 11px; }
    .patient-name { color: #172b4d; font-weight: 700; }
    .status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border: 1px solid transparent; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .status::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
    .status.completed { border-color: #cdebd9; background: #f0faf4; color: #15803d; }
    .status.pending { border-color: #f4e3bf; background: #fff9eb; color: #a76e12; }
    .empty-state { padding: 38px 20px; color: #728198; font-size: 13px; text-align: center; }

    .progress-panel { padding: 18px 20px 20px; }
    .progress-heading { display: flex; align-items: center; justify-content: space-between; color: #64748b; font-size: 12.5px; font-weight: 600; }
    .progress-heading strong { color: #172b4d; font-size: 18px; font-weight: 800; }
    .progress-track { overflow: hidden; height: 9px; margin: 12px 0 9px; border-radius: 20px; background: #e9eef4; }
    .progress-fill { width: <?php echo $completion_rate; ?>%; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0f766e, #14b8a6); }
    .progress-copy { margin: 0; color: #7b8a9f; font-size: 11.5px; line-height: 1.6; }
    .quick-title { margin: 20px 0 10px; padding-top: 15px; border-top: 1px solid #edf1f5; color: #64748b; font-size: 10.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .quick-links { display: grid; gap: 8px; }
    .quick-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid #e9eef3; border-radius: 9px; background: #fff; color: #334155; text-decoration: none; font-size: 12px; font-weight: 700; transition: all .15s ease; }
    .quick-link i { display: grid; width: 28px; height: 28px; place-items: center; border-radius: 7px; background: #f0fdfa; color: #0f766e; font-size: 14px; }
    .quick-link:hover { transform: translateX(2px); border-color: #99f6e4; background: #f0fdfa; color: #0f766e; }

    @media (max-width: 1000px) {
        .metric-grid, .scale-stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .analytics-grid, .dashboard-content-grid, .scale-analytics-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 620px) {
        .dashboard-wrap { padding: 16px 12px 28px; }
        .dashboard-hero { align-items: flex-start; flex-direction: column; min-height: 0; padding: 22px 18px; }
        .dashboard-action { width: 100%; justify-content: center; }
        .dashboard-tabs { width: 100%; }
        .scale-intro { flex-direction: column; align-items: flex-start; }
        .scale-legend-list, .workspace-summary-grid { grid-template-columns: 1fr; }
        .assignment-table th:nth-child(3), .assignment-table td:nth-child(3) { display: none; }
    }
</style>

<div class="dashboard-wrap">
    <div class="dashboard-hero">
        <div>
            <div class="dashboard-eyebrow">Clinical assessment workspace</div>
            <h1>Good day, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h1>
            <p>A clear overview of your patients, assessment activity, severity breakdowns, and follow-ups.</p>
            <span class="dashboard-date"><i class="bi bi-calendar3" aria-hidden="true"></i><?php echo date('l, d F Y'); ?></span>
        </div>
        <a class="dashboard-action" href="assign-assessments.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Assign assessment</a>
    </div>

    <?php if (!empty($dashboard_errors)): ?>
        <div class="dashboard-alert" role="alert"><?php echo htmlspecialchars(implode(' ', $dashboard_errors), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <!-- Dashboard Tabs -->
    <div class="dashboard-tabs" role="tablist" aria-label="Assessment dashboard sections">
        <button class="dashboard-tab" id="tab-overview" type="button" role="tab" aria-selected="true" aria-controls="panel-overview" tabindex="0"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Overview</button>
        <button class="dashboard-tab" id="tab-bai" type="button" role="tab" aria-selected="false" aria-controls="panel-bai" tabindex="-1">BAI <span class="dashboard-tab-count"><?php echo $beck_stats_loaded ? (int) $beck_stats['bai']['records'] : '—'; ?></span></button>
        <button class="dashboard-tab" id="tab-bdi" type="button" role="tab" aria-selected="false" aria-controls="panel-bdi" tabindex="-1">BDI <span class="dashboard-tab-count"><?php echo $beck_stats_loaded ? (int) $beck_stats['bdi']['records'] : '—'; ?></span></button>
        <button class="dashboard-tab" id="tab-bprs" type="button" role="tab" aria-selected="false" aria-controls="panel-bprs" tabindex="-1">BPRS <span class="dashboard-tab-count"><?php echo $module_stats['bprs']['total'] === null ? '—' : (int) $module_stats['bprs']['total']; ?></span></button>
        <button class="dashboard-tab" id="tab-panss" type="button" role="tab" aria-selected="false" aria-controls="panel-panss" tabindex="-1">PANSS <span class="dashboard-tab-count"><?php echo $module_stats['panss']['total'] === null ? '—' : (int) $module_stats['panss']['total']; ?></span></button>
        <button class="dashboard-tab" id="tab-ymrs" type="button" role="tab" aria-selected="false" aria-controls="panel-ymrs" tabindex="-1">YMRS <span class="dashboard-tab-count"><?php echo $module_stats['ymrs']['total'] === null ? '—' : (int) $module_stats['ymrs']['total']; ?></span></button>
        <button class="dashboard-tab" id="tab-ace_iii" type="button" role="tab" aria-selected="false" aria-controls="panel-ace_iii" tabindex="-1">ACE-III <span class="dashboard-tab-count"><?php echo $module_stats['ace_iii']['total'] === null ? '—' : (int) $module_stats['ace_iii']['total']; ?></span></button>
    </div>

    <!-- OVERVIEW PANEL -->
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
                    <span class="chart-total">6 assessment scales</span>
                </div>
                <div class="chart-canvas-wrap">
                    <canvas id="assessmentActivityChart" role="img" aria-label="Bar chart comparing BAI, BDI, BPRS, PANSS, YMRS, and ACE-III assessment records"></canvas>
                </div>
                <div class="chart-legend" aria-hidden="true">
                    <span class="legend-item"><span class="legend-dot" style="background:#4f46e5"></span>Total records</span>
                    <span class="legend-item"><span class="legend-dot" style="background:#06b6d4"></span>Last 7 days</span>
                </div>
                <div class="chart-note">Click any scale tab above to view its dedicated pie chart and clinical breakdown.</div>
                <div class="chart-links">
                    <a href="bprs-assessment-records.php">BPRS records <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                    <a href="panss-assessment-records.php">PANSS records <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                    <a href="ymrs-assessment-records.php">YMRS records <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                    <a href="ace-iii-assessment-records.php">ACE-III records <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
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
                        <a class="quick-link" href="ymrs-assessments.php"><i class="bi bi-speedometer2"></i> New YMRS assessment</a>
                        <a class="quick-link" href="ace-iii-assessments.php"><i class="bi bi-cpu"></i> New ACE-III assessment</a>
                        <a class="quick-link" href="view-assessment-questions.php"><i class="bi bi-journal-text"></i> Manage question bank</a>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <!-- INDIVIDUAL SCALE TABS WITH DEDICATED PIE CHARTS -->
    <?php foreach ($scale_tabs as $scale):
        $sid = $scale['id'];
        $pie_cfg = $tab_charts_config[$sid];
    ?>
        <section class="dashboard-tab-panel" id="panel-<?php echo htmlspecialchars($sid, ENT_QUOTES, 'UTF-8'); ?>" role="tabpanel" aria-labelledby="tab-<?php echo htmlspecialchars($sid, ENT_QUOTES, 'UTF-8'); ?>" tabindex="0" hidden>
            <header class="scale-intro">
                <div class="scale-intro-main">
                    <span class="scale-intro-icon"><i class="bi <?php echo htmlspecialchars($scale['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i></span>
                    <div>
                        <h2><?php echo htmlspecialchars($scale['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p><?php echo htmlspecialchars($scale['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
                <span class="scale-intro-badge"><?php echo htmlspecialchars((string) $scale['badge'], ENT_QUOTES, 'UTF-8'); ?></span>
            </header>

            <div class="scale-stat-grid">
                <?php foreach ($scale['metrics'] as $metric): ?>
                    <div class="scale-stat">
                        <span class="scale-stat-label"><i class="bi <?php echo htmlspecialchars($metric[2], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i><?php echo htmlspecialchars($metric[0], ENT_QUOTES, 'UTF-8'); ?></span>
                        <strong class="scale-stat-value"><?php echo $metric[1] === null ? '—' : htmlspecialchars((string) $metric[1], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="scale-analytics-grid">
                <!-- Left: Dedicated Pie/Doughnut Chart -->
                <div class="scale-pie-card">
                    <div class="chart-heading">
                        <div>
                            <h2><?php echo htmlspecialchars($pie_cfg['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <p><?php echo htmlspecialchars($pie_cfg['subtitle'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>
                    <div class="scale-pie-body">
                        <div class="scale-pie-wrap">
                            <canvas id="pieChart_<?php echo htmlspecialchars($sid, ENT_QUOTES, 'UTF-8'); ?>" role="img" aria-label="<?php echo htmlspecialchars($pie_cfg['title'], ENT_QUOTES, 'UTF-8'); ?>"></canvas>
                        </div>
                        <div class="scale-legend-list">
                            <?php foreach ($pie_cfg['labels'] as $idx => $lbl): ?>
                                <div class="scale-legend-row">
                                    <span class="scale-legend-left">
                                        <span class="scale-legend-dot" style="background: <?php echo htmlspecialchars($pie_cfg['colors'][$idx], ENT_QUOTES, 'UTF-8'); ?>;"></span>
                                        <span><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </span>
                                    <span class="scale-legend-val"><?php echo htmlspecialchars((string) $pie_cfg['values'][$idx], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Clinical Overview & Direct Actions -->
                <div class="scale-summary-card">
                    <div class="chart-heading">
                        <div>
                            <h2><?php echo htmlspecialchars($scale['summary_title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <p>Quick actions and scale insights</p>
                        </div>
                    </div>
                    <div class="scale-summary-body">
                        <h3><?php echo htmlspecialchars($scale['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p><?php echo htmlspecialchars($scale['summary'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="scale-actions">
                            <?php foreach ($scale['actions'] as $a_idx => $action): ?>
                                <a class="scale-action<?php echo $a_idx > 0 ? ' secondary' : ''; ?>" href="<?php echo htmlspecialchars($action[1], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="bi <?php echo htmlspecialchars($action[2], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($action[0], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const assignmentData = <?php echo json_encode($assignment_chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const assessmentData = <?php echo json_encode($assessment_chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const tabChartsConfig = <?php echo json_encode($tab_charts_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const renderedTabCharts = {};

    const tabs = Array.from(document.querySelectorAll('.dashboard-tab[role="tab"]'));
    const tabPanels = Array.from(document.querySelectorAll('.dashboard-tab-panel[role="tabpanel"]'));
    const assignmentCanvas = document.getElementById('assignmentStatusChart');
    const assessmentCanvas = document.getElementById('assessmentActivityChart');

    function renderScalePieChart(scaleId) {
        if (renderedTabCharts[scaleId] || typeof Chart === 'undefined') return;
        const cfg = tabChartsConfig[scaleId];
        const canvas = document.getElementById('pieChart_' + scaleId);
        if (!cfg || !canvas) return;

        const sum = cfg.values.reduce(function (acc, v) { return acc + Number(v || 0); }, 0);
        const hasData = sum > 0;

        renderedTabCharts[scaleId] = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: hasData ? cfg.labels : ['No records yet'],
                datasets: [{
                    data: hasData ? cfg.values : [1],
                    backgroundColor: hasData ? cfg.colors : ['#e2e8f0'],
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: hasData ? 6 : 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: hasData,
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.label + ': ' + context.raw + ' ' + cfg.unit;
                            }
                        }
                    }
                }
            }
        });
    }

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

        const scaleId = panelId.replace('panel-', '');
        if (tabChartsConfig[scaleId]) {
            renderScalePieChart(scaleId);
        }

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