<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

// Get project ID from URL
$project_id = isset($_GET['id']) ? $_GET['id'] : 0;

if($project_id == 0) {
    header("Location: manage_projects.php");
    exit();
}

// Fetch project details
$project_query = "SELECT * FROM projects WHERE Project_ID = ?";
$stmt = $conn->prepare($project_query);
if(!$stmt) {
    die("Project query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project_result = $stmt->get_result();

if($project_result->num_rows === 0) {
    header("Location: manage_projects.php");
    exit();
}

$project = $project_result->fetch_assoc();
$project_type = $project['Project_Type'];

// Get active tab from URL
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

// Get filter parameters
$selected_package = isset($_GET['package_filter']) ? $_GET['package_filter'] : '';
$selected_task = isset($_GET['task_filter']) ? $_GET['task_filter'] : '';

// Handle success messages
if(isset($_GET['success'])) {
    if($_GET['success'] == 'worker_removed') {
        $success_msg = "Employee removed from project successfully!";
    } elseif($_GET['success'] == 'status_updated') {
        $success_msg = "Employee status updated successfully!";
    } elseif($_GET['success'] == 'note_updated') {
        $success_msg = "Note updated successfully!";
    }
}

// First check if Notes column exists, if not add it
$check_column = "SHOW COLUMNS FROM worker_assignments LIKE 'Notes'";
$column_check = $conn->query($check_column);
if($column_check && $column_check->num_rows == 0) {
    $add_column = "ALTER TABLE worker_assignments ADD COLUMN Notes TEXT NULL";
    $conn->query($add_column);
}

// Handle inline worker status update via AJAX
if(isset($_POST['inline_worker_status'])) {
    $assignment_id = $_POST['assignment_id'];
    $new_status = $_POST['worker_status'];
    
    $update_query = "UPDATE worker_assignments SET Status = ? WHERE Assignment_ID = ?";
    $stmt = $conn->prepare($update_query);
    if($stmt) {
        $stmt->bind_param("si", $new_status, $assignment_id);
        
        if($stmt->execute()) {
            // Check if this was a Project Director
            $check_query = "SELECT wa.Role FROM worker_assignments wa WHERE wa.Assignment_ID = ?";
            $check_stmt = $conn->prepare($check_query);
            if($check_stmt) {
                $check_stmt->bind_param("i", $assignment_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $assignment_data = $check_result->fetch_assoc();
                $check_stmt->close();
                
                // If this was the Project Director and status is no longer Active, clear project director field
                if($assignment_data && $assignment_data['Role'] == 'Project Director' && $new_status != 'Active') {
                    $clear_project = "UPDATE projects SET Project_Director = NULL WHERE Project_ID = ?";
                    $clear_stmt = $conn->prepare($clear_project);
                    if($clear_stmt) {
                        $clear_stmt->bind_param("i", $project_id);
                        $clear_stmt->execute();
                        $clear_stmt->close();
                    }
                }
            }
            
            echo json_encode(['success' => true, 'status' => $new_status]);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            exit();
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit();
    }
}

// Handle note update via AJAX
if(isset($_POST['update_note'])) {
    $assignment_id = $_POST['assignment_id'];
    $note = $_POST['note'];
    
    $update_query = "UPDATE worker_assignments SET Notes = ? WHERE Assignment_ID = ?";
    $stmt = $conn->prepare($update_query);
    if($stmt) {
        $stmt->bind_param("si", $note, $assignment_id);
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'note' => $note]);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            exit();
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit();
    }
}

// Get valid worker assignment statuses
$status_query = "SHOW COLUMNS FROM worker_assignments LIKE 'Status'";
$status_result = $conn->query($status_query);
if($status_result && $status_result->num_rows > 0) {
    $status_row = $status_result->fetch_assoc();
    preg_match("/^enum\(\'(.*)\'\)$/", $status_row['Type'], $matches);
    $worker_statuses = explode("','", $matches[1]);
} else {
    $worker_statuses = ['Active', 'Paused', 'Completed', 'Removed'];
}

// Build base URL for tabs
$base_url = "manage_project_workers.php?id=" . $project_id;

// Fetch ALL workers for All Levels view (Current - Active/Paused)
$all_workers_current_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                     wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status,
                                     wa.Notes,
                                     CASE 
                                         WHEN wa.Package_ID IS NOT NULL AND wa.Maintenance_ID IS NULL THEN 'Package'
                                         WHEN wa.Maintenance_ID IS NOT NULL THEN 'Task'
                                         ELSE 'Project'
                                     END as Level,
                                     p.Package_Name, p.Package_ID,
                                     m.Maintenance_ID as Task_ID, m.Task_type,
                                     a.Asset_Name, a.Asset_ID,
                                     z.Zone_Name
                              FROM workers w
                              JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                              LEFT JOIN packages p ON wa.Package_ID = p.Package_ID
                              LEFT JOIN maintenance m ON wa.Maintenance_ID = m.Maintenance_ID
                              LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID
                              LEFT JOIN zones z ON m.Zone_ID = z.Zone_ID
                              WHERE wa.Project_ID = ? AND wa.Status IN ('Active', 'Paused')
                              ORDER BY 
                                  CASE Level
                                      WHEN 'Project' THEN 1
                                      WHEN 'Package' THEN 2
                                      WHEN 'Task' THEN 3
                                  END,
                                  w.Worker_Name";
$stmt = $conn->prepare($all_workers_current_query);
if(!$stmt) {
    die("All workers current query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$all_workers_current_result = $stmt->get_result();
$all_workers_current_count = $all_workers_current_result->num_rows;

// Fetch ALL workers for Past view (Completed/Removed)
$all_workers_past_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                   wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status,
                                   wa.Notes,
                                   CASE 
                                       WHEN wa.Package_ID IS NOT NULL AND wa.Maintenance_ID IS NULL THEN 'Package'
                                       WHEN wa.Maintenance_ID IS NOT NULL THEN 'Task'
                                       ELSE 'Project'
                                   END as Level,
                                   p.Package_Name, p.Package_ID,
                                   m.Maintenance_ID as Task_ID, m.Task_type,
                                   a.Asset_Name, a.Asset_ID,
                                   z.Zone_Name
                            FROM workers w
                            JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                            LEFT JOIN packages p ON wa.Package_ID = p.Package_ID
                            LEFT JOIN maintenance m ON wa.Maintenance_ID = m.Maintenance_ID
                            LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID
                            LEFT JOIN zones z ON m.Zone_ID = z.Zone_ID
                            WHERE wa.Project_ID = ? AND wa.Status IN ('Completed', 'Removed')
                            ORDER BY 
                                CASE Level
                                    WHEN 'Project' THEN 1
                                    WHEN 'Package' THEN 2
                                    WHEN 'Task' THEN 3
                                END,
                                w.Worker_Name";
$stmt = $conn->prepare($all_workers_past_query);
if(!$stmt) {
    die("All workers past query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$all_workers_past_result = $stmt->get_result();
$all_workers_past_count = $all_workers_past_result->num_rows;

// Fetch project workers (project level) - Current (Active/Paused)
$project_workers_current_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                         wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status,
                                         wa.Notes
                                  FROM workers w
                                  JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                                  WHERE wa.Project_ID = ? AND wa.Package_ID IS NULL AND wa.Maintenance_ID IS NULL 
                                    AND wa.Status IN ('Active', 'Paused')
                                  ORDER BY 
                                      CASE wa.Status
                                          WHEN 'Active' THEN 1
                                          WHEN 'Paused' THEN 2
                                      END,
                                      w.Worker_Name ASC";
$stmt = $conn->prepare($project_workers_current_query);
if(!$stmt) {
    die("Project workers current query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project_workers_current_result = $stmt->get_result();
$project_workers_current_count = $project_workers_current_result->num_rows;

// Fetch project workers (project level) - Past (Completed/Removed)
$project_workers_past_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                       wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status,
                                       wa.Notes
                                FROM workers w
                                JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                                WHERE wa.Project_ID = ? AND wa.Package_ID IS NULL AND wa.Maintenance_ID IS NULL 
                                  AND wa.Status IN ('Completed', 'Removed')
                                ORDER BY 
                                    CASE wa.Status
                                        WHEN 'Completed' THEN 1
                                        WHEN 'Removed' THEN 2
                                    END,
                                    w.Worker_Name ASC";
$stmt = $conn->prepare($project_workers_past_query);
if(!$stmt) {
    die("Project workers past query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project_workers_past_result = $stmt->get_result();
$project_workers_past_count = $project_workers_past_result->num_rows;

// Check if a Project Director exists in active assignments
$has_director = false;
$director_name = '';
$project_workers_current_result->data_seek(0);
while($worker = $project_workers_current_result->fetch_assoc()) {
    if($worker['Role'] == 'Project Director' && $worker['Participation_Status'] == 'Active') {
        $has_director = true;
        $director_name = $worker['Worker_Name'];
        break;
    }
}
$project_workers_current_result->data_seek(0);

// If director exists in assignments but not in project table, update project table
if($has_director && empty($project['Project_Director'])) {
    $update_project = "UPDATE projects SET Project_Director = ? WHERE Project_ID = ?";
    $update_stmt = $conn->prepare($update_project);
    if($update_stmt) {
        $update_stmt->bind_param("si", $director_name, $project_id);
        $update_stmt->execute();
        $update_stmt->close();
        $project['Project_Director'] = $director_name;
    }
}
// If director exists in project table but not in active assignments, clear project table
else if(!$has_director && !empty($project['Project_Director'])) {
    $update_project = "UPDATE projects SET Project_Director = NULL WHERE Project_ID = ?";
    $update_stmt = $conn->prepare($update_project);
    if($update_stmt) {
        $update_stmt->bind_param("i", $project_id);
        $update_stmt->execute();
        $update_stmt->close();
        $project['Project_Director'] = null;
    }
}

// Fetch all packages for this project (for filter dropdown)
$packages_list_query = "SELECT Package_ID, Package_Name FROM packages 
                        WHERE Project_ID = ? 
                        ORDER BY Package_Name";
$stmt = $conn->prepare($packages_list_query);
if(!$stmt) {
    die("Packages list query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$packages_list_result = $stmt->get_result();

// Fetch all maintenance tasks for this project (for task filter dropdown)
$tasks_list_query = "SELECT m.Maintenance_ID, m.Task_type, a.Asset_Name 
                     FROM maintenance m 
                     LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID 
                     WHERE m.Project_ID = ? 
                     ORDER BY m.Maintenance_ID DESC";
$stmt = $conn->prepare($tasks_list_query);
if(!$stmt) {
    die("Tasks list query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$tasks_list_result = $stmt->get_result();

// Fetch package workers with optional filter - Current (Active/Paused)
$package_workers_current_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                         wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status,
                                         wa.Notes,
                                         p.Package_Name, p.Package_ID 
                                  FROM workers w
                                  JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                                  JOIN packages p ON wa.Package_ID = p.Package_ID 
                                  WHERE wa.Project_ID = ? AND wa.Package_ID IS NOT NULL AND wa.Maintenance_ID IS NULL
                                    AND wa.Status IN ('Active', 'Paused')";
if(!empty($selected_package)) {
    $package_workers_current_query .= " AND p.Package_ID = " . intval($selected_package);
}
$package_workers_current_query .= " ORDER BY 
                                      CASE wa.Status
                                          WHEN 'Active' THEN 1
                                          WHEN 'Paused' THEN 2
                                      END,
                                      p.Package_Name, w.Worker_Name";
$stmt = $conn->prepare($package_workers_current_query);
if(!$stmt) {
    die("Package workers current query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$package_workers_current_result = $stmt->get_result();
$package_workers_current_count = $package_workers_current_result->num_rows;

// Fetch package workers with optional filter - Past (Completed/Removed)
$package_workers_past_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                       wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status,
                                       wa.Notes,
                                       p.Package_Name, p.Package_ID 
                                FROM workers w
                                JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                                JOIN packages p ON wa.Package_ID = p.Package_ID 
                                WHERE wa.Project_ID = ? AND wa.Package_ID IS NOT NULL AND wa.Maintenance_ID IS NULL
                                  AND wa.Status IN ('Completed', 'Removed')";
if(!empty($selected_package)) {
    $package_workers_past_query .= " AND p.Package_ID = " . intval($selected_package);
}
$package_workers_past_query .= " ORDER BY 
                                    CASE wa.Status
                                        WHEN 'Completed' THEN 1
                                        WHEN 'Removed' THEN 2
                                    END,
                                    p.Package_Name, w.Worker_Name";
$stmt = $conn->prepare($package_workers_past_query);
if(!$stmt) {
    die("Package workers past query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$package_workers_past_result = $stmt->get_result();
$package_workers_past_count = $package_workers_past_result->num_rows;

// Fetch task workers with optional task filter - Current (Active/Paused)
$task_workers_current_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                      wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status,
                                      wa.Notes,
                                      m.Maintenance_ID, m.Task_type, m.Description, m.Status as Task_Status,
                                      a.Asset_Name, a.Asset_ID,
                                      p.Package_Name, p.Package_ID,
                                      z.Zone_Name
                               FROM workers w
                               JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                               JOIN maintenance m ON wa.Maintenance_ID = m.Maintenance_ID 
                               LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID 
                               LEFT JOIN packages p ON m.Package_ID = p.Package_ID 
                               LEFT JOIN zones z ON m.Zone_ID = z.Zone_ID
                               WHERE m.Project_ID = ? AND wa.Maintenance_ID IS NOT NULL
                                 AND wa.Status IN ('Active', 'Paused')";
if(!empty($selected_task)) {
    $task_workers_current_query .= " AND m.Maintenance_ID = " . intval($selected_task);
}
$task_workers_current_query .= " ORDER BY 
                                    CASE wa.Status
                                        WHEN 'Active' THEN 1
                                        WHEN 'Paused' THEN 2
                                    END,
                                    m.Start_Date DESC, w.Worker_Name";
$stmt = $conn->prepare($task_workers_current_query);
if(!$stmt) {
    die("Task workers current query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$task_workers_current_result = $stmt->get_result();
$task_workers_current_count = $task_workers_current_result->num_rows;

// Fetch task workers with optional task filter - Past (Completed/Removed)
$task_workers_past_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Contact, w.Designation,
                                    wa.Role, wa.Assignment_ID, wa.Assigned_Date, wa.Status as Participation_Status,
                                    wa.Notes,
                                    m.Maintenance_ID, m.Task_type, m.Description, m.Status as Task_Status,
                                    a.Asset_Name, a.Asset_ID,
                                    p.Package_Name, p.Package_ID,
                                    z.Zone_Name
                             FROM workers w
                             JOIN worker_assignments wa ON w.Worker_ID = wa.Worker_ID
                             JOIN maintenance m ON wa.Maintenance_ID = m.Maintenance_ID 
                             LEFT JOIN assets a ON m.Asset_ID = a.Asset_ID 
                             LEFT JOIN packages p ON m.Package_ID = p.Package_ID 
                             LEFT JOIN zones z ON m.Zone_ID = z.Zone_ID
                             WHERE m.Project_ID = ? AND wa.Maintenance_ID IS NOT NULL
                               AND wa.Status IN ('Completed', 'Removed')";
if(!empty($selected_task)) {
    $task_workers_past_query .= " AND m.Maintenance_ID = " . intval($selected_task);
}
$task_workers_past_query .= " ORDER BY 
                                  CASE wa.Status
                                      WHEN 'Completed' THEN 1
                                      WHEN 'Removed' THEN 2
                                  END,
                                  m.Start_Date DESC, w.Worker_Name";
$stmt = $conn->prepare($task_workers_past_query);
if(!$stmt) {
    die("Task workers past query failed: " . $conn->error);
}
$stmt->bind_param("i", $project_id);
$stmt->execute();
$task_workers_past_result = $stmt->get_result();
$task_workers_past_count = $task_workers_past_result->num_rows;

// Function to get status badge class
function getStatusBadgeClass($status) {
    switch($status) {
        case 'Active': return 'status-active';
        case 'Paused': return 'status-paused';
        case 'Completed': return 'status-completed';
        case 'Removed': return 'status-removed';
        default: return 'status-not-started';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Team - <?php echo htmlspecialchars($project['Project_Name']); ?> - Smart DNCC</title>
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
            --completed: #6b46c1;
            --removed: #c53030;
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
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 80px;
            text-align: center;
            display: inline-block;
        }
        .status-active {
            background: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        .status-paused {
            background: #fed7aa;
            color: #92400e;
            border: 1px solid #fdba74;
        }
        .status-completed {
            background: #e9d8fd;
            color: #6b46c1;
            border: 1px solid #d6bcfa;
        }
        .status-removed {
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #fc8181;
        }

        .project-banner {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .project-banner h2 {
            color: white;
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .project-badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            min-width: 100px;
            text-align: center;
        }

        .team-stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.2s;
            min-height: 90px;
        }
        .team-stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .team-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .team-stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .team-stat-label {
            color: #718096;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* Stats Row - 4 columns */
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-col {
            flex: 1;
            min-width: 180px;
        }

        /* Filter Section - Inside tabs */
        .filter-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }

        .filter-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.75rem;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-control {
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
            width: 100%;
            background: white;
        }
        .filter-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .filter-row {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .clear-filter {
            color: var(--danger);
            text-decoration: none;
            font-size: 0.75rem;
            padding: 4px 8px;
            white-space: nowrap;
        }

        .nav-tabs {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
        }
        .nav-tabs .nav-link {
            color: #718096;
            font-weight: 600;
            border: none;
            padding: 12px 24px;
            margin: 0 2px;
            min-width: 140px;
            text-align: center;
        }
        .nav-tabs .nav-link.active {
            color: var(--accent);
            background: none;
            border-bottom: 3px solid var(--accent);
        }

        /* Employee Cards - NEW CLEAN DESIGN */
        .employee-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
            border-left: 6px solid var(--accent);
            transition: all 0.2s;
        }
        .employee-card:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
        }

        .employee-card.active-card {
            border-left-color: var(--success);
        }
        .employee-card.paused-card {
            border-left-color: var(--warning);
        }
        .employee-card.completed-card {
            border-left-color: var(--completed);
            opacity: 0.8;
        }
        .employee-card.removed-card {
            border-left-color: var(--removed);
            background: #fff5f5;
            opacity: 0.8;
        }

        .employee-card.director-card {
            background: #fef3c7;
            border-left: 6px solid #d97706;
        }

        /* ID Badge */
        .id-badge {
            background: #e2e8f0;
            color: #2d3748;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        /* Assignment Details Section - DYNAMIC COLUMNS BASED ON COUNT */
        .assignment-details {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            margin: 12px 0;
            border: 1px solid #e2e8f0;
            width: 100%;
        }

        .details-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(0, 1fr));
            gap: 8px;
            width: 100%;
        }

        .detail-chip {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            background: white;
            padding: 6px 10px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            font-size: 0.8rem;
            text-align: center;
            min-width: 0;
        }

        /* Adjust font size based on number of chips */
        .details-grid:has(:nth-child(1 of .detail-chip):last-child) .detail-chip { font-size: 1rem; } /* 1 chip */
        .details-grid:has(:nth-child(2 of .detail-chip):last-child) .detail-chip { font-size: 0.95rem; } /* 2 chips */
        .details-grid:has(:nth-child(3 of .detail-chip):last-child) .detail-chip { font-size: 0.9rem; } /* 3 chips */
        .details-grid:has(:nth-child(4 of .detail-chip):last-child) .detail-chip { font-size: 0.85rem; } /* 4 chips */
        .details-grid:has(:nth-child(5 of .detail-chip):last-child) .detail-chip { font-size: 0.8rem; } /* 5 chips */
        .details-grid:has(:nth-child(6 of .detail-chip):last-child) .detail-chip { font-size: 0.75rem; } /* 6 chips */
        .details-grid:has(:nth-child(7 of .detail-chip):last-child) .detail-chip { font-size: 0.7rem; } /* 7 chips */
        .details-grid:has(:nth-child(8 of .detail-chip):last-child) .detail-chip { font-size: 0.65rem; } /* 8 chips */

        /* Fallback for browsers that don't support :has() */
        @supports not selector(:has(*)) {
            .details-grid {
                display: flex;
                flex-wrap: wrap;
            }
            .detail-chip {
                flex: 1 1 auto;
                font-size: 0.8rem;
                min-width: 150px;
            }
        }

        .detail-chip .text-muted {
            font-size: 0.7em;
            font-weight: 500;
            color: #64748b;
            white-space: nowrap;
        }

        .detail-chip span:not(.text-muted) {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }

        .package-link, .task-link, .asset-link {
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
            display: inline-block;
            vertical-align: middle;
        }

        .package-link {
            color: #805ad5;
        }
        .package-link:hover {
            text-decoration: underline;
        }

        .task-link {
            color: #38a169;
        }
        .task-link:hover {
            text-decoration: underline;
        }

        .asset-link {
            color: #3182ce;
        }
        .asset-link:hover {
            text-decoration: underline;
        }

        /* Level badges */
        .badge-project-level { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; min-width: 70px; text-align: center; }
        .badge-package-level { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; min-width: 70px; text-align: center; }
        .badge-task-level { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; min-width: 70px; text-align: center; }
        .badge-director { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; min-width: 70px; text-align: center; }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #edf2f7;
            color: #4a5568;
            min-width: 80px;
            text-align: center;
        }

        /* Worker Status Display */
        .worker-status-display {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            padding: 4px 12px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .worker-status-text {
            font-size: 0.75rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        .worker-status-edit-btn {
            background: none;
            border: none;
            color: var(--success);
            cursor: pointer;
            font-size: 0.7rem;
            padding: 2px 6px;
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
            font-size: 0.7rem;
            min-width: 90px;
        }
        
        .worker-status-save-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            cursor: pointer;
        }
        
        .worker-status-cancel-btn {
            background: #e2e8f0;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            cursor: pointer;
        }

        /* Note Section */
        .note-section {
            margin-top: 12px;
            padding: 12px;
            background: #fff9f0;
            border: 1px solid #ffedd5;
            border-radius: 8px;
        }

        .note-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: #9a3412;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .note-content {
            font-size: 0.85rem;
            color: #4b5563;
            background: white;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #fed7aa;
        }

        .note-edit {
            display: none;
            margin-top: 8px;
        }

        .note-edit textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #fed7aa;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 8px;
        }

        .note-edit textarea:focus {
            outline: none;
            border-color: #f59e0b;
            box-shadow: 0 0 0 2px rgba(245,158,11,0.1);
        }

        .note-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .note-save-btn {
            background: #f59e0b;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            cursor: pointer;
        }
        .note-save-btn:hover {
            background: #d97706;
        }

        .note-cancel-btn {
            background: #e2e8f0;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            cursor: pointer;
        }

        .note-edit-btn {
            background: none;
            border: none;
            color: #9a3412;
            cursor: pointer;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .note-edit-btn:hover {
            background: #fed7aa;
        }

        /* Buttons */
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid transparent;
            transition: all 0.2s;
            min-width: 70px;
            justify-content: center;
        }

        .btn-view-employee {
            background: #ebf8ff;
            color: #3182ce;
            border-color: #bee3f8;
        }
        .btn-view-employee:hover {
            background: #3182ce;
            color: white;
            border-color: #3182ce;
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
        }
        .btn-back:hover {
            background: #718096;
            color: white;
        }

        .employee-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #edf2f7;
            align-items: center;
            flex-wrap: wrap;
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

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 70px;
            text-align: center;
            display: inline-block;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: #f8fafc;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px;
            white-space: nowrap;
        }

        .table td {
            padding: 12px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.85rem;
        }

        .empty-state {
            background: #f8fafc;
            border: 2px dashed #cbd5e0;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            color: #718096;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            color: #cbd5e0;
        }

        .director-message {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        h2, h4 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.4rem; }
        h4 { font-size: 1rem; }

        .text-muted {
            color: #718096 !important;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .project-banner {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .nav-tabs {
                flex-direction: column;
                align-items: center;
            }
            
            .nav-tabs .nav-link {
                width: 100%;
                margin-bottom: 2px;
            }
            
            .filter-row {
                flex-direction: column;
                gap: 12px;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .employee-card {
                flex-direction: column;
                gap: 12px;
            }
            
            .employee-actions {
                width: 100%;
                justify-content: center;
            }
            
            /* Mobile adjustments for details grid */
            .details-grid {
                grid-template-columns: 1fr !important;
            }
            
            .detail-chip {
                font-size: 0.8rem !important;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Project Team</h2>
           
            </div>
            <div>
                <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Project
                </a>
            </div>
        </div>
        
        <div class="content-area">
            
            <!-- Project Banner -->
            <div class="project-banner">
                <div>
                    <div class="d-flex gap-2 mb-2 flex-wrap">
                        <span class="project-badge">
                            <i class="fas 
                                <?php 
                                if($project_type == 'Large') echo 'fa-building';
                                elseif($project_type == 'Routine') echo 'fa-calendar-check';
                                else echo 'fa-exclamation';
                                ?> me-2">
                            </i>
                            <?php echo $project_type; ?>
                        </span>
                        <span class="project-badge">
                            <i class="fas fa-hashtag me-2"></i>ID: <?php echo $project_id; ?>
                        </span>
                    </div>
                    <h2><?php echo htmlspecialchars($project['Project_Name']); ?></h2>
                    <div class="mt-2">
                        <i class="fas fa-user-tie me-2"></i>
                        <strong>Director:</strong> 
                        <?php echo $project['Project_Director'] ? htmlspecialchars($project['Project_Director']) : '<span class="text-warning">Not Assigned</span>'; ?>
                    </div>
                </div>
                <div>
                    <span class="badge fs-6 p-3
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

            <!-- Success/Error Messages -->
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

            <!-- Team Stats - 4 columns -->
            <div class="stats-row">
                <div class="stat-col">
                    <div class="team-stat-card">
                        <div class="team-stat-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="team-stat-number"><?php echo $all_workers_current_count; ?> </div>
                            <div class="team-stat-label">All Levels</div>
                        </div>
                    </div>
                </div>
                <div class="stat-col">
                    <div class="team-stat-card">
                        <div class="team-stat-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-folder-open"></i>
                        </div>
                        <div>
                            <div class="team-stat-number"><?php echo $project_workers_current_count; ?> </div>
                            <div class="team-stat-label">Project Level</div>
                        </div>
                    </div>
                </div>
                <div class="stat-col">
                    <div class="team-stat-card">
                        <div class="team-stat-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <div>
                            <div class="team-stat-number"><?php echo $package_workers_current_count; ?> </div>
                            <div class="team-stat-label">Package Level</div>
                        </div>
                    </div>
                </div>
                <div class="stat-col">
                    <div class="team-stat-card">
                        <div class="team-stat-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div>
                            <div class="team-stat-number"><?php echo $task_workers_current_count; ?> </div>
                            <div class="team-stat-label">Task Level</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Centered Tabs -->
            <ul class="nav nav-tabs" id="teamTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo ($active_tab == 'all') ? 'active' : ''; ?>" 
                       href="?id=<?php echo $project_id; ?>&tab=all&package_filter=<?php echo $selected_package; ?>&task_filter=<?php echo $selected_task; ?>">
                        <i class="fas fa-users me-2"></i>
                        All Levels (<?php echo $all_workers_current_count; ?>)
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo ($active_tab == 'project') ? 'active' : ''; ?>" 
                       href="?id=<?php echo $project_id; ?>&tab=project&package_filter=<?php echo $selected_package; ?>&task_filter=<?php echo $selected_task; ?>">
                        <i class="fas fa-folder-open me-2"></i>
                        Project Level (<?php echo $project_workers_current_count; ?>)
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo ($active_tab == 'package') ? 'active' : ''; ?>" 
                       href="?id=<?php echo $project_id; ?>&tab=package&package_filter=<?php echo $selected_package; ?>&task_filter=<?php echo $selected_task; ?>">
                        <i class="fas fa-cubes me-2"></i>
                        Package Level (<?php echo $package_workers_current_count; ?>)
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo ($active_tab == 'task') ? 'active' : ''; ?>" 
                       href="?id=<?php echo $project_id; ?>&tab=task&package_filter=<?php echo $selected_package; ?>&task_filter=<?php echo $selected_task; ?>">
                        <i class="fas fa-tasks me-2"></i>
                        Task Level (<?php echo $task_workers_current_count; ?>)
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo ($active_tab == 'past') ? 'active' : ''; ?>" 
                       href="?id=<?php echo $project_id; ?>&tab=past&package_filter=<?php echo $selected_package; ?>&task_filter=<?php echo $selected_task; ?>">
                        <i class="fas fa-history me-2"></i>
                        Past Employees (<?php echo $all_workers_past_count; ?>)
                    </a>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content">
                
                <!-- ALL LEVELS - CURRENT -->
                <?php if($active_tab == 'all'): ?>
                <div class="tab-pane fade show active">
                    <div class="content-section">
                        <h4 class="mb-3">
                            <i class="fas fa-users me-2" style="color: #3182ce;"></i>
                            Current Team Members (Active/Paused)
                        </h4>
                        
                        <?php if($all_workers_current_count > 0): ?>
                            <?php while($employee = $all_workers_current_result->fetch_assoc()): 
                                $card_class = '';
                                if($employee['Participation_Status'] == 'Active') $card_class = 'active-card';
                                elseif($employee['Participation_Status'] == 'Paused') $card_class = 'paused-card';
                                
                                $is_director = ($employee['Role'] == 'Project Director' && $employee['Level'] == 'Project');
                            ?>
                                <div class="employee-card <?php echo $card_class; ?> <?php echo $is_director ? 'director-card' : ''; ?>">
                                    <!-- Row 1: ID and Name -->
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="id-badge me-2">ID: <?php echo $employee['Worker_ID']; ?></span>
                                        <h5 class="mb-0" style="font-size: 1.1rem; font-weight: 600;"><?php echo htmlspecialchars($employee['Worker_Name']); ?></h5>
                                    </div>
                                    
                                    <!-- Row 2: Assignment Details -->
                                    <!-- Row 2: Assignment Details - DYNAMIC COLUMNS BASED ON COUNT -->
<div class="assignment-details">
    <div class="details-label">Assignment Details:</div>
    <div class="details-grid">
        <!-- Level -->
        <div class="detail-chip">
            <span class="text-muted">Level:</span>
            <span class="badge 
                <?php 
                if($employee['Level'] == 'Project') echo 'badge-project-level';
                elseif($employee['Level'] == 'Package') echo 'badge-package-level';
                elseif($employee['Level'] == 'Task') echo 'badge-task-level';
                ?>">
                <?php echo $employee['Level']; ?>
            </span>
        </div>
        
        <!-- Package (if applicable) -->
        <?php if($employee['Package_ID']): ?>
        <div class="detail-chip">
            <span class="text-muted">Package:</span>
            <a href="view_package.php?id=<?php echo $employee['Package_ID']; ?>" class="package-link" title="<?php echo htmlspecialchars($employee['Package_Name']); ?>">
                ID-<?php echo $employee['Package_ID']; ?>
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Task (if applicable) -->
        <?php if($employee['Task_ID']): ?>
        <div class="detail-chip">
            <span class="text-muted">Task:</span>
            <a href="view_maintenance.php?id=<?php echo $employee['Task_ID']; ?>" class="task-link" title="<?php echo htmlspecialchars($employee['Task_type']); ?>">
                ID-<?php echo $employee['Task_ID']; ?>
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Role -->
        <div class="detail-chip">
            <span class="text-muted">Role:</span>
            <span class="fw-semibold" title="<?php echo htmlspecialchars($employee['Role']); ?>"><?php echo htmlspecialchars($employee['Role']); ?></span>
        </div>
        
        <!-- Time -->
        <div class="detail-chip">
            <span class="text-muted">Since:</span>
            <span><?php echo date('M Y', strtotime($employee['Assigned_Date'])); ?></span>
        </div>
        
        
        <div class="detail-chip">
            <span class="text-muted">Designation:</span>
            <span title="<?php echo htmlspecialchars($employee['Designation']); ?>"><?php echo htmlspecialchars($employee['Designation']); ?></span>
        </div>
      

    </div>
</div>
                                    <!-- Note Section -->
                                    <div class="note-section">
                                        <div class="note-header">
                                            <i class="fas fa-sticky-note"></i>
                                            <span>Admin Note</span>
                                            <button class="note-edit-btn" onclick="showNoteEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                        <div id="note-display-<?php echo $employee['Assignment_ID']; ?>" class="note-content">
                                            <?php echo !empty($employee['Notes']) ? nl2br(htmlspecialchars($employee['Notes'])) : 'No notes added yet.'; ?>
                                        </div>
                                        <div id="note-edit-<?php echo $employee['Assignment_ID']; ?>" class="note-edit">
                                            <textarea id="note-text-<?php echo $employee['Assignment_ID']; ?>" rows="2" placeholder="Add notes about this employee..."><?php echo htmlspecialchars($employee['Notes'] ?? ''); ?></textarea>
                                            <div class="note-actions">
                                                <button class="note-cancel-btn" onclick="cancelNoteEdit(<?php echo $employee['Assignment_ID']; ?>)">Cancel</button>
                                                <button class="note-save-btn" onclick="saveNote(<?php echo $employee['Assignment_ID']; ?>)">Save Note</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Actions Row: Status + View Button -->
                                    <div class="employee-actions">
                                        <!-- Status Display with Edit Button -->
                                        <div id="worker-status-display-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-display">
                                            <span class="worker-status-text">Status:</span>
                                            <span class="status-badge <?php echo getStatusBadgeClass($employee['Participation_Status']); ?>">
                                                <?php echo $employee['Participation_Status']; ?>
                                            </span>
                                            <button type="button" class="worker-status-edit-btn" onclick="showWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-edit" style="color: var(--success);"></i>
                                            </button>
                                        </div>
                                        
                                        <!-- Status Edit Form -->
                                        <div id="worker-status-edit-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-edit-form">
                                            <select id="worker-status-select-<?php echo $employee['Assignment_ID']; ?>" class="form-select">
                                                <?php foreach($worker_statuses as $status_option): ?>
                                                    <option value="<?php echo $status_option; ?>" 
                                                        <?php echo ($employee['Participation_Status'] == $status_option) ? 'selected' : ''; ?>>
                                                        <?php echo $status_option; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="worker-status-save-btn" onclick="saveWorkerStatus(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="worker-status-cancel-btn" onclick="cancelWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>

                                        <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view-employee">
                                            <i class="fas fa-user"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-users fa-3x mb-3"></i>
                                <h5>No Current Team Members</h5>
                                <p class="mb-0">No active or paused employees found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- PROJECT LEVEL - CURRENT -->
                <?php if($active_tab == 'project'): ?>
                <div class="tab-pane fade show active">
                    <div class="content-section">
                        <h4 class="mb-3">
                            <i class="fas fa-user-tie me-2" style="color: #3182ce;"></i>
                            Project Level - Current Team
                        </h4>
                        
                        <?php if($project_workers_current_count > 0): ?>
                            <?php while($employee = $project_workers_current_result->fetch_assoc()): 
                                $is_director = ($employee['Role'] == 'Project Director');
                                $card_class = '';
                                if($employee['Participation_Status'] == 'Active') $card_class = 'active-card';
                                elseif($employee['Participation_Status'] == 'Paused') $card_class = 'paused-card';
                            ?>
                                <div class="employee-card <?php echo $card_class; ?> <?php echo $is_director ? 'director-card' : ''; ?>">
                                    <!-- Row 1: ID and Name -->
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="id-badge me-2">ID: <?php echo $employee['Worker_ID']; ?></span>
                                        <h5 class="mb-0" style="font-size: 1.1rem; font-weight: 600;"><?php echo htmlspecialchars($employee['Worker_Name']); ?></h5>
                                    </div>
                                    
                                    <!-- Row 2: Assignment Details -->
                                    <div class="assignment-details">
                                        <div class="details-label">Assignment Details:</div>
                                        <div class="details-grid">
                                            <!-- Level -->
                                            <div class="detail-chip">
                                                <span class="text-muted">Level:</span>
                                                <span class="badge badge-project-level">Project</span>
                                            </div>
                                            
                                            <!-- Role -->
                                            <div class="detail-chip">
                                                <span class="text-muted">Role:</span>
                                                <span class="fw-semibold"><?php echo htmlspecialchars($employee['Role']); ?></span>
                                            </div>
                                            
                                            <!-- Time -->
                                            <div class="detail-chip">
                                                <span class="text-muted">Since:</span>
                                                <span><?php echo date('M Y', strtotime($employee['Assigned_Date'])); ?></span>
                                            </div>
                                            
                                            
                                            <div class="detail-chip">
                                                <span class="text-muted">Designation:</span>
                                                <span><?php echo htmlspecialchars($employee['Designation']); ?></span>
                                            </div>
                                          
                                        </div>
                                    </div>

                                    <!-- Note Section -->
                                    <div class="note-section">
                                        <div class="note-header">
                                            <i class="fas fa-sticky-note"></i>
                                            <span>Admin Note</span>
                                            <button class="note-edit-btn" onclick="showNoteEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                        <div id="note-display-<?php echo $employee['Assignment_ID']; ?>" class="note-content">
                                            <?php echo !empty($employee['Notes']) ? nl2br(htmlspecialchars($employee['Notes'])) : 'No notes added yet.'; ?>
                                        </div>
                                        <div id="note-edit-<?php echo $employee['Assignment_ID']; ?>" class="note-edit">
                                            <textarea id="note-text-<?php echo $employee['Assignment_ID']; ?>" rows="2" placeholder="Add notes..."><?php echo htmlspecialchars($employee['Notes'] ?? ''); ?></textarea>
                                            <div class="note-actions">
                                                <button class="note-cancel-btn" onclick="cancelNoteEdit(<?php echo $employee['Assignment_ID']; ?>)">Cancel</button>
                                                <button class="note-save-btn" onclick="saveNote(<?php echo $employee['Assignment_ID']; ?>)">Save</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Actions Row: Status + View Button -->
                                    <div class="employee-actions">
                                        <!-- Status Display with Edit Button -->
                                        <div id="worker-status-display-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-display">
                                            <span class="worker-status-text">Status:</span>
                                            <span class="status-badge <?php echo getStatusBadgeClass($employee['Participation_Status']); ?>">
                                                <?php echo $employee['Participation_Status']; ?>
                                            </span>
                                            <button type="button" class="worker-status-edit-btn" onclick="showWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-edit" style="color: var(--success);"></i>
                                            </button>
                                        </div>
                                        
                                        <!-- Status Edit Form -->
                                        <div id="worker-status-edit-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-edit-form">
                                            <select id="worker-status-select-<?php echo $employee['Assignment_ID']; ?>" class="form-select">
                                                <?php foreach($worker_statuses as $status_option): ?>
                                                    <option value="<?php echo $status_option; ?>" 
                                                        <?php echo ($employee['Participation_Status'] == $status_option) ? 'selected' : ''; ?>>
                                                        <?php echo $status_option; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="worker-status-save-btn" onclick="saveWorkerStatus(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="worker-status-cancel-btn" onclick="cancelWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>

                                        <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view-employee">
                                            <i class="fas fa-user"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-user-tie fa-3x mb-3"></i>
                                <h5>No Current Project Level Employees</h5>
                                <p class="mb-0">No active or paused employees at project level.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- PACKAGE LEVEL - CURRENT -->
                <?php if($active_tab == 'package'): ?>
                <div class="tab-pane fade show active">
                    <div class="content-section">
                        <h4 class="mb-3">
                            <i class="fas fa-cubes me-2" style="color: #805ad5;"></i>
                            Package Level - Current Team
                        </h4>
                        
                        <!-- Package Filter -->
                        <div class="filter-section">
                            <form method="GET" action="manage_project_workers.php" id="packageFilterForm">
                                <input type="hidden" name="id" value="<?php echo $project_id; ?>">
                                <input type="hidden" name="tab" value="package">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <div class="filter-label">Filter by Package</div>
                                        <select name="package_filter" class="filter-control" onchange="this.form.submit()">
                                            <option value="">All Packages</option>
                                            <?php 
                                            $packages_list_result->data_seek(0);
                                            while($pkg = $packages_list_result->fetch_assoc()): 
                                            ?>
                                                <option value="<?php echo $pkg['Package_ID']; ?>" 
                                                    <?php echo ($selected_package == $pkg['Package_ID']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($pkg['Package_Name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <?php if(!empty($selected_package)): ?>
                                    <div class="filter-group" style="flex: 0 0 auto;">
                                        <a href="?id=<?php echo $project_id; ?>&tab=package" class="clear-filter">
                                            <i class="fas fa-times-circle me-1"></i>Clear Filter
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                        
                        <?php if($package_workers_current_count > 0): ?>
                            <?php 
                            $current_package = '';
                            while($employee = $package_workers_current_result->fetch_assoc()): 
                                $card_class = '';
                                if($employee['Participation_Status'] == 'Active') $card_class = 'active-card';
                                elseif($employee['Participation_Status'] == 'Paused') $card_class = 'paused-card';
                                
                                if($current_package != $employee['Package_Name']):
                                    $current_package = $employee['Package_Name'];
                            ?>
                                    <h6 class="mt-3 mb-2" style="color: #805ad5;">
                                        <i class="fas fa-cube me-2"></i>
                                        <?php echo htmlspecialchars($employee['Package_Name']); ?>
                                    </h6>
                            <?php endif; ?>
                                <div class="employee-card <?php echo $card_class; ?>" style="border-left-color: #805ad5;">
                                    <!-- Row 1: ID and Name -->
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="id-badge me-2">ID: <?php echo $employee['Worker_ID']; ?></span>
                                        <h5 class="mb-0" style="font-size: 1.1rem; font-weight: 600;"><?php echo htmlspecialchars($employee['Worker_Name']); ?></h5>
                                    </div>
                                    
                                    <!-- Row 2: Assignment Details -->
                                    <div class="assignment-details">
                                        <div class="details-label">Assignment Details:</div>
                                        <div class="details-grid">
                                            <!-- Level -->
                                            <div class="detail-chip">
                                                <span class="text-muted">Level:</span>
                                                <span class="badge badge-package-level">Package</span>
                                            </div>
                                            
                                            <!-- Package -->
                                            <div class="detail-chip">
                                                <span class="text-muted">Package:</span>
                                                <a href="view_package.php?id=<?php echo $employee['Package_ID']; ?>" class="package-link">
                                                    ID-<?php echo $employee['Package_ID']; ?>
                                                </a>
                                            </div>
                                            
                                            <!-- Role -->
                                            <div class="detail-chip">
                                                <span class="text-muted">Role:</span>
                                                <span class="fw-semibold"><?php echo htmlspecialchars($employee['Role']); ?></span>
                                            </div>
                                            
                                            <!-- Time -->
                                            <div class="detail-chip">
                                                <span class="text-muted">Since:</span>
                                                <span><?php echo date('M Y', strtotime($employee['Assigned_Date'])); ?></span>
                                            </div>
                                            
                                            <!-- Designation (if exists) -->
                                            <?php if($employee['Designation']): ?>
                                            <div class="detail-chip">
                                                <span class="text-muted">Designation:</span>
                                                <span><?php echo htmlspecialchars($employee['Designation']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Note Section -->
                                    <div class="note-section">
                                        <div class="note-header">
                                            <i class="fas fa-sticky-note"></i>
                                            <span>Admin Note</span>
                                            <button class="note-edit-btn" onclick="showNoteEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                        <div id="note-display-<?php echo $employee['Assignment_ID']; ?>" class="note-content">
                                            <?php echo !empty($employee['Notes']) ? nl2br(htmlspecialchars($employee['Notes'])) : 'No notes added yet.'; ?>
                                        </div>
                                        <div id="note-edit-<?php echo $employee['Assignment_ID']; ?>" class="note-edit">
                                            <textarea id="note-text-<?php echo $employee['Assignment_ID']; ?>" rows="2" placeholder="Add notes..."><?php echo htmlspecialchars($employee['Notes'] ?? ''); ?></textarea>
                                            <div class="note-actions">
                                                <button class="note-cancel-btn" onclick="cancelNoteEdit(<?php echo $employee['Assignment_ID']; ?>)">Cancel</button>
                                                <button class="note-save-btn" onclick="saveNote(<?php echo $employee['Assignment_ID']; ?>)">Save</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Actions Row: Status + View Button -->
                                    <div class="employee-actions">
                                        <!-- Status Display with Edit Button -->
                                        <div id="worker-status-display-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-display">
                                            <span class="worker-status-text">Status:</span>
                                            <span class="status-badge <?php echo getStatusBadgeClass($employee['Participation_Status']); ?>">
                                                <?php echo $employee['Participation_Status']; ?>
                                            </span>
                                            <button type="button" class="worker-status-edit-btn" onclick="showWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-edit" style="color: var(--success);"></i>
                                            </button>
                                        </div>
                                        
                                        <!-- Status Edit Form -->
                                        <div id="worker-status-edit-<?php echo $employee['Assignment_ID']; ?>" class="worker-status-edit-form">
                                            <select id="worker-status-select-<?php echo $employee['Assignment_ID']; ?>" class="form-select">
                                                <?php foreach($worker_statuses as $status_option): ?>
                                                    <option value="<?php echo $status_option; ?>" 
                                                        <?php echo ($employee['Participation_Status'] == $status_option) ? 'selected' : ''; ?>>
                                                        <?php echo $status_option; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="worker-status-save-btn" onclick="saveWorkerStatus(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="worker-status-cancel-btn" onclick="cancelWorkerStatusEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>

                                        <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view-employee">
                                            <i class="fas fa-user"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-cubes fa-3x mb-3"></i>
                                <h5>No Current Package Level Employees</h5>
                                <p class="mb-0">No active or paused employees at package level.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TASK LEVEL - CURRENT -->
                <?php if($active_tab == 'task'): ?>
                <div class="tab-pane fade show active">
                    <div class="content-section">
                        <h4 class="mb-3">
                            <i class="fas fa-tasks me-2" style="color: #38a169;"></i>
                            Task Level - Current Team
                        </h4>
                        
                        <!-- Task Filter -->
                        <div class="filter-section">
                            <form method="GET" action="manage_project_workers.php" id="taskFilterForm">
                                <input type="hidden" name="id" value="<?php echo $project_id; ?>">
                                <input type="hidden" name="tab" value="task">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <div class="filter-label">Filter by Task</div>
                                        <select name="task_filter" class="filter-control" onchange="this.form.submit()">
                                            <option value="">All Tasks</option>
                                            <?php 
                                            $tasks_list_result->data_seek(0);
                                            while($task = $tasks_list_result->fetch_assoc()): 
                                            ?>
                                                <option value="<?php echo $task['Maintenance_ID']; ?>" 
                                                    <?php echo ($selected_task == $task['Maintenance_ID']) ? 'selected' : ''; ?>>
                                                    #<?php echo $task['Maintenance_ID']; ?> - <?php echo htmlspecialchars($task['Task_type']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <?php if(!empty($selected_task)): ?>
                                    <div class="filter-group" style="flex: 0 0 auto;">
                                        <a href="?id=<?php echo $project_id; ?>&tab=task" class="clear-filter">
                                            <i class="fas fa-times-circle me-1"></i>Clear Filter
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                        
                        <?php if($task_workers_current_count > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Task ID</th>
                                            <th>Employee</th>
                                            <th>Role</th>
                                            <th>Task Details</th>
                                            <th>Asset / Package</th>
                                            <th>Zone</th>
                                            <th>Task Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($employee = $task_workers_current_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <span class="badge badge-task-level">
                                                    #<?php echo $employee['Maintenance_ID']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($employee['Worker_Name']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar me-1"></i><?php echo date('M Y', strtotime($employee['Assigned_Date'])); ?>
                                                </small>
                                                <div class="mt-1">
                                                    <span class="status-badge <?php echo getStatusBadgeClass($employee['Participation_Status']); ?>">
                                                        <?php echo $employee['Participation_Status']; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="role-badge"><?php echo htmlspecialchars($employee['Role']); ?></span>
                                                <?php if($employee['Designation']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($employee['Designation']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($employee['Task_type']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars(substr($employee['Description'], 0, 50)); ?>...</small>
                                            </td>
                                            <td>
                                                <?php if($employee['Asset_Name']): ?>
                                                    <i class="fas fa-building me-1" style="color: #3182ce;"></i>
                                                    #<?php echo $employee['Asset_ID']; ?> - <?php echo htmlspecialchars($employee['Asset_Name']); ?>
                                                    <br>
                                                <?php endif; ?>
                                                <?php if($employee['Package_Name']): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-cube me-1"></i>
                                                        <?php echo htmlspecialchars($employee['Package_Name']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($employee['Zone_Name']): ?>
                                                    <i class="fas fa-map-marker-alt me-1" style="color: #e53e3e;"></i>
                                                    <?php echo htmlspecialchars($employee['Zone_Name']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge 
                                                    <?php 
                                                    if($employee['Task_Status'] == 'Active') echo 'bg-success';
                                                    elseif($employee['Task_Status'] == 'Paused') echo 'bg-warning';
                                                    elseif($employee['Task_Status'] == 'Not Started') echo 'bg-secondary';
                                                    elseif($employee['Task_Status'] == 'Completed') echo 'bg-info';
                                                    elseif($employee['Task_Status'] == 'Cancelled') echo 'bg-danger';
                                                    ?>">
                                                    <?php echo $employee['Task_Status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1 flex-wrap">
                                                    <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view-employee">
                                                        <i class="fas fa-user"></i> View
                                                    </a>
                                                    <a href="view_maintenance.php?id=<?php echo $employee['Maintenance_ID']; ?>" class="action-btn btn-view-task">
                                                        <i class="fas fa-tasks"></i> Task
                                                    </a>
                                                    <?php if($employee['Package_ID']): ?>
                                                        <a href="view_package.php?id=<?php echo $employee['Package_ID']; ?>" class="action-btn btn-view-package">
                                                            <i class="fas fa-cubes"></i> Package
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if($employee['Asset_ID']): ?>
                                                        <a href="view_asset.php?id=<?php echo $employee['Asset_ID']; ?>" class="action-btn btn-view-asset">
                                                            <i class="fas fa-building"></i> Asset
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Notes for Task Level -->
                            <div class="mt-4">
                                <h5 class="mb-2">Employee Notes</h5>
                                <?php 
                                $task_workers_current_result->data_seek(0);
                                while($employee = $task_workers_current_result->fetch_assoc()): 
                                ?>
                                <div class="employee-card" style="margin-bottom: 8px; padding: 12px;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($employee['Worker_Name']); ?></strong> 
                                            <small class="text-muted">(Task #<?php echo $employee['Maintenance_ID']; ?>)</small>
                                        </div>
                                        <button class="note-edit-btn" onclick="showNoteEdit(<?php echo $employee['Assignment_ID']; ?>)">
                                            <i class="fas fa-edit"></i> Edit Note
                                        </button>
                                    </div>
                                    <div id="note-display-<?php echo $employee['Assignment_ID']; ?>" class="note-content mt-2">
                                        <?php echo !empty($employee['Notes']) ? nl2br(htmlspecialchars($employee['Notes'])) : 'No notes added yet.'; ?>
                                    </div>
                                    <div id="note-edit-<?php echo $employee['Assignment_ID']; ?>" class="note-edit mt-2">
                                        <textarea id="note-text-<?php echo $employee['Assignment_ID']; ?>" rows="2" placeholder="Add notes..."><?php echo htmlspecialchars($employee['Notes'] ?? ''); ?></textarea>
                                        <div class="note-actions">
                                            <button class="note-cancel-btn" onclick="cancelNoteEdit(<?php echo $employee['Assignment_ID']; ?>)">Cancel</button>
                                            <button class="note-save-btn" onclick="saveNote(<?php echo $employee['Assignment_ID']; ?>)">Save</button>
                                        </div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-tasks fa-3x mb-3"></i>
                                <h5>No Current Task Level Employees</h5>
                                <p class="mb-0">No active or paused employees at task level.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- PAST EMPLOYEES - ALL LEVELS -->
                <?php if($active_tab == 'past'): ?>
                <div class="tab-pane fade show active">
                    <div class="content-section">
                        <h4 class="mb-3">
                            <i class="fas fa-history me-2" style="color: #718096;"></i>
                            Past Employees (Completed/Removed)
                        </h4>
                        
                        <!-- Package/Task Filters for Past -->
                        <div class="filter-section">
                            <form method="GET" action="manage_project_workers.php" id="pastFilterForm">
                                <input type="hidden" name="id" value="<?php echo $project_id; ?>">
                                <input type="hidden" name="tab" value="past">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <div class="filter-label">Filter by Package</div>
                                        <select name="package_filter" class="filter-control" onchange="this.form.submit()">
                                            <option value="">All Packages</option>
                                            <?php 
                                            $packages_list_result->data_seek(0);
                                            while($pkg = $packages_list_result->fetch_assoc()): 
                                            ?>
                                                <option value="<?php echo $pkg['Package_ID']; ?>" 
                                                    <?php echo ($selected_package == $pkg['Package_ID']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($pkg['Package_Name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="filter-group">
                                        <div class="filter-label">Filter by Task</div>
                                        <select name="task_filter" class="filter-control" onchange="this.form.submit()">
                                            <option value="">All Tasks</option>
                                            <?php 
                                            $tasks_list_result->data_seek(0);
                                            while($task = $tasks_list_result->fetch_assoc()): 
                                            ?>
                                                <option value="<?php echo $task['Maintenance_ID']; ?>" 
                                                    <?php echo ($selected_task == $task['Maintenance_ID']) ? 'selected' : ''; ?>>
                                                    #<?php echo $task['Maintenance_ID']; ?> - <?php echo htmlspecialchars($task['Task_type']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <?php if(!empty($selected_package) || !empty($selected_task)): ?>
                                    <div class="filter-group" style="flex: 0 0 auto;">
                                        <a href="?id=<?php echo $project_id; ?>&tab=past" class="clear-filter">
                                            <i class="fas fa-times-circle me-1"></i>Clear All
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                        
                        <?php if($all_workers_past_count > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Level</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Package/Task</th>
                                            <th>Assigned Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($employee = $all_workers_past_result->fetch_assoc()): ?>
                                        <tr class="<?php echo ($employee['Participation_Status'] == 'Removed') ? 'table-danger' : 'table-secondary'; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($employee['Worker_Name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge 
                                                    <?php 
                                                    if($employee['Level'] == 'Project') echo 'badge-project-level';
                                                    elseif($employee['Level'] == 'Package') echo 'badge-package-level';
                                                    elseif($employee['Level'] == 'Task') echo 'badge-task-level';
                                                    ?>">
                                                    <?php echo $employee['Level']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($employee['Role']); ?>
                                                <?php if($employee['Designation']): ?>
                                                    <br><small><?php echo htmlspecialchars($employee['Designation']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo getStatusBadgeClass($employee['Participation_Status']); ?>">
                                                    <?php echo $employee['Participation_Status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if($employee['Package_Name']): ?>
                                                    <i class="fas fa-cube me-1"></i> <?php echo htmlspecialchars($employee['Package_Name']); ?>
                                                <?php elseif($employee['Task_ID']): ?>
                                                    <i class="fas fa-tasks me-1"></i> Task #<?php echo $employee['Task_ID']; ?> - <?php echo htmlspecialchars($employee['Task_type']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Project Level</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo date('M Y', strtotime($employee['Assigned_Date'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <a href="view_worker.php?id=<?php echo $employee['Worker_ID']; ?>" class="action-btn btn-view-employee">
                                                        <i class="fas fa-user"></i> View
                                                    </a>
                                                    <?php if($employee['Package_ID']): ?>
                                                        <a href="view_package.php?id=<?php echo $employee['Package_ID']; ?>" class="action-btn btn-view-package">
                                                            <i class="fas fa-cubes"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if($employee['Task_ID']): ?>
                                                        <a href="view_maintenance.php?id=<?php echo $employee['Task_ID']; ?>" class="action-btn btn-view-task">
                                                            <i class="fas fa-tasks"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-history fa-3x mb-3"></i>
                                <h5>No Past Employees</h5>
                                <p class="mb-0">No completed or removed employees found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    // Worker Status Edit Functions
    function showWorkerStatusEdit(assignmentId) {
        document.getElementById('worker-status-display-' + assignmentId).style.display = 'none';
        document.getElementById('worker-status-edit-' + assignmentId).style.display = 'flex';
    }
    
    function cancelWorkerStatusEdit(assignmentId) {
        document.getElementById('worker-status-display-' + assignmentId).style.display = 'flex';
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
                        var statusBadge = statusDisplay.querySelector('.status-badge');
                        statusBadge.className = 'status-badge ' + getStatusClass(newStatus);
                        statusBadge.textContent = newStatus;
                        cancelWorkerStatusEdit(assignmentId);
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
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
    
    function getStatusClass(status) {
        switch(status) {
            case 'Active': return 'status-active';
            case 'Paused': return 'status-paused';
            case 'Completed': return 'status-completed';
            case 'Removed': return 'status-removed';
            default: return 'status-not-started';
        }
    }
    
    // Note edit functions
    function showNoteEdit(assignmentId) {
        document.getElementById('note-display-' + assignmentId).style.display = 'none';
        document.getElementById('note-edit-' + assignmentId).style.display = 'block';
    }
    
    function cancelNoteEdit(assignmentId) {
        document.getElementById('note-display-' + assignmentId).style.display = 'block';
        document.getElementById('note-edit-' + assignmentId).style.display = 'none';
    }
    
    function saveNote(assignmentId) {
        var noteText = document.getElementById('note-text-' + assignmentId).value;
        var saveBtn = document.querySelector('#note-edit-' + assignmentId + ' .note-save-btn');
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
                        document.getElementById('note-display-' + assignmentId).innerHTML = noteText || 'No notes added yet.';
                        cancelNoteEdit(assignmentId);
                    } else {
                        alert('Error: ' + response.error);
                    }
                } catch(e) {
                    alert('Error saving note');
                }
            } else {
                alert('Server error');
            }
        };
        xhr.send('update_note=1&assignment_id=' + assignmentId + '&note=' + encodeURIComponent(noteText));
    }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>