<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_maintenance.php");
    exit();
}

$maintenance_id = $_GET['id'];

// Debug: Check if maintenance ID is valid
if(!is_numeric($maintenance_id)) {
    die("Invalid maintenance ID");
}

// Get task basic info for header
$task_query = "
    SELECT m.Maintenance_ID, m.Task_type, m.Status, m.Cost
    FROM Maintenance m
    WHERE m.Maintenance_ID = ?
";
$stmt = $conn->prepare($task_query);
if(!$stmt) {
    die("Error preparing task query: " . $conn->error);
}
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$task_result = $stmt->get_result();

if($task_result->num_rows === 0) {
    header("Location: manage_maintenance.php");
    exit();
}

$task = $task_result->fetch_assoc();
$stmt->close();

// total resource cost
$resource_cost_query = "
    SELECT 
        COALESCE(SUM(Quantity * Unit_Cost), 0) as Total_Resource_Cost,
        COUNT(*) as Resource_Count
    FROM Resources 
    WHERE Maintenance_ID = ?
";
$stmt = $conn->prepare($resource_cost_query);
if(!$stmt) {
    die("Error preparing resource query: " . $conn->error);
}
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$resource_cost_result = $stmt->get_result();
$resource_data = $resource_cost_result->fetch_assoc();
$stmt->close();

// total employee cost from worker_assignments
$employee_cost_query = "
    SELECT 
        COALESCE(SUM(w.Worker_Salary), 0) as Total_Employee_Cost,
        COUNT(DISTINCT wa.Assignment_ID) as Employee_Count
    FROM worker_assignments wa
    JOIN Workers w ON wa.Worker_ID = w.Worker_ID
    WHERE wa.Maintenance_ID = ? AND wa.Status IN ('Active', 'Paused', 'Completed')
";
$stmt = $conn->prepare($employee_cost_query);
if(!$stmt) {
    die("Error preparing employee cost query: " . $conn->error);
}
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$employee_cost_result = $stmt->get_result();
$employee_data = $employee_cost_result->fetch_assoc();
$stmt->close();

// total spent cost (resources + employees only)
$total_actual_cost = $resource_data['Total_Resource_Cost'] + $employee_data['Total_Employee_Cost'];

//budget utilization
$budget_utilization = 0;
$budget_status = 'No Budget';
$status_class = 'badge-info';
$progress_class = 'bg-info';

if($task['Cost'] > 0) {
    $budget_utilization = ($total_actual_cost / $task['Cost']) * 100;
    
    if($budget_utilization <= 70) {
        $budget_status = 'Under Budget';
        $status_class = 'badge-good';
        $progress_class = 'bg-success';
    } elseif($budget_utilization <= 90) {
        $budget_status = 'Approaching Limit';
        $status_class = 'badge-warning';
        $progress_class = 'bg-warning';
    } else {
        $budget_status = 'Over Budget';
        $status_class = 'badge-danger';
        $progress_class = 'bg-danger';
    }
}

// all resources for this maintenance
$resources_query = "
    SELECT r.*, (r.Quantity * r.Unit_Cost) as Total_Cost 
    FROM Resources r 
    WHERE r.Maintenance_ID = ? 
    ORDER BY r.Resource_ID DESC
";
$stmt = $conn->prepare($resources_query);
if(!$stmt) {
    die("Error preparing resources list query: " . $conn->error);
}
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$resources_result = $stmt->get_result();
$stmt->close();

//all employees details for this maintenance from worker_assignments
$employees_query = "
    SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Designation,
           wa.Role, wa.Assignment_ID, wa.Status as Participation_Status, wa.Assigned_Date
    FROM worker_assignments wa
    JOIN Workers w ON wa.Worker_ID = w.Worker_ID
    WHERE wa.Maintenance_ID = ? 
    ORDER BY 
        CASE wa.Status
            WHEN 'Active' THEN 1
            WHEN 'Paused' THEN 2
            WHEN 'Completed' THEN 3
            WHEN 'Fired' THEN 4
            ELSE 5
        END,
        wa.Assigned_Date DESC
";
$stmt = $conn->prepare($employees_query);
if(!$stmt) {
    die("Error preparing employees list query: " . $conn->error);
}
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$employees_result = $stmt->get_result();
$stmt->close();

