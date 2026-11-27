<style>
    /* Enterprise Sidebar Styling */
    .admin-sidebar {
        background: linear-gradient(180deg, #0b192c 0%, #0f2438 60%, #0d2b36 100%) !important;
        color: #cbd5e1;
        display: flex;
        flex-direction: column;
        border-right: 1px solid rgba(255, 255, 255, 0.06);
        box-shadow: 6px 0 28px rgba(2, 6, 23, 0.22);
        transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1), width 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Brand Header */
    .admin-sidebar .sidebar-header {
        padding: 18px 18px 16px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        background: rgba(255, 255, 255, 0.02);
    }

    .admin-sidebar .brand-mark {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        color: #fff;
    }

    .admin-sidebar .sidebar-logo-wrap {
        width: 60px;
        height: 50px;
        border-radius: 12px;
        background: #fff;
        padding: 6px;
        display: grid;
        place-items: center;
        box-shadow: 0 6px 16px rgba(20, 184, 166, 0.28);
        flex-shrink: 0;
    }

    .admin-sidebar .sidebar-logo-wrap img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .admin-sidebar .brand-copy {
        display: flex;
        flex-direction: column;
        line-height: 1.15;
    }

    .admin-sidebar .brand-title {
        font-size: 16px;
        font-weight: 800;
        letter-spacing: -0.01em;
        color: #ffffff;
    }

    .admin-sidebar .brand-subtitle {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: #5eead4;
        margin-top: 2px;
    }

    /* Scrollable Navigation Area */
    .admin-sidebar .sidebar-nav {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 14px 12px 24px;
        scrollbar-width: thin;
        scrollbar-color: rgba(148, 163, 184, 0.25) transparent;
    }

    .admin-sidebar .sidebar-nav::-webkit-scrollbar {
        width: 5px;
    }

    .admin-sidebar .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.25);
        border-radius: 99px;
    }

    /* Section Labels */
    .admin-sidebar .sidebar-section-label {
        padding: 14px 12px 6px;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #64748b;
    }

    .admin-sidebar .sidebar-section-label:first-child {
        padding-top: 4px;
    }

    /* Primary Nav Links */
    .admin-sidebar .nav-link {
        position: relative;
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 9px 12px;
        margin-bottom: 3px;
        border-radius: 10px;
        color: #cbd5e1;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color 0.18s ease, color 0.18s ease, transform 0.16s ease;
    }

    .admin-sidebar .nav-link:hover {
        background: rgba(255, 255, 255, 0.06);
        color: #ffffff;
    }

    .admin-sidebar .nav-link.active {
        background: linear-gradient(90deg, rgba(15, 118, 110, 0.34), rgba(20, 184, 166, 0.14));
        color: #ffffff;
        font-weight: 700;
        box-shadow: inset 0 0 0 1px rgba(94, 234, 212, 0.2);
    }

    .admin-sidebar .nav-link.active::before {
        content: "";
        position: absolute;
        left: 0;
        top: 8px;
        bottom: 8px;
        width: 3px;
        border-radius: 0 4px 4px 0;
        background: #2dd4bf;
    }

    .admin-sidebar .nav-icon {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.05);
        color: #94a3b8;
        display: grid;
        place-items: center;
        font-size: 14px;
        flex-shrink: 0;
        transition: background-color 0.18s ease, color 0.18s ease;
    }

    .admin-sidebar .nav-link:hover .nav-icon {
        background: rgba(255, 255, 255, 0.1);
        color: #e2e8f0;
    }

    .admin-sidebar .nav-link.active .nav-icon {
        background: #0f766e;
        color: #ffffff;
        box-shadow: 0 3px 10px rgba(15, 118, 110, 0.35);
    }

    .admin-sidebar .nav-text {
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Smooth Rotating Chevron */
    .admin-sidebar .collapse-indicator {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        color: #64748b;
        transition: transform 0.24s cubic-bezier(0.4, 0, 0.2, 1), color 0.18s ease;
    }

    .admin-sidebar .nav-link[aria-expanded="true"] .collapse-indicator {
        transform: rotate(180deg);
        color: #5eead4;
    }

    /* Smooth Submenu Tree */
    .admin-sidebar .sidebar-submenu {
        margin: 2px 0 6px 22px;
        padding-left: 12px;
        border-left: 1.5px solid rgba(148, 163, 184, 0.18);
    }

    .admin-sidebar .sidebar-submenu .nav-link {
        padding: 7px 10px;
        font-size: 12.5px;
        font-weight: 500;
        color: #94a3b8;
        margin-bottom: 2px;
    }

    .admin-sidebar .sidebar-submenu .nav-link:hover {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.05);
    }

    .admin-sidebar .sidebar-submenu .nav-link.active {
        background: rgba(20, 184, 166, 0.14);
        color: #5eead4;
        font-weight: 700;
        box-shadow: none;
    }

    .admin-sidebar .sidebar-submenu .nav-link.active::before {
        display: none;
    }

    .admin-sidebar .sidebar-submenu .nav-icon {
        width: 22px;
        height: 22px;
        background: transparent;
        font-size: 12.5px;
    }

    .admin-sidebar .sidebar-submenu .nav-link.active .nav-icon {
        background: transparent;
        color: #2dd4bf;
        box-shadow: none;
    }

    /* Smooth Bootstrap Collapse */
    .admin-sidebar .collapsing {
        transition: height 0.24s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }
</style>

<aside class="admin-sidebar" id="adminSidebar" aria-label="Main navigation">
    <div class="sidebar-header">
        <a class="brand-mark" href="dashboard.php" aria-label="Vimhans Academic dashboard">
            <span class="sidebar-logo-wrap">
                <img src="assets/image/vim_blue.png" alt="Logo">
            </span>
            <span class="brand-copy">
                <span class="brand-title">Vimhans</span>
                <span class="brand-subtitle">Academic</span>
            </span>
        </a>
    </div>

    <nav class="sidebar-nav" id="sidebarAccordion">
        <?php
        $current_page = basename($_SERVER['PHP_SELF']);
        $user_role = (int) ($_SESSION['role'] ?? 0);
        $report_aid = isset($_GET['assessment_id']) ? (int) $_GET['assessment_id'] : 0;

        $is_patient_portal_active = in_array($current_page, [
            'public-assessment-form.php',
            'assign-assessments.php'
        ], true);

        $is_bprs_active = in_array($current_page, [
            'bprs-assessments.php',
            'bprs-assessment-records.php',
            'bprs-assessment-score.php'
        ], true);

        $is_bdi_active = in_array($current_page, [
            'bdi-assessments.php',
            'bdi-assessment-records.php',
            'bdi-assessment-score.php'
        ], true)
            || ($current_page === 'assessment-report.php' && $report_aid === 1)
            || ($current_page === 'assigned-assessments.php' && $report_aid === 1);

        $is_bai_active = in_array($current_page, [
            'bai-assessments.php',
            'bai-assessment-records.php',
            'bai-assessment-score.php'
        ], true)
            || ($current_page === 'assessment-report.php' && $report_aid === 2)
            || ($current_page === 'assigned-assessments.php' && $report_aid === 2);

        $is_ace_iii_active = in_array($current_page, [
            'ace-iii-assessments.php',
            'ace-iii-assessment-records.php',
            'ace-iii-assessment-score.php'
        ], true);

        $is_panss_active = in_array($current_page, [
            'panss-assessments.php',
            'panss-assessment-records.php',
            'panss-assessment-score.php'
        ], true);

        $is_ymrs_active = in_array($current_page, [
            'ymrs-assessments.php',
            'ymrs-assessment-records.php',
            'ymrs-assessment-score.php'
        ], true);

        $is_patient_assessment_active = in_array($current_page, [
            'public-assessment-form.php',
            'assessment-form.php',
            'assessment-report.php',
            'ace-iii-assessments.php',
            'ace-iii-assessment-records.php',
            'ace-iii-assessment-score.php'
        ], true);

        $is_question_bank_active = in_array($current_page, [
            'add-assessment-questions.php',
            'view-assessment-questions.php'
        ], true);
        ?>

        <div class="sidebar-section-label">Overview</div>

        <a class="nav-link <?= $current_page === 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php" <?= $current_page === 'dashboard.php' ? 'aria-current="page"' : ''; ?>>
            <span class="nav-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
            <span class="nav-text">Dashboard</span>
        </a>

        <?php if ($user_role === 1): ?>
            <a class="nav-link <?= $current_page === 'all-assessment-records.php' ? 'active' : ''; ?>" href="all-assessment-records.php" <?= $current_page === 'all-assessment-records.php' ? 'aria-current="page"' : ''; ?>>
                <span class="nav-icon"><i class="bi bi-collection" aria-hidden="true"></i></span>
                <span class="nav-text">All Records</span>
            </a>

            <!-- Patient Portal (Patient Assessments + Assign Assessments) -->
            <a class="nav-link <?= $is_patient_portal_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#patientPortalCollapse" role="button" aria-expanded="<?= $is_patient_portal_active ? 'true' : 'false'; ?>" aria-controls="patientPortalCollapse">
                <span class="nav-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
                <span class="nav-text">Patient Portal</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_patient_portal_active ? 'show' : ''; ?>" id="patientPortalCollapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'public-assessment-form.php' ? 'active' : ''; ?>" href="public-assessment-form.php" <?= $current_page === 'public-assessment-form.php' ? 'aria-current="page"' : ''; ?>>
                            <span class="nav-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
                            <span class="nav-text">Patient Assessments</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'assign-assessments.php' ? 'active' : ''; ?>" href="assign-assessments.php" <?= $current_page === 'assign-assessments.php' ? 'aria-current="page"' : ''; ?>>
                            <span class="nav-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                            <span class="nav-text">Assign Assessments</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-section-label">Clinical Scales</div>

            <!-- PANSS -->
            <a class="nav-link <?= $is_panss_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#panssCollapse" role="button" aria-expanded="<?= $is_panss_active ? 'true' : 'false'; ?>" aria-controls="panssCollapse">
                <span class="nav-icon"><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i></span>
                <span class="nav-text">PANSS Assessment</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_panss_active ? 'show' : ''; ?>" id="panssCollapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'panss-assessments.php' ? 'active' : ''; ?>" href="panss-assessments.php">
                            <span class="nav-icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Form</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['panss-assessment-records.php', 'panss-assessment-score.php'], true) ? 'active' : ''; ?>" href="panss-assessment-records.php">
                            <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Records</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- YMRS -->
            <a class="nav-link <?= $is_ymrs_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#ymrsCollapse" role="button" aria-expanded="<?= $is_ymrs_active ? 'true' : 'false'; ?>" aria-controls="ymrsCollapse">
                <span class="nav-icon"><i class="bi bi-lightning-charge" aria-hidden="true"></i></span>
                <span class="nav-text">YMRS Assessment</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_ymrs_active ? 'show' : ''; ?>" id="ymrsCollapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'ymrs-assessments.php' ? 'active' : ''; ?>" href="ymrs-assessments.php">
                            <span class="nav-icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Form</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['ymrs-assessment-records.php', 'ymrs-assessment-score.php'], true) ? 'active' : ''; ?>" href="ymrs-assessment-records.php">
                            <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Records</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- BPRS -->
            <a class="nav-link <?= $is_bprs_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#bprsCollapse" role="button" aria-expanded="<?= $is_bprs_active ? 'true' : 'false'; ?>" aria-controls="bprsCollapse">
                <span class="nav-icon"><i class="bi bi-clipboard2-data" aria-hidden="true"></i></span>
                <span class="nav-text">BPRS Assessment</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_bprs_active ? 'show' : ''; ?>" id="bprsCollapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'bprs-assessments.php' ? 'active' : ''; ?>" href="bprs-assessments.php">
                            <span class="nav-icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Form</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['bprs-assessment-records.php', 'bprs-assessment-score.php'], true) ? 'active' : ''; ?>" href="bprs-assessment-records.php">
                            <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Records</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- BDI -->
            <a class="nav-link <?= $is_bdi_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#bdiCollapse" role="button" aria-expanded="<?= $is_bdi_active ? 'true' : 'false'; ?>" aria-controls="bdiCollapse">
                <span class="nav-icon"><i class="bi bi-heart-pulse" aria-hidden="true"></i></span>
                <span class="nav-text">BDI Assessment</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_bdi_active ? 'show' : ''; ?>" id="bdiCollapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'bdi-assessments.php' ? 'active' : ''; ?>" href="bdi-assessments.php">
                            <span class="nav-icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Form</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['bdi-assessment-records.php', 'bdi-assessment-score.php'], true) ? 'active' : ''; ?>" href="bdi-assessment-records.php">
                            <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Records</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- BAI -->
            <a class="nav-link <?= $is_bai_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#baiCollapse" role="button" aria-expanded="<?= $is_bai_active ? 'true' : 'false'; ?>" aria-controls="baiCollapse">
                <span class="nav-icon"><i class="bi bi-activity" aria-hidden="true"></i></span>
                <span class="nav-text">BAI Assessment</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_bai_active ? 'show' : ''; ?>" id="baiCollapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'bai-assessments.php' ? 'active' : ''; ?>" href="bai-assessments.php">
                            <span class="nav-icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Form</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['bai-assessment-records.php', 'bai-assessment-score.php'], true) ? 'active' : ''; ?>" href="bai-assessment-records.php">
                            <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Records</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- ACE-III -->
            <a class="nav-link <?= $is_ace_iii_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#aceIII-collapse" role="button" aria-expanded="<?= $is_ace_iii_active ? 'true' : 'false'; ?>" aria-controls="aceIII-collapse">
                <span class="nav-icon"><i class="bi bi-cpu" aria-hidden="true"></i></span>
                <span class="nav-text">ACE-III Assessment</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_ace_iii_active ? 'show' : ''; ?>" id="aceIII-collapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'ace-iii-assessments.php' ? 'active' : ''; ?>" href="ace-iii-assessments.php">
                            <span class="nav-icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Form</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['ace-iii-assessment-records.php', 'ace-iii-assessment-score.php'], true) ? 'active' : ''; ?>" href="ace-iii-assessment-records.php">
                            <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span class="nav-text">Assessment Records</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-section-label">Management</div>

            <!-- Question Bank -->
            <a class="nav-link <?= $is_question_bank_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#questionBankCollapse" role="button" aria-expanded="<?= $is_question_bank_active ? 'true' : 'false'; ?>" aria-controls="questionBankCollapse">
                <span class="nav-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span>
                <span class="nav-text">Question Bank</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_question_bank_active ? 'show' : ''; ?>" id="questionBankCollapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'add-assessment-questions.php' ? 'active' : ''; ?>" href="add-assessment-questions.php">
                            <span class="nav-icon"><i class="bi bi-plus-square" aria-hidden="true"></i></span>
                            <span class="nav-text">Add Questions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'view-assessment-questions.php' ? 'active' : ''; ?>" href="view-assessment-questions.php">
                            <span class="nav-icon"><i class="bi bi-list-check" aria-hidden="true"></i></span>
                            <span class="nav-text">Manage Questions</span>
                        </a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($user_role === 2): ?>
            <div class="sidebar-section-label">My Assessments</div>
            <a class="nav-link <?= $is_patient_assessment_active ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#patientAssessmentCollapse" role="button" aria-expanded="<?= $is_patient_assessment_active ? 'true' : 'false'; ?>" aria-controls="patientAssessmentCollapse">
                <span class="nav-icon"><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i></span>
                <span class="nav-text">Assessments</span>
                <span class="ms-auto collapse-indicator"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
            </a>
            <div class="collapse <?= $is_patient_assessment_active ? 'show' : ''; ?>" id="patientAssessmentCollapse" data-bs-parent="#sidebarAccordion">
                <ul class="nav flex-column sidebar-submenu">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'public-assessment-form.php' ? 'active' : ''; ?>" href="public-assessment-form.php">
                            <span class="nav-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
                            <span class="nav-text">Patient Assessments</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page === 'assessment-form.php' || $current_page === 'assessment-report.php') && $report_aid === 1 ? 'active' : ''; ?>" href="public-assessment-form.php">
                            <span class="nav-icon"><i class="bi bi-heart-pulse" aria-hidden="true"></i></span>
                            <span class="nav-text">BDI Assessment</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page === 'assessment-form.php' || $current_page === 'assessment-report.php') && $report_aid === 2 ? 'active' : ''; ?>" href="public-assessment-form.php">
                            <span class="nav-icon"><i class="bi bi-activity" aria-hidden="true"></i></span>
                            <span class="nav-text">BAI Assessment</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'ace-iii-assessments.php' ? 'active' : ''; ?>" href="ace-iii-assessments.php">
                            <span class="nav-icon"><i class="bi bi-clipboard2-check" aria-hidden="true"></i></span>
                            <span class="nav-text">ACE-III Assessment</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['ace-iii-assessment-records.php', 'ace-iii-assessment-score.php'], true) ? 'active' : ''; ?>" href="ace-iii-assessment-records.php">
                            <span class="nav-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span class="nav-text">ACE-III Records</span>
                        </a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </nav>
</aside>