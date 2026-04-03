<?php
session_start();
include("connect.php");

// Check if user is logged in and is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

// Handle budget update
if(isset($_POST['update_budget'])) {
    $project_id = $_POST['project_id'];
    $new_budget = $_POST['project_budget'];
    
    // Validate budget - must be 0 or positive number
    if($new_budget < 0) {
        $error_msg = "Budget cannot be negative.";
    } else {
        $update_query = "UPDATE Projects SET Budget = ? WHERE Project_ID = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("di", $new_budget, $project_id);
        
        if($stmt->execute()) {
            $success_msg = "Budget updated successfully!";
        } else {
            $error_msg = "Error updating budget: " . $conn->error;
        }
        $stmt->close();
    }
}

// Handle project deletion
if(isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    $check_query = "SELECT 
                        (SELECT COUNT(*) FROM Packages WHERE Project_ID = ?) as package_count,
                        (SELECT COUNT(*) FROM Maintenance WHERE Project_ID = ?) as task_count";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ii", $delete_id, $delete_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $deps = $result->fetch_assoc();
    
    if($deps['package_count'] > 0 || $deps['task_count'] > 0) {
        $error_msg = "Cannot delete project with existing packages or maintenance tasks.";
    } else {
        $delete_query = "DELETE FROM Projects WHERE Project_ID = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("i", $delete_id);
        if($stmt->execute()) {
            $success_msg = "Project deleted successfully!";
        } else {
            $error_msg = "Error deleting project: " . $conn->error;
        }
    }
}

// Get filter type from URL
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Projects query - includes project budget from Projects table
$projects_query = "SELECT 
                    p.*,
                    p.Budget as project_budget,
                    COUNT(DISTINCT pz.Zone_ID) as zone_count,
                    COUNT(DISTINCT pk.Package_ID) as package_count,
                    COUNT(DISTINCT m.Maintenance_ID) as task_count,
                    COALESCE(SUM(b.Amount_Allocation), 0) as total_asset_budgets,
                    COALESCE(SUM(b.Amount_Spent), 0) as total_spent
                    FROM Projects p
                    LEFT JOIN Project_Zones pz ON p.Project_ID = pz.Project_ID
                    LEFT JOIN Packages pk ON p.Project_ID = pk.Project_ID
                    LEFT JOIN Maintenance m ON p.Project_ID = m.Project_ID
                    LEFT JOIN Budgets b ON p.Project_ID = b.Project_ID";

$where_conditions = [];
$params = [];
$types = '';

