<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

// Get context from URL
$asset_id = isset($_GET['asset_id']) ? $_GET['asset_id'] : null;
$project_id = isset($_GET['project_id']) ? $_GET['project_id'] : null;
$package_id = isset($_GET['package_id']) ? $_GET['package_id'] : null;
$report_id = isset($_GET['report_id']) ? $_GET['report_id'] : null;

// Get prefilled form data from URL (preserved from asset creation)
$prefilled_task_name = isset($_GET['task_name']) ? $_GET['task_name'] : null;
$prefilled_task_type = isset($_GET['task_type']) ? $_GET['task_type'] : null;
$prefilled_cost = isset($_GET['cost']) ? $_GET['cost'] : null;
$prefilled_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$prefilled_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$prefilled_description = isset($_GET['description']) ? $_GET['description'] : null;

// Check if we're returning from asset creation with a new asset
$new_asset_id = isset($_GET['new_asset_id']) ? $_GET['new_asset_id'] : null;
$new_asset_name = isset($_GET['new_asset_name']) ? $_GET['new_asset_name'] : null;
$show_asset_success = false;
$is_new_construction = false;

// If we have a new asset ID, use it and update fixed values
if($new_asset_id) {
    $asset_id = $new_asset_id;
    $show_asset_success = true;
    
    // Fetch the new asset details
    $new_asset_query = "SELECT * FROM assets WHERE Asset_ID = ?";
    $stmt = $conn->prepare($new_asset_query);
    $stmt->bind_param("i", $new_asset_id);
    $stmt->execute();
    $new_asset_result = $stmt->get_result();
    $new_asset = $new_asset_result->fetch_assoc();
    $stmt->close();
    
    if($new_asset) {
        // Check if this is a new construction asset
        if($new_asset['Asset_Condition'] == 'New Construction') {
            $is_new_construction = true;
            // Set fixed values from the new asset
            $prefilled_cost = $new_asset['Expenses'];
            $prefilled_start_date = $new_asset['Installation_Date'];
            if(!$prefilled_task_type) {
                $prefilled_task_type = 'Construction';
            }
            if(!$prefilled_task_name) {
                $prefilled_task_name = 'New Construction - ' . $new_asset['Asset_Name'];
            }
        } else {
            // For non-construction assets, use asset values but keep editable
            if(!$prefilled_cost) {
                $prefilled_cost = $new_asset['Expenses'];
            }
            if(!$prefilled_start_date) {
                $prefilled_start_date = $new_asset['Installation_Date'];
            }
        }
    }
}

$asset = null;
$project = null;
$package = null;
$report = null;

// Get asset details
if($asset_id) {
    $asset_query = "SELECT * FROM assets WHERE Asset_ID = ?";
    $stmt = $conn->prepare($asset_query);
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $asset_result = $stmt->get_result();
    $asset = $asset_result->fetch_assoc();
    $stmt->close();
    
    // If this is a new construction asset, mark it
    if($asset && $asset['Asset_Condition'] == 'New Construction') {
        $is_new_construction = true;
    }
    
    // Set values from asset if not already set
    if($asset) {
        if(!$prefilled_cost) {
            $prefilled_cost = $asset['Expenses'];
        }
        if(!$prefilled_start_date) {
            $prefilled_start_date = $asset['Installation_Date'];
        }
        if(!$prefilled_task_type && $asset['Asset_Condition'] == 'New Construction') {
            $prefilled_task_type = 'Construction';
        }
    }
}

// Get project details
if($project_id) {
    $project_query = "SELECT * FROM projects WHERE Project_ID = ?";
    $stmt = $conn->prepare($project_query);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $project_result = $stmt->get_result();
    $project = $project_result->fetch_assoc();
    $stmt->close();
}

// Get package details
if($package_id) {
    $package_query = "SELECT p.*, pr.Project_Name, pr.Project_ID, pr.Project_Type FROM packages p 
                      JOIN projects pr ON p.Project_ID = pr.Project_ID 
                      WHERE p.Package_ID = ?";
    $stmt = $conn->prepare($package_query);
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $package_result = $stmt->get_result();
    $package = $package_result->fetch_assoc();
    $stmt->close();
    
    // Ensure project_id is set from package
    if($package && !$project_id) {
        $project_id = $package['Project_ID'];
        // Refresh project info
        $proj_query = "SELECT * FROM projects WHERE Project_ID = ?";
        $stmt = $conn->prepare($proj_query);
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $proj_result = $stmt->get_result();
        $project = $proj_result->fetch_assoc();
        $stmt->close();
    }
}

