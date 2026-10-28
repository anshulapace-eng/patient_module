<?php
// Output buffering start karein taaki header redirection ki warnings na aayein
ob_start();

$page_title = "Admin - View Questions";
require('layout/base.php'); 

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php'; 

$success_msg = '';$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_question_id'])) {
    $q_id         = intval($_POST['update_question_id']);
    $assess_id    = intval($_POST['assessment_id_redirect'] ?? 0);
    $q_text       = trim($_POST['edit_question_text'] ?? '');
    $q_sort       = intval($_POST['edit_sort_order'] ?? 1);
    $options_text =$_POST['edit_options_text'] ?? [];

    if ($q_id > 0) {$conn->begin_transaction();
        try {
            if (!empty($q_text)) {
                $stmt_check =$conn->prepare("SELECT id FROM assessment_questions WHERE assessment_id = ? AND question_text = ? AND id != ?");
                $stmt_check->bind_param("isi", $assess_id, $q_text,$q_id);
                $stmt_check->execute();$stmt_check->store_result();
                
                if ($stmt_check->num_rows > 0) {
                    throw new Exception("Question already exists: \"" . htmlspecialchars($q_text) . "\"");
                }
                $stmt_check->close();
            }

            $stmt_up_q =$conn->prepare("UPDATE assessment_questions SET question_text = ?, sort_order = ? WHERE id = ?");
            $stmt_up_q->bind_param("sii", $q_text, $q_sort,$q_id);
            $stmt_up_q->execute();$stmt_up_q->close();

            // 2. Options update karein
            $stmt_up_opt =$conn->prepare("UPDATE assessment_options SET option_text = ? WHERE id = ?");
            
            if (isset($options_text) && is_array($options_text)) {
                foreach ($options_text as $opt_id =>$opt_val) {
                    $opt_val = trim($opt_val);
                    $opt_id = intval($opt_id);
                    if ($opt_id > 0) {
                        $stmt_up_opt->bind_param("si", $opt_val, $opt_id);$stmt_up_opt->execute();
                    }
                }
                $stmt_up_opt->close();
            }

            $conn->commit();$_SESSION['success_msg'] = "Question updated successfully!";
            
            if (ob_get_length()) ob_clean();
            header("Location: view-assessment-questions.php?assessment_id=$assess_id");
            exit();

        } catch (Exception $e) {$conn->rollback();
            $_SESSION['error_msg'] = "Update failed: " . $e->getMessage();
            if (ob_get_length()) ob_clean();
            header("Location: view-assessment-questions.php?assessment_id=$assess_id");
            exit();
        }
    }
}


if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $assess_redirect = intval($_GET['assessment_id'] ?? 0);

    if ($del_id > 0) {$conn->begin_transaction();
        try {
            $stmt_opt_del =$conn->prepare("DELETE FROM assessment_options WHERE question_id = ?");
            $stmt_opt_del->bind_param("i", $del_id);
            $stmt_opt_del->execute();$stmt_opt_del->close();

            $stmt_q_del =$conn->prepare("DELETE FROM assessment_questions WHERE id = ?");
            $stmt_q_del->bind_param("i", $del_id);
            $stmt_q_del->execute();$stmt_q_del->close();

            $conn->commit();$_SESSION['success_msg'] = "Question deleted successfully!";
            
            if (ob_get_length()) ob_clean();
            header("Location: view-assessment-questions.php?assessment_id=$assess_redirect");
            exit();
        } catch (Exception $e) {$conn->rollback();
            $_SESSION['error_msg'] = "Delete failed: " . $e->getMessage();
            
            if (ob_get_length()) ob_clean();
            header("Location: view-assessment-questions.php?assessment_id=$assess_redirect");
            exit();
        }
    }
}

// Session Messages Handling
if (isset($_SESSION['success_msg'])) {
    $success_msg =$_SESSION['success_msg'];
    unset($_SESSION['success_msg']); 
}
if (isset($_SESSION['error_msg'])) {
    $error_msg =$_SESSION['error_msg'];
    unset($_SESSION['error_msg']); 
}


