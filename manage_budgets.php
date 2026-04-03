<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

// total budget allocation and spending
$budget_summary_query = "
    SELECT 
        COALESCE(SUM(b.Amount_Allocation), 0) as Total_Allocation,
        COALESCE(SUM(b.Amount_Spent), 0) as Total_Spent,
        COALESCE(SUM(b.Amount_Allocation - b.Amount_Spent), 0) as Total_Remaining,
        COUNT(b.Budget_ID) as Total_Budgets
    FROM Budgets b
";
$budget_summary_result = $conn->query($budget_summary_query);
$budget_summary = $budget_summary_result->fetch_assoc();

//  all budgets with asset info
$budgets_query = "
    SELECT 
        b.*,
        a.Asset_Name,
        a.Asset_Type,
        a.Location,
        (b.Amount_Allocation - b.Amount_Spent) as Remaining_Amount,
        CASE 
            WHEN b.Amount_Spent = 0 THEN 0
            ELSE (b.Amount_Spent / b.Amount_Allocation) * 100 
        END as Spending_Percentage
    FROM Budgets b
    LEFT JOIN Assets a ON b.Asset_ID = a.Asset_ID
    ORDER BY b.Budget_ID DESC
";
$budgets_result = $conn->query($budgets_query);

//assets without budgets
$assets_without_budgets_query = "
    SELECT a.* 
    FROM Assets a 
    LEFT JOIN Budgets b ON a.Asset_ID = b.Asset_ID 
    WHERE b.Budget_ID IS NULL 
    ORDER BY a.Asset_Name
