<?php
session_start();
include("connect.php");

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

// Get package ID from URL
$package_id = isset($_GET['id']) ? $_GET['id'] : 0;

if($package_id == 0) {
    header("Location: manage_projects.php");
    exit();
}

// Handle description update via AJAX or form
if(isset($_POST['update_description'])) {
    $description = $_POST['package_description'];
    $update_query = "UPDATE packages SET Description = ? WHERE Package_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $description, $package_id);
    $stmt->execute();
    header("Location: view_package.php?id=$package_id&desc_updated=1");
    exit();
}

// Handle update package status
if(isset($_POST['update_package_status'])) {
    $status = $_POST['package_status'];
    $update_query = "UPDATE packages SET Status = ? WHERE Package_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $status, $package_id);
    $stmt->execute();
    header("Location: view_package.php?id=$package_id&status_updated=1");
    exit();
}

// Handle update worker assignment status
if(isset($_POST['update_worker_status'])) {
    $assignment_id = $_POST['assignment_id'];
    $new_status = $_POST['worker_status'];
    
    $update_query = "UPDATE worker_assignments SET Status = ? WHERE Assignment_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $new_status, $assignment_id);
    
    if($stmt->execute()) {
        // Check if this was the Team Leader and status changed to non-active
        $check_query = "SELECT w.Worker_Name, wa.Role FROM worker_assignments wa 
                        JOIN workers w ON wa.Worker_ID = w.Worker_ID 
                        WHERE wa.Assignment_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $assignment_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $assignment_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        // If this was the Team Leader and status changed to non-active AND non-paused, clear package team leader field
        if($assignment_data && $assignment_data['Role'] == 'Team Leader' && $new_status != 'Active' && $new_status != 'Paused') {
            $clear_package = "UPDATE packages SET Team_Leader = NULL WHERE Package_ID = ?";
            $clear_stmt = $conn->prepare($clear_package);
            $clear_stmt->bind_param("i", $package_id);
            $clear_stmt->execute();
            $clear_stmt->close();
        }
        
        header("Location: view_package.php?id=$package_id&worker_status_updated=1");
        exit();
    } else {
        $error_msg = "Error updating worker status: " . $conn->error;
    }
}

// Handle inline worker status update via AJAX
if(isset($_POST['inline_worker_status'])) {
    $assignment_id = $_POST['assignment_id'];
    $new_status = $_POST['worker_status'];
    
    $update_query = "UPDATE worker_assignments SET Status = ? WHERE Assignment_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $new_status, $assignment_id);
    
    if($stmt->execute()) {
        // Check if this was the Team Leader
        $check_query = "SELECT w.Worker_Name, wa.Role FROM worker_assignments wa 
                        JOIN workers w ON wa.Worker_ID = w.Worker_ID 
                        WHERE wa.Assignment_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $assignment_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $assignment_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        // If this was the Team Leader and status is no longer Active, clear package team leader field
        if($assignment_data && $assignment_data['Role'] == 'Team Leader' && $new_status != 'Active') {
            $clear_package = "UPDATE packages SET Team_Leader = NULL WHERE Package_ID = ?";
            $clear_stmt = $conn->prepare($clear_package);
            $clear_stmt->bind_param("i", $package_id);
            $clear_stmt->execute();
            $clear_stmt->close();
        }
        
        echo json_encode(['success' => true, 'status' => $new_status]);
        exit();
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit();
    }
}

// Fetch package details with project info
$package_query = "SELECT p.*, pr.Project_Name, pr.Project_Type, pr.Project_ID,
                         pr.Status as Project_Status, pr.Start_Date as Project_Start, pr.End_Date as Project_End
                  FROM packages p
                  JOIN projects pr ON p.Project_ID = pr.Project_ID
                  WHERE p.Package_ID = ?";
$stmt = $conn->prepare($package_query);
$stmt->bind_param("i", $package_id);
$stmt->execute();
$package_result = $stmt->get_result();

if($package_result->num_rows === 0) {
    header("Location: manage_projects.php");
    exit();
}

$package = $package_result->fetch_assoc();
$project_id = $package['Project_ID'];
$project_type = $package['Project_Type'];
$current_team_leader = $package['Team_Leader'] ?? '';

