<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
    .admin-sidebar {
        background: var(--primary, #1a365d);
        min-height: 100vh;
        position: fixed;
        width: 220px;
        z-index: 1000;
    }

    .sidebar-brand {
        padding: 15px 10px;
    }

    .sidebar-brand h3 {
        font-size: 1rem;
        color: white;
        margin-bottom: 4px;
    }

    .sidebar-brand small {
        font-size: 0.7rem;
        color: rgba(255,255,255,0.6);
    }

    .admin-nav-link {
        color: #cbd5e0;
        padding: 10px 15px;
        margin: 1px 8px;
        border-radius: 4px;
        transition: all 0.2s;
        font-weight: 500;
        display: flex;
        align-items: center;
        text-decoration: none;
        font-size: 0.8rem;
    }

    .admin-nav-link:hover, .admin-nav-link.active {
        background: var(--accent, #3182ce);
        color: white;
    }

    .admin-nav-link i {
        width: 16px;
        margin-right: 8px;
        font-size: 12px;
    }

    .admin-nav-link:last-child {
        margin-top: 20px;
        background: #e53e3e !important;
    }

    .admin-nav-link:last-child:hover {
        background: #c53030 !important;
    }
</style>

<div class="admin-sidebar">
    <div class="sidebar-brand text-center">
        <h3 class="text-white mb-0">Smart DNCC</h3>
        <small class="text-white">Admin Panel</small>
    </div>

    <div class="sidebar-nav pt-2">
  
        <a href="admin_dashboard.php" 
           class="admin-nav-link <?php echo ($current_page == 'admin_dashboard.php') ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i>Dashboard
        </a>

        <a href="manage_projects.php" 
           class="admin-nav-link <?php echo ($current_page == 'manage_projects.php') ? 'active' : ''; ?>">
            <i class="fas fa-folder-open"></i>Projects
        </a>

        <a href="manage_assets.php" 
           class="admin-nav-link <?php echo ($current_page == 'manage_assets.php') ? 'active' : ''; ?>">
            <i class="fas fa-building"></i>Assets
        </a>

        <a href="manage_maintenance.php" 
           class="admin-nav-link <?php echo ($current_page == 'manage_maintenance.php') ? 'active' : ''; ?>">
            <i class="fas fa-building"></i>Maintenance
        </a>
      
        <a href="manage_workers.php" 
           class="admin-nav-link <?php echo ($current_page == 'manage_workers.php') ? 'active' : ''; ?>">
            <i class="fas fa-users-cog"></i>Employees
        </a>
        
        <a href="manage_reports.php" 
           class="admin-nav-link <?php echo ($current_page == 'manage_reports.php') ? 'active' : ''; ?>">
            <i class="fas fa-file-alt"></i>Citizen Reports
        </a>
        
        <a href="manage_users.php" 
           class="admin-nav-link <?php echo ($current_page == 'manage_users.php') ? 'active' : ''; ?>">
            <i class="fas fa-users-cog"></i>Users
        </a>

        <!-- Logout -->
        <a href="logout.php" 
           class="admin-nav-link">
            <i class="fas fa-sign-out-alt"></i>Logout
        </a>
    </div>
</div>