
<nav class="navbar admin-navbar navbar-expand bg-white">
    <div class="container-fluid px-2 px-lg-4">
        <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-controls="adminSidebar"
            aria-expanded="true" aria-label="Toggle sidebar">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <div class="navbar-actions ms-auto">
            <button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="Switch color theme"
              title="Switch color theme">
              <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
            </button>
            <div class="dropdown">
                <button class="profile-button dropdown-toggle d-flex align-items-center gap-2 px-3 py-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: auto; height: auto;">
                    
                    <div class="text-start">
                        
                        <!-- Name ke liye Div -->
                        <div class="profile-name fw-semibold lh-1 mb-1 text-nowrap" style="font-size: 0.85rem;">
                            <?= isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'Demo User' ?>
                        </div>
                        
                        <!-- Role ke liye Div -->
                        <div class="user-role text-muted lh-1 text-nowrap" style="font-size: 0.7rem;">
                            <?= (isset($_SESSION['role']) && $_SESSION['role'] == "1") ? "Admin" : "User" ?> 
                        </div>

                    </div>

                </button>
                
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2">
                    <li><a class="dropdown-item text-danger py-2" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<style>
    .profile-button {
        cursor: pointer;
        text-align: left;
        white-space: nowrap;
    }
</style>