// Get citizen report details
if($report_id) {
    $report_query = "SELECT cr.*, u.firstName, u.lastName, a.Asset_Name, a.Asset_ID 
                     FROM citizen_reports cr 
                     JOIN users u ON cr.user_id = u.id 
                     LEFT JOIN assets a ON cr.Asset_ID = a.Asset_ID
                     WHERE cr.Report_ID = ?";
    $stmt = $conn->prepare($report_query);
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $report_result = $stmt->get_result();
    $report = $report_result->fetch_assoc();
    $stmt->close();
}

// Determine if Construction option should be available
$show_construction_option = false;
if($is_new_construction) {
    $show_construction_option = true;
} elseif($project && $project['Project_Type'] == 'Large') {
    $show_construction_option = true;
} elseif($package && isset($package['Project_Type']) && $package['Project_Type'] == 'Large') {
    $show_construction_option = true;
}

// Get assets list
$assets_query = "SELECT Asset_ID, Asset_Name, Asset_Type, Asset_Condition, Location, Expenses, Installation_Date
                 FROM assets 
                 WHERE Asset_Condition IN ('Needs Maintenance', 'New Construction', 'Good', 'Fair', 'Poor', 'Under Maintenance')
                 ORDER BY 
                     CASE Asset_Condition
                         WHEN 'Needs Maintenance' THEN 1
                         WHEN 'Poor' THEN 2
                         WHEN 'Fair' THEN 3
                         WHEN 'Good' THEN 4
                         WHEN 'New Construction' THEN 5
                         WHEN 'Under Maintenance' THEN 6
                         ELSE 7
                     END,
                     Asset_Name";
$assets_result = $conn->query($assets_query);

