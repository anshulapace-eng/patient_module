<?php
session_start();
// Output buffering start karein
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php'; // Database connection ($conn)

// 1. URL se public parameters pakdein
$assessment_id = isset($_GET['assessment_id']) ? intval($_GET['assessment_id']) : 0;
$patient_id_get = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
$assignment_id_get = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;

/*
 * LMS launch parameters.
 * These are used only when the assessment is opened as an LMS lesson.
 * The existing standalone Module 1 assignment_id flow remains unchanged.
 */
$lms_assignment_id_get = isset($_GET['lms_assignment_id']) ? intval($_GET['lms_assignment_id']) : 0;
$course_id_get = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
$lesson_id_get = isset($_GET['lesson_id']) ? intval($_GET['lesson_id']) : 0;

$lms_mode = ($lms_assignment_id_get > 0);

$submitted = false;
$total_score = 0;
$user_name = '';
$user_mobile = '';
$user_email = '';
$assessment_date = '';
$results = [];

if ($assessment_id <= 0 || $patient_id_get <= 0 || !isset($conn)) {
    die("<div style='text-align:center; padding: 50px; font-family: sans-serif;'><h2>Invalid Assessment Link</h2><p>Please check the URL provided to you.</p></div>");
}

// Security Check: Confirm karein ki ye assignment actually exist karti hai
if ($assignment_id_get > 0) {
    $assignment_query = "SELECT id FROM assigned_assessments WHERE id = ? AND patient_id = ? AND assessment_id = ? LIMIT 1";
    if ($assignment_stmt = $conn->prepare($assignment_query)) {
        $assignment_stmt->bind_param("iii", $assignment_id_get, $patient_id_get, $assessment_id);
        $assignment_stmt->execute();
        $assignment_stmt->store_result();
        $assignment_is_valid = $assignment_stmt->num_rows === 1;
        $assignment_stmt->close();
        
        if (!$assignment_is_valid) {
            die("<div style='text-align:center; padding: 50px; font-family: sans-serif;'><h2>Invalid or Expired Link</h2><p>This assessment link is no longer valid.</p></div>");
        }
    }
}

/*
 * LMS SECURITY CHECK
 *
 * When BDI/BAI is opened from the main LMS, validate the real LMS
 * assignment + assigned lesson before showing the assessment.
 */
if ($lms_mode && isset($conn)) {

    if ($patient_id_get <= 0 || $course_id_get <= 0 || $lesson_id_get <= 0) {
        die("<div style='text-align:center; padding: 50px; font-family: sans-serif;'><h2>Invalid LMS Assessment Link</h2><p>Please reopen the assessment from your learning module.</p></div>");
    }

    $lms_assignment_query = "
        SELECT
            pa.id,
            pa.patient_id,
            pa.course_id,
            pa.due_date,
            pai.lesson_id
        FROM patient_assignment pa
        INNER JOIN patient_assignment_items pai
            ON pai.assignment_id = pa.id
        WHERE
            pa.id = ?
        AND pa.patient_id = ?
        AND pa.course_id = ?
        AND pai.lesson_id = ?
        AND pa.status = 1
        AND pa.due_date >= CURDATE()
        LIMIT 1
    ";

    if ($lms_stmt = $conn->prepare($lms_assignment_query)) {

        $lms_stmt->bind_param(
            "iiii",
            $lms_assignment_id_get,
            $patient_id_get,
            $course_id_get,
            $lesson_id_get
        );

        $lms_stmt->execute();
        $lms_result = $lms_stmt->get_result();
        $lms_assignment = $lms_result->fetch_assoc();
        $lms_stmt->close();

        if (!$lms_assignment) {
            die("<div style='text-align:center; padding: 50px; font-family: sans-serif;'><h2>Invalid or Expired LMS Assignment</h2><p>This assessment is not part of the selected learning assignment.</p></div>");
        }
    } else {
        die("<div style='text-align:center; padding: 50px; font-family: sans-serif;'><h2>Unable to Validate Assessment</h2><p>Please try again.</p></div>");
    }
}

/*
 * The assignment ID written back to the assessment form is:
 *   - LMS assignment ID in LMS mode
 *   - existing Module 1 assigned_assessments ID in standalone mode
 */
$form_assignment_id = $lms_mode
    ? $lms_assignment_id_get
    : $assignment_id_get;

