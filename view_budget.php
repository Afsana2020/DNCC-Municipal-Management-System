<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_budgest.php");
    exit();
}

$budget_id = $_GET['id'];

// budget information with asset details
$budget_query = "
    SELECT 
        b.*,
        a.Asset_Name,
        a.Asset_Type,
        a.Location,
        a.Asset_Condition,
        (b.Amount_Allocation - b.Amount_Spent) as Remaining_Amount,
        CASE 
            WHEN b.Amount_Spent = 0 THEN 0
            ELSE (b.Amount_Spent / b.Amount_Allocation) * 100 
        END as Spending_Percentage
    FROM Budgets b
    LEFT JOIN Assets a ON b.Asset_ID = a.Asset_ID
    WHERE b.Budget_ID = ?
";
$stmt = $conn->prepare($budget_query);
$stmt->bind_param("i", $budget_id);
$stmt->execute();
$budget_result = $stmt->get_result();

if($budget_result->num_rows === 0) {
    header("Location: manage_budgets.php");
    exit();
}

$budget = $budget_result->fetch_assoc();
$stmt->close();

// total maintenance costs for all maintenance tasks
$total_maintenance_cost = 0;
$maintenance_tasks = [];

if($budget['Asset_ID']) {
    // all maintenance tasks for this asset with their total costs
    $maintenance_query = "
        SELECT m.*, 
               (SELECT COALESCE(SUM(r.Quantity * r.Unit_Cost), 0) 
                FROM Resources r 
                WHERE r.Maintenance_ID = m.Maintenance_ID) as Resource_Cost,
               (SELECT COALESCE(SUM(w.Worker_Salary), 0) 
                FROM Workers w 
                WHERE w.Maintenance_ID = m.Maintenance_ID) as Worker_Cost
        FROM Maintenance m 
        WHERE m.Asset_ID = ?
        ORDER BY m.Start_Date DESC
    ";
    $stmt = $conn->prepare($maintenance_query);
    $stmt->bind_param("i", $budget['Asset_ID']);
    $stmt->execute();
    $maintenance_result = $stmt->get_result();
    
    while($task = $maintenance_result->fetch_assoc()) {
        // total cost for this maintenance (resources + workers)
        $task_total_cost = $task['Resource_Cost'] + $task['Worker_Cost'];
        $total_maintenance_cost += $task_total_cost;
        
        $maintenance_tasks[] = array_merge($task, ['Total_Cost' => $task_total_cost]);
    }
    $stmt->close();

    // Update the Amount_Spent
    if($total_maintenance_cost != $budget['Amount_Spent']) {
        $update_query = "UPDATE Budgets SET Amount_Spent = ? WHERE Budget_ID = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("di", $total_maintenance_cost, $budget_id);
        $stmt->execute();
        $stmt->close();
        
        
        $stmt = $conn->prepare($budget_query);
        $stmt->bind_param("i", $budget_id);
        $stmt->execute();
        $budget_result = $stmt->get_result();
        $budget = $budget_result->fetch_assoc();
        $stmt->close();
    }
}


$actual_spent = $budget['Amount_Spent'];
$actual_spending_percentage = ($actual_spent / $budget['Amount_Allocation']) * 100;