$selected_assessment_id = intval($_GET['assessment_id'] ?? 0);


$assessments_result =$conn->query("SELECT id, title FROM assessments ORDER BY id ASC");
$assessments = [];
if ($assessments_result &&$assessments_result->num_rows > 0) {
    while ($row =$assessments_result->fetch_assoc()) {
        $assessments[] =$row;
    }
}


$questions = [];
if ($selected_assessment_id > 0) {
    $q_stmt =$conn->prepare("SELECT id, question_text, sort_order FROM assessment_questions WHERE assessment_id = ? ORDER BY sort_order ASC, id ASC");
    $q_stmt->bind_param("i", $selected_assessment_id);$q_stmt->execute();
    $q_result =$q_stmt->get_result();

    while ($q_row =$q_result->fetch_assoc()) {
        $q_id =$q_row['id'];
        
        $opt_stmt =$conn->prepare("SELECT id, option_text FROM assessment_options WHERE question_id = ? ORDER BY id ASC");
        $opt_stmt->bind_param("i", $q_id);
        $opt_stmt->execute();$opt_result = $opt_stmt->get_result();$options = [];
        while ($opt_row =$opt_result->fetch_assoc()) {
            $options[] =$opt_row;
        }
        $opt_stmt->close();
        
        $q_row['options'] =$options;
        $questions[] =$q_row;
    }
    $q_stmt->close();
}
?>

<!-- GOOGLE FONTS & STYLING -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --primary-color: #4f46e5;
    --primary-hover: #4338ca;
    --bg-main: #f1f5f9;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
    --text-main: #0f172a;
    --text-muted: #64748b;
}

body {
    background-color: var(--bg-main);
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: var(--text-main);
}

.question-page {
    max-width: 1050px;
    margin: 40px auto 80px auto;
    padding: 0 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid var(--border-color);
    padding: 24px 28px;
    border-radius: 16px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
}
.page-title {
    font-size: 24px;
    font-weight: 800;
    color: var(--text-main);
    letter-spacing: -0.03em;
    margin: 0;
}

.filter-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 22px 28px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.form-select, .form-control {
    border-color: var(--border-color) !important;
    padding: 11px 14px;
    font-size: 14px;
    border-radius: 12px;
    box-shadow: none !important;
    font-weight: 500;
}
.form-select:focus, .form-control:focus {
    border-color: var(--primary-color) !important;
    box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1) !important;
}

.question-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
    transition: all 0.25s ease;
    overflow: hidden;
}
.question-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.05);
}
.question-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 24px;
    background: #fafafa;
    border-bottom: 1px solid var(--border-color);
}
.question-number {
    background: var(--primary-color);
    color: #fff;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
}
.question-body {
    padding: 22px 24px;
}
.question-text {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 16px;
    line-height: 1.5;
}

.options-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}
.option-box {
    display: flex;
    align-items: center;
    background: #f8fafc;
    border: 1.5px solid var(--border-color);
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 13px;
}

.modal-content {
    border-radius: 20px !important;
    border: 1px solid var(--border-color);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15) !important;
    overflow: hidden;
}
.modal-header {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-bottom: 1px solid var(--border-color);
    padding: 20px 24px;
}
.modal-body {
    padding: 28px;
    background: #ffffff;
}
.modal-footer {
    background: #f8fafc;
    border-top: 1px solid var(--border-color);
    padding: 16px 24px;
}
.modal-option-box {
    display: flex;
    align-items: center;
    background: #f8fafc;
    border: 1.5px solid var(--border-color);
    padding: 6px 10px;
    border-radius: 12px;
    transition: all 0.2s ease;
}
.modal-option-box:focus-within {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.08);
    background: #ffffff;
}
.modal-option-badge {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e2e8f0;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 800;
    color: #475569;
    margin-right: 10px;
    flex-shrink: 0;
}
.modal-option-input {
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
    padding: 4px 0 !important;
    font-size: 13px !important;
    width: 100%;
}

