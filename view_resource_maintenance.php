<?php
session_start();
include("connect.php");

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

// Get maintenance info with project/package context
if($is_admin) {
    // Admin view - full details with context
    $maintenance_query = "SELECT m.*, a.Asset_Name, a.Asset_Type, a.Location,
                                 pr.Project_ID, pr.Project_Name,
                                 p.Package_ID, p.Package_Name
                          FROM Maintenance m 
                          JOIN Assets a ON m.Asset_ID = a.Asset_ID 
                          LEFT JOIN Projects pr ON m.Project_ID = pr.Project_ID
                          LEFT JOIN Packages p ON m.Package_ID = p.Package_ID
                          WHERE m.Maintenance_ID = ?";
    
    $resources_query = "SELECT r.*, (r.Quantity * r.Unit_Cost) as Total_Cost 
                        FROM Resources r 
                        WHERE r.Maintenance_ID = ? 
                        ORDER BY r.Resource_ID DESC";
} else {
    // Citizen view - limited
    $maintenance_query = "SELECT m.*, a.Asset_Name, a.Asset_Type, a.Location,
                                 pr.Project_Name,
                                 p.Package_Name
                          FROM Citizen_Maintenance_View m 
                          JOIN Citizen_Asset_View a ON m.Asset_ID = a.Asset_ID 
                          LEFT JOIN Projects pr ON m.Project_ID = pr.Project_ID
                          LEFT JOIN Packages p ON m.Package_ID = p.Package_ID
                          WHERE m.Maintenance_ID = ?";
    
    $resources_query = "SELECT r.* 
                        FROM Citizen_Resource_View r 
                        WHERE r.Maintenance_ID = ? 
                        ORDER BY r.Resource_ID DESC";
}