// spending status
if($actual_spending_percentage <= 70) {
    $status_class = 'badge-good';
    $status_text = 'Under Budget';
    $progress_class = 'bg-success';
} elseif($actual_spending_percentage <= 90) {
    $status_class = 'badge-warning';
    $status_text = 'Approaching Limit';
    $progress_class = 'bg-warning';
} else {
    $status_class = 'badge-danger';
    $status_text = 'Over Budget';
    $progress_class = 'bg-danger';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yearly Budget Details - Smart DNCC</title>
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
        }

        .admin-body {
            background: #f8f9fa;
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            font-size: 0.875rem;
        }

        .admin-sidebar {
            background: var(--primary);
            min-height: 100vh;
            position: fixed;
            width: 220px;
            z-index: 1000;
        }

        .admin-main {
            margin-left: 220px;
            padding: 0;
            min-height: 100vh;
            overflow-x: hidden;
            width: calc(100% - 220px);
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
            background: var(--accent);
            color: white;
        }

        .admin-nav-link i {
            width: 16px;
            margin-right: 8px;
            font-size: 12px;
        }

        .content-section {
            background: white;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid #dee2e6;
        }

        .admin-header {
            background: white;
            border-bottom: 1px solid #dee2e6;
            padding: 15px 20px;
            margin-bottom: 0;
        }

        .status-badge {
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-good { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-warning { background: #fffaf0; color: #744210; border: 1px solid #faf089; }
        .badge-danger { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        .badge-info { background: #ebf8ff; color: #2c5aa0; border: 1px solid #90cdf4; }

        .info-card {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
            border-left: 4px solid var(--accent);
        }

        .budget-card {
            background: white;
            border-radius: 8px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            text-align: center;
            position: relative;
        }

        .budget-period {
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--info);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.6rem;
            font-weight: 600;
        }

        .budget-amount {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .budget-label {
            color: #718096;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .progress {
            height: 8px;
            margin: 12px 0;
        }

        .info-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.75rem;
            margin-bottom: 2px;
        }

        .info-value {
            color: #2d3748;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .alert {
            border: none;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-bottom: 12px;
            padding: 8px 12px;
        }

        .btn {
            border-radius: 4px;
            font-weight: 500;
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
        }

        .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
        }

        h2 {
            color: #2d3748;
            font-weight: 600;
            font-size: 1.25rem;
            margin: 0;
        }

        h4 {
            color: #2d3748;
            font-weight: 600;
            font-size: 1rem;
            margin: 0;
        }

        .text-muted {
            color: #6c757d !important;
            font-size: 0.8rem;
        }

        .sidebar-brand {
            padding: 15px 10px;
        }

        .sidebar-brand h3 {
            font-size: 1rem;
        }

        .sidebar-brand small {
            font-size: 0.7rem;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            color: #dee2e6;
        }

        .section-header {
            border-left: 4px solid var(--accent);
            padding-left: 12px;
            margin-bottom: 16px;
        }

        .table th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            font-size: 0.8rem;
            border-bottom: 1px solid #dee2e6;
            padding: 8px 12px;
            white-space: nowrap;
        }

        .table td {
            padding: 8px 12px;
            vertical-align: middle;
            border-color: #dee2e6;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .cost-highlight {
            font-weight: 600;
            color: #276749;
        }

        html, body {
            overflow-x: hidden;
            width: 100%;
            max-width: 100%;
        }
    </style>
</head>
<body class="admin-body">

<div class="container-fluid">
    <div class="row">
        
        <?php include 'sidebar.php'; ?>

        <!-- Main -->
        <div class="admin-main">
       
            <div class="admin-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h2>Yearly Budget Details</h2>
                        <p class="text-muted mb-0">
                            Yearly maintenance budget for asset tracking. 
                            <strong>Amount Spent</strong> is automatically calculated from all maintenance task costs.
                        </p>
                    </div>
                    <div class="col-auto">
                        <a href="manage_budgets.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Budgets
                        </a>
                    </div>
                </div>
            </div>

            <div class="container-fluid p-3">
             
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="budget-card">
                            <div class="budget-period">YEARLY BUDGET</div>
                            <div class="budget-amount text-primary">৳<?php echo number_format($budget['Amount_Allocation'], 2); ?></div>
                            <div class="budget-label">Total Yearly Allocation</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="budget-card">
                            <div class="budget-period">AUTO-CALCULATED</div>
                            <div class="budget-amount text-success">৳<?php echo number_format($actual_spent, 2); ?></div>
                            <div class="budget-label">Amount Spent (All Tasks)</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="budget-card">
                            <div class="budget-period">REMAINING</div>
                            <div class="budget-amount text-info">৳<?php echo number_format($budget['Remaining_Amount'], 2); ?></div>
                            <div class="budget-label">Remaining Budget</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="budget-card">
                            <div class="budget-period">UTILIZATION</div>
                            <div class="budget-amount text-warning"><?php echo number_format($actual_spending_percentage, 1); ?>%</div>
                            <div class="budget-label">Budget Used</div>
                        </div>
                    </div>
                </div>

                <!-- Info Alert -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Budget Tracking Information:</strong> 
                    The <strong>Amount Spent (৳<?php echo number_format($actual_spent, 2); ?>)</strong> is automatically calculated 
                    and updated by summing up all resource and Employee Costs from maintenance tasks below. 
                    This represents the total maintenance expenditure against your yearly budget.
                </div>

                <!-- Spending -->
                <div class="content-section">
                    <h4 class="mb-3">
                        <i class="fas fa-chart-line me-2 text-primary"></i>
                        Yearly Budget Utilization
                    </h4>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="fw-bold">Budget Utilization Progress</span>
                            <span class="fw-bold"><?php echo number_format($actual_spending_percentage, 1); ?>%</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar <?php echo $progress_class; ?>" 
                                 role="progressbar" 
                                 style="width: <?php echo min($actual_spending_percentage, 100); ?>%"
                                 aria-valuenow="<?php echo $actual_spending_percentage; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                            </div>
                        </div>
                    </div>
                    <div class="text-center">
                        <span class="status-badge <?php echo $status_class; ?>">
                            <i class="fas fa-chart-line me-1"></i><?php echo $status_text; ?>
                        </span>
                    </div>
                </div>

                <!-- Budget Info -->
                <div class="content-section">
                    <h4 class="mb-3">
                        <i class="fas fa-info-circle me-2 text-info"></i>
                        Budget Information
                    </h4>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="info-card">
                                <div class="info-label">Budget ID</div>
                                <div class="info-value">#<?php echo $budget['Budget_ID']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="info-card">
                                <div class="info-label">Budget Status</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php if($budget['Asset_ID']): ?>
                            <div class="col-md-6 mb-3">
                                <div class="info-card">
                                    <div class="info-label">Asset</div>
                                    <div class="info-value">
                                        <strong><?php echo htmlspecialchars($budget['Asset_Name']); ?></strong>
                                        <br><span class="text-muted"><?php echo htmlspecialchars($budget['Asset_Type']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="info-card">
                                    <div class="info-label">Asset Location</div>
                                    <div class="info-value"><?php echo htmlspecialchars($budget['Location']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Related Maintenance Tasks -->
                <?php if($budget['Asset_ID'] && count($maintenance_tasks) > 0): ?>
                <div class="content-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">
                            <i class="fas fa-tools me-2 text-warning"></i>
                            Maintenance Tasks Contributing to Budget
                        </h4>
                        <div class="text-end">
                            <small class="text-muted d-block">Total from all tasks below:</small>
                            <small class="text-muted d-block fw-bold">৳<?php echo number_format($actual_spent, 2); ?></small>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Task ID</th>
                                    <th>Task Type</th>
                                    <th>Resource Cost</th>
                                    <th>Employee Cost</th>
                                    <th>Total Cost</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($maintenance_tasks as $task): ?>
                                    <tr>
                                        <td><strong>#<?php echo $task['Maintenance_ID']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($task['Task_type']); ?></td>
                                        <td>৳<?php echo number_format($task['Resource_Cost'], 2); ?></td>
                                        <td>৳<?php echo number_format($task['Worker_Cost'], 2); ?></td>
                                        <td class="fw-bold cost-highlight">৳<?php echo number_format($task['Total_Cost'], 2); ?></td>
                                        <td>
                                            <span class="status-badge 
                                                <?php 
                                                switch($task['Status']) {
                                                    case 'Completed': echo 'badge-good'; break;
                                                    case 'In Progress': echo 'badge-info'; break;
                                                    case 'Pending': echo 'badge-warning'; break;
                                                    default: echo 'badge-danger';
                                                }
                                                ?>">
                                                <?php echo htmlspecialchars($task['Status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view_maintenance.php?id=<?php echo $task['Maintenance_ID']; ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-eye me-1"></i>View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-active">
                                    <td colspan="4" class="text-end fw-bold">Total Amount Spent (stored in database):</td>
                                    <td class="fw-bold text-success">৳<?php echo number_format($actual_spent, 2); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php elseif($budget['Asset_ID']): ?>
                <div class="content-section">
                    <div class="empty-state">
                        <i class="fas fa-tools"></i>
                        <h5>No Maintenance Tasks</h5>
                        <p class="text-muted">No maintenance tasks found for this asset. Amount Spent will be ৳0.00 until tasks are created.</p>
                    </div>
                </div>
                <?php endif; ?>

            
                <div class="content-section">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="edit_budget.php?id=<?php echo $budget['Budget_ID']; ?>" class="btn btn-warning">
                            <i class="fas fa-edit me-1"></i>Edit Budget Allocation
                        </a>
                        <?php if($budget['Asset_ID']): ?>
                            <a href="view_asset.php?id=<?php echo $budget['Asset_ID']; ?>" class="btn btn-info">
                                <i class="fas fa-building me-1"></i>View Asset Details
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>