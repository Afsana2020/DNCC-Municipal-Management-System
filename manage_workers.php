<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

// Handle worker deletion
if(isset($_GET['delete_worker'])) {
    $worker_id = intval($_GET['delete_worker']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First delete all worker assignments (foreign key constraint)
        $delete_assignments = "DELETE FROM worker_assignments WHERE Worker_ID = ?";
        $stmt = $conn->prepare($delete_assignments);
        $stmt->bind_param("i", $worker_id);
        $stmt->execute();
        $stmt->close();
        
        // Then delete the worker
        $delete_worker = "DELETE FROM workers WHERE Worker_ID = ?";
        $stmt = $conn->prepare($delete_worker);
        $stmt->bind_param("i", $worker_id);
        $stmt->execute();
        
        if($stmt->affected_rows > 0) {
            $conn->commit();
            header("Location: manage_workers.php?success=worker_deleted");
            exit();
        } else {
            throw new Exception("Worker not found");
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: manage_workers.php?error=delete_failed");
        exit();
    }
}

// Handle success messages
if(isset($_GET['success'])) {
    if($_GET['success'] == 'worker_added') {
        $success_msg = "Employee added successfully!";
    } elseif($_GET['success'] == 'worker_updated') {
        $success_msg = "Employee updated successfully!";
    } elseif($_GET['success'] == 'worker_assigned') {
        $success_msg = "Employee assigned successfully!";
    } elseif($_GET['success'] == 'worker_removed') {
        $success_msg = "Employee removed successfully!";
    } elseif($_GET['success'] == 'worker_deleted') {
        $success_msg = "Employee permanently deleted from database!";
    }
}

// Handle error messages
if(isset($_GET['error'])) {
    if($_GET['error'] == 'delete_failed') {
        $error_msg = "Failed to delete employee. Please try again.";
    }
}

// Get filter parameters
$filter_project = isset($_GET['project_id']) ? $_GET['project_id'] : '';
$filter_package = isset($_GET['package_id']) ? $_GET['package_id'] : '';
$filter_task = isset($_GET['task_id']) ? $_GET['task_id'] : '';
$filter_level = isset($_GET['level']) ? $_GET['level'] : '';

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

// Get tasks based on selected package
$tasks_query = "SELECT m.Maintenance_ID, m.Task_type, m.Description, m.Project_ID, m.Package_ID,
                       a.Asset_Name
                FROM maintenance m
                LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID";
if(!empty($filter_package)) {
    $tasks_query .= " WHERE m.Package_ID = " . intval($filter_package);
} elseif(!empty($filter_project)) {
    $tasks_query .= " WHERE m.Project_ID = " . intval($filter_project);
}
$tasks_query .= " ORDER BY m.Start_Date DESC";
$tasks_result = $conn->query($tasks_query);

// Build workers query with filters
$workers_query = "SELECT 
                    w.Worker_ID,
                    w.Worker_Name,
                    w.Worker_Salary,
                    w.Designation as permanent_designation,
                    COUNT(wa.Assignment_ID) as total_assignments,
                    SUM(CASE WHEN wa.Status = 'Active' THEN 1 ELSE 0 END) as active_assignments
                  FROM workers w
                  LEFT JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID AND wa.Status = 'Active'
                  WHERE 1=1";

// Apply filters based on worker_assignments
if(!empty($filter_project)) {
    $workers_query .= " AND w.Worker_ID IN (
        SELECT DISTINCT Worker_ID FROM worker_assignments 
        WHERE Project_ID = " . intval($filter_project) . " OR 
              Package_ID IN (SELECT Package_ID FROM packages WHERE Project_ID = " . intval($filter_project) . ") OR
              Maintenance_ID IN (SELECT Maintenance_ID FROM maintenance WHERE Project_ID = " . intval($filter_project) . ")
    )";
}

if(!empty($filter_package)) {
    $workers_query .= " AND w.Worker_ID IN (
        SELECT DISTINCT Worker_ID FROM worker_assignments 
        WHERE Package_ID = " . intval($filter_package) . " OR
              Maintenance_ID IN (SELECT Maintenance_ID FROM maintenance WHERE Package_ID = " . intval($filter_package) . ")
    )";
}

if(!empty($filter_task)) {
    $workers_query .= " AND w.Worker_ID IN (
        SELECT DISTINCT Worker_ID FROM worker_assignments 
        WHERE Maintenance_ID = " . intval($filter_task) . "
    )";
}

