<?php
session_start();
include("connect.php");

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

// Get project ID from URL
$project_id = isset($_GET['project_id']) ? $_GET['project_id'] : 0;

if($project_id == 0) {
    header("Location: manage_projects.php");
    exit();
}

// Fetch project details
$project_query = "SELECT * FROM projects WHERE Project_ID = ?";
$stmt = $conn->prepare($project_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project_result = $stmt->get_result();

if($project_result->num_rows === 0) {
    header("Location: manage_projects.php");
    exit();
}

$project = $project_result->fetch_assoc();
$project_type = $project['Project_Type'];

// Fetch project zones for reference only
$zones_query = "SELECT z.* FROM zones z 
                JOIN project_zones pz ON z.Zone_ID = pz.Zone_ID 
                WHERE pz.Project_ID = ? 
                ORDER BY z.Zone_Name";
$stmt = $conn->prepare($zones_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$zones_result = $stmt->get_result();

// Handle form submission
if(isset($_POST['add_package'])) {
    $package_name = $_POST['package_name'];
    $description = $_POST['description'];
    $package_type = ($project_type == 'Large') ? 'DPP' : 'Maintenance';
    $budget = !empty($_POST['budget']) ? $_POST['budget'] : 0;
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    $insert_query = "INSERT INTO packages (Project_ID, Package_Name, Description, Package_Type, Budget, Start_Date, End_Date, Status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'Not Started')";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("isssdss", $project_id, $package_name, $description, $package_type, $budget, $start_date, $end_date);
    
    if($stmt->execute()) {
        $package_id = $stmt->insert_id;
        // Redirect to view the newly created package
        header("Location: view_package.php?id=$package_id&success=package_created");
        exit();
    } else {
        $error_msg = "Error creating package: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Package - Project #<?php echo $project_id; ?> - Smart DNCC</title>
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
            padding: 12px 28px;
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
            font-size: 1.3rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-left p {
            color: #64748b;
            font-size: 0.8rem;
            margin: 2px 0 0 0;
        }

        .content-area {
            padding: 0 28px;
            overflow-y: auto;
            height: calc(100vh - 60px);
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
            border-radius: 14px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            max-width: 800px;
            margin: 16px auto;
            width: 100%;
        }

        /* Project Info Card */
        .project-info-card {
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            border-radius: 10px;
            padding: 12px 18px;
            margin: 16px 0 12px 0;
            border: 1px solid #cbd5e0;
        }

        .project-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            background: rgba(49,130,206,0.1);
            color: var(--accent);
            border: 1px solid #90cdf4;
        }

        /* Zone Reference */
        .zone-reference {
            background: #ebf8ff;
            border: 1px solid #90cdf4;
            border-radius: 8px;
            padding: 8px 14px;
            margin-bottom: 12px;
            color: #2c5282;
            font-size: 0.8rem;
        }

        /* Form Styling - 2 column layout */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
            font-size: 0.8rem;
            letter-spacing: 0.3px;
        }

        .required::after {
            content: "*";
            color: var(--danger);
            margin-left: 4px;
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

        textarea.form-control {
            resize: vertical;
            min-height: 70px;
        }

        /* Buttons */
        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 6px 14px;
            font-size: 0.8rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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
            margin: 12px 0;
            padding: 8px 16px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }

        h2 {
            font-size: 1.3rem;
        }

        .text-muted {
            color: #718096 !important;
        }

        small.text-muted {
            font-size: 0.7rem;
            margin-top: 3px;
            display: block;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.7rem;
        }
        
        .badge-large { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .badge-routine { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-urgent { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .content-area {
                padding: 0 20px;
            }
            
            .header-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .form-row {
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
                        <i class="fas fa-cubes" style="color: var(--purple);"></i>
                        Create New Package
                    </h2>
                    <p>Project #<?php echo $project_id; ?>: <?php echo htmlspecialchars($project['Project_Name']); ?></p>
                </div>
                <div>
                    <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>
        </div>

        <!-- Content Area - Starts right after header -->
        <div class="content-area">
        
            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Project Info Summary - Shows ID and Type -->
            <div class="project-info-card">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <span class="project-badge">
                                <i class="fas 
                                    <?php 
                                    if($project_type == 'Large') echo 'fa-building';
                                    elseif($project_type == 'Routine') echo 'fa-calendar-check';
                                    else echo 'fa-exclamation';
                                    ?> me-1">
                                </i>
                                <?php echo $project_type; ?>
                            </span>
                            <span class="project-badge">
                                <i class="fas fa-hashtag me-1"></i>ID: <?php echo $project_id; ?>
                            </span>
                            <span><strong><?php echo htmlspecialchars($project['Project_Name']); ?></strong></span>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end mt-2 mt-md-0">
                        <span class="badge 
                            <?php 
                            if($project['Status'] == 'Not Started') echo 'bg-secondary';
                            elseif($project['Status'] == 'Active') echo 'bg-success';
                            elseif($project['Status'] == 'Paused') echo 'bg-warning';
                            elseif($project['Status'] == 'Completed') echo 'bg-info';
                            elseif($project['Status'] == 'Cancelled') echo 'bg-danger';
                            ?>">
                            <?php echo $project['Status']; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Add Package Form -->
            <div class="content-section">
                <form method="POST" action="" id="packageForm">
                    
                    <!-- Row 1: Package Name | Budget -->
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Package Name</label>
                            <input type="text" class="form-control" name="package_name" 
                                   placeholder="e.g., Road Repair Package" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Budget (BDT)</label>
                            <input type="number" class="form-control" name="budget" step="0.01" 
                                   placeholder="Enter amount">
                            <small class="text-muted">Optional</small>
                        </div>
                    </div>

                    <!-- Row 2: Description (full width) -->
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2" 
                                      placeholder="Describe the scope of this package..."></textarea>
                        </div>
                    </div>

                    <!-- Row 3: Start Date | End Date -->
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Start Date</label>
                            <input type="date" class="form-control" name="start_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">End Date</label>
                            <?php 
                            $default_end = date('Y-m-d', strtotime('+3 months'));
                            ?>
                            <input type="date" class="form-control" name="end_date" 
                                   value="<?php echo $default_end; ?>" required>
                        </div>
                    </div>

                    <input type="hidden" name="add_package" value="1">

                    <div class="info-note mt-3 mb-3" style="background: #ebf8ff; border: 1px solid #90cdf4; border-radius: 6px; padding: 8px 12px; font-size: 0.75rem;">
                        <i class="fas fa-info-circle" style="color: var(--accent);"></i>
                        <span>After creating this package, you will be redirected to the package view page where you can add tasks, assign team members, and manage resources.</span>
                    </div>

                    <div class="d-flex gap-2 justify-content-end mt-3">
                        <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                        <button type="reset" class="btn btn-outline-secondary">
                            Reset
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save me-1"></i>Create Package
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Form validation
    document.getElementById('packageForm').addEventListener('submit', function(e) {
        const packageName = document.querySelector('input[name="package_name"]').value.trim();
        if(!packageName) {
            e.preventDefault();
            alert('Please enter package name');
            return;
        }
        
        const startDate = document.querySelector('input[name="start_date"]').value;
        const endDate = document.querySelector('input[name="end_date"]').value;
        
        if(new Date(startDate) > new Date(endDate)) {
            e.preventDefault();
            alert('End date must be after start date');
            return;
        }
    });

    // Auto-hide alerts after 3 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 500);
        });
    }, 3000);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>