// ================= FETCH PATIENT DETAILS AUTOMATICALLY =================
if ($patient_id_get > 0 && isset($conn)) {
    $patient_query = "SELECT first_name, last_name, phone, email FROM users WHERE id = ? LIMIT 1";
    if ($stmt_p = $conn->prepare($patient_query)) {
        $stmt_p->bind_param("i", $patient_id_get);
        $stmt_p->execute();
        $p_res = $stmt_p->get_result();
        if ($p_row = $p_res->fetch_assoc()) {
            $user_name = trim(($p_row['first_name'] ?? '') . ' ' . ($p_row['last_name'] ?? ''));
            if ($user_name === '' && !empty($p_row['email'])) {
                $email_parts = explode('@', $p_row['email']);
                $user_name = $email_parts[0];
            }
            $user_mobile = $p_row['phone'] ?? '';
            $user_email = $p_row['email'] ?? '';
        }
        $stmt_p->close();
    }
}

// Fallback agar POST request aati hai
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_name = $_POST['user_name'] ?? $user_name;
    $user_mobile = $_POST['user_mobile'] ?? $user_mobile;
    $user_email = $_POST['user_email'] ?? $user_email;
    $assessment_date = !empty($_POST['assessment_date']) ? $_POST['assessment_date'] : date('Y-m-d');
} else {
    $assessment_date = date('Y-m-d');
}

// ================= FETCH ASSESSMENT DETAILS FROM DATABASE =================
$assessment_title = "Assessment";
$assessment_desc = "";

if (isset($conn)) {
    $asm_query = "SELECT title, description FROM assessments WHERE id = ? LIMIT 1";
    if ($stmt_asm = $conn->prepare($asm_query)) {
        $stmt_asm->bind_param("i", $assessment_id);
        $stmt_asm->execute();
        $asm_res = $stmt_asm->get_result();
        if ($asm_row = $asm_res->fetch_assoc()) {
            $assessment_title = $asm_row['title'];
            $assessment_desc = $asm_row['description'];
        }
        $stmt_asm->close();
    }
}

$page_title = $assessment_title . " - Vimhans Academic";

// Note: Agar layout files public pages par access nahi hoti toh inko hata sakte hain.
require('layout/base.php');

// ================= FETCH QUESTIONS & OPTIONS FROM DATABASE =================
$questions_data = [];

if (isset($conn)) {
    $q_query = "SELECT id, question_text, sort_order FROM assessment_questions WHERE assessment_id = ? ORDER BY sort_order ASC, id ASC";
    if ($stmt_q = $conn->prepare($q_query)) {
        $stmt_q->bind_param("i", $assessment_id);
        $stmt_q->execute();
        $q_result = $stmt_q->get_result();
        
        while ($q_row = $q_result->fetch_assoc()) {
            $q_id = $q_row['id'];
            $options = [];
            $opt_query = "SELECT id, option_text, score_value FROM assessment_options WHERE question_id = ? ORDER BY id ASC";
            if ($stmt_opt = $conn->prepare($opt_query)) {
                $stmt_opt->bind_param("i", $q_id);
                $stmt_opt->execute();
                $opt_result = $stmt_opt->get_result();
                while ($opt_row = $opt_result->fetch_assoc()) {
                    $options[$opt_row['score_value']] = $opt_row['option_text'];
                }
                $stmt_opt->close();
            }
            
            $questions_data[$q_id] = [
                'text' => $q_row['question_text'],
                'sort_order' => $q_row['sort_order'],
                'options' => $options
            ];
        }
        $stmt_q->close();
    }
}

$total_questions = count($questions_data);