";
$assets_without_budgets_result = $conn->query($assets_without_budgets_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Budget - Smart DNCC</title>
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
        .badge-secondary { background: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6; }

        .action-btn {
            padding: 4px 8px;
            margin: 1px;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            transition: all 0.2s;
            font-size: 0.75rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
        }

        .btn-view { background: #ebf8ff; color: #3182ce; border-color: #bee3f8; }
        .btn-edit { background: #fffaf0; color: #d69e2e; border-color: #faf089; }
        .btn-add { background: #f0fff4; color: #38a169; border-color: #9ae6b4; }
        .btn-asset { background: #f8f9fa; color: #6c757d; border-color: #dee2e6; }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
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

        .table-hover tbody tr:hover {
            background: #f8f9fa;
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

        .budget-card {
            background: white;
            border-radius: 8px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
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
            height: 6px;
            margin-top: 8px;
        }

        .spending-status {
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 4px;
        }

        .info-card {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
            border-left: 4px solid var(--info);
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

        html, body {
            overflow-x: hidden;
            width: 100%;
            max-width: 100%;
        }

        .admin-main {
            overflow-x: hidden;
            width: calc(100% - 220px);
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
                        <h2>Yearly Budget Management For All Assets:</h2>
                        <p class="text-muted mb-0">Track and manage yearly maintenance budgets across all assets</p>
                    </div>
                    
                </div>
            </div>

            <div class="container-fluid p-3">
             
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Budget Information:</strong> 
                    This system manages <strong>yearly maintenance budgets</strong>. 
                    <strong>Amount Spent</strong> is automatically calculated from maintenance tasks and cannot be manually edited.
                </div>

             
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="budget-card text-center">
                            <div class="budget-amount text-primary">৳<?php echo number_format($budget_summary['Total_Allocation'], 2); ?></div>
                            <div class="budget-label">Total Yearly Allocation</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="budget-card text-center">
                            <div class="budget-amount text-success">৳<?php echo number_format($budget_summary['Total_Spent'], 2); ?></div>
                            <div class="budget-label">Total Auto-Calculated Spending</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="budget-card text-center">
                            <div class="budget-amount text-info">৳<?php echo number_format($budget_summary['Total_Remaining'], 2); ?></div>
                            <div class="budget-label">Total Remaining Budget</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="budget-card text-center">
                            <div class="budget-amount text-warning"><?php echo $budget_summary['Total_Budgets']; ?></div>
                            <div class="budget-label">Assets with Budgets</div>
                        </div>
                    </div>
                </div>

                <!-- Assets with Budgets -->
                <div class="content-section">
                    <div class="section-header">
                        <h4 class="mb-1">
                            <i class="fas fa-money-bill-wave me-2 text-success"></i>
                            Assets with Yearly Budgets
                        </h4>
                        <p class="text-muted mb-0">Assets that have yearly maintenance budget allocations</p>
                    </div>

                    <?php if($budgets_result && $budgets_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead>
                                    <tr>
                                        <th>Budget ID</th>
                                        <th>Asset</th>
                                        <th>Yearly Allocation</th>
                                        <th>Auto-Calculated Spent</th>
                                        <th>Remaining</th>
                                        <th>Spending %</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($budget = $budgets_result->fetch_assoc()): ?>
                                        <?php 
                                        $spending_percentage = $budget['Spending_Percentage'];
                                        if($spending_percentage <= 70) {
                                            $status_class = 'badge-good';
                                            $status_text = 'Under Budget';
                                            $progress_class = 'bg-success';
                                        } elseif($spending_percentage <= 90) {
                                            $status_class = 'badge-warning';
                                            $status_text = 'Approaching Limit';
                                            $progress_class = 'bg-warning';
                                        } else {
                                            $status_class = 'badge-danger';
                                            $status_text = 'Over Budget';
                                            $progress_class = 'bg-danger';
                                        }
                                        ?>
                                        <tr>
                                            <td><strong>#<?php echo $budget['Budget_ID']; ?></strong></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($budget['Asset_Name']); ?></strong>
                                                <?php if($budget['Asset_Type']): ?>
                                                    <br><span class="text-muted"><?php echo htmlspecialchars($budget['Asset_Type']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold">৳<?php echo number_format($budget['Amount_Allocation'], 2); ?></td>
                                            <td class="text-success">৳<?php echo number_format($budget['Amount_Spent'], 2); ?></td>
                                            <td class="text-info">৳<?php echo number_format($budget['Remaining_Amount'], 2); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="width: 80px;">
                                                        <div class="progress-bar <?php echo $progress_class; ?>" 
                                                             role="progressbar" 
                                                             style="width: <?php echo min($spending_percentage, 100); ?>%"
                                                             aria-valuenow="<?php echo $spending_percentage; ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                    <span class="fw-bold"><?php echo number_format($spending_percentage, 1); ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <a href="view_budget.php?id=<?php echo $budget['Budget_ID']; ?>" class="action-btn btn-view" title="View Budget Details">
                                                        <i class="fas fa-eye me-1"></i>View
                                                    </a>
                                                    <a href="edit_budget.php?id=<?php echo $budget['Budget_ID']; ?>" class="action-btn btn-edit" title="Edit Budget Allocation">
                                                        <i class="fas fa-edit me-1"></i>Edit
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
                            <i class="fas fa-money-bill-wave"></i>
                            <h5>No Budgets Found</h5>
                            <p class="text-muted">No yearly budget allocations have been created yet.</p>
                            <a href="add_budget.php" class="btn btn-primary">
                                <i class="fas fa-plus me-1"></i>Create First Budget
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Assets without Budgets -->
                <div class="content-section">
                    <div class="section-header">
                        <h4 class="mb-1">
                            <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
                            Assets Without Yearly Budgets
                        </h4>
                        <p class="text-muted mb-0">Assets that don't have yearly maintenance budget allocations</p>
                    </div>

                    <?php if($assets_without_budgets_result && $assets_without_budgets_result->num_rows > 0): ?>
                        <div class="info-card mb-3">
                            <div class="info-label">Information</div>
                            <div class="info-value">
                                <i class="fas fa-info-circle me-1 text-info"></i>
                                These assets don't have yearly budgets. Create budgets to track maintenance spending and budget utilization.
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead>
                                    <tr>
                                        <th>Asset ID</th>
                                        <th>Asset Name</th>
                                        <th>Type</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($asset = $assets_without_budgets_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong>#<?php echo $asset['Asset_ID']; ?></strong></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($asset['Asset_Name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($asset['Asset_Type']); ?></td>
                                            <td><?php echo htmlspecialchars($asset['Location']); ?></td>
                                            <td>
                                                <span class="status-badge badge-secondary">
                                                    No Budget
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <a href="add_budget.php?asset_id=<?php echo $asset['Asset_ID']; ?>" class="action-btn btn-add" title="Add Budget for this Asset">
                                                        <i class="fas fa-plus me-1"></i>Add Budget
                                                    </a>
                                                    <a href="view_asset.php?id=<?php echo $asset['Asset_ID']; ?>" class="action-btn btn-asset" title="View Asset Details">
                                                        <i class="fas fa-building me-1"></i>View Asset
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
                            <i class="fas fa-check-circle text-success"></i>
                            <h5>All Assets Have Budgets</h5>
                            <p class="text-muted">Great! All assets have yearly budget allocations.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>