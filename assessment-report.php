<?php
session_start();
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php'; // Database connection

// URL se IDs pakdein
$assessment_id = isset($_GET['assessment_id']) ? intval($_GET['assessment_id']) : 0;
$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;

if ($patient_id <= 0) {
    echo "<div style='text-align:center; padding: 50px; font-family: sans-serif;'><h2>Invalid Patient ID.</h2><button onclick='history.back()'>Go Back</button></div>";
    exit;
}

$user_name = '';
$assessment_date = '';
$total_score = 0;
$status_text = '';
$results = [];

// ================= FETCH SAVED REPORT FROM DATABASE =================
if (isset($conn)) {
    // Hum is patient ka sabse latest record nikal rahe hain
    $sql = "SELECT ar.user_name, ar.assessment_date, ar.total_score, ar.status_text, ar.responses_data
            FROM assessment_responses ar
            INNER JOIN users u ON u.id = ar.patient_id
                AND u.role_id = 2
                AND u.patient_id = " . PATIENT_SCOPE_ID . "
            WHERE ar.patient_id = ?
            ORDER BY ar.id DESC LIMIT 1";
            
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $user_name = $row['user_name'];
            $assessment_date = $row['assessment_date'];
            $total_score = $row['total_score'];
            $status_text = $row['status_text'];
            
            // JSON string ko wapas PHP Array mein convert kar rahe hain
            if (!empty($row['responses_data'])) {
                $results = json_decode($row['responses_data'], true);
            }
        } else {
            echo "<div style='text-align:center; padding: 50px; font-family: sans-serif;'><h2>No report found for this patient.</h2><button onclick='history.back()'>Go Back</button></div>";
            exit;
        }
        $stmt->close();
    }
}

// Chart Calculation ke variables
$green_count = 0; $yellow_count = 0; $red_count = 0; $pink_count = 0;

if (!empty($results)) {
    foreach ($results as $res) {
        $s = isset($res['selected_score']) ? intval($res['selected_score']) : 0;
        
        if ($assessment_id == 2) {
            if ($s <= 1) $green_count++;
            elseif ($s == 2) $yellow_count++;
            else $red_count++;
        } else {
            if ($s == 0) $green_count++;
            elseif ($s == 1) $green_count++;
            elseif ($s == 2) $pink_count++;
            elseif ($s == 3) $yellow_count++;
            else $red_count++;
        }
    }
}

// Color setup (Same as your original design)
if ($assessment_id == 2) { 
    if ($total_score <= 21) { $status_color = "#22c55e"; $badge_bg = "#dcfce7"; } 
    elseif ($total_score <= 35) { $status_color = "#eab308"; $badge_bg = "#fef9c3"; } 
    else { $status_color = "#ef4444"; $badge_bg = "#fee2e2"; }
} else { 
    if ($total_score <= 10) { $status_color = "#22c55e"; $badge_bg = "#dcfce7"; } 
    elseif ($total_score <= 16) { $status_color = "#22c55e"; $badge_bg = "#dcfce7"; } 
    elseif ($total_score <= 20) { $status_color = "#ec4899"; $badge_bg = "#fce7f3"; } 
    elseif ($total_score <= 30) { $status_color = "#eab308"; $badge_bg = "#fef9c3"; } 
    elseif ($total_score <= 40) { $status_color = "#ef4444"; $badge_bg = "#fee2e2"; } 
    else { $status_color = "#dc2626"; $badge_bg = "#fee2e2"; }
}

// Original Title Logic
$assessment_title = ($assessment_id == 2) ? "Beck Anxiety Inventory (BAI)" : "Beck Depression Inventory (BDI)";
$total_questions = ($assessment_id == 2) ? 21 : 21; // Generally both have 21 questions

