<?php
ob_start(); 
$page_title = "Admin - Add Assessment Questions";
require('layout/base.php'); 

error_reporting(E_ALL);
ini_set('display_errors', 1);


include 'config.php';
$success_msg = '';
$error_msg = '';


// if (isset($conn)) {
//     $conn->query("CREATE TABLE IF NOT EXISTS assigned_assessments (
//         id INT AUTO_INCREMENT PRIMARY KEY, 
//         patient_id INT, 
//         assessment_id INT, 
//         status VARCHAR(50) DEFAULT 'Pending', 
//         assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
//     )");
// }


$assessments = [];
if (isset($conn)) {
    $res = $conn->query("SELECT id, title FROM assessments ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $assessments[] = $row;
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_assessments'])) {$patient_type = $_POST['patient_type'] ?? 'existing';$patient_id = 0;
    $assessment_ids =$_POST['assessment_ids'] ?? [];

    if (empty($assessment_ids)) {$error_msg = "Please select at least one assessment to assign.";
    } else {
        if ($patient_type == 'new') {
            // Register New Patient into `users` table
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '.');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');

            $raw_password = trim($_POST['password'] ?? '');$password_to_store = !empty($raw_password) ? sha1($raw_password) : sha1('12345678');

            $check =$conn->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
            $check->bind_param("s", $phone);
            $check->execute();$check->store_result();

            if ($check->num_rows > 0) {$error_msg = "This phone number is already registered! Please use 'Existing Registered Patient' option.";
            } elseif (!empty($email)) {
                $check_email =$conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $check_email->bind_param("s", $email);
                $check_email->execute();$check_email->store_result();

                if ($check_email->num_rows > 0) {$error_msg = "This email address is already registered! Please use another email or log in.";
                }
                $check_email->close();
            }
            
            $check->close();

            // Agar koi error nahi hai toh naya user insert karein
            if (empty($error_msg)) {
                $stmt_ins =$conn->prepare("INSERT INTO users (first_name, last_name, phone, email, address, password, status, role_id, patient_id) VALUES (?, ?, ?, ?, ?, ?, 1, 2, " . PATIENT_SCOPE_ID . ")");
                if ($stmt_ins) {$stmt_ins->bind_param("ssssss", $first_name,$last_name, $phone,$email, $address,$password_to_store);
                    if ($stmt_ins->execute()) {
                        $patient_id =$conn->insert_id;
                    } else {
                        $error_msg = "The patient could not be registered. Please try again.";
                    }
                    $stmt_ins->close();
                } else {
                    $error_msg = "The patient could not be registered. Please try again.";
                }
            }
        } else {
            $patient_id = intval($_POST['existing_patient_id'] ?? 0);
            if ($patient_id == 0) {$error_msg = "Please search and select a valid patient.";
            }
        }

      
        if ($patient_id > 0 && empty($error_msg)) {
            $patient_check = $conn->prepare(
                "SELECT id FROM users WHERE id = ? AND role_id = 2 AND patient_id = " . PATIENT_SCOPE_ID . " LIMIT 1"
            );
            if (!$patient_check) {
                $error_msg = "The selected patient could not be validated. Please try again.";
            } else {
                $patient_check->bind_param("i", $patient_id);
                $patient_check->execute();
                $patient_check->store_result();
                if ($patient_check->num_rows !== 1) {
                    $error_msg = "Please select a valid patient.";
                }
                $patient_check->close();
            }
        }

        if ($patient_id > 0 && empty($error_msg)) {
            $newly_assigned_count = 0;
            $already_pending_count = 0;

           
            $check_stmt = $conn->prepare("SELECT id FROM assigned_assessments WHERE patient_id = ? AND assessment_id = ? AND status = 'Pending' LIMIT 1");
            $stmt_assign = $conn->prepare("INSERT INTO assigned_assessments (patient_id, assessment_id, status) VALUES (?, ?, 'Pending')");

            foreach ($assessment_ids as $a_id) {
                $check_stmt->bind_param("ii", $patient_id, $a_id);
                $check_stmt->execute();
                $check_stmt->store_result();

                if ($check_stmt->num_rows > 0) {
                    $already_pending_count++;
                } else {
                    $stmt_assign->bind_param("ii", $patient_id, $a_id);
                    if ($stmt_assign->execute()) {
                        $newly_assigned_count++;
                    }
                }
            }

            $check_stmt->close();
            $stmt_assign->close();

            if ($newly_assigned_count > 0 && $already_pending_count == 0) {
                $success_msg = "$newly_assigned_count Assessment(s) successfully assigned to the patient!";
            } elseif ($newly_assigned_count > 0 && $already_pending_count > 0) {
                $success_msg = "$newly_assigned_count assessment(s) assigned! ($already_pending_count skipped because they are already 'Pending').";
            } elseif ($newly_assigned_count == 0 && $already_pending_count > 0) {
                $error_msg = "Selected assessment(s) are already 'Pending' for this patient. Patient must complete them first.";
            } else {
                $error_msg = "Failed to assign assessments. Please try again.";
            }
        }
    }
}
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Select2 CSS (Only for Patient Search) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        :root {
            --primary: #4f46e5;
            --bg: #f8fafc;
            --card: #ffffff;
            --border: #cbd5e1;
        }

        body {
            background-color: var(--bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #0f172a;
        }

        .main-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 15px;
        }

        .custom-card {
            background: var(--card);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 800;
            border-bottom: 2px solid var(--primary);
            display: inline-block;
            padding-bottom: 6px;
            margin-bottom: 20px;
            color: var(--primary);
        }

        /* Select2 Customization */
        .select2-container .select2-selection--single {
            min-height: 44px;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 4px;
            font-size: 14px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px;
        }

        .btn-custom {
            background: var(--primary);
            color: #fff;
            font-weight: 700;
            padding: 14px 20px;
            border-radius: 8px;
            border: none;
            width: 100%;
            transition: all 0.2s;
            font-size: 15px;
        }

        .btn-custom:hover {
            background: #4338ca;
            color: white;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 6px;
        }

        .form-control {
            border-radius: 6px;
            font-size: 14px;
            padding: 10px 14px;
            border-color: var(--border);
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* Radio Button Styling */
        .radio-group {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .radio-card {
            flex: 1;
            min-width: 250px;
            border: 1.5px solid var(--border);
            padding: 16px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            margin: 0;
        }

        .radio-card:hover {
            border-color: #94a3b8;
        }

        .radio-card.active {
            border-color: var(--primary);
            background: #eef2ff;
            color: var(--primary);
            font-weight: 700;
        }

        input[type="radio"],
        input[type="checkbox"] {
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        /* Assessments Checkbox List Box Styling */
        .assessments-box {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 15px;
            background: #fafafa;
        }

        .assessment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 10px;
            border-radius: 4px;
            transition: background 0.15s;
            cursor: pointer;
        }

        .assessment-item:hover {
            background: #f1f5f9;
        }
    </style>


    <div class="main-container">
        <h2 class="fw-bold mb-4">Assign Assessments</h2>

        <?php if ($success_msg): ?>
            <div class="alert alert-success fw-bold border-0 shadow-sm">✓ <?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger fw-bold border-0 shadow-sm">⚠ <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- 1. PATIENT SELECTION CARD -->
            <div class="custom-card">
                <div class="section-title">1. Patient Details</div>

                <div class="radio-group">
                    <label class="radio-card active" id="lbl_existing">
                        <input type="radio" name="patient_type" value="existing" checked> Existing Registered Patient
                    </label>
                    <label class="radio-card" id="lbl_new">
                        <input type="radio" name="patient_type" value="new"> Register New Patient
                    </label>
                </div>

                <!-- Existing Patient Dropdown (Select2) -->
                <div id="existing_patient_section">
                    <label class="form-label">Search Patient (By Phone or Name) *</label>
                    <select name="existing_patient_id" id="patient_search" class="form-control" required style="width: 100%;">
                        <option value=""></option>
                    </select>
                    <small class="text-muted d-block mt-2">Start typing a mobile number or name to search...</small>
                </div>

                <!-- New Patient Form (Hidden by default) -->
                <div id="new_patient_section" style="display: none;">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" id="req_fname" class="form-control" placeholder="E.g. Rahul">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" placeholder="E.g. Sharma">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile Number *</label>
                            <input type="text" name="phone" id="req_phone" class="form-control" placeholder="10 digit number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Leave blank for default (12345678)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" placeholder="City / Area">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. ASSESSMENT ASSIGNMENT CARD -->
            <div class="custom-card">
                <div class="section-title">2. Select Assessments</div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Choose Assessment(s) to Assign *</label>
                    <div>
                        <button type="button" id="select_all_btn" class="btn btn-sm btn-outline-primary py-0 px-2">Select All</button>
                        <button type="button" id="deselect_all_btn" class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1">Deselect All</button>
                    </div>
                </div>

                <!-- Assessments Checkbox Box -->
                <div class="assessments-box">
                    <?php if (empty($assessments)): ?>
                        <p class="text-muted text-center my-2">No assessments found in database.</p>
                    <?php else: ?>
                        <?php foreach ($assessments as $asm): ?>
                            <label class="assessment-item w-100 mb-1">
                                <input type="checkbox" name="assessment_ids[]" value="<?php echo $asm['id']; ?>" class="assessment-checkbox">
                                <span class="fw-medium text-dark"><?php echo htmlspecialchars($asm['title']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <small class="text-muted d-block mt-2">You can select multiple assessments via checkboxes.</small>

                <button type="submit" name="assign_assessments" class="btn-custom mt-4">
                    Assign Assessments Now
                </button>
            </div>
        </form>
    </div>

    <!-- jQuery Must be loaded before Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- JAVASCRIPT FOR SELECT2, FORM TOGGLE & CHECKBOXES -->
    <script>
        $(document).ready(function() { 

         
            $('#patient_search').select2({
                placeholder: 'Search patient by phone number or name...',
                allowClear: true,
                width: '100%',
                minimumInputLength: 2,
                ajax: {
                    url: 'ajax_get_users.php',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                }
            });

            // 2. Select All / Deselect All functionality
            $('#select_all_btn').on('click', function() {
                $('.assessment-checkbox').prop('checked', true);
            });

            $('#deselect_all_btn').on('click', function() {
                $('.assessment-checkbox').prop('checked', false);
            });

            // 3. Toggle Logic between Existing & New Patient with disabled state handling
            $('input[name="patient_type"]').on('change', function() {
                var selectedVal = $(this).val();

                // Update styling
                $('.radio-card').removeClass('active');
                $(this).closest('.radio-card').addClass('active');

                if (selectedVal === 'new') {
                    // Hide Existing, Show New
                    $('#existing_patient_section').hide();
                    $('#new_patient_section').fadeIn();

                    // Enable new patient fields, Disable existing search
                    $('#new_patient_section :input').prop('disabled', false);
                    $('#patient_search').prop('disabled', true);

                    // Adjust required attributes
                    $('#req_fname, #req_phone').prop('required', true);
                    $('#patient_search').prop('required', false);
                } else {
                    // Hide New, Show Existing
                    $('#new_patient_section').hide();
                    $('#existing_patient_section').fadeIn();

                    // Disable new patient fields, Enable existing search
                    $('#new_patient_section :input').prop('disabled', true);
                    $('#patient_search').prop('disabled', false);

                    // Adjust required attributes
                    $('#req_fname, #req_phone').prop('required', false);
                    $('#patient_search').prop('required', true);
                }
            });

            // Trigger change on load to set proper disabled states correctly
            $('input[name="patient_type"]:checked').trigger('change');
        });
    </script>
<?php 
require('layout/footer.php'); 
ob_end_flush();
?>