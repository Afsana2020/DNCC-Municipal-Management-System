<?php
session_start();
include("connect.php");

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
        header("Location: manage_maintenance.php");
    }
    exit();
}

$maintenance_id = $_GET['id'];

// Get maintenance info with project/package context
if($is_admin) {
    // Admin - all details with project/package context
    $maintenance_query = "SELECT 
                        m.Maintenance_ID,
                        m.task_name,
                        m.Task_type,
                        m.Cost,
                        m.Start_Date,
                        m.End_Date,
                        m.Description,
                        m.Status,
                        a.Asset_ID,
                        a.Asset_Name,
                        a.Asset_Type,
                        a.Location,
                        a.Asset_Condition,
                        a.Installation_Date,
                        a.Expenses as Asset_Cost,
                        DATEDIFF(CURDATE(), m.Start_Date) as Days_Active,
                        pr.Project_ID,
                        pr.Project_Name,
                        pr.Project_Type,
                        p.Package_ID,
                        p.Package_Name
                        FROM maintenance m
                        JOIN assets a ON m.Asset_ID = a.Asset_ID
                        LEFT JOIN projects pr ON m.Project_ID = pr.Project_ID
                        LEFT JOIN packages p ON m.Package_ID = p.Package_ID
                        WHERE m.Maintenance_ID = ?";
} else {
    // Citizen - using views with project/package context
    $maintenance_query = "SELECT 
                        m.Maintenance_ID,
                        m.task_name,
                        m.Task_type,
                        m.Start_Date,
                        m.End_Date,
                        m.Description,
                        m.Status,
                        m.Asset_ID,
                        a.Asset_Name,
                        a.Location,
                        a.Asset_Type,
                        a.Asset_Condition,
                        a.Installation_Date,
                        DATEDIFF(CURDATE(), m.Start_Date) as Days_Active,
                        pr.Project_ID,
                        pr.Project_Name,
                        p.Package_ID,
                        p.Package_Name
                        FROM maintenance m
                        JOIN assets a ON m.Asset_ID = a.Asset_ID
                        LEFT JOIN projects pr ON m.Project_ID = pr.Project_ID
                        LEFT JOIN packages p ON m.Package_ID = p.Package_ID
                        WHERE m.Maintenance_ID = ?";
}

