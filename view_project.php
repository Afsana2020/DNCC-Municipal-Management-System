<?php
session_start();
include("connect.php");

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

// Get project ID from URL
$project_id = isset($_GET['id']) ? $_GET['id'] : 0;

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
$project_budget = $project['Budget'] ?? 0;
$current_director = $project['Project_Director'] ?? '';

// Handle budget update
if(isset($_POST['update_budget'])) {
    $new_budget = $_POST['project_budget'];
    
    if($new_budget < 0) {
        $error_msg = "Budget cannot be negative.";
    } else {
        $update_query = "UPDATE projects SET Budget = ? WHERE Project_ID = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("di", $new_budget, $project_id);
        
        if($stmt->execute()) {
            $success_msg = "Project budget updated successfully!";
            $project_budget = $new_budget;
        } else {
            $error_msg = "Error updating budget: " . $conn->error;
        }
        $stmt->close();
    }
}

// Handle update worker assignment status
if(isset($_POST['update_worker_status'])) {
    $assignment_id = $_POST['assignment_id'];
    $new_status = $_POST['worker_status'];
    
    $update_query = "UPDATE worker_assignments SET Status = ? WHERE Assignment_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $new_status, $assignment_id);
    
    if($stmt->execute()) {
        $check_query = "SELECT w.Worker_Name, wa.Role FROM worker_assignments wa 
                        JOIN workers w ON wa.Worker_ID = w.Worker_ID 
                        WHERE wa.Assignment_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $assignment_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $assignment_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if($assignment_data && $assignment_data['Role'] == 'Project Director' && $new_status != 'Active') {
            $clear_project = "UPDATE projects SET Project_Director = NULL WHERE Project_ID = ?";
            $clear_stmt = $conn->prepare($clear_project);
            $clear_stmt->bind_param("i", $project_id);
            $clear_stmt->execute();
            $clear_stmt->close();
        }
        
        header("Location: view_project.php?id=$project_id&worker_status_updated=1");
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
        $check_query = "SELECT w.Worker_Name, wa.Role FROM worker_assignments wa 
                        JOIN workers w ON wa.Worker_ID = w.Worker_ID 
                        WHERE wa.Assignment_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $assignment_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $assignment_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if($assignment_data && $assignment_data['Role'] == 'Project Director' && $new_status != 'Active') {
            $clear_project = "UPDATE projects SET Project_Director = NULL WHERE Project_ID = ?";
            $clear_stmt = $conn->prepare($clear_project);
            $clear_stmt->bind_param("i", $project_id);
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

// Fetch zones for this project
$zones_query = "SELECT z.*, pz.Project_Zone_ID 
                FROM zones z 
                JOIN project_zones pz ON z.Zone_ID = pz.Zone_ID 
                WHERE pz.Project_ID = ? 
                ORDER BY z.Zone_Name";
$stmt = $conn->prepare($zones_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$zones_result = $stmt->get_result();
$zone_count = $zones_result->num_rows;

// Fetch packages with segment counts and budget info
$packages_query = "SELECT p.*,
                   (SELECT COUNT(*) FROM maintenance WHERE Package_ID = p.Package_ID) as segment_count,
                   (SELECT SUM(Cost) FROM maintenance WHERE Package_ID = p.Package_ID) as total_segments_planned
                   FROM packages p 
                   WHERE p.Project_ID = ? 
                   ORDER BY p.Start_Date DESC";
$stmt = $conn->prepare($packages_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$packages_result = $stmt->get_result();
$package_count = $packages_result->num_rows;

// Calculate total package budgets
$total_package_budgets = 0;
$packages_result->data_seek(0);
while($pkg = $packages_result->fetch_assoc()) {
    $total_package_budgets += $pkg['Budget'] ?? 0;
}
$packages_result->data_seek(0);

// ========== BUDGET CALCULATION FOR WARNING ==========
// Calculate total actual cost from resources and employees
$total_resource_cost = 0;
$total_employee_cost = 0;

// Get total resource cost for this project through maintenance tasks
$resource_cost_query = "SELECT COALESCE(SUM(r.Quantity * r.Unit_Cost), 0) as Total_Resource_Cost 
                        FROM resources r
                        INNER JOIN maintenance m ON r.Maintenance_ID = m.Maintenance_ID
                        WHERE m.Project_ID = ?";
$stmt = $conn->prepare($resource_cost_query);
if($stmt) {
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $resource_result = $stmt->get_result();
    $resource_data = $resource_result->fetch_assoc();
    $total_resource_cost = $resource_data['Total_Resource_Cost'] ?? 0;
    $stmt->close();
} else {
    $total_resource_cost = 0;
}

// Get total employee cost for this project
$employee_cost_query = "SELECT COALESCE(SUM(w.Worker_Salary), 0) as Total_Employee_Cost
                        FROM worker_assignments wa
                        INNER JOIN workers w ON wa.Worker_ID = w.Worker_ID
                        WHERE wa.Project_ID = ? AND wa.Status IN ('Active', 'Paused', 'Completed')";
$stmt = $conn->prepare($employee_cost_query);
if($stmt) {
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $employee_result = $stmt->get_result();
    $employee_data = $employee_result->fetch_assoc();
    $total_employee_cost = $employee_data['Total_Employee_Cost'] ?? 0;
    $stmt->close();
} else {
    $total_employee_cost = 0;
}

$total_actual_cost = $total_resource_cost + $total_employee_cost;
$is_over_budget = ($project_budget > 0 && $total_actual_cost > $project_budget);
$over_budget_amount = max(0, $total_actual_cost - $project_budget);
// ===================================================

// Fetch ALL employees assigned to this project
// Fetch ONLY project-level employees assigned to this project (not package or maintenance level)
$project_employees_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                   wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Assignment_Status
                            FROM workers w
                            JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                            WHERE wa.Project_ID = ? AND wa.Assignment_Type = 'project'
                            ORDER BY 
                                CASE wa.Status
                                    WHEN 'Active' THEN 1
                                    WHEN 'Paused' THEN 2
                                    WHEN 'Completed' THEN 3
                                    WHEN 'Removed' THEN 4
                                    ELSE 5
                                END,
                                CASE wa.Role
                                    WHEN 'Project Director' THEN 1
                                    WHEN 'Executive Engineer' THEN 2
                                    WHEN 'Superintending Engineer' THEN 3
                                    WHEN 'Assistant Engineer' THEN 4
                                    WHEN 'Site Engineer' THEN 5
                                    WHEN 'Supervisor' THEN 6
                                    WHEN 'Worker' THEN 7
                                    ELSE 8
                                END, 
                            w.Worker_Name";
$stmt = $conn->prepare($project_employees_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project_employees_result = $stmt->get_result();

// Separate active/paused from inactive for display
$active_employees = [];
$inactive_employees = [];
$has_director = false;
$director_name = '';

while($employee = $project_employees_result->fetch_assoc()) {
    if($employee['Assignment_Status'] == 'Active' || $employee['Assignment_Status'] == 'Paused') {
        $active_employees[] = $employee;
        if($employee['Role'] == 'Project Director' && $employee['Assignment_Status'] == 'Active') {
            $has_director = true;
            $director_name = $employee['Worker_Name'];
        }
    } else {
        $inactive_employees[] = $employee;
    }
}
$active_employee_count = count($active_employees);
$inactive_employee_count = count($inactive_employees);

// If director exists in assignments but not in project table, update project table
if($has_director && empty($current_director)) {
    $update_project = "UPDATE projects SET Project_Director = ? WHERE Project_ID = ?";
    $update_stmt = $conn->prepare($update_project);
    $update_stmt->bind_param("si", $director_name, $project_id);
    $update_stmt->execute();
    $update_stmt->close();
    $project['Project_Director'] = $director_name;
}
// If director exists in project table but not in active assignments, clear project table
else if(!$has_director && !empty($current_director)) {
    $update_project = "UPDATE projects SET Project_Director = NULL WHERE Project_ID = ?";
    $update_stmt = $conn->prepare($update_project);
    $update_stmt->bind_param("i", $project_id);
    $update_stmt->execute();
    $update_stmt->close();
    $project['Project_Director'] = null;
}

// Handle zone removal (Large only)
if(isset($_GET['remove_zone'])) {
    $zone_id = $_GET['remove_zone'];
    
    $delete_query = "DELETE FROM project_zones WHERE Project_ID = ? AND Zone_ID = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("ii", $project_id, $zone_id);
    $stmt->execute();
    
    header("Location: view_project.php?id=$project_id");
    exit();
}

// Handle add zone (Large only)
if(isset($_POST['add_zone'])) {
    $zone_id = $_POST['zone_id'];
    
    $check_query = "SELECT * FROM project_zones WHERE Project_ID = ? AND Zone_ID = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ii", $project_id, $zone_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    
    if($check_result->num_rows === 0) {
        $insert_query = "INSERT INTO project_zones (Project_ID, Zone_ID) VALUES (?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("ii", $project_id, $zone_id);
        $stmt->execute();
    }
    
    header("Location: view_project.php?id=$project_id");
    exit();
}

// Handle set zone (Routine & Urgent)
if(isset($_POST['set_zone'])) {
    $zone_id = $_POST['zone_id'];
    
    $delete_query = "DELETE FROM project_zones WHERE Project_ID = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    
    $insert_query = "INSERT INTO project_zones (Project_ID, Zone_ID) VALUES (?, ?)";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("ii", $project_id, $zone_id);
    $stmt->execute();
    
    header("Location: view_project.php?id=$project_id");
    exit();
}

// Handle update project status
if(isset($_POST['update_project_status'])) {
    $status = $_POST['project_status'];
    
    $update_query = "UPDATE projects SET Status = ? WHERE Project_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $status, $project_id);
    $stmt->execute();
    
    header("Location: view_project.php?id=$project_id");
    exit();
}

// Handle update project description
if(isset($_POST['update_project_description'])) {
    $description = $_POST['project_description'];
    
    $update_query = "UPDATE projects SET Description = ? WHERE Project_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $description, $project_id);
    $stmt->execute();
    
    header("Location: view_project.php?id=$project_id");
    exit();
}

// Get all zones for dropdown
$all_zones_query = "SELECT * FROM zones ORDER BY Zone_Name";
$all_zones_result = $conn->query($all_zones_query);

// Handle success messages
if(isset($_GET['worker_status_updated'])) {
    $success_msg = "Worker status updated successfully!";
}

// Fetch urgent tasks for emergency projects
$urgent_tasks = null;
if($project_type == 'Urgent') {
    $urgent_tasks_query = "SELECT m.*, a.Asset_Name 
                          FROM maintenance m 
                          LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID
                          WHERE m.Project_ID = ? 
                          ORDER BY m.Start_Date DESC";
    $stmt = $conn->prepare($urgent_tasks_query);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $urgent_tasks = $stmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Project - Smart DNCC</title>
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

        .project-header {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .project-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            color: white;
        }

        .director-info {
            background: rgba(255,215,0,0.15);
            border: 1px solid rgba(255,215,0,0.3);
            border-radius: 8px;
            padding: 8px 16px;
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #ffd700;
            font-size: 0.9rem;
        }

        .project-type-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            min-width: 100px;
            text-align: center;
            display: inline-block;
        }

        .project-id-badge {
            background: rgba(0,0,0,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.9);
            min-width: 80px;
            text-align: center;
            display: inline-block;
        }

        /* FIXED SIZE INFO CARDS - NO TEXT WRAP, ELLIPSIS */
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

        /* FIXED SIZE BUDGET SUMMARY */
        .budget-summary {
            background: linear-gradient(135deg, #f0fff4, #e6fffa);
            border: 1px solid #9ae6b4;
            border-radius: 12px;
            padding: 16px 20px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            height: 100px;
            min-height: 100px;
        }

        .budget-summary.budget-warning {
            background: linear-gradient(135deg, #fff5f5, #fed7d7);
            border: 2px solid #e53e3e;
        }

        .budget-col {
            flex: 1;
            text-align: center;
            padding: 0 5px;
            overflow: hidden;
        }

        .budget-label {
            font-size: 0.7rem;
            color: #2d3748;
            text-transform: uppercase;
            font-weight: 600;
            display: block;
            margin-bottom: 5px;
            white-space: nowrap;
        }

        .budget-amount {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .warning-text {
            color: #e53e3e;
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 4px;
        }

        /* ZONE ROW - TWO COLUMNS FIXED */
        .zone-row {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            border-left: 4px solid var(--accent);
            height: 56px;
            min-height: 56px;
        }
        .zone-row:hover {
            background: #f8fafc;
        }

        .zone-info {
            display: flex;
            align-items: center;
            gap: 40px;
            flex: 1;
        }

        .zone-info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 160px;
        }

        .zone-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .zone-value {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1a202c;
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }

        /* PACKAGE ROW */
        .package-row {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            border-left: 6px solid var(--purple);
            min-height: 80px;
        }
        .package-row:hover {
            background: #faf5ff;
        }

        .package-info {
            flex: 1;
            overflow: hidden;
        }

        .package-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .package-title i {
            color: var(--purple);
            margin-right: 6px;
        }

        .package-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.7rem;
            color: #718096;
        }

        .package-meta-item {
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .package-description {
            background: #f8fafc;
            border-radius: 6px;
            padding: 6px 10px;
            margin-top: 6px;
            font-size: 0.7rem;
            border-left: 3px solid var(--purple);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .package-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        /* TABLE STYLES - For Team Members and Emergency Tasks */
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

        .director-badge {
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

        /* Task Type Badge - FIXED WIDTH */
        .task-type-badge {
            background: #fff5f5;
            color: #c53030;
            border: 1px solid #fc8181;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-align: center;
            display: inline-block;
            white-space: nowrap;
            min-width: 130px;
            width: 200px;
        }

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

        .btn-package {
            background: #faf5ff;
            color: #805ad5;
            border-color: #d6bcfa;
        }
        .btn-package:hover {
            background: #805ad5;
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

        .description-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
            margin: 20px 0;
            border-left: 4px solid var(--accent);
        }

        .detail-link {
            width: 100%;
            background-color: #f8f9fa;
            color: #212529;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.2s ease-in-out;
            border: 1px solid #dee2e6 !important;
            border-radius: 8px;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
        }

        .detail-link:hover {
            background-color: #e9ecef;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .director-message {
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

        .budget-edit-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.6rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .budget-edit-btn:hover {
            background: #2f855a;
        }

        .budget-edit-form {
            display: none;
            width: 100%;
            margin-top: 6px;
        }

        .budget-edit-form form {
            display: flex;
            align-items: center;
            gap: 5px;
            width: 100%;
        }

        .budget-input {
            flex: 1;
            padding: 4px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 0.7rem;
        }

        .budget-save-btn, .budget-cancel-btn {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.6rem;
            cursor: pointer;
        }

        .budget-save-btn {
            background: var(--success);
            color: white;
            border: none;
        }
        .budget-cancel-btn {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 12px;
            border: none;
        }
        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .zone-detail-row {
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .zone-detail-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .zone-detail-value {
            font-size: 0.9rem;
            color: #1a202c;
        }
        .zone-area-box {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            margin-top: 8px;
            border-left: 4px solid var(--accent);
        }

        h2, h4 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.3rem; }
        h4 { font-size: 1rem; }

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

        .warning-badge {
            background: #e53e3e;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }

        @media (max-width: 992px) {
            .admin-main {
                margin-left: 0;
            }
            
            .zone-info {
                gap: 20px;
                flex-wrap: wrap;
            }
            
            .zone-info-item {
                min-width: 140px;
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
            }
        }

        @media (max-width: 768px) {
            .project-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .budget-summary {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                height: auto;
            }
            
            .budget-col {
                text-align: left;
                padding: 0;
            }
            
            .budget-amount {
                font-size: 1.1rem;
            }
            
            .package-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .package-actions {
                width: 100%;
                justify-content: flex-end;
            }
            
            .package-title {
                white-space: normal;
            }
            
            .package-meta {
                flex-direction: column;
                gap: 6px;
            }
            
            .package-meta-item {
                white-space: normal;
            }
            
            .zone-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                height: auto;
            }
            
            .zone-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
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
                <h2>Project Dashboard</h2>
            </div>
            <div>
                <a href="manage_projects.php" class="btn-back me-2">
                    <i class="fas fa-arrow-left"></i> Back to Projects
                </a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                    <i class="fas fa-edit me-1"></i>Update Status
                </button>
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
            
            <!-- Project Header -->
            <div class="project-header">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="project-type-badge">
                            <i class="fas 
                                <?php 
                                if($project_type == 'Large') echo 'fa-building';
                                elseif($project_type == 'Routine') echo 'fa-calendar-check';
                                else echo 'fa-exclamation';
                                ?> me-1">
                            </i>
                            <?php echo $project_type; ?>
                        </span>
                        <span class="project-id-badge">
                            <i class="fas fa-hashtag me-1"></i>ID: <?php echo $project_id; ?>
                        </span>
                    </div>
                    <h1><?php echo htmlspecialchars($project['Project_Name']); ?></h1>
                    
                    <!-- Project Director Display -->
                    <?php if(!empty($project['Project_Director'])): ?>
                    <div class="director-info">
                        <i class="fas fa-crown"></i>
                        <span>Project Director: <strong><?php echo htmlspecialchars($project['Project_Director']); ?></strong></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <?php
                    $status_class = '';
                    if($project['Status'] == 'Not Started') {
                        $status_class = 'status-not-started';
                    } elseif($project['Status'] == 'Active') {
                        $status_class = 'status-active';
                    } elseif($project['Status'] == 'Paused') {
                        $status_class = 'status-paused';
                    } elseif($project['Status'] == 'Completed') {
                        $status_class = 'status-completed';
                    } elseif($project['Status'] == 'Cancelled') {
                        $status_class = 'status-cancelled';
                    }
                    ?>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <?php echo $project['Status']; ?>
                    </span>
                </div>
            </div>

            <!-- Project Info Cards - FIXED SIZE -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="info-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Timeline</div>
                            <div class="info-value" title="<?php echo date('d M Y', strtotime($project['Start_Date'])) . ' - ' . date('d M Y', strtotime($project['End_Date'])); ?>">
                                <?php echo date('d M Y', strtotime($project['Start_Date'])); ?> - 
                                <?php echo date('d M Y', strtotime($project['End_Date'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="info-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Created</div>
                            <div class="info-value"><?php echo date('d M Y', strtotime($project['Created_At'])); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="info-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Project Budget</div>
                            <div class="info-value">
                                <div id="budgetDisplayContainer" style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                                    <span id="budgetDisplay" style="font-size: 0.8rem; font-weight: 600; <?php echo $project_budget > 0 ? 'color: #2d3748;' : 'color: #e53e3e;'; ?>">
                                        <?php if($project_budget > 0): ?>
                                            ৳<?php echo number_format($project_budget); ?>
                                        <?php else: ?>
                                            Not set
                                        <?php endif; ?>
                                    </span>
                                    <button id="budgetEditTrigger" class="budget-edit-btn" onclick="toggleBudgetEdit()">
                                        <i class="fas fa-edit"></i> <?php echo $project_budget > 0 ? 'Edit' : 'Add'; ?>
                                    </button>
                                </div>
                                
                                <div id="budgetEditForm" class="budget-edit-form">
                                    <form method="POST">
                                        <input type="number" class="budget-input" name="project_budget" 
                                               value="<?php echo $project_budget; ?>" step="1" min="0" 
                                               placeholder="Amount" autofocus>
                                        <button type="submit" name="update_budget" class="budget-save-btn">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button type="button" class="budget-cancel-btn" onclick="toggleBudgetEdit()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Budget Summary  -->
            <div class="budget-summary <?php echo $is_over_budget ? 'budget-warning' : ''; ?>">
                <div class="budget-col">
                    <span class="budget-label">Total Project Budget</span>
                    <div class="budget-amount" title="৳<?php echo number_format($project_budget); ?>">৳<?php echo number_format($project_budget); ?></div>
                </div>
                <div class="budget-col">
                    <span class="budget-label">Package Budgets</span>
                    <div class="budget-amount" style="color: #805ad5;" title="৳<?php echo number_format($total_package_budgets); ?>">৳<?php echo number_format($total_package_budgets); ?></div>
                </div>
                <div class="budget-col">
                    <span class="budget-label">Remaining Amount</span>
                    <?php 
                    $remaining = $project_budget - $total_package_budgets;
                    if($remaining < 0): 
                    ?>
                        <div class="budget-amount" style="color: #e53e3e;" title="-৳<?php echo number_format(abs($remaining)); ?>">-৳<?php echo number_format(abs($remaining)); ?></div>
                        <small class="text-danger" style="font-size: 0.65rem;">Over budget by ৳<?php echo number_format(abs($remaining)); ?></small>
                    <?php elseif($remaining > 0): ?>
                        <div class="budget-amount" style="color: #38a169;" title="৳<?php echo number_format($remaining); ?>">৳<?php echo number_format($remaining); ?></div>
                        <small class="text-success" style="font-size: 0.65rem;">Available</small>
                    <?php else: ?>
                        <div class="budget-amount" style="color: #718096;">৳0</div>
                        <small class="text-muted" style="font-size: 0.65rem;">Fully allocated</small>
                    <?php endif; ?>
                </div>
            </div>

      <!-- Budget Warning Alert - Minimal -->
<?php if($is_over_budget): ?>
<div class="alert alert-danger mb-3 py-1 px-3" style="background: #fee; border: 1px solid #e53e3e; border-radius: 4px;">
    <div class="d-flex align-items-center justify-content-between">
        <span style="color: #c53030; font-size: 0.75rem;">
            <i class="fas fa-exclamation-triangle me-1"></i>
            <strong>Budget exceeded</strong> by ৳<?php echo number_format($over_budget_amount); ?>
        </span>
        <a href="manage_project_budget.php?id=<?php echo $project_id; ?>" class="btn btn-small text-danger" style="font-size: 0.7rem;">
            View <i class="fas fa-arrow-right ms-1"></i>
        </a>
    </div>
</div>
<?php endif; ?>

            <!-- Project Description -->
            <div class="description-box">
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-align-left me-2" style="color: var(--accent);"></i>
                    <strong style="font-size: 0.85rem;">Project Description</strong>
                    <button class="btn btn-sm btn-outline-primary ms-2 py-0 px-2" style="font-size: 0.7rem;" data-bs-toggle="modal" data-bs-target="#addDescriptionModal">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                </div>
                <?php if(!empty($project['Description'])): ?>
                    <p class="mb-0" style="font-size: 0.8rem;"><?php echo nl2br(htmlspecialchars($project['Description'])); ?></p>
                <?php else: ?>
                    <p class="mb-0 text-muted fst-italic" style="font-size: 0.8rem;">No description added yet.</p>
                <?php endif; ?>
            </div>

            <!-- Stats Row - FIXED SIZE -->
            <div class="row g-3 mb-4">
                <?php if($project_type != 'Urgent'): ?>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $zone_count; ?></div>
                            <div class="stat-label">Zones</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $package_count; ?></div>
                            <div class="stat-label">Packages</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(229,62,62,0.1); color: #e53e3e;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $active_employee_count; ?></div>
                            <div class="stat-label">Active Team</div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- For Urgent projects: only Packages and Active Team (2 columns, 6 each) -->
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $package_count; ?></div>
                            <div class="stat-label">Packages</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(229,62,62,0.1); color: #e53e3e;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $active_employee_count; ?></div>
                            <div class="stat-label">Active Team</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Zones Section with Two Columns -->
            <?php if($project_type != 'Urgent'): ?>
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-map-marker-alt me-2" style="color: #3182ce;"></i>
                        <?php echo ($project_type == 'Large') ? 'Project Zones' : 'Project Zone'; ?> (<?php echo $zone_count; ?>)
                    </h4>
                    <?php if($project_type == 'Large'): ?>
                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addZoneModal">
                            <i class="fas fa-plus me-1"></i>Add Zone
                        </button>
                    <?php else: ?>
                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#setZoneModal">
                            <i class="fas fa-edit me-1"></i><?php echo ($zone_count > 0) ? 'Change Zone' : 'Set Zone'; ?>
                        </button>
                    <?php endif; ?>
                </div>
                
                <?php if($zone_count > 0): ?>
                    <?php 
                    $zones_result->data_seek(0);
                    while($zone = $zones_result->fetch_assoc()): 
                    ?>
                    <div class="zone-row">
                        <div class="zone-info">
                            <div class="zone-info-item">
                                <span class="zone-label">Zone ID:</span>
                                <span class="zone-value"><?php echo $zone['Zone_ID']; ?></span>
                            </div>
                            <div class="zone-info-item">
                                <span class="zone-label">Zone Code:</span>
                                <span class="zone-value"><?php echo $zone['Zone_Code']; ?></span>
                            </div>
                        </div>
                        <div class="row-actions">
                            <a href="javascript:void(0);" onclick="showZoneDetails(<?php echo $zone['Zone_ID']; ?>, '<?php echo htmlspecialchars($zone['Zone_Name']); ?>', '<?php echo $zone['Zone_Code']; ?>')" class="action-btn btn-view">
                                <i class="fas fa-eye me-1"></i> View
                            </a>
                            <?php if($project_type == 'Large'): ?>
                            <a href="view_project.php?id=<?php echo $project_id; ?>&remove_zone=<?php echo $zone['Zone_ID']; ?>" 
                               class="action-btn btn-delete" 
                               onclick="return confirm('Remove this zone from project?')">
                                <i class="fas fa-times me-1"></i> Remove
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-map-marker-alt fa-2x mb-2"></i>
                        <p class="mb-2">No zones added yet</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Packages Section -->
            <?php if($project_type != 'Urgent'): ?>
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-cubes me-2" style="color: #805ad5;"></i>
                        Project Packages (<?php echo $package_count; ?>)
                    </h4>
                    <a href="add_package.php?project_id=<?php echo $project_id; ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-plus me-1"></i>Create Package
                    </a>
                </div>
                
                <?php if($package_count > 0): ?>
                    <?php 
                    $packages_result->data_seek(0);
                    while($package = $packages_result->fetch_assoc()): 
                    ?>
                    <div class="package-row">
                        <div class="package-info">
                            <div class="package-title" title="<?php echo htmlspecialchars($package['Package_Name']); ?>">
                                <i class="fas fa-cube"></i>
                                <?php echo htmlspecialchars($package['Package_Name']); ?>
                            </div>
                            
                            <?php if(!empty($package['Description'])): ?>
                            <div class="package-description" title="<?php echo htmlspecialchars($package['Description']); ?>">
                                <i class="fas fa-align-left me-1" style="color: #805ad5; font-size: 0.65rem;"></i>
                                <?php echo htmlspecialchars($package['Description']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="package-meta">
                                <span class="package-meta-item">
                                    <i class="fas fa-user" style="color: #38a169;"></i>
                                    Leader: <?php echo htmlspecialchars($package['Team_Leader'] ?: 'Not assigned'); ?>
                                </span>
                                <span class="package-meta-item">
                                    <i class="fas fa-calendar" style="color: #805ad5;"></i>
                                    <?php echo date('d/m/Y', strtotime($package['Start_Date'])); ?>
                                </span>
                                <span class="package-meta-item">
                                    <?php
                                    $pkg_status_class = '';
                                    if($package['Status'] == 'Not Started') {
                                        $pkg_status_class = 'status-not-started';
                                    } elseif($package['Status'] == 'Active') {
                                        $pkg_status_class = 'status-active';
                                    } elseif($package['Status'] == 'Paused') {
                                        $pkg_status_class = 'status-paused';
                                    } elseif($package['Status'] == 'Completed') {
                                        $pkg_status_class = 'status-completed';
                                    } elseif($package['Status'] == 'Cancelled') {
                                        $pkg_status_class = 'status-cancelled';
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $pkg_status_class; ?>">
                                        <?php echo $package['Status']; ?>
                                    </span>
                                </span>
                                <span class="package-meta-item">
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-tasks me-1"></i>
                                        Segments: <?php echo $package['segment_count']; ?>
                                    </span>
                                </span>
                                <?php if($package['Budget'] > 0): ?>
                                <span class="package-meta-item">
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-money-bill me-1"></i>
                                        ৳<?php echo number_format($package['Budget']); ?>
                                    </span>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="package-actions">
                            <a href="view_package.php?id=<?php echo $package['Package_ID']; ?>" class="action-btn btn-package">
                                <i class="fas fa-cubes me-1"></i> View
                            </a>
                            <a href="delete_package.php?id=<?php echo $package['Package_ID']; ?>" 
                               class="action-btn btn-delete" 
                               onclick="return confirm('Delete this package?')">
                                <i class="fas fa-trash me-1"></i> Delete
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-cubes fa-3x mb-3"></i>
                        <h5>No packages created yet</h5>
                        <p class="mb-3">Create packages to organize project segments and maintenance work.</p>
                        <a href="add_package.php?project_id=<?php echo $project_id; ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-plus me-1"></i>Create First Package
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Urgent Project - Emergency Tasks with Table Layout -->
            <?php if($project_type == 'Urgent' && $urgent_tasks): ?>
            <div class="content-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>
                        <i class="fas fa-exclamation-triangle me-2" style="color: #e53e3e;"></i>
                        Emergency Tasks
                    </h4>
                    <div>
                        <span class="badge bg-secondary me-2"><?php echo $urgent_tasks->num_rows; ?> tasks</span>
                        <a href="add_maintenance.php?project_id=<?php echo $project_id; ?>" class="btn btn-danger btn-sm">
                            <i class="fas fa-plus me-1"></i>New Task
                        </a>
                    </div>
                </div>

                <?php if($urgent_tasks->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                32<th style="width: 10%">Task ID</th>
                                    <th style="width: 20%">Task Type</th>
                                    <th style="width: 30%">Asset</th>
                                    <th style="width: 15%">Status</th>
                                    <th style="width: 15%">Start Date</th>
                                    <th style="width: 10%">Actions</th>
                                </thead>
                            <tbody>
                                <?php while($task = $urgent_tasks->fetch_assoc()): 
                                    $task_status_class = '';
                                    if($task['Status'] == 'Not Started') {
                                        $task_status_class = 'status-not-started';
                                    } elseif($task['Status'] == 'Active') {
                                        $task_status_class = 'status-active';
                                    } elseif($task['Status'] == 'Paused') {
                                        $task_status_class = 'status-paused';
                                    } elseif($task['Status'] == 'Completed') {
                                        $task_status_class = 'status-completed';
                                    } elseif($task['Status'] == 'Cancelled') {
                                        $task_status_class = 'status-cancelled';
                                    }
                                ?>
                                <tr>
                                    <td class="text-center">
                                        <strong>#<?php echo $task['Maintenance_ID']; ?></strong>
                                    </td>
                                    <td>
                                        <span class="task-type-badge">
                                            <i class="fas fa-tasks me-1"></i>
                                            <?php echo htmlspecialchars($task['Task_type'] ?? 'Maintenance'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="asset-badge" title="<?php echo htmlspecialchars($task['Asset_Name'] ?: 'No asset'); ?>">
                                            <i class="fas fa-building me-1"></i> 
                                            <?php echo htmlspecialchars($task['Asset_Name'] ?: 'No asset'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="status-badge <?php echo $task_status_class; ?>">
                                            <?php echo $task['Status']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php echo date('d/m/Y', strtotime($task['Start_Date'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="view_maintenance.php?id=<?php echo $task['Maintenance_ID']; ?>" 
                                           class="action-btn btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                        <h5>No emergency tasks yet</h5>
                        <p class="mb-3">Create an emergency task for immediate response.</p>
                        <a href="add_maintenance.php?project_id=<?php echo $project_id; ?>" class="btn btn-danger btn-sm">
                            <i class="fas fa-plus me-1"></i>Create Emergency Task
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Team Members Section - TABLE LAYOUT -->
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-users me-2" style="color: #e53e3e;"></i>
                        Project Level Team (<?php echo $active_employee_count; ?>)
                    </h4>
                    <a href="add_worker.php?project_id=<?php echo $project_id; ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-plus-circle me-1"></i>Add Employee
                    </a>
                </div>
                
                <?php if(!$has_director): ?>
                <div class="director-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><strong>Note:</strong> No Project Director assigned yet. Add an employee with role "Project Director" to lead this project.</span>
                </div>
                <?php endif; ?>
                
                <?php if($active_employee_count > 0): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                32<th style="width: 8%">ID</th>
                                    <th style="width: 20%">Employee Name</th>
                                    <th style="width: 15%">Role</th>
                                    <th style="width: 12%">Designation</th>
                                    <th style="width: 12%">Salary</th>
                                    <th style="width: 15%">Assigned Date</th>
                                    <th style="width: 8%">Status</th>
                                    <th style="width: 10%">Actions</th>
                                </thead>
                            <tbody>
                                <?php foreach($active_employees as $employee): 
                                    $is_director = ($employee['Role'] == 'Project Director');
                                ?>
                                32
                                    <td class="text-center">
                                        <strong>#<?php echo $employee['Worker_ID']; ?></strong>
                                    </td>
                                    <td>
                                        <i class="fas fa-user-tie me-1" style="color: <?php echo $is_director ? '#d97706' : '#3182ce'; ?>;"></i>
                                        <?php echo htmlspecialchars($employee['Worker_Name']); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if($is_director): ?>
                                            <span class="director-badge">
                                                <i class="fas fa-crown me-1"></i>Director
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
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-users fa-2x mb-2"></i>
                        <p class="mb-2">No active team members assigned to this project</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Details Section -->
            <div class="content-section">
                <h2 class="mb-4 fw-bold text-center border-bottom border-3 border-primary pb-2" style="font-size: 1.1rem;">
                    Project Management
                </h2>

                <div class="d-flex flex-column align-items-stretch gap-3 w-100">
                    <a href="manage_project_workers.php?id=<?php echo $project_id; ?>" 
                       class="detail-link">
                        <span><i class="fas fa-users me-2" style="color: #e53e3e;"></i>Team Management</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>

                    <a href="manage_project_resources.php?id=<?php echo $project_id; ?>" 
                       class="detail-link">
                        <span><i class="fas fa-tools me-2" style="color: #3182ce;"></i>Resources Management</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>

                    <a href="manage_project_budget.php?id=<?php echo $project_id; ?>" 
                       class="detail-link">
                        <span><i class="fas fa-coins me-2" style="color: #38a169;"></i>Budget Overview</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- Legend/Note -->
            <div class="d-flex justify-content-end mb-2">
                <div class="d-flex gap-3" style="font-size: 0.6rem; color: #718096;">
                    <span><i class="fas fa-circle me-1" style="color: #e53e3e;"></i> Team</span>
                    <span><i class="fas fa-circle me-1" style="color: #3182ce;"></i> Resources</span>
                    <span><i class="fas fa-circle me-1" style="color: #38a169;"></i> Budget</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Zone Details Modal -->
    <div class="modal fade" id="zoneDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-map-marker-alt me-2"></i>Zone Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12">
                            <div class="zone-detail-row">
                                <div class="zone-detail-label">Zone ID & Code</div>
                                <div class="zone-detail-value">
                                    <span class="zone-value me-2" id="modalZoneId">-</span>
                                    <span class="zone-value" id="modalZoneCode">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="zone-detail-row">
                                <div class="zone-detail-label">Area within this zone</div>
                                <div class="zone-area-box" id="modalZoneArea">
                                    <i class="fas fa-map me-2"></i>Loading...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Description Modal -->
    <div class="modal fade" id="addDescriptionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Project Description</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="update_project_description" value="1">
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="project_description" class="form-control" rows="5"><?php echo htmlspecialchars($project['Description'] ?? ''); ?></textarea>
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

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Project Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="update_project_status" value="1">
                        <div class="mb-3">
                            <label class="form-label">Project Status</label>
                            <select name="project_status" class="form-select">
                                <option value="Not Started" <?php if($project['Status'] == 'Not Started') echo 'selected'; ?>>Not Started</option>
                                <option value="Active" <?php if($project['Status'] == 'Active') echo 'selected'; ?>>Active</option>
                                <option value="Paused" <?php if($project['Status'] == 'Paused') echo 'selected'; ?>>Paused</option>
                                <option value="Completed" <?php if($project['Status'] == 'Completed') echo 'selected'; ?>>Completed</option>
                                <option value="Cancelled" <?php if($project['Status'] == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if($project_type == 'Large'): ?>
    <!-- Add Zone Modal (Large) - SORTED BY ZONE ID (NUMERIC) -->
    <div class="modal fade" id="addZoneModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Zone to Project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="add_zone" value="1">
                        <div class="mb-3">
                            <label class="form-label">Select Zone</label>
                            <select name="zone_id" class="form-select" required>
                                <option value="">-- Choose Zone --</option>
                                <?php 
                                $all_zones_result->data_seek(0);
                                // Create array and sort by Zone_ID as integer
                                $zones_array = [];
                                while($zone = $all_zones_result->fetch_assoc()) {
                                    $zones_array[] = $zone;
                                }
                                // Sort by Zone_ID numerically
                                usort($zones_array, function($a, $b) {
                                    return (int)$a['Zone_ID'] - (int)$b['Zone_ID'];
                                });
                                foreach($zones_array as $zone): 
                                ?>
                                    <option value="<?php echo $zone['Zone_ID']; ?>">
                                        <?php echo $zone['Zone_Code']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Zone</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($project_type == 'Routine'): ?>
    <!-- Set Zone Modal (Routine) - SORTED BY ZONE ID (NUMERIC) -->
    <div class="modal fade" id="setZoneModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo ($zone_count > 0) ? 'Change Project Zone' : 'Set Project Zone'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="set_zone" value="1">
                        <div class="mb-3">
                            <label class="form-label">Select Zone</label>
                            <select name="zone_id" class="form-select" required>
                                <option value="">-- Choose Zone --</option>
                                <?php 
                                $all_zones_result->data_seek(0);
                                // Create array and sort by Zone_ID as integer
                                $zones_array = [];
                                while($zone = $all_zones_result->fetch_assoc()) {
                                    $zones_array[] = $zone;
                                }
                                // Sort by Zone_ID numerically
                                usort($zones_array, function($a, $b) {
                                    return (int)$a['Zone_ID'] - (int)$b['Zone_ID'];
                                });
                                foreach($zones_array as $zone): 
                                ?>
                                    <option value="<?php echo $zone['Zone_ID']; ?>">
                                        <?php echo $zone['Zone_Code']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><?php echo ($zone_count > 0) ? 'Update Zone' : 'Set Zone'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function toggleBudgetEdit() {
        var displayContainer = document.getElementById('budgetDisplayContainer');
        var editForm = document.getElementById('budgetEditForm');
        
        if (editForm.style.display === 'none' || editForm.style.display === '') {
            displayContainer.style.display = 'none';
            editForm.style.display = 'block';
            setTimeout(function() {
                editForm.querySelector('input').focus();
            }, 100);
        } else {
            displayContainer.style.display = 'flex';
            editForm.style.display = 'none';
        }
    }
    
    // Zone Details Modal
    function showZoneDetails(zoneId, zoneName, zoneCode) {
        document.getElementById('modalZoneId').innerHTML = '<i class="fas fa-hashtag me-1"></i>Zone ID: ' + zoneId;
        document.getElementById('modalZoneCode').innerHTML = '<i class="fas fa-code me-1"></i>' + zoneCode;
        document.getElementById('modalZoneArea').innerHTML = '<i class="fas fa-map me-2"></i>' + zoneName;
        
        var modal = new bootstrap.Modal(document.getElementById('zoneDetailsModal'));
        modal.show();
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