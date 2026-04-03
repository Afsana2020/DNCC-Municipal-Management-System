<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

// Get worker ID from URL
$worker_id = isset($_GET['worker_id']) ? intval($_GET['worker_id']) : 0;

if($worker_id == 0) {
    header("Location: manage_workers.php");
    exit();
}

// Get worker details (basic info only now)
$worker_query = "SELECT Worker_Name FROM workers WHERE Worker_ID = ?";
$stmt = $conn->prepare($worker_query);
if(!$stmt) {
    die("Worker query failed: " . $conn->error);
}
$stmt->bind_param("i", $worker_id);
$stmt->execute();
$worker_result = $stmt->get_result();

if($worker_result->num_rows === 0) {
    header("Location: manage_workers.php");
    exit();
}

$worker = $worker_result->fetch_assoc();
$stmt->close();

// Get worker's current active assignments (ALL of them for hierarchy checking) - FOR VIEW ONLY, NO END BUTTON
$current_assignments_query = "SELECT wa.*, 
                                     CASE 
                                        WHEN wa.Assignment_Type = 'project' THEN pr.Project_Name
                                        WHEN wa.Assignment_Type = 'package' THEN pk.Package_Name
                                        WHEN wa.Assignment_Type = 'maintenance' THEN m.Task_type
                                     END as item_name,
                                     pr.Project_ID,
                                     pr.Project_Name as project_name,
                                     pk.Package_ID,
                                     pk.Package_Name as package_name,
                                     pk.Project_ID as package_project_id,
                                     m.Project_ID as task_project_id,
                                     m.Package_ID as task_package_id,
                                     m.Maintenance_ID,
                                     a.Asset_ID,
                                     a.Asset_Name
                              FROM worker_assignments wa
                              LEFT JOIN projects pr ON wa.Project_ID = pr.Project_ID AND wa.Assignment_Type = 'project'
                              LEFT JOIN packages pk ON wa.Package_ID = pk.Package_ID AND wa.Assignment_Type = 'package'
                              LEFT JOIN maintenance m ON wa.Maintenance_ID = m.Maintenance_ID AND wa.Assignment_Type = 'maintenance'
                              LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID
                              WHERE wa.Worker_ID = ? AND wa.Status = 'Active'
                              ORDER BY wa.Assigned_Date DESC";
$stmt = $conn->prepare($current_assignments_query);
if(!$stmt) {
    die("Current assignments query failed: " . $conn->error);
}
$stmt->bind_param("i", $worker_id);
$stmt->execute();
$current_assignments_result = $stmt->get_result();
$all_assignments = [];
while($row = $current_assignments_result->fetch_assoc()) {
    $all_assignments[] = $row;
}
$stmt->close();

// For display, we need a separate result set - FOR VIEW ONLY, NO END BUTTON
$display_query = "SELECT wa.*, 
                         CASE 
                            WHEN wa.Assignment_Type = 'project' THEN pr.Project_Name
                            WHEN wa.Assignment_Type = 'package' THEN pk.Package_Name
                            WHEN wa.Assignment_Type = 'maintenance' THEN m.Task_type
                         END as item_name,
                         pr.Project_ID,
                         pk.Package_ID,
                         m.Maintenance_ID
                  FROM worker_assignments wa
                  LEFT JOIN projects pr ON wa.Project_ID = pr.Project_ID AND wa.Assignment_Type = 'project'
                  LEFT JOIN packages pk ON wa.Package_ID = pk.Package_ID AND wa.Assignment_Type = 'package'
                  LEFT JOIN maintenance m ON wa.Maintenance_ID = m.Maintenance_ID AND wa.Assignment_Type = 'maintenance'
                  WHERE wa.Worker_ID = ? AND wa.Status = 'Active'
                  ORDER BY wa.Assigned_Date DESC";
$stmt = $conn->prepare($display_query);
if(!$stmt) {
    die("Display query failed: " . $conn->error);
}
$stmt->bind_param("i", $worker_id);
$stmt->execute();
$current_assignments = $stmt->get_result();
$stmt->close();

// Determine current step
$step = isset($_GET['step']) ? $_GET['step'] : 'level';

// Get selected level
$selected_level = isset($_GET['level']) ? $_GET['level'] : '';

// Get selected item details (for step 3)
$selected_type = isset($_GET['selected_type']) ? $_GET['selected_type'] : '';
$selected_id = isset($_GET['selected_id']) ? intval($_GET['selected_id']) : 0;
$selected_name = isset($_GET['selected_name']) ? urldecode($_GET['selected_name']) : '';

// Get items based on selected level - NO FILTERS, SHOW ALL
$items_result = null;
$item_count = 0;

if($step == 'select' && $selected_level) {
    if($selected_level == 'project') {
        $items_query = "SELECT Project_ID as id, Project_Name as name, Project_Type as type, Status as status 
                        FROM projects 
                        WHERE Status != 'Completed'
                        ORDER BY Created_At DESC";
        $items_result = $conn->query($items_query);
        if($items_result) {
            $item_count = $items_result->num_rows;
        }
        
    } elseif($selected_level == 'package') {
        $items_query = "SELECT p.Package_ID as id, p.Package_Name as name, p.Status as status,
                               pr.Project_Name, pr.Project_ID,
                               (SELECT COUNT(*) FROM maintenance WHERE Package_ID = p.Package_ID AND Status IN ('Active', 'Paused')) as active_tasks
                        FROM packages p
                        JOIN projects pr ON p.Project_ID = pr.Project_ID
                        WHERE p.Status != 'Completed'
                        ORDER BY pr.Project_Name, p.Package_Name";
        $items_result = $conn->query($items_query);
        if($items_result) {
            $item_count = $items_result->num_rows;
        }
        
    } elseif($selected_level == 'maintenance') {
        $items_query = "SELECT m.Maintenance_ID as id, m.Task_type as name, m.Status as status,
                               a.Asset_Name, a.Asset_Type, a.Asset_ID,
                               p.Package_Name, p.Package_ID,
                               pr.Project_Name, pr.Project_ID
                        FROM maintenance m
                        JOIN assets a ON m.Asset_ID = a.Asset_ID
                        LEFT JOIN packages p ON m.Package_ID = p.Package_ID
                        LEFT JOIN projects pr ON m.Project_ID = pr.Project_ID
                        WHERE m.Status IN ('Not Started', 'Active', 'Paused')
                        ORDER BY m.Start_Date DESC";
        $items_result = $conn->query($items_query);
        if($items_result) {
            $item_count = $items_result->num_rows;
        }
    }
}