$stmt = $conn->prepare($maintenance_query);
if(!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$maintenance_result = $stmt->get_result();

if($maintenance_result->num_rows === 0) {
    if($is_admin) {
        header("Location: manage_maintenance.php");
    } else {
        header("Location: manage_maintenance.php");
    }
    exit();
}

$maintenance = $maintenance_result->fetch_assoc();
$stmt->close();

// Status change (admin)
if($is_admin && isset($_POST['update_status'])) {
    $new_status = $_POST['update_status'];
    
    $update_query = "UPDATE maintenance SET Status = ? WHERE Maintenance_ID = ?";
    $stmt = $conn->prepare($update_query);
    if($stmt) {
        $stmt->bind_param("si", $new_status, $maintenance_id);
        
        if($stmt->execute()) {
            if($new_status == 'Completed') {
                $update_asset_query = "UPDATE assets SET Asset_Condition = 'Good', Checking_Date = CURDATE() WHERE Asset_ID = ?";
                $stmt2 = $conn->prepare($update_asset_query);
                if($stmt2) {
                    $stmt2->bind_param("i", $maintenance['Asset_ID']);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }
            // cancel : back to original condition
            elseif($new_status == 'Cancelled') {
                $original_condition = ($maintenance['Task_type'] == 'Construction') ? 'New Construction' : 'Needs Maintenance';
                $update_asset_query = "UPDATE assets SET Asset_Condition = ? WHERE Asset_ID = ?";
                $stmt2 = $conn->prepare($update_asset_query);
                if($stmt2) {
                    $stmt2->bind_param("si", $original_condition, $maintenance['Asset_ID']);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }
            
            header("Location: view_maintenance.php?id=$maintenance_id&success=1");
            exit();
        } else {
            $error_msg = "Error updating maintenance status: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Maintenance - Smart DNCC</title>
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
            cursor: default;
        }

        .chevron {
            color: #a0aec0;
            font-size: 0.8rem;
            margin: 0 4px;
        }

        /* Status badges - UNIFORM WIDTH */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
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

        /* Task type badges - Updated for new enum values */
        .task-type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            min-width: 100px;
            text-align: center;
        }
        .task-type-construction { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .task-type-repair { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        .task-type-maintenance { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .task-type-restoration { background: #fef5e7; color: #ad6b35; border: 1px solid #ffe6b3; }

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

        .description-box {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            line-height: 1.6;
        }

        /* Status section - FULL WIDTH */
        .status-full {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            width: 100%;
        }

        .status-header {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.9rem;
            margin-bottom: 12px;
        }

        .status-controls {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }

        .status-box {
            background: #edf2f7;
            color: #4a5568;
            padding: 16px 20px;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }

        .permanent-status {
            background: #edf2f7;
            color: #4a5568;
            padding: 16px 20px;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }

        /* Dropdown styling */
        .status-dropdown {
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid #cbd5e0;
            background: white;
            font-size: 0.9rem;
            min-width: 200px;
            cursor: pointer;
            flex: 1;
        }

        .status-dropdown:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .update-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 120px;
        }

        .update-btn:hover {
            background: #2c5282;
        }

        .update-btn:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
        }

        /* Original detail links */
        .detail-link {
            width: 100%;
            background-color: #f8f9fa;
            color: #212529;
            font-size: 1.1rem;
            font-weight: 500;
            transition: all 0.2s ease-in-out;
        }

        .detail-link:hover {
            background-color: #e9ecef;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        /* Consistent button styling */
        .action-btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid transparent;
            transition: all 0.2s;
            min-width: 120px;
            justify-content: center;
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
        
        .btn-edit-disabled {
            background: #f1f5f9;
            color: #94a3b8;
            border: 1px solid #cbd5e0;
            cursor: not-allowed;
            opacity: 0.6;
            pointer-events: none;
        }

        .btn-back {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 8px 16px;
            border-radius: 8px;
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

        h2, h4 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.35rem; }
        h4 { font-size: 1.1rem; }

        .text-muted {
            color: #718096 !important;
        }

        .section-header {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 20px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }

        /* 4 Rows x 2 Columns Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
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
            
            .status-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .status-dropdown {
                width: 100%;
            }
            
            .update-btn {
                width: 100%;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 16px;
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
            <div class="header-title" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h2>Maintenance Task Details</h2>
                    <p>Task #<?php echo $maintenance['Maintenance_ID']; ?></p>
                </div>
                <div class="d-flex gap-2">
                    <?php if($is_admin): ?>
                    <a href="edit_maintenance.php?id=<?php echo $maintenance['Maintenance_ID']; ?>" class="btn btn-warning">
                        <i class="fas fa-edit me-1"></i>Edit Task
                    </a>
                    <?php endif; ?>
                    <a href="manage_maintenance.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>
        </div>

        <div class="content-area">
            
            <!-- Hierarchy Banner with Clickable Links -->
            <div class="hierarchy-banner">
                <div class="hierarchy-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                    <i class="fas fa-sitemap"></i>
                </div>
                <div class="hierarchy-path">
                    <?php if(!empty($maintenance['Project_ID'])): ?>
                        <a href="view_project.php?id=<?php echo $maintenance['Project_ID']; ?>" class="hierarchy-link badge-project">
                            <i class="fas fa-folder-open"></i>
                            Project #<?php echo $maintenance['Project_ID']; ?>
                        </a>
                        <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                    
                    <?php if(!empty($maintenance['Package_ID'])): ?>
                        <a href="view_package.php?id=<?php echo $maintenance['Package_ID']; ?>" class="hierarchy-link badge-package">
                            <i class="fas fa-cubes"></i>
                            Package #<?php echo $maintenance['Package_ID']; ?>
                        </a>
                        <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                    
                    <span class="hierarchy-link badge-task">
                        <i class="fas fa-tasks"></i>
                        Task #<?php echo $maintenance['Maintenance_ID']; ?>
                    </span>
                </div>
            </div>

            <?php if(isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>Maintenance status updated successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Task Information Section - 4 Rows x 2 Columns -->
            <div class="content-section">
                <div class="section-header">
                    <i class="fas fa-tasks me-2"></i>Task Information
                </div>
                <div class="info-grid">
                    <!-- Row 1, Col 1 -->
                    <div class="info-card">
                        <div class="info-label">Task ID</div>
                        <div class="info-value">#<?php echo $maintenance['Maintenance_ID']; ?></div>
                    </div>
                    
                    <!-- Row 1, Col 2 -->
                    <div class="info-card">
                        <div class="info-label">Task Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($maintenance['task_name']); ?></div>
                    </div>
                    
                    <!-- Row 2, Col 1 -->
                    <div class="info-card">
                        <div class="info-label">Task Category</div>
                        <div class="info-value">
                            <span class="task-type-badge <?php 
                                switch($maintenance['Task_type']) {
                                    case 'Construction': echo 'task-type-construction'; break;
                                    case 'Repair': echo 'task-type-repair'; break;
                                    case 'Maintenance': echo 'task-type-maintenance'; break;
                                    case 'Restoration': echo 'task-type-restoration'; break;
                                    default: echo 'task-type-maintenance';
                                }
                            ?>">
                                <?php echo $maintenance['Task_type']; ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Row 2, Col 2 -->
                    <div class="info-card">
                        <div class="info-label">Current Status</div>
                        <div class="info-value">
                            <span class="status-badge <?php 
                                switch($maintenance['Status']) {
                                    case 'Not Started': echo 'badge-notstarted'; break;
                                    case 'Active': echo 'badge-active'; break;
                                    case 'Paused': echo 'badge-paused'; break;
                                    case 'Completed': echo 'badge-completed'; break;
                                    case 'Cancelled': echo 'badge-cancelled'; break;
                                    default: echo 'badge-notstarted';
                                }
                            ?>">
                                <?php echo $maintenance['Status']; ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Row 3, Col 1 -->
                    <div class="info-card">
                        <div class="info-label">Start Date</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($maintenance['Start_Date'])); ?></div>
                    </div>
                    
                    <!-- Row 3, Col 2 -->
                    <div class="info-card">
                        <div class="info-label">End Date</div>
                        <div class="info-value">
                            <?php 
                            if(!empty($maintenance['End_Date'])) {
                                echo date('d/m/Y', strtotime($maintenance['End_Date']));
                            } else {
                                echo '<span class="text-muted">Not set</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <!-- Row 4, Col 1 -->
                    <div class="info-card">
                        <div class="info-label">Duration</div>
                        <div class="info-value">
                            <?php 
                            $days_active = $maintenance['Days_Active'];
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
                        </div>
                    </div>
                    
                    <!-- Row 4, Col 2 - Only show cost for admin -->
                    <div class="info-card">
                        <div class="info-label"><?php echo $is_admin ? 'Estimated Cost' : 'Task Age'; ?></div>
                        <div class="info-value">
                            <?php if($is_admin): ?>
                                ৳<?php echo number_format($maintenance['Cost'], 2); ?>
                            <?php else: ?>
                                <?php 
                                $created_date = new DateTime($maintenance['Start_Date']);
                                $today = new DateTime();
                                $age = $created_date->diff($today)->days;
                                echo $age . ' days ago';
                                ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Task Description - Full Width Below Grid -->
                <div class="mt-4">
                    <h5 class="mb-2">Task Description</h5>
                    <div class="description-box">
                        <?php echo !empty($maintenance['Description']) ? nl2br(htmlspecialchars($maintenance['Description'])) : '<span class="text-muted">No description provided</span>'; ?>
                    </div>
                </div>
            </div>

            <!-- Asset Information Section -->
            <div class="content-section">
                <div class="section-header">
                    <i class="fas fa-building me-2"></i>Linked Asset Information
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Asset ID</div>
                            <div class="info-value">#<?php echo $maintenance['Asset_ID']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Asset Name</div>
                            <div class="info-value">
                                <a href="<?php echo $is_admin ? 'view_asset.php' : 'citizen_asset_view.php'; ?>?id=<?php echo $maintenance['Asset_ID']; ?>" class="text-decoration-none">
                                    <?php echo $maintenance['Asset_Name']; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Asset Type</div>
                            <div class="info-value"><?php echo $maintenance['Asset_Type']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Condition</div>
                            <div class="info-value"><?php echo $maintenance['Asset_Condition']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Location</div>
                            <div class="info-value"><?php echo $maintenance['Location']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Installed Date</div>
                            <div class="info-value"><?php echo date('d/m/Y', strtotime($maintenance['Installation_Date'])); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if($is_admin): ?>
            <!-- Task Status - FULL WIDTH SECTION -->
            <div class="content-section">
                <?php if($maintenance['Status'] == 'Completed' || $maintenance['Status'] == 'Cancelled'): ?>
                    <div class="permanent-status">
                        <i class="fas fa-lock" style="color: #718096;"></i>
                        <span><strong>Task Status:</strong> This task is permanently <span class="status-badge <?php 
                            echo ($maintenance['Status'] == 'Completed') ? 'badge-completed' : 'badge-cancelled'; 
                        ?>"><?php echo $maintenance['Status']; ?></span>. No further changes can be made.</span>
                    </div>
                <?php else: ?>
                    <div class="status-full">
                        <div class="status-header">
                            <i class="fas fa-edit me-2"></i>Update Task Status
                        </div>
                        <form method="POST" action="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="status-controls">
                            <select name="update_status" class="status-dropdown" required>
                                <option value="">-- Select New Status --</option>
                                <option value="Not Started" <?php echo ($maintenance['Status'] == 'Not Started') ? 'selected' : ''; ?>>Not Started</option>
                                <option value="Active" <?php echo ($maintenance['Status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Paused" <?php echo ($maintenance['Status'] == 'Paused') ? 'selected' : ''; ?>>Paused</option>
                                <option value="Completed">Completed (Permanent - updates asset to Good)</option>
                                <option value="Cancelled">Cancelled (Permanent - reverts asset condition)</option>
                            </select>
                            <button type="submit" class="update-btn" onclick="return confirm('Are you sure you want to change the status?')">
                                <i class="fas fa-save me-1"></i>Update Status
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Details Section -->
            <div class="content-section">
                <div class="section-header">
                    <i class="fas fa-info-circle me-2"></i>Additional Details
                </div>

                <div class="d-flex flex-column align-items-stretch gap-3 w-100">
                    <?php if($is_admin): ?>
                    <a href="view_asset.php?id=<?php echo $maintenance['Asset_ID']; ?>" 
                       class="detail-link py-4 px-5 text-decoration-none border d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-building me-2"></i>Asset</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <?php endif; ?>

                    <a href="view_worker_maintenance.php?id=<?php echo $maintenance_id; ?>" 
                       class="detail-link py-4 px-5 text-decoration-none border d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-hard-hat me-2"></i>Employees</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>

                    <a href="view_resource_maintenance.php?id=<?php echo $maintenance_id; ?>" 
                       class="detail-link py-4 px-5 text-decoration-none border d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-boxes me-2"></i>Resources</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>

                    <?php if($is_admin): ?>
                    <a href="view_budget_maintenance.php?id=<?php echo $maintenance_id; ?>" 
                       class="detail-link py-4 px-5 text-decoration-none border d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-money-bill me-2"></i>Budget</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>