.empty-state {
    text-align: center;
    padding: 50px 20px;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
}

@media (max-width: 768px) {
    .options-grid { grid-template-columns: 1fr; }
    .page-header { flex-direction: column; align-items: flex-start; gap: 16px; }
}
</style>

<div class="question-page">

    <div class="page-header">
        <div>
            <h1 class="page-title"><i class="bi bi-journal-text text-indigo me-2"></i>View Assessment Questions</h1>
        </div>
        <div>
            <a href="add-assessment-questions.php" class="btn btn-primary btn-sm fw-bold px-4 py-2 shadow-sm rounded-pill">
                <i class="bi bi-plus-lg me-1"></i> Add Questions
            </a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div id="flash-message" class="alert alert-success border-0 shadow-sm py-3 mb-4 rounded-3 fw-medium" style="font-size: 14px; background: #dcfce7; color: #166534;">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div id="flash-message" class="alert alert-danger border-0 shadow-sm py-3 mb-4 rounded-3 fw-medium" style="font-size: 14px; background: #fee2e2; color: #991b1b;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>

    <!-- FILTER BY ASSESSMENT -->
    <div class="filter-card">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-10">
                <label class="form-label fw-bold text-muted text-uppercase" style="font-size: 11px; letter-spacing: 0.08em;">Select Assessment Target *</label>
                <select name="assessment_id" class="form-select" onchange="this.form.submit()" required>
                    <option value="">-- Choose Assessment --</option>
                    <?php foreach ($assessments as$assessment): ?>
                        <option value="<?php echo $assessment['id']; ?>" <?php echo ($selected_assessment_id ==$assessment['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($assessment['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <!-- QUESTIONS DISPLAY AREA -->
    <?php if ($selected_assessment_id > 0): ?>
        <?php if (!empty($questions)): ?>
            <div id="questions-list">
                <?php foreach ($questions as $index =>$q): ?>
                    <div class="question-card">
                        <div class="question-header">
                            <div>
                                <span class="question-number">Question #<?php echo $index + 1; ?></span>
                                <span class="text-muted ms-2" style="font-size: 12px; font-weight: 600;">(Order: <?php echo $q['sort_order']; ?>)</span>
                            </div>
                            <div class="d-flex gap-2">
                                <!-- EDIT BUTTON (Triggers Modern Modal Safely via data-attribute) -->
                                <button type="button" class="btn btn-outline-primary btn-sm py-1 px-3 fw-semibold rounded-pill" style="font-size: 12px;" 
                                    onclick='openEditModal(this)' data-question='<?php echo htmlspecialchars(json_encode($q), ENT_QUOTES, "UTF-8"); ?>'>
                                    <i class="bi bi-pencil-square me-1"></i> Edit
                                </button>
                                <!-- DELETE BUTTON -->
                                <a href="view-assessment-questions.php?assessment_id=<?php echo $selected_assessment_id; ?>&delete_id=<?php echo$q['id']; ?>" class="btn btn-outline-danger btn-sm py-1 px-3 fw-semibold rounded-pill" style="font-size: 12px;" onclick="return confirm('Are you sure you want to delete this question?');">
                                    <i class="bi bi-trash3 me-1"></i> Delete
                                </a>
                            </div>
                        </div>
                        
                        <div class="question-body">
                            <div class="question-text">
                                <?php echo htmlspecialchars($q['question_text']); ?>
                            </div>

                            <div class="text-muted fw-bold mb-3" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em;">
                                <i class="bi bi-list-check me-1"></i> Answer Options
                            </div>

                            <div class="options-grid">
                                <?php if (!empty($q['options'])): ?>
                                    <?php foreach ($q['options'] as $opt_idx =>$opt): ?>
                                        <div class="option-box">
                                            <div><strong class="text-secondary me-2"><?php echo chr(65 + $opt_idx); ?>.</strong> <?php echo htmlspecialchars($opt['option_text']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-muted fst-italic" style="font-size: 13px;">No options found for this question.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-inbox fs-2 text-muted mb-2 d-block"></i>
                No questions found for this assessment. Use the button above to add some!
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-arrow-up-circle fs-2 text-indigo mb-2 d-block"></i>
            Please select an assessment from the dropdown filter above to load its questions.
        </div>
    <?php endif; ?>

</div>

<!-- ULTRA-MODERN EDIT QUESTION BOOTSTRAP MODAL -->
<div class="modal fade" id="editQuestionModal" tabindex="-1" aria-labelledby="editQuestionModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="">
          <div class="modal-header">
            <h5 class="modal-title fw-bold text-dark fs-5" id="editQuestionModalLabel">
                <i class="bi bi-pencil-square text-indigo me-2"></i>Edit Question, Order & Options
            </h5>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          
          <div class="modal-body">
              <input type="hidden" name="update_question_id" id="modal_question_id">
              <input type="hidden" name="assessment_id_redirect" value="<?php echo $selected_assessment_id; ?>">

              <!-- Question Statement & Sort Order Grid -->
              <div class="row g-3 mb-4">
                  <div class="col-md-9">
                      <label class="form-label fw-bold text-muted text-uppercase" style="font-size: 11px; letter-spacing: 0.08em;">Question Statement *</label>
                      <input type="text" name="edit_question_text" id="modal_question_text" class="form-control fw-medium" >
                  </div>
                  <div class="col-md-3">
                      <label class="form-label fw-bold text-muted text-uppercase" style="font-size: 11px; letter-spacing: 0.08em;">Sort Order *</label>
                      <input type="number" name="edit_sort_order" id="modal_sort_order" class="form-control fw-bold text-center" min="1" required>
                  </div>
              </div>

              <div class="mb-3">
                  <label class="form-label fw-bold text-muted text-uppercase" style="font-size: 11px; letter-spacing: 0.08em;">Answer Options (A, B, C, D) *</label>
              </div>

              <div class="row g-3" id="modal_options_container">
                  <!-- Dynamic Option Boxes Injected via JS -->
              </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-light border px-4 py-2 rounded-pill fw-semibold btn-sm text-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-bold btn-sm shadow-sm">Save Changes</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- JAVASCRIPT FOR MODAL HANDLING -->
<script>
function openEditModal(button) {
    // Safely parse JSON data attribute
    const questionData = JSON.parse(button.getAttribute('data-question'));

    // Fill Question ID, Text, and Sort Order
    document.getElementById('modal_question_id').value = questionData.id;
    document.getElementById('modal_question_text').value = questionData.question_text || '';
    document.getElementById('modal_sort_order').value = questionData.sort_order || 1;

    // Fill Options dynamically with the new sleek container layout
    const container = document.getElementById('modal_options_container');
    container.innerHTML = '';

    const labels = ['A', 'B', 'C', 'D'];
    
    if (questionData.options) {
        questionData.options.forEach((opt, index) => {
            let labelName = labels[index] || (index + 1);
            let colDiv = document.createElement('div');
            colDiv.className = 'col-md-6';
            
            colDiv.innerHTML = `
                <div class="modal-option-box">
                    <div class="modal-option-badge">${labelName}</div>
                    <input type="text" name="edit_options_text[${opt.id}]" class="form-control modal-option-input" value="${escapeHtml(opt.option_text)}" required>
                </div>
            `;
            container.appendChild(colDiv);
        });
    }

    // Show Bootstrap Modal
    var myModal = new bootstrap.Modal(document.getElementById('editQuestionModal'));
    myModal.show();
}

// HTML Escape helper to safely parse text inside inputs
function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
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

<?php 
require('layout/footer.php'); 
ob_end_flush();
?>