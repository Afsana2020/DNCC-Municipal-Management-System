<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

// Handle success messages
if(isset($_GET['success'])) {
    if($_GET['success'] == 'task_created') {
        $success_msg = "Maintenance task created successfully!";
    } elseif($_GET['success'] == 'task_updated') {
        $success_msg = "Maintenance task updated successfully!";
    } elseif($_GET['success'] == 'task_deleted') {
        $success_msg = "Maintenance task deleted successfully!";
    } elseif($_GET['success'] == 'asset_updated') {
        $success_msg = "Asset condition updated successfully!";
    }
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_project = isset($_GET['project_id']) ? $_GET['project_id'] : '';
$filter_package = isset($_GET['package_id']) ? $_GET['package_id'] : '';

// Get all projects for filter dropdown
$projects_query = "SELECT Project_ID, Project_Name, Project_Type, Status 
                   FROM projects 
                   ORDER BY Created_At DESC";
$projects_result = $conn->query($projects_query);

// Get packages based on selected project
$packages_query = "SELECT p.Package_ID, p.Package_Name, p.Project_ID, pr.Project_Name 
                   FROM packages p
                   JOIN projects pr ON p.Project_ID = pr.Project_ID";
if(!empty($filter_project)) {
    $packages_query .= " WHERE p.Project_ID = " . intval($filter_project);
}
$packages_query .= " ORDER BY p.Start_Date DESC";
$packages_result = $conn->query($packages_query);

// Handle maintenance deletion
if(isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    // First update any workers assigned to this task
    $update_workers = "UPDATE workers SET Maintenance_ID = NULL WHERE Maintenance_ID = ?";
    $stmt = $conn->prepare($update_workers);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete from Maintenance table 
    $delete_query = "DELETE FROM maintenance WHERE Maintenance_ID = ?";
    $stmt = $conn->prepare($delete_query);
    if($stmt) {
        $stmt->bind_param("i", $delete_id);
        
        if($stmt->execute()) {
            header("Location: manage_maintenance.php?success=task_deleted");
            exit();
        } else {
            $error_msg = "Error deleting maintenance task: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get counts for stats
$stats_query = "SELECT 
                (SELECT COUNT(*) FROM maintenance) as total_tasks,
                (SELECT COUNT(*) FROM maintenance WHERE Status = 'Not Started') as not_started_count,
                (SELECT COUNT(*) FROM maintenance WHERE Status = 'Active') as active_count,
                (SELECT COUNT(*) FROM maintenance WHERE Status = 'Paused') as paused_count,
                (SELECT COUNT(*) FROM maintenance WHERE Status = 'Completed') as completed_count,
                (SELECT COUNT(*) FROM maintenance WHERE Status = 'Cancelled') as cancelled_count,
                (SELECT SUM(Cost) FROM maintenance) as total_cost";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Build maintenance query with filters
$maintenance_query = "SELECT m.*, a.Asset_ID, a.Asset_Name, a.Asset_Type, a.Location, a.Asset_Condition,
                             pr.Project_ID, pr.Project_Name, pr.Project_Type,
                             p.Package_ID, p.Package_Name,
                             (SELECT COUNT(*) FROM workers WHERE Maintenance_ID = m.Maintenance_ID) as worker_count,
                             (SELECT COUNT(*) FROM resources WHERE Maintenance_ID = m.Maintenance_ID) as resource_count,
                             DATEDIFF(CURDATE(), m.Start_Date) as Days_Active
                      FROM maintenance m 
                      JOIN assets a ON m.Asset_ID = a.Asset_ID 
                      LEFT JOIN projects pr ON m.Project_ID = pr.Project_ID
                      LEFT JOIN packages p ON m.Package_ID = p.Package_ID
                      WHERE 1=1";

if(!empty($filter_status)) {
    $maintenance_query .= " AND m.Status = '" . $conn->real_escape_string($filter_status) . "'";
}

if(!empty($filter_project)) {
    $maintenance_query .= " AND m.Project_ID = " . intval($filter_project);
}

if(!empty($filter_package)) {
    $maintenance_query .= " AND m.Package_ID = " . intval($filter_package);
}

$maintenance_query .= " ORDER BY 
                        CASE m.Status
                            WHEN 'Not Started' THEN 1
                            WHEN 'Active' THEN 2
                            WHEN 'Paused' THEN 3
                            WHEN 'Completed' THEN 4
                            WHEN 'Cancelled' THEN 5
                            ELSE 6
                        END,
                        m.Start_Date DESC";

$maintenance_result = $conn->query($maintenance_query);
$task_count = $maintenance_result->num_rows;

// Calculate the longest task type text for uniform badge width
$task_types = ['Construction', 'Repair', 'Routine Maintenance', 'Emergency'];
$longest_task_type = '';
$max_length = 0;
foreach($task_types as $type) {
    $len = strlen($type);
    if($len > $max_length) {
        $max_length = $len;
        $longest_task_type = $type;
    }
}
// Set badge width based on longest text (approximately 7px per character + padding)
$badge_width = ($max_length * 7) + 32; // 7px per char + 32px padding
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Maintenance - Smart DNCC</title>
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
            --badge-width: <?php echo $badge_width; ?>px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0 0 4px 0;
        }

        .header-title p {
            color: #64748b;
            font-size: 0.85rem;
            margin: 0;
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
            padding: 20px;
            border: 1px solid #e2e8f0;
        }

        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }

        .filter-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.75rem;
            margin-bottom: 4px;
        }

        .filter-control {
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
            width: 100%;
        }
        .filter-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 90px;
        }

        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
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
            letter-spacing: 0.3px;
        }

        /* STATUS BADGES - ALL SAME WIDTH */
        .status-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            white-space: nowrap;
            display: inline-block;
            min-width: 100px;
            text-align: center;
        }

        .badge-notstarted { background: #e2e8f0; color: #2d3748; border: 1px solid #cbd5e0; }
        .badge-active { background: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
        .badge-paused { background: #feebc8; color: #744210; border: 1px solid #fbd38d; }
        .badge-completed { background: #e9d8fd; color: #6b46c1; border: 1px solid #d6bcfa; }
        .badge-cancelled { background: #fed7d7; color: #c53030; border: 1px solid #fc8181; }
        
        /* TASK TYPE BADGES - UNIFORM WIDTH based on longest text */
        .task-type-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-block;
            width: var(--badge-width);
            text-align: center;
            white-space: nowrap;
        }
        .type-construction { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .type-repair { background: #fffaf0; color: #744210; border: 1px solid #fbd38d; }
        .type-routine { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .type-emergency { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        
        /* PROJECT BADGE */
        .badge-project { 
            background: #ebf8ff; 
            color: #2c5282; 
            border: 1px solid #90cdf4; 
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            min-width: 100px;
            text-align: center;
            display: inline-block;
        }

        /* ASSET ID BADGE */
        .asset-id-badge {
            background: #e2e8f0;
            color: #2d3748;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 100px;
            text-align: center;
            display: inline-block;
        }

        .action-btn {
            padding: 4px 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 0.7rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: white;
            min-width: 70px;
            justify-content: center;
        }

        .btn-view { color: #3182ce; border-color: #bee3f8; }
        .btn-view:hover { background: #ebf8ff; }

        /* TABLE FIXED LAYOUT */
        .table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .table th {
            background: #f8fafc;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 8px;
            text-align: center;
            white-space: nowrap;
        }

        .table td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.85rem;
            text-align: center;
            white-space: nowrap;
        }

        .table tr:hover {
            background: #fafbfc;
        }

        /* FIXED COLUMN WIDTHS */
        .table th:nth-child(1), .table td:nth-child(1) { width: 8%; }  /* Task ID */
        .table th:nth-child(2), .table td:nth-child(2) { width: 18%; } /* Task Type */
        .table th:nth-child(3), .table td:nth-child(3) { width: 12%; } /* Asset */
        .table th:nth-child(4), .table td:nth-child(4) { width: 12%; } /* Project */
        .table th:nth-child(5), .table td:nth-child(5) { width: 10%; } /* Duration */
        .table th:nth-child(6), .table td:nth-child(6) { width: 12%; } /* Status */
        .table th:nth-child(7), .table td:nth-child(7) { width: 10%; } /* Cost */
        .table th:nth-child(8), .table td:nth-child(8) { width: 8%; }  /* Actions */

        .alert {
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            padding: 12px 20px;
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

        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 8px 16px;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: #2c5282;
            border-color: #2c5282;
        }

        .btn-outline-secondary {
            border: 1px solid #cbd5e0;
            color: #4a5568;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            border-color: #718096;
            color: white;
        }

        .clear-filter {
            color: var(--danger);
            text-decoration: none;
            font-size: 0.75rem;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            color: #cbd5e0;
        }

        h2, h4 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.4rem; }
        h4 { font-size: 1rem; }

        .text-muted {
            color: #718096 !important;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .stat-card {
                margin-bottom: 12px;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
            
            .table {
                table-layout: auto;
            }
            
            .task-type-badge {
                font-size: 0.65rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Maintenance Management</h2>
                <p>Manage maintenance tasks across all projects</p>
            </div>
           
        </div>

        <div class="content-area">
            <?php if(isset($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['total_tasks'] ?? 0; ?></div>
                            <div class="stat-label">Total Tasks</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(160,174,192,0.1); color: #718096;">
                            <i class="fas fa-hourglass-start"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['not_started_count'] ?? 0; ?></div>
                            <div class="stat-label">Not Started</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['active_count'] ?? 0; ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(214,158,46,0.1); color: #d69e2e;">
                            <i class="fas fa-pause-circle"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['paused_count'] ?? 0; ?></div>
                            <div class="stat-label">Paused</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['completed_count'] ?? 0; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(229,62,62,0.1); color: #e53e3e;">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['cancelled_count'] ?? 0; ?></div>
                            <div class="stat-label">Cancelled</div>
                        </div>
                    </div>
                </div>
            </div>

       

            <!-- Filter Section - NO extra button -->
            <div class="filter-section">
                <form method="GET" action="manage_maintenance.php" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="filter-label">Status</div>
                            <select name="status" class="filter-control" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <option value="Not Started" <?php echo ($filter_status == 'Not Started') ? 'selected' : ''; ?>>Not Started</option>
                                <option value="Active" <?php echo ($filter_status == 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Paused" <?php echo ($filter_status == 'Paused') ? 'selected' : ''; ?>>Paused</option>
                                <option value="Completed" <?php echo ($filter_status == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?php echo ($filter_status == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <div class="filter-label">Project</div>
                            <select name="project_id" class="filter-control" onchange="this.form.submit()">
                                <option value="">All Projects</option>
                                <?php 
                                if($projects_result && $projects_result->num_rows > 0) {
                                    $projects_result->data_seek(0);
                                    while($project = $projects_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $project['Project_ID']; ?>" 
                                        <?php echo ($filter_project == $project['Project_ID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['Project_Name']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <div class="filter-label">Package</div>
                            <select name="package_id" class="filter-control" onchange="this.form.submit()">
                                <option value="">All Packages</option>
                                <?php 
                                if($packages_result && $packages_result->num_rows > 0) {
                                    $packages_result->data_seek(0);
                                    while($package = $packages_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $package['Package_ID']; ?>" 
                                        <?php echo ($filter_package == $package['Package_ID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($package['Package_Name']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>
                    </div>
                </form>

                <!-- Active Filters Display -->
                <?php if(!empty($filter_status) || !empty($filter_project) || !empty($filter_package)): ?>
                    <div class="mt-3 pt-2 border-top">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge bg-light text-dark">Active Filters:</span>
                            <?php if(!empty($filter_status)): ?>
                                <span class="status-badge 
                                    <?php 
                                    if($filter_status == 'Not Started') echo 'badge-notstarted';
                                    elseif($filter_status == 'Active') echo 'badge-active';
                                    elseif($filter_status == 'Paused') echo 'badge-paused';
                                    elseif($filter_status == 'Completed') echo 'badge-completed';
                                    elseif($filter_status == 'Cancelled') echo 'badge-cancelled';
                                    ?>">
                                    <?php echo $filter_status; ?>
                                </span>
                            <?php endif; ?>

                            <?php if(!empty($filter_project)): 
                                $project_name = "";
                                if($projects_result && $projects_result->num_rows > 0) {
                                    $projects_result->data_seek(0);
                                    while($p = $projects_result->fetch_assoc()) {
                                        if($p['Project_ID'] == $filter_project) {
                                            $project_name = $p['Project_Name'];
                                            break;
                                        }
                                    }
                                }
                            ?>
                                <span class="badge-project">Project: <?php echo htmlspecialchars($project_name); ?></span>
                            <?php endif; ?>

                            <?php if(!empty($filter_package)): 
                                $package_name = "";
                                if($packages_result && $packages_result->num_rows > 0) {
                                    $packages_result->data_seek(0);
                                    while($pkg = $packages_result->fetch_assoc()) {
                                        if($pkg['Package_ID'] == $filter_package) {
                                            $package_name = $pkg['Package_Name'];
                                            break;
                                        }
                                    }
                                }
                            ?>
                                <span class="badge bg-info text-dark">Package: <?php echo htmlspecialchars($package_name); ?></span>
                            <?php endif; ?>

                            <a href="manage_maintenance.php" class="clear-filter ms-2">
                                <i class="fas fa-times-circle me-1"></i>Clear All
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Maintenance Tasks Table -->
            <div class="content-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>Maintenance Tasks</h4>
                    <span class="badge bg-secondary"><?php echo $task_count; ?> task(s) found</span>
                </div>

                <?php if($maintenance_result && $task_count > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Task ID</th>
                                    <th>Task Type</th>
                                    <th>Asset</th>
                                    <th>Project</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th>Cost</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($task = $maintenance_result->fetch_assoc()): ?>
                                    <?php
                                    $task_type_class = '';
                                    switch($task['Task_type']) {
                                        case 'Construction': $task_type_class = 'type-construction'; break;
                                        case 'Repair': $task_type_class = 'type-repair'; break;
                                        case 'Routine Maintenance': $task_type_class = 'type-routine'; break;
                                        case 'Emergency': $task_type_class = 'type-emergency'; break;
                                        default: $task_type_class = 'type-routine';
                                    }
                                    $status_class = '';
                                    switch($task['Status']) {
                                        case 'Not Started': $status_class = 'badge-notstarted'; break;
                                        case 'Active': $status_class = 'badge-active'; break;
                                        case 'Paused': $status_class = 'badge-paused'; break;
                                        case 'Completed': $status_class = 'badge-completed'; break;
                                        case 'Cancelled': $status_class = 'badge-cancelled'; break;
                                    }
                                    ?>
                                    <tr>
                                        <td><strong>#<?php echo $task['Maintenance_ID']; ?></strong></td>
                                        <td>
                                            <span class="task-type-badge <?php echo $task_type_class; ?>">
                                                <?php echo htmlspecialchars($task['Task_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view_asset.php?id=<?php echo $task['Asset_ID']; ?>" class="text-decoration-none">
                                                <span class="asset-id-badge">#<?php echo $task['Asset_ID']; ?></span>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if(!empty($task['Project_ID'])): ?>
                                                <a href="view_project.php?id=<?php echo $task['Project_ID']; ?>" class="text-decoration-none">
                                                    <span class="badge-project">#P<?php echo $task['Project_ID']; ?></span>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $days_active = $task['Days_Active'] ?? 0;
                                            if($days_active < 0) {
                                                echo 'Not started';
                                            } elseif($days_active == 0) {
                                                echo 'Today';
                                            } elseif($days_active == 1) {
                                                echo '1 day';
                                            } else {
                                                echo $days_active . ' days';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $task['Status']; ?>
                                            </span>
                                        </td>
                                        <td>৳<?php echo number_format($task['Cost'], 2); ?></td>
                                        <td>
                                            <a href="view_maintenance.php?id=<?php echo $task['Maintenance_ID']; ?>" class="action-btn btn-view">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tools"></i>
                        <h5>No Maintenance Tasks Found</h5>
                        <p class="text-muted">No tasks match your current filter criteria.</p>
                        <?php if(!empty($filter_status) || !empty($filter_project) || !empty($filter_package)): ?>
                            <a href="manage_maintenance.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-times me-2"></i>Clear Filters
                            </a>
                        <?php else: ?>
                            <a href="add_maintenance.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus-circle me-2"></i>Create First Task
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelete(id) {
            if(confirm('Are you sure you want to delete this maintenance task? This action cannot be undone.')) {
                window.location.href = 'manage_maintenance.php?delete_id=' + id;
            }
        }
    </script>
</body>
</html>