// Function to get status badge class for task status
function getTaskStatusClass($status) {
    switch($status) {
        case 'Not Started': return 'badge-not-started';
        case 'Active': return 'badge-active';
        case 'Paused': return 'badge-paused';
        case 'Completed': return 'badge-completed';
        case 'Cancelled': return 'badge-cancelled';
        default: return 'badge-secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Budget - Smart DNCC</title>
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
            --paused: #fbbf24;
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

        /* Section Header with Button */
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

        /* UNIFORM BADGES - Standardized */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 100px;
            text-align: center;
            display: inline-block;
        }

        .badge-not-started {
            background: #e2e8f0;
            color: #475569;
            border: 1px solid #cbd5e0;
        }
        .badge-active {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .badge-paused {
            background: #fed7aa;
            color: #92400e;
            border: 1px solid #fdba74;
        }
        .badge-completed {
            background: #e9d8fd;
            color: #553c9a;
            border: 1px solid #d6bcfa;
        }
        .badge-cancelled {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .badge-good { 
            background: #d1fae5; 
            color: #065f46; 
            border: 1px solid #a7f3d0; 
        }
        .badge-warning { 
            background: #fed7aa; 
            color: #92400e; 
            border: 1px solid #fdba74; 
        }
        .badge-danger { 
            background: #fee2e2; 
            color: #b91c1c; 
            border: 1px solid #fecaca; 
        }
        .badge-info { 
            background: #e2e8f0; 
            color: #475569; 
            border: 1px solid #cbd5e0; 
        }

        /* Cost Cards - UNIFORM */
        .cost-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .cost-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            text-align: center;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .cost-amount {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .cost-label {
            color: #718096;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .progress {
            height: 8px;
            margin: 12px 0;
            background-color: #edf2f7;
        }

        /* Resource/Employee Rows */
        .resource-row, .employee-row {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
        }
        .resource-row:hover, .employee-row:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
        }
        
        .employee-row.active-employee {
            border-left: 4px solid var(--success);
        }
        .employee-row.paused-employee {
            border-left: 4px solid var(--paused);
        }
        .employee-row.completed-employee {
            border-left: 4px solid var(--info);
        }
        .employee-row.fired-employee {
            border-left: 4px solid var(--danger);
        }

        .resource-info, .employee-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            flex: 1;
        }

        .resource-id, .employee-id {
            background: #e2e8f0;
            color: #2d3748;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 50px;
            text-align: center;
        }

        .resource-actions, .employee-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
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
            min-width: 80px;
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

        .btn-resource-header {
            background: #fffaf0;
            color: #d69e2e;
            border: 1px solid #faf089;
            min-width: 120px;
        }
        .btn-resource-header:hover {
            background: #d69e2e;
            color: white;
            border-color: #d69e2e;
        }

        .btn-employee-header {
            background: #f0fff4;
            color: #38a169;
            border: 1px solid #9ae6b4;
            min-width: 120px;
        }
        .btn-employee-header:hover {
            background: #38a169;
            color: white;
            border-color: #38a169;
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

        .alert {
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            padding: 12px 20px;
        }
        .alert-warning {
            background: #fffaf0;
            color: #744210;
            border: 1px solid #fbd38d;
        }

        .cost-highlight {
            font-weight: 600;
            color: #276749;
        }

        .budget-remaining {
            font-weight: 600;
            color: #2c5aa0;
        }

        .actual-cost {
            font-weight: 600;
            color: #d69e2e;
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
            
            .cost-cards {
                grid-template-columns: 1fr;
            }
            
            .resource-row, .employee-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .resource-actions, .employee-actions {
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
                <h2>Task Budget Analysis</h2>
                <p>Budget breakdown for task #<?php echo $maintenance_id; ?></p>
            </div>
            <div class="header-buttons">
                <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Task
                </a>
            </div>
        </div>

        <div class="content-area">
            
            <!-- Simple Task Header -->
            <div class="task-header">
                <h3><i class="fas fa-tasks me-2"></i><?php echo htmlspecialchars($task['Task_type']); ?></h3>
                <div class="task-meta">
                    <span><i class="fas fa-info-circle"></i> Status: 
                        <span class="status-badge <?php echo getTaskStatusClass($task['Status']); ?>">
                            <?php echo $task['Status']; ?>
                        </span>
                    </span>
                    <span><i class="fas fa-coins"></i> Allocated Budget: ৳<?php echo number_format($task['Cost'], 2); ?></span>
                </div>
            </div>

            <!-- Budget Overview -->
            <div class="content-section">
                <h4 class="mb-3">Budget Overview</h4>
                <?php if($task['Cost'] > 0): ?>
                    <div class="cost-cards">
                        <div class="cost-card">
                            <div class="cost-amount text-primary">৳<?php echo number_format($task['Cost'], 2); ?></div>
                            <div class="cost-label">Allocated Budget</div>
                        </div>
                        <div class="cost-card">
                            <div class="cost-amount text-success">৳<?php echo number_format($total_actual_cost, 2); ?></div>
                            <div class="cost-label">Actual Cost</div>
                        </div>
                        <div class="cost-card">
                            <div class="cost-amount text-info">৳<?php echo number_format($task['Cost'] - $total_actual_cost, 2); ?></div>
                            <div class="cost-label">Remaining Budget</div>
                        </div>
                    </div>

                    <!-- Budget Progress -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="fw-bold">Budget Utilization</span>
                            <span class="fw-bold"><?php echo number_format($budget_utilization, 1); ?>%</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar <?php echo $progress_class; ?>" 
                                 role="progressbar" 
                                 style="width: <?php echo min($budget_utilization, 100); ?>%"
                                 aria-valuenow="<?php echo $budget_utilization; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <span class="status-badge <?php echo $status_class; ?>">
                                <i class="fas fa-chart-line me-1"></i><?php echo $budget_status; ?>
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>No Budget Allocated</strong> - This maintenance task doesn't have a budget assigned.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Cost Breakdown - 2x2 Grid -->
            <div class="content-section">
                <h4 class="mb-3">Cost Breakdown</h4>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="info-card" style="border-left-color: var(--warning);">
                            <div class="info-label">Resource Cost</div>
                            <div class="info-value">৳<?php echo number_format($resource_data['Total_Resource_Cost'], 2); ?></div>
                            <small class="text-muted"><?php echo $resource_data['Resource_Count']; ?> resource(s)</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-card" style="border-left-color: var(--success);">
                            <div class="info-label">Employee Cost</div>
                            <div class="info-value">৳<?php echo number_format($employee_data['Total_Employee_Cost'], 2); ?></div>
                            <small class="text-muted"><?php echo $employee_data['Employee_Count']; ?> employee(s)</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resources Section - With Header Button -->
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-boxes me-2" style="color: var(--warning);"></i>
                        Resources (<?php echo $resource_data['Resource_Count']; ?>)
                    </h4>
                    <a href="view_resource_maintenance.php?id=<?php echo $maintenance_id; ?>" class="action-btn btn-resource-header">
                        <i class="fas fa-edit"></i> Manage Resources
                    </a>
                </div>

                <?php if($resources_result && $resources_result->num_rows > 0): ?>
                    <?php while($resource = $resources_result->fetch_assoc()): ?>
                        <div class="resource-row">
                            <div class="resource-info">
                                <span class="resource-id">#<?php echo $resource['Resource_ID']; ?></span>
                                <span><i class="fas fa-cube me-1" style="color: #d69e2e;"></i> <?php echo htmlspecialchars($resource['Resource_Type']); ?></span>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-hashtag me-1"></i> Qty: <?php echo number_format($resource['Quantity']); ?>
                                </span>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-money-bill me-1"></i> ৳<?php echo number_format($resource['Unit_Cost'], 2); ?>/u
                                </span>
                                <span class="cost-highlight">
                                    ৳<?php echo number_format($resource['Total_Cost'], 2); ?>
                                </span>
                            </div>
                            <div class="resource-actions">
                                <a href="view_resource.php?id=<?php echo $resource['Resource_ID']; ?>" class="action-btn btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-boxes"></i>
                        <h5>No Resources</h5>
                        <p class="text-muted">No resources have been allocated to this maintenance task.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Employees Section - With Header Button and participation status -->
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-hard-hat me-2" style="color: var(--success);"></i>
                        Employees (<?php echo $employee_data['Employee_Count']; ?>)
                    </h4>
                    <a href="view_worker_maintenance.php?id=<?php echo $maintenance_id; ?>" class="action-btn btn-employee-header">
                        <i class="fas fa-edit"></i> Manage Employees
                    </a>
                </div>

                <?php if($employees_result && $employees_result->num_rows > 0): ?>
                    <?php while($employee = $employees_result->fetch_assoc()): 
                        $row_class = '';
                        if($employee['Participation_Status'] == 'Active') $row_class = 'active-employee';
                        elseif($employee['Participation_Status'] == 'Paused') $row_class = 'paused-employee';
                        elseif($employee['Participation_Status'] == 'Completed') $row_class = 'completed-employee';
                        elseif($employee['Participation_Status'] == 'Fired') $row_class = 'fired-employee';
                    ?>
                    <div class="employee-row <?php echo $row_class; ?>">
                        <div class="employee-info">
                            <span class="employee-id">#<?php echo $employee['Worker_ID']; ?></span>
                            <span><i class="fas fa-user-tie me-1" style="color: #38a169;"></i> <?php echo htmlspecialchars($employee['Worker_Name']); ?></span>
                            
                            <!-- Role from worker_assignments -->
                            <?php if(isset($employee['Role'])): ?>
                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($employee['Role']); ?></span>
                            <?php endif; ?>
                            
                            <!-- Participation Status Badge -->
                            <?php if(isset($employee['Participation_Status'])): ?>
                                <?php
                                $participation_class = '';
                                if($employee['Participation_Status'] == 'Active') $participation_class = 'badge-active';
                                elseif($employee['Participation_Status'] == 'Paused') $participation_class = 'badge-paused';
                                elseif($employee['Participation_Status'] == 'Completed') $participation_class = 'badge-completed';
                                elseif($employee['Participation_Status'] == 'Fired') $participation_class = 'badge-cancelled';
                                ?>
                                <span class="status-badge <?php echo $participation_class; ?>" style="min-width: 80px;">
                                    <?php echo $employee['Participation_Status']; ?>
                                </span>
                            <?php endif; ?>
                            
                            <span class="cost-highlight">
                                ৳<?php echo number_format($employee['Worker_Salary'], 2); ?>
                            </span>
                            
                            <?php if(isset($employee['Assigned_Date'])): ?>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-calendar me-1"></i> <?php echo date('M Y', strtotime($employee['Assigned_Date'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="employee-actions">
                            <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-hard-hat"></i>
                        <h5>No Employees</h5>
                        <p class="text-muted">No employees have been assigned to this maintenance task.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>