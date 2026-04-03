<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_assets.php");
    exit();
}

$asset_id = $_GET['id'];

// asset details 
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
$stmt = $conn->prepare($asset_query);
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$asset_result = $stmt->get_result();

if($asset_result->num_rows === 0) {
    header("Location: manage_assets.php");
    exit();
}

$asset = $asset_result->fetch_assoc();
$stmt->close();

// Check if this asset is linked to any project/package
$project_link_query = "SELECT 
                        (SELECT COUNT(*) FROM Maintenance WHERE Asset_ID = ? AND Project_ID IS NOT NULL) as has_project,
                        (SELECT COUNT(*) FROM Maintenance WHERE Asset_ID = ? AND Package_ID IS NOT NULL) as has_package,
                        (SELECT COUNT(*) FROM Maintenance WHERE Asset_ID = ?) as has_tasks";
$link_stmt = $conn->prepare($project_link_query);
$link_stmt->bind_param("iii", $asset_id, $asset_id, $asset_id);
$link_stmt->execute();
$link_result = $link_stmt->get_result();
$links = $link_result->fetch_assoc();
$link_stmt->close();

//form submission
if(isset($_POST['update_asset'])) {
    $asset_type = $_POST['asset_type'];
    $asset_name = $_POST['asset_name'];
    $asset_condition = $_POST['asset_condition'];
    $expenses = $_POST['expenses'];
    $location = $_POST['location'];
    $installation_date = $_POST['installation_date'];
    $check_interval = $_POST['check_interval'];
    
    // original condition before update
    $original_condition = $asset['Asset_Condition'];

    // Update asset
    $update_query = "UPDATE Assets SET 
                    Asset_Type = ?, 
                    Asset_Name = ?, 
                    Asset_Condition = ?, 
                    Expenses = ?, 
                    Location = ?, 
                    Installation_Date = ?, 
                    Maintenance_Interval = ? 
                    WHERE Asset_ID = ?";
    
    $stmt = $conn->prepare($update_query);
    if(!$stmt) {
        $error_msg = "Prepare failed: " . $conn->error;
    } else {
        $stmt->bind_param("sssdssii", $asset_type, $asset_name, $asset_condition, $expenses, $location, $installation_date, $check_interval, $asset_id);
        
        if($stmt->execute()) {
        
            $maintenance_states = ['Needs Maintenance', 'Under Maintenance'];
            $regular_states = ['Good', 'Fair', 'Poor'];
            
            // from maintenance state to regular state
            if (in_array($original_condition, $maintenance_states) && in_array($asset_condition, $regular_states)) {
                $cancel_maintenance_query = "UPDATE Maintenance SET Status = 'Cancelled' 
                                           WHERE Asset_ID = ? AND Status IN ('Pending', 'In Progress')";
                $cancel_stmt = $conn->prepare($cancel_maintenance_query);
                if ($cancel_stmt) {
                    $cancel_stmt->bind_param("i", $asset_id);
                    $cancel_stmt->execute();
                    $cancel_stmt->close();
                }
            }
            
            $stmt->close();
            
            // Redirect to view page with success message
            header("Location: view_asset.php?id=" . $asset_id . "&success=1");
            exit();
            
        } else {
            $error_msg = "Error updating asset: " . $stmt->error;
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
    <title>Edit Asset - Smart DNCC</title>
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

        .header-title h2 {
            font-size: 1.6rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0 0 4px 0;
        }

        .header-title p {
            color: #64748b;
            font-size: 0.95rem;
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

        /* Form Container */
        .form-container {
            max-width: 1000px;
            margin: 0 auto;
            width: 100%;
        }

        /* Form Card */
        .content-section {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.05);
            border: none;
        }

        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 0.95rem;
            letter-spacing: 0.3px;
        }

        .form-control, .form-select {
            border-radius: 10px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            font-size: 0.95rem;
            width: 100%;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(49, 130, 206, 0.15);
        }

        /* Buttons */
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 10px 20px;
            font-size: 0.9rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
        }
        .btn-primary:hover {
            background: #2c5282;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(49,130,206,0.25);
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            color: white;
            border-color: #718096;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .alert {
            border-radius: 12px;
            font-size: 0.95rem;
            margin-bottom: 24px;
            padding: 16px 24px;
            border: none;
        }
        .alert-success {
            background: #f0fff4;
            color: #276749;
            border-left: 4px solid #38a169;
        }
        .alert-danger {
            background: #fff5f5;
            color: #c53030;
            border-left: 4px solid #e53e3e;
        }

        .warning-note {
            background: #fff3cd;
            border-left: 4px solid #d69e2e;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
            font-size: 0.95rem;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        h2 {
            font-size: 1.6rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
        }

        .text-muted {
            color: #64748b !important;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .content-section {
                padding: 24px;
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
                <div>
                    <h2>
                        <i class="fas fa-edit me-2" style="color: var(--accent);"></i>
                        Edit Asset
                    </h2>
                    <p class="text-muted">Update information for <?php echo htmlspecialchars($asset['Asset_Name']); ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="view_asset.php?id=<?php echo $asset_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-eye me-1"></i>View
                    </a>
                    <a href="manage_assets.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>
        </div>

        <!-- Scrollable Content Area -->
        <div class="content-area">
            <div class="form-container">
                
                <?php if(isset($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Edit Asset Form -->
                <div class="content-section">
                    <?php 
                    $maintenance_states = ['Needs Maintenance', 'Under Maintenance'];
                    if (in_array($asset['Asset_Condition'], $maintenance_states)): 
                        
                        // Check for active maintenance tasks
                        $active_tasks_query = "SELECT COUNT(*) as active_count FROM Maintenance 
                                             WHERE Asset_ID = ? AND Status IN ('Pending', 'In Progress')";
                        $task_stmt = $conn->prepare($active_tasks_query);
                        $task_stmt->bind_param("i", $asset_id);
                        $task_stmt->execute();
                        $task_result = $task_stmt->get_result();
                        $active_tasks = $task_result->fetch_assoc();
                        $task_stmt->close();
                        
                        if ($active_tasks['active_count'] > 0): ?>
                            <div class="warning-note">
                                <i class="fas fa-exclamation-triangle fa-lg"></i>
                                <span>This asset has <strong><?php echo $active_tasks['active_count']; ?></strong> active maintenance task(s). Changing to Good/Fair/Poor will cancel them.</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Asset Type</label>
                                <select class="form-select" name="asset_type" required>
                                    <option value="">Select Type</option>
                                    <option value="Road" <?php echo ($asset['Asset_Type'] == 'Road') ? 'selected' : ''; ?>>Road</option>
                                    <option value="Building" <?php echo ($asset['Asset_Type'] == 'Building') ? 'selected' : ''; ?>>Building</option>
                                    <option value="Bridge" <?php echo ($asset['Asset_Type'] == 'Bridge') ? 'selected' : ''; ?>>Bridge</option>
                                    <option value="Park" <?php echo ($asset['Asset_Type'] == 'Park') ? 'selected' : ''; ?>>Park</option>
                                    <option value="Drainage" <?php echo ($asset['Asset_Type'] == 'Drainage') ? 'selected' : ''; ?>>Drainage</option>
                                    <option value="Streetlight" <?php echo ($asset['Asset_Type'] == 'Streetlight') ? 'selected' : ''; ?>>Streetlight</option>
                                    <option value="Public Toilet" <?php echo ($asset['Asset_Type'] == 'Public Toilet') ? 'selected' : ''; ?>>Public Toilet</option>
                                    <option value="Playground" <?php echo ($asset['Asset_Type'] == 'Playground') ? 'selected' : ''; ?>>Playground</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Asset Name</label>
                                <input type="text" class="form-control" name="asset_name" 
                                       value="<?php echo htmlspecialchars($asset['Asset_Name']); ?>" 
                                       placeholder="e.g., Mirpur Road" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Condition</label>
                                <select class="form-select" name="asset_condition" required>
                                    <option value="">Select Condition</option>
                                    <option value="Needs Maintenance" <?php echo ($asset['Asset_Condition'] == 'Needs Maintenance') ? 'selected' : ''; ?>>Needs Maintenance</option>
                                    <option value="Good" <?php echo ($asset['Asset_Condition'] == 'Good') ? 'selected' : ''; ?>>Good</option>
                                    <option value="Fair" <?php echo ($asset['Asset_Condition'] == 'Fair') ? 'selected' : ''; ?>>Fair</option>
                                    <option value="Poor" <?php echo ($asset['Asset_Condition'] == 'Poor') ? 'selected' : ''; ?>>Poor</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cost (BDT)</label>
                                <input type="number" class="form-control" name="expenses" step="0.01" 
                                       value="<?php echo $asset['Expenses']; ?>" 
                                       placeholder="Enter cost" required>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Location</label>
                                <input type="text" class="form-control" name="location" 
                                       value="<?php echo htmlspecialchars($asset['Location']); ?>" 
                                       placeholder="Enter exact location" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Installation Date</label>
                                <input type="date" class="form-control" name="installation_date" 
                                       value="<?php echo $asset['Installation_Date']; ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Check Interval</label>
                                <select class="form-select" name="check_interval" required>
                                    <option value="">Select</option>
                                    <option value="7" <?php echo ($asset['Maintenance_Interval'] == '7') ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="15" <?php echo ($asset['Maintenance_Interval'] == '15') ? 'selected' : ''; ?>>Bi-weekly</option>
                                    <option value="30" <?php echo ($asset['Maintenance_Interval'] == '30') ? 'selected' : ''; ?>>Monthly</option>
                                    <option value="60" <?php echo ($asset['Maintenance_Interval'] == '60') ? 'selected' : ''; ?>>2 Months</option>
                                    <option value="90" <?php echo ($asset['Maintenance_Interval'] == '90') ? 'selected' : ''; ?>>Quarterly</option>
                                    <option value="180" <?php echo ($asset['Maintenance_Interval'] == '180') ? 'selected' : ''; ?>>Half-yearly</option>
                                    <option value="365" <?php echo ($asset['Maintenance_Interval'] == '365') ? 'selected' : ''; ?>>Yearly</option>
                                </select>
                            </div>
                        </div>

                        <div class="d-flex gap-2 justify-content-end mt-5">
                            <a href="view_asset.php?id=<?php echo $asset_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Cancel
                            </a>
                            <button type="submit" name="update_asset" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Asset
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>