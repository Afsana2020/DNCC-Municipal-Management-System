<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
    .citizen-sidebar {
        background: var(--primary, #1a365d);
        min-height: 100vh;
        position: fixed;
        width: 220px;
        z-index: 1000;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    .sidebar-brand {
        padding: 20px 15px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        text-align: center;
    }

    .sidebar-brand h3 {
        font-size: 1.1rem;
        font-weight: 600;
        color: white;
        margin-bottom: 5px;
        letter-spacing: 0.5px;
    }

    .sidebar-brand h3 i {
        color: white;
        font-size: 1.2rem;
    }

    .sidebar-brand h5 {
        font-size: 0.9rem;
        font-weight: 500;
        color: white;
        margin-bottom: 2px;
        opacity: 0.95;
    }

    .sidebar-brand small {
        font-size: 0.7rem;
        color: rgba(255,255,255,0.6);
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }

    .sidebar-nav {
        padding: 15px 0;
    }

    .nav {
        display: flex;
        flex-direction: column;
        list-style: none;
        padding-left: 0;
        margin-bottom: 0;
    }

    .citizen-nav-link {
        color: #cbd5e0;
        padding: 12px 18px;
        margin: 2px 10px;
        border-radius: 6px;
        transition: all 0.2s ease;
        font-weight: 500;
        display: flex;
        align-items: center;
        text-decoration: none;
        font-size: 0.85rem;
        letter-spacing: 0.3px;
    }

    .citizen-nav-link i {
        width: 20px;
        margin-right: 12px;
        font-size: 0.95rem;
        text-align: center;
        color: #cbd5e0;
    }

    .citizen-nav-link:hover {
        background: var(--accent, #3182ce);
        color: white;
        transform: translateX(2px);
    }

    .citizen-nav-link:hover i {
        color: white;
    }

    .citizen-nav-link.active {
        background: var(--accent, #3182ce);
        color: white;
        font-weight: 600;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .citizen-nav-link.active i {
        color: white;
    }

    /* Logout link special styling */
    .citizen-nav-link:last-child {
        margin-top: 20px;
        background: #e53e3e !important;
        color: white !important;
    }

    .citizen-nav-link:last-child i {
        color: white !important;
    }

    .citizen-nav-link:last-child:hover {
        background: #c53030 !important;
        color: white !important;
    }

    /* Scrollbar styling */
    .citizen-sidebar::-webkit-scrollbar {
        width: 4px;
    }
    .citizen-sidebar::-webkit-scrollbar-track {
        background: #2d3748;
    }
    .citizen-sidebar::-webkit-scrollbar-thumb {
        background: #4a5568;
        border-radius: 4px;
    }
</style>

<div class="citizen-sidebar">
    <div class="sidebar-brand">
        <h3><i class="fas fa-city"></i> Smart DNCC</h3>
        <small>Citizen Portal</small>
    </div>
    <div class="sidebar-nav">
        <nav class="nav flex-column">
            <a href="citizen_dashboard.php" class="citizen-nav-link <?php echo ($current_page == 'citizen_dashboard.php') ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>Dashboard
            </a>
            <a href="manage_assets.php" class="citizen-nav-link <?php echo ($current_page == 'manage_assets.php' || $current_page == 'view_asset.php') ? 'active' : ''; ?>">
                <i class="fas fa-building"></i>Public Assets
            </a>
            <a href="citizen_reports.php" class="citizen-nav-link <?php echo ($current_page == 'citizen_reports.php' || $current_page == 'view_report.php') ? 'active' : ''; ?>">
                <i class="fas fa-flag"></i>My Reports
            </a>
            <a href="citizen_profile.php" class="citizen-nav-link <?php echo ($current_page == 'citizen_profile.php') ? 'active' : ''; ?>">
                <i class="fas fa-user"></i>My Profile
            </a>
            <a href="logout.php" class="citizen-nav-link">
                <i class="fas fa-sign-out-alt"></i>Logout
            </a>
        </nav>
    </div>
</div>