<?php
session_start();
include("connect.php");

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_maintenance.php");
    exit();
}

$maintenance_id = $_GET['id'];

// maintenance details and asset info
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
                    a.Asset_Condition
                    FROM maintenance m
                    JOIN assets a ON m.Asset_ID = a.Asset_ID
                    WHERE m.Maintenance_ID = ?";
$stmt = $conn->prepare($maintenance_query);
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$maintenance_result = $stmt->get_result();

if($maintenance_result->num_rows === 0) {
    header("Location: manage_maintenance.php");
    exit();
}

$maintenance = $maintenance_result->fetch_assoc();
$stmt->close();

// form submission
if(isset($_POST['update_maintenance'])) {
    $task_name = $_POST['task_name'];
    $task_type = $_POST['task_type']; // This comes from hidden input
    $cost = $_POST['cost'];
    $start_date = $_POST['start_date'];
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $description = $_POST['description'];
    $status = $_POST['status'];

    // Update maintenance task
    $update_query = "UPDATE maintenance SET 
                    task_name = ?, 
                    Task_type = ?, 
                    Cost = ?, 
                    Start_Date = ?, 
                    End_Date = ?, 
                    Description = ?, 
                    Status = ? 
                    WHERE Maintenance_ID = ?";
    
    $stmt = $conn->prepare($update_query);
    if($stmt) {
        $stmt->bind_param("ssdssssi", $task_name, $task_type, $cost, $start_date, $end_date, $description, $status, $maintenance_id);
        
        if($stmt->execute()) {
            $success_msg = "Maintenance task updated successfully!";
            
            // If status changed to Completed, update asset condition
            if($status == 'Completed' && $maintenance['Status'] != 'Completed') {
                $update_asset_query = "UPDATE assets SET Asset_Condition = 'Good', Checking_Date = CURDATE() WHERE Asset_ID = ?";
                $stmt2 = $conn->prepare($update_asset_query);
                if($stmt2) {
                    $stmt2->bind_param("i", $maintenance['Asset_ID']);
                    $stmt2->execute();
                    $stmt2->close();
                    $success_msg .= " Asset condition updated to Good.";
                }
            }
            // If status changed to Cancelled, revert asset condition
            elseif($status == 'Cancelled' && $maintenance['Status'] != 'Cancelled') {
                $original_condition = ($maintenance['Task_type'] == 'Construction') ? 'New Construction' : 'Needs Maintenance';
                $update_asset_query = "UPDATE assets SET Asset_Condition = ? WHERE Asset_ID = ?";
                $stmt2 = $conn->prepare($update_asset_query);
                if($stmt2) {
                    $stmt2->bind_param("si", $original_condition, $maintenance['Asset_ID']);
                    $stmt2->execute();
                    $stmt2->close();
                    $success_msg .= " Asset condition reverted.";
                }
            }
            
            // Refresh data
            $maintenance['task_name'] = $task_name;
            $maintenance['Cost'] = $cost;
            $maintenance['Start_Date'] = $start_date;
            $maintenance['End_Date'] = $end_date;
            $maintenance['Description'] = $description;
            $maintenance['Status'] = $status;
        } else {
            $error_msg = "Error updating maintenance task: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Get valid statuses from maintenance table
$status_query = "SHOW COLUMNS FROM maintenance LIKE 'Status'";
$status_result = $conn->query($status_query);
$status_row = $status_result->fetch_assoc();
preg_match("/^enum\(\'(.*)\'\)$/", $status_row['Type'], $matches);
$valid_statuses = explode("','", $matches[1]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Maintenance - Smart DNCC</title>
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
            border: 1px solid #e2e8f0;
        }

        /* Form Card */
        .form-card {
            max-width: 800px;
            margin: 0 auto;
        }

        /* Asset Info Card */
        .asset-info-card {
            background: linear-gradient(to right, #f8fafc, #edf2f7);
            border-left: 6px solid var(--info);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .asset-info-card h6 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 16px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        .info-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #718096;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: #2d3748;
        }

        /* Form Styling */
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
            font-size: 0.8rem;
            letter-spacing: 0.3px;
        }

        .form-control, .form-select {
            border-radius: 6px;
            padding: 8px 12px;
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

        .form-control:disabled, .form-select:disabled {
            background-color: #f8fafc;
            cursor: not-allowed;
            opacity: 0.7;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Small muted note */
        .muted-note {
            font-size: 0.65rem;
            color: #718096;
            margin-top: 4px;
        }

        /* Buttons */
        .btn {
            border-radius: 6px;
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

        .alert {
            border: 1px solid;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            padding: 12px 20px;
        }
        .alert-success {
            background: #f0fff4;
            color: #276749;
            border-color: #9ae6b4;
        }
        .alert-danger {
            background: #fff5f5;
            color: #c53030;
            border-color: #fc8181;
        }

        /* Status Note */
        .status-note {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 16px;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .status-note i {
            font-size: 1.2rem;
            color: var(--warning);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }
        .badge-not-started { background: #e2e8f0; color: #2d3748; border: 1px solid #cbd5e0; }
        .badge-active { background: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
        .badge-paused { background: #feebc8; color: #744210; border: 1px solid #fbd38d; }
        .badge-completed { background: #e9d8fd; color: #6b46c1; border: 1px solid #d6bcfa; }
        .badge-cancelled { background: #fed7d7; color: #c53030; border: 1px solid #fc8181; }
        .badge-construction { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .badge-repair { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        .badge-maintenance { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-restoration { background: #fef5e7; color: #ad6b35; border: 1px solid #ffe6b3; }

        h2 {
            font-size: 1.4rem;
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
            
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
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
                        <i class="fas fa-edit me-2" style="color: var(--warning);"></i>
                        Edit Maintenance Task
                    </h2>
                    <p class="text-muted">Update maintenance task #<?php echo $maintenance_id; ?> details</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-eye me-1"></i>View Task
                    </a>
                    <a href="manage_maintenance.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>
        </div>

        <!-- Scrollable Content Area -->
        <div class="content-area">
            
            <?php if(isset($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Edit Maintenance Form -->
            <div class="content-section">
                <div class="form-card">
                    <!-- Asset Info -->
                    <div class="asset-info-card">
                        <h6><i class="fas fa-building me-2" style="color: var(--info);"></i>Asset #<?php echo $maintenance['Asset_ID']; ?></h6>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Name</div>
                                <div class="info-value"><?php echo $maintenance['Asset_Name']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Type</div>
                                <div class="info-value"><?php echo $maintenance['Asset_Type']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Condition</div>
                                <div class="info-value">
                                    <span class="badge 
                                        <?php 
                                        if($maintenance['Asset_Condition'] == 'Good') echo 'badge-good';
                                        elseif($maintenance['Asset_Condition'] == 'Fair') echo 'badge-fair';
                                        elseif($maintenance['Asset_Condition'] == 'Poor') echo 'badge-poor';
                                        elseif($maintenance['Asset_Condition'] == 'Under Maintenance') echo 'badge-active';
                                        elseif($maintenance['Asset_Condition'] == 'Needs Maintenance') echo 'badge-needs';
                                        elseif($maintenance['Asset_Condition'] == 'New Construction') echo 'badge-construction';
                                        ?>">
                                        <?php echo $maintenance['Asset_Condition']; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Location</div>
                                <div class="info-value"><?php echo $maintenance['Location']; ?></div>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="">
                        <div class="row g-4">
                            <!-- Task Name -->
                            <div class="col-12">
                                <label class="form-label">Task Name *</label>
                                <input type="text" class="form-control" name="task_name" 
                                       value="<?php echo htmlspecialchars($maintenance['task_name']); ?>" 
                                       placeholder="Enter task name" required>
                            </div>

                            <!-- Task Category - Always locked -->
                            <div class="col-md-6">
                                <label class="form-label">Task Category</label>
                                <input type="text" class="form-control" 
                                       value="<?php 
                                           switch($maintenance['Task_type']) {
                                               case 'Construction': echo 'Construction (New assets)'; break;
                                               case 'Repair': echo 'Repair (Fix damaged assets)'; break;
                                               case 'Maintenance': echo 'Maintenance (Routine upkeep)'; break;
                                               case 'Restoration': echo 'Restoration (Major refurbishment)'; break;
                                               default: echo $maintenance['Task_type'];
                                           }
                                       ?>" 
                                       readonly disabled>
                                <input type="hidden" name="task_type" value="<?php echo $maintenance['Task_type']; ?>">
                                <div class="muted-note">Task category cannot be changed</div>
                            </div>
                            
                            <!-- Cost -->
                            <div class="col-md-6">
                                <label class="form-label">Estimated Cost (BDT) *</label>
                                <input type="number" class="form-control" name="cost" step="0.01" 
                                       value="<?php echo $maintenance['Cost']; ?>" 
                                       placeholder="Enter estimated cost" required>
                            </div>

                            <!-- Start Date -->
                            <div class="col-md-4">
                                <label class="form-label">Start Date *</label>
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo $maintenance['Start_Date']; ?>" required>
                            </div>

                            <!-- End Date -->
                            <div class="col-md-4">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" 
                                       value="<?php echo $maintenance['End_Date']; ?>">
                                <small class="text-muted">Optional</small>
                            </div>
                            
                            <!-- Status -->
                            <div class="col-md-4">
                                <label class="form-label">Status *</label>
                                <select class="form-select" name="status" required>
                                    <?php foreach($valid_statuses as $status_option): ?>
                                        <option value="<?php echo $status_option; ?>" 
                                            <?php echo ($maintenance['Status'] == $status_option) ? 'selected' : ''; ?>>
                                            <?php echo $status_option; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <label class="form-label">Task Description</label>
                                <textarea class="form-control" name="description" rows="4" 
                                          placeholder="Describe the maintenance work..."><?php echo htmlspecialchars($maintenance['Description']); ?></textarea>
                            </div>
                        </div>

                        <!-- Status Change Note -->
                        <div class="status-note">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Note:</strong> 
                                <ul class="mb-0 mt-1 small">
                                    <li>Changing to Completed will update asset condition to Good</li>
                                    <li>Changing to Cancelled will revert asset condition</li>
                                </ul>
                            </div>
                        </div>

                        <div class="d-flex gap-2 justify-content-end mt-4">
                            <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Cancel
                            </a>
                            <button type="submit" name="update_maintenance" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Task
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