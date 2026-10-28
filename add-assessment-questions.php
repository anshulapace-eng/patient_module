<?php
ob_start(); 
$page_title = "Admin - Add Assessment Questions";
require('layout/base.php'); 

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php'; // Database connection ($conn)

$error_msg = '';


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $assessment_id   = intval($_POST['assessment_id'] ?? 0);
    $question_texts  = $_POST['question_texts'] ?? [];
    $sort_orders     = $_POST['sort_orders'] ?? [];
    $options_text    = $_POST['options_text'] ?? [];

    if (isset($conn) && $assessment_id > 0 && !empty($question_texts)) {
        $conn->begin_transaction();

        try {
            $stmt_check = $conn->prepare("SELECT id FROM assessment_questions WHERE assessment_id = ? AND question_text = ?");
            
            $stmt_q = $conn->prepare("
                INSERT INTO assessment_questions 
                (assessment_id, question_text, sort_order) 
                VALUES (?, ?, ?)
            ");

            $stmt_opt = $conn->prepare("
                INSERT INTO assessment_options 
                (question_id, option_text, score_value) 
                VALUES (?, ?, 0)
            ");

            $added_count = 0;

            foreach ($question_texts as $i => $q_text) {
                $q_text = trim($q_text);
                $q_sort = intval($sort_orders[$i] ?? ($i + 1));

                if (!empty($q_text)) {
                    $stmt_check->bind_param("is", $assessment_id, $q_text);
                    $stmt_check->execute();
                    $stmt_check->store_result();

                    if ($stmt_check->num_rows > 0) {
                        throw new Exception("Question already exists: \"" . htmlspecialchars($q_text) . "\"");
                    }
                }

                    $stmt_q->bind_param("isi", $assessment_id, $q_text, $q_sort);
                    $stmt_q->execute();
                    $new_question_id = $conn->insert_id;

                    if (isset($options_text) && is_array($options_text)) {
                        foreach ($options_text as $optIndex => $optRow) {
                            $opt_text  = trim($optRow[$i] ?? '');

                            if (!empty($opt_text)) {
                                $stmt_opt->bind_param("is", $new_question_id, $opt_text);
                                $stmt_opt->execute();
                            }
                        }
                    }

                    $added_count++;
                
            }

            $conn->commit();
            $_SESSION['success_msg'] = "$added_count question(s) added successfully!";
            header("Location: view-assessment-questions.php?assessment_id=$assessment_id");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = $e->getMessage();
        }
    } else {
        $error_msg = "Please select an assessment target and provide at least one complete question.";
    }
}
?>

<!-- GOOGLE FONTS & STYLING -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body {
    background-color: #f8fafc;
    font-family: 'Inter', sans-serif;
}
.question-page {
    max-width: 1040px;
    margin: 40px auto;
    padding: 0 20px;
}
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 16px;
}
.page-title {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.02em;
    margin: 0;
}
.page-subtitle {
    font-size: 14px;
    color: #64748b;
    margin-top: 4px;
}
.panel-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.panel-title {
    font-size: 15px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.question-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.01);
    transition: all 0.2s ease;
}
.question-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.04);
}
.question-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    border-top-left-radius: 12px;
    border-top-right-radius: 12px;
}
.question-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #0f172a;
    color: #ffffff;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
}
.delete-btn {
    border: none;
    background: #fee2e2;
    color: #991b1b;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s;
}
.delete-btn:hover {
    background: #fecaca;
}
.question-body {
    padding: 20px;
}
.form-label-custom {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 6px;
}
.form-control, .form-select {
    border-color: #cbd5e1;
    padding: 9px 12px;
    font-size: 14px;
    border-radius: 8px;
}
.form-control:focus, .form-select:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}
.options-divider {
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin: 20px 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.options-container {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}
.option-row {
    display: grid;
    grid-template-columns: 36px 1fr;
    align-items: center;
    gap: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 10px;
    border-radius: 8px;
}
.option-badge {
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e2e8f0;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #475569;
}
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 24px;
    margin-top: 30px;
    position: sticky;
    bottom: 20px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
    z-index: 100;
}
.custom-alert {
    border-radius: 8px;
    font-size: 14px;
    padding: 12px 16px;
    margin-bottom: 24px;
    border: none;
    background: #fee2e2;
    color: #991b1b;
}
@media (max-width: 768px) {
    .options-container {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="question-page">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Add Questions</h1>
            <div class="page-subtitle">Configure assessments, add multi-choice questions, and set scoring values in bulk.</div>
        </div>
        <div>
            <a href="view-assessment-questions.php" class="btn btn-light border btn-sm fw-semibold px-3 py-2">
                <i class="bi bi-list-ul me-1"></i> View Question List
            </a>
        </div>
    </div>

    <!-- ERROR NOTIFICATION -->
    <?php if ($error_msg): ?>
        <div id="flash-message" class="alert custom-alert d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div><strong>Notice:</strong> <?php echo htmlspecialchars($error_msg); ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" id="bulkForm">

        <!-- ASSESSMENT SELECTOR PANEL -->
        <div class="panel-card">
            <div class="panel-title">
                <i class="bi bi-folder2-open text-primary"></i> Target Assessment Selection
            </div>
            <div class="row">
                <div class="col-md-7">
                    <label class="form-label-custom">Select Assessment *</label>
                    <select name="assessment_id" class="form-select" required>
                        <option value="">-- Choose Assessment Target --</option>
                        <?php
                        $assessment_query = "SELECT id, title FROM assessments ORDER BY id ASC";
                        $assessment_result = $conn->query($assessment_query);
                        
                        if ($assessment_result && $assessment_result->num_rows > 0) {
                            while ($row = $assessment_result->fetch_assoc()) {
                                echo '<option value="' . $row['id'] . '">' . htmlspecialchars($row['title']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- DYNAMIC QUESTIONS WRAPPER -->
        <div id="questions-wrapper">

            <!-- QUESTION CARD (DEFAULT ITEM 1) -->
            <div class="question-card">
                <div class="question-header">
                    <span class="question-number">Question #1</span>
                    <button type="button" class="delete-btn" onclick="removeRow(this)" style="display:none;">Remove Question</button>
                </div>
                
                <div class="question-body">
                    <div class="row g-3">
                        <div class="col-md-10">
                            <label class="form-label-custom">Question Statement</label>
                            <input type="text" name="question_texts[]" class="form-control" placeholder="Type your core question statement here..." >
                        </div>
                        <div class="col-md-2">
                            <label class="form-label-custom">Sort Order</label>
                            <input type="number" name="sort_orders[]" class="form-control order-input" value="1" min="1" required>
                        </div>
                    </div>

                    <div class="options-divider">
                        <i class="bi bi-list-check text-primary"></i> Answer Choices & Associated Scores
                    </div>

                    <div class="options-container">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <div class="option-row">
                                <div class="option-badge"><?php echo chr(65 + $i); ?></div>
                                <input type="text" name="options_text[<?php echo $i; ?>][]" class="form-control form-control-sm" placeholder="Option <?php echo $i + 1; ?>" required>
                               
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- STICKY ACTION BAR -->
        <div class="action-bar">
            <button type="button" class="btn btn-outline-primary btn-sm fw-semibold px-3 py-2" onclick="addQuestionBlock()">
                <i class="bi bi-plus-lg me-1"></i> Add More
            </button>
            <button type="submit" class="btn btn-success btn-sm fw-bold px-4 py-2 shadow-sm">
                <i class="bi bi-check2-all me-1"></i> Submit Questions
            </button>
        </div>

    </form>
</div>

<!-- JAVASCRIPT LOGIC -->
<script>
function addQuestionBlock() {
    const wrapper = document.getElementById('questions-wrapper');
    const newRow = document.createElement('div');
    newRow.className = 'question-card';

    let html = `
        <div class="question-header">
            <span class="question-number">Question #</span>
            <button type="button" class="delete-btn" onclick="removeRow(this)">Remove Question</button>
        </div>
        
        <div class="question-body">
            <div class="row g-3">
                <div class="col-md-10">
                    <label class="form-label-custom">Question Statement</label>
                    <input type="text" name="question_texts[]" class="form-control" placeholder="Type your core question statement here..." >
                </div>
                <div class="col-md-2">
                    <label class="form-label-custom">Sort Order</label>
                    <input type="number" name="sort_orders[]" class="form-control order-input" value="" min="1" required>
                </div>
            </div>

            <div class="options-divider">
                <i class="bi bi-list-check text-primary"></i> Answer Choices & Associated Scores
            </div>

            <div class="options-container">
    `;

    const labels = ['A', 'B', 'C', 'D'];
    for (let i = 0; i < 4; i++) {
        html += `
            <div class="option-row">
                <div class="option-badge">${labels[i]}</div>
                <input type="text" name="options_text[${i}][]" class="form-control form-control-sm" placeholder="Option ${i + 1} description..." required>
            </div>
        `;
    }

    html += `</div></div>`;

    newRow.innerHTML = html;
    wrapper.appendChild(newRow);
    
    updateQuestionNumbers();
    newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function removeRow(btn) {
    const row = btn.closest('.question-card');
    if (!row) return;
    
    row.remove();
    updateQuestionNumbers();
}

function updateQuestionNumbers() {
    const rows = document.querySelectorAll('.question-card');
    
    rows.forEach((row, index) => {
        const numberSpan = row.querySelector('.question-number');
        const deleteBtn = row.querySelector('.delete-btn');
        const orderInput = row.querySelector('.order-input');

        if (numberSpan) {
            numberSpan.textContent = 'Question #' + (index + 1);
        }

        if (orderInput && !orderInput.value) {
            orderInput.value = index + 1;
        }

        if (deleteBtn) {
            deleteBtn.style.display = rows.length === 1 ? 'none' : 'block';
        }
    });
}

setTimeout(function() {
    const alertBox = document.getElementById('flash-message');
    if (alertBox) {
        alertBox.style.transition = "opacity 0.5s ease";
        alertBox.style.opacity = "0";
        setTimeout(() => alertBox.remove(), 500);
    }
}, 3000); 
</script>

<?php require('layout/footer.php'); ?>