<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start(); 
}

if (!isset($_SESSION['user_login'])) { 
    header("Location: login.php");
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?> 
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="adminHMD professional admin dashboard template">
  <title>Dashboard | adminHMD</title>

  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/vendors/bootstrap-icons/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/css/style.css">
   <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- DataTables Bootstrap 5 CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

   

  <!-- Custom CSS for Dropdown Animation and Layout -->
  <style>
    /* Menu Items Alignment */
    .sidebar-nav .nav-item {
      width: 100%;
    }

    /* Arrow icon transition */
    .collapse-indicator i {
      transition: transform 0.3s ease;
      display: inline-block;
    }

    /* Rotate arrow when dropdown is open */
    .nav-link[aria-expanded="true"] .collapse-indicator i {
      transform: rotate(180deg);
    }




    /* DataTables Search & Pagination Styling */
    .dataTables_wrapper .row {
        align-items: center;
        margin-bottom: 15px;
    }
    
    .dataTables_filter input {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 6px 12px;
        box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
    }

    .dataTables_filter input:focus {
        border-color: #3b82f6;
        outline: none;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }

    .dataTables_length select {
        border-radius: 6px;
        padding: 4px 28px 4px 10px;
        border: 1px solid #cbd5e1;
    }

    .page-item.active .page-link {
        background-color: #4f46e5;
        border-color: #4f46e5;
        border-radius: 6px;
    }

    .page-link {
        color: #475569;
        border-radius: 6px;
        margin: 0 3px;
        border: none;
    }

    /* Mobile view adjustments */
    @media (max-width: 767.98px) {
        .card-box { padding: 15px; }
        .dataTables_wrapper .row > div {
            margin-bottom: 10px;
            text-align: left !important;
        }
        .dataTables_filter {
            text-align: left !important;
            width: 100%;
        }
        .dataTables_filter input { width: 100%; margin-left: 0; margin-top: 5px; }
        
        .action-buttons {
            width: 100%;
            justify-content: space-between;
        }
        .action-buttons .btn { flex: 1; justify-content: center; }
    }
    

  </style>
</head>

<body>
  <div class="admin-shell">
    <div class="sidebar-backdrop" data-sidebar-close></div>
     <?php  require('sidebar.php') ?>
   

    <div class="admin-main">
      <?php  require('header.php') ?>

      <main class="dashboard-content">