<?php
session_start();
include("connect.php");

if(!isset($_SESSION['email'])) {
    header("Location: logout.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_citizen = ($_SESSION['role'] == 'citizen');

$success_msg = $error_msg = "";
$user_info = [];
$reports_result = null;
$assets_result = null;

if($is_admin && isset($_GET['user_id'])) {
    $target_user_id = intval($_GET['user_id']);
    
    // target user info (admin)
    $target_user_query = "SELECT firstName, lastName, email FROM users WHERE id = ?";
    $stmt = $conn->prepare($target_user_query);
    if($stmt) {
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
        $target_user_result = $stmt->get_result();
        $target_user_info = $target_user_result->fetch_assoc();
        $stmt->close();
    } else {
        $error_msg = "Database error: " . $conn->error;
    }
} else {
    $target_user_id = $_SESSION['id'];
    $target_user_info = null;
}

// current user info (own)
$current_user_id = $_SESSION['id'];
$user_query = "SELECT firstName, lastName, email FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
if($stmt) {
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $user_info = $user_result->fetch_assoc();
    $stmt->close();
}

// form submission for new report WITH IMAGE UPLOAD (LONGBLOB)
if(isset($_POST['submit_report']) && $is_citizen) {
    $report_type = trim($_POST['report_type']);
    $description = trim($_POST['description']);
    $asset_id = isset($_POST['asset_id']) && !empty($_POST['asset_id']) ? $_POST['asset_id'] : null;
    $report_date = date('Y-m-d');
    
    // Handle image upload to LONGBLOB
    $report_image = null;
    
    if(isset($_FILES['report_image']) && $_FILES['report_image']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file_size = $_FILES['report_image']['size'];
        $file_tmp = $_FILES['report_image']['tmp_name'];
        $file_type = $_FILES['report_image']['type'];
        
        // Validate file type
        if(!in_array($file_type, $allowed_types)) {
            $error_msg = "Only JPG, PNG, GIF & WEBP images are allowed.";
        }
        // Validate file size
        elseif($file_size > $max_size) {
            $error_msg = "Image size must be less than 5MB.";
        }
        else {
            // Read image file as binary
            $report_image = file_get_contents($file_tmp);
        }
    }
    
    // Only proceed if no upload error
    if(empty($error_msg)) {
        // Check if Report_Image column exists
        $check_column = "SHOW COLUMNS FROM Citizen_Reports LIKE 'Report_Image'";
        $column_check = $conn->query($check_column);
        $has_image_column = $column_check && $column_check->num_rows > 0;
        
        if($has_image_column) {
            // With LONGBLOB column
            if($asset_id) {
                $insert_query = "INSERT INTO Citizen_Reports (Report_type, Report_Date, Description, Report_Image, user_id, Asset_ID, Status) VALUES (?, ?, ?, ?, ?, ?, 'Submitted')";
                $stmt = $conn->prepare($insert_query);
                if($stmt) {
                    $stmt->bind_param("ssssii", $report_type, $report_date, $description, $report_image, $target_user_id, $asset_id);
                } else {
                    $error_msg = "Database error: " . $conn->error;
                }
            } else {
                $insert_query = "INSERT INTO Citizen_Reports (Report_type, Report_Date, Description, Report_Image, user_id, Status) VALUES (?, ?, ?, ?, ?, 'Submitted')";
                $stmt = $conn->prepare($insert_query);
                if($stmt) {
                    $stmt->bind_param("ssssi", $report_type, $report_date, $description, $report_image, $target_user_id);
                } else {
                    $error_msg = "Database error: " . $conn->error;
                }
            }
        } else {
            // Without image column (original query)
            if($asset_id) {
                $insert_query = "INSERT INTO Citizen_Reports (Report_type, Report_Date, Description, user_id, Asset_ID, Status) VALUES (?, ?, ?, ?, ?, 'Submitted')";
                $stmt = $conn->prepare($insert_query);
                if($stmt) {
                    $stmt->bind_param("sssii", $report_type, $report_date, $description, $target_user_id, $asset_id);
                } else {
                    $error_msg = "Database error: " . $conn->error;
                }
            } else {
                $insert_query = "INSERT INTO Citizen_Reports (Report_type, Report_Date, Description, user_id, Status) VALUES (?, ?, ?, ?, 'Submitted')";
                $stmt = $conn->prepare($insert_query);
                if($stmt) {
                    $stmt->bind_param("sssi", $report_type, $report_date, $description, $target_user_id);
                } else {
                    $error_msg = "Database error: " . $conn->error;
                }
            }
        }
        
        if(isset($stmt) && $stmt) {
            if($stmt->execute()) {
                $success_msg = "Report submitted successfully!" . ($report_image ? " Image attached." : "");
                // Refresh the page to show new report
                header("Location: " . $_SERVER['PHP_SELF'] . ($is_admin ? "?user_id=$target_user_id" : ""));
                exit();
            } else {
                $error_msg = "Error submitting report: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Check if Report_Image column exists
$check_column = "SHOW COLUMNS FROM Citizen_Reports LIKE 'Report_Image'";
$column_check = $conn->query($check_column);
$has_image_column = $column_check && $column_check->num_rows > 0;

// Reports query
$reports_query = "SELECT cr.*, 
                         a.Asset_Name,
                         COUNT(m.Maintenance_ID) as maintenance_count
                  FROM Citizen_Reports cr 
                  LEFT JOIN Assets a ON cr.Asset_ID = a.Asset_ID
                  LEFT JOIN Maintenance m ON m.Report_ID = cr.Report_ID
                  WHERE cr.user_id = ? 
                  GROUP BY cr.Report_ID
                  ORDER BY cr.Report_Date DESC";

$stmt = $conn->prepare($reports_query);
if($stmt) {
    $stmt->bind_param("i", $target_user_id);
    $stmt->execute();
    $reports_result = $stmt->get_result();
    $stmt->close();
} else {
    $error_msg = "Error loading reports: " . $conn->error;
}

if($is_citizen) {
    $assets_query = "SELECT Asset_ID, Asset_Name, Asset_Type, Location FROM Assets ORDER BY Asset_Name";
    $assets_result = $conn->query($assets_query);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_admin ? 'Citizen Reports' : 'My Reports'; ?> - Smart DNCC</title>
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
        }

        .header-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
        }

        .header-title p {
            color: #64748b;
            font-size: 0.85rem;
            margin: 5px 0 0 0;
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

        .status-submitted { background: #e3f2fd; color: #1565c0; border: 1px solid #90cdf4; }
        .status-under-review { background: #fff3e0; color: #ef6c00; border: 1px solid #fbd38d; }
        .status-in-progress { background: #e8f5e8; color: #2e7d32; border: 1px solid #9ae6b4; }
        .status-resolved { background: #f3e5f5; color: #7b1fa2; border: 1px solid #d6bcfa; }
        .status-closed { background: #f5f5f5; color: #616161; border: 1px solid #cbd5e0; }

        /* Asset badge */
        .asset-badge {
            background: #ebf8ff;
            color: #2c5282;
            border: 1px solid #90cdf4;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 70px;
            text-align: center;
            display: inline-block;
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
            min-width: 100px;
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

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
            min-width: 120px;
        }
        .btn-primary:hover {
            background: #2c5282;
        }

        .btn-outline-primary {
            background: white;
            color: var(--accent);
            border: 1px solid var(--accent);
            min-width: 100px;
        }
        .btn-outline-primary:hover {
            background: var(--accent);
            color: white;
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            min-width: 100px;
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

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.7rem;
            min-width: 80px;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            min-width: 70px;
            text-align: center;
            display: inline-block;
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

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: #f8fafc;
            color: #4a5568;
            font-weight: 600;
            font-size: 0.7rem;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .table td {
            padding: 12px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.8rem;
        }

        .table tr:hover {
            background: #fafbfc;
        }

        /* Image preview styles */
        .image-preview {
            width: 60px;
            height: 40px;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.2s;
            object-fit: cover;
            border: 1px solid #e2e8f0;
        }
        
        .image-preview:hover {
            transform: scale(2);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 10;
            position: relative;
        }
        
        .image-upload-preview {
            max-width: 200px;
            max-height: 150px;
            margin-top: 10px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .header-buttons {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        h2, h4 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.35rem; }
        h4 { font-size: 1rem; }

        .text-muted {
            color: #718096 !important;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            color: #cbd5e0;
        }

        /* Modal styling */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }

        .modal-header {
            border-bottom: 1px solid #e2e8f0;
            padding: 20px 24px;
        }

        .modal-header .modal-title {
            font-weight: 600;
            color: #1a202c;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid #e2e8f0;
            padding: 16px 24px;
        }

        .form-label {
            font-weight: 500;
            color: #4a5568;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }

        .form-control, .form-select {
            border-radius: 6px;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        @media (max-width: 768px) {
            .admin-main, .citizen-main {
                margin-left: 0;
            }
            
            .admin-header, .citizen-header {
                padding: 16px;
            }
            
            .header-title {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .header-buttons {
                width: 100%;
                justify-content: space-between;
            }
            
            .content-area {
                padding: 16px;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
            
            .image-preview:hover {
                transform: scale(1.5);
            }
        }
    </style>
</head>
<body>
    <?php 
    if($is_admin) {
        include 'sidebar.php';
    } else {
        include 'citizen_sidebar.php';
    }
    ?>

    <div class="<?php echo $is_admin ? 'admin-main' : 'citizen-main'; ?>">
        <div class="<?php echo $is_admin ? 'admin-header' : 'citizen-header'; ?>">
            <div class="header-title">
                <div>
                    <h2>
                        <?php 
                        if($is_admin && $target_user_info) {
                            echo 'Citizen Reports';
                        } else {
                            echo 'My Reports';
                        }
                        ?>
                    </h2>
                    <p class="text-muted mb-0">
                        <?php echo $is_admin && $target_user_info ? 'Viewing reports by ' . htmlspecialchars($target_user_info['firstName'] . ' ' . $target_user_info['lastName']) : 'Submit and track your reports'; ?>
                    </p>
                </div>
                <div class="header-buttons">
                    <?php if($is_admin && $target_user_info): ?>
                        <a href="citizen_profile.php?id=<?php echo $target_user_id; ?>" class="btn-back">
                            <i class="fas fa-user me-1"></i> View Profile
                        </a>
                        <a href="manage_users.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    <?php elseif($is_citizen): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reportModal">
                            <i class="fas fa-plus me-1"></i> New Report
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="content-area">
            
            <?php if(!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Reports List -->
            <div class="content-section">
                <h4 class="mb-3">
                    <i class="fas fa-flag me-2 text-primary"></i>
                    Reports (<?php echo $reports_result ? $reports_result->num_rows : 0; ?>)
                </h4>

                <?php if($reports_result && $reports_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Asset</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <?php if($has_image_column): ?>
                                    <th>Image</th>
                                    <?php endif; ?>
                                    <th>Tasks</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($report = $reports_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong>#<?php echo $report['Report_ID']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($report['Report_type']); ?></td>
                                        <td>
                                            <?php if($report['Asset_Name']): ?>
                                                <span class="asset-badge">
                                                    <i class="fas fa-building me-1"></i>
                                                    <?php echo htmlspecialchars($report['Asset_Name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($report['Report_Date'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $report['Status'])); ?>">
                                                <?php echo $report['Status']; ?>
                                            </span>
                                        </td>
                                        <?php if($has_image_column): ?>
                                        <td>
                                            <?php if(!empty($report['Report_Image'])): ?>
                                                <?php 
                                                $image_data = base64_encode($report['Report_Image']);
                                                $image_src = 'data:image/jpeg;base64,' . $image_data;
                                                ?>
                                                <a href="#" onclick="window.open('<?php echo $image_src; ?>','_blank','width=800,height=600'); return false;">
                                                    <img src="<?php echo $image_src; ?>" 
                                                         alt="Report" 
                                                         class="image-preview"
                                                         title="Click to view full image">
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td>
                                            <?php if($report['maintenance_count'] > 0): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-tools me-1"></i>
                                                    <?php echo $report['maintenance_count']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="view_report.php?id=<?php echo $report['Report_ID']; ?>" class="action-btn btn-view btn-sm">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-flag"></i>
                        <h5>No Reports Found</h5>
                        <p class="text-muted">
                            <?php echo $is_admin ? 'This citizen hasn\'t submitted any reports yet.' : 'You haven\'t submitted any reports yet.'; ?>
                        </p>
                        <?php if($is_citizen): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reportModal">
                                <i class="fas fa-plus me-1"></i> Submit Your First Report
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Report Modal with Image Upload -->
    <?php if($is_citizen): ?>
    <div class="modal fade" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Submit New Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Report Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="report_type" required>
                                    <option value="">Select Type</option>
                                    <option value="Infrastructure Issue">Infrastructure Issue</option>
                                    <option value="Maintenance Request">Maintenance Request</option>
                                    <option value="Safety Concern">Safety Concern</option>
                                    <option value="Cleanliness Issue">Cleanliness Issue</option>
                                    <option value="Damage Report">Damage Report</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Related Asset <span class="text-danger">*</span></label>
                                <select class="form-select" name="asset_id" required>
                                    <option value="">Select Asset</option>
                                    <?php if($assets_result): ?>
                                        <?php $assets_result->data_seek(0); ?>
                                        <?php while($asset = $assets_result->fetch_assoc()): ?>
                                            <option value="<?php echo $asset['Asset_ID']; ?>">
                                                <?php echo htmlspecialchars($asset['Asset_Name']); ?> (<?php echo htmlspecialchars($asset['Location']); ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="description" rows="5" 
                                          placeholder="Please provide detailed description of the issue..." 
                                          required></textarea>
                            </div>
                            
                            <!-- Image Upload Field -->
                            <div class="col-12">
                                <label class="form-label">Upload Image (Optional)</label>
                                <input type="file" class="form-control" name="report_image" id="report_image" 
                                       accept="image/jpeg,image/png,image/jpg,image/gif,image/webp">
                                <small class="text-muted">Max: 5MB. Allowed: JPG, PNG, GIF, WEBP</small>
                                
                                <!-- Image Preview -->
                                <div id="imagePreview" class="mt-2" style="display: none;">
                                    <img src="" alt="Preview" class="image-upload-preview">
                                    <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="clearImage()">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_report" class="btn btn-primary">Submit Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Image preview functionality
    document.getElementById('report_image').addEventListener('change', function(e) {
        const preview = document.getElementById('imagePreview');
        const previewImg = preview.querySelector('img');
        const file = e.target.files[0];
        
        if(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                preview.style.display = 'block';
            }
            reader.readAsDataURL(file);
        } else {
            clearImage();
        }
    });

    function clearImage() {
        document.getElementById('report_image').value = '';
        document.getElementById('imagePreview').style.display = 'none';
        document.getElementById('imagePreview').querySelector('img').src = '';
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 500);
        });
    }, 5000);
    </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>