// Handle form submission
if(isset($_POST['add_maintenance'])) {
    $task_name = $_POST['task_name'];
    $task_type = $_POST['task_type'];
    $cost = $_POST['cost'];
    $start_date = $_POST['start_date'];
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $description = $_POST['description'];
    $asset_id = $_POST['asset_id'];
    $status = 'Not Started';
    
    // Project and Package are passed from URL context
    $project_id = isset($_POST['context_project_id']) ? $_POST['context_project_id'] : null;
    $package_id = isset($_POST['context_package_id']) ? $_POST['context_package_id'] : null;
    $report_id = isset($_POST['context_report_id']) ? $_POST['context_report_id'] : null;
    
    // Insert maintenance task
    $maintenance_query = "INSERT INTO maintenance 
                         (task_name, Task_type, Cost, Start_Date, End_Date, Description, Status, 
                          Asset_ID, Project_ID, Package_ID, Report_ID) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($maintenance_query);
    if($stmt) {
        $stmt->bind_param("ssdsssssiii", 
            $task_name,
            $task_type, 
            $cost, 
            $start_date, 
            $end_date, 
            $description, 
            $status, 
            $asset_id, 
            $project_id, 
            $package_id, 
            $report_id
        );
        
        if($stmt->execute()) {
            $maintenance_id = $stmt->insert_id;
            
            // Update asset condition to Under Maintenance
            $update_asset_query = "UPDATE assets SET Asset_Condition = 'Under Maintenance' WHERE Asset_ID = ?";
            $stmt2 = $conn->prepare($update_asset_query);
            $stmt2->bind_param("i", $asset_id);
            $stmt2->execute();
            $stmt2->close();
            
            // Update citizen report if linked
            if($report_id) {
                $update_report_query = "UPDATE citizen_reports SET Status = 'In Progress' WHERE Report_ID = ?";
                $stmt3 = $conn->prepare($update_report_query);
                $stmt3->bind_param("i", $report_id);
                $stmt3->execute();
                $stmt3->close();
            }
            
            $_SESSION['success_msg'] = "Maintenance task created successfully!";
            
            // Redirect to view the newly created task
            header("Location: view_maintenance.php?id=$maintenance_id&success=task_created");
            exit();
            
        } else {
            $error_msg = "Error creating maintenance task: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_msg = "Prepare failed: " . $conn->error;
    }
}

if(isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Maintenance Task - Smart DNCC</title>
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
        }

        .header-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .header-left h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-left p {
            color: #64748b;
            font-size: 0.85rem;
            margin: 4px 0 0 0;
        }

        .content-area {
            padding: 24px 28px;
            overflow-y: auto;
            height: calc(100vh - 89px);
            display: flex;
            flex-direction: column;
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
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            max-width: 1000px;
            margin: 0 auto;
            width: 100%;
        }

        /* Hierarchy Banner */
        .hierarchy-banner {
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }

        .hierarchy-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
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
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            gap: 6px;
        }

        .badge-project { 
            background: #ebf8ff; 
            color: #2c5282; 
            border: 1px solid #90cdf4; 
        }
        .badge-package { 
            background: #faf5ff; 
            color: #6b46c1; 
            border: 1px solid #d6bcfa; 
        }
        .badge-current { 
            background: #f0fff4; 
            color: #276749; 
            border: 1px solid #9ae6b4; 
        }

        .chevron {
            color: #a0aec0;
            font-size: 0.8rem;
            margin: 0 4px;
        }

        /* Context Cards */
        .context-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .context-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            border-left: 4px solid;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .context-card.asset { border-left-color: var(--info); }
        .context-card.project { border-left-color: var(--accent); }
        .context-card.package { border-left-color: var(--purple); }
        .context-card.report { border-left-color: var(--warning); }

        .context-card h6 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .context-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .context-item {
            display: flex;
            flex-direction: column;
        }

        .context-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #718096;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .context-value {
            font-size: 0.85rem;
            font-weight: 500;
            color: #2d3748;
            word-break: break-word;
        }

        /* Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            min-width: 80px;
            text-align: center;
        }

        .badge-large { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .badge-routine { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-urgent { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        .badge-good { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-fair { background: #fefcbf; color: #975a16; border: 1px solid #fbd38d; }
        .badge-poor { background: #fed7d7; color: #c53030; border: 1px solid #fc8181; }
        .badge-needs { background: #feebc8; color: #7b341e; border: 1px solid #fbd38d; }
        .badge-not-started { background: #e2e8f0; color: #2d3748; border: 1px solid #cbd5e0; }

        /* Form Styling */
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
            font-size: 0.8rem;
            letter-spacing: 0.3px;
        }

        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            font-size: 0.85rem;
            width: 100%;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }

        .form-control[readonly], .form-select[readonly] {
            background-color: #f8fafc;
            cursor: not-allowed;
        }

        /* Small Alert - Compact */
        .alert-small {
            border-radius: 8px;
            font-size: 0.75rem;
            padding: 6px 12px;
            margin-bottom: 16px;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
            background: #e6f4ff;
            border: 1px solid #91caff;
            color: #0958d9;
        }
        
        .alert-small-success {
            background: #f6ffed;
            border-color: #b7eb8f;
            color: #389e0d;
        }
        
        .alert-small i {
            margin-right: 6px;
            font-size: 0.7rem;
        }

        /* Asset Note Box - Compact */
        .asset-note {
            background: #e6f4ff;
            border: 1px solid #91caff;
            border-radius: 8px;
            padding: 10px 14px;
            margin: 12px 0;
            color: #0958d9;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .asset-note i {
            font-size: 1rem;
            color: #1677ff;
        }

        .asset-note-content {
            flex: 1;
        }

        .asset-note-title {
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 2px;
        }

        .asset-note-text {
            font-size: 0.7rem;
            margin-bottom: 4px;
            opacity: 0.9;
        }

        /* Buttons */
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 8px 16px;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: #2c5282;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(49,130,206,0.2);
        }

        .btn-outline-primary {
            background: white;
            color: var(--accent);
            border: 1px solid var(--accent);
        }
        .btn-outline-primary:hover {
            background: var(--accent);
            color: white;
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            color: white;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.7rem;
        }

        h2 {
            font-size: 1.4rem;
        }

        h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 20px;
        }

        .text-muted {
            color: #718096 !important;
        }

        small.text-muted {
            font-size: 0.7rem;
            display: block;
            margin-top: 4px;
        }

        .row.g-3 {
            --bs-gutter-y: 1rem;
        }

        .info-note {
            background: #e6f4ff;
            border: 1px solid #91caff;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 0.7rem;
            margin: 12px 0;
            color: #0958d9;
        }
        
        .info-note i {
            margin-right: 6px;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .content-area {
                padding: 16px;
            }
            
            .header-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .context-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <!-- Header -->
        <div class="admin-header">
            <div class="header-title">
                <div class="header-left">
                    <h2>
                        <i class="fas fa-tools" style="color: var(--accent);"></i>
                        Add Maintenance Task
                    </h2>
                    <p>
                        <?php 
                        if($project && $package) echo "Project #" . $project['Project_ID'] . " / Package #" . $package['Package_ID'];
                        elseif($project) echo "Project #" . $project['Project_ID'];
                        elseif($package) echo "Package #" . $package['Package_ID'];
                        elseif($report) echo "Citizen Report #" . $report['Report_ID'];
                        else echo "Create a new maintenance task";
                        ?>
                    </p>
                </div>
                <div>
                    <?php if($package): ?>
                        <a href="view_package.php?id=<?php echo $package_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php elseif($project): ?>
                        <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php elseif($report): ?>
                        <a href="view_report.php?id=<?php echo $report_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php else: ?>
                        <a href="manage_maintenance.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Content Area -->
        <div class="content-area">
            
            <?php if(isset($error_msg)): ?>
                <div class="alert-small">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <!-- Single success message for new asset -->
            <?php if($show_asset_success && $new_asset_name): ?>
                <div class="alert-small alert-small-success">
                    <i class="fas fa-check-circle"></i> New asset "<?php echo htmlspecialchars($new_asset_name); ?>" created successfully!
                    <?php if($is_new_construction): ?>
                        Task details have been updated with asset information and are now fixed.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if(isset($success_msg)): ?>
                <div class="alert-small alert-small-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <!-- Hierarchy Banner -->
            <?php if($project || $package): ?>
            <div class="hierarchy-banner">
                <div class="hierarchy-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="hierarchy-path">
                    <?php if($project): ?>
                        <a href="view_project.php?id=<?php echo $project_id; ?>" class="hierarchy-link badge-project">
                            <i class="fas fa-folder-open"></i>
                            Project #<?php echo $project['Project_ID']; ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if($package): ?>
                        <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                        <a href="view_package.php?id=<?php echo $package_id; ?>" class="hierarchy-link badge-package">
                            <i class="fas fa-cubes"></i>
                            Package #<?php echo $package['Package_ID']; ?>
                        </a>
                    <?php endif; ?>
                    
                    <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                    <span class="hierarchy-link badge-current">
                        <i class="fas fa-plus-circle"></i>
                        New Task
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Add Maintenance Form -->
            <div class="content-section">
                <!-- Context Info Cards -->
                <?php if($asset || $project || $package || $report): ?>
                <div class="context-cards">
                    <?php if($asset): ?>
                    <div class="context-card asset">
                        <h6><i class="fas fa-building" style="color: var(--info);"></i> Asset #<?php echo $asset['Asset_ID']; ?></h6>
                        <div class="context-grid">
                            <div class="context-item">
                                <span class="context-label">Name</span>
                                <span class="context-value"><?php echo htmlspecialchars($asset['Asset_Name']); ?></span>
                            </div>
                            <div class="context-item">
                                <span class="context-label">Type</span>
                                <span class="context-value"><?php echo htmlspecialchars($asset['Asset_Type']); ?></span>
                            </div>
                            <div class="context-item">
                                <span class="context-label">Condition</span>
                                <span class="context-value">
                                    <span class="badge 
                                        <?php 
                                        if($asset['Asset_Condition'] == 'Good') echo 'badge-good';
                                        elseif($asset['Asset_Condition'] == 'Fair') echo 'badge-fair';
                                        elseif($asset['Asset_Condition'] == 'Poor') echo 'badge-poor';
                                        elseif($asset['Asset_Condition'] == 'Needs Maintenance') echo 'badge-needs';
                                        ?>">
                                        <?php echo $asset['Asset_Condition']; ?>
                                    </span>
                                </span>
                            </div>
                            <div class="context-item">
                                <span class="context-label">Location</span>
                                <span class="context-value"><?php echo htmlspecialchars($asset['Location']); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if($project): ?>
                    <div class="context-card project">
                        <h6><i class="fas fa-folder-open" style="color: var(--accent);"></i> Project #<?php echo $project['Project_ID']; ?></h6>
                        <div class="context-grid">
                            <div class="context-item" style="grid-column: span 2;">
                                <span class="context-label">Name</span>
                                <span class="context-value"><?php echo htmlspecialchars($project['Project_Name']); ?></span>
                            </div>
                            <div class="context-item">
                                <span class="context-label">Type</span>
                                <span class="context-value">
                                    <span class="badge 
                                        <?php 
                                        if($project['Project_Type'] == 'Large') echo 'badge-large';
                                        elseif($project['Project_Type'] == 'Routine') echo 'badge-routine';
                                        else echo 'badge-urgent';
                                        ?>">
                                        <?php echo $project['Project_Type']; ?>
                                    </span>
                                </span>
                            </div>
                            <div class="context-item">
                                <span class="context-label">Status</span>
                                <span class="context-value"><?php echo $project['Status']; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if($package): ?>
                    <div class="context-card package">
                        <h6><i class="fas fa-cubes" style="color: var(--purple);"></i> Package #<?php echo $package['Package_ID']; ?></h6>
                        <div class="context-grid">
                            <div class="context-item" style="grid-column: span 2;">
                                <span class="context-label">Name</span>
                                <span class="context-value"><?php echo htmlspecialchars($package['Package_Name']); ?></span>
                            </div>
                            <div class="context-item">
                                <span class="context-label">Type</span>
                                <span class="context-value"><?php echo $package['Package_Type']; ?></span>
                            </div>
                            <div class="context-item">
                                <span class="context-label">Team Leader</span>
                                <span class="context-value"><?php echo $package['Team_Leader'] ?: 'Not Assigned'; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if($report): ?>
                    <div class="context-card report">
                        <h6><i class="fas fa-file-alt" style="color: var(--warning);"></i> Report #<?php echo $report['Report_ID']; ?></h6>
                        <div class="context-grid">
                            <div class="context-item">
                                <span class="context-label">By</span>
                                <span class="context-value"><?php echo $report['firstName'] . ' ' . $report['lastName']; ?></span>
                            </div>
                            <div class="context-item">
                                <span class="context-label">Date</span>
                                <span class="context-value"><?php echo date('d/m/Y', strtotime($report['Report_Date'])); ?></span>
                            </div>
                            <?php if($report['Asset_Name']): ?>
                            <div class="context-item" style="grid-column: span 2;">
                                <span class="context-label">Asset</span>
                                <span class="context-value"><?php echo $report['Asset_Name']; ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="maintenanceForm">
                    <!-- Hidden fields for context -->
                    <?php if($project_id): ?>
                        <input type="hidden" name="context_project_id" value="<?php echo $project_id; ?>">
                    <?php endif; ?>
                    <?php if($package_id): ?>
                        <input type="hidden" name="context_package_id" value="<?php echo $package_id; ?>">
                    <?php endif; ?>
                    <?php if($report_id): ?>
                        <input type="hidden" name="context_report_id" value="<?php echo $report_id; ?>">
                    <?php endif; ?>
                    <?php if($asset_id): ?>
                        <input type="hidden" name="asset_id" value="<?php echo $asset_id; ?>">
                    <?php endif; ?>

                    <h4>Task Details</h4>
                    
                    <div class="row g-3">
                        <!-- Task Name -->
                        <div class="col-12">
                            <label class="form-label">Task Name *</label>
                            <?php if($is_new_construction): ?>
                                <input type="text" class="form-control" name="task_name" 
                                       value="<?php echo $prefilled_task_name ? $prefilled_task_name : 'New Construction Task'; ?>" 
                                       placeholder="Enter task name" required readonly disabled>
                                <input type="hidden" name="task_name" value="<?php echo $prefilled_task_name ? $prefilled_task_name : 'New Construction Task'; ?>">
                                <small class="text-muted">Fixed for new construction task</small>
                            <?php else: ?>
                                <input type="text" class="form-control" name="task_name" 
                                       value="<?php echo $prefilled_task_name; ?>" 
                                       placeholder="e.g., Pothole Repair on Mirpur Road" required>
                            <?php endif; ?>
                        </div>

                        <!-- Task Type -->
                        <div class="col-md-6">
                            <label class="form-label">Task Type *</label>
                            <?php if($is_new_construction): ?>
                                <select class="form-select" name="task_type" required readonly disabled>
                                    <option value="Construction" <?php echo ($prefilled_task_type == 'Construction') ? 'selected' : ''; ?>>Construction</option>
                                    <option value="Repair" <?php echo ($prefilled_task_type == 'Repair') ? 'selected' : ''; ?>>Repair</option>
                                    <option value="Maintenance" <?php echo ($prefilled_task_type == 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="Restoration" <?php echo ($prefilled_task_type == 'Restoration') ? 'selected' : ''; ?>>Restoration</option>
                                </select>
                                <input type="hidden" name="task_type" value="<?php echo $prefilled_task_type; ?>">
                                <small class="text-muted">Fixed as new asset is being constructed</small>
                            <?php else: ?>
                                <select class="form-select" name="task_type" required>
                                    <option value="">Select Type</option>
                                    <?php if($show_construction_option): ?>
                                        <option value="Construction" <?php echo ($prefilled_task_type == 'Construction') ? 'selected' : ''; ?>>Construction</option>
                                    <?php endif; ?>
                                    <option value="Repair" <?php echo ($prefilled_task_type == 'Repair') ? 'selected' : ''; ?>>Repair</option>
                                    <option value="Maintenance" <?php echo ($prefilled_task_type == 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="Restoration" <?php echo ($prefilled_task_type == 'Restoration') ? 'selected' : ''; ?>>Restoration</option>
                                </select>
                            <?php endif; ?>
                        </div>

                        <!-- Cost -->
                        <div class="col-md-6">
                            <label class="form-label">Estimated Cost (BDT) *</label>
                            <?php if($is_new_construction): ?>
                                <input type="number" class="form-control" name="cost" step="0.01" 
                                       value="<?php echo $prefilled_cost; ?>" 
                                       placeholder="Enter cost" required readonly disabled>
                                <input type="hidden" name="cost" value="<?php echo $prefilled_cost; ?>">
                                <small class="text-muted">Fixed from installation cost</small>
                            <?php else: ?>
                                <input type="number" class="form-control" name="cost" step="0.01" 
                                       value="<?php echo $prefilled_cost; ?>" 
                                       placeholder="Enter cost" required>
                            <?php endif; ?>
                        </div>

                        <!-- Start Date -->
                        <div class="col-md-4">
                            <label class="form-label">Start Date *</label>
                            <?php if($is_new_construction): ?>
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo $prefilled_start_date ? $prefilled_start_date : date('Y-m-d'); ?>" required readonly disabled>
                                <input type="hidden" name="start_date" value="<?php echo $prefilled_start_date ? $prefilled_start_date : date('Y-m-d'); ?>">
                                <small class="text-muted">Fixed from installation date</small>
                            <?php else: ?>
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo $prefilled_start_date ? $prefilled_start_date : date('Y-m-d'); ?>" required>
                            <?php endif; ?>
                        </div>
                        
                        <!-- End Date -->
                        <div class="col-md-4">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $prefilled_end_date; ?>">
                            <small class="text-muted">Optional - Can be modified</small>
                        </div>

                        <!-- Status -->
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <input type="text" class="form-control" value="Not Started" readonly disabled>
                            <input type="hidden" name="status" value="Not Started">
                            <small class="text-muted">New tasks start as Not Started</small>
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="3" 
                                      placeholder="Describe the work..." required><?php 
                                $desc_value = '';
                                if($prefilled_description) {
                                    $desc_value = htmlspecialchars($prefilled_description);
                                } elseif($report && $report['Description']) {
                                    $desc_value = htmlspecialchars($report['Description']);
                                }
                                echo $desc_value;
                            ?></textarea>
                        </div>

                        <!-- Asset Selection -->
                        <?php if(!$asset_id): ?>
                        <div class="col-12">
                            <label class="form-label">Select Asset *</label>
                            <select class="form-select" name="asset_id" id="asset_select" required>
                                <option value="">Select Asset</option>
                                <?php 
                                if($assets_result && $assets_result->num_rows > 0) {
                                    $assets_result->data_seek(0);
                                    while($asset_option = $assets_result->fetch_assoc()): 
                                        $selected = ($new_asset_id && $new_asset_id == $asset_option['Asset_ID']) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $asset_option['Asset_ID']; ?>" <?php echo $selected; ?>>
                                        #<?php echo $asset_option['Asset_ID']; ?> - <?php echo htmlspecialchars($asset_option['Asset_Name']); ?> 
                                        (<?php echo $asset_option['Asset_Condition']; ?>) - ৳<?php echo number_format($asset_option['Expenses'], 2); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>

                        <!-- Asset Creation Note -->
                        <div class="col-12">
                            <div class="asset-note">
                                <i class="fas fa-plus-circle"></i>
                                <div class="asset-note-content">
                                    <div class="asset-note-title">Need a new asset?</div>
                                    <div class="asset-note-text">Create it first - your description and end date will be preserved</div>
                                    <div>
                                        <?php
                                        // Build the URL with all preserved data
                                        $create_asset_url = "add_asset.php?return_to=add_maintenance&context=maintenance";
                                        if($project_id) $create_asset_url .= "&project_id=$project_id";
                                        if($package_id) $create_asset_url .= "&package_id=$package_id";
                                        if($report_id) $create_asset_url .= "&report_id=$report_id";
                                        if($prefilled_task_name) $create_asset_url .= "&task_name=" . urlencode($prefilled_task_name);
                                        if($prefilled_task_type) $create_asset_url .= "&task_type=" . urlencode($prefilled_task_type);
                                        if($prefilled_cost) $create_asset_url .= "&cost=" . urlencode($prefilled_cost);
                                        if($prefilled_start_date) $create_asset_url .= "&start_date=" . urlencode($prefilled_start_date);
                                        if($prefilled_end_date) $create_asset_url .= "&end_date=" . urlencode($prefilled_end_date);
                                        if($prefilled_description) $create_asset_url .= "&description=" . urlencode($prefilled_description);
                                        ?>
                                        <a href="<?php echo $create_asset_url; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-plus-circle me-1"></i>Create New Asset
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php else: ?>
                        <div class="col-12">
                            <label class="form-label">Selected Asset</label>
                            <input type="text" class="form-control" value="#<?php echo $asset['Asset_ID']; ?> - <?php echo htmlspecialchars($asset['Asset_Name']); ?> (<?php echo $asset['Asset_Condition']; ?>)" readonly disabled>
                            <input type="hidden" name="asset_id" value="<?php echo $asset_id; ?>">
                        </div>
                        <?php endif; ?>

                        <div class="col-12 d-flex gap-2 justify-content-end mt-3">
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="fas fa-undo me-1"></i>Reset
                            </button>
                            <button type="submit" name="add_maintenance" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Create Task
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Form validation
    document.getElementById('maintenanceForm').addEventListener('submit', function(e) {
        const assetSelect = document.querySelector('select[name="asset_id"]');
        const assetHidden = document.querySelector('input[name="asset_id"]');
        
        if(!assetSelect && !assetHidden) {
            e.preventDefault();
            alert('Please select an asset');
            return false;
        }
        
        if(assetSelect && !assetSelect.value) {
            e.preventDefault();
            alert('Please select an asset');
            return false;
        }
        
        const taskName = document.querySelector('input[name="task_name"]');
        if(taskName && !taskName.value) {
            e.preventDefault();
            alert('Please enter a task name');
            return false;
        }
        
        const description = document.querySelector('textarea[name="description"]').value.trim();
        if(!description) {
            e.preventDefault();
            alert('Please enter a description');
            return false;
        }
        
        return true;
    });

    // Auto-hide alerts after 3 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert-small').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 500);
        });
    }, 3000);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
</body>
</html>