// Fetch segments (maintenance tasks) under this package
$segments_query = "SELECT m.*, a.Asset_Name, a.Asset_Type, a.Location,
                          (SELECT COUNT(*) FROM worker_assignments WHERE Maintenance_ID = m.Maintenance_ID AND Status IN ('Active', 'Paused')) as worker_count,
                          (SELECT SUM(w.Worker_Salary) FROM worker_assignments wa 
                           JOIN workers w ON wa.Worker_ID = w.Worker_ID 
                           WHERE wa.Maintenance_ID = m.Maintenance_ID AND wa.Status IN ('Active', 'Paused')) as total_worker_cost,
                          (SELECT SUM(Quantity * Unit_Cost) FROM resources WHERE Maintenance_ID = m.Maintenance_ID) as total_resource_cost
                   FROM maintenance m
                   LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID
                   WHERE m.Package_ID = ?
                   ORDER BY m.Start_Date DESC";
$stmt = $conn->prepare($segments_query);
$stmt->bind_param("i", $package_id);
$stmt->execute();
$segments_result = $stmt->get_result();
$segment_count = $segments_result->num_rows;

// Calculate segment costs
$total_segment_worker_cost = 0;
$total_segment_resource_cost = 0;
$total_segment_estimated_cost = 0;

$segments_result->data_seek(0);
while($segment = $segments_result->fetch_assoc()) {
    $total_segment_worker_cost += $segment['total_worker_cost'] ?? 0;
    $total_segment_resource_cost += $segment['total_resource_cost'] ?? 0;
    $total_segment_estimated_cost += $segment['Cost'] ?? 0;
}
$segments_result->data_seek(0);

// Package budget (planned allocation from Packages table)
$package_budget = $package['Budget'] ?? 0;

// Fetch CURRENT package level employees (Active + Paused)
$package_employees_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                   wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Assignment_Status
                            FROM workers w
                            JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                            WHERE wa.Package_ID = ? AND wa.Status IN ('Active', 'Paused')
                            ORDER BY 
                                CASE wa.Status
                                    WHEN 'Active' THEN 1
                                    WHEN 'Paused' THEN 2
                                END,
                                CASE wa.Role
                                    WHEN 'Team Leader' THEN 1
                                    WHEN 'Supervisor' THEN 2
                                    WHEN 'Engineer' THEN 3
                                    WHEN 'Technician' THEN 4
                                    WHEN 'Worker' THEN 5
                                    ELSE 6
                                END, 
                            w.Worker_Name";
$stmt = $conn->prepare($package_employees_query);
$stmt->bind_param("i", $package_id);
$stmt->execute();
$package_employees_result = $stmt->get_result();
$package_employee_count = $package_employees_result->num_rows;

// Check if a Team Leader exists in the active OR paused assignments
$has_team_leader = false;
$team_leader_name = '';
$package_employees_result->data_seek(0);
while($employee = $package_employees_result->fetch_assoc()) {
    if($employee['Role'] == 'Team Leader' && ($employee['Assignment_Status'] == 'Active' || $employee['Assignment_Status'] == 'Paused')) {
        $has_team_leader = true;
        $team_leader_name = $employee['Worker_Name'];
        break;
    }
}
$package_employees_result->data_seek(0);

// If team leader exists in assignments but not in package table, update package table
if($has_team_leader && empty($current_team_leader)) {
    $update_package = "UPDATE packages SET Team_Leader = ? WHERE Package_ID = ?";
    $update_stmt = $conn->prepare($update_package);
    $update_stmt->bind_param("si", $team_leader_name, $package_id);
    $update_stmt->execute();
    $update_stmt->close();
    $package['Team_Leader'] = $team_leader_name;
}
// If team leader exists in package table but not in active/paused assignments, clear package table
else if(!$has_team_leader && !empty($current_team_leader)) {
    $update_package = "UPDATE packages SET Team_Leader = NULL WHERE Package_ID = ?";
    $update_stmt = $conn->prepare($update_package);
    $update_stmt->bind_param("i", $package_id);
    $update_stmt->execute();
    $update_stmt->close();
    $package['Team_Leader'] = null;
}

// Calculate package level employee total cost (only active for budget)
$package_employee_total_cost = 0;
$package_employees_result->data_seek(0);
while($employee = $package_employees_result->fetch_assoc()) {
    if($employee['Assignment_Status'] == 'Active') {
        $package_employee_total_cost += $employee['Worker_Salary'];
    }
}
$package_employees_result->data_seek(0);