$page_title = "Assessment Report - Vimhans Academic";
require('layout/base.php');
?>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    :root {
        --bai-primary: #0d9488; 
        --bai-primary-hover: #0f766e;
        --bai-primary-light: #f0fdfa;
        --bai-bg: #f4f7f9;
        --bai-card: #ffffff;
        --bai-text: #1e293b;
        --bai-muted: #64748b;
        --bai-border: #e2e8f0;
        --bai-shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.04);
        --bai-shadow-md: 0 4px 12px rgba(0, 0, 0, 0.05);
        --bai-radius-md: 8px;
        --bai-radius-lg: 12px;
    }

    body { background-color: var(--bai-bg); -webkit-font-smoothing: antialiased; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif;}

    .bai-container {
        max-width: 780px;
        background: var(--bai-card);
        padding: 24px 32px;
        margin: 30px auto;
        border-radius: var(--bai-radius-lg);
        box-shadow: var(--bai-shadow-md);
        box-sizing: border-box;
        border: 1px solid #ffffff;
    }

    .res-top-banner { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px; margin-bottom: 20px; }
    .res-title-group { display: flex; align-items: center; gap: 12px; }
    .res-brain-icon { width: 40px; height: 40px; background: var(--bai-primary-light); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--bai-primary); }
    .res-main-title { font-size: 20px; font-weight: 800; color: var(--bai-text); margin: 0; }
    .res-sub-title { font-size: 12px; color: var(--bai-muted); margin: 2px 0 0 0; font-weight: 600; text-transform: uppercase; }
    
    .res-completed-pill { display: inline-flex; align-items: center; gap: 6px; background: #f0fdf4; color: #16a34a; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; border: 1px solid #bbf7d0; }

    .res-grid-card {
        display: grid; grid-template-columns: 140px 1fr 240px;
        background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--bai-radius-lg);
        padding: 20px; align-items: center; gap: 20px; margin-bottom: 20px;
    }

    .res-score-circle {
        width: 100px; height: 100px; border-radius: 50%; margin: 0 auto;
        display: flex; align-items: center; justify-content: center; position: relative;
        background: conic-gradient(<?php echo $status_color; ?> <?php echo min(100, ($total_score / ($assessment_id == 2 ? 63 : 60)) * 100); ?>%, #f1f5f9 0);
    }
    .res-score-circle::before { content: ""; position: absolute; width: 80px; height: 80px; background: #ffffff; border-radius: 50%; }
    .res-score-inner { position: relative; z-index: 2; text-align: center; line-height: 1; }
    .res-score-num { font-size: 28px; font-weight: 800; color: var(--bai-text); }
    .res-score-denom { font-size: 11px; color: #94a3b8; font-weight: 700; margin-top: 4px; display: block; }

    .res-desc-area { border-left: 1px solid #f1f5f9; border-right: 1px solid #f1f5f9; padding: 0 20px; }
    .res-status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; color: <?php echo $status_color; ?>; background: <?php echo $badge_bg; ?>; margin-bottom: 8px; }
    .res-status-heading { font-size: 18px; font-weight: 800; color: var(--bai-text); margin: 0 0 6px 0; }
    .res-status-desc { font-size: 12px; color: #475569; line-height: 1.5; margin: 0; }

    .res-patient-info { display: flex; flex-direction: column; gap: 10px; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid #e2e8f0; }
    .res-info-row { display: flex; align-items: center; justify-content: space-between; font-size: 12px; }
    .res-info-label { display: flex; align-items: center; gap: 6px; color: #64748b; font-weight: 600; }
    .res-info-val { color: var(--bai-text); font-weight: 700; }

    .bai-scale-grid {
        display: grid; grid-template-columns: repeat(<?php echo ($assessment_id == 2) ? 3 : 6; ?>, 1fr); gap: 8px;
        background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--bai-radius-lg);
        padding: 16px; margin-bottom: 20px;
    }
    .bai-scale-item { background: #f8fafc; border: 1.5px solid transparent; border-radius: 8px; text-align: center; padding: 10px 6px; position: relative; }
    .bai-scale-item.active { background: #ffffff; border-color: <?php echo $status_color; ?>; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    .bai-scale-item.active::before { content: "Here"; position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: <?php echo $status_color; ?>; color: white; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 6px; }
    
    .bai-scale-color-bar { height: 6px; border-radius: 6px; margin-bottom: 8px; width: 100%; opacity: 0.6;}
    .bai-scale-item.active .bai-scale-color-bar { opacity: 1; height: 8px;}
    .bai-scale-range-txt { display: block; font-size: 11px; font-weight: 800; color: var(--bai-text); margin-bottom: 2px; }
    .bai-scale-title-txt { display: block; font-size: 10px; font-weight: 700; opacity: 0.8; }

    .bai-review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;}
    .bai-review-title { font-size: 16px; font-weight: 800; color: var(--bai-text); margin: 0; }
    .bai-review-count { font-size: 12px; color: var(--bai-primary); font-weight: 700; background: var(--bai-primary-light); padding: 3px 10px; border-radius: 12px;}

    .bai-review-box { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; gap: 16px; }
    .bai-review-box p { margin: 0; font-size: 13px; font-weight: 500; color: #334155; line-height: 1.4; }
    .review-score-badge { background: #f8fafc; color: var(--bai-text); min-width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-weight: 800; font-size: 13px; border: 1px solid #e2e8f0; flex-shrink: 0; }

    .bai-actions { display: flex; gap: 12px; margin-top: 24px; }
    .bai-action-btn { flex: 1; display: inline-flex; justify-content: center; align-items: center; gap: 8px; padding: 12px 16px; border-radius: 8px; font-family: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1.5px solid #e2e8f0; background: #ffffff; color: #475569; }
    .bai-action-btn:hover { background: #f8fafc; border-color: #cbd5e1; color: var(--bai-text); }
    .bai-action-primary { background: var(--bai-primary); color: #ffffff; border-color: var(--bai-primary); }
    .bai-action-primary:hover { background: var(--bai-primary-hover); color: white; }

    @media (max-width: 768px) {
        .res-grid-card { grid-template-columns: 1fr; text-align: center; gap: 16px; }
        .res-desc-area { border: none; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 16px 0; }
    }
    @media print {
        .bai-actions, .navbar, footer { display: none !important; }
        .bai-container { box-shadow: none; border: none; padding: 0; margin: 0; max-width: 100%;}
    }
</style>

<div class="bai-container">

    <?php $safe_date = !empty($assessment_date) ? date('d M, Y', strtotime($assessment_date)) : date('d M, Y'); ?>

    <div class="bai-result-wrap">
        <div class="res-top-banner">
            <div class="res-title-group">
                <div class="res-brain-icon">📊</div>
                <div>
                    <h2 class="res-main-title"><?php echo htmlspecialchars($assessment_title); ?> Result</h2>
                    <p class="res-sub-title">Clinical Evaluation Report</p>
                </div>
            </div>
            <div class="res-completed-pill">✓ Completed</div>
        </div>

        <div class="res-grid-card">
            <div class="res-score-circle">
                <div class="res-score-inner">
                    <span class="res-score-num"><?php echo $total_score; ?></span>
                    <span class="res-score-denom">out of <?php echo ($total_questions * 3); ?></span>
                </div>
            </div>

            <div class="res-desc-area">
                <div class="res-status-badge"><?php echo htmlspecialchars($status_text); ?></div>
                <h3 class="res-status-heading">Evaluation Score Level</h3>
                <p class="res-status-desc">Based on the responses, the score falls into the <strong><?php echo htmlspecialchars($status_text); ?></strong> range.</p>
            </div>

            <div class="res-patient-info">
                <div class="res-info-row">
                    <span class="res-info-label">👤 Patient Name</span>
                    <span class="res-info-val"><?php echo htmlspecialchars($user_name); ?></span>
                </div>
                <div class="res-info-row">
                    <span class="res-info-label">📅 Date</span>
                    <span class="res-info-val"><?php echo htmlspecialchars($safe_date); ?></span>
                </div>
                <div class="res-info-row">
                    <span class="res-info-label">📊 Final Score</span>
                    <span class="res-info-val" style="color: <?php echo $status_color; ?>;"><?php echo $total_score; ?> / <?php echo ($total_questions * 3); ?></span>
                </div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--bai-radius-lg); padding: 20px; margin-bottom: 20px; display: flex; flex-direction: column; align-items: center;">
            <h3 class="bai-review-title" style="align-self: flex-start; margin-bottom: 15px;">Severity Breakdown (Pie Chart)</h3>
            <div style="width: 100%; max-width: 320px; height: 320px;">
                <canvas id="baiPieChart"></canvas>
            </div>
        </div>

        <!-- Dynamic Grid Based on Assessment ID -->
        <?php if ($assessment_id == 2): ?>
            <!-- BAI 3-Tier Grid -->
            <div class="bai-scale-grid">
                <div class="bai-scale-item <?php echo ($total_score <= 21) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #22c55e;"></div>
                    <span class="bai-scale-range-txt">0-21</span>
                    <span class="bai-scale-title-txt" style="color: #16a34a;">Low anxiety</span>
                </div>
                <div class="bai-scale-item <?php echo ($total_score >= 22 && $total_score <= 35) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #eab308;"></div>
                    <span class="bai-scale-range-txt">22-35</span>
                    <span class="bai-scale-title-txt" style="color: #ca8a04;">Moderate anxiety</span>
                </div>
                <div class="bai-scale-item <?php echo ($total_score >= 36) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #ef4444;"></div>
                    <span class="bai-scale-range-txt">36+</span>
                    <span class="bai-scale-title-txt" style="color: #dc2626;">Concerning levels of anxiety</span>
                </div>
            </div>
        <?php else: ?>
            <!-- BDI 6-Tier Grid -->
            <div class="bai-scale-grid" style="grid-template-columns: repeat(6, 1fr);">
                <div class="bai-scale-item <?php echo ($total_score <= 10) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #22c55e;"></div>
                    <span class="bai-scale-range-txt">0-10</span>
                    <span class="bai-scale-title-txt" style="color: #16a34a;">Normal</span>
                </div>
                <div class="bai-scale-item <?php echo ($total_score >= 11 && $total_score <= 16) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #22c55e;"></div>
                    <span class="bai-scale-range-txt">11-16</span>
                    <span class="bai-scale-title-txt" style="color: #16a34a;">Mild</span>
                </div>
                <div class="bai-scale-item <?php echo ($total_score >= 17 && $total_score <= 20) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #ec4899;"></div>
                    <span class="bai-scale-range-txt">17-20</span>
                    <span class="bai-scale-title-txt" style="color: #db2777;">Borderline</span>
                </div>
                <div class="bai-scale-item <?php echo ($total_score >= 21 && $total_score <= 30) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #eab308;"></div>
                    <span class="bai-scale-range-txt">21-30</span>
                    <span class="bai-scale-title-txt" style="color: #ca8a04;">Moderate</span>
                </div>
                <div class="bai-scale-item <?php echo ($total_score >= 31 && $total_score <= 40) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #ef4444;"></div>
                    <span class="bai-scale-range-txt">31-40</span>
                    <span class="bai-scale-title-txt" style="color: #dc2626;">Severe</span>
                </div>
                <div class="bai-scale-item <?php echo ($total_score > 40) ? 'active' : ''; ?>">
                    <div class="bai-scale-color-bar" style="background: #dc2626;"></div>
                    <span class="bai-scale-range-txt">40+</span>
                    <span class="bai-scale-title-txt" style="color: #991b1b;">Extremely Severe</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="bai-review-header">
            <h3 class="bai-review-title">Detailed Response Log</h3>
            <span class="bai-review-count"><?php echo count($results); ?> Answered</span>
        </div>

        <?php 
        $res_counter = 1;
        if(!empty($results)) {
            foreach ($results as $id => $res): 
        ?>
            <div class="bai-review-box">
                <p><strong><?php echo sprintf('%02d', $res_counter); ?>.</strong> <?php echo htmlspecialchars($res['question'] ?? 'Question'); ?> <span style="color:#94a3b8; font-size:12px; margin-left: 8px;">(<?php echo htmlspecialchars($res['selected_text'] ?? ''); ?>)</span></p>
                <div class="review-score-badge">+<?php echo htmlspecialchars($res['selected_score'] ?? '0'); ?></div>
            </div>
        <?php 
            $res_counter++;
            endforeach; 
        } else {
            echo "<p style='text-align:center; color:var(--bai-muted);'>No responses to display.</p>";
        }
        ?>

        <div class="bai-actions">
            <a href="public-assessment-form.php?patient_id=<?php echo htmlspecialchars($patient_id); ?>" class="bai-action-btn"><i class="bi bi-arrow-left me-2"></i> Back </a>
            
            <button type="button" class="bai-action-btn bai-action-primary" onclick="window.print();"><i class="bi bi-printer me-2"></i> Print Report</button>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('baiPieChart').getContext('2d');
    const isBai = <?php echo ($assessment_id == 2) ? 'true' : 'false'; ?>;
    
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: isBai ? ['Low (Score 0-1)', 'Moderate (Score 2)', 'Severe (Score 3)'] : ['Normal', 'Mild', 'Borderline', 'Moderate', 'Severe', 'Extremely Severe'],
            datasets: [{
                data: isBai ? [
                    <?php echo $green_count; ?>,
                    <?php echo $yellow_count; ?>,
                    <?php echo $red_count; ?>
                ] : [
                    <?php echo $green_count; ?>, // Normal/Mild (combine for pie chart display accuracy)
                    <?php echo $green_count; ?>, // Mild
                    <?php echo $pink_count; ?>, // Borderline
                    <?php echo $yellow_count; ?>, // Moderate
                    <?php echo $red_count; ?>, // Severe
                    <?php echo $red_count; ?>  // Ext Severe
                ],
                backgroundColor: isBai ? [
                    '#22c55e', '#eab308', '#ef4444'
                ] : [
                    '#22c55e', '#22c55e', '#ec4899', '#eab308', '#ef4444', '#dc2626'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: {
                            family: 'Plus Jakarta Sans',
                            size: 11,
                            weight: '600'
                        },
                        padding: 12
                    }
                }
            }
        }
    });
});
</script>

<?php 
require('layout/footer.php'); 
ob_end_flush();
?>