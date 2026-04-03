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
$context = isset($_GET['context']) ? $_GET['context'] : null;
$project_id = isset($_GET['project_id']) ? $_GET['project_id'] : null;
$package_id = isset($_GET['package_id']) ? $_GET['package_id'] : null;
$report_id = isset($_GET['report_id']) ? $_GET['report_id'] : null;
$return_to = isset($_GET['return_to']) ? $_GET['return_to'] : 'manage_assets.php';

// Get prefilled form data from maintenance page
$prefilled_task_type = isset($_GET['task_type']) ? $_GET['task_type'] : null;
$prefilled_cost = isset($_GET['cost']) ? $_GET['cost'] : null;
$prefilled_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$prefilled_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$prefilled_description = isset($_GET['description']) ? $_GET['description'] : null;

// Fetch project details if coming from project
$project = null;
if($project_id) {
    $project_query = "SELECT * FROM Projects WHERE Project_ID = ?";
    $stmt = $conn->prepare($project_query);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $project_result = $stmt->get_result();
    $project = $project_result->fetch_assoc();
    $stmt->close();
}

// Fetch package details if coming from package
$package = null;
if($package_id) {
    $package_query = "SELECT p.*, pr.Project_Name FROM Packages p 
                      JOIN Projects pr ON p.Project_ID = pr.Project_ID 
                      WHERE p.Package_ID = ?";
    $stmt = $conn->prepare($package_query);
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $package_result = $stmt->get_result();
    $package = $package_result->fetch_assoc();
    $stmt->close();
}

// Fetch report details if coming from report
$report = null;
if($report_id) {
    $report_query = "SELECT * FROM Citizen_Reports WHERE Report_ID = ?";
    $stmt = $conn->prepare($report_query);
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $report_result = $stmt->get_result();
    $report = $report_result->fetch_assoc();
    $stmt->close();
}

// Determine if this is from a project context
$is_from_project = ($context == 'maintenance' && ($project_id || $package_id || $report_id));

// Handle form submission
if(isset($_POST['add_asset'])) {
    $asset_type = $_POST['asset_type'];
    $asset_name = $_POST['asset_name'];
    $asset_condition = $_POST['asset_condition'];
    $expenses = $_POST['expenses'];
    $location = $_POST['location'];
    $installation_date = $_POST['installation_date'];
    $check_interval = $_POST['check_interval'];
    $today = date('Y-m-d');

    // checking date based on condition
    $checking_date = null;
    if(in_array($asset_condition, ['Good', 'Fair', 'Poor'])) {
        $checking_date = $today; 
    }

    // Insert the asset
    $insert_query = "INSERT INTO Assets (Asset_Type, Asset_Name, Asset_Condition, Expenses, Location, Installation_Date, Maintenance_Interval, Checking_Date) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($insert_query);
    if(!$stmt) {
        $error_msg = "Prepare failed: " . $conn->error;
    } else {
        $stmt->bind_param("sssdssis", $asset_type, $asset_name, $asset_condition, $expenses, $location, $installation_date, $check_interval, $checking_date);
        
        if($stmt->execute()) {
            $asset_id = $stmt->insert_id;
            
            // If coming from maintenance task context, redirect back with all prefilled data
            if($is_from_project && $return_to == 'add_maintenance') {
                // Build return URL with asset_id and all preserved form data
                $return_url = "add_maintenance.php?new_asset_id=$asset_id&new_asset_name=" . urlencode($asset_name);
                
                // Preserve all context information
                if($project_id) $return_url .= "&project_id=$project_id";
                if($package_id) $return_url .= "&package_id=$package_id";
                if($report_id) $return_url .= "&report_id=$report_id";
                
                // Preserve the editable fields (description and end date)
                if($prefilled_end_date) $return_url .= "&end_date=" . urlencode($prefilled_end_date);
                if($prefilled_description) $return_url .= "&description=" . urlencode($prefilled_description);
                
                // Also preserve task type if it exists
                if($prefilled_task_type) $return_url .= "&task_type=" . urlencode($prefilled_task_type);
                
                // No need to preserve cost and start date as they will come from the new asset
                
                header("Location: $return_url");
                exit();
            } else {
                // Normal asset addition - redirect to manage_assets
                $_SESSION['success_msg'] = "Asset '$asset_name' added successfully!";
                header("Location: manage_assets.php");
                exit();
            }
        } else {
            $error_msg = "Error adding asset: " . $stmt->error;
        }
        $stmt->close();
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
    <title>Add Asset - Smart DNCC</title>
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
            padding: 16px 28px;
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
            margin: 0;
        }

        .header-title p {
            color: #64748b;
            font-size: 0.8rem;
            margin: 2px 0 0 0;
        }

        .content-area {
            padding: 24px 28px;
            overflow-y: auto;
            height: calc(100vh - 73px);
            display: flex;
            flex-direction: column;
            justify-content: center;
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
            border-radius: 16px;
            padding: 28px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 24px rgba(0,0,0,0.05);
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }

        /* Ensure content doesn't get hidden behind header when centered */
        .content-area:first-child {
            margin-top: auto;
        }
        
        .content-area:last-child {
            margin-bottom: auto;
        }

        /* Small Alert - Compact */
        .alert-small {
            border-radius: 8px;
            font-size: 0.75rem;
            padding: 6px 12px;
            margin-bottom: 16px;
            max-width: 900px;
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

        /* Form Styling */
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
        }

        .form-control, .form-select {
            border-radius: 10px;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            font-size: 0.9rem;
            width: 100%;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(49, 130, 206, 0.1);
        }

        .form-control:disabled, .form-select:disabled {
            background-color: #f8fafc;
            cursor: not-allowed;
            opacity: 0.7;
        }

        /* Context Badge */
        .context-badge {
            background: #e6f4ff;
            border: 1px solid #91caff;
            color: #0958d9;
            padding: 6px 14px;
            border-radius: 30px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            margin-bottom: 20px;
            font-weight: 500;
            max-width: fit-content;
        }

        /* Preserved Data Card */
        .preserved-data-card {
            background: #e6f4ff;
            border: 1px solid #91caff;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }

        .preserved-data-card h6 {
            font-size: 0.8rem;
            font-weight: 600;
            color: #0958d9;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .preserved-data-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
        }

        .preserved-data-item {
            display: flex;
            flex-direction: column;
        }

        .preserved-label {
            font-size: 0.6rem;
            text-transform: uppercase;
            color: #1677ff;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .preserved-value {
            font-size: 0.8rem;
            color: #0958d9;
            font-weight: 500;
            word-break: break-word;
        }

        /* Info Box for Task Link */
        .task-link-info {
            background: #fef5e7;
            border: 1px solid #ffe6b3;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .task-link-info h6 {
            color: #ad6b35;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .task-link-info .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .task-link-info .info-item {
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #ffe6b3;
        }

        .task-link-info .info-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            color: #ad6b35;
            font-weight: 600;
        }

        .task-link-info .info-value {
            font-size: 0.85rem;
            color: #7b4a2e;
            font-weight: 500;
        }

        .task-link-info .info-note {
            font-size: 0.7rem;
            color: #ad6b35;
            margin-top: 8px;
            font-style: italic;
        }

        /* Project Prompt */
        .project-prompt {
            background: #e6f4ff;
            border: 1px solid #91caff;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .project-prompt i {
            color: #1677ff;
            font-size: 0.9rem;
        }

        .project-prompt strong {
            font-size: 0.85rem;
            color: #0958d9;
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

        .btn-outline-primary {
            background: white;
            color: var(--accent);
            border: 1px solid var(--accent);
        }
        .btn-outline-primary:hover {
            background: var(--accent);
            color: white;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.75rem;
        }

        h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
        }

        .text-muted {
            color: #64748b !important;
        }

        small.text-muted {
            display: block;
            margin-top: 4px;
            font-size: 0.7rem;
        }

        .info-note {
            background: #e6f4ff;
            border: 1px solid #91caff;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 0.7rem;
            margin-top: 16px;
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
                justify-content: flex-start;
            }
            
            .content-section {
                padding: 20px;
                margin: 16px auto;
            }
            
            .project-prompt {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .preserved-data-grid {
                grid-template-columns: 1fr;
            }
            
            .task-link-info .info-grid {
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
                <div>
                    <h2>
                        <i class="fas fa-building me-2" style="color: var(--accent);"></i>
                        Add Asset
                    </h2>
                    <p class="text-muted">
                        <?php 
                        if($is_from_project) {
                            if($package) echo "For Package: " . htmlspecialchars($package['Package_Name']);
                            elseif($project) echo "For Project: " . htmlspecialchars($project['Project_Name']);
                            elseif($report) echo "From Report #" . $report['Report_ID'];
                        } else {
                            echo "Add new infrastructure asset";
                        }
                        ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <?php if($is_from_project): ?>
                        <a href="<?php echo $return_to; ?>.php?<?php 
                            if($project_id) echo "project_id=$project_id";
                            if($package_id) echo "package_id=$package_id";
                            if($report_id) echo "report_id=$report_id";
                            if($prefilled_task_type) echo "&task_type=" . urlencode($prefilled_task_type);
                            if($prefilled_cost) echo "&cost=" . urlencode($prefilled_cost);
                            if($prefilled_start_date) echo "&start_date=" . urlencode($prefilled_start_date);
                            if($prefilled_end_date) echo "&end_date=" . urlencode($prefilled_end_date);
                            if($prefilled_description) echo "&description=" . urlencode($prefilled_description);
                        ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php else: ?>
                        <a href="manage_assets.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Scrollable Content Area - Centered -->
        <div class="content-area">
        
            <?php if(isset($error_msg)): ?>
                <div class="alert-small">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <?php if(isset($success_msg)): ?>
                <div class="alert-small alert-small-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <!-- Task Link Information - Shows how values will be passed to maintenance task -->
            <?php if($is_from_project && ($prefilled_task_type || $prefilled_cost || $prefilled_description)): ?>
            <div class="task-link-info">
                <h6>
                    <i class="fas fa-link"></i> This Asset Will Be Linked to Your Maintenance Task
                </h6>
                <div class="info-grid">
                    <?php if($prefilled_task_type): ?>
                    <div class="info-item">
                        <div class="info-label">Task Type</div>
                        <div class="info-value"><?php echo htmlspecialchars($prefilled_task_type); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if($prefilled_cost): ?>
                    <div class="info-item">
                        <div class="info-label">Task Cost (will be updated)</div>
                        <div class="info-value">৳<?php echo number_format($prefilled_cost); ?> → Will be replaced with asset cost</div>
                    </div>
                    <?php endif; ?>
                    <?php if($prefilled_start_date): ?>
                    <div class="info-item">
                        <div class="info-label">Start Date (will be updated)</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($prefilled_start_date)); ?> → Will be replaced with installation date</div>
                    </div>
                    <?php endif; ?>
                    <?php if($prefilled_end_date): ?>
                    <div class="info-item">
                        <div class="info-label">End Date (preserved)</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($prefilled_end_date)); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if($prefilled_description): ?>
                    <div class="info-item">
                        <div class="info-label">Description (preserved)</div>
                        <div class="info-value"><?php echo htmlspecialchars(substr($prefilled_description, 0, 100)) . (strlen($prefilled_description) > 100 ? '...' : ''); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="info-note">
                    <i class="fas fa-info-circle"></i> After creating this asset, you'll return to the maintenance form. The task cost will be set to this asset's cost, and start date will be set to the installation date. Your description and end date will be preserved.
                </div>
            </div>
            <?php endif; ?>

            <!-- Add Asset Form -->
            <div class="content-section">
                <?php if(!$is_from_project): ?>
                <div class="project-prompt">
                    <div>
                        <i class="fas fa-folder-open me-1"></i>
                        <strong>New Construction?</strong> Need to add asset through large project context
                    </div>
                    <a href="manage_projects.php" class="btn btn-sm btn-outline-primary">
                        View Projects <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Asset Type *</label>
                            <select class="form-select" name="asset_type" required>
                                <option value="">Select Type</option>
                                <option value="Road">Road</option>
                                <option value="Building">Building</option>
                                <option value="Bridge">Bridge</option>
                                <option value="Park">Park</option>
                                <option value="Drainage">Drainage</option>
                                <option value="Streetlight">Streetlight</option>
                                <option value="Public Toilet">Public Toilet</option>
                                <option value="Playground">Playground</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Asset Name *</label>
                            <input type="text" class="form-control" name="asset_name" 
                                   placeholder="e.g., Mirpur Road" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Condition *</label>
                            <select class="form-select" name="asset_condition" required 
                                <?php echo $is_from_project ? 'disabled' : ''; ?>>
                                <option value="">Select Condition</option>
                                <?php if($is_from_project): ?>
                                    <option value="New Construction" selected>New Construction</option>
                                <?php else: ?>
                                    <option value="Needs Maintenance">Needs Maintenance</option>
                                    <option value="Good">Good</option>
                                    <option value="Fair">Fair</option>
                                    <option value="Poor">Poor</option>
                                <?php endif; ?>
                            </select>
                            <?php if($is_from_project): ?>
                                <input type="hidden" name="asset_condition" value="New Construction">
                                <small class="text-muted">Auto-set for projects (New Construction)</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Cost (BDT) *</label>
                            <input type="number" class="form-control" name="expenses" step="0.01" 
                                   placeholder="Enter cost" required
                                   value="<?php echo $prefilled_cost; ?>">
                            <?php if($prefilled_cost): ?>
                                <small class="text-muted">Pre-filled from task cost (will be updated in task)</small>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Location *</label>
                            <input type="text" class="form-control" name="location" 
                                   placeholder="Enter exact location" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Installation Date *</label>
                            <input type="date" class="form-control" name="installation_date" 
                                   value="<?php echo $prefilled_start_date ? $prefilled_start_date : date('Y-m-d'); ?>" required>
                            <?php if($prefilled_start_date): ?>
                                <small class="text-muted">Pre-filled from task start date (will update task start date)</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Maintenance Interval *</label>
                            <select class="form-select" name="check_interval" required>
                                <option value="">Select</option>
                                <option value="30">Monthly (30 days)</option>
                                <option value="90">Quarterly (90 days)</option>
                                <option value="180">Half-yearly (180 days)</option>
                                <option value="365">Yearly (365 days)</option>
                            </select>
                        </div>
                    </div>

                    <div class="info-note">
                        <i class="fas fa-info-circle"></i> 
                        <?php if($is_from_project): ?>
                            After creating this asset, you'll return to the maintenance form. The task cost will be set to this asset's cost, and start date will be set to the installation date. 
                        <?php else: ?>
                            Asset will be available for maintenance tasks
                        <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="fas fa-undo me-1"></i>Reset
                        </button>
                        <button type="submit" name="add_asset" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Save Asset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>