// Fetch INACTIVE package level employees (Completed/Removed) - REMOVED FROM DISPLAY
$inactive_employees_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                    wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Assignment_Status
                             FROM workers w
                             JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                             WHERE wa.Package_ID = ? AND wa.Status IN ('Completed', 'Removed')
                             ORDER BY wa.Assigned_Date DESC";
$stmt = $conn->prepare($inactive_employees_query);
$stmt->bind_param("i", $package_id);
$stmt->execute();
$inactive_employees_result = $stmt->get_result();
$inactive_employee_count = $inactive_employees_result->num_rows;

// Calculate total allocated budget (segments estimated + active package employees)
$total_allocated = $total_segment_estimated_cost + $package_employee_total_cost;

// Calculate remaining budget
$remaining_budget = $package_budget - $total_allocated;
$is_over_budget = $remaining_budget < 0;

// Handle success messages
if(isset($_GET['desc_updated'])) {
    $success_msg = "Description updated successfully!";
}
if(isset($_GET['status_updated'])) {
    $success_msg = "Package status updated successfully!";
}
if(isset($_GET['worker_status_updated'])) {
    $success_msg = "Worker assignment status updated successfully!";
}
if(isset($_GET['success'])) {
    if($_GET['success'] == 'segment_added') {
        $success_msg = "Segment added successfully!";
    } elseif($_GET['success'] == 'segment_deleted') {
        $success_msg = "Segment deleted successfully!";
    } elseif($_GET['success'] == 'worker_assigned') {
        $success_msg = "Employee assigned to package successfully!";
    } elseif($_GET['success'] == 'worker_removed') {
        $success_msg = "Employee removed from package successfully!";
    }
}

// Handle delete segment
if(isset($_GET['delete_segment'])) {
    $segment_id = $_GET['delete_segment'];
    
    // First delete worker assignments for this segment
    $delete_assignments = "DELETE FROM worker_assignments WHERE Maintenance_ID = ?";
    $stmt = $conn->prepare($delete_assignments);
    $stmt->bind_param("i", $segment_id);
    $stmt->execute();
    
    // Then delete the segment
    $delete_query = "DELETE FROM maintenance WHERE Maintenance_ID = ? AND Package_ID = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("ii", $segment_id, $package_id);
    
    if($stmt->execute()) {
        header("Location: view_package.php?id=$package_id&success=segment_deleted");
        exit();
    } else {
        $error_msg = "Error deleting segment: " . $conn->error;
    }
}

