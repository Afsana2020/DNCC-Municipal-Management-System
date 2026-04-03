<?php
session_start();
include("connect.php");

// Check if user is logged in
if(!isset($_SESSION['email'])) {
    header("Location: logout.php");
    exit();
}

if(!isset($_GET['id']) || empty($_GET['id'])) {
    if($_SESSION['role'] == 'citizen') {
        header("Location: citizen_reports.php");
    } else {
        header("Location: manage_reports.php");
    }
    exit();
}

$report_id = intval($_GET['id']);
$user_id = $_SESSION['id'];
$user_role = $_SESSION['role'];

// Handle success/error messages from urgent creation
if(isset($_GET['success']) && $_GET['success'] == 'urgent_created') {
    $project_id = $_GET['project_id'] ?? '';
    $task_id = $_GET['task_id'] ?? '';
    $success_msg = "Urgent project created successfully. Project #$project_id and Task #$task_id have been linked to this report.";
}

if(isset($_GET['error']) && $_GET['error'] == 'urgent_failed') {
    $error_msg = "Failed to create urgent project: " . ($_GET['message'] ?? 'Unknown error');
}

// Check if Report_Image column exists
$check_column = "SHOW COLUMNS FROM Citizen_Reports LIKE 'Report_Image'";
$column_check = $conn->query($check_column);
$has_image_column = $column_check && $column_check->num_rows > 0;

// report details - include image if column exists
if($has_image_column) {
    $report_query = "SELECT cr.*, u.firstName, u.lastName, u.email, 
                            a.Asset_ID, a.Asset_Name, a.Asset_Type, a.Location as Asset_Location,
                            a.Asset_Condition
                     FROM Citizen_Reports cr 
                     JOIN users u ON cr.user_id = u.id 
                     LEFT JOIN Assets a ON cr.Asset_ID = a.Asset_ID
                     WHERE cr.Report_ID = ?";
} else {
    $report_query = "SELECT cr.*, u.firstName, u.lastName, u.email, 
                            a.Asset_ID, a.Asset_Name, a.Asset_Type, a.Location as Asset_Location,
                            a.Asset_Condition
                     FROM Citizen_Reports cr 
                     JOIN users u ON cr.user_id = u.id 
                     LEFT JOIN Assets a ON cr.Asset_ID = a.Asset_ID
                     WHERE cr.Report_ID = ?";
}
                 
// user restriction for citizens
if($user_role == 'citizen') {
    $report_query .= " AND cr.user_id = ?";
    $stmt = $conn->prepare($report_query);
    $stmt->bind_param("ii", $report_id, $user_id);
    $stmt->execute();
    $report_result = $stmt->get_result();
    $report = $report_result->fetch_assoc();
    $stmt->close();
} else {
    $stmt = $conn->prepare($report_query);
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $report_result = $stmt->get_result();
    $report = $report_result->fetch_assoc();
    $stmt->close();
}

if(!$report) {
    if($user_role == 'citizen') {
        header("Location: citizen_reports.php");
    } else {
        header("Location: manage_reports.php");
    }
    exit();
}

// Check if asset has any maintenance tasks
$has_maintenance = false;
$maintenance_task = null;
if($report['Asset_ID']) {
    $check_query = "SELECT m.Maintenance_ID, m.Task_type, m.Status, m.Start_Date,
                           p.Project_ID, p.Project_Name, p.Project_Type
                    FROM Maintenance m
                    LEFT JOIN Projects p ON m.Project_ID = p.Project_ID
                    WHERE m.Asset_ID = ?
                    ORDER BY m.Start_Date DESC LIMIT 1";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $report['Asset_ID']);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0) {
        $has_maintenance = true;
        $maintenance_task = $result->fetch_assoc();
    }
    $stmt->close();
}

//form submissions (admin only)
if($user_role == 'admin' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    // Update report status
    if(isset($_POST['update_status'])) {
        $new_status = $_POST['status'];
        $admin_response = $_POST['admin_response'] ?? '';
        
        if($new_status == 'In Progress' && !$has_maintenance) {
            $update_error = "Cannot set status to 'In Progress' without any maintenance tasks for this asset.";
        } else {
            $update_query = "UPDATE Citizen_Reports SET Status = ?, Admin_Response = ?, Response_Date = CURDATE() WHERE Report_ID = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("ssi", $new_status, $admin_response, $report_id);
            
            if($stmt->execute()) {
                $update_success = "Report status updated successfully!";
                $report['Status'] = $new_status;
                $report['Admin_Response'] = $admin_response;
                $report['Response_Date'] = date('Y-m-d');
            } else {
                $update_error = "Error updating report status: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    // Update asset condition - only if no maintenance
    if(isset($_POST['update_asset_condition'])) {
        if($has_maintenance) {
            $update_error = "Cannot update asset condition while maintenance tasks exist.";
        } else {
            $new_condition = $_POST['asset_condition'];
            
            $update_query = "UPDATE Assets SET Asset_Condition = ? WHERE Asset_ID = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("si", $new_condition, $report['Asset_ID']);
            
            if($stmt->execute()) {
                $update_success = "Asset condition updated successfully!";
                $report['Asset_Condition'] = $new_condition;
            } else {
                $update_error = "Error updating asset condition: " . $conn->error;
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report #<?php echo $report_id; ?> - Smart DNCC</title>
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

        .report-status-badge {
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            min-width: 120px;
            text-align: center;
            display: inline-block;
        }

        .status-submitted { background: #e3f2fd; color: #1565c0; border: 1px solid #90cdf4; }
        .status-under-review { background: #fff3e0; color: #ef6c00; border: 1px solid #fbd38d; }
        .status-in-progress { background: #e8f5e8; color: #2e7d32; border: 1px solid #9ae6b4; }
        .status-resolved { background: #f3e5f5; color: #7b1fa2; border: 1px solid #d6bcfa; }
        .status-closed { background: #f5f5f5; color: #616161; border: 1px solid #cbd5e0; }

        /* Work Status Badges - Standardized */
        .work-status-not-started {
            background: #e2e8f0;
            color: #475569;
            border: 1px solid #cbd5e0;
        }
        .work-status-active {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .work-status-paused {
            background: #fed7aa;
            color: #92400e;
            border: 1px solid #fdba74;
        }
        .work-status-completed {
            background: #e9d8fd;
            color: #553c9a;
            border: 1px solid #d6bcfa;
        }
        .work-status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* Project Type Badges */
        .project-type-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        .project-type-large { background: #ebf8ff; color: #2c5282; }
        .project-type-routine { background: #f0fff4; color: #276749; }
        .project-type-urgent { background: #fff5f5; color: #c53030; }

        /* Info Cards - UNIFORM */
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            min-height: 85px;
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

        /* Admin Action Cards - CLEAN */
        .admin-action-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid var(--accent);
        }

        .admin-action-card h6 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 16px;
            color: #2d3748;
        }

        /* Admin Response Header */
        .admin-response-header {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            border-left: 4px solid var(--success);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-response-header h5 {
            margin: 0;
            color: #2d3748;
            font-size: 1.1rem;
        }

        .admin-response-header .response-date {
            color: #64748b;
            font-size: 0.85rem;
        }

        /* Project badges */
        .project-badge {
            background: #f8f9fa;
            color: #2d3748;
            border: 1px solid #e2e8f0;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 500;
            min-width: 70px;
            text-align: center;
            display: inline-block;
        }
        
        .project-type-large {
            background: #ebf8ff;
            color: #2c5282;
            border-color: #90cdf4;
        }
        
        .project-type-routine {
            background: #f0fff4;
            color: #276749;
            border-color: #9ae6b4;
        }
        
        .project-type-urgent {
            background: #fff5f5;
            color: #c53030;
            border-color: #fc8181;
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
            min-width: 100px;
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

        .btn-outline-success {
            background: white;
            color: var(--success);
            border: 1px solid var(--success);
            min-width: 100px;
        }
        .btn-outline-success:hover {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
            border: none;
            min-width: 100px;
        }
        .btn-danger:hover {
            background: #c53030;
        }

        .btn-info {
            background: var(--info);
            color: white;
            border: none;
            min-width: 100px;
        }
        .btn-info:hover {
            background: #0089a3;
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
            min-width: 100px;
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

        /* Action Cards - UNIFORM */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 16px;
        }

        .action-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.2s;
            min-height: 180px;
            display: flex;
            flex-direction: column;
        }

        .action-card:hover {
            border-color: #cbd5e0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .action-card h6 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2d3748;
        }

        .action-card p {
            font-size: 0.8rem;
            color: #718096;
            margin-bottom: 16px;
            flex: 1;
        }

        .urgent-card {
            border-left: 4px solid var(--danger);
            background: #fff5f5;
        }

        .admin-note {
            background: #f8fafc;
            border-left: 4px solid var(--warning);
            padding: 16px 20px;
            margin-bottom: 24px;
            border-radius: 8px;
        }

        .report-image-container {
            margin-top: 20px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            text-align: center;
        }
        
        .report-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            display: inline-block;
        }
        
        .report-image:hover {
            transform: scale(1.02);
        }

        /* Modal for image - CENTERED */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 220px;
            top: 0;
            width: calc(100% - 220px);
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.95);
        }

        .image-modal-content {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 90vh;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 0 30px rgba(0,0,0,0.5);
        }

        .image-modal-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10000;
            transition: color 0.3s;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.3);
            border-radius: 50%;
        }
        .image-modal-close:hover {
            color: #fff;
            background: rgba(229,62,62,0.8);
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

        .form-control, .form-select {
            border-radius: 6px;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
            width: 100%;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
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
        .alert-info {
            background: #ebf8ff;
            color: #2c5282;
            border: 1px solid #90cdf4;
        }

        h2, h5 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.35rem; }
        h5 { font-size: 1rem; }

        .text-muted {
            color: #718096 !important;
        }

        .asset-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .asset-header h5 {
            margin: 0;
        }

        /* Small text for labels */
        .small-label {
            font-size: 0.7rem;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 2px;
        }

        /* Citizen info alert */
        .citizen-alert {
            background: #ebf8ff;
            border-left: 4px solid var(--info);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .citizen-alert i {
            font-size: 1.2rem;
            color: var(--info);
        }

        /* Report details grid */
        .report-details-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .description-card {
            grid-column: span 4;
            border-left: 4px solid var(--purple);
        }

        /* Enhanced Maintenance Task Card with Centered Content */
        .maintenance-task-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            margin: 16px 0;
            border-left: 4px solid var(--accent);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .maintenance-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .maintenance-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: rgba(49,130,206,0.1);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .maintenance-title {
            flex: 1;
        }

        .maintenance-title h6 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #2d3748;
        }

        .maintenance-details-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .maintenance-detail-item {
            padding: 5px 0;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .detail-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .detail-value {
            font-size: 0.95rem;
            color: #1a202c;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            width: 100%;
        }

        .project-name {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Center badges and ensure consistent spacing */
        .status-badge, .project-type-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .project-info-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            width: 100%;
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
            
            .action-grid {
                grid-template-columns: 1fr;
            }
            
            .content-area {
                padding: 16px;
            }
            
            .row.g-3 > [class*="col-"] {
                margin-bottom: 12px;
            }
            
            .report-details-grid {
                grid-template-columns: 1fr;
            }
            
            .description-card {
                grid-column: span 1;
            }
            
            .image-modal {
                left: 0;
                width: 100%;
            }
            
            .image-modal-content {
                max-width: 95%;
            }
            
            .image-modal-close {
                top: 10px;
                right: 15px;
                font-size: 30px;
            }
            
            .maintenance-details-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .maintenance-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .maintenance-header .action-btn {
                align-self: flex-start;
            }
            
            .project-name {
                max-width: 100%;
            }
            
            .maintenance-detail-item {
                text-align: left;
                align-items: flex-start;
            }
            
            .detail-label, .detail-value {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php 
    if($user_role == 'citizen') {
        include 'citizen_sidebar.php'; 
    } else {
        include 'sidebar.php';
    }
    ?>

    <div class="<?php echo $user_role == 'citizen' ? 'citizen-main' : 'admin-main'; ?>">
        <div class="<?php echo $user_role == 'citizen' ? 'citizen-header' : 'admin-header'; ?>">
            <div class="header-title">
                <div>
                    <h2>Report #<?php echo $report_id; ?></h2>
                    <p class="text-muted mb-0">Report details and maintenance updates</p>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="report-status-badge status-<?php echo strtolower(str_replace(' ', '-', $report['Status'])); ?>">
                        <?php echo $report['Status']; ?>
                    </span>
                    <?php if($user_role == 'citizen'): ?>
                        <a href="citizen_reports.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    <?php else: ?>
                        <a href="manage_reports.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="content-area">
            
            <?php if(isset($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if(isset($update_success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $update_success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if(isset($update_error) || isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $update_error ?? $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Citizen View - Clean Alert -->
            <?php if($user_role == 'citizen'): ?>
                <div class="citizen-alert">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Your report is being processed</strong>
                        <p class="mb-0 small">Status will be updated by admin. Check back for updates.</p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Admin Response Header - Separate div at top -->
            <?php if($report['Admin_Response'] || $report['Response_Date']): ?>
            <div class="admin-response-header">
                <div>
                    <h5><i class="fas fa-reply me-2" style="color: var(--success);"></i>Admin Response</h5>
                    <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($report['Admin_Response'])); ?></p>
                </div>
                <?php if($report['Response_Date']): ?>
                <div class="response-date">
                    <i class="fas fa-calendar-alt me-1"></i>
                    <?php echo date('F j, Y', strtotime($report['Response_Date'])); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Admin only - Fixed with separate rows and proper labels -->
            <?php if($user_role == 'admin'): ?>
                
                
                <div class="content-section">
                    <h5 class="mb-3">Admin Actions</h5>
                    
                    <!-- First Row - Update Status -->
                    <div class="admin-action-card mb-4" style="border-left-color: var(--accent);">
                        <h6><i class="fas fa-flag me-2"></i>Update Report Status</h6>
                        <form method="POST">
                            <div class="row align-items-end">
                                <div class="col-md-2 mb-2">
                                    <div class="small-label">Status:</div>
                                    <select class="form-select" name="status" required>
                                        <option value="Submitted" <?php echo $report['Status'] == 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                                        <option value="Under Review" <?php echo $report['Status'] == 'Under Review' ? 'selected' : ''; ?>>Under Review</option>
                                        <?php if($has_maintenance): ?>
                                            <option value="In Progress" <?php echo $report['Status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <?php endif; ?>
                                        <option value="Resolved" <?php echo $report['Status'] == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        <option value="Closed" <?php echo $report['Status'] == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-2">
                                    <div class="small-label">Admin Response:</div>
                                    <textarea class="form-control" name="admin_response" rows="1" placeholder="Enter admin response..."><?php echo htmlspecialchars($report['Admin_Response'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <div class="small-label">&nbsp;</div>
                                    <button type="submit" name="update_status" class="action-btn btn-primary w-100">
                                        Update
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Second Row - Update Asset Condition -->
                    <?php if($report['Asset_ID']): ?>
                    <div class="admin-action-card" style="border-left-color: var(--info);">
                        <h6><i class="fas fa-building me-2"></i>Update Asset Condition</h6>
                        <?php if($has_maintenance): ?>
                            <div class="text-muted">
                                <i class="fas fa-lock me-2"></i>
                                Cannot change condition while maintenance tasks exist
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <div class="row align-items-end">
                                    <div class="col-md-10 mb-2">
                                        <div class="small-label">Asset Condition:</div>
                                        <select class="form-select" name="asset_condition" required>
                                            <option value="Good" <?php echo $report['Asset_Condition'] == 'Good' ? 'selected' : ''; ?>>Good</option>
                                            <option value="Fair" <?php echo $report['Asset_Condition'] == 'Fair' ? 'selected' : ''; ?>>Fair</option>
                                            <option value="Poor" <?php echo $report['Asset_Condition'] == 'Poor' ? 'selected' : ''; ?>>Poor</option>
                                            <option value="New Construction" <?php echo $report['Asset_Condition'] == 'New Construction' ? 'selected' : ''; ?>>New Construction</option>
                                            <option value="Needs Maintenance" <?php echo $report['Asset_Condition'] == 'Needs Maintenance' ? 'selected' : ''; ?>>Needs Maintenance</option>
                                            <option value="Under Maintenance" <?php echo $report['Asset_Condition'] == 'Under Maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <div class="small-label">&nbsp;</div>
                                        <button type="submit" name="update_asset_condition" class="action-btn btn-info w-100">
                                            Update
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Report Details - Now in a single row layout -->
            <div class="content-section">
                <h5 class="mb-3">Report Information</h5>
                <div class="report-details-grid">
                    <div class="info-card">
                        <div class="info-label">Report Type</div>
                        <div class="info-value"><?php echo htmlspecialchars($report['Report_type']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Report Date</div>
                        <div class="info-value"><?php echo date('M j, Y', strtotime($report['Report_Date'])); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Submitted By</div>
                        <div class="info-value"><?php echo htmlspecialchars($report['firstName'] . ' ' . $report['lastName']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Email</div>
                        <div class="info-value text-truncate"><?php echo htmlspecialchars($report['email']); ?></div>
                    </div>
                    <div class="info-card description-card">
                        <div class="info-label">Description</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($report['Description'])); ?></div>
                    </div>
                </div>
                
                <!-- Report Image - Centered -->
                <?php if($has_image_column && !empty($report['Report_Image'])): ?>
                <div class="info-card mt-3" style="border-left-color: var(--warning);">
                    <div class="info-label">Attached Image</div>
                    <div class="report-image-container mt-2" onclick="openImageModal()">
                        <?php 
                        $image_data = base64_encode($report['Report_Image']);
                        $image_src = 'data:image/jpeg;base64,' . $image_data;
                        ?>
                        <img src="<?php echo $image_src; ?>" alt="Report Image" class="report-image" id="reportImage">
                        <p class="small text-muted mt-2 mb-0"><i class="fas fa-search-plus me-1"></i>Click image to enlarge</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Image Modal - Centered -->
            <div id="imageModal" class="image-modal">
                <span class="image-modal-close" onclick="closeImageModal()">&times;</span>
                <img class="image-modal-content" id="modalImage">
            </div>

            <!-- Related Asset -->
            <?php if($report['Asset_ID']): ?>
            <div class="content-section">
                <div class="asset-header">
                    <h5><i class="fas fa-building me-2" style="color: var(--info);"></i>Related Asset</h5>
                    <?php if($user_role == 'admin'): ?>
                        <a href="view_asset.php?id=<?php echo $report['Asset_ID']; ?>" class="action-btn btn-view">
                            <i class="fas fa-eye me-1"></i> View Asset
                        </a>
                    <?php else: ?>
                        <span class="text-muted small"><i class="fas fa-lock me-1"></i>Asset #<?php echo $report['Asset_ID']; ?></span>
                    <?php endif; ?>
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Asset Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($report['Asset_Name']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Asset Type</div>
                            <div class="info-value"><?php echo htmlspecialchars($report['Asset_Type']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Asset Condition</div>
                            <div class="info-value"><?php echo $report['Asset_Condition']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <div class="info-label">Asset ID</div>
                            <div class="info-value">#<?php echo $report['Asset_ID']; ?></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="info-card">
                            <div class="info-label">Location</div>
                            <div class="info-value"><?php echo htmlspecialchars($report['Asset_Location']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Maintenance Status - Enhanced with Centered Columns -->
            <div class="content-section">
                <h5 class="mb-3"><i class="fas fa-tasks me-2" style="color: var(--accent);"></i>Maintenance Status</h5>
                
                <?php if($has_maintenance && $maintenance_task): ?>
                    <!-- Show existing maintenance task with centered columns -->
                    <div class="maintenance-task-card">
                        <div class="maintenance-header">
                            <div class="maintenance-icon">
                                <i class="fas fa-tools"></i>
                            </div>
                            <div class="maintenance-title">
                                <h6>Task #<?php echo $maintenance_task['Maintenance_ID']; ?>: <?php echo htmlspecialchars($maintenance_task['Task_type']); ?></h6>
                            </div>
                            <?php if($user_role == 'admin'): ?>
                                <a href="view_maintenance.php?id=<?php echo $maintenance_task['Maintenance_ID']; ?>" class="action-btn btn-view">
                                    <i class="fas fa-eye me-1"></i> View Task
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="maintenance-details-grid">
                            <!-- Started Column - Centered -->
                            <div class="maintenance-detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-calendar-alt"></i> Started
                                </div>
                                <div class="detail-value">
                                    <?php echo date('M j, Y', strtotime($maintenance_task['Start_Date'])); ?>
                                </div>
                            </div>
                            
                            <!-- Project Column - Centered -->
                            <div class="maintenance-detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-folder"></i> Project
                                </div>
                                <div class="detail-value">
                                    <?php if($maintenance_task['Project_Name']): ?>
                                        <div class="project-info-wrapper">
                                            <span class="project-name"><?php echo htmlspecialchars($maintenance_task['Project_Name']); ?></span>
                                            <span class="project-type-badge <?php 
                                                if($maintenance_task['Project_Type'] == 'Large') echo 'project-type-large';
                                                elseif($maintenance_task['Project_Type'] == 'Routine') echo 'project-type-routine';
                                                elseif($maintenance_task['Project_Type'] == 'Urgent') echo 'project-type-urgent';
                                            ?>">
                                                <i class="fas 
                                                    <?php 
                                                    if($maintenance_task['Project_Type'] == 'Large') echo 'fa-building';
                                                    elseif($maintenance_task['Project_Type'] == 'Routine') echo 'fa-calendar-check';
                                                    elseif($maintenance_task['Project_Type'] == 'Urgent') echo 'fa-exclamation-triangle';
                                                    ?>">
                                                </i>
                                                <?php echo $maintenance_task['Project_Type']; ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">No project linked</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Status Column - Centered -->
                            <div class="maintenance-detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-flag"></i> Status
                                </div>
                                <div class="detail-value">
                                    <span class="status-badge <?php 
                                        if($maintenance_task['Status'] == 'Not Started') echo 'work-status-not-started';
                                        elseif($maintenance_task['Status'] == 'Active') echo 'work-status-active';
                                        elseif($maintenance_task['Status'] == 'Paused') echo 'work-status-paused';
                                        elseif($maintenance_task['Status'] == 'Completed') echo 'work-status-completed';
                                        elseif($maintenance_task['Status'] == 'Cancelled') echo 'work-status-cancelled';
                                    ?>">
                                        <i class="fas 
                                            <?php 
                                            if($maintenance_task['Status'] == 'Not Started') echo 'fa-clock';
                                            elseif($maintenance_task['Status'] == 'Active') echo 'fa-play-circle';
                                            elseif($maintenance_task['Status'] == 'Paused') echo 'fa-pause-circle';
                                            elseif($maintenance_task['Status'] == 'Completed') echo 'fa-check-circle';
                                            elseif($maintenance_task['Status'] == 'Cancelled') echo 'fa-times-circle';
                                            ?>">
                                        </i>
                                        <?php echo $maintenance_task['Status']; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif($user_role == 'admin'): ?>
                    <!-- No maintenance - Show create buttons for admin only -->
                    <div class="text-muted text-center py-3 mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        No maintenance tasks for this asset yet.
                    </div>
                    
                    <div class="action-grid">
                        <!-- Large Project -->
                        <div class="action-card">
                            <h6><i class="fas fa-building me-2" style="color: var(--accent);"></i>Large Project</h6>
                            <p>Multi-zone projects. Create project first, then add packages.</p>
                            <a href="add_project.php?asset_id=<?php echo $report['Asset_ID']; ?>&type=Large" class="action-btn btn-outline-primary w-100">
                                <i class="fas fa-plus-circle me-1"></i> Create Large
                            </a>
                        </div>
                        
                        <!-- Routine Project -->
                        <div class="action-card">
                            <h6><i class="fas fa-calendar-check me-2" style="color: var(--success);"></i>Routine Project</h6>
                            <p>Single-zone maintenance. Create project first, then add packages.</p>
                            <a href="add_project.php?asset_id=<?php echo $report['Asset_ID']; ?>&type=Routine" class="action-btn btn-outline-success w-100">
                                <i class="fas fa-plus-circle me-1"></i> Create Routine
                            </a>
                        </div>
                        
                        <!-- Urgent Project -->
                        <div class="action-card urgent-card">
                            <h6><i class="fas fa-exclamation-triangle me-2" style="color: var(--danger);"></i>Urgent Project</h6>
                            <p>Emergency response. Creates project and automatically attaches task.</p>
                            <a href="add_project.php?from_report=1&asset_id=<?php echo $report['Asset_ID']; ?>&report_id=<?php echo $report_id; ?>" class="action-btn btn-danger w-100">
                                <i class="fas fa-bolt me-1"></i> Create Urgent
                            </a>
                        </div>
                    </div>
                    
                    <div class="text-muted small mt-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Asset #<?php echo $report['Asset_ID']; ?> (<?php echo htmlspecialchars($report['Asset_Name']); ?>) will be pre-selected.
                    </div>
                    
                <?php else: ?>
                    <!-- Citizen view when no tasks -->
                    <div class="text-center py-4">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <p class="text-muted mb-0">No maintenance tasks have been created for this report yet.</p>
                        <p class="small text-muted mt-2">
                            <i class="fas fa-clock me-1"></i>
                            Admin will create projects and tasks based on your report.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php else: ?>
            <div class="content-section text-center">
                <i class="fas fa-building fa-3x text-muted mb-3"></i>
                <h5>No Asset Associated</h5>
                <p class="text-muted">This report is not linked to any asset.</p>
                <?php if($user_role == 'citizen'): ?>
                    <p class="small text-muted">Your report has been submitted and will be reviewed by admin.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function openImageModal() {
        var img = document.getElementById('reportImage');
        var modal = document.getElementById('imageModal');
        var modalImg = document.getElementById('modalImage');
        modal.style.display = "block";
        modalImg.src = img.src;
    }

    function closeImageModal() {
        document.getElementById('imageModal').style.display = "none";
    }

    // Close modal when clicking outside the image
    window.onclick = function(event) {
        var modal = document.getElementById('imageModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>