if($filter_type != 'all') {
    $where_conditions[] = "p.Project_Type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

if($filter_status != 'all') {
    $where_conditions[] = "p.Status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if(!empty($search)) {
    $search_term = "%$search%";
    $where_conditions[] = "(p.Project_Name LIKE ? OR p.Project_Director LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

if(!empty($where_conditions)) {
    $projects_query .= " WHERE " . implode(" AND ", $where_conditions);
}

$projects_query .= " GROUP BY p.Project_ID ORDER BY p.Created_At DESC";

$stmt = $conn->prepare($projects_query);
if(!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$projects_result = $stmt->get_result();

// Get counts for stats
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN Project_Type = 'Large' THEN 1 ELSE 0 END) as large_count,
                SUM(CASE WHEN Project_Type = 'Routine' THEN 1 ELSE 0 END) as routine_count,
                SUM(CASE WHEN Project_Type = 'Urgent' THEN 1 ELSE 0 END) as urgent_count
                FROM Projects";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Projects - Smart DNCC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #38a169;
            --warning: #d69e2e;
            --danger: #e53e3e;
            --info: #00a3c4;
            --purple: #805ad5;
            --not-started: #94a3b8;
            --cancelled: #6b7280;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 0.875rem;
            overflow: hidden;
            height: 100vh;
            width: 100vw;
        }

        .admin-main {
            margin-left: 220px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            background: #f8fafc;
        }

        .admin-header {
            background: white;
            padding: 20px 28px;
            border-bottom: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .header-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
        }

        .header-title p {
            color: #64748b;
            font-size: 0.85rem;
            margin: 5px 0 0 0;
        }

        .content-area {
            padding: 24px 28px;
            overflow-y: auto;
            height: calc(100vh - 89px);
        }

        .content-area::-webkit-scrollbar {
            width: 6px;
        }
        .content-area::-webkit-scrollbar-track {
            background: #edf2f7;
        }
        .content-area::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 6px;
        }

        .content-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e2e8f0;
        }

        /* Stats cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 80px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1a202c;
            line-height: 1.2;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        /* Status badges - UNIFORM WIDTH */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 80px;
            text-align: center;
            display: inline-block;
        }

        .badge-large { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .badge-routine { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-urgent { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        
        /* Updated status classes */
        .status-not-started { 
            background: #e2e8f0; 
            color: #475569; 
            border: 1px solid #cbd5e0; 
        }
        .status-active { 
            background: #d1fae5; 
            color: #065f46; 
            border: 1px solid #a7f3d0; 
        }
        .status-paused { 
            background: #fed7aa; 
            color: #92400e; 
            border: 1px solid #fdba74; 
        }
        .status-completed { 
            background: #e9d8fd; 
            color: #553c9a; 
            border: 1px solid #d6bcfa; 
        }
        .status-cancelled { 
            background: #fee2e2; 
            color: #b91c1c; 
            border: 1px solid #fecaca; 
        }

        /* Budget display */
        .budget-cell {
            position: relative;
            min-height: 32px;
            display: flex;
            justify-content: center;
        }

        .budget-display-mode {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .budget-edit-mode {
            display: none;
            align-items: center;
            gap: 4px;
        }

        .budget-box {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 6px;
            padding: 4px 8px;
            width: 110px;
            text-align: center;
            font-weight: 600;
            font-size: 0.75rem;
            color: #276749;
            display: inline-block;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .budget-box.no-budget {
            background: #fff5f5;
            border-color: #fc8181;
            color: #e53e3e;
            font-size: 0.7rem;
        }

        .budget-edit-input {
            width: 110px;
            padding: 4px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.75rem;
            height: 30px;
        }
        .budget-edit-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .budget-edit-save {
            background: var(--success);
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            height: 30px;
            width: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .budget-edit-save:hover {
            background: #2f855a;
        }

        .budget-edit-cancel {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            height: 30px;
            width: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .budget-edit-cancel:hover {
            background: #718096;
            color: white;
        }

        /* Filter bar - CLEAN DESIGN */
        .filter-bar {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 20px;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #edf2f7;
        }

        .filter-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .filter-select {
            border: none;
            background: transparent;
            font-size: 0.8rem;
            padding: 4px 20px 4px 4px;
            color: #2d3748;
            font-weight: 500;
            cursor: pointer;
            outline: none;
        }

        .filter-select:hover {
            color: var(--accent);
        }

        .search-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .search-box {
            padding: 6px 12px;
            font-size: 0.8rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            width: 200px;
            background: white;
        }
        .search-box:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .search-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
        }
        .search-btn:hover {
            background: #2c5282;
        }

        .clear-filter {
            color: var(--danger);
            text-decoration: none;
            font-size: 0.75rem;
            padding: 4px 8px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border-radius: 4px;
        }
        .clear-filter:hover {
            background: #fff5f5;
        }

        /* Legend items styling */
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.65rem;
            color: #4a5568;
            background: #f8fafc;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #edf2f7;
        }

        /* Action buttons */
        .action-btn {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid transparent;
            transition: all 0.2s;
        }

        .btn-view { 
            background: #ebf8ff; 
            color: #3182ce; 
            border: 1px solid #bee3f8; 
        }
        .btn-view:hover { 
            background: #3182ce; 
            color: white; 
        }

        .btn-budget { 
            background: #f0fff4; 
            color: #38a169; 
            border: 1px solid #9ae6b4; 
            padding: 4px 8px;
            cursor: pointer;
        }
        .btn-budget:hover { 
            background: #38a169; 
            color: white; 
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover {
            background: #2c5282;
            color: white;
        }

        /* Table - CLEAN LAYOUT */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: #f8fafc;
            color: #4a5568;
            font-weight: 600;
            font-size: 0.7rem;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 6px;
            text-align: left;
            text-transform: uppercase;
        }

        .table td {
            padding: 10px 6px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.8rem;
        }

        .table tr:hover {
            background: #fafbfc;
        }

        /* Center align specific columns */
        .table th:nth-child(2), .table td:nth-child(2),
        .table th:nth-child(3), .table td:nth-child(3),
        .table th:nth-child(4), .table td:nth-child(4),
        .table th:nth-child(5), .table td:nth-child(5),
        .table th:nth-child(6), .table td:nth-child(6) {
            text-align: center;
        }

        /* Actions column */
        .actions-cell {
            display: flex;
            gap: 4px;
            justify-content: center;
        }

        /* Z/P/T badges - IN A ROW */
        .stats-badge-row {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .stat-badge-sm {
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .badge-zone {
            background: #ebf8ff;
            color: #2c5282;
            border: 1px solid #90cdf4;
        }

        .badge-package-sm {
            background: #faf5ff;
            color: #805ad5;
            border: 1px solid #d6bcfa;
        }

        .badge-task-sm {
            background: #f0fff4;
            color: #276749;
            border: 1px solid #9ae6b4;
        }

        .project-row {
            border-left: 3px solid transparent;
        }
        .project-row.large { border-left-color: #3182ce; }
        .project-row.routine { border-left-color: #38a169; }
        .project-row.urgent { border-left-color: #e53e3e; }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.65rem;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #f0fff4;
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        .alert-danger {
            background: #fff5f5;
            color: #c53030;
            border: 1px solid #fc8181;
        }

        /* Director styling */
        .director-text {
            font-size: 0.7rem;
            color: #64748b;
            margin-top: 2px;
        }
        .director-name {
            font-weight: 500;
            color: #2d3748;
        }
        .director-not-assigned {
            color: #e53e3e;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .search-wrapper {
                margin-left: 0;
                width: 100%;
            }
            .search-box {
                width: 100%;
            }
            .budget-cell {
                flex-direction: column;
                align-items: center;
                gap: 2px;
            }
            .actions-cell {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <div>
                    <h2>Projects</h2>
                    <p class="text-muted">Manage infrastructure projects</p>
                </div>
                <a href="add_project.php" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i>New Project
                </a>
            </div>
        </div>

        <div class="content-area">
            
            <?php if(isset($success_msg)): ?>
                <div class="alert alert-success"><?php echo $success_msg; ?></div>
            <?php endif; ?>
            
            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger"><?php echo $error_msg; ?></div>
            <?php endif; ?>
            
            <!-- Stats Row -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #eff6ff; color: #3182ce;">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #eff6ff; color: #3182ce;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?php echo $stats['large_count']; ?></div>
                        <div class="stat-label">Large</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f0fdf4; color: #38a169;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?php echo $stats['routine_count']; ?></div>
                        <div class="stat-label">Routine</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fef2f2; color: #e53e3e;">
                        <i class="fas fa-exclamation"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?php echo $stats['urgent_count']; ?></div>
                        <div class="stat-label">Urgent</div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Bar - Clean and organized -->
            <div class="filter-bar">
                <!-- Type Filter -->
                <div class="filter-group">
                    <span class="filter-label">Type:</span>
                    <select class="filter-select" onchange="window.location.href='?filter=' + this.value + '&status=<?php echo $filter_status; ?>&search=<?php echo urlencode($search); ?>'">
                        <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="Large" <?php echo $filter_type == 'Large' ? 'selected' : ''; ?>>Large</option>
                        <option value="Routine" <?php echo $filter_type == 'Routine' ? 'selected' : ''; ?>>Routine</option>
                        <option value="Urgent" <?php echo $filter_type == 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
                    </select>
                </div>

                <!-- Status Filter - Updated with new statuses -->
                <div class="filter-group">
                    <span class="filter-label">Status:</span>
                    <select class="filter-select" onchange="window.location.href='?filter=<?php echo $filter_type; ?>&status=' + this.value + '&search=<?php echo urlencode($search); ?>'">
                        <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="Not Started" <?php echo $filter_status == 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                        <option value="Active" <?php echo $filter_status == 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Paused" <?php echo $filter_status == 'Paused' ? 'selected' : ''; ?>>Paused</option>
                        <option value="Completed" <?php echo $filter_status == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo $filter_status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>

                <!-- Search -->
                <div class="search-wrapper">
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="filter" value="<?php echo $filter_type; ?>">
                        <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                        <input type="text" name="search" class="search-box" placeholder="Search projects..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="search-btn">Search</button>
                    </form>
                </div>
            </div>

            <!-- Active Filters Display -->
            <?php if($filter_type != 'all' || $filter_status != 'all' || !empty($search)): ?>
                <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                    <span class="badge bg-light text-dark">Active Filters:</span>
                    <?php if($filter_type != 'all'): ?>
                        <span class="status-badge badge-<?php echo strtolower($filter_type); ?>">Type: <?php echo $filter_type; ?></span>
                    <?php endif; ?>
                    <?php if($filter_status != 'all'): ?>
                        <?php
                        $status_class = '';
                        if($filter_status == 'Not Started') $status_class = 'status-not-started';
                        elseif($filter_status == 'Active') $status_class = 'status-active';
                        elseif($filter_status == 'Paused') $status_class = 'status-paused';
                        elseif($filter_status == 'Completed') $status_class = 'status-completed';
                        elseif($filter_status == 'Cancelled') $status_class = 'status-cancelled';
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>">Status: <?php echo $filter_status; ?></span>
                    <?php endif; ?>
                    <?php if(!empty($search)): ?>
                        <span class="badge bg-info text-dark">Search: "<?php echo htmlspecialchars($search); ?>"</span>
                    <?php endif; ?>
                    <a href="manage_projects.php" class="clear-filter">
                        <i class="fas fa-times-circle"></i> Clear All
                    </a>
                </div>
            <?php endif; ?>
            
            <!-- Projects Table -->
            <div class="content-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0">Projects</h4>
                    <div class="d-flex gap-2">
                        <span class="legend-item">
                            <i class="fas fa-map-marker-alt" style="color: #3182ce;"></i>
                            <span>Zone</span>
                        </span>
                        <span class="legend-item">
                            <i class="fas fa-cubes" style="color: #805ad5;"></i>
                            <span>Package</span>
                        </span>
                        <span class="legend-item">
                            <i class="fas fa-tasks" style="color: #38a169;"></i>
                            <span>Segment</span>
                        </span>
                    </div>
                </div>

                <?php if($projects_result && $projects_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 28%;">Project</th>
                                    <th style="width: 10%;">Type</th>
                                    <th style="width: 10%;">Status</th>
                                    <th style="width: 15%;">Stats</th>
                                    <th style="width: 20%;">Budget</th>
                                    <th style="width: 17%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($project = $projects_result->fetch_assoc()): 
                                    $unique_id = 'budget_' . $project['Project_ID'];
                                    
                                    // Determine status class
                                    $status_class = '';
                                    if($project['Status'] == 'Not Started') {
                                        $status_class = 'status-not-started';
                                    } elseif($project['Status'] == 'Active') {
                                        $status_class = 'status-active';
                                    } elseif($project['Status'] == 'Paused') {
                                        $status_class = 'status-paused';
                                    } elseif($project['Status'] == 'Completed') {
                                        $status_class = 'status-completed';
                                    } elseif($project['Status'] == 'Cancelled') {
                                        $status_class = 'status-cancelled';
                                    }
                                ?>
                                <tr class="project-row <?php echo strtolower($project['Project_Type']); ?>">
                                    <td style="text-align: left;">
                                        <strong>#<?php echo $project['Project_ID']; ?></strong>
                                        <div style="font-weight: 500;"><?php echo $project['Project_Name']; ?></div>
                                        <div class="director-text">
                                            <strong>Director:</strong> 
                                            <?php 
                                            if($project['Project_Director']) {
                                                echo '<span class="director-name">' . htmlspecialchars($project['Project_Director']) . '</span>';
                                            } else {
                                                echo '<span class="director-not-assigned">Not assigned</span>';
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge 
                                            <?php  
                                            if($project['Project_Type'] == 'Large') echo 'badge-large';
                                            elseif($project['Project_Type'] == 'Routine') echo 'badge-routine';
                                            else echo 'badge-urgent';
                                            ?>">
                                            <?php echo $project['Project_Type']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $project['Status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="stats-badge-row">
                                            <span class="stat-badge-sm badge-zone">
                                                <i class="fas fa-map-marker-alt"></i> <?php echo $project['zone_count']; ?>
                                            </span>
                                            <span class="stat-badge-sm badge-package-sm">
                                                <i class="fas fa-cubes"></i> <?php echo $project['package_count']; ?>
                                            </span>
                                            <span class="stat-badge-sm badge-task-sm">
                                                <i class="fas fa-tasks"></i> <?php echo $project['task_count']; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="budget-cell">
                                            <!-- Display Mode -->
                                            <div id="display_<?php echo $unique_id; ?>" class="budget-display-mode">
                                                <?php if($project['project_budget'] > 0): ?>
                                                    <span class="budget-box" title="৳<?php echo number_format($project['project_budget']); ?>">
                                                        ৳<?php echo number_format($project['project_budget']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="budget-box no-budget">Not Allocated</span>
                                                <?php endif; ?>
                                                <button class="btn-budget action-btn" onclick="toggleBudgetEdit('<?php echo $unique_id; ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                            </div>
                                            
                                            <!-- Edit Mode - Exactly the same position -->
                                            <div id="edit_<?php echo $unique_id; ?>" class="budget-edit-mode">
                                                <form method="POST" style="display: flex; gap: 4px; align-items: center; margin: 0;">
                                                    <input type="hidden" name="project_id" value="<?php echo $project['Project_ID']; ?>">
                                                    <input type="number" class="budget-edit-input" name="project_budget" 
                                                           value="<?php echo $project['project_budget']; ?>" step="1" min="0" placeholder="Amount">
                                                    <button type="submit" name="update_budget" class="budget-edit-save" title="Save">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="budget-edit-cancel" onclick="toggleBudgetEdit('<?php echo $unique_id; ?>')" title="Cancel">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="actions-cell">
                                            <a href="view_project.php?id=<?php echo $project['Project_ID']; ?>" class="action-btn btn-view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                        <h5>No Projects Found</h5>
                        <p class="text-muted">No projects match your criteria.</p>
                        <a href="add_project.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>Create New Project
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function toggleBudgetEdit(id) {
        var displayDiv = document.getElementById('display_' + id);
        var editDiv = document.getElementById('edit_' + id);
        
        if (editDiv.style.display === 'none' || editDiv.style.display === '') {
            displayDiv.style.display = 'none';
            editDiv.style.display = 'flex';
            // Focus the input field
            setTimeout(function() {
                editDiv.querySelector('input').focus();
            }, 100);
        } else {
            displayDiv.style.display = 'flex';
            editDiv.style.display = 'none';
        }
    }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>