// Get valid worker assignment statuses
$status_query = "SHOW COLUMNS FROM worker_assignments LIKE 'Status'";
$status_result = $conn->query($status_query);
$status_row = $status_result->fetch_assoc();
preg_match("/^enum\(\'(.*)\'\)$/", $status_row['Type'], $matches);
$worker_statuses = explode("','", $matches[1]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Package - #<?php echo $package_id; ?> - Smart DNCC</title>
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

        .package-header {
            background: linear-gradient(135deg, var(--purple) 0%, #6b46c1 100%);
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .package-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            color: white;
        }

        .package-type-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            min-width: 120px;
            text-align: center;
        }

        .package-id-badge {
            background: rgba(0,0,0,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.9);
            min-width: 80px;
            text-align: center;
        }

        .project-ref {
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 8px 12px;
            margin-top: 10px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .project-ref a {
            color: white;
            text-decoration: underline;
        }
        .project-ref a:hover {
            color: rgba(255,255,255,0.8);
        }

        /* FIXED SIZE INFO CARDS */
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 15px;
            width: 100%;
            height: 80px;
            min-height: 80px;
            max-height: 80px;
            overflow: hidden;
        }

        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .info-content {
            flex: 1;
            overflow: hidden;
            min-width: 0;
        }

        .info-label {
            color: #718096;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            white-space: nowrap;
        }

        .info-value {
            font-size: 0.8rem;
            font-weight: 600;
            color: #2d3748;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* FIXED SIZE STAT CARDS */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 15px;
            width: 100%;
            height: 90px;
            min-height: 90px;
            max-height: 90px;
            overflow: hidden;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
        }

        .stat-label {
            color: #718096;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            white-space: nowrap;
        }

        .description-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 24px;
            border-left: 4px solid var(--purple);
        }

        /* Budget Section - 3 columns FIXED */
        .budget-summary-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }

        .budget-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .budget-row:last-child {
            margin-bottom: 0;
        }

        .budget-col {
            flex: 1;
            background: #f8fafc;
            border-radius: 10px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            min-height: 100px;
        }

        .budget-col.package {
            border-left: 4px solid var(--accent);
        }

        .budget-col.segments {
            border-left: 4px solid var(--purple);
        }

        .budget-col.team {
            border-left: 4px solid var(--danger);
        }

        .budget-col.remaining {
            border-left: 4px solid <?php echo $is_over_budget ? 'var(--danger)' : 'var(--success)'; ?>;
            background: <?php echo $is_over_budget ? '#fff5f5' : '#f0fff4'; ?>;
        }

        .budget-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #718096;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .budget-amount {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.2;
            color: #1a202c;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .budget-amount.warning {
            color: var(--danger);
        }

        .budget-amount.success {
            color: var(--success);
        }

        .budget-subtext {
            font-size: 0.7rem;
            color: #718096;
            margin-top: 6px;
        }

        .warning-badge {
            background: var(--danger);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* TABLE STYLES - For Segments and Team Members */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: #f8fafc;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.7rem;
            border-bottom: 2px solid #e2e8f0;
            padding: 12px 8px;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-align: center;
        }

        .data-table td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.8rem;
        }

        .data-table tr:hover {
            background: #fafbfc;
        }

        .data-table td:first-child,
        .data-table th:first-child {
            text-align: center;
        }

        .data-table td:nth-child(2),
        .data-table th:nth-child(2) {
            text-align: left;
        }

        .data-table td:nth-child(3),
        .data-table th:nth-child(3),
        .data-table td:nth-child(4),
        .data-table th:nth-child(4),
        .data-table td:nth-child(5),
        .data-table th:nth-child(5),
        .data-table td:nth-child(6),
        .data-table th:nth-child(6) {
            text-align: center;
        }

        /* Role Badge */
        .role-badge {
            background: #e2e8f0;
            color: #2d3748;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 100px;
            text-align: center;
            display: inline-block;
            white-space: nowrap;
        }

        .team-leader-badge {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 100px;
            text-align: center;
            display: inline-block;
            white-space: nowrap;
        }

        /* Status Badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 90px;
            text-align: center;
            display: inline-block;
            white-space: nowrap;
        }

        .status-not-started { background: #e2e8f0; color: #475569; border: 1px solid #cbd5e0; }
        .status-active { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-paused { background: #fed7aa; color: #92400e; border: 1px solid #fdba74; }
        .status-completed { background: #e9d8fd; color: #553c9a; border: 1px solid #d6bcfa; }
        .status-cancelled { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        /* Asset Badge */
        .asset-badge {
            background: #ebf8ff;
            color: #2c5282;
            border: 1px solid #90cdf4;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Cost Badge */
        .cost-badge {
            background: #f0fff4;
            color: #276749;
            border: 1px solid #9ae6b4;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        /* Worker Status Display */
        .worker-status-display {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            padding: 4px 10px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .worker-status-text {
            font-size: 0.65rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        .worker-status-badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
        }
        .worker-status-badge.active {
            background: #c6f6d5;
            color: #276749;
        }
        .worker-status-badge.paused {
            background: #fed7aa;
            color: #92400e;
        }
        
        .worker-status-edit-btn {
            background: none;
            border: none;
            color: var(--success);
            cursor: pointer;
            font-size: 0.65rem;
            padding: 2px 4px;
            border-radius: 4px;
        }
        .worker-status-edit-btn:hover {
            background: #e2e8f0;
        }
        
        .worker-status-edit-form {
            display: none;
            align-items: center;
            gap: 4px;
        }
        
        .worker-status-edit-form select {
            padding: 4px 8px;
            border-radius: 20px;
            border: 1px solid #cbd5e0;
            font-size: 0.65rem;
            min-width: 80px;
        }
        
        .worker-status-save-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            cursor: pointer;
        }
        
        .worker-status-cancel-btn {
            background: #e2e8f0;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            cursor: pointer;
        }

        /* Package Status Display */
        .status-display {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .status-badge-large {
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            min-width: 140px;
            text-align: center;
            display: inline-block;
        }

        .edit-status-btn {
            background: var(--success);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
            padding: 8px 16px;
            border-radius: 30px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .edit-status-btn:hover {
            background: #2f855a;
        }

        .status-edit-form {
            display: none;
            align-items: center;
            gap: 8px;
        }

        .status-edit-form select {
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid #cbd5e0;
            font-size: 0.9rem;
            min-width: 150px;
        }
        .status-edit-form select:focus {
            outline: none;
            border-color: var(--accent);
        }

        .save-status-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .cancel-status-btn {
            background: #e2e8f0;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.8rem;
            cursor: pointer;
        }

        /* Action Buttons */
        .action-btn {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: 1px solid transparent;
            transition: all 0.2s;
            min-width: 65px;
            justify-content: center;
            white-space: nowrap;
        }

        .btn-view {
            background: #ebf8ff;
            color: #3182ce;
            border-color: #bee3f8;
        }
        .btn-view:hover {
            background: #3182ce;
            color: white;
        }

        .btn-delete {
            background: #fff5f5;
            color: #e53e3e;
            border-color: #fc8181;
        }
        .btn-delete:hover {
            background: #e53e3e;
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
            border: none;
            min-width: 100px;
            padding: 5px 12px;
            font-size: 0.75rem;
        }
        .btn-success:hover {
            background: #2f855a;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
            min-width: 100px;
            padding: 5px 12px;
            font-size: 0.75rem;
        }
        .btn-primary:hover {
            background: #2c5282;
        }

        .btn-outline-primary {
            background: white;
            color: var(--accent);
            border: 1px solid var(--accent);
            min-width: 100px;
            padding: 5px 12px;
            font-size: 0.75rem;
        }
        .btn-outline-primary:hover {
            background: var(--accent);
            color: white;
        }

        .btn-back {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            min-width: 110px;
            justify-content: center;
        }
        .btn-back:hover {
            background: #718096;
            color: white;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.7rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-header h4 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            white-space: nowrap;
        }

        .empty-state {
            background: #f8fafc;
            border: 2px dashed #cbd5e0;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            color: #718096;
        }

        .team-leader-message {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 16px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
        }

        .alert {
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.8rem;
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

        .modal-content {
            border-radius: 12px;
            border: none;
        }
        .modal-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        @media (max-width: 992px) {
            .admin-main {
                margin-left: 0;
            }
            
            .budget-row {
                flex-direction: column;
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
            }
        }

        @media (max-width: 768px) {
            .package-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .budget-row {
                flex-direction: column;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .data-table td, .data-table th {
                padding: 8px 6px;
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Package Dashboard</h2>
            </div>
            <div>
                <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn-back me-2">
                    <i class="fas fa-arrow-left"></i> Back to Project
                </a>
                
            </div>
        </div>
        
        <div class="content-area">
            
            <!-- Success/Error Messages -->
            <?php if(isset($success_msg)): ?>
                <div class="alert alert-success"><?php echo $success_msg; ?></div>
            <?php endif; ?>

            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <!-- Package Header -->
            <div class="package-header">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="package-type-badge">
                            <i class="fas fa-cube me-1"></i>
                            <?php echo $package['Package_Type']; ?>
                        </span>
                        <span class="package-id-badge">
                            <i class="fas fa-hashtag me-1"></i>ID: <?php echo $package_id; ?>
                        </span>
                    </div>
                    <h1><?php echo htmlspecialchars($package['Package_Name']); ?></h1>
                    
                    <!-- Project Reference - Using Project ID only -->
                    <div class="project-ref">
                        <i class="fas fa-folder-open me-2"></i>
                        Part of Project: 
                        <a href="view_project.php?id=<?php echo $project_id; ?>">
                            Project-<?php echo $project_id; ?>
                        </a>
                    </div>
                </div>
                <div>
                    <div id="package-status-display" class="status-display">
                        <span class="status-badge-large
                            <?php 
                            if($package['Status'] == 'Not Started') echo 'status-not-started';
                            elseif($package['Status'] == 'Active') echo 'status-active';
                            elseif($package['Status'] == 'Paused') echo 'status-paused';
                            elseif($package['Status'] == 'Completed') echo 'status-completed';
                            elseif($package['Status'] == 'Cancelled') echo 'status-cancelled';
                            ?>">
                            <?php echo $package['Status']; ?>
                        </span>
                        <button type="button" class="edit-status-btn" onclick="showPackageStatusEdit()">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                    </div>
                    
                    <div id="package-status-edit" class="status-edit-form">
                        <form method="POST" style="display: flex; gap: 8px; align-items: center;">
                            <input type="hidden" name="update_package_status" value="1">
                            <select name="package_status" class="form-select">
                                <option value="Not Started" <?php if($package['Status'] == 'Not Started') echo 'selected'; ?>>Not Started</option>
                                <option value="Active" <?php if($package['Status'] == 'Active') echo 'selected'; ?>>Active</option>
                                <option value="Paused" <?php if($package['Status'] == 'Paused') echo 'selected'; ?>>Paused</option>
                                <option value="Completed" <?php if($package['Status'] == 'Completed') echo 'selected'; ?>>Completed</option>
                                <option value="Cancelled" <?php if($package['Status'] == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                            </select>
                            <button type="submit" class="save-status-btn">Save</button>
                            <button type="button" class="cancel-status-btn" onclick="hidePackageStatusEdit()">Cancel</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Package Info Cards - FIXED SIZE -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="info-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Timeline</div>
                            <div class="info-value" title="<?php echo date('d M Y', strtotime($package['Start_Date'])) . ' - ' . date('d M Y', strtotime($package['End_Date'])); ?>">
                                <?php echo date('d M Y', strtotime($package['Start_Date'])); ?> - 
                                <?php echo date('d M Y', strtotime($package['End_Date'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="info-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Segments</div>
                            <div class="info-value"><?php echo $segment_count; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="info-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Package Budget</div>
                            <div class="info-value">৳<?php echo number_format($package_budget); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Package Description -->
            <div class="description-box">
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-align-left me-2" style="color: var(--purple);"></i>
                    <strong>Package Description</strong>
                    <button class="btn btn-sm btn-outline-primary ms-2 py-0 px-2" data-bs-toggle="modal" data-bs-target="#addDescriptionModal">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                </div>
                <?php if(!empty($package['Description'])): ?>
                    <p class="mb-0" style="font-size: 0.85rem;"><?php echo nl2br(htmlspecialchars($package['Description'])); ?></p>
                <?php else: ?>
                    <p class="mb-0 text-muted fst-italic" style="font-size: 0.85rem;">No description added yet.</p>
                <?php endif; ?>
            </div>

            <!-- Stats Row - FIXED SIZE -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $segment_count; ?></div>
                            <div class="stat-label">Total Segments</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(229,62,62,0.1); color: #e53e3e;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $package_employee_count; ?></div>
                            <div class="stat-label">Current Team</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BUDGET SECTION - 3 Columns Fixed -->
            <div class="budget-summary-section">
                <h4 class="mb-3" style="color: #1a202c; font-size: 0.95rem;">
                    <i class="fas fa-chart-pie me-2" style="color: var(--accent);"></i>
                    Budget Overview
                </h4>
                
                <div class="budget-row">
                    <div class="budget-col package">
                        <div class="budget-label">
                            <i class="fas fa-box me-1"></i> Package Budget
                        </div>
                        <div class="budget-amount" title="৳<?php echo number_format($package_budget); ?>">৳<?php echo number_format($package_budget); ?></div>
                        <div class="budget-subtext">Total allocated for this package</div>
                    </div>
                    
                    <div class="budget-col segments">
                        <div class="budget-label">
                            <i class="fas fa-tasks me-1"></i> Segments Estimated
                        </div>
                        <div class="budget-amount" title="৳<?php echo number_format($total_segment_estimated_cost); ?>">৳<?php echo number_format($total_segment_estimated_cost); ?></div>
                        <div class="budget-subtext">Sum of all <?php echo $segment_count; ?> segment budgets</div>
                    </div>

                    <div class="budget-col team">
                        <div class="budget-label">
                            <i class="fas fa-users me-1"></i> Current Team (Active)
                        </div>
                        <div class="budget-amount" title="৳<?php echo number_format($package_employee_total_cost); ?>">৳<?php echo number_format($package_employee_total_cost); ?></div>
                        <div class="budget-subtext">Total for active members (excludes paused)</div>
                    </div>
                </div>
                
                <div class="budget-row">
                    <div class="budget-col remaining">
                        <div class="budget-label">
                            <i class="fas fa-coins me-1"></i> Remaining Budget
                        </div>
                        <div class="budget-amount <?php echo $is_over_budget ? 'warning' : 'success'; ?>" title="৳<?php echo number_format(abs($remaining_budget)); ?>">
                            ৳<?php echo number_format(abs($remaining_budget)); ?>
                        </div>
                        <div class="budget-subtext">
                            <?php if($is_over_budget): ?>
                                <span class="warning-badge">
                                    <i class="fas fa-exclamation-triangle"></i> Over Budget
                                </span>
                            <?php else: ?>
                                <i class="fas fa-check-circle" style="color: var(--success);"></i> Available after segments and active team
                            <?php endif; ?>
                        </div>
                        <div class="budget-subtext mt-1">
                            <small>Total Allocated: ৳<?php echo number_format($total_allocated); ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Segments Section - TABLE LAYOUT -->
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-tasks me-2" style="color: #38a169;"></i>
                        Segments (<?php echo $segment_count; ?>)
                    </h4>
                    <a href="add_maintenance.php?package_id=<?php echo $package_id; ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-plus me-1"></i>Add Segment
                    </a>
                </div>
                
                <?php if($segment_count > 0): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 8%">ID</th>
                                    <th style="width: 15%">Task Type</th>
                                    <th style="width: 20%">Asset</th>
                                    <th style="width: 12%">Start Date</th>
                                    <th style="width: 10%">Status</th>
                                    <th style="width: 12%">Budget</th>
                                    <th style="width: 8%">Employees</th>
                                    <th style="width: 15%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($segment = $segments_result->fetch_assoc()): 
                                    $status_class = '';
                                    if($segment['Status'] == 'Not Started') {
                                        $status_class = 'status-not-started';
                                    } elseif($segment['Status'] == 'Active') {
                                        $status_class = 'status-active';
                                    } elseif($segment['Status'] == 'Paused') {
                                        $status_class = 'status-paused';
                                    } elseif($segment['Status'] == 'Completed') {
                                        $status_class = 'status-completed';
                                    } elseif($segment['Status'] == 'Cancelled') {
                                        $status_class = 'status-cancelled';
                                    }
                                ?>
                                <tr>
                                    <td class="text-center">
                                        <strong>#<?php echo $segment['Maintenance_ID']; ?></strong>
                                    </td>
                                    <td>
                                        <i class="fas fa-tasks me-1" style="color: #38a169;"></i>
                                        <?php echo htmlspecialchars($segment['Task_type']); ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="asset-badge" title="<?php echo htmlspecialchars($segment['Asset_Name'] ?: 'No asset'); ?>">
                                            <i class="fas fa-building me-1"></i>
                                            <?php echo $segment['Asset_Name'] ? '#'.$segment['Asset_ID'].' '.htmlspecialchars(substr($segment['Asset_Name'], 0, 20)) : 'Not assigned'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php echo date('d/m/Y', strtotime($segment['Start_Date'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $segment['Status']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="cost-badge">
                                            <i class="fas fa-money-bill me-1"></i>
                                            ৳<?php echo number_format($segment['Cost'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-users me-1"></i>
                                            <?php echo $segment['worker_count']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="view_maintenance.php?id=<?php echo $segment['Maintenance_ID']; ?>" class="action-btn btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="view_package.php?id=<?php echo $package_id; ?>&delete_segment=<?php echo $segment['Maintenance_ID']; ?>" 
                                           class="action-btn btn-delete" 
                                           onclick="return confirm('Delete this segment? All associated employees and resources will be unassigned.')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks fa-3x mb-3"></i>
                        <h5>No segments added yet</h5>
                        <p class="mb-3">Segments are the maintenance tasks within this package.</p>
                        <a href="add_maintenance.php?package_id=<?php echo $package_id; ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-plus me-1"></i>Add First Segment
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Current Team Section - TABLE LAYOUT -->
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-users me-2" style="color: #e53e3e;"></i>
                        Package Level Team (<?php echo $package_employee_count; ?>)
                    </h4>
                    <a href="add_worker.php?package_id=<?php echo $package_id; ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-plus-circle me-1"></i>Add Member
                    </a>
                </div>
                
                <?php if(!$has_team_leader): ?>
                <div class="team-leader-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><strong>Note:</strong> No Team Leader assigned yet. Add an employee with role "Team Leader" to lead this package.</span>
                </div>
                <?php endif; ?>
                
                <?php if($package_employee_count > 0): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 8%">ID</th>
                                    <th style="width: 20%">Employee Name</th>
                                    <th style="width: 15%">Role</th>
                                    <th style="width: 12%">Designation</th>
                                    <th style="width: 12%">Salary</th>
                                    <th style="width: 15%">Assigned Date</th>
                                    <th style="width: 8%">Status</th>
                                    <th style="width: 10%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($employee = $package_employees_result->fetch_assoc()): 
                                    $is_team_leader = ($employee['Role'] == 'Team Leader');
                                ?>
                                <tr>
                                    <td class="text-center">
                                        <strong>#<?php echo $employee['Worker_ID']; ?></strong>
                                    </td>
                                    <td>
                                        <i class="fas fa-user-tie me-1" style="color: <?php echo $is_team_leader ? '#d97706' : '#3182ce'; ?>;"></i>
                                        <?php echo htmlspecialchars($employee['Worker_Name']); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if($is_team_leader): ?>
                                            <span class="team-leader-badge">
                                                <i class="fas fa-crown me-1"></i>Team Leader
                                            </span>
                                        <?php else: ?>
                                            <span class="role-badge"><?php echo $employee['Role']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark">
                                            <?php echo htmlspecialchars($employee['Designation'] ?: 'Not set'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-money-bill me-1"></i>
                                            ৳<?php echo number_format($employee['Worker_Salary']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php echo date('d/m/Y', strtotime($employee['Assigned_Date'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div id="worker-status-display-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-display">
                                            <span class="worker-status-text">Status:</span>
                                            <span class="worker-status-badge <?php echo strtolower($employee['Assignment_Status']); ?>">
                                                <?php echo $employee['Assignment_Status']; ?>
                                            </span>
                                            <button type="button" class="worker-status-edit-btn" onclick="showWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                        
                                        <div id="worker-status-edit-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-edit-form">
                                            <select id="worker-status-select-<?php echo $employee['Assignment_ID']; ?>">
                                                <option value="Active" <?php echo ($employee['Assignment_Status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                                                <option value="Paused" <?php echo ($employee['Assignment_Status'] == 'Paused') ? 'selected' : ''; ?>>Paused</option>
                                                <option value="Completed">Completed</option>
                                                <option value="Removed">Removed</option>
                                            </select>
                                            <button type="button" class="worker-status-save-btn" onclick="saveWorkerStatus(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="worker-status-cancel-btn" onclick="cancelWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-users fa-2x mb-2"></i>
                        <p class="mb-2">No current team members assigned to this package</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Add Description Modal -->
    <div class="modal fade" id="addDescriptionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Package Description</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="update_description" value="1">
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="package_description" class="form-control" rows="5"><?php echo htmlspecialchars($package['Description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Description</button>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <script>
        // Package Status Edit Functions
        function showPackageStatusEdit() {
            document.getElementById('package-status-display').style.display = 'none';
            document.getElementById('package-status-edit').style.display = 'flex';
        }

        function hidePackageStatusEdit() {
            document.getElementById('package-status-display').style.display = 'flex';
            document.getElementById('package-status-edit').style.display = 'none';
        }
        
        // Worker Status Edit Functions
        function showWorkerStatusEdit(assignmentId) {
            document.getElementById('worker-status-display-' + assignmentId).style.display = 'none';
            document.getElementById('worker-status-edit-' + assignmentId).style.display = 'inline-flex';
        }
        
        function cancelWorkerStatusEdit(assignmentId) {
            document.getElementById('worker-status-display-' + assignmentId).style.display = 'inline-flex';
            document.getElementById('worker-status-edit-' + assignmentId).style.display = 'none';
        }
        
        function saveWorkerStatus(assignmentId) {
            var select = document.getElementById('worker-status-select-' + assignmentId);
            var newStatus = select.value;
            
            var saveBtn = document.querySelector('#worker-status-edit-' + assignmentId + ' .worker-status-save-btn');
            var originalHtml = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            saveBtn.disabled = true;
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                saveBtn.innerHTML = originalHtml;
                saveBtn.disabled = false;
                
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            var statusDisplay = document.getElementById('worker-status-display-' + assignmentId);
                            var statusBadge = statusDisplay.querySelector('.worker-status-badge');
                            statusBadge.className = 'worker-status-badge ' + newStatus.toLowerCase();
                            statusBadge.textContent = newStatus;
                            cancelWorkerStatusEdit(assignmentId);
                            location.reload();
                        } else {
                            alert('Error updating status: ' + response.error);
                        }
                    } catch(e) {
                        alert('Error updating status');
                    }
                } else {
                    alert('Server error');
                }
            };
            xhr.send('inline_worker_status=1&assignment_id=' + assignmentId + '&worker_status=' + encodeURIComponent(newStatus));
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>