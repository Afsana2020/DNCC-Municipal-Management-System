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
        header("Location: manage_assets.php");
    } else {
        header("Location: manage_assets.php");
    }
    exit();
}

$asset_id = $_GET['id'];

// Handle mark as checked (admin only)
if($is_admin && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['asset_condition'])) {
    $asset_condition = $_POST['asset_condition'];
    $today = date('Y-m-d');
    
    if(in_array($asset_condition, ['Needs Maintenance', 'New Construction', 'Under Maintenance'])) {
        $checking_date = NULL;
    } else {
        $checking_date = $today;
    }
    
    $update_query = "UPDATE Assets SET Asset_Condition = ?, Checking_Date = ? WHERE Asset_ID = ?";
    $stmt = $conn->prepare($update_query);
    
    if($stmt) {
        $stmt->bind_param("ssi", $asset_condition, $checking_date, $asset_id);
        
        if($stmt->execute()) {
            header("Location: view_asset.php?id=$asset_id&success=1");
            exit();
        } else {
            $error_msg = "Error updating asset: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error_msg = "Failed to prepare statement: " . $conn->error;
    }
}

// asset details 
if($is_admin) {
    // Admin view - full 
    $asset_query = "SELECT *, 
                    DATE_ADD(Checking_Date, INTERVAL Maintenance_Interval DAY) as Next_Checking_Date,
                    DATEDIFF(DATE_ADD(Checking_Date, INTERVAL Maintenance_Interval DAY), CURDATE()) as Days_Until_Check,
                    DATEDIFF(CURDATE(), DATE_ADD(Checking_Date, INTERVAL Maintenance_Interval DAY)) as Days_Overdue,
                    CASE 
                        WHEN Checking_Date IS NULL THEN 'Not Checked'
                        WHEN DATE_ADD(Checking_Date, INTERVAL Maintenance_Interval DAY) < CURDATE() THEN 'Overdue'
                        WHEN DATEDIFF(DATE_ADD(Checking_Date, INTERVAL Maintenance_Interval DAY), CURDATE()) <= 1 THEN 'Due Soon'
                        ELSE 'Scheduled'
                    END as Check_Status,
                    CASE 
                        WHEN DATE_ADD(Checking_Date, INTERVAL Maintenance_Interval DAY) <= CURDATE() THEN 'Due For Check'
                        ELSE 'Not Due'
                    END as Check_Button_Status
                    FROM Assets 
                    WHERE Asset_ID = ?";
} else {
    // Citizen view - fixed
    $asset_query = "SELECT 
                    Asset_ID,
                    Asset_Type,
                    Asset_Name,
                    Asset_Condition,
                    Location,
                    Installation_Date,
                    Last_Maintenance_Date
                    FROM Assets 
                    WHERE Asset_ID = ?";
}

$stmt = $conn->prepare($asset_query);
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$asset_result = $stmt->get_result();

if($asset_result->num_rows === 0) {
    if($is_admin) {
        header("Location: manage_assets.php");
    } else {
        header("Location: manage_assets.php");
    }
    exit();
}

$asset = $asset_result->fetch_assoc();
$stmt->close();

// maintenance history with project info
if($is_admin) {
    // Admin view - full with project
    $maintenance_query = "SELECT m.Maintenance_ID, m.Task_type, m.Cost, m.Start_Date, m.Status,
                                 p.Project_ID, p.Project_Type
                         FROM Maintenance m 
                         LEFT JOIN Projects p ON m.Project_ID = p.Project_ID
                         WHERE m.Asset_ID = ? 
                         ORDER BY m.Start_Date DESC";
} else {
    // Citizen view - basic
    $maintenance_query = "SELECT 
                         Maintenance_ID, 
                         Task_type, 
                         Start_Date, 
                         Status
                         FROM Maintenance 
                         WHERE Asset_ID = ? 
                         ORDER BY Start_Date DESC";
}

$stmt = $conn->prepare($maintenance_query);
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$maintenance_result = $stmt->get_result();
$maintenance_history = $maintenance_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_admin ? 'View Asset' : 'Public Asset Details'; ?> - Smart DNCC</title>
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

        .info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            height: 100%;
            border-left: 4px solid var(--accent);
        }

        .info-label {
            font-weight: 600;
            color: #64748b;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .info-value {
            color: #1a202c;
            font-size: 0.95rem;
            font-weight: 500;
        }

        /* Status badges - UNIFORM WIDTH */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            min-width: 100px;
            text-align: center;
        }

        .badge-good { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-fair { background: #fffaf0; color: #744210; border: 1px solid #fbd38d; }
        .badge-poor { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        .badge-new-construction { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .badge-needs-maintenance { background: #fffaf0; color: #744210; border: 1px solid #fbd38d; }
        .badge-under-maintenance { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; }
        
        /* Maintenance Status Badges */
        .badge-not-started { background: #e2e8f0; color: #2d3748; border: 1px solid #cbd5e0; }
        .badge-active { background: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
        .badge-paused { background: #feebc8; color: #744210; border: 1px solid #fbd38d; }
        .badge-completed { background: #e9d8fd; color: #6b46c1; border: 1px solid #d6bcfa; }
        .badge-cancelled { background: #fed7d7; color: #c53030; border: 1px solid #fc8181; }

        /* Check Status Badges */
        .check-status-overdue { background: #fed7d7; color: #c53030; border: 1px solid #fc8181; }
        .check-status-due-soon { background: #feebc8; color: #744210; border: 1px solid #fbd38d; }
        .check-status-scheduled { background: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
        .check-status-not-checked { background: #e2e8f0; color: #2d3748; border: 1px solid #cbd5e0; }

        /* Project Badge - SIMPLE ID ONLY */
        .project-badge {
            background: #ebf8ff;
            color: #2c5282;
            border: 1px solid #90cdf4;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            min-width: 70px;
            justify-content: center;
        }
        .project-badge:hover {
            background: #3182ce;
            color: white;
            border-color: #3182ce;
        }

        /* Project Type Badge - SMALL */
        .project-type {
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.6rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 4px;
        }
        .project-type-large { background: #ebf8ff; color: #2c5282; }
        .project-type-routine { background: #f0fff4; color: #276749; }
        .project-type-urgent { background: #fff5f5; color: #c53030; }

        /* Consistent button styling */
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
        }
        .btn-back:hover {
            background: #718096;
            color: white;
        }

        .action-btn {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid transparent;
            transition: all 0.2s;
            min-width: 60px;
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

        /* Admin quick actions */
        .quick-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .quick-action-btn {
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid transparent;
            transition: all 0.2s;
            min-width: 140px;
            justify-content: center;
        }

        .btn-edit-large {
            background: #fffaf0;
            color: #d69e2e;
            border: 2px solid #faf089;
        }
        .btn-edit-large:hover {
            background: #d69e2e;
            color: white;
            border-color: #d69e2e;
        }

        .btn-delete-large {
            background: #fff5f5;
            color: #e53e3e;
            border: 2px solid #fc8181;
        }
        .btn-delete-large:hover {
            background: #e53e3e;
            color: white;
            border-color: #e53e3e;
        }

        .btn-check-large {
            background: #f0fff4;
            color: #38a169;
            border: 2px solid #9ae6b4;
        }
        .btn-check-large:hover {
            background: #38a169;
            color: white;
            border-color: #38a169;
        }

        /* Table Styling - CLEAN */
        .maintenance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .maintenance-table th {
            background: #f8fafc;
            color: #4a5568;
            font-weight: 600;
            font-size: 0.7rem;
            padding: 12px 8px;
            text-align: center;
            border-bottom: 2px solid #e2e8f0;
        }

        .maintenance-table td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.8rem;
            text-align: center;
        }

        .maintenance-table tbody tr:hover {
            background: #fafbfc;
        }

        /* Fixed column widths */
        .maintenance-table th:nth-child(1), .maintenance-table td:nth-child(1) { width: 10%; } /* ID */
        .maintenance-table th:nth-child(2), .maintenance-table td:nth-child(2) { width: 15%; } /* Type */
        <?php if($is_admin): ?>
        .maintenance-table th:nth-child(3), .maintenance-table td:nth-child(3) { width: 15%; } /* Project */
        .maintenance-table th:nth-child(4), .maintenance-table td:nth-child(4) { width: 10%; } /* Cost */
        .maintenance-table th:nth-child(5), .maintenance-table td:nth-child(5) { width: 12%; } /* Date */
        .maintenance-table th:nth-child(6), .maintenance-table td:nth-child(6) { width: 12%; } /* Status */
        .maintenance-table th:nth-child(7), .maintenance-table td:nth-child(7) { width: 8%; }  /* Action */
        <?php else: ?>
        .maintenance-table th:nth-child(3), .maintenance-table td:nth-child(3) { width: 15%; } /* Date */
        .maintenance-table th:nth-child(4), .maintenance-table td:nth-child(4) { width: 15%; } /* Status */
        <?php endif; ?>

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #a0aec0;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
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

        .cost-value {
            font-weight: 600;
            color: #2d3748;
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
            
            .quick-actions {
                justify-content: flex-start;
            }
            
            .maintenance-table {
                font-size: 0.7rem;
            }
            
            .maintenance-table th, 
            .maintenance-table td {
                padding: 8px 4px;
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
                <h2><?php echo $is_admin ? 'Asset Details' : 'Public Asset Details'; ?></h2>
                <p><?php echo htmlspecialchars($asset['Asset_Name']); ?></p>
            </div>
            <a href="<?php echo $is_admin ? 'manage_assets.php' : 'manage_assets.php'; ?>" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <div class="content-area">
            
            <?php if(isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>Asset updated successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Asset Information -->
            <div class="content-section">
                <h4 class="mb-3">Asset Information</h4>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">ID</div>
                            <div class="info-value">#<?php echo $asset['Asset_ID']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Type</div>
                            <div class="info-value"><?php echo $asset['Asset_Type']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Condition</div>
                            <div class="info-value">
                                <?php 
                                $condition_class = '';
                                switch($asset['Asset_Condition']) {
                                    case 'Good': $condition_class = 'badge-good'; break;
                                    case 'Fair': $condition_class = 'badge-fair'; break;
                                    case 'Poor': $condition_class = 'badge-poor'; break;
                                    case 'New Construction': $condition_class = 'badge-new-construction'; break;
                                    case 'Needs Maintenance': $condition_class = 'badge-needs-maintenance'; break;
                                    case 'Under Maintenance': $condition_class = 'badge-under-maintenance'; break;
                                    default: $condition_class = 'badge-fair';
                                }
                                ?>
                                <span class="status-badge <?php echo $condition_class; ?>">
                                    <?php echo $asset['Asset_Condition']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Installed</div>
                            <div class="info-value"><?php echo date('d/m/Y', strtotime($asset['Installation_Date'])); ?></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="info-card">
                            <div class="info-label">Name</div>
                            <div class="info-value"><?php echo $asset['Asset_Name']; ?></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="info-card">
                            <div class="info-label">Location</div>
                            <div class="info-value"><?php echo $asset['Location']; ?></div>
                        </div>
                    </div>
                    
                    <?php if($is_admin): ?>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Cost</div>
                            <div class="info-value cost-value">৳<?php echo number_format($asset['Expenses']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Interval</div>
                            <div class="info-value"><?php echo $asset['Maintenance_Interval']; ?> days</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Last Check</div>
                            <div class="info-value"><?php echo $asset['Checking_Date'] ? date('d/m/Y', strtotime($asset['Checking_Date'])) : 'Not checked'; ?></div>
                        </div>
                    </div>
                    <?php if($asset['Checking_Date']): ?>
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-label">Next Check</div>
                            <div class="info-value">
                                <?php 
                                $next = date('d/m/Y', strtotime($asset['Next_Checking_Date']));
                                $days = $asset['Days_Until_Check'];
                                if($days < 0) {
                                    echo '<span class="text-danger">' . $next . ' (' . abs($days) . 'd overdue)</span>';
                                } elseif($days == 0) {
                                    echo '<span class="text-warning">' . $next . ' (Today)</span>';
                                } else {
                                    echo $next . ' (' . $days . 'd)';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="status-badge check-status-<?php echo strtolower(str_replace(' ', '-', $asset['Check_Status'])); ?>">
                                    <?php echo $asset['Check_Status']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="col-md-12">
                        <div class="info-card">
                            <div class="info-label">Last Maintenance</div>
                            <div class="info-value"><?php echo $asset['Last_Maintenance_Date'] ? date('d/m/Y', strtotime($asset['Last_Maintenance_Date'])) : 'None'; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Admin Quick Actions -->
            <?php if($is_admin): ?>
            <div class="content-section">
                <h4 class="mb-3">Quick Actions</h4>
                <div class="quick-actions">
                    <a href="edit_asset.php?id=<?php echo $asset['Asset_ID']; ?>" class="quick-action-btn btn-edit-large">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    
                    <?php if($asset['Check_Button_Status'] == 'Due For Check'): ?>
                        <button type="button" class="quick-action-btn btn-check-large" data-bs-toggle="modal" data-bs-target="#checkAssetModal">
                            <i class="fas fa-check-circle"></i>
                            Mark Checked
                        </button>
                    <?php endif; ?>
                    
                    <a href="manage_assets.php?delete_id=<?php echo $asset['Asset_ID']; ?>" class="quick-action-btn btn-delete-large" onclick="return confirm('Delete this asset?')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Maintenance History - CLEAN TABLE WITH IDs -->
            <div class="content-section">
                <h4 class="mb-3">Maintenance History</h4>
                
                <?php if(count($maintenance_history) > 0): ?>
                    <div class="table-responsive">
                        <table class="maintenance-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Task Type</th>
                                    <?php if($is_admin): ?>
                                    <th>Project</th>
                                    <th>Cost</th>
                                    <?php endif; ?>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <?php if($is_admin): ?>
                                    <th></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($maintenance_history as $maintenance): ?>
                                <tr>
                                    <td><strong>#<?php echo $maintenance['Maintenance_ID']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($maintenance['Task_type']); ?></td>
                                    
                                    <?php if($is_admin): ?>
                                    <td>
                                        <?php if(!empty($maintenance['Project_ID'])): ?>
                                            <a href="view_project.php?id=<?php echo $maintenance['Project_ID']; ?>" class="project-badge">
                                                #P<?php echo $maintenance['Project_ID']; ?>
                                                <?php if(!empty($maintenance['Project_Type'])): ?>
                                                    <span class="project-type project-type-<?php echo strtolower($maintenance['Project_Type']); ?>">
                                                        <?php echo $maintenance['Project_Type']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cost-value">৳<?php echo number_format($maintenance['Cost']); ?></td>
                                    <?php endif; ?>
                                    
                                    <td><?php echo date('d/m/Y', strtotime($maintenance['Start_Date'])); ?></td>
                                    <td>
                                        <?php 
                                        $status_class = '';
                                        switch($maintenance['Status']) {
                                            case 'Not Started': $status_class = 'badge-not-started'; break;
                                            case 'Active': $status_class = 'badge-active'; break;
                                            case 'Paused': $status_class = 'badge-paused'; break;
                                            case 'Completed': $status_class = 'badge-completed'; break;
                                            case 'Cancelled': $status_class = 'badge-cancelled'; break;
                                            default: $status_class = 'badge-not-started';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $maintenance['Status']; ?>
                                        </span>
                                    </td>
                                    
                                    <?php if($is_admin): ?>
                                    <td>
                                        <a href="view_maintenance.php?id=<?php echo $maintenance['Maintenance_ID']; ?>" class="action-btn btn-view">
                                            View
                                        </a>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tools"></i>
                        <p>No maintenance history</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Check Asset Modal (Admin only) -->
    <?php if($is_admin): ?>
    <div class="modal fade" id="checkAssetModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Condition</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Condition</label>
                            <select class="form-select" name="asset_condition" required>
                                <option value="Good" <?php echo $asset['Asset_Condition'] == 'Good' ? 'selected' : ''; ?>>Good</option>
                                <option value="Fair" <?php echo $asset['Asset_Condition'] == 'Fair' ? 'selected' : ''; ?>>Fair</option>
                                <option value="Poor" <?php echo $asset['Asset_Condition'] == 'Poor' ? 'selected' : ''; ?>>Poor</option>
                                <option value="Needs Maintenance" <?php echo $asset['Asset_Condition'] == 'Needs Maintenance' ? 'selected' : ''; ?>>Needs Maintenance</option>
                                <option value="New Construction" <?php echo $asset['Asset_Condition'] == 'New Construction' ? 'selected' : ''; ?>>New Construction</option>
                                <option value="Under Maintenance" <?php echo $asset['Asset_Condition'] == 'Under Maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>