// ================= SUBMIT HANDLING CODE =================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $submitted = true;
    
    /*
     * In LMS mode, always use the already-validated GET values.
     * This prevents a patient from changing hidden POST IDs.
     */
    if ($lms_mode) {

        $patient_id = $patient_id_get;
        $assignment_id = $lms_assignment_id_get;
        $course_id = $course_id_get;
        $lesson_id = $lesson_id_get;

    } else {

        $patient_id = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : null;
        $assignment_id = !empty($_POST['assignment_id']) ? intval($_POST['assignment_id']) : null;
        $course_id = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
        $lesson_id = !empty($_POST['lesson_id']) ? intval($_POST['lesson_id']) : null;
    }

    foreach ($questions_data as $id => $q_info) {
        if (isset($_POST['q_' . $id])) {
            $selected_val = intval($_POST['q_' . $id]);
            $total_score += $selected_val;
            
            $results[$id] = [
                'question' => $q_info['text'],
                'selected_score' => $selected_val,
                'selected_text' => $q_info['options'][$selected_val] ?? ''
            ];
        }
    }

    if ($assessment_id == 2) { 
        if ($total_score <= 21) {
            $status_text = "Low anxiety";
        } elseif ($total_score <= 35) {
            $status_text = "Moderate anxiety";
        } else {
            $status_text = "Concerning levels of anxiety";
        }
    } else { 
        if ($total_score <= 10) {
            $status_text = "Normal";
        } elseif ($total_score <= 16) {
            $status_text = "Mild";
        } elseif ($total_score <= 20) {
            $status_text = "Borderline";
        } elseif ($total_score <= 30) {
            $status_text = "Moderate";
        } elseif ($total_score <= 40) {
            $status_text = "Severe";
        } else {
            $status_text = "Extremely Severe";
        }
    }

    if (isset($conn)) {
        $json_results = json_encode($results);
        $sql = "INSERT INTO assessment_responses (patient_id, assignment_id, course_id, lesson_id, user_name, user_email, user_mobile, assessment_date, total_score, status_text, responses_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
        $response_saved = false;

        if ($stmt = $conn->prepare($sql)) {

            $stmt->bind_param(
                "iiiissssiss",
                $patient_id,
                $assignment_id,
                $course_id,
                $lesson_id,
                $user_name,
                $user_email,
                $user_mobile,
                $assessment_date,
                $total_score,
                $status_text,
                $json_results
            );

            $response_saved = $stmt->execute();
            $stmt->close();
        }

        /*
         * Module 1 assignment record sync.
         *
         * Standalone mode:
         *   update the existing assigned_assessments row.
         *
         * LMS mode:
         *   also create/update an assigned_assessments row so the
         *   assessment appears in Module 1 -> Assigned Assessments.
         */
        if ($patient_id > 0 && $assessment_id > 0) {

            $assignment_record_id = 0;

            /*
             * First try to find an existing assignment for this
             * patient + assessment. Prefer a pending row, otherwise
             * use the latest row.
             */
            $find_assignment_sql = "
                SELECT id
                FROM assigned_assessments
                WHERE patient_id = ?
                  AND assessment_id = ?
                ORDER BY
                    CASE
                        WHEN status = 'Pending' THEN 0
                        ELSE 1
                    END,
                    id DESC
                LIMIT 1
            ";

            if ($find_stmt = $conn->prepare($find_assignment_sql)) {

                $find_stmt->bind_param(
                    "ii",
                    $patient_id,
                    $assessment_id
                );

                $find_stmt->execute();
                $find_result = $find_stmt->get_result();

                if ($find_row = $find_result->fetch_assoc()) {
                    $assignment_record_id = (int)$find_row['id'];
                }

                $find_stmt->close();
            }

            if ($assignment_record_id > 0) {

                $update_assignment_sql = "
                    UPDATE assigned_assessments
                    SET status = 'completed'
                    WHERE id = ?
                    LIMIT 1
                ";

                if ($update_assignment_stmt = $conn->prepare($update_assignment_sql)) {

                    $update_assignment_stmt->bind_param(
                        "i",
                        $assignment_record_id
                    );

                    $update_assignment_stmt->execute();
                    $update_assignment_stmt->close();
                }

            } else {

                $insert_assignment_sql = "
                    INSERT INTO assigned_assessments
                    (
                        patient_id,
                        assessment_id,
                        status,
                        assigned_date
                    )
                    VALUES
                    (?, ?, 'completed', NOW())
                ";

                if ($insert_assignment_stmt = $conn->prepare($insert_assignment_sql)) {

                    $insert_assignment_stmt->bind_param(
                        "ii",
                        $patient_id,
                        $assessment_id
                    );

                    $insert_assignment_stmt->execute();
                    $insert_assignment_stmt->close();
                }
            }
        }

        /*
         * LMS flow:
         * mark the actual LMS lesson completed so my-learning.php /
         * lesson-view.php can unlock the next lesson.
         */
        if ($lms_mode && $response_saved) {

            $progress_exists = false;

            $progress_check = "
                SELECT id
                FROM patient_progress
                WHERE patient_id = ?
                AND assignment_id = ?
                AND lesson_id = ?
                LIMIT 1
            ";

            if ($progress_stmt = $conn->prepare($progress_check)) {

                $progress_stmt->bind_param(
                    "iii",
                    $patient_id,
                    $assignment_id,
                    $lesson_id
                );

                $progress_stmt->execute();
                $progress_stmt->store_result();

                $progress_exists = ($progress_stmt->num_rows > 0);

                $progress_stmt->close();
            }

            if ($progress_exists) {

                $progress_update = "
                    UPDATE patient_progress
                    SET
                        status = 2,
                        watch_percentage = 100,
                        watch_seconds = 0,
                        completed_at = NOW(),
                        last_viewed_at = NOW()
                    WHERE patient_id = ?
                    AND assignment_id = ?
                    AND lesson_id = ?
                    LIMIT 1
                ";

                if ($progress_stmt = $conn->prepare($progress_update)) {

                    $progress_stmt->bind_param(
                        "iii",
                        $patient_id,
                        $assignment_id,
                        $lesson_id
                    );

                    $progress_stmt->execute();
                    $progress_stmt->close();
                }

            } else {

                $progress_insert = "
                    INSERT INTO patient_progress
                    (
                        patient_id,
                        assignment_id,
                        course_id,
                        lesson_id,
                        status,
                        watch_percentage,
                        watch_seconds,
                        started_at,
                        completed_at,
                        last_viewed_at
                    )
                    VALUES
                    (?, ?, ?, ?, 2, 100, 0, NOW(), NOW(), NOW())
                ";

                if ($progress_stmt = $conn->prepare($progress_insert)) {

                    $progress_stmt->bind_param(
                        "iiii",
                        $patient_id,
                        $assignment_id,
                        $course_id,
                        $lesson_id
                    );

                    $progress_stmt->execute();
                    $progress_stmt->close();
                }
            }
        }
    }
}
?>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- CSS Design -->
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

    body { background-color: var(--bai-bg); -webkit-font-smoothing: antialiased; margin: 0; padding: 0; }

    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .animate-enter { animation: fadeUp 0.4s ease forwards; opacity: 0; }
    .delay-1 { animation-delay: 0.05s; }
    .delay-2 { animation-delay: 0.1s; }

    .bai-container {
        font-family: 'Plus Jakarta Sans', sans-serif;
        max-width: 780px;
        background: var(--bai-card);
        padding: 24px 32px;
        margin: 30px auto;
        border-radius: var(--bai-radius-lg);
        box-shadow: var(--bai-shadow-md);
        box-sizing: border-box;
        border: 1px solid #ffffff;
    }

    .bai-header { text-align: center; margin-bottom: 24px; }
    .animated-title {
        background: linear-gradient(135deg, #0f766e, #14b8a6);
        background-clip: text;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        font-size: 24px;
        font-weight: 800;
        margin-bottom: 8px;
        letter-spacing: -0.3px;
    }
    .bai-header p { color: var(--bai-muted); font-size: 13px; line-height: 1.5; max-width: 550px; margin: 0 auto; font-weight: 500; }

    .section-header-wrap { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; margin-top: 24px; }
    .section-header-wrap:first-of-type { margin-top: 0; }
    .section-badge {
        background: linear-gradient(135deg, #14b8a6, #0d9488);
        color: white; font-size: 14px; font-weight: 700; width: 32px; height: 32px;
        display: flex; align-items: center; justify-content: center; border-radius: 8px; flex-shrink: 0;
    }
    .section-title h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--bai-text); }
    .section-title p { margin: 2px 0 0 0; font-size: 12px; color: var(--bai-muted); }

    .user-info-box { background: #f8fafc; border: 1px solid var(--bai-border); border-radius: var(--bai-radius-md); padding: 16px; margin-bottom: 16px; }
    .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; align-items: start; }
    .form-group label { display: block; font-weight: 600; font-size: 12px; color: #475569; margin-bottom: 6px; text-transform: uppercase; }
    
    .form-control-plaintext {
        width: 100%;
        padding: 10px 12px;
        border: 1.5px solid var(--bai-border);
        border-radius: 6px;
        font-size: 13px;
        color: var(--bai-text);
        background: #e9ecef;
        box-sizing: border-box;
        font-family: inherit;
        font-weight: 600;
        cursor: not-allowed;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .progress-container {
        position: sticky; top: 15px; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(8px);
        padding: 12px 16px; border-radius: var(--bai-radius-md); box-shadow: var(--bai-shadow-sm);
        border: 1px solid #e2e8f0; margin-bottom: 20px; z-index: 100;
    }
    .progress-header { display: flex; justify-content: space-between; align-items: center; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 8px; }
    #progress-text { color: var(--bai-primary); background: var(--bai-primary-light); padding: 3px 10px; border-radius: 12px; font-size: 11px;}
    .progress-bar-bg { background: #e2e8f0; height: 6px; border-radius: 6px; overflow: hidden; }
    .progress-bar-fill { background: linear-gradient(90deg, #14b8a6, #0d9488); height: 100%; width: 0%; border-radius: 6px; transition: width 0.3s ease; }

    .bai-q-box { margin-bottom: 16px; padding: 16px; background: #ffffff; border: 1px solid var(--bai-border); border-radius: var(--bai-radius-md); transition: all 0.2s ease; }
    .bai-q-box:hover { border-color: #cbd5e1; }
    .bai-q-box p { font-weight: 700; font-size: 14px; color: #1e293b; margin-top: 0; margin-bottom: 12px; }

    .bai-options { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .bai-options label { 
        display: flex; align-items: center; padding: 10px 14px; background: #f8fafc; border: 1.5px solid transparent; 
        border-radius: 8px; cursor: pointer; transition: all 0.2s ease; margin: 0; gap: 12px; 
    }
    .bai-options label:hover { background: #ffffff; border-color: #cbd5e1; }
    .bai-options label:has(input[type="radio"]:checked) { background-color: var(--bai-primary-light); border-color: var(--bai-primary); }
    .bai-options label:has(input[type="radio"]:checked) .option-text { color: #115e59; font-weight: 600; }
    
    .bai-options input[type="radio"] { 
        appearance: none; -webkit-appearance: none; width: 16px; height: 16px; 
        border: 1.5px solid #94a3b8; border-radius: 50%; margin: 0; cursor: pointer; flex-shrink: 0; position: relative; background: #ffffff;
    }
    .bai-options input[type="radio"]::after {
        content: ""; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0);
        width: 8px; height: 8px; border-radius: 50%; background: var(--bai-primary); transition: transform 0.2s ease;
    }
    .bai-options input[type="radio"]:checked { border-color: var(--bai-primary); }
    .bai-options input[type="radio"]:checked::after { transform: translate(-50%, -50%) scale(1); }

    .option-text { font-size: 13.5px; color: #334155; font-weight: 500; line-height: 1.4; }

    .bai-btn { 
        background: linear-gradient(135deg, #14b8a6, #0d9488); color: white; padding: 14px 24px; border: none; 
        border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 700; width: 100%; 
        transition: all 0.2s ease; margin-top: 20px; font-family: inherit; letter-spacing: 0.3px;
    }
    .bai-btn:hover { background: #0f766e; }

    @media (max-width: 480px) {
        .bai-container { padding: 16px; margin: 16px 10px; }
        .bai-options { grid-template-columns: 1fr; }
    }
</style>

<div class="bai-container animate-enter">
    
    <?php if (!$submitted): ?>
        
        <div class="bai-header delay-1 animate-enter">
            <h2 class="animated-title"><?php echo htmlspecialchars($assessment_title); ?></h2>
            <p><?php echo htmlspecialchars($assessment_desc); ?></p>
        </div>

        <form method="POST" class="delay-2 animate-enter">
            <input type="hidden" name="patient_id" value="<?php echo htmlspecialchars($patient_id_get); ?>">
            <input type="hidden" name="assignment_id" value="<?php echo htmlspecialchars($form_assignment_id); ?>">
            <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course_id_get); ?>">
            <input type="hidden" name="lesson_id" value="<?php echo htmlspecialchars($lesson_id_get); ?>">

            <div class="section-header-wrap">
                <div class="section-badge">01</div>
                <div class="section-title">
                    <h3>Patient Details</h3>
                    <p>Primary information for the assessment record.</p>
                </div>
            </div>

            <div class="user-info-box">
                <div class="info-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                    
                    <div class="form-group">
                        <label>Full Name *</label>
                        <div class="form-control-plaintext"><?php echo htmlspecialchars(!empty($user_name) ? $user_name : 'N/A'); ?></div>
                        <input type="hidden" name="user_name" value="<?php echo htmlspecialchars($user_name); ?>">
                    </div>
                    <div class="form-group">
                        <label>Mobile Number *</label>
                        <div class="form-control-plaintext"><?php echo htmlspecialchars(!empty($user_mobile) ? $user_mobile : 'N/A'); ?></div>
                        <input type="hidden" name="user_mobile" value="<?php echo htmlspecialchars($user_mobile); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email Address *</label>
                        <div class="form-control-plaintext"><?php echo htmlspecialchars(!empty($user_email) ? $user_email : 'N/A'); ?></div>
                        <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($user_email); ?>">
                    </div>
                    <div class="form-group">
                        <label>Assessment Date *</label>
                        <div class="form-control-plaintext"><?php echo date('Y-m-d'); ?></div>
                        <input type="hidden" name="assessment_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
            </div>

            <div class="section-header-wrap">
                <div class="section-badge">02</div>
                <div class="section-title">
                    <h3>Assessment Questions</h3>
                    <p>Select one response for every question (Total <?php echo $total_questions; ?>).</p>
                </div>
            </div>

            <div class="progress-container">
                <div class="progress-header">
                    <span>Assessment Progress</span>
                    <span id="progress-text">0 / <?php echo $total_questions; ?> Answered</span>
                </div>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" id="progress-fill"></div>
                </div>
            </div>

            <?php 
            $counter = 1;
            foreach ($questions_data as $id => $q_info): 
            ?>
                <div class="bai-q-box">
                    <p><?php echo sprintf('%02d', $counter) . '. ' . htmlspecialchars($q_info['text']); ?></p>
                    <div class="bai-options">
                        <?php foreach ($q_info['options'] as $score_val => $opt_text): ?>
                            <label>
                                <input type="radio" name="q_<?php echo $id; ?>" value="<?php echo $score_val; ?>" required>
                                <span class="option-text"><?php echo htmlspecialchars($opt_text); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php 
            $counter++;
            endforeach; 
            ?>

            <button type="submit" class="bai-btn">Submit Assessment</button>
        </form>

    <?php else: ?>

        <!-- ================= SIMPLE SUCCESS MESSAGE ================= -->
        <!-- ================= SIMPLE SUCCESS MESSAGE ================= -->
        <div class="text-center" style="padding: 40px 20px; text-align: center;">
            <div style="font-size: 70px; color: var(--bai-primary); margin-bottom: 20px;">
                <!-- HTML entity for Checkmark -->
                &#10004;
            </div>
            <h2 style="font-weight: 800; color: var(--bai-text); margin-bottom: 15px;">Assessment Submitted Successfully!</h2>
            <p style="color: var(--bai-muted); font-size: 15px; margin-bottom: 30px; max-width: 500px; margin-left: auto; margin-right: auto; line-height: 1.6;">
                Thank you, <?php echo htmlspecialchars(!empty($user_name) ? $user_name : 'Patient'); ?>. Your responses have been securely recorded and the status has been successfully updated.
            </p>
            <?php
            if ($lms_mode) {
                $lms_back_url = '../lesson-view.php?assignment_id='
                    . (int)$lms_assignment_id_get
                    . '&lesson_id='
                    . (int)$lesson_id_get;

                $lms_back_text = 'Back to Learning';
            } else {
                $lms_back_url = 'public-assessment-form.php?patient_id='
                    . (int)$patient_id_get;

                $lms_back_text = 'Back to Assessment';
            }
            ?>

            <a
                href="<?php echo htmlspecialchars($lms_back_url, ENT_QUOTES, 'UTF-8'); ?>"
                class="bai-btn"
                style="width: auto; padding: 12px 30px; display: inline-block; text-decoration: none;">
                <?php echo htmlspecialchars($lms_back_text); ?>
            </a>
        </div>

    <?php endif; ?>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const totalQuestions = <?php echo $total_questions ?? 0; ?>;
    const progressFill = document.getElementById("progress-fill");
    const progressText = document.getElementById("progress-text");
    
    if (progressFill && progressText && totalQuestions > 0) {
        const radios = document.querySelectorAll('input[type="radio"]');

        function updateProgress() {
            const checkedGroups = new Set();
            document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
                checkedGroups.add(radio.name);
            });
            const answered = checkedGroups.size;
            const percentage = (answered / totalQuestions) * 100;
            progressFill.style.width = percentage + '%';
            progressText.innerText = answered + ' / ' + totalQuestions + ' Answered';
        }

        radios.forEach(radio => { radio.addEventListener('change', updateProgress); });
    }
});
</script>

<?php 
// Public form hai toh footer shayad zaroorat na ho
if (file_exists('layout/footer.php')) {
    require('layout/footer.php'); 
}
ob_end_flush();
?>