// Handle final assignment
if(isset($_POST['assign_work'])) {
    $assignment_type = $_POST['assignment_type'];
    $project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
    $package_id = !empty($_POST['package_id']) ? $_POST['package_id'] : null;
    $maintenance_id = !empty($_POST['maintenance_id']) ? $_POST['maintenance_id'] : null;
    
    // Get role from form
    $role = $_POST['role'];
    
    // Handle custom role if "Other" was selected
    if($role == 'Other' && !empty($_POST['custom_role'])) {
        $role = trim($_POST['custom_role']);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Check for EXACT duplicate before inserting
        $check_query = "SELECT COUNT(*) as count FROM worker_assignments 
                       WHERE Worker_ID = ? AND Status = 'Active' AND Assignment_Type = ? AND ";
        
        if($project_id) {
            $check_query .= "Project_ID = ?";
            $check_stmt = $conn->prepare($check_query);
            if(!$check_stmt) {
                throw new Exception("Check query prepare failed: " . $conn->error);
            }
            $check_stmt->bind_param("isi", $worker_id, $assignment_type, $project_id);
        } elseif($package_id) {
            $check_query .= "Package_ID = ?";
            $check_stmt = $conn->prepare($check_query);
            if(!$check_stmt) {
                throw new Exception("Check query prepare failed: " . $conn->error);
            }
            $check_stmt->bind_param("isi", $worker_id, $assignment_type, $package_id);
        } elseif($maintenance_id) {
            $check_query .= "Maintenance_ID = ?";
            $check_stmt = $conn->prepare($check_query);
            if(!$check_stmt) {
                throw new Exception("Check query prepare failed: " . $conn->error);
            }
            $check_stmt->bind_param("isi", $worker_id, $assignment_type, $maintenance_id);
        } else {
            throw new Exception("No valid ID provided");
        }
        
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if($check_row['count'] > 0) {
            throw new Exception("This employee is already assigned to this specific work");
        }
        
        // Insert new assignment
        $insert_query = "INSERT INTO worker_assignments 
                         (Worker_ID, Assignment_Type, Project_ID, Package_ID, Maintenance_ID, Role, Status, Assigned_Date)
                         VALUES (?, ?, ?, ?, ?, ?, 'Active', NOW())";
        
        $stmt = $conn->prepare($insert_query);
        if(!$stmt) {
            throw new Exception("Insert query prepare failed: " . $conn->error);
        }
        $stmt->bind_param("isiiis", 
            $worker_id,
            $assignment_type,
            $project_id, 
            $package_id, 
            $maintenance_id,
            $role
        );
        $stmt->execute();
        $new_assignment_id = $conn->insert_id;
        $stmt->close();
        
        // Handle special roles for all levels
        if($role == 'Project Director' && $project_id) {
            // Clear any existing Project Director for this project
            $clear_query = "UPDATE projects SET Project_Director = NULL WHERE Project_ID = ?";
            $clear_stmt = $conn->prepare($clear_query);
            if(!$clear_stmt) {
                throw new Exception("Clear director query failed: " . $conn->error);
            }
            $clear_stmt->bind_param("i", $project_id);
            $clear_stmt->execute();
            $clear_stmt->close();
            
            // Set new Project Director (store worker name)
            $update_project = "UPDATE projects SET Project_Director = ? WHERE Project_ID = ?";
            $proj_stmt = $conn->prepare($update_project);
            if(!$proj_stmt) {
                throw new Exception("Update project query failed: " . $conn->error);
            }
            $proj_stmt->bind_param("si", $worker['Worker_Name'], $project_id);
            $proj_stmt->execute();
            $proj_stmt->close();
        }
        
        if($role == 'Team Leader' && $package_id) {
            // Clear any existing Team Leader for this package
            $clear_query = "UPDATE packages SET Team_Leader = NULL WHERE Package_ID = ?";
            $clear_stmt = $conn->prepare($clear_query);
            if(!$clear_stmt) {
                throw new Exception("Clear leader query failed: " . $conn->error);
            }
            $clear_stmt->bind_param("i", $package_id);
            $clear_stmt->execute();
            $clear_stmt->close();
            
            // Set new Team Leader (store worker name)
            $update_package = "UPDATE packages SET Team_Leader = ? WHERE Package_ID = ?";
            $pkg_stmt = $conn->prepare($update_package);
            if(!$pkg_stmt) {
                throw new Exception("Update package query failed: " . $conn->error);
            }
            $pkg_stmt->bind_param("si", $worker['Worker_Name'], $package_id);
            $pkg_stmt->execute();
            $pkg_stmt->close();
        }
        
        // Handle Team Leader for maintenance tasks
        if($role == 'Team Leader' && $maintenance_id) {
            // Tasks can also have team leaders - update maintenance table if needed
            // Note: maintenance table doesn't have Team_Leader column, so we just store in worker_assignments
            // This is handled by the role field in worker_assignments
        }
        
        $conn->commit();
        header("Location: view_worker.php?id=$worker_id&success=worker_assigned");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error assigning work: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Work - <?php echo htmlspecialchars($worker['Worker_Name']); ?></title>
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

        .title-section h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0 0 4px 0;
        }

        .title-section p {
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

        /* Worker Info Card - Simplified */
        .worker-info-card {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            border-radius: 12px;
            padding: 20px 28px;
            margin-bottom: 24px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .worker-info-card h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
            color: white;
        }

        .worker-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Current Assignments Section - VIEW ONLY */
        .current-assignments {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }

        .assignment-item {
            display: flex;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #edf2f7;
            gap: 15px;
        }

        .assignment-item:last-child {
            border-bottom: none;
        }

        .assignment-type-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 80px;
            text-align: center;
        }

        .type-project { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .type-package { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; }
        .type-maintenance { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }

        .status-active { color: #38a169; font-weight: 600; }
        .status-completed { color: #718096; }

        .view-link {
            color: #3182ce;
            text-decoration: none;
            font-weight: 500;
            margin-left: auto;
        }
        .view-link:hover {
            text-decoration: underline;
        }

        /* Step Indicator */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 30px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            border-radius: 30px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #718096;
        }

        .step.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .step.completed {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .step-number {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        /* Level Selection Cards */
        .level-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            max-width: 900px;
            margin: 40px auto;
        }

        .level-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .level-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .level-card.project:hover { border-color: #3182ce; }
        .level-card.package:hover { border-color: #805ad5; }
        .level-card.maintenance:hover { border-color: #38a169; }

        .level-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
        }

        .level-card h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .level-card p {
            color: #718096;
            margin: 0;
            font-size: 0.9rem;
        }

        /* Items List - Full width cards with view button */
        .items-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 20px;
        }

        .item-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .item-card:hover {
            background: #f8fafc;
            border-color: var(--accent);
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
        }

        .item-card.already-assigned {
            border-color: #fbbf24;
            background: #fef3c7;
            opacity: 0.9;
        }

        .item-card.already-assigned:hover {
            border-color: #f59e0b;
            background: #feebc8;
        }

        .item-content {
            flex: 1;
            min-width: 300px;
        }

        .item-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: #1a202c;
        }

        .item-details {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
        }

        .detail-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f8fafc;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            color: #2d3748;
            border: 1px solid #e2e8f0;
        }

        .detail-badge i {
            color: #718096;
        }

        .item-footer {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .level-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .level-project { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .level-package { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; }
        .level-maintenance { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-not-started { background: #e2e8f0; color: #2d3748; border: 1px solid #cbd5e0; }
        .status-active { background: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
        .status-paused { background: #fed7aa; color: #92400e; border: 1px solid #fdba74; }

        /* Action Buttons Group */
        .item-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-shrink: 0;
        }

        .btn-view-item {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: white;
            border: 1px solid #cbd5e0;
            color: #4a5568;
            transition: all 0.2s;
        }
        .btn-view-item:hover {
            background: #e2e8f0;
            border-color: #a0aec0;
        }

        .btn-select-item {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--accent);
            color: white;
            border: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        .btn-select-item:hover {
            background: #2c5282;
        }
        .btn-select-item:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
        }

        .duplicate-warning-badge {
            background: #fbbf24;
            color: #92400e;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        /* Assignment Form - Simplified */
        .assignment-form-container {
            background: white;
            border-radius: 16px;
            padding: 30px;
            border: 1px solid #e2e8f0;
            max-width: 600px;
            margin: 0 auto;
        }

        .selected-work-banner {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .selected-work-banner i {
            font-size: 2rem;
            color: var(--success);
        }

        .selected-work-info h3 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 4px;
            color: #1a202c;
        }

        .selected-work-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        /* Role Form - Simplified */
        .role-form {
            background: #f8fafc;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e2e8f0;
        }

        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
            font-size: 0.8rem;
        }

        .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
            width: 100%;
        }
        .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .role-warning {
            background: #fef3c7;
            border-left: 4px solid #d97706;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.85rem;
        }

        .custom-field {
            margin-top: 10px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 3px solid var(--accent);
        }

        .custom-field input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .modal-header {
            border-radius: 16px 16px 0 0;
            padding: 20px 24px;
            border-bottom: none;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        .modal-header .btn-close:hover {
            opacity: 1;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 0 0 16px 16px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .assignment-item-modal {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
        }
        .assignment-item-modal:hover {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .hierarchy-link {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        .hierarchy-link:hover {
            text-decoration: underline;
        }

        .hierarchy-link.project-link { color: #3182ce; }
        .hierarchy-link.package-link { color: #805ad5; }
        .hierarchy-link.task-link { color: #38a169; }
        .hierarchy-link.asset-link { color: #e53e3e; }

        .id-badge {
            background: #e2e8f0;
            color: #2d3748;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .level-grid {
                grid-template-columns: 1fr;
                padding: 0 20px;
            }
            
            .step-indicator {
                flex-direction: column;
                align-items: stretch;
            }
            
            .item-card {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .item-actions {
                width: 100%;
                justify-content: flex-end;
            }
            
            .item-details {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <div class="title-section">
                    <h2>Assign Work</h2>
                    <p>
                        <?php 
                        if($step == 'level') echo "Select assignment level for " . htmlspecialchars($worker['Worker_Name']);
                        elseif($step == 'select') echo "Select " . ucfirst($selected_level) . " for " . htmlspecialchars($worker['Worker_Name']);
                        else echo "Set role for selected work";
                        ?>
                    </p>
                </div>
                <div>
                    <?php if($step == 'select'): ?>
                        <a href="assign_work.php?worker_id=<?php echo $worker_id; ?>" class="btn-back">
                            <i class="fas fa-arrow-left me-1"></i>Back to Levels
                        </a>
                    <?php elseif($step == 'assign'): ?>
                        <a href="assign_work.php?worker_id=<?php echo $worker_id; ?>&step=select&level=<?php echo $selected_level; ?>" class="btn-back">
                            <i class="fas fa-arrow-left me-1"></i>Back to Selection
                        </a>
                    <?php else: ?>
                        <a href="view_worker.php?id=<?php echo $worker_id; ?>" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back to Employee
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="content-area">
            
            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <!-- Worker Info Banner - Simplified -->
            <div class="worker-info-card">
                <div>
                    <h3><?php echo htmlspecialchars($worker['Worker_Name']); ?></h3>
                </div>
                <div>
                    <span class="worker-badge">
                        <i class="fas fa-user-tie me-1"></i>
                        Employee #<?php echo $worker_id; ?>
                    </span>
                </div>
            </div>

            <!-- Current Active Assignments - VIEW ONLY (NO END BUTTON) -->
            <?php if($current_assignments && $current_assignments->num_rows > 0): ?>
            <div class="current-assignments">
                <h5 class="mb-3"><i class="fas fa-briefcase me-2"></i>Current Active Assignments</h5>
                <?php while($assignment = $current_assignments->fetch_assoc()): ?>
                    <div class="assignment-item">
                        <span class="assignment-type-badge type-<?php echo $assignment['Assignment_Type']; ?>">
                            <?php echo ucfirst($assignment['Assignment_Type']); ?>
                        </span>
                        <div>
                            <strong><?php echo htmlspecialchars($assignment['item_name']); ?></strong>
                            <br>
                            <small class="text-muted">Role: <?php echo htmlspecialchars($assignment['Role']); ?></small>
                        </div>
                        <span class="status-<?php echo strtolower($assignment['Status']); ?> ms-auto">
                            <i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i>
                            <?php echo $assignment['Status']; ?>
                        </span>
                        <?php 
                        $view_link = '';
                        if($assignment['Assignment_Type'] == 'project') {
                            $view_link = 'view_project.php?id=' . $assignment['Project_ID'];
                        } elseif($assignment['Assignment_Type'] == 'package') {
                            $view_link = 'view_package.php?id=' . $assignment['Package_ID'];
                        } elseif($assignment['Assignment_Type'] == 'maintenance') {
                            $view_link = 'view_maintenance.php?id=' . $assignment['Maintenance_ID'];
                        }
                        ?>
                        <a href="<?php echo $view_link; ?>" class="view-link" target="_blank">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?php echo $step == 'level' ? 'active' : ($step != 'level' ? 'completed' : ''); ?>">
                    <span class="step-number">1</span>
                    <span>Choose Level</span>
                </div>
                <i class="fas fa-chevron-right" style="color: #cbd5e0;"></i>
                <div class="step <?php echo $step == 'select' ? 'active' : ($step == 'assign' ? 'completed' : ''); ?>">
                    <span class="step-number">2</span>
                    <span>Select Work</span>
                </div>
                <i class="fas fa-chevron-right" style="color: #cbd5e0;"></i>
                <div class="step <?php echo $step == 'assign' ? 'active' : ''; ?>">
                    <span class="step-number">3</span>
                    <span>Set Role</span>
                </div>
            </div>

            <?php if($step == 'level'): ?>
                <!-- STEP 1: SELECT LEVEL -->
                <div class="level-grid">
                    <a href="assign_work.php?worker_id=<?php echo $worker_id; ?>&step=select&level=project" class="level-card project">
                        <div class="level-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-folder-open"></i>
                        </div>
                        <h3>Project Level</h3>
                        <p>Assign to an entire project</p>
                    </a>

                    <a href="assign_work.php?worker_id=<?php echo $worker_id; ?>&step=select&level=package" class="level-card package">
                        <div class="level-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <h3>Package Level</h3>
                        <p>Assign to a specific package</p>
                    </a>

                    <a href="assign_work.php?worker_id=<?php echo $worker_id; ?>&step=select&level=maintenance" class="level-card maintenance">
                        <div class="level-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <h3>Task Level</h3>
                        <p>Assign to a maintenance task</p>
                    </a>
                </div>

            <?php elseif($step == 'select' && $selected_level): ?>
                <!-- STEP 2: SELECT SPECIFIC ITEM - WITH MODAL WARNINGS ON SELECT BUTTON -->

                <!-- Items List - Full width cards with view button -->
                <?php if($items_result && $item_count > 0): ?>
                    <div class="section-header">
                        <h4><?php echo ucfirst($selected_level); ?>s Available</h4>
                        <span class="count-badge"><?php echo $item_count; ?> found</span>
                    </div>
                    
                    <div class="items-list">
                        <?php while($item = $items_result->fetch_assoc()): ?>
                            <?php
                            $view_url = '';
                            if($selected_level == 'project') {
                                $view_url = 'view_project.php?id=' . $item['id'];
                            } elseif($selected_level == 'package') {
                                $view_url = 'view_package.php?id=' . $item['id'];
                            } elseif($selected_level == 'maintenance') {
                                $view_url = 'view_maintenance.php?id=' . $item['id'];
                            }
                            
                            // Check if worker is already assigned to this specific item
                            $already_assigned = false;
                            $check_query = "SELECT COUNT(*) as count FROM worker_assignments 
                                           WHERE Worker_ID = ? AND Status = 'Active' AND ";
                            
                            if($selected_level == 'project') {
                                $check_query .= "Project_ID = ? AND Assignment_Type = 'project'";
                                $stmt = $conn->prepare($check_query);
                                if($stmt) {
                                    $stmt->bind_param("ii", $worker_id, $item['id']);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    $row = $result->fetch_assoc();
                                    $already_assigned = ($row['count'] > 0);
                                    $stmt->close();
                                }
                            } elseif($selected_level == 'package') {
                                $check_query .= "Package_ID = ? AND Assignment_Type = 'package'";
                                $stmt = $conn->prepare($check_query);
                                if($stmt) {
                                    $stmt->bind_param("ii", $worker_id, $item['id']);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    $row = $result->fetch_assoc();
                                    $already_assigned = ($row['count'] > 0);
                                    $stmt->close();
                                }
                            } elseif($selected_level == 'maintenance') {
                                $check_query .= "Maintenance_ID = ? AND Assignment_Type = 'maintenance'";
                                $stmt = $conn->prepare($check_query);
                                if($stmt) {
                                    $stmt->bind_param("ii", $worker_id, $item['id']);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    $row = $result->fetch_assoc();
                                    $already_assigned = ($row['count'] > 0);
                                    $stmt->close();
                                }
                            }
                            
                            $select_url = "assign_work.php?worker_id=$worker_id&step=assign&selected_type=$selected_level&selected_id=".$item['id']."&selected_name=".urlencode($item['name']);
                            
                            // Get worker's current assignments for this level type
                            $same_level_assignments = [];
                            $same_level_query = "SELECT wa.*, 
                                                        CASE 
                                                            WHEN wa.Assignment_Type = 'project' THEN pr.Project_Name
                                                            WHEN wa.Assignment_Type = 'package' THEN pk.Package_Name
                                                            WHEN wa.Assignment_Type = 'maintenance' THEN m.Task_type
                                                        END as item_name,
                                                        pr.Project_ID,
                                                        pr.Project_Name as project_name,
                                                        pk.Package_ID,
                                                        pk.Package_Name as package_name,
                                                        pk.Project_ID as package_project_id,
                                                        m.Project_ID as task_project_id,
                                                        m.Package_ID as task_package_id,
                                                        m.Maintenance_ID,
                                                        a.Asset_ID,
                                                        a.Asset_Name
                                                 FROM worker_assignments wa
                                                 LEFT JOIN projects pr ON wa.Project_ID = pr.Project_ID AND wa.Assignment_Type = 'project'
                                                 LEFT JOIN packages pk ON wa.Package_ID = pk.Package_ID AND wa.Assignment_Type = 'package'
                                                 LEFT JOIN maintenance m ON wa.Maintenance_ID = m.Maintenance_ID AND wa.Assignment_Type = 'maintenance'
                                                 LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID
                                                 WHERE wa.Worker_ID = ? AND wa.Status = 'Active' AND wa.Assignment_Type = ?";
                            $stmt2 = $conn->prepare($same_level_query);
                            if($stmt2) {
                                $stmt2->bind_param("is", $worker_id, $selected_level);
                                $stmt2->execute();
                                $same_level_result = $stmt2->get_result();
                                while($row = $same_level_result->fetch_assoc()) {
                                    $same_level_assignments[] = $row;
                                }
                                $stmt2->close();
                            }
                            
                            // Check for hierarchy conflicts
                            $hierarchy_conflicts = [];
                            if($selected_level == 'project') {
                                foreach($all_assignments as $assignment) {
                                    // Check if worker is in any package under this project
                                    if($assignment['Assignment_Type'] == 'package' && $assignment['package_project_id'] == $item['id']) {
                                        $hierarchy_conflicts[] = $assignment;
                                    }
                                    // Check if worker is in any task under this project
                                    else if($assignment['Assignment_Type'] == 'maintenance' && $assignment['task_project_id'] == $item['id']) {
                                        $hierarchy_conflicts[] = $assignment;
                                    }
                                }
                            } elseif($selected_level == 'package') {
                                foreach($all_assignments as $assignment) {
                                    // Check if worker is in any task under this package
                                    if($assignment['Assignment_Type'] == 'maintenance' && $assignment['task_package_id'] == $item['id']) {
                                        $hierarchy_conflicts[] = $assignment;
                                    }
                                    // Check if worker is in the project that owns this package
                                    else if($assignment['Assignment_Type'] == 'project' && $assignment['Project_ID'] == $item['Project_ID']) {
                                        $hierarchy_conflicts[] = $assignment;
                                    }
                                }
                            } elseif($selected_level == 'maintenance') {
                                foreach($all_assignments as $assignment) {
                                    // Check if worker is in the package that owns this task
                                    if($assignment['Assignment_Type'] == 'package' && $assignment['Package_ID'] == $item['Package_ID']) {
                                        $hierarchy_conflicts[] = $assignment;
                                    }
                                    // Check if worker is in the project that owns this task
                                    else if($assignment['Assignment_Type'] == 'project' && $assignment['Project_ID'] == $item['Project_ID']) {
                                        $hierarchy_conflicts[] = $assignment;
                                    }
                                    // Check if worker is in any other task under the same package
                                    else if($assignment['Assignment_Type'] == 'maintenance' && $assignment['task_package_id'] == $item['Package_ID'] && $assignment['Maintenance_ID'] != $item['id']) {
                                        $hierarchy_conflicts[] = $assignment;
                                    }
                                }
                            }
                            ?>
                            
                            <div class="item-card <?php echo $selected_level; ?> <?php echo $already_assigned ? 'already-assigned' : ''; ?>" 
                                 data-item-id="<?php echo $item['id']; ?>"
                                 data-item-name="<?php echo htmlspecialchars($item['name']); ?>"
                                 data-item-level="<?php echo $selected_level; ?>"
                                 data-already-assigned="<?php echo $already_assigned ? '1' : '0'; ?>"
                                 data-same-level-assignments='<?php echo json_encode($same_level_assignments); ?>'
                                 data-hierarchy-conflicts='<?php echo json_encode($hierarchy_conflicts); ?>'
                                 data-select-url="<?php echo $select_url; ?>">
                                
                                <div class="item-content">
                                    <div class="item-title">
                                        <i class="fas fa-<?php 
                                            if($selected_level == 'project') echo 'folder';
                                            elseif($selected_level == 'package') echo 'cube';
                                            else echo 'tasks';
                                        ?> me-2" style="color: var(--<?php 
                                            if($selected_level == 'project') echo 'accent';
                                            elseif($selected_level == 'package') echo 'purple';
                                            else echo 'success';
                                        ?>);"></i>
                                        <?php echo htmlspecialchars($item['name']); ?>
                                        <?php if($already_assigned): ?>
                                            <span class="duplicate-warning-badge ms-2">Already Assigned</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="item-details">
                                        <?php if($selected_level == 'project'): ?>
                                            <span class="detail-badge">
                                                <i class="fas fa-tag"></i>
                                                Type: <?php echo $item['type']; ?>
                                            </span>
                                            <span class="detail-badge">
                                                <i class="fas fa-circle"></i>
                                                Status: <?php echo $item['status']; ?>
                                            </span>
                                        
                                        <?php elseif($selected_level == 'package'): ?>
                                            <span class="detail-badge">
                                                <i class="fas fa-folder-open"></i>
                                                Project: 
                                                <a href="view_project.php?id=<?php echo $item['Project_ID']; ?>" class="hierarchy-link project-link" target="_blank">
                                                    #<?php echo $item['Project_ID']; ?>
                                                </a>
                                            </span>
                                            <span class="detail-badge">
                                                <i class="fas fa-tasks"></i>
                                                Active Tasks: <?php echo $item['active_tasks']; ?>
                                            </span>
                                            <span class="detail-badge">
                                                <i class="fas fa-circle"></i>
                                                Status: <?php echo $item['status']; ?>
                                            </span>
                                        
                                        <?php elseif($selected_level == 'maintenance'): ?>
                                            <span class="detail-badge">
                                                <i class="fas fa-box"></i>
                                                Asset: 
                                                <a href="view_asset.php?id=<?php echo $item['Asset_ID']; ?>" class="hierarchy-link asset-link" target="_blank">
                                                    #<?php echo $item['Asset_ID']; ?>
                                                </a>
                                            </span>
                                            <?php if($item['Project_ID']): ?>
                                            <span class="detail-badge">
                                                <i class="fas fa-folder-open"></i>
                                                Project: 
                                                <a href="view_project.php?id=<?php echo $item['Project_ID']; ?>" class="hierarchy-link project-link" target="_blank">
                                                    #<?php echo $item['Project_ID']; ?>
                                                </a>
                                            </span>
                                            <?php endif; ?>
                                            <?php if($item['Package_ID']): ?>
                                            <span class="detail-badge">
                                                <i class="fas fa-cube"></i>
                                                Package: 
                                                <a href="view_package.php?id=<?php echo $item['Package_ID']; ?>" class="hierarchy-link package-link" target="_blank">
                                                    #<?php echo $item['Package_ID']; ?>
                                                </a>
                                            </span>
                                            <?php endif; ?>
                                            <span class="detail-badge">
                                                <i class="fas fa-circle"></i>
                                                Status: <?php echo $item['status']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="item-footer">
                                        <span class="level-indicator level-<?php echo $selected_level; ?>">
                                            <i class="fas fa-<?php 
                                                if($selected_level == 'project') echo 'folder-open';
                                                elseif($selected_level == 'package') echo 'cubes';
                                                else echo 'tasks';
                                            ?> me-1"></i>
                                            <?php echo ucfirst($selected_level); ?> Level
                                        </span>
                                        
                                        <?php if($selected_level == 'maintenance'): ?>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $item['status'])); ?>">
                                                <?php echo $item['status']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="item-actions" onclick="event.stopPropagation()">
                                    <a href="<?php echo $view_url; ?>" class="btn-view-item" target="_blank">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if(!$already_assigned): ?>
                                        <button class="btn-select-item" onclick="handleSelectClick(this, '<?php echo $select_url; ?>', <?php echo htmlspecialchars(json_encode($same_level_assignments)); ?>, <?php echo htmlspecialchars(json_encode($hierarchy_conflicts)); ?>, '<?php echo $selected_level; ?>')">
                                            <i class="fas fa-check-circle"></i> Select
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-select-item" style="background: #cbd5e0; cursor: not-allowed;" disabled>
                                            <i class="fas fa-ban"></i> Assigned
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-<?php 
                            if($selected_level == 'project') echo 'folder-open';
                            elseif($selected_level == 'package') echo 'cubes';
                            else echo 'tasks';
                        ?>"></i>
                        <h5>No <?php echo ucfirst($selected_level); ?>s Found</h5>
                        <p class="text-muted">No available items found for this level.</p>
                    </div>
                <?php endif; ?>

            <?php elseif($step == 'assign' && $selected_type && $selected_id): ?>
                <!-- STEP 3: SET ROLE - SIMPLIFIED -->
                
                <?php
                // Get current holder information based on selected type
                $current_holder = null;
                $current_holder_name = '';
                $role_warning = '';
                
                if($selected_type == 'project') {
                    $proj_query = "SELECT Project_Director FROM projects WHERE Project_ID = ?";
                    $stmt = $conn->prepare($proj_query);
                    if($stmt) {
                        $stmt->bind_param("i", $selected_id);
                        $stmt->execute();
                        $proj_result = $stmt->get_result();
                        $project_data = $proj_result->fetch_assoc();
                        if($project_data && !empty($project_data['Project_Director'])) {
                            $current_holder = 'Project Director';
                            $current_holder_name = $project_data['Project_Director'];
                            $role_warning = "This project already has a Project Director. Assigning a new one will replace the current director.";
                        }
                        $stmt->close();
                    }
                } elseif($selected_type == 'package') {
                    $pkg_query = "SELECT Team_Leader FROM packages WHERE Package_ID = ?";
                    $stmt = $conn->prepare($pkg_query);
                    if($stmt) {
                        $stmt->bind_param("i", $selected_id);
                        $stmt->execute();
                        $pkg_result = $stmt->get_result();
                        $package_data = $pkg_result->fetch_assoc();
                        if($package_data && !empty($package_data['Team_Leader'])) {
                            $current_holder = 'Team Leader';
                            $current_holder_name = $package_data['Team_Leader'];
                            $role_warning = "This package already has a Team Leader. Assigning a new one will replace the current leader.";
                        }
                        $stmt->close();
                    }
                } elseif($selected_type == 'maintenance') {
                    // Maintenance tasks don't have a Team Leader field in the maintenance table
                    // But they can have Team Leader role in worker_assignments
                    $current_holder = null;
                }
                ?>
                
                <div class="assignment-form-container">
                    <!-- Selected Work Banner -->
                    <div class="selected-work-banner">
                        <i class="fas fa-check-circle"></i>
                        <div class="selected-work-info">
                            <h3><?php echo htmlspecialchars($selected_name); ?></h3>
                            <p>
                                <span class="selected-work-badge" style="background: <?php 
                                    if($selected_type == 'project') echo '#ebf8ff; color: #2c5282; border: 1px solid #90cdf4;';
                                    elseif($selected_type == 'package') echo '#faf5ff; color: #6b46c1; border: 1px solid #d6bcfa;';
                                    else echo '#f0fff4; color: #276749; border: 1px solid #9ae6b4;';
                                ?>">
                                    <?php 
                                    if($selected_type == 'project') echo 'Project Level';
                                    elseif($selected_type == 'package') echo 'Package Level';
                                    else echo 'Task Level';
                                    ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Current Holder Warning -->
                    <?php if($current_holder): ?>
                        <div class="role-warning">
                            <i class="fas fa-exclamation-triangle me-2" style="color: #d97706;"></i>
                            <strong>Current <?php echo $current_holder; ?>:</strong> <?php echo htmlspecialchars($current_holder_name); ?>
                            <br>
                            <small><?php echo $role_warning; ?></small>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="assignment_type" value="<?php echo $selected_type; ?>">
                        
                        <?php if($selected_type == 'project'): ?>
                            <input type="hidden" name="project_id" value="<?php echo $selected_id; ?>">
                        <?php elseif($selected_type == 'package'): ?>
                            <input type="hidden" name="package_id" value="<?php echo $selected_id; ?>">
                            <?php
                            // Get project ID for this package
                            $proj_query = "SELECT Project_ID FROM packages WHERE Package_ID = ?";
                            $stmt = $conn->prepare($proj_query);
                            if($stmt) {
                                $stmt->bind_param("i", $selected_id);
                                $stmt->execute();
                                $proj_result = $stmt->get_result();
                                $proj_data = $proj_result->fetch_assoc();
                                if($proj_data) {
                                    echo '<input type="hidden" name="project_id" value="'.$proj_data['Project_ID'].'">';
                                }
                                $stmt->close();
                            }
                            ?>
                        <?php elseif($selected_type == 'maintenance'): ?>
                            <input type="hidden" name="maintenance_id" value="<?php echo $selected_id; ?>">
                            <?php
                            // Get package and project IDs for this task
                            $task_query = "SELECT Package_ID, Project_ID FROM maintenance WHERE Maintenance_ID = ?";
                            $stmt = $conn->prepare($task_query);
                            if($stmt) {
                                $stmt->bind_param("i", $selected_id);
                                $stmt->execute();
                                $task_result = $stmt->get_result();
                                $task_data = $task_result->fetch_assoc();
                                if($task_data) {
                                    if($task_data['Package_ID']) {
                                        echo '<input type="hidden" name="package_id" value="'.$task_data['Package_ID'].'">';
                                    }
                                    if($task_data['Project_ID']) {
                                        echo '<input type="hidden" name="project_id" value="'.$task_data['Project_ID'].'">';
                                    }
                                }
                                $stmt->close();
                            }
                            ?>
                        <?php endif; ?>

                        <!-- Role Form - Simplified -->
                        <div class="role-form">
                            <div class="mb-3">
                                <label class="form-label">Role for this Assignment *</label>
                                <select class="form-select" name="role" required>
                                    <option value="">Select Role</option>
                                    
                                    <?php if($selected_type == 'project'): ?>
                                        <!-- Project Level - Can be Project Director or any role -->
                                        <option value="Project Director">Project Director</option>
                                        <option value="Executive Engineer">Executive Engineer</option>
                                        <option value="Superintending Engineer">Superintending Engineer</option>
                                        <option value="Assistant Engineer">Assistant Engineer</option>
                                        <option value="Site Engineer">Site Engineer</option>
                                        <option value="Supervisor">Supervisor</option>
                                        <option value="Worker">Worker</option>
                                        
                                    <?php elseif($selected_type == 'package'): ?>
                                        <!-- Package Level - Can be Team Leader or any role -->
                                        <option value="Team Leader">Team Leader</option>
                                        <option value="Supervisor">Supervisor</option>
                                        <option value="Engineer">Engineer</option>
                                        <option value="Technician">Technician</option>
                                        <option value="Worker">Worker</option>
                                        
                                    <?php elseif($selected_type == 'maintenance'): ?>
                                        <!-- Task Level - Can also have Team Leader -->
                                        <option value="Team Leader">Team Leader</option>
                                        <option value="Supervisor">Supervisor</option>
                                        <option value="Technician">Technician</option>
                                        <option value="Worker">Worker</option>
                                        <option value="Helper">Helper</option>
                                        
                                    <?php endif; ?>
                                    
                                    <option value="Other">Other (Specify)</option>
                                </select>
                                
                                <!-- Custom role field -->
                                <div id="custom_role_field" class="custom-field" style="display: none;">
                                    <input type="text" class="form-control" name="custom_role" placeholder="Enter custom role name">
                                </div>
                                
                                <?php if($selected_type == 'project'): ?>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Selecting "Project Director" will update the project's director field
                                    </small>
                                <?php elseif($selected_type == 'package'): ?>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Selecting "Team Leader" will update the package's team leader field
                                    </small>
                                <?php elseif($selected_type == 'maintenance'): ?>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Selecting "Team Leader" will assign them as leader for this task
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <a href="assign_work.php?worker_id=<?php echo $worker_id; ?>&step=select&level=<?php echo $selected_type; ?>" class="btn btn-outline-secondary">
                                Back
                            </a>
                            <button type="submit" name="assign_work" class="btn btn-success" 
                                    onclick="return confirm('<?php echo $current_holder ? 'This will replace the current ' . $current_holder . '. ' : ''; ?>Proceed with assignment?')">
                                <i class="fas fa-check-circle me-1"></i>Confirm Assignment
                            </button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <!-- Worker Assignment Modal -->
    <div class="modal fade" id="workerAssignmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Employee Assignment Check
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
                </div>
                <div class="modal-body">
                    <!-- Duplicate Message (exact same assignment) -->
                    <div id="duplicateMessage" style="display: none;">
                        <div class="alert alert-warning" style="background: #fef3c7; border: 1px solid #fcd34d;">
                            <i class="fas fa-exclamation-circle me-2" style="color: #d97706;"></i>
                            <strong>Already Assigned:</strong> This employee is already assigned to this exact 
                            <span id="duplicateLevel"></span>. They cannot be assigned again to the same work.
                        </div>
                    </div>

                    <!-- Same Level Warning (different item in same level) -->
                    <div id="sameLevelWarning" style="display: none;">
                        <div class="alert alert-info" style="background: #e0f2fe; border: 1px solid #7dd3fc;">
                            <i class="fas fa-info-circle me-2" style="color: #0369a1;"></i>
                            <strong>Notice:</strong> This employee is already working on another 
                            <span id="sameLevelText"></span> at the same level.
                        </div>
                    </div>

                    <!-- Hierarchy Warning (different level but related) -->
                    <div id="hierarchyWarning" style="display: none;">
                        <div class="alert alert-warning" style="background: #fed7aa; border: 1px solid #fdba74;">
                            <i class="fas fa-sitemap me-2" style="color: #c2410c;"></i>
                            <strong>Hierarchy Notice:</strong> This employee is already working on the following under this project/package:
                        </div>
                    </div>

                    <h6 class="mb-3 mt-3" id="assignmentListTitle">Current Related Assignments:</h6>
                    <div id="currentAssignmentList" class="assignment-list"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="cancelBtn">Cancel</button>
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal" id="proceedAssignBtn" style="display: none;">
                        <i class="fas fa-check-circle"></i> Assign Anyway
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Show/hide custom role field
    document.querySelector('select[name="role"]')?.addEventListener('change', function() {
        const customField = document.getElementById('custom_role_field');
        if (this.value === 'Other') {
            customField.style.display = 'block';
        } else {
            customField.style.display = 'none';
        }
    });

    let pendingSelectUrl = '';

    function handleSelectClick(button, selectUrl, sameLevelAssignments, hierarchyConflicts, level) {
        // Prevent event bubbling
        event.stopPropagation();
        
        // Parse the data if they're strings
        if (typeof sameLevelAssignments === 'string') {
            sameLevelAssignments = JSON.parse(sameLevelAssignments);
        }
        if (typeof hierarchyConflicts === 'string') {
            hierarchyConflicts = JSON.parse(hierarchyConflicts);
        }
        
        // Reset modal display
        document.getElementById('duplicateMessage').style.display = 'none';
        document.getElementById('sameLevelWarning').style.display = 'none';
        document.getElementById('hierarchyWarning').style.display = 'none';
        document.getElementById('proceedAssignBtn').style.display = 'none';
        document.getElementById('assignmentListTitle').innerText = 'Current Related Assignments:';
        document.getElementById('assignmentListTitle').style.display = 'block';
        
        // Check for hierarchy conflicts first
        if (hierarchyConflicts && hierarchyConflicts.length > 0) {
            document.getElementById('hierarchyWarning').style.display = 'block';
            
            // Populate assignments list with clickable links (using IDs)
            let html = '';
            hierarchyConflicts.forEach(assignment => {
                let icon = '';
                let color = '';
                let typeText = '';
                let linkUrl = '';
                let linkClass = '';
                let id = '';
                
                if (assignment.Assignment_Type === 'project') {
                    icon = 'fa-folder-open';
                    color = '#3182ce';
                    typeText = 'Project';
                    id = assignment.Project_ID;
                    linkUrl = 'view_project.php?id=' + id;
                    linkClass = 'project-link';
                } else if (assignment.Assignment_Type === 'package') {
                    icon = 'fa-cubes';
                    color = '#805ad5';
                    typeText = 'Package';
                    id = assignment.Package_ID;
                    linkUrl = 'view_package.php?id=' + id;
                    linkClass = 'package-link';
                } else if (assignment.Assignment_Type === 'maintenance') {
                    icon = 'fa-tasks';
                    color = '#38a169';
                    typeText = 'Task';
                    id = assignment.Maintenance_ID;
                    linkUrl = 'view_maintenance.php?id=' + id;
                    linkClass = 'task-link';
                }
                
                html += `
                    <div class="assignment-item-modal">
                        <div class="d-flex gap-3">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(${color === '#3182ce' ? '49,130,206' : color === '#805ad5' ? '128,90,213' : '56,161,105'},0.1); display: flex; align-items: center; justify-content: center; color: ${color};">
                                <i class="fas ${icon}"></i>
                            </div>
                            <div>
                                <strong>${typeText}: <a href="${linkUrl}" class="hierarchy-link ${linkClass}" target="_blank">#${id}</a></strong>
                                <div style="font-size: 0.8rem; color: #64748b;">
                                    <span class="me-3"><i class="fas fa-tag me-1"></i>Role: ${assignment.Role}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('currentAssignmentList').innerHTML = html;
            document.getElementById('proceedAssignBtn').style.display = 'inline-flex';
            pendingSelectUrl = selectUrl;
            
            const modal = new bootstrap.Modal(document.getElementById('workerAssignmentModal'));
            modal.show();
            return;
        }
        
        // Check for same level assignments
        if (sameLevelAssignments && sameLevelAssignments.length > 0) {
            document.getElementById('sameLevelWarning').style.display = 'block';
            document.getElementById('sameLevelText').innerText = level;
            
            // Populate assignments list with clickable links (using IDs)
            let html = '';
            sameLevelAssignments.forEach(assignment => {
                let icon = '';
                let color = '';
                let typeText = '';
                let linkUrl = '';
                let linkClass = '';
                let id = '';
                
                if (assignment.Assignment_Type === 'project') {
                    icon = 'fa-folder-open';
                    color = '#3182ce';
                    typeText = 'Project';
                    id = assignment.Project_ID;
                    linkUrl = 'view_project.php?id=' + id;
                    linkClass = 'project-link';
                } else if (assignment.Assignment_Type === 'package') {
                    icon = 'fa-cubes';
                    color = '#805ad5';
                    typeText = 'Package';
                    id = assignment.Package_ID;
                    linkUrl = 'view_package.php?id=' + id;
                    linkClass = 'package-link';
                } else if (assignment.Assignment_Type === 'maintenance') {
                    icon = 'fa-tasks';
                    color = '#38a169';
                    typeText = 'Task';
                    id = assignment.Maintenance_ID;
                    linkUrl = 'view_maintenance.php?id=' + id;
                    linkClass = 'task-link';
                }
                
                html += `
                    <div class="assignment-item-modal">
                        <div class="d-flex gap-3">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(${color === '#3182ce' ? '49,130,206' : color === '#805ad5' ? '128,90,213' : '56,161,105'},0.1); display: flex; align-items: center; justify-content: center; color: ${color};">
                                <i class="fas ${icon}"></i>
                            </div>
                            <div>
                                <strong>${typeText}: <a href="${linkUrl}" class="hierarchy-link ${linkClass}" target="_blank">#${id}</a></strong>
                                <div style="font-size: 0.8rem; color: #64748b;">
                                    <span class="me-3"><i class="fas fa-tag me-1"></i>Role: ${assignment.Role}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('currentAssignmentList').innerHTML = html;
            document.getElementById('proceedAssignBtn').style.display = 'inline-flex';
            pendingSelectUrl = selectUrl;
            
            const modal = new bootstrap.Modal(document.getElementById('workerAssignmentModal'));
            modal.show();
        } else {
            // No conflicts, proceed to next step
            window.location.href = selectUrl;
        }
    }

    // Handle proceed button
    document.getElementById('proceedAssignBtn')?.addEventListener('click', function() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('workerAssignmentModal'));
        modal.hide();
        if (pendingSelectUrl) {
            window.location.href = pendingSelectUrl;
        }
    });

    // Prevent modal from closing when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            e.preventDefault();
        }
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>