if(!empty($filter_level)) {
    if($filter_level == 'project') {
        $workers_query .= " AND w.Worker_ID IN (
            SELECT DISTINCT Worker_ID FROM worker_assignments 
            WHERE Assignment_Type = 'project'
        )";
    } elseif($filter_level == 'package') {
        $workers_query .= " AND w.Worker_ID IN (
            SELECT DISTINCT Worker_ID FROM worker_assignments 
            WHERE Assignment_Type = 'package'
        )";
    } elseif($filter_level == 'maintenance') {
        $workers_query .= " AND w.Worker_ID IN (
            SELECT DISTINCT Worker_ID FROM worker_assignments 
            WHERE Assignment_Type = 'maintenance'
        )";
    }
}

$workers_query .= " GROUP BY w.Worker_ID ORDER BY w.Worker_Name ASC";

$workers_result = $conn->query($workers_query);
$worker_count = $workers_result->num_rows;

// Get counts for stats
$stats_query = "SELECT 
                (SELECT COUNT(*) FROM workers) as total_workers,
                (SELECT COUNT(DISTINCT Worker_ID) FROM worker_assignments WHERE Assignment_Type = 'project') as project_workers,
                (SELECT COUNT(DISTINCT Worker_ID) FROM worker_assignments WHERE Assignment_Type = 'package') as package_workers,
                (SELECT COUNT(DISTINCT Worker_ID) FROM worker_assignments WHERE Assignment_Type = 'maintenance') as task_workers,
                (SELECT COUNT(*) FROM workers WHERE Worker_ID NOT IN (SELECT DISTINCT Worker_ID FROM worker_assignments WHERE Status = 'Active')) as unassigned_workers";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - Smart DNCC</title>
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

        /* Worker Status badges - removed status column, keeping for active assignments count */
        .worker-status-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            white-space: nowrap;
            display: inline-block;
            min-width: 100px;
            text-align: center;
            box-sizing: border-box;
        }

        .worker-status-active { 
            background: #c6f6d5; 
            color: #276749; 
            border: 1px solid #9ae6b4; 
        }
        .worker-status-unassigned { 
            background: #edf2f7; 
            color: #4a5568; 
            border: 1px solid #cbd5e0; 
        }

        /* Designation badge - centered */
        .designation-badge {
            background: #e2e8f0;
            color: #2d3748;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-block;
            min-width: 120px;
            text-align: center;
            box-sizing: border-box;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-left: auto;
            margin-right: auto;
        }

        /* Active Assignments Badge */
        .active-assignments-badge {
            background: #e53e3e;
            color: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            min-width: 80px;
            text-align: center;
            box-sizing: border-box;
        }

        .active-assignments-zero {
            background: #edf2f7;
            color: #4a5568;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            min-width: 80px;
            text-align: center;
            box-sizing: border-box;
            border: 1px solid #cbd5e0;
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
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            border-color: #718096;
            color: white;
        }

        .btn-sm {
            padding: 4px 12px;
            font-size: 0.75rem;
        }

        .action-btn {
            padding: 3px 8px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 0.75rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: white;
            white-space: nowrap;
        }

        .btn-view { color: #3182ce; border-color: #bee3f8; }
        .btn-view:hover { background: #ebf8ff; }
        .btn-assign { color: #805ad5; border-color: #d6bcfa; }
        .btn-assign:hover { background: #faf5ff; }
        .btn-remove { color: #e53e3e; border-color: #fed7d7; }
        .btn-remove:hover { background: #fff5f5; }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: #f8fafc;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 10px;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .table td {
            padding: 12px 10px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.85rem;
        }

        .table tr:hover {
            background: #fafbfc;
        }

        /* Adjusted column widths - ID takes more space (10%) */
        .table th:nth-child(1), .table td:nth-child(1) { width: 10%; }   /* ID - increased */
        .table th:nth-child(2), .table td:nth-child(2) { width: 20%; }  /* Employee Name - reduced */
        .table th:nth-child(3), .table td:nth-child(3) { width: 19%; }  /* Designation */
        .table th:nth-child(4), .table td:nth-child(4) { width: 13%; }  /* Salary */
        .table th:nth-child(5), .table td:nth-child(5) { width: 13%; }  /* Active Assignments */
        .table th:nth-child(6), .table td:nth-child(6) { width: 25%; }  /* Actions - increased */

        /* Center all appropriate columns */
        .table td:nth-child(1),
        .table td:nth-child(3),
        .table td:nth-child(4),
        .table td:nth-child(5) {
            text-align: center;
        }

        .table th:nth-child(1),
        .table th:nth-child(3),
        .table th:nth-child(4),
        .table th:nth-child(5),
        .table th:nth-child(6) {
            text-align: center;
        }

        /* Make ID bold and more prominent */
        .table td:nth-child(1) strong {
            font-size: 0.9rem;
            color: #1a202c;
        }

        /* Employee name column left aligned with reduced padding */
        .table td:nth-child(2) {
            text-align: left;
            padding-left: 5px;
            padding-right: 5px;
        }
        .table th:nth-child(2) {
            text-align: left;
            padding-left: 5px;
        }

        /* Designation column - centered with reduced padding to move closer */
        .table td:nth-child(3) {
            text-align: center;
            padding-left: 2px;
            padding-right: 2px;
        }

        .actions-container {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 500;
            min-width: 70px;
            text-align: center;
            display: inline-block;
        }

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

        .salary-text {
            font-size: 0.85rem;
            font-weight: 500;
            color: #2d3748;
        }

        @media (max-width: 992px) {
            .admin-main {
                margin-left: 0;
            }
            
            .stat-card {
                margin-bottom: 12px;
            }
            
            .table th:nth-child(1), .table td:nth-child(1) { width: 10%; }
            .table th:nth-child(2), .table td:nth-child(2) { width: 20%; }
            .table th:nth-child(3), .table td:nth-child(3) { width: 18%; }
            .table th:nth-child(4), .table td:nth-child(4) { width: 13%; }
            .table th:nth-child(5), .table td:nth-child(5) { width: 13%; }
            .table th:nth-child(6), .table td:nth-child(6) { width: auto; min-width: 160px; }
            
            .actions-container {
                justify-content: flex-start;
            }
        }

        @media (max-width: 768px) {
            .filter-section .row > div {
                margin-bottom: 12px;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Employee Management</h2>
                <p>View employees across all projects, packages, and tasks</p>
            </div>
            <div>
                <a href="add_worker.php" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Add New Employee
                </a>
            </div>
        </div>
        
        <div class="content-area">
            
            <?php if(!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards - Adjusted to 5 columns after removing status filter -->
            <div class="row g-3 mb-4">
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['total_workers']; ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-folder-open"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['project_workers']; ?></div>
                            <div class="stat-label">Project</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['package_workers']; ?></div>
                            <div class="stat-label">Package</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['task_workers']; ?></div>
                            <div class="stat-label">Task</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(229,62,62,0.1); color: #e53e3e;">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['unassigned_workers']; ?></div>
                            <div class="stat-label">Unassigned</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-filter"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $worker_count; ?></div>
                            <div class="stat-label">Showing</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section - Adjusted to 4 columns (col-3 each) since status column removed -->
            <div class="filter-section">
                <form method="GET" action="manage_workers.php" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="filter-label">Project</div>
                            <select name="project_id" class="filter-control" onchange="this.form.submit()">
                                <option value="">All Projects</option>
                                <?php 
                                $projects_result->data_seek(0);
                                while($project = $projects_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $project['Project_ID']; ?>" 
                                        <?php echo ($filter_project == $project['Project_ID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['Project_Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="filter-label">Package</div>
                            <select name="package_id" class="filter-control" onchange="this.form.submit()">
                                <option value="">All Packages</option>
                                <?php 
                                $packages_result->data_seek(0);
                                while($package = $packages_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $package['Package_ID']; ?>" 
                                        <?php echo ($filter_package == $package['Package_ID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($package['Package_Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="filter-label">Task</div>
                            <select name="task_id" class="filter-control" onchange="this.form.submit()">
                                <option value="">All Tasks</option>
                                <?php 
                                $tasks_result->data_seek(0);
                                while($task = $tasks_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $task['Maintenance_ID']; ?>" 
                                        <?php echo ($filter_task == $task['Maintenance_ID']) ? 'selected' : ''; ?>>
                                        #<?php echo $task['Maintenance_ID']; ?> - <?php echo $task['Task_type']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="filter-label">Assignment Level</div>
                            <select name="level" class="filter-control" onchange="this.form.submit()">
                                <option value="">All Levels</option>
                                <option value="project" <?php echo ($filter_level == 'project') ? 'selected' : ''; ?>>Project Level</option>
                                <option value="package" <?php echo ($filter_level == 'package') ? 'selected' : ''; ?>>Package Level</option>
                                <option value="maintenance" <?php echo ($filter_level == 'maintenance') ? 'selected' : ''; ?>>Task Level</option>
                            </select>
                        </div>
                    </div>
                </form>
                
                <!-- Active Filters - Status filter removed -->
                <?php if(!empty($filter_project) || !empty($filter_package) || !empty($filter_task) || !empty($filter_level)): ?>
                    <div class="mt-3 pt-2 border-top">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge bg-light text-dark">Active:</span>
                            <?php if(!empty($filter_project)): 
                                $project_name = "";
                                $projects_result->data_seek(0);
                                while($p = $projects_result->fetch_assoc()) {
                                    if($p['Project_ID'] == $filter_project) {
                                        $project_name = $p['Project_Name'];
                                        break;
                                    }
                                }
                            ?>
                                <span class="badge bg-primary">Project: <?php echo htmlspecialchars($project_name); ?></span>
                            <?php endif; ?>
                            
                            <?php if(!empty($filter_package)): 
                                $package_name = "";
                                $packages_result->data_seek(0);
                                while($p = $packages_result->fetch_assoc()) {
                                    if($p['Package_ID'] == $filter_package) {
                                        $package_name = $p['Package_Name'];
                                        break;
                                    }
                                }
                            ?>
                                <span class="badge bg-success">Package: <?php echo htmlspecialchars($package_name); ?></span>
                            <?php endif; ?>
                            
                            <?php if(!empty($filter_task)): ?>
                                <span class="badge bg-info">Task #<?php echo $filter_task; ?></span>
                            <?php endif; ?>
                            
                            <?php if(!empty($filter_level)): ?>
                                <span class="badge bg-warning">Level: <?php echo ucfirst($filter_level); ?></span>
                            <?php endif; ?>
                            
                            <a href="manage_workers.php" class="clear-filter">
                                <i class="fas fa-times-circle me-1"></i>Clear All
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Workers Table - Status column removed -->
            <div class="content-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>Employees</h4>
                    <span class="badge bg-secondary"><?php echo $worker_count; ?> found</span>
                </div>

                <?php if($workers_result && $workers_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Employee Name</th>
                                    <th>Designation</th>
                                    <th>Salary</th>
                                    <th>Active Assignments</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($worker = $workers_result->fetch_assoc()): ?>
                                    <?php
                                    $has_active = $worker['active_assignments'] > 0;
                                    ?>
                                    <tr>
                                        <td><strong>#<?php echo $worker['Worker_ID']; ?></strong></td>
                                        <td>
                                            <a href="view_worker.php?id=<?php echo $worker['Worker_ID']; ?>" class="text-decoration-none">
                                                <strong><?php echo htmlspecialchars($worker['Worker_Name']); ?></strong>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="designation-badge" title="<?php echo htmlspecialchars($worker['permanent_designation'] ?: 'Not set'); ?>">
                                                <?php echo htmlspecialchars($worker['permanent_designation'] ?: 'Not set'); ?>
                                            </span>
                                        </td>
                                        <td><span class="salary-text">৳<?php echo number_format($worker['Worker_Salary'], 0); ?></span></td>
                                        <td>
                                            <?php if($worker['active_assignments'] > 0): ?>
                                                <span class="active-assignments-badge">
                                                    <?php echo $worker['active_assignments']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="active-assignments-zero">
                                                    0
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="actions-container">
                                                <a href="view_worker.php?id=<?php echo $worker['Worker_ID']; ?>" class="action-btn btn-view" title="View Employee Details">
                                                    <i class="fas fa-user"></i> View
                                                </a>
                                                <a href="assign_work.php?worker_id=<?php echo $worker['Worker_ID']; ?>" class="action-btn btn-assign" title="Assign Work">
                                                    <i class="fas fa-briefcase"></i> Assign
                                                </a>
                                                <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $worker['Worker_ID']; ?>)" class="action-btn btn-remove" title="Remove Employee Permanently">
                                                    <i class="fas fa-trash-alt"></i> Remove
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-hard-hat"></i>
                        <h5>No Employees Found</h5>
                        <p class="text-muted">No employees match your current filter criteria.</p>
                        <?php if(!empty($filter_project) || !empty($filter_package) || !empty($filter_task) || !empty($filter_level)): ?>
                            <a href="manage_workers.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-times me-1"></i>Clear Filters
                            </a>
                        <?php else: ?>
                            <a href="add_worker.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-user-plus me-1"></i>Add First Employee
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function confirmDelete(workerId) {
        if (confirm('⚠️ ARE YOU SURE?\n\nYou are about to permanently remove this employee from the database and from all work assignmenets. This action CANNOT be undone.\n\nDo you want to continue?')) {
            window.location.href = 'manage_workers.php?delete_worker=' + workerId;
        }
    }
    
    document.getElementById('filterForm').addEventListener('change', function() {
        this.submit();
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>