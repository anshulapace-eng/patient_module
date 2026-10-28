<?php
session_start();
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php'; // Database connection ($conn)

$page_title = "Patient Assessment Dashboard - Vimhans Academic";

// Do not preload a patient from the session. Load records only after a search selection.
$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
$patient_data = null;
$assessments = [];
$completed_assessments = [];

if ($patient_id > 0 && isset($conn)) {
    // Fetch Patient Details
    $p_query = "SELECT id, CONCAT(first_name, ' ', IF(last_name='.', '', last_name)) as full_name, phone, email FROM users WHERE id = ? LIMIT 1";
    if ($stmt_p = $conn->prepare($p_query)) {
        $stmt_p->bind_param("i", $patient_id);
        $stmt_p->execute();
        $p_res = $stmt_p->get_result();
        $patient_data = $p_res->fetch_assoc();
        $stmt_p->close();
    }

    $assigned_query = "SELECT aa.id AS assignment_id, aa.assessment_id, aa.status, aa.assigned_date,
        a.title, a.description
        FROM assigned_assessments aa
        INNER JOIN assessments a ON a.id = aa.assessment_id
        WHERE aa.patient_id = ? ORDER BY aa.id ASC";
    if ($stmt_c = $conn->prepare($assigned_query)) {
        $stmt_c->bind_param("i", $patient_id);
        $stmt_c->execute();
        $c_res = $stmt_c->get_result();
        while ($c_row = $c_res->fetch_assoc()) {
            $assessments[] = $c_row;
            if ($c_row['status'] === '1' || strtolower($c_row['status']) === 'completed') {
                $completed_assessments[] = intval($c_row['assignment_id']);
            }
        }
        $stmt_c->close();
    }
}