// Get status
if($is_admin) {
    $status_query = "SELECT Status FROM Maintenance WHERE Maintenance_ID = ?";
} else {
    $status_query = "SELECT Status FROM Citizen_Maintenance_View WHERE Maintenance_ID = ?";
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
$is_task_completed_or_cancelled = in_array($maintenance_status, ['Completed', 'Cancelled']);
$stmt->close();

// Get maintenance task info
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

// Get resources
$stmt = $conn->prepare($resources_query);
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$resources_result = $stmt->get_result();
$resource_count = $resources_result->num_rows;
$stmt->close();

// Calculate totals
$total_resource_cost = 0;
$resources_data = [];
if($resource_count > 0) {
    $resources_result->data_seek(0);
    while($resource = $resources_result->fetch_assoc()) {
        $resources_data[] = $resource;
        if($is_admin) {
            $total_resource_cost += $resource['Total_Cost'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Resources - Smart DNCC</title>
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

        .admin-main, .citizen-main {
            margin-left: 220px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            background: #f8fafc;
        }

        .admin-header, .citizen-header {
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

        /* Hierarchy Banner */
        .hierarchy-banner {
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .hierarchy-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .hierarchy-path {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .hierarchy-link {
            display: inline-flex;
            align-items: center;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            gap: 8px;
            min-width: 100px;
            justify-content: center;
        }

        .hierarchy-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .badge-project { 
            background: #ebf8ff; 
            color: #2c5282; 
            border: 1px solid #90cdf4; 
        }
        .badge-project:hover { 
            background: #bee3f8; 
            color: #1a365d; 
        }
        
        .badge-package { 
            background: #faf5ff; 
            color: #6b46c1; 
            border: 1px solid #d6bcfa; 
        }
        .badge-package:hover { 
            background: #e9d8fd; 
            color: #553c9a; 
        }
        
        .badge-task { 
            background: #f0fff4; 
            color: #276749; 
            border: 1px solid #9ae6b4; 
        }
        .badge-task:hover { 
            background: #c6f6d5; 
            color: #22543d; 
        }
        
        .badge-current { 
            background: #e2e8f0; 
            color: #4a5568; 
            border: 1px solid #cbd5e0; 
            cursor: default;
        }

        .chevron {
            color: #a0aec0;
            font-size: 0.8rem;
            margin: 0 4px;
        }

        /* Info Cards */
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            min-height: 80px;
            border-left: 4px solid var(--accent);
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
        }

        /* Status badges - UNIFORM WIDTH */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 90px;
            text-align: center;
            display: inline-block;
        }

        .badge-pending { background: #feebc8; color: #744210; border: 1px solid #fbd38d; }
        .badge-progress { background: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
        .badge-completed { background: #e9d8fd; color: #6b46c1; border: 1px solid #d6bcfa; }
        .badge-cancelled { background: #fed7d7; color: #c53030; border: 1px solid #fc8181; }

        /* Resource Row */
        .resource-row {
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
        .resource-row:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
        }

        .resource-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            flex: 1;
        }

        .resource-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .resource-id-badge {
            background: #e2e8f0;
            color: #2d3748;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 50px;
            text-align: center;
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
            border-color: #bee3f8;
        }
        .btn-view:hover {
            background: #3182ce;
            color: white;
            border-color: #3182ce;
        }

        .btn-edit {
            background: #fffaf0;
            color: #d69e2e;
            border-color: #faf089;
        }
        .btn-edit:hover {
            background: #d69e2e;
            color: white;
            border-color: #d69e2e;
        }

        .btn-delete {
            background: #fff5f5;
            color: #e53e3e;
            border-color: #fc8181;
        }
        .btn-delete:hover {
            background: #e53e3e;
            color: white;
            border-color: #e53e3e;
        }

        .btn-success {
            background: var(--success);
            color: white;
            border: none;
            min-width: 100px;
            padding: 6px 16px;
        }
        .btn-success:hover {
            background: #2f855a;
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            min-width: 100px;
            padding: 6px 16px;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            color: white;
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

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-header h4 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
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

        .total-cost-card {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .cost-highlight {
            font-weight: 700;
            color: #276749;
            font-size: 1.8rem;
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

        .status-info {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #856404;
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
            .admin-main, .citizen-main {
                margin-left: 0;
            }
            
            .admin-header, .citizen-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .hierarchy-banner {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .resource-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .resource-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <?php if($is_admin): ?>
        <?php include 'sidebar.php'; ?>
    <?php else: ?>
        <?php include 'citizen_sidebar.php'; ?>
    <?php endif; ?>

    <div class="<?php echo $is_admin ? 'admin-main' : 'citizen-main'; ?>">
        <div class="<?php echo $is_admin ? 'admin-header' : 'citizen-header'; ?>">
            <div class="header-title">
                <h2>Task Resources</h2>
                <p>Resources for Task #<?php echo $maintenance_id; ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Task
                </a>
                <?php if($is_admin && !$is_task_completed_or_cancelled): ?>
                    <a href="add_resource.php?maintenance_id=<?php echo $maintenance_id; ?>" class="btn btn-success">
                        <i class="fas fa-plus-circle me-1"></i>Add Resource
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-area">

            <!-- Hierarchy Banner -->
           <!-- Hierarchy Banner -->
<div class="hierarchy-banner">
    <div class="hierarchy-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
        <i class="fas fa-tasks"></i>
    </div>
    <div class="hierarchy-path">
        <?php if(!empty($maintenance['Project_ID'])): ?>
            <a href="view_project.php?id=<?php echo $maintenance['Project_ID']; ?>" class="hierarchy-link badge-project">
                <i class="fas fa-folder-open"></i>
                Project-<?php echo $maintenance['Project_ID']; ?>
            </a>
            <span class="chevron"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
        
        <?php if(!empty($maintenance['Package_ID'])): ?>
            <a href="view_package.php?id=<?php echo $maintenance['Package_ID']; ?>" class="hierarchy-link badge-package">
                <i class="fas fa-cubes"></i>
                Package-<?php echo $maintenance['Package_ID']; ?>
            </a>
            <span class="chevron"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
        
        <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="hierarchy-link badge-task">
            <i class="fas fa-tasks"></i>
            Segment-<?php echo $maintenance_id; ?>
        </a>
        
        <span class="chevron"><i class="fas fa-chevron-right"></i></span>
        
        <span class="hierarchy-link badge-current">
            <i class="fas fa-boxes"></i>
            Resources
        </span>
    </div>
</div>

            <!-- Task Summary Card -->
            <div class="content-section">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Task ID</div>
                            <div class="info-value">#<?php echo $maintenance_id; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Task Type</div>
                            <div class="info-value"><?php echo htmlspecialchars($maintenance['Task_type']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Asset</div>
                            <div class="info-value"><?php echo htmlspecialchars($maintenance['Asset_Name']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="status-badge 
                                    <?php 
                                    if($maintenance['Status'] == 'Completed') echo 'badge-completed';
                                    elseif($maintenance['Status'] == 'Cancelled') echo 'badge-cancelled';
                                    elseif($maintenance['Status'] == 'In Progress') echo 'badge-progress';
                                    else echo 'badge-pending';
                                    ?>">
                                    <?php echo $maintenance['Status']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Completed/Cancelled Warning -->
            <?php if($is_task_completed_or_cancelled): ?>
            <div class="status-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>This task is <?php echo $maintenance['Status']; ?>.</strong> 
                <?php if($is_admin): ?>
                    You cannot add or modify resources for <?php echo strtolower($maintenance['Status']); ?> tasks.
                <?php else: ?>
                    This task is <?php echo strtolower($maintenance['Status']); ?>. Resource information is view-only.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Total Cost Summary (Admin only) -->
            <?php if($is_admin && $resource_count > 0): ?>
            <div class="total-cost-card">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-1">Total Resource Cost</h5>
                        <p class="text-muted mb-0">Sum of all resources allocated to this task</p>
                    </div>
                    <div class="col-auto">
                        <div class="cost-highlight">৳<?php echo number_format($total_resource_cost, 2); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Resources List -->
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-boxes me-2" style="color: #805ad5;"></i>
                        Allocated Resources (<?php echo $resource_count; ?>)
                    </h4>
                </div>

                <?php if($resource_count > 0): ?>
                    <?php foreach($resources_data as $resource): ?>
                    <div class="resource-row">
                        <div class="resource-info">
                            <span class="resource-id-badge">#<?php echo $resource['Resource_ID']; ?></span>
                            <span><i class="fas fa-cube me-1" style="color: #805ad5;"></i> <?php echo htmlspecialchars($resource['Resource_Type']); ?></span>
                            <span class="badge bg-light text-dark">
                                <i class="fas fa-hashtag me-1"></i> Qty: <?php echo number_format($resource['Quantity']); ?>
                            </span>
                            
                            <?php if($is_admin): ?>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-money-bill me-1"></i> ৳<?php echo number_format($resource['Unit_Cost'], 2); ?>/u
                                </span>
                                <span class="badge bg-success text-white">
                                    <i class="fas fa-calculator me-1"></i> ৳<?php echo number_format($resource['Total_Cost'], 2); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if($is_admin && !$is_task_completed_or_cancelled): ?>
                        <div class="resource-actions">
                            <a href="view_resource.php?id=<?php echo $resource['Resource_ID']; ?>" class="action-btn btn-view" title="View Resource">
                                <i class="fas fa-eye me-1"></i> View
                            </a>
                            <a href="edit_resource.php?id=<?php echo $resource['Resource_ID']; ?>&maintenance_id=<?php echo $maintenance_id; ?>" class="action-btn btn-edit" title="Edit Resource">
                                <i class="fas fa-edit me-1"></i> Edit
                            </a>
                            <a href="delete_resource.php?id=<?php echo $resource['Resource_ID']; ?>&maintenance_id=<?php echo $maintenance_id; ?>" 
                               class="action-btn btn-delete" 
                               onclick="return confirm('Remove this resource from the task?')"
                               title="Remove Resource">
                                <i class="fas fa-trash me-1"></i> Remove
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-boxes"></i>
                        <h5>No Resources Allocated</h5>
                        <p class="text-muted">No resources are currently allocated to this maintenance task.</p>
                        <?php if($is_admin && !$is_task_completed_or_cancelled): ?>
                            <a href="add_resource.php?maintenance_id=<?php echo $maintenance_id; ?>" class="btn btn-success">
                                <i class="fas fa-plus-circle me-1"></i>Add First Resource
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>