<?php
session_start();
include("connect.php");

if(isset($_GET['success'])) {
    if($_GET['success'] == 'worker_added') {
        $success_msg = "Employee added successfully!";
    } elseif($_GET['success'] == 'worker_updated') {
        $success_msg = "Employee updated successfully!";
    } elseif($_GET['success'] == 'worker_assigned') {
        $success_msg = "Employee assigned successfully!";
    } elseif($_GET['success'] == 'worker_removed') {
        $success_msg = "Employee removed successfully!";
    }
}

// Check if user is logged in
if(!isset($_SESSION['email'])) {
    header("Location: logout.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_citizen = ($_SESSION['role'] == 'citizen');

if(!isset($_GET['id']) || empty($_GET['id'])) {
    if($is_admin) {
        header("Location: manage_maintenance.php");
    } else {
        header("Location: citizen_maintenance.php");
    }
    exit();
}

$maintenance_id = $_GET['id'];

// Get task status
if($is_admin) {
    $status_query = "SELECT Status FROM maintenance WHERE Maintenance_ID = ?";
} else {
    $status_query = "SELECT Status FROM maintenance WHERE Maintenance_ID = ?";
}

$stmt = $conn->prepare($status_query);
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$status_result = $stmt->get_result();

if($status_result->num_rows === 0) {
    if($is_admin) {
        header("Location: manage_maintenance.php");
    } else {
        header("Location: citizen_maintenance.php");
    }
    exit();
}

$maintenance_status = $status_result->fetch_assoc()['Status'];
$is_task_ended = in_array($maintenance_status, ['Completed', 'Cancelled']);
$stmt->close();

// Get basic task info for header
if($is_admin) {
    $maintenance_query = "SELECT m.Task_type, a.Asset_Name, m.Status,
                                 pr.Project_ID, pr.Project_Name,
                                 p.Package_ID, p.Package_Name
                          FROM maintenance m 
                          JOIN assets a ON m.Asset_ID = a.Asset_ID 
                          LEFT JOIN projects pr ON m.Project_ID = pr.Project_ID
                          LEFT JOIN packages p ON m.Package_ID = p.Package_ID
                          WHERE m.Maintenance_ID = ?";
} else {
    $maintenance_query = "SELECT m.Task_type, a.Asset_Name, m.Status,
                                 pr.Project_ID, pr.Project_Name,
                                 p.Package_ID, p.Package_Name
                          FROM maintenance m 
                          JOIN assets a ON m.Asset_ID = a.Asset_ID 
                          LEFT JOIN projects pr ON m.Project_ID = pr.Project_ID
                          LEFT JOIN packages p ON m.Package_ID = p.Package_ID
                          WHERE m.Maintenance_ID = ?";
}

$stmt = $conn->prepare($maintenance_query);
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$maintenance_result = $stmt->get_result();

if($maintenance_result->num_rows === 0) {
    if($is_admin) {
        header("Location: manage_maintenance.php");
    } else {
        header("Location: citizen_maintenance.php");
    }
    exit();
}

$maintenance = $maintenance_result->fetch_assoc();
$stmt->close();

// Get employees assigned to this task - SHOW ALL (Active, Paused, Completed, Removed)
if($is_admin) {
    $employees_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                               wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status
                        FROM workers w
                        JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                        WHERE wa.Maintenance_ID = ?
                        ORDER BY 
                            CASE wa.Status
                                WHEN 'Active' THEN 1
                                WHEN 'Paused' THEN 2
                                WHEN 'Completed' THEN 3
                                WHEN 'Removed' THEN 4
                                ELSE 5
                            END,
                            w.Worker_Name ASC";
} else {
    $employees_query = "SELECT w.Worker_ID, w.Worker_Name, w.Designation, wa.Role
                        FROM workers w
                        JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                        WHERE wa.Maintenance_ID = ? AND wa.Status = 'Active'
                        ORDER BY w.Worker_Name ASC";
}

$stmt = $conn->prepare($employees_query);
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$employees_result = $stmt->get_result();
$employee_count = $employees_result->num_rows;
$stmt->close();

// Handle remove employee (admin only)
if($is_admin && isset($_GET['remove_worker']) && !$is_task_ended) {
    $assignment_id = $_GET['remove_worker'];
    
    // Update to Removed status
    $remove_query = "UPDATE worker_assignments SET Status = 'Removed' WHERE Assignment_ID = ? AND Maintenance_ID = ?";
    $stmt = $conn->prepare($remove_query);
    $stmt->bind_param("ii", $assignment_id, $maintenance_id);
    
    if($stmt->execute()) {
        header("Location: view_maintenance_workers.php?id=$maintenance_id&success=worker_removed");
        exit();
    } else {
        $error_msg = "Error removing employee: " . $conn->error;
    }
    $stmt->close();
}

// Handle inline worker status update via AJAX
if(isset($_POST['inline_worker_status'])) {
    $assignment_id = $_POST['assignment_id'];
    $new_status = $_POST['worker_status'];
    
    $update_query = "UPDATE worker_assignments SET Status = ? WHERE Assignment_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $new_status, $assignment_id);
    
    if($stmt->execute()) {
        echo json_encode(['success' => true, 'status' => $new_status]);
        exit();
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit();
    }
}

// Get valid worker assignment statuses
$status_query = "SHOW COLUMNS FROM worker_assignments LIKE 'Status'";
$status_result = $conn->query($status_query);
$status_row = $status_result->fetch_assoc();
preg_match("/^enum\(\'(.*)\'\)$/", $status_row['Type'], $matches);
$worker_statuses = explode("','", $matches[1]);

// Separate active/paused from inactive for display
$active_employees = [];
$inactive_employees = [];

while($employee = $employees_result->fetch_assoc()) {
    if($employee['Participation_Status'] == 'Active' || $employee['Participation_Status'] == 'Paused') {
        $active_employees[] = $employee;
    } else {
        $inactive_employees[] = $employee;
    }
}
$employees_result->data_seek(0);
$active_count = count($active_employees);
$inactive_count = count($inactive_employees);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Employees - Smart DNCC</title>
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
            --paused: #fbbf24;
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
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* Simple Task Header */
        .task-header {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            border-radius: 12px;
            padding: 20px 28px;
            margin-bottom: 24px;
            color: white;
        }

        .task-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0 0 8px 0;
            color: white;
        }

        .task-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .task-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Status badges - Standardized */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 80px;
            text-align: center;
            display: inline-block;
        }

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

        /* Worker Status Badges */
        .worker-status-badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
        }
        .worker-status-badge.active {
            background: #c6f6d5;
            color: #276749;
        }
        .worker-status-badge.paused {
            background: #fed7aa;
            color: #92400e;
        }
        .worker-status-badge.completed {
            background: #e9d8fd;
            color: #6b46c1;
        }
        .worker-status-badge.removed {
            background: #fed7d7;
            color: #c53030;
        }

        /* Worker Status Display */
        .worker-status-display {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            padding: 4px 12px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .worker-status-text {
            font-size: 0.75rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        .worker-status-edit-btn {
            background: none;
            border: none;
            color: var(--success);
            cursor: pointer;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .worker-status-edit-btn:hover {
            background: #e2e8f0;
        }
        
        .worker-status-edit-form {
            display: none;
            align-items: center;
            gap: 4px;
        }
        
        .worker-status-edit-form select {
            padding: 4px 8px;
            border-radius: 20px;
            border: 1px solid #cbd5e0;
            font-size: 0.7rem;
            min-width: 90px;
        }
        
        .worker-status-save-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            cursor: pointer;
        }
        
        .worker-status-cancel-btn {
            background: #e2e8f0;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            cursor: pointer;
        }

        /* Hierarchy Breadcrumb */
        .breadcrumb-nav {
            margin-bottom: 20px;
        }

        .breadcrumb {
            background: white;
            padding: 12px 20px;
            border-radius: 30px;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .breadcrumb-item {
            display: flex;
            align-items: center;
        }

        .breadcrumb-link {
            color: #4a5568;
            text-decoration: none;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .breadcrumb-link:hover {
            background: #edf2f7;
        }

        .breadcrumb-current {
            color: #2d3748;
            font-weight: 600;
            padding: 4px 12px;
            background: #edf2f7;
            border-radius: 20px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Employee Row */
        .employee-row {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            border-left: 4px solid transparent;
        }
        .employee-row:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
        }
        .employee-row.active-employee {
            border-left-color: var(--success);
        }
        .employee-row.paused-employee {
            border-left-color: var(--paused);
        }
        .employee-row.inactive-employee {
            border-left-color: var(--danger);
            background: #fff5f5;
            opacity: 0.8;
        }

        .employee-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            flex: 1;
        }

        .employee-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
            align-items: center;
        }

        .role-badge {
            background: #e2e8f0;
            color: #2d3748;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 50px;
            text-align: center;
            display: inline-block;
        }

        /* Section Header */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-header h4 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* UNIFORM BUTTONS */
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
            transition: all 0.2s;
            min-width: 70px;
            justify-content: center;
        }

        .btn-view {
            background: #ebf8ff;
            color: #3182ce;
            border: 1px solid #bee3f8;
        }
        .btn-view:hover {
            background: #3182ce;
            color: white;
            border-color: #3182ce;
        }

        .btn-success {
            background: var(--success);
            color: white;
            border: none;
            min-width: 120px;
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            text-decoration: none;
        }
        .btn-success:hover {
            background: #2f855a;
        }

        .btn-back {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            min-width: 120px;
            justify-content: center;
        }
        .btn-back:hover {
            background: #718096;
            color: white;
        }

        .header-buttons {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            min-width: 70px;
            text-align: center;
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

        .status-info {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #856404;
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
        h2 { font-size: 1.35rem; }
        h4 { font-size: 1.1rem; }

        .text-muted {
            color: #718096 !important;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .admin-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .header-buttons {
                width: 100%;
                flex-direction: column;
            }
            
            .header-buttons a {
                width: 100%;
            }
            
            .task-meta {
                flex-direction: column;
                gap: 8px;
            }
            
            .employee-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .employee-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Task Employees</h2>
                <p>Manage employees assigned to this task</p>
            </div>
            <div class="header-buttons">
                <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Task
                </a>
                <?php if($is_admin && !$is_task_ended): ?>
                    <a href="add_worker.php?maintenance_id=<?php echo $maintenance_id; ?>" class="btn-success">
                        <i class="fas fa-plus-circle me-1"></i> Add Employee
                    </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="content-area">
            
            <?php if(isset($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Simple Breadcrumb Navigation -->
            <div class="breadcrumb-nav">
                <div class="breadcrumb">
                    <?php if(!empty($maintenance['Project_ID'])): ?>
                        <span class="breadcrumb-item">
                            <a href="view_project.php?id=<?php echo $maintenance['Project_ID']; ?>" class="breadcrumb-link">
                                <i class="fas fa-folder-open"></i> #<?php echo $maintenance['Project_ID']; ?>
                            </a>
                        </span>
                        <i class="fas fa-chevron-right" style="color: #cbd5e0; font-size: 0.7rem;"></i>
                    <?php endif; ?>
                    
                    <?php if(!empty($maintenance['Package_ID'])): ?>
                        <span class="breadcrumb-item">
                            <a href="view_package.php?id=<?php echo $maintenance['Package_ID']; ?>" class="breadcrumb-link">
                                <i class="fas fa-cubes"></i> #<?php echo $maintenance['Package_ID']; ?>
                            </a>
                        </span>
                        <i class="fas fa-chevron-right" style="color: #cbd5e0; font-size: 0.7rem;"></i>
                    <?php endif; ?>
                    
                    <span class="breadcrumb-item">
                        <span class="breadcrumb-current">
                            <i class="fas fa-tasks"></i> #<?php echo $maintenance_id; ?>
                        </span>
                    </span>
                </div>
            </div>

            <!-- Simple Task Header with standardized status -->
            <div class="task-header">
                <h3><i class="fas fa-tasks me-2"></i><?php echo htmlspecialchars($maintenance['Task_type']); ?></h3>
                <div class="task-meta">
                    <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($maintenance['Asset_Name']); ?></span>
                    <span><i class="fas fa-info-circle"></i> Status: 
                        <?php
                        $task_status_class = '';
                        if($maintenance['Status'] == 'Not Started') $task_status_class = 'status-not-started';
                        elseif($maintenance['Status'] == 'Active') $task_status_class = 'status-active';
                        elseif($maintenance['Status'] == 'Paused') $task_status_class = 'status-paused';
                        elseif($maintenance['Status'] == 'Completed') $task_status_class = 'status-completed';
                        elseif($maintenance['Status'] == 'Cancelled') $task_status_class = 'status-cancelled';
                        ?>
                        <span class="status-badge <?php echo $task_status_class; ?>">
                            <?php echo $maintenance['Status']; ?>
                        </span>
                    </span>
                </div>
            </div>

            <!-- Task Completed/Cancelled Warning -->
            <?php if($is_task_ended): ?>
            <div class="status-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>This task is <?php echo $maintenance['Status']; ?>.</strong> 
                You cannot add or modify employees for <?php echo strtolower($maintenance['Status']); ?> tasks.
            </div>
            <?php endif; ?>

            <!-- Active Employees Section -->
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-users me-2" style="color: #e53e3e;"></i>
                        Segment level employee: (<?php echo $active_count; ?>)
                    </h4>
                </div>

                <?php if($active_count > 0): ?>
                    <?php foreach($active_employees as $employee): ?>
                    <div class="employee-row <?php echo ($employee['Participation_Status'] == 'Active') ? 'active-employee' : 'paused-employee'; ?>">
                        <div class="employee-info">
                            <span class="role-badge">#<?php echo $employee['Worker_ID']; ?></span>
                            <span><i class="fas fa-user-tie me-1" style="color: #3182ce;"></i> <?php echo htmlspecialchars($employee['Worker_Name']); ?></span>
                            <span class="role-badge"><?php echo htmlspecialchars($employee['Role']); ?></span>
                            
                            <?php if($is_admin): ?>
                                <?php if(!empty($employee['Designation'])): ?>
                                    <span class="badge bg-light text-dark"><?php echo htmlspecialchars($employee['Designation']); ?></span>
                                <?php endif; ?>
                                
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-calendar me-1"></i> <?php echo date('M Y', strtotime($employee['Assigned_Date'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if($is_admin && !$is_task_ended): ?>
                        <div class="employee-actions">
                            <!-- Status Display with Edit Button -->
                            <div id="worker-status-display-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-display">
                                <span class="worker-status-text">Status:</span>
                                <span class="worker-status-badge <?php echo strtolower($employee['Participation_Status']); ?>">
                                    <?php echo $employee['Participation_Status']; ?>
                                </span>
                                <button type="button" class="worker-status-edit-btn" onclick="showWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                    <i class="fas fa-edit" style="color: var(--success);"></i>
                                </button>
                            </div>
                            
                            <!-- Status Edit Form (hidden by default) -->
                            <div id="worker-status-edit-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-edit-form">
                                <select id="worker-status-select-<?php echo $employee['Assignment_ID']; ?>" class="form-select">
                                    <?php foreach($worker_statuses as $status_option): ?>
                                        <option value="<?php echo $status_option; ?>" 
                                            <?php echo ($employee['Participation_Status'] == $status_option) ? 'selected' : ''; ?>>
                                            <?php echo $status_option; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="worker-status-save-btn" onclick="saveWorkerStatus(<?php echo $employee['Assignment_ID']; ?>)">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button type="button" class="worker-status-cancel-btn" onclick="cancelWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            
                            <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view">
                                View
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-hard-hat"></i>
                        <h5>No Active Employees</h5>
                        <p class="text-muted">No active or paused employees are currently assigned to this task.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Inactive Employees Section (Completed/Removed) -->
            <?php if($inactive_count > 0): ?>
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-user-slash me-2" style="color: #718096;"></i>
                        Inactive Members (<?php echo $inactive_count; ?>)
                    </h4>
                </div>
                
                <?php foreach($inactive_employees as $employee): ?>
                <div class="employee-row inactive-employee">
                    <div class="employee-info">
                        <span class="role-badge">#<?php echo $employee['Worker_ID']; ?></span>
                        <span><i class="fas fa-user-tie me-1" style="color: #718096;"></i> <?php echo htmlspecialchars($employee['Worker_Name']); ?></span>
                        <span class="role-badge"><?php echo htmlspecialchars($employee['Role']); ?></span>
                        
                        <?php if($is_admin): ?>
                            <?php if(!empty($employee['Designation'])): ?>
                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($employee['Designation']); ?></span>
                            <?php endif; ?>
                            
                            <!-- Status Badge - Shows Completed or Removed -->
                            <span class="worker-status-badge <?php echo strtolower($employee['Participation_Status']); ?>">
                                <?php echo $employee['Participation_Status']; ?>
                            </span>
                            
                            <span class="badge bg-light text-dark">
                                <i class="fas fa-calendar me-1"></i> <?php echo date('M Y', strtotime($employee['Assigned_Date'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if($is_admin && !$is_task_ended): ?>
                    <div class="employee-actions">
                        <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view">
                            View
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Worker Status Edit Functions
        function showWorkerStatusEdit(assignmentId) {
            document.getElementById('worker-status-display-' + assignmentId).style.display = 'none';
            document.getElementById('worker-status-edit-' + assignmentId).style.display = 'flex';
        }
        
        function cancelWorkerStatusEdit(assignmentId) {
            document.getElementById('worker-status-display-' + assignmentId).style.display = 'flex';
            document.getElementById('worker-status-edit-' + assignmentId).style.display = 'none';
        }
        
        function saveWorkerStatus(assignmentId) {
            var select = document.getElementById('worker-status-select-' + assignmentId);
            var newStatus = select.value;
            
            // Show loading state
            var saveBtn = document.querySelector('#worker-status-edit-' + assignmentId + ' .worker-status-save-btn');
            var originalHtml = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            saveBtn.disabled = true;
            
            // Send AJAX request
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                saveBtn.innerHTML = originalHtml;
                saveBtn.disabled = false;
                
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            // Update the display
                            var statusDisplay = document.getElementById('worker-status-display-' + assignmentId);
                            var statusBadge = statusDisplay.querySelector('.worker-status-badge');
                            statusBadge.className = 'worker-status-badge ' + newStatus.toLowerCase();
                            statusBadge.textContent = newStatus;
                            
                            // Hide edit form, show display
                            cancelWorkerStatusEdit(assignmentId);
                            
                            // Reload page to reflect any changes
                            location.reload();
                        } else {
                            alert('Error updating status: ' + response.error);
                        }
                    } catch(e) {
                        alert('Error updating status');
                    }
                } else {
                    alert('Server error');
                }
            };
            xhr.send('inline_worker_status=1&assignment_id=' + assignmentId + '&worker_status=' + encodeURIComponent(newStatus));
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>