$total_assigned = count($assessments);
$total_completed = count($completed_assessments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendors/bootstrap-icons/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        :root {
            /* Premium Medical Palette */
            --brand-main: #0f766e; 
            --brand-light: #f0fdfa;
            --brand-hover: #0d9488;
            
            --success-color: #059669;
            --success-bg: #d1fae5;
            
            --neutral-bg: #f8fafc;
            --surface: #ffffff;
            
            --text-dark: #0f172a;
            --text-muted: #475569;
            --border-color: #e2e8f0;

            --radius-md: 10px;
            --radius-lg: 16px;

            --shadow-premium: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            --shadow-card: 0 2px 4px rgba(0,0,0,0.03), 0 1px 2px rgba(0,0,0,0.02);
        }

        body { 
            background-color: var(--neutral-bg); 
            font-family: 'Inter', sans-serif; 
            color: var(--text-dark); 
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6 { font-family: 'Plus Jakarta Sans', sans-serif; }

        .dashboard-container { max-width: 980px; margin: 40px auto; padding: 0 20px; }

        /* Page Header */
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; }
        .header-titles h2 { font-size: 26px; font-weight: 800; color: var(--text-dark); margin-bottom: 4px; letter-spacing: -0.5px; }
        .header-titles p { font-size: 14px; color: var(--text-muted); margin: 0; font-weight: 500; }
        .btn-back { 
            background: var(--surface); border: 1px solid var(--border-color); 
            color: var(--text-dark); padding: 8px 16px; border-radius: 8px; 
            font-weight: 600; font-size: 13px; text-decoration: none; 
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;
        }
        .btn-back:hover { background: var(--neutral-bg); border-color: #cbd5e1; color: var(--brand-main); }

        /* UNIFIED APP PANEL */
        .unified-panel {
            background: var(--surface); border-radius: var(--radius-lg);
            box-shadow: var(--shadow-premium); border: 1px solid var(--border-color);
            overflow: hidden; 
        }

        /* 1. Panel Header (Search) */
        .panel-header { background: #fdfdfd; padding: 30px 32px; border-bottom: 1px solid var(--border-color); }
        .search-area label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px; }
        .select2-container .select2-selection--single { height: 48px; border: 1.5px solid var(--border-color); border-radius: var(--radius-md); padding: 8px 14px; font-size: 15px; font-weight: 500; background: var(--surface); }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 46px; right: 10px;}
        .select2-container--default.select2-container--focus .select2-selection--single { border-color: var(--brand-main); box-shadow: 0 0 0 3px var(--brand-light); outline: none; }

        /* Profile Card */
        .profile-card {
            background: var(--surface); border: 1px solid var(--border-color);
            border-radius: var(--radius-md); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); overflow: hidden;
        }
        .profile-header {
            display: flex; align-items: center; gap: 16px; padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: #fafafa;
        }
        .profile-avatar {
            width: 46px; height: 46px; border-radius: 50%; background: var(--brand-light); color: var(--brand-main);
            display: flex; align-items: center; justify-content: center; font-size: 22px;
        }
        .profile-identity h3 { margin: 0 0 2px 0; font-size: 18px; font-weight: 700; color: var(--text-dark); }
        .profile-identity span { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .status-badge { background: var(--success-bg); color: var(--success-color); padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; margin-left: auto; letter-spacing: 0.3px; }
        .profile-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); padding: 20px 24px; gap: 24px; }
        .detail-item { display: flex; flex-direction: column; gap: 6px; }
        .detail-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px; }
        .detail-label i { color: var(--brand-main); font-size: 14px; }
        .detail-value { font-size: 15px; font-weight: 600; color: var(--text-dark); }

        /* 2. Panel Body (The List) */
        .panel-body { background: #f8fafc; padding: 0 0 32px 0; }
        .list-heading {
            padding: 24px 32px 20px 32px; font-size: 16px; font-weight: 700; color: var(--text-dark);
            display: flex; align-items: center; gap: 10px; background: #ffffff; border-bottom: 1px solid var(--border-color);
        }

        .assessment-list-container {
            padding: 24px 32px 0 32px;
            display: flex; flex-direction: column; gap: 16px;
        }

        /* Improved Card Style Rows */
        .asm-row {
            background: var(--surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px 24px;
            display: flex; justify-content: space-between; align-items: center;
            transition: all 0.25s ease;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
        }
        .asm-row:hover {
            box-shadow: 0 8px 16px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
            transform: translateY(-2px);
        }
        
        .asm-row::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: transparent;
        }
        .asm-row.completed::before { background: var(--success-color); }
        
        .asm-details { flex: 1; padding-right: 20px; }
        .asm-title-wrap { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
        .asm-title-wrap h4 { font-size: 16px; font-weight: 700; color: var(--text-dark); margin: 0; }
        
        .asm-meta { font-size: 13px; color: var(--text-muted); font-weight: 500; margin: 0; display: flex; align-items: center; gap: 8px;}
        .dot { width: 4px; height: 4px; background: #cbd5e1; border-radius: 50%; display: inline-block; }

        /* Sleek Status Pills */
        .status-pill { padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 4px;}
        .pill-done { background: var(--success-bg); color: var(--success-color); }
        .pill-wait { background: #e0f2fe; color: #0284c7; }

        /* Professional Buttons */
        .asm-actions { display: flex; gap: 16px; align-items: center; }
        
        /* The "Submitted" Text Tag */
        .status-tag {
            font-size: 13px; font-weight: 600; color: var(--success-color);
            display: flex; align-items: center; gap: 6px;
            padding: 8px 12px; border-radius: 6px;
            background: #f0fdf4; border: 1px solid #dcfce7;
        }

        .btn-modern {
            padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; 
            display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.2s;
            cursor: pointer;
        }
        .btn-primary-solid { background: var(--brand-main); color: white; border: 1px solid var(--brand-main); }
        .btn-primary-solid:hover { background: var(--brand-hover); color: white; box-shadow: 0 4px 12px rgba(15, 118, 110, 0.2); }
        
        .btn-report { background: white; color: var(--brand-main); border: 1.5px solid var(--border-color); }
        .btn-report:hover { background: var(--brand-main); color: white; border-color: var(--brand-main); }

        /* Responsive Design */
        @media (max-width: 768px) {
            .profile-details { grid-template-columns: 1fr; gap: 16px; }
            .asm-row { flex-direction: column; align-items: flex-start; gap: 16px; padding: 20px; }
            .asm-actions { width: 100%; justify-content: space-between;}
            .page-header { flex-direction: column; align-items: flex-start; gap: 16px; }
            .assessment-list-container { padding: 20px 20px 0 20px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        
        <!-- Header -->
        <div class="page-header">
            <div class="header-titles">
                <h2>Clinical Assessments</h2>
                <p>Manage, evaluate and track patient progress smoothly.</p>
            </div>
            <a href="#" class="btn-back" onclick="history.back();"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        <!-- UNIFIED APP PANEL -->
        <div class="unified-panel">
            
            <!-- TOP SECTION: Search & Profile -->
            <div class="panel-header">
                <div class="search-area" style="<?php echo $patient_data ? 'margin-bottom: 24px;' : 'margin-bottom: 0;'; ?>">
                    <form method="GET" action="" id="patientSelectForm">
                        <label><i class="bi bi-search me-1"></i> Lookup Assigned Patient</label>
                        <select id="assigned_patient_search" name="patient_id" class="form-control" required style="width: 100%;">
                            <?php if ($patient_data): ?>
                                <option value="<?php echo $patient_data['id']; ?>" selected><?php echo htmlspecialchars($patient_data['full_name'] . ' (' . $patient_data['phone'] . ')'); ?></option>
                            <?php else: ?>
                                <option value=""></option>
                            <?php endif; ?>
                        </select>
                    </form>
                </div>

                <?php if ($patient_data): ?>
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <i class="bi bi-person-fill"></i>
                        </div>
                        <div class="profile-identity">
                            <h3><?php echo htmlspecialchars($patient_data['full_name']); ?></h3>
                            <span>Patient Record</span>
                        </div>
                        <div class="status-badge"><i class="bi bi-check-circle-fill me-1" style="font-size:11px;"></i> Active</div>
                    </div>
                    <div class="profile-details">
                        <div class="detail-item">
                            <span class="detail-label"><i class="bi bi-telephone"></i> Phone Number</span>
                            <span class="detail-value"><?php echo htmlspecialchars($patient_data['phone']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><i class="bi bi-envelope"></i> Email Address</span>
                            <span class="detail-value"><?php echo htmlspecialchars(!empty($patient_data['email']) ? $patient_data['email'] : 'Not Provided'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><i class="bi bi-bar-chart-steps"></i> Progress</span>
                            <span class="detail-value"><?php echo $total_completed; ?> / <?php echo $total_assigned; ?> Modules Completed</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- BOTTOM SECTION: Connected List -->
            <?php if ($patient_data): ?>
            <div class="panel-body">
                <div class="list-heading">
                    <i class="bi bi-list-check" style="font-size: 20px; color: var(--brand-main);"></i> Assigned Modules
                </div>
                
                <div class="assessment-list-container">
                    <?php 
                    foreach ($assessments as $index => $asm): 
                        $asm_id = (int) $asm['assessment_id'];
                        $assignment_id = (int) $asm['assignment_id'];
                        
                        $is_completed = in_array($assignment_id, $completed_assessments);
                    ?>
                        <div class="asm-row <?php echo $is_completed ? 'completed' : ''; ?>">
                            
                            <div class="asm-details">
                                <div class="asm-title-wrap">
                                    <h4><?php echo htmlspecialchars($asm['title']); ?></h4>
                                    <?php if ($is_completed): ?>
                                        <span class="status-pill pill-done"><i class="bi bi-check-circle-fill"></i> Completed</span>
                                    <?php else: ?>
                                        <span class="status-pill pill-wait"><i class="bi bi-clock-fill"></i> Pending</span>
                                    <?php endif; ?>
                                </div>
                                <div class="asm-meta">
                                    <span>Sequence: #<?php echo ($index + 1); ?></span>
                                    <span class="dot"></span>
                                    <span>Assigned: <?php echo !empty($asm['assigned_date']) ? date('d M Y', strtotime($asm['assigned_date'])) : 'Recently'; ?></span>
                                </div>
                            </div>
                            
                            <div class="asm-actions">
                                <?php if (!$is_completed): ?>
                                    <a href="assessment-form.php?assessment_id=<?php echo $asm_id; ?>&assignment_id=<?php echo (int) $asm['assignment_id']; ?>&patient_id=<?php echo $patient_id; ?>" class="btn-modern btn-primary-solid">
                                        Take Assessment <i class="bi bi-arrow-right"></i>
                                    </a>
                                <?php else: ?>
                                    <!-- Beautiful Submitted Status Tag -->
                                    <span class="status-tag">
                                        <i class="bi bi-check2-all"></i> Submitted
                                    </span>
                                    <!-- Clean Outline Report Button -->
                                     <?php  if(($_SESSION['role'] ?? 0) == 1){ ?>
                                    <a href="assessment-report.php?assessment_id=<?php echo $asm_id; ?>&patient_id=<?php echo $patient_id; ?>" class="btn-modern btn-report">
                                        <i class="bi bi-bar-chart-fill"></i> Report
                                    </a>
                                    <?php }?>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php 
                    endforeach; 
                    ?>
                </div>
            </div>
            <?php endif; ?>

        </div> <!-- End Unified Panel -->
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
$(document).ready(function() {
    $('#assigned_patient_search').select2({
        placeholder: 'Enter 10-digit phone number...',
        allowClear: true,
        width: '100%',
        minimumInputLength: 10,
        maximumInputLength: 10,
        language: {
            inputTooShort: function () {
                return "Please enter exactly 10 digits to search...";
            },
            inputTooLong: function () {
                return "Phone number cannot exceed 10 digits.";
            },
            noResults: function () {
                return "No patient found with this number.";
            }
        },
        ajax: {
            url: 'get_assigned_users.php',
            dataType: 'json',
            delay: 300,
            data: function(params) {
                return { q: params.term };
            },
            transport: function (params, success, failure) {
                var searchWord = params.data.q || '';
                if (searchWord.length !== 10) {
                    return false; 
                }
                var $request = $.ajax(params);
                $request.then(success);
                $request.fail(failure);
                return $request;
            },
            processResults: function(data) {
                return { results: data };
            },
            cache: true
        }
    });

    $(document).on('keypress', '.select2-search__field', function(e) {
        if (e.which != 8 && e.which != 0 && (e.which < 48 || e.which > 57)) {
            return false; 
        }
    });

    $(document).on('input', '.select2-search__field', function() {
        var val = $(this).val();
        if (val.length > 10) {
            $(this).val(val.substring(0, 10)); 
        }
    });

    $('#assigned_patient_search').on('select2:select', function(e) {
        var data = e.params.data;
        window.location.href = '?patient_id=' + data.id;
    });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>