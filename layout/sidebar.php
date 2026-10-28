<aside class="admin-sidebar" id="adminSidebar" aria-label="Main navigation">
    <div class="sidebar-header">
        <a class="brand-mark" href="assign-assessments.php" aria-label="Vimhans Academic dashboard">
           <img src="assets/image/logo.png" alt="Logo" class="sidebar-logo" style="background: linear-gradient(135deg, #2563eb, #0f766e); width:70px; padding:6px;">
            <span class="brand-copy">
                <span class="brand-title">Vimhans </span>
                <span class="brand-subtitle">Academic</span>
            </span>
        </a>
    </div>

    <nav class="sidebar-nav">
        <?php
        $current_page = basename($_SERVER['PHP_SELF']);
        $user_role = $_SESSION['role'] ?? 0;
        $is_bprs_active = in_array($current_page, [
            'bprs-assessments.php',
            'bprs-assessment-records.php'
        ], true);
        $is_assessment_active = in_array($current_page, [
            'public-assessment-form.php',
            'assessment-report.php'
        ], true);
        $is_question_bank_active = in_array($current_page, [
            'add-assessment-questions.php',
            'view-assessment-questions.php'
        ], true);
        ?>


        <a class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php" <?= $current_page == 'dashboard.php' ? 'aria-current="page"' : ''; ?>>
            <span class="nav-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
            <span class="nav-text">Dashboard</span>
        </a>

        <a class="nav-link <?= $is_assessment_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#assessmentCollapse" role="button" aria-expanded="<?= $is_assessment_active ? 'true' : 'false'; ?>" aria-controls="assessmentCollapse">
            <span class="nav-icon"><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i></span>
            <span class="nav-text">Assessments</span>
            <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
        </a>

        <div class="collapse <?= $is_assessment_active ? 'show' : ''; ?>" id="assessmentCollapse">
            <ul class="nav flex-column ms-3">
                <li class="nav-item">
                    <a class="nav-link <?= $current_page == 'public-assessment-form.php' ? 'active' : ''; ?>" href="public-assessment-form.php" <?= $current_page == 'public-assessment-form.php' ? 'aria-current="page"' : ''; ?>>
                        <span class="nav-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
                        <span class="nav-text">Patient Assessments</span>
                    </a>
                </li>
            </ul>
        </div>

        <?php if ($user_role == 1): ?>
            <a class="nav-link <?= $is_bprs_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#bprsCollapse" role="button" aria-expanded="<?= $is_bprs_active ? 'true' : 'false'; ?>" aria-controls="bprsCollapse">
                <span class="nav-icon"><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i></span>
                <span class="nav-text">BPRS Assessment</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_bprs_active ? 'show' : ''; ?>" id="bprsCollapse">
                <ul class="nav flex-column ms-3">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page == 'bprs-assessments.php' ? 'active' : ''; ?>" href="bprs-assessments.php" <?= $current_page == 'bprs-assessments.php' ? 'aria-current="page"' : ''; ?>>
                            <span class="nav-icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Form</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page == 'bprs-assessment-records.php' ? 'active' : ''; ?>" href="bprs-assessment-records.php" <?= $current_page == 'bprs-assessment-records.php' ? 'aria-current="page"' : ''; ?>>
                            <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Records</span>
                        </a>
                    </li>
                </ul>
            </div>
            <a class="nav-link <?= $current_page == 'assign-assessments.php' ? 'active' : ''; ?>" href="assign-assessments.php" <?= $current_page == 'assign-assessments.php' ? 'aria-current="page"' : ''; ?>>
                <span class="nav-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                <span class="nav-text">Assign Assessments</span>
            </a>
            <a class="nav-link <?= $current_page == 'assigned-assessments.php' ? 'active' : ''; ?>" href="assigned-assessments.php" <?= $current_page == 'assigned-assessments.php' ? 'aria-current="page"' : ''; ?>>
                <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                <span class="nav-text">Assignment Records</span>
            </a>
        <?php endif; ?>

        <?php if ($user_role == 1): ?>
           

            <a class="nav-link <?= $is_question_bank_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#questionBankCollapse" role="button" aria-expanded="<?= $is_question_bank_active ? 'true' : 'false'; ?>" aria-controls="questionBankCollapse">
                <span class="nav-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span>
                <span class="nav-text">Question Bank</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>

            <div class="collapse <?= $is_question_bank_active ? 'show' : ''; ?>" id="questionBankCollapse">
                <ul class="nav flex-column ms-3">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page == 'add-assessment-questions.php' ? 'active' : ''; ?>" href="add-assessment-questions.php" <?= $current_page == 'add-assessment-questions.php' ? 'aria-current="page"' : ''; ?>>
                            <span class="nav-icon"><i class="bi bi-plus-square" aria-hidden="true"></i></span>
                            <span class="nav-text">Add Questions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page == 'view-assessment-questions.php' ? 'active' : ''; ?>" href="view-assessment-questions.php" <?= $current_page == 'view-assessment-questions.php' ? 'aria-current="page"' : ''; ?>>
                            <span class="nav-icon"><i class="bi bi-list-check" aria-hidden="true"></i></span>
                            <span class="nav-text">Manage Questions</span>
                        </a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </nav>
</aside>