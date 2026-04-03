<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

$success_msg = '';
$error_msg = '';

if(isset($_GET['success'])) {
    if($_GET['success'] == 'worker_updated') {
        $success_msg = "Employee updated successfully!";
    } elseif($_GET['success'] == 'worker_assigned') {
        $success_msg = "Work assigned successfully!";
    } elseif($_GET['success'] == 'status_updated') {
        $success_msg = "Participation status updated successfully!";
    }
}

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_workers.php");
    exit();
}

$worker_id = $_GET['id'];

// Get worker basic information
$worker_query = "SELECT Worker_ID, Worker_Name, Worker_Salary, Contact, Designation 
                 FROM Workers WHERE Worker_ID = ?";
$stmt = $conn->prepare($worker_query);
$stmt->bind_param("i", $worker_id);
$stmt->execute();
$worker_result = $stmt->get_result();

if($worker_result->num_rows === 0) {
    header("Location: manage_workers.php");
    exit();
}

$worker = $worker_result->fetch_assoc();
$stmt->close();

// Handle status update
if(isset($_POST['update_participation_status'])) {
    $assignment_id = $_POST['assignment_id'];
    $new_status = $_POST['participation_status'];
    
    $update_query = "UPDATE worker_assignments SET Status = ? WHERE Assignment_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $new_status, $assignment_id);
    
    if($stmt->execute()) {
        header("Location: view_worker.php?id=$worker_id&success=status_updated");
        exit();
    } else {
        $error_msg = "Error updating status: " . $conn->error;
    }
    $stmt->close();
}

// Get ALL assignments for this worker with assignment status
$assignments_query = "SELECT wa.*,
                             CASE 
                                WHEN wa.Assignment_Type = 'project' THEN pr.Project_Name
                                WHEN wa.Assignment_Type = 'package' THEN pk.Package_Name
                                WHEN wa.Assignment_Type = 'maintenance' THEN m.Task_type
                             END as item_name,
                             CASE 
                                WHEN wa.Assignment_Type = 'project' THEN pr.Status
                                WHEN wa.Assignment_Type = 'package' THEN pk.Status
                                WHEN wa.Assignment_Type = 'maintenance' THEN m.Status
                             END as assignment_status,
                             pr.Project_ID,
                             pr.Project_Name as project_name,
                             pr.Status as project_status,
                             pk.Package_ID,
                             pk.Package_Name as package_name,
                             pk.Status as package_status,
                             m.Maintenance_ID,
                             m.Status as maintenance_status,
                             a.Asset_ID,
                             a.Asset_Name as asset_name
                      FROM worker_assignments wa
                      LEFT JOIN Projects pr ON wa.Project_ID = pr.Project_ID AND wa.Assignment_Type = 'project'
                      LEFT JOIN Packages pk ON wa.Package_ID = pk.Package_ID AND wa.Assignment_Type = 'package'
                      LEFT JOIN Maintenance m ON wa.Maintenance_ID = m.Maintenance_ID AND wa.Assignment_Type = 'maintenance'
                      LEFT JOIN Assets a ON m.Asset_ID = a.Asset_ID
                      WHERE wa.Worker_ID = ?
                      ORDER BY 
                         CASE wa.Status
                            WHEN 'Active' THEN 1
                            WHEN 'Paused' THEN 2
                            ELSE 3
                         END,
                         wa.Assigned_Date DESC";

$stmt = $conn->prepare($assignments_query);
$stmt->bind_param("i", $worker_id);
$stmt->execute();
$assignments_result = $stmt->get_result();

// Store assignments by status
$current_assignments = []; // Active + Paused (only if assignment itself is not completed/cancelled)
$history_assignments = []; // Completed, Fired, or assignment is completed/cancelled

while($row = $assignments_result->fetch_assoc()) {
    // Check if the assignment itself is completed or cancelled
    $assignment_ended = in_array($row['assignment_status'], ['Completed', 'Cancelled']);
    
    // If worker status is Active/Paused AND assignment is still active, it's current
    if(($row['Status'] == 'Active' || $row['Status'] == 'Paused') && !$assignment_ended) {
        $current_assignments[] = $row;
    } else {
        $history_assignments[] = $row; // Everything else goes to history
    }
}

$current_count = count($current_assignments);
$history_count = count($history_assignments);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Employee - <?php echo htmlspecialchars($worker['Worker_Name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #1e3a5f;
            --primary-light: #2563eb;
            --secondary: #475569;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #7c3aed;
            --paused: #b45309;
            --light-bg: #f8fafc;
            --border-color: #e2e8f0;
            --not-started: #94a3b8;
            --cancelled: #6b7280;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f1f5f9;
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
            background: #f1f5f9;
        }

        .admin-header {
            background: white;
            padding: 20px 28px;
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .header-title h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin: 0 0 4px 0;
            letter-spacing: -0.025em;
        }

        .header-title p {
            color: #64748b;
            font-size: 0.9rem;
            margin: 0;
        }

        .content-area {
            padding: 24px 28px;
            overflow-y: auto;
            height: calc(100vh - 89px);
        }

        .content-area::-webkit-scrollbar {
            width: 8px;
        }
        .content-area::-webkit-scrollbar-track {
            background: #e2e8f0;
        }
        .content-area::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 8px;
        }
        .content-area::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }

        /* Profile Card - Modern Design */
        .profile-card {
            background: linear-gradient(135deg, var(--primary) 0%, #2563eb 100%);
            border-radius: 20px;
            padding: 30px 35px;
            margin-bottom: 30px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .profile-info h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 0 0 12px 0;
            color: white;
            letter-spacing: -0.025em;
        }

        .info-grid {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.1);
            padding: 8px 16px;
            border-radius: 40px;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .info-item i {
            font-size: 1rem;
            opacity: 0.9;
        }

        .info-item strong {
            font-weight: 600;
            margin-left: 4px;
        }

        .profile-stats {
            display: flex;
            gap: 25px;
            background: rgba(255,255,255,0.15);
            padding: 18px 30px;
            border-radius: 60px;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .stat-item {
            text-align: center;
            min-width: 80px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.75rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Content Sections */
        .content-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
        }

        .section-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0f172a;
        }

        .count-badge {
            background: #f1f5f9;
            color: #475569;
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        /* Assignment Cards */
        .assignment-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 16px;
            transition: all 0.2s;
            border-left: 6px solid transparent;
        }

        .assignment-card:last-child {
            margin-bottom: 0;
        }

        .assignment-card:hover {
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            border-color: #cbd5e1;
            transform: translateY(-2px);
        }

        .assignment-card.current-active {
            border-left-color: var(--success);
        }
        .assignment-card.current-paused {
            border-left-color: var(--paused);
        }
        .assignment-card.history-completed {
            border-left-color: var(--info);
        }
        .assignment-card.history-fired {
            border-left-color: var(--danger);
        }
        .assignment-card.history-assignment-ended {
            border-left-color: #94a3b8;
        }

        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .type-badge {
            padding: 6px 18px;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .type-project {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        .type-package {
            background: #ede9fe;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        .type-maintenance {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        /* Participation Status Badges - FIXED WIDTH 90px */
        .participation-badge {
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            width: 90px;
            text-align: center;
            box-sizing: border-box;
        }

        .participation-active {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .participation-paused {
            background: #fed7aa;
            color: #92400e;
            border: 1px solid #fdba74;
        }
        .participation-completed {
            background: #e0e7ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
        }
        .participation-fired {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* Assignment Status Badge - FIXED WIDTH 90px */
        .assignment-status-badge {
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            width: 90px;
            text-align: center;
            box-sizing: border-box;
            display: inline-block;
        }
        .assignment-status-not-started {
            background: #e2e8f0;
            color: #475569;
            border: 1px solid #cbd5e0;
        }
        .assignment-status-active {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .assignment-status-paused {
            background: #fed7aa;
            color: #92400e;
            border: 1px solid #fdba74;
        }
        .assignment-status-completed {
            background: #e9d8fd;
            color: #553c9a;
            border: 1px solid #d6bcfa;
        }
        .assignment-status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .assignment-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 16px;
            color: #0f172a;
        }

        .assignment-title a {
            color: #0f172a;
            text-decoration: none;
        }
        .assignment-title a:hover {
            color: var(--primary-light);
        }

        /* Info Row with centered columns */
        .info-row {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .info-col {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .detail-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .detail-value {
            font-size: 0.95rem;
            color: #0f172a;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        /* Status column specific layout */
        .status-col {
            display: flex;
            align-items: center;
            gap: 4px;
            justify-content: center;
        }

        /* Status display - fixed width badge, button beside it */
        .status-display {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .edit-status-btn {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.7rem;
            padding: 2px 4px;
            border-radius: 4px;
            transition: all 0.2s;
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .edit-status-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .status-edit-form {
            display: none;
            align-items: center;
            gap: 4px;
        }

        .status-edit-form select {
            padding: 4px 6px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-size: 0.7rem;
            width: 90px;
        }
        .status-edit-form select:focus {
            outline: none;
            border-color: var(--primary-light);
        }

        .save-status-btn {
            background: var(--primary-light);
            color: white;
            border: none;
            padding: 4px 6px;
            border-radius: 4px;
            font-size: 0.65rem;
            cursor: pointer;
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .save-status-btn:hover {
            background: #1d4ed8;
        }

        .cancel-status-btn {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 4px 6px;
            border-radius: 4px;
            font-size: 0.65rem;
            cursor: pointer;
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .cancel-status-btn:hover {
            background: #e2e8f0;
        }

        .btn-view {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 8px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-view:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-edit {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fcd34d;
            padding: 10px 24px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-edit:hover {
            background: #f59e0b;
            color: white;
            border-color: #f59e0b;
        }

        .btn-back {
            background: white;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 10px 24px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-back:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .header-buttons {
            display: flex;
            gap: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            background: #f8fafc;
            border-radius: 12px;
            color: #64748b;
            border: 2px dashed #cbd5e1;
        }

        .empty-state i {
            font-size: 3rem;
            color: #94a3b8;
            margin-bottom: 16px;
        }

        .empty-state h6 {
            font-size: 1rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }

        .alert {
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            padding: 14px 20px;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-danger {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .context-box {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            border-left: 4px solid var(--primary-light);
        }

        .context-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .context-item i {
            width: 20px;
            color: var(--primary-light);
        }

        .context-item a {
            color: var(--primary-light);
            text-decoration: none;
        }
        .context-item a:hover {
            text-decoration: underline;
        }

        .text-end {
            text-align: right;
        }

        .assignment-status {
            display: inline-block;
            margin-left: 8px;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .profile-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
                padding: 20px;
            }
            
            .profile-stats {
                width: 100%;
                justify-content: space-around;
                padding: 15px;
            }
            
            .info-grid {
                flex-direction: column;
                gap: 10px;
            }
            
            .header-buttons {
                flex-direction: column;
            }
            
            .status-edit-form {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Employee Profile</h2>
                <p>View employee details and assignment history</p>
            </div>
            <div class="header-buttons">
                <a href="edit_worker.php?id=<?php echo $worker['Worker_ID']; ?>" class="btn-edit">
                    <i class="fas fa-edit"></i> Edit Employee
                </a>
                <a href="assign_work.php?worker_id=<?php echo $worker['Worker_ID']; ?>" class="btn-edit" style="background: #e0f2fe; color: #0369a1; border-color: #7dd3fc;">
                    <i class="fas fa-briefcase"></i> Assign Work
                </a>
                <a href="manage_workers.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
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

            <!-- Employee Profile Card -->
            <div class="profile-card">
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($worker['Worker_Name']); ?></h1>
                    <div class="info-grid">
                        <div class="info-item">
                            <i class="fas fa-tag"></i>
                            <span>Designation: <strong><?php echo htmlspecialchars($worker['Designation'] ?: 'Not set'); ?></strong></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-phone"></i>
                            <span>Contact: <strong><?php echo htmlspecialchars($worker['Contact'] ?: 'Not provided'); ?></strong></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Salary: <strong>৳<?php echo number_format($worker['Worker_Salary'], 0); ?>/mo</strong></span>
                        </div>
                    </div>
                </div>
                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $current_count; ?></div>
                        <div class="stat-label">Current</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $history_count; ?></div>
                        <div class="stat-label">History</div>
                    </div>
                </div>
            </div>

            <!-- Current Assignments (Active + Paused) -->
            <div class="content-section">
                <div class="section-header">
                    <h3>
                        <i class="fas fa-briefcase" style="color: var(--success);"></i>
                        Current Assignments
                    </h3>
                    <span class="count-badge"><?php echo $current_count; ?> active</span>
                </div>
                
                <?php if($current_count > 0): ?>
                    <?php foreach($current_assignments as $index => $assignment): ?>
                        <?php
                        // Determine the ID field and view page based on assignment type
                        $view_page = '';
                        $id_value = '';
                        
                        if($assignment['Assignment_Type'] == 'project') {
                            $view_page = 'view_project.php';
                            $id_value = $assignment['Project_ID'];
                        } elseif($assignment['Assignment_Type'] == 'package') {
                            $view_page = 'view_package.php';
                            $id_value = $assignment['Package_ID'];
                        } else {
                            $view_page = 'view_maintenance.php';
                            $id_value = $assignment['Maintenance_ID'];
                        }
                        
                        // Determine card class
                        $card_class = ($assignment['Status'] == 'Active') ? 'current-active' : 'current-paused';
                        $participation_class = ($assignment['Status'] == 'Active') ? 'participation-active' : 'participation-paused';
                        $participation_icon = ($assignment['Status'] == 'Active') ? 'play-circle' : 'pause-circle';
                        
                        // Assignment status class
                        $assignment_status_class = '';
                        if($assignment['assignment_status'] == 'Not Started') {
                            $assignment_status_class = 'assignment-status-not-started';
                        } elseif($assignment['assignment_status'] == 'Active') {
                            $assignment_status_class = 'assignment-status-active';
                        } elseif($assignment['assignment_status'] == 'Paused') {
                            $assignment_status_class = 'assignment-status-paused';
                        } elseif($assignment['assignment_status'] == 'Completed') {
                            $assignment_status_class = 'assignment-status-completed';
                        } elseif($assignment['assignment_status'] == 'Cancelled') {
                            $assignment_status_class = 'assignment-status-cancelled';
                        }
                        ?>
                        <div class="assignment-card <?php echo $card_class; ?>">
                            <div class="assignment-header">
                                <span class="type-badge type-<?php echo $assignment['Assignment_Type']; ?>">
                                    <i class="fas fa-<?php 
                                        echo $assignment['Assignment_Type'] == 'project' ? 'folder-open' : 
                                            ($assignment['Assignment_Type'] == 'package' ? 'cubes' : 'tasks'); 
                                    ?>"></i>
                                    <?php echo ucfirst($assignment['Assignment_Type']); ?>
                                </span>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px;">Assignment status:</span>
                                    <span class="assignment-status-badge <?php echo $assignment_status_class; ?>">
                                        <?php echo $assignment['assignment_status']; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="assignment-title">
                                <a href="<?php echo $view_page; ?>?id=<?php echo $id_value; ?>">
                                    <?php echo htmlspecialchars($assignment['item_name']); ?>
                                </a>
                            </div>
                            
                            <!-- Bootstrap Row with centered columns -->
                            <div class="info-row">
                                <div class="row g-3">
                                    <div class="col-4 info-col">
                                        <div class="detail-label">Role</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($assignment['Role']); ?></div>
                                    </div>
                                    <div class="col-4 info-col">
                                        <div class="detail-label">Assigned</div>
                                        <div class="detail-value"><?php echo date('M d, Y', strtotime($assignment['Assigned_Date'])); ?></div>
                                    </div>
                                    <div class="col-4 info-col">
                                        <div class="detail-label">Participation</div>
                                        <div class="status-col">
                                            <!-- Status Display -->
                                            <div id="status-display-<?php echo $assignment['Assignment_ID']; ?>" class="status-display">
                                                <span class="participation-badge <?php echo $participation_class; ?>">
                                                    <i class="fas fa-<?php echo $participation_icon; ?>"></i>
                                                    <?php echo $assignment['Status']; ?>
                                                </span>
                                                <button type="button" class="edit-status-btn" onclick="showStatusEdit(<?php echo $assignment['Assignment_ID']; ?>)">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                            </div>
                                            
                                            <!-- Status Edit Form -->
                                            <div id="status-edit-<?php echo $assignment['Assignment_ID']; ?>" class="status-edit-form">
                                                <form method="POST" style="display: flex; gap: 4px; align-items: center;">
                                                    <input type="hidden" name="assignment_id" value="<?php echo $assignment['Assignment_ID']; ?>">
                                                    <select name="participation_status" class="form-select form-select-sm">
                                                        <option value="Active" <?php echo $assignment['Status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                                        <option value="Paused" <?php echo $assignment['Status'] == 'Paused' ? 'selected' : ''; ?>>Paused</option>
                                                        <option value="Completed">Completed</option>
                                                        <option value="Fired">Fired</option>
                                                    </select>
                                                    <button type="submit" name="update_participation_status" class="save-status-btn">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="cancel-status-btn" onclick="hideStatusEdit(<?php echo $assignment['Assignment_ID']; ?>)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Context Information -->
                            <?php if($assignment['Assignment_Type'] == 'maintenance' && $assignment['asset_name']): ?>
                            <div class="context-box">
                                <div class="context-item">
                                    <i class="fas fa-building"></i>
                                    <span><strong>Asset:</strong> <a href="view_asset.php?id=<?php echo $assignment['Asset_ID']; ?>"><?php echo htmlspecialchars($assignment['asset_name']); ?></a></span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($assignment['project_name'] && $assignment['Assignment_Type'] != 'project'): ?>
                            <div class="context-box">
                                <div class="context-item">
                                    <i class="fas fa-folder-open"></i>
                                    <span><strong>Project:</strong> <a href="view_project.php?id=<?php echo $assignment['Project_ID']; ?>"><?php echo htmlspecialchars($assignment['project_name']); ?></a></span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($assignment['package_name'] && $assignment['Assignment_Type'] == 'maintenance'): ?>
                            <div class="context-box">
                                <div class="context-item">
                                    <i class="fas fa-cube"></i>
                                    <span><strong>Package:</strong> <a href="view_package.php?id=<?php echo $assignment['Package_ID']; ?>"><?php echo htmlspecialchars($assignment['package_name']); ?></a></span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="text-end mt-3">
                                <a href="<?php echo $view_page; ?>?id=<?php echo $id_value; ?>" class="btn-view">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-briefcase"></i>
                        <h6>No Current Assignments</h6>
                        <p class="text-muted small">This employee has no active or paused assignments.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- History (Completed, Fired, or Assignment Ended) -->
            <div class="content-section">
                <div class="section-header">
                    <h3>
                        <i class="fas fa-history" style="color: var(--info);"></i>
                        Assignment History
                    </h3>
                    <span class="count-badge"><?php echo $history_count; ?> records</span>
                </div>
                
                <?php if($history_count > 0): ?>
                    <?php foreach($history_assignments as $assignment): ?>
                        <?php
                        // Determine the ID field and view page based on assignment type
                        $view_page = '';
                        $id_value = '';
                        
                        if($assignment['Assignment_Type'] == 'project') {
                            $view_page = 'view_project.php';
                            $id_value = $assignment['Project_ID'];
                        } elseif($assignment['Assignment_Type'] == 'package') {
                            $view_page = 'view_package.php';
                            $id_value = $assignment['Package_ID'];
                        } else {
                            $view_page = 'view_maintenance.php';
                            $id_value = $assignment['Maintenance_ID'];
                        }
                        
                        // Determine card class and participation class
                        $card_class = '';
                        $participation_class = '';
                        $participation_icon = '';
                        
                        if($assignment['Status'] == 'Completed') {
                            $card_class = 'history-completed';
                            $participation_class = 'participation-completed';
                            $participation_icon = 'check-circle';
                        } elseif($assignment['Status'] == 'Fired') {
                            $card_class = 'history-fired';
                            $participation_class = 'participation-fired';
                            $participation_icon = 'exclamation-circle';
                        } else {
                            // Worker status is Active/Paused but assignment is completed/cancelled
                            $card_class = 'history-assignment-ended';
                            $participation_class = $assignment['Status'] == 'Active' ? 'participation-active' : 'participation-paused';
                            $participation_icon = $assignment['Status'] == 'Active' ? 'play-circle' : 'pause-circle';
                        }
                        
                        // Assignment status badge class
                        $assignment_status_class = '';
                        if($assignment['assignment_status'] == 'Not Started') {
                            $assignment_status_class = 'assignment-status-not-started';
                        } elseif($assignment['assignment_status'] == 'Active') {
                            $assignment_status_class = 'assignment-status-active';
                        } elseif($assignment['assignment_status'] == 'Paused') {
                            $assignment_status_class = 'assignment-status-paused';
                        } elseif($assignment['assignment_status'] == 'Completed') {
                            $assignment_status_class = 'assignment-status-completed';
                        } elseif($assignment['assignment_status'] == 'Cancelled') {
                            $assignment_status_class = 'assignment-status-cancelled';
                        }
                        ?>
                        <div class="assignment-card <?php echo $card_class; ?>">
                            <div class="assignment-header">
                                <span class="type-badge type-<?php echo $assignment['Assignment_Type']; ?>">
                                    <i class="fas fa-<?php 
                                        echo $assignment['Assignment_Type'] == 'project' ? 'folder-open' : 
                                            ($assignment['Assignment_Type'] == 'package' ? 'cubes' : 'tasks'); 
                                    ?>"></i>
                                    <?php echo ucfirst($assignment['Assignment_Type']); ?>
                                </span>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px;">Assignment status:</span>
                                    <span class="assignment-status-badge <?php echo $assignment_status_class; ?>">
                                        <?php echo $assignment['assignment_status']; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="assignment-title">
                                <a href="<?php echo $view_page; ?>?id=<?php echo $id_value; ?>">
                                    <?php echo htmlspecialchars($assignment['item_name']); ?>
                                </a>
                            </div>
                            
                            <!-- Bootstrap Row with centered columns -->
                            <div class="info-row">
                                <div class="row g-3">
                                    <div class="col-4 info-col">
                                        <div class="detail-label">Role</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($assignment['Role']); ?></div>
                                    </div>
                                    <div class="col-4 info-col">
                                        <div class="detail-label">Assigned</div>
                                        <div class="detail-value"><?php echo date('M d, Y', strtotime($assignment['Assigned_Date'])); ?></div>
                                    </div>
                                    <div class="col-4 info-col">
                                        <div class="detail-label">Participation</div>
                                        <div class="status-col">
                                            <div class="status-display">
                                                <span class="participation-badge <?php echo $participation_class; ?>">
                                                    <i class="fas fa-<?php echo $participation_icon; ?>"></i>
                                                    <?php echo $assignment['Status']; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-end mt-3">
                                <a href="<?php echo $view_page; ?>?id=<?php echo $id_value; ?>" class="btn-view">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h6>No History</h6>
                        <p class="text-muted small">This employee has no completed, fired, or ended assignments.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function showStatusEdit(assignmentId) {
        document.getElementById('status-display-' + assignmentId).style.display = 'none';
        document.getElementById('status-edit-' + assignmentId).style.display = 'flex';
    }
    
    function hideStatusEdit(assignmentId) {
        document.getElementById('status-display-' + assignmentId).style.display = 'flex';
        document.getElementById('status-edit-' + assignmentId).style.display = 'none';
    }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>