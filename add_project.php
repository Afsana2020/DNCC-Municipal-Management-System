<?php
session_start();
include("connect.php");

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

// Check if coming from a report
$from_report = isset($_GET['from_report']) && $_GET['from_report'] == 1;
$asset_id = isset($_GET['asset_id']) ? intval($_GET['asset_id']) : 0;
$report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;

// Get report and asset details if coming from report
$report_data = null;
$asset_data = null;

if($from_report && $asset_id > 0 && $report_id > 0) {
    // Get asset details
    $asset_query = "SELECT Asset_Name, Asset_Type, Location FROM Assets WHERE Asset_ID = ?";
    $stmt = $conn->prepare($asset_query);
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $asset_result = $stmt->get_result();
    $asset_data = $asset_result->fetch_assoc();
    $stmt->close();
    
    // Get report details
    $report_query = "SELECT Report_type, Description FROM Citizen_Reports WHERE Report_ID = ?";
    $stmt = $conn->prepare($report_query);
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $report_result = $stmt->get_result();
    $report_data = $report_result->fetch_assoc();
    $stmt->close();
}

// Handle form submission
if(isset($_POST['add_project'])) {
    $project_name = $_POST['project_name'];
    $project_type = $_POST['project_type'];
    $project_description = $_POST['project_description'];
    $project_budget = $_POST['project_budget'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $status = $_POST['status'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Insert Project
        $insert_project = "INSERT INTO Projects (Project_Name, Project_Type, Description, Budget, Start_Date, End_Date, Status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_project);
        $stmt->bind_param("sssdsss", $project_name, $project_type, $project_description, $project_budget, $start_date, $end_date, $status);
        $stmt->execute();
        $project_id = $stmt->insert_id;
        $stmt->close();
        
        // 2. If this is from a report and type is Urgent, create the maintenance task
        if($from_report && $project_type == 'Urgent' && $asset_id > 0 && $report_id > 0) {
            
            // Create Maintenance Task
            $task_type = $report_data['Report_type'];
            $task_description = "Task for Report #" . $report_id . "\n\n" . $report_data['Description'];
            $task_cost = 0; // Default cost
            $task_status = 'Not Started'; // Updated to new status
            
            $task_query = "INSERT INTO Maintenance (Asset_ID, Project_ID, Task_type, Cost, Start_Date, Description, Status, Report_ID) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($task_query);
            $stmt->bind_param("iisdsssi", $asset_id, $project_id, $task_type, $task_cost, $start_date, $task_description, $task_status, $report_id);
            $stmt->execute();
            $task_id = $stmt->insert_id;
            $stmt->close();
            
            // Update asset condition
            $update_asset = "UPDATE Assets SET Asset_Condition = 'Under Maintenance' WHERE Asset_ID = ?";
            $stmt = $conn->prepare($update_asset);
            $stmt->bind_param("i", $asset_id);
            $stmt->execute();
            $stmt->close();
            
            // Update report status
            $update_report = "UPDATE Citizen_Reports SET Status = 'In Progress' WHERE Report_ID = ?";
            $stmt = $conn->prepare($update_report);
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            // Redirect to view the newly created project
            header("Location: view_project.php?id=$project_id&success=urgent_created&task_id=$task_id");
            exit();
            
        } else {
            // Regular project creation
            $conn->commit();
            
            // Redirect to view the newly created project
            header("Location: view_project.php?id=$project_id&success=project_created");
            exit();
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error creating project: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $from_report ? 'Create Urgent Project' : 'Add Project'; ?> - Smart DNCC</title>
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
            padding: 10px 24px;
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
            font-size: 1.2rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-left p {
            color: #64748b;
            font-size: 0.7rem;
            margin: 2px 0 0 0;
        }

        .content-area {
            padding: 24px 24px;
            overflow-y: auto;
            height: calc(100vh - 55px);
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
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
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            max-width: 900px;
            margin: 20px auto;
            width: 100%;
        }

        /* Report Context Banner */
        .report-context {
            background: #fff5f5;
            border: 1px solid #fc8181;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #c53030;
        }

        .report-context i {
            font-size: 1.2rem;
        }

        .report-context strong {
            color: #c53030;
        }

        /* Project Type Cards */
        .project-type-row {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }

        .project-type-card {
            flex: 1;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .project-type-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .project-type-card.active {
            border-width: 2px;
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .project-type-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }

        #type-large:hover:not(.disabled), #type-large.active { border-color: #3182ce; background: #ebf8ff; }
        #type-routine:hover:not(.disabled), #type-routine.active { border-color: #38a169; background: #f0fff4; }
        #type-urgent:hover:not(.disabled), #type-urgent.active { border-color: #e53e3e; background: #fff5f5; }

        .project-type-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 6px;
            font-size: 16px;
        }

        .project-type-card h5 {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .project-type-card p {
            font-size: 0.65rem;
            color: #718096;
            margin: 0;
            line-height: 1.2;
        }

        /* Form Styling */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-row.full-width {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
            font-size: 0.75rem;
        }

        .required-field::after {
            content: " *";
            color: var(--danger);
            font-weight: bold;
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
            box-shadow: 0 0 0 2px rgba(49, 130, 206, 0.1);
        }

        .form-control:disabled, .form-select:disabled {
            background: #f8fafc;
            cursor: not-allowed;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 70px;
        }

        /* Info Note */
        .info-note {
            background: #ebf8ff;
            border: 1px solid #90cdf4;
            border-radius: 6px;
            padding: 10px 14px;
            margin: 16px 0;
            color: #2c5282;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
        }

        .info-note i {
            color: var(--accent);
            font-size: 0.9rem;
        }

        /* Buttons */
        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 6px 16px;
            font-size: 0.8rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: #2c5282;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }
        .btn-danger:hover {
            background: #c53030;
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
        }

        h2 {
            font-size: 1.2rem;
        }

        h4 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 12px;
        }

        .alert {
            padding: 8px 14px;
            margin-bottom: 12px;
            font-size: 0.8rem;
        }

        .asset-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 0.85rem;
            border-left: 4px solid var(--info);
        }

        .asset-preview i {
            color: var(--info);
            margin-right: 8px;
        }

        small.text-muted {
            font-size: 0.65rem;
            margin-top: 4px;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .project-type-row {
                flex-direction: column;
                gap: 8px;
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
                        <i class="fas fa-plus-circle" style="color: <?php echo $from_report ? 'var(--danger)' : 'var(--accent)'; ?>;"></i>
                        <?php echo $from_report ? 'Create Urgent Project from Report' : 'New Project'; ?>
                    </h2>
                    <p><?php echo $from_report ? 'Auto-filled from report #' . $report_id : 'Add zones/packages later'; ?></p>
                </div>
                <div>
                    <?php if($from_report): ?>
                        <a href="view_report.php?id=<?php echo $report_id; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back to Report
                        </a>
                    <?php else: ?>
                        <a href="manage_projects.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="content-area">
        
            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show py-1" role="alert">
                    <i class="fas fa-exclamation-circle me-1"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close btn-sm py-1" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Report Context Banner (if coming from report) -->
            <?php if($from_report && $report_data && $asset_data): ?>
                <div class="report-context">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Creating Urgent Project from Report #<?php echo $report_id; ?></strong><br>
                        <span>Asset: <?php echo htmlspecialchars($asset_data['Asset_Name']); ?> (<?php echo htmlspecialchars($asset_data['Asset_Type']); ?>)</span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Create Project Form -->
            <div class="content-section">
                <form method="POST" action="" id="projectForm">
                    
                    <!-- Hidden fields for report context -->
                    <?php if($from_report): ?>
                        <input type="hidden" name="from_report" value="1">
                        <input type="hidden" name="asset_id" value="<?php echo $asset_id; ?>">
                        <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                    <?php endif; ?>

                    <!-- Project Type -->
                    <h4>1. Project Type</h4>
                    <div class="project-type-row">
                        <div class="project-type-card <?php echo $from_report ? 'disabled' : ''; ?>" 
                             onclick="<?php echo $from_report ? '' : "selectType('Large')"; ?>" 
                             id="type-large">
                            <div class="project-type-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                                <i class="fas fa-building"></i>
                            </div>
                            <h5>Large</h5>
                            <p>Multi-zone</p>
                        </div>
                        
                        <div class="project-type-card <?php echo $from_report ? 'disabled' : ''; ?>" 
                             onclick="<?php echo $from_report ? '' : "selectType('Routine')"; ?>" 
                             id="type-routine">
                            <div class="project-type-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <h5>Routine</h5>
                            <p>Single zone</p>
                        </div>
                        
                        <div class="project-type-card <?php echo $from_report ? 'active' : ''; ?>" 
                             onclick="selectType('Urgent')" 
                             id="type-urgent">
                            <div class="project-type-icon" style="background: rgba(229,62,62,0.1); color: #e53e3e;">
                                <i class="fas fa-exclamation"></i>
                            </div>
                            <h5>Urgent</h5>
                            <p>Immediate</p>
                        </div>
                    </div>
                    <input type="hidden" name="project_type" id="project_type" value="<?php echo $from_report ? 'Urgent' : ''; ?>" required>

                    <!-- Project Information -->
                    <h4>2. Project Info</h4>
                    
                    <!-- Asset Preview (if from report) -->
                    <?php if($from_report && $asset_data): ?>
                        <div class="asset-preview">
                            <i class="fas fa-building"></i>
                            <strong>Asset:</strong> <?php echo htmlspecialchars($asset_data['Asset_Name']); ?> 
                            (<?php echo htmlspecialchars($asset_data['Asset_Type']); ?>) - 
                            <?php echo htmlspecialchars($asset_data['Location']); ?>
                            <br>
                            <small class="text-muted">This asset will be automatically linked to the urgent task</small>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Row 1: Name | Budget -->
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required-field">Name</label>
                            <input type="text" class="form-control" name="project_name" id="project_name" 
                                   value="<?php echo $from_report && $report_data ? 'URGENT: ' . htmlspecialchars($report_data['Report_type']) . ' - ' . htmlspecialchars($asset_data['Asset_Name']) : ''; ?>" 
                                   placeholder="Project name" 
                                   <?php echo $from_report ? 'readonly' : ''; ?> 
                                   required>
                            <?php if($from_report): ?>
                                <small class="text-muted">Auto-generated from report</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required-field">Budget</label>
                            <input type="number" class="form-control" name="project_budget" id="project_budget" 
                                   step="1" min="0" placeholder="Amount" required>
                        </div>
                    </div>

                    <!-- Row 2: Start | End -->
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required-field">Start</label>
                            <input type="date" class="form-control" name="start_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required-field">End</label>
                            <?php 
                            $default_end = $from_report ? date('Y-m-d', strtotime('+7 days')) : date('Y-m-d', strtotime('+1 month'));
                            ?>
                            <input type="date" class="form-control" name="end_date" 
                                   value="<?php echo $default_end; ?>" required>
                            <?php if($from_report): ?>
                                <small class="text-muted">7-day timeline for urgent projects</small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Row 3: Description -->
                
                    <div class="form-row full-width">
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="project_description" rows="2" 
                                    placeholder="Project scope..."><?php 
                                if($from_report && $report_data) {
                                    echo "Created from Report #" . $report_id . "\n\nReport Type: " . $report_data['Report_type'] . "\n\nReport Description: " . $report_data['Description'];
                                }
                            ?></textarea>
                            <?php if($from_report): ?>
                                <small class="text-muted">Auto-populated from report</small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Row 4: Status -->
                    <div class="form-row full-width">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Not Started" selected>Not Started</option>
                                <option value="Active">Active</option>
                                <option value="Paused">Paused</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <!-- Info Note -->
                    <div class="info-note">
                        <i class="fas fa-info-circle"></i>
                        <span>
                            <?php if($from_report): ?>
                                <strong>One-Click Urgent Response:</strong> This will create the project AND automatically create a maintenance task linked to the asset. You will be redirected to the project view page.
                            <?php else: ?>
                                <strong>Budget Information:</strong> This is the total project budget. You'll allocate portions to individual packages later.
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Buttons -->
                    <div class="d-flex gap-2 justify-content-end">
                        <?php if($from_report): ?>
                            <a href="view_report.php?id=<?php echo $report_id; ?>" class="btn btn-outline-secondary btn-sm">
                                Cancel
                            </a>
                            <button type="submit" name="add_project" class="btn btn-danger btn-sm">
                                <i class="fas fa-exclamation-triangle me-1"></i>Create Urgent Project & Task
                            </button>
                        <?php else: ?>
                            <button type="reset" class="btn btn-outline-secondary btn-sm">
                                Reset
                            </button>
                            <button type="submit" name="add_project" class="btn btn-primary btn-sm">
                                Create Project
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Project type selection
    function selectType(type) {
        <?php if($from_report): ?>
        // If from report, only urgent is allowed
        if(type != 'Urgent') {
            return;
        }
        <?php endif; ?>
        
        document.querySelectorAll('.project-type-card').forEach(el => {
            el.classList.remove('active');
        });
        document.getElementById('type-' + type.toLowerCase()).classList.add('active');
        document.getElementById('project_type').value = type;
    }

    // Form validation
    document.getElementById('projectForm').addEventListener('submit', function(e) {
        if(!document.getElementById('project_type').value) {
            e.preventDefault();
            alert('Please select a project type');
            return;
        }
        if(!document.getElementById('project_name').value.trim()) {
            e.preventDefault();
            alert('Please enter project name');
            return;
        }
        if(!document.getElementById('project_budget').value || document.getElementById('project_budget').value <= 0) {
            e.preventDefault();
            alert('Please enter valid budget');
            return;
        }
    });

    // Budget input - only allow numbers
    document.getElementById('project_budget').addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>