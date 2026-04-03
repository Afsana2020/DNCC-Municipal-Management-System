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

// Get context from URL
$maintenance_id = isset($_GET['maintenance_id']) ? $_GET['maintenance_id'] : null;
$package_id = isset($_GET['package_id']) ? $_GET['package_id'] : null;
$project_id = isset($_GET['project_id']) ? $_GET['project_id'] : null;
$preset_role = isset($_GET['role']) ? $_GET['role'] : ''; // For preset role like 'Project Director'
$return_to = isset($_GET['return']) ? $_GET['return'] : 'manage_workers.php';

// Determine assignment level based on what IDs are present
$assignment_level = 'unassigned';
if($maintenance_id) {
    $assignment_level = 'maintenance';
} elseif($package_id) {
    $assignment_level = 'package';
} elseif($project_id) {
    $assignment_level = 'project';
}

// Get project info
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

// Get package info (always comes with project)
$package = null;
if($package_id) {
    $package_query = "SELECT p.*, pr.Project_Name, pr.Project_ID 
                      FROM Packages p 
                      JOIN Projects pr ON p.Project_ID = pr.Project_ID 
                      WHERE p.Package_ID = ?";
    $stmt = $conn->prepare($package_query);
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $package_result = $stmt->get_result();
    $package = $package_result->fetch_assoc();
    $stmt->close();
    
    // If package has project, ensure we have project context
    if($package && !$project_id) {
        $project_id = $package['Project_ID'];
        // Get project info
        $proj_query = "SELECT * FROM Projects WHERE Project_ID = ?";
        $stmt = $conn->prepare($proj_query);
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $proj_result = $stmt->get_result();
        $project = $proj_result->fetch_assoc();
        $stmt->close();
    }
}

// Get maintenance task info (comes with package and project)
$maintenance_task = null;
if($maintenance_id) {
    $maintenance_query = "SELECT m.*, a.Asset_Name, a.Asset_Type,
                                 pkg.Package_ID, pkg.Package_Name, pkg.Project_ID,
                                 pr.Project_Name
                          FROM Maintenance m 
                          JOIN Assets a ON m.Asset_ID = a.Asset_ID 
                          LEFT JOIN Packages pkg ON m.Package_ID = pkg.Package_ID
                          LEFT JOIN Projects pr ON m.Project_ID = pr.Project_ID
                          WHERE m.Maintenance_ID = ?";
    $stmt = $conn->prepare($maintenance_query);
    $stmt->bind_param("i", $maintenance_id);
    $stmt->execute();
    $maintenance_result = $stmt->get_result();
    $maintenance_task = $maintenance_result->fetch_assoc();
    $stmt->close();
    
    // If maintenance has package, set package context
    if($maintenance_task && $maintenance_task['Package_ID'] && !$package_id) {
        $package_id = $maintenance_task['Package_ID'];
        
        // Get package info
        $pkg_query = "SELECT p.*, pr.Project_Name 
                      FROM Packages p 
                      JOIN Projects pr ON p.Project_ID = pr.Project_ID 
                      WHERE p.Package_ID = ?";
        $stmt = $conn->prepare($pkg_query);
        $stmt->bind_param("i", $package_id);
        $stmt->execute();
        $pkg_result = $stmt->get_result();
        $package = $pkg_result->fetch_assoc();
        $stmt->close();
    }
    
    // If maintenance has project, set project context
    if($maintenance_task && $maintenance_task['Project_ID'] && !$project_id) {
        $project_id = $maintenance_task['Project_ID'];
        
        // Get project info
        $proj_query = "SELECT * FROM Projects WHERE Project_ID = ?";
        $stmt = $conn->prepare($proj_query);
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $proj_result = $stmt->get_result();
        $project = $proj_result->fetch_assoc();
        $stmt->close();
    }
}

// Get all projects for dropdown (if needed for standalone)
$projects_query = "SELECT Project_ID, Project_Name, Project_Type, Status 
                   FROM Projects 
                   WHERE Status != 'Completed' 
                   ORDER BY Created_At DESC";
$projects_result = $conn->query($projects_query);

// Get all workers for existing worker dropdown with their current assignments
$existing_workers_result = null;
if($project_id || $package_id || $maintenance_id) {
    $existing_workers_query = "SELECT 
                                    w.Worker_ID, 
                                    w.Worker_Name, 
                                    w.Worker_Salary,
                                    w.Contact,
                                    w.Designation as permanent_designation,
                                    (SELECT GROUP_CONCAT(CONCAT(wa.Role, ' (', 
                                        CASE 
                                            WHEN wa.Assignment_Type = 'project' THEN 'Project'
                                            WHEN wa.Assignment_Type = 'package' THEN 'Package'
                                            WHEN wa.Assignment_Type = 'maintenance' THEN 'Task'
                                        END, ')') SEPARATOR ', ') 
                                     FROM worker_assignments wa 
                                     WHERE wa.Worker_ID = w.Worker_ID AND wa.Status = 'Active') as current_roles,
                                    (SELECT COUNT(*) FROM worker_assignments 
                                     WHERE Worker_ID = w.Worker_ID AND Status = 'Active') as active_assignments,
                                    -- Check for EXACT match only (same ID, same type)
                                    CASE
                                        WHEN ? IS NOT NULL AND EXISTS(
                                            SELECT 1 FROM worker_assignments 
                                            WHERE Worker_ID = w.Worker_ID 
                                            AND Status = 'Active'
                                            AND Project_ID = ?
                                            AND Assignment_Type = 'project'
                                        ) THEN 1
                                        WHEN ? IS NOT NULL AND EXISTS(
                                            SELECT 1 FROM worker_assignments 
                                            WHERE Worker_ID = w.Worker_ID 
                                            AND Status = 'Active'
                                            AND Package_ID = ?
                                            AND Assignment_Type = 'package'
                                        ) THEN 1
                                        WHEN ? IS NOT NULL AND EXISTS(
                                            SELECT 1 FROM worker_assignments 
                                            WHERE Worker_ID = w.Worker_ID 
                                            AND Status = 'Active'
                                            AND Maintenance_ID = ?
                                            AND Assignment_Type = 'maintenance'
                                        ) THEN 1
                                        ELSE 0
                                    END as already_assigned_here
                                FROM Workers w
                                ORDER BY w.Worker_Name";
    
    $stmt = $conn->prepare($existing_workers_query);
    $stmt->bind_param("iiiiii", 
        $project_id, $project_id, 
        $package_id, $package_id, 
        $maintenance_id, $maintenance_id
    );
    $stmt->execute();
    $existing_workers_result = $stmt->get_result();
    
    // Also get detailed info for each worker with assignments
    $workers_details = [];
    if($existing_workers_result) {
        while($worker = $existing_workers_result->fetch_assoc()) {
            // Get detailed assignments for modal
            $assignments_query = "SELECT wa.*,
                                         CASE 
                                            WHEN wa.Assignment_Type = 'project' THEN pr.Project_Name
                                            WHEN wa.Assignment_Type = 'package' THEN pk.Package_Name
                                            WHEN wa.Assignment_Type = 'maintenance' THEN m.Task_type
                                         END as item_name
                                  FROM worker_assignments wa
                                  LEFT JOIN Projects pr ON wa.Project_ID = pr.Project_ID AND wa.Assignment_Type = 'project'
                                  LEFT JOIN Packages pk ON wa.Package_ID = pk.Package_ID AND wa.Assignment_Type = 'package'
                                  LEFT JOIN Maintenance m ON wa.Maintenance_ID = m.Maintenance_ID AND wa.Assignment_Type = 'maintenance'
                                  WHERE wa.Worker_ID = ? AND wa.Status = 'Active'";
            $stmt2 = $conn->prepare($assignments_query);
            $stmt2->bind_param("i", $worker['Worker_ID']);
            $stmt2->execute();
            $assignments_result = $stmt2->get_result();
            $assignments = [];
            while($assignment = $assignments_result->fetch_assoc()) {
                $assignments[] = $assignment;
            }
            $workers_details[$worker['Worker_ID']] = [
                'worker' => $worker,
                'assignments' => $assignments
            ];
            $stmt2->close();
        }
        // Reset the result pointer for the dropdown
        $existing_workers_result->data_seek(0);
    }
}

// Check for task team leader
$task_leader = null;
if($maintenance_id) {
    $task_leader_query = "SELECT w.Worker_Name, wa.Role, wa.Assignment_ID 
                          FROM worker_assignments wa
                          JOIN workers w ON wa.Worker_ID = w.Worker_ID
                          WHERE wa.Maintenance_ID = ? 
                          AND wa.Role = 'Team Leader' 
                          AND wa.Status = 'Active'
                          AND wa.Assignment_Type = 'maintenance'
                          LIMIT 1";
    $task_leader_stmt = $conn->prepare($task_leader_query);
    $task_leader_stmt->bind_param("i", $maintenance_id);
    $task_leader_stmt->execute();
    $task_leader_result = $task_leader_stmt->get_result();
    $task_leader = $task_leader_result->fetch_assoc();
    $task_leader_stmt->close();
}

// Common designation options
$designation_options = [
    'Engineer' => 'Engineer (Civil/Electrical/Mechanical)',
    'Architect' => 'Architect',
    'Urban Planner' => 'Urban Planner',
    'Surveyor' => 'Surveyor',
    'Technician' => 'Technician',
    'Accountant' => 'Accountant',
    'Finance Officer' => 'Finance Officer',
    'HR Officer' => 'HR Officer',
    'Administrator' => 'Administrator',
    'Legal Advisor' => 'Legal Advisor',
    'IT Specialist' => 'IT Specialist',
    'Field Worker' => 'Field Worker',
    'Supervisor' => 'Supervisor',
    'Manager' => 'Manager',
    'Director' => 'Director',
    'Consultant' => 'Consultant',
    'Other' => 'Other (Specify)'
];

// Handle form submission - Add new worker
if(isset($_POST['add_worker'])) {
    $worker_name = trim($_POST['worker_name']);
    $worker_salary = $_POST['worker_salary'];
    $contact = trim($_POST['contact']);
    $designation = trim($_POST['designation']);
    
    // Handle custom designation if "Other" was selected
    if($designation == 'Other' && !empty($_POST['custom_designation'])) {
        $designation = trim($_POST['custom_designation']);
    }
    
    // Get IDs from hidden fields (set by context)
    $project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
    $package_id = !empty($_POST['package_id']) ? $_POST['package_id'] : null;
    $maintenance_id = !empty($_POST['maintenance_id']) ? $_POST['maintenance_id'] : null;
    $assignment_type = '';
    
    if($maintenance_id) {
        $assignment_type = 'maintenance';
    } elseif($package_id) {
        $assignment_type = 'package';
    } elseif($project_id) {
        $assignment_type = 'project';
    }

    // Get role from form if there's an assignment
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';
    
    // Handle custom role if "Other" was selected
    if($role == 'Other' && !empty($_POST['custom_role'])) {
        $role = trim($_POST['custom_role']);
    }

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert worker with permanent designation (no role)
        $insert_query = "INSERT INTO Workers 
                         (Worker_Name, Worker_Salary, Contact, Designation) 
                         VALUES (?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sdss", 
            $worker_name, 
            $worker_salary, 
            $contact,
            $designation
        );
        
        if(!$stmt->execute()) {
            throw new Exception("Error adding employee: " . $stmt->error);
        }
        
        $new_worker_id = $conn->insert_id;
        
        // If there's an assignment context, create an assignment with role
        if(!empty($assignment_type) && !empty($role)) {
            // Check for EXACT duplicate only (same type and same ID)
            $check_query = "SELECT COUNT(*) as count FROM worker_assignments 
                           WHERE Worker_ID = ? AND Status = 'Active' AND 
                           Assignment_Type = ? AND ";
            
            if($project_id) {
                $check_query .= "Project_ID = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("isi", $new_worker_id, $assignment_type, $project_id);
            } elseif($package_id) {
                $check_query .= "Package_ID = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("isi", $new_worker_id, $assignment_type, $package_id);
            } elseif($maintenance_id) {
                $check_query .= "Maintenance_ID = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("isi", $new_worker_id, $assignment_type, $maintenance_id);
            }
            
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_row = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if($check_row['count'] > 0) {
                throw new Exception("This employee is already assigned to this specific " . $assignment_type);
            }
            
            $insert_assignment = "INSERT INTO worker_assignments 
                                  (Worker_ID, Assignment_Type, Project_ID, Package_ID, Maintenance_ID, Role, Status, Assigned_Date)
                                  VALUES (?, ?, ?, ?, ?, ?, 'Active', NOW())";
            
            $stmt = $conn->prepare($insert_assignment);
            $stmt->bind_param("isiiis", 
                $new_worker_id,
                $assignment_type,
                $project_id, 
                $package_id, 
                $maintenance_id,
                $role
            );
            
            if(!$stmt->execute()) {
                throw new Exception("Error creating assignment: " . $stmt->error);
            }
        }
        
        // Handle special roles for Project Director and Team Leader
        if($role == 'Project Director' && !empty($project_id)) {
            // First, clear any existing Project Director for this project
            $clear_query = "UPDATE Projects SET Project_Director = NULL WHERE Project_ID = ?";
            $clear_stmt = $conn->prepare($clear_query);
            $clear_stmt->bind_param("i", $project_id);
            $clear_stmt->execute();
            $clear_stmt->close();
            
            // Set new Project Director (store worker name)
            $update_project = "UPDATE Projects SET Project_Director = ? WHERE Project_ID = ?";
            $proj_stmt = $conn->prepare($update_project);
            $proj_stmt->bind_param("si", $worker_name, $project_id);
            $proj_stmt->execute();
            $proj_stmt->close();
        }
        
        if($role == 'Team Leader' && !empty($package_id) && empty($maintenance_id)) {
            // First, clear any existing Team Leader for this package
            $clear_query = "UPDATE Packages SET Team_Leader = NULL WHERE Package_ID = ?";
            $clear_stmt = $conn->prepare($clear_query);
            $clear_stmt->bind_param("i", $package_id);
            $clear_stmt->execute();
            $clear_stmt->close();
            
            // Set new Team Leader (store worker name)
            $update_package = "UPDATE Packages SET Team_Leader = ? WHERE Package_ID = ?";
            $pkg_stmt = $conn->prepare($update_package);
            $pkg_stmt->bind_param("si", $worker_name, $package_id);
            $pkg_stmt->execute();
            $pkg_stmt->close();
        }
        
        // For task level Team Leader - no separate table field, just assignment
        // The assignment itself handles the team leader role
        
        // Commit transaction
        $conn->commit();
        
        // Redirect based on highest level
        if($maintenance_id) {
            header("Location: view_maintenance.php?id=$maintenance_id&success=worker_added");
        } elseif($package_id) {
            header("Location: view_package.php?id=$package_id&success=worker_added");
        } elseif($project_id) {
            header("Location: view_project.php?id=$project_id&success=worker_added");
        } else {
            header("Location: manage_workers.php?success=worker_added");
        }
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
    }
}

// Handle existing worker assignment
if(isset($_POST['assign_existing_worker'])) {
    $worker_id = $_POST['worker_id'];
    $role = $_POST['role_existing'];
    
    // Handle custom role if "Other" was selected
    if($role == 'Other' && !empty($_POST['custom_role_existing'])) {
        $role = trim($_POST['custom_role_existing']);
    }
    
    // Get IDs from hidden fields
    $project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
    $package_id = !empty($_POST['package_id']) ? $_POST['package_id'] : null;
    $maintenance_id = !empty($_POST['maintenance_id']) ? $_POST['maintenance_id'] : null;
    
    $assignment_type = '';
    if($maintenance_id) {
        $assignment_type = 'maintenance';
    } elseif($package_id) {
        $assignment_type = 'package';
    } elseif($project_id) {
        $assignment_type = 'project';
    }

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get worker's current info
        $check_query = "SELECT Worker_Name, Designation FROM Workers WHERE Worker_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $worker_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $worker = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if(!$worker) {
            throw new Exception("Worker not found");
        }
        
        // Check for EXACT duplicate only (same type and same ID)
        $check_dup_query = "SELECT COUNT(*) as count FROM worker_assignments 
                           WHERE Worker_ID = ? AND Status = 'Active' AND 
                           Assignment_Type = ? AND ";
        
        if($project_id) {
            $check_dup_query .= "Project_ID = ?";
            $check_dup_stmt = $conn->prepare($check_dup_query);
            $check_dup_stmt->bind_param("isi", $worker_id, $assignment_type, $project_id);
        } elseif($package_id) {
            $check_dup_query .= "Package_ID = ?";
            $check_dup_stmt = $conn->prepare($check_dup_query);
            $check_dup_stmt->bind_param("isi", $worker_id, $assignment_type, $package_id);
        } elseif($maintenance_id) {
            $check_dup_query .= "Maintenance_ID = ?";
            $check_dup_stmt = $conn->prepare($check_dup_query);
            $check_dup_stmt->bind_param("isi", $worker_id, $assignment_type, $maintenance_id);
        }
        
        $check_dup_stmt->execute();
        $check_dup_result = $check_dup_stmt->get_result();
        $check_dup_row = $check_dup_result->fetch_assoc();
        $check_dup_stmt->close();
        
        if($check_dup_row['count'] > 0) {
            throw new Exception("This employee is already assigned to this specific " . $assignment_type);
        }
        
        // Create new assignment (don't clear old ones - allow multiple but not exact duplicate)
        $insert_assignment = "INSERT INTO worker_assignments 
                              (Worker_ID, Assignment_Type, Project_ID, Package_ID, Maintenance_ID, Role, Status, Assigned_Date)
                              VALUES (?, ?, ?, ?, ?, ?, 'Active', NOW())";
        
        $stmt = $conn->prepare($insert_assignment);
        $stmt->bind_param("isiiis", 
            $worker_id,
            $assignment_type,
            $project_id, 
            $package_id, 
            $maintenance_id,
            $role
        );
        
        if(!$stmt->execute()) {
            throw new Exception("Error assigning employee: " . $stmt->error);
        }
        
        // Handle special roles for Project Director and Team Leader
        if($role == 'Project Director' && !empty($project_id)) {
            // Clear any existing Project Director for this project
            $clear_project = "UPDATE Projects SET Project_Director = NULL WHERE Project_ID = ?";
            $clear_stmt = $conn->prepare($clear_project);
            $clear_stmt->bind_param("i", $project_id);
            $clear_stmt->execute();
            $clear_stmt->close();
            
            // Set new Project Director
            $update_project = "UPDATE Projects SET Project_Director = ? WHERE Project_ID = ?";
            $proj_stmt = $conn->prepare($update_project);
            $proj_stmt->bind_param("si", $worker['Worker_Name'], $project_id);
            $proj_stmt->execute();
            $proj_stmt->close();
        }
        
        if($role == 'Team Leader' && !empty($package_id) && empty($maintenance_id)) {
            // Clear any existing Team Leader for this package
            $clear_package = "UPDATE Packages SET Team_Leader = NULL WHERE Package_ID = ?";
            $clear_stmt = $conn->prepare($clear_package);
            $clear_stmt->bind_param("i", $package_id);
            $clear_stmt->execute();
            $clear_stmt->close();
            
            // Set new Team Leader
            $update_package = "UPDATE Packages SET Team_Leader = ? WHERE Package_ID = ?";
            $pkg_stmt = $conn->prepare($update_package);
            $pkg_stmt->bind_param("si", $worker['Worker_Name'], $package_id);
            $pkg_stmt->execute();
            $pkg_stmt->close();
        }
        
        // For task level Team Leader - no separate table field, just assignment
        
        // Commit transaction
        $conn->commit();
        
        // Redirect based on highest level
        if($maintenance_id) {
            header("Location: view_maintenance.php?id=$maintenance_id&success=worker_assigned");
        } elseif($package_id) {
            header("Location: view_package.php?id=$package_id&success=worker_assigned");
        } elseif($project_id) {
            header("Location: view_project.php?id=$project_id&success=worker_assigned");
        } else {
            header("Location: manage_workers.php?success=worker_assigned");
        }
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
    }
}

// Handle remove Project Director
if(isset($_POST['remove_project_director'])) {
    $project_id = $_POST['project_id'];
    
    $update_query = "UPDATE Projects SET Project_Director = NULL WHERE Project_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("i", $project_id);
    
    if($stmt->execute()) {
        header("Location: view_project.php?id=$project_id&success=director_removed");
    } else {
        header("Location: view_project.php?id=$project_id&error=director_remove_failed");
    }
    exit();
}

// Handle remove Package Team Leader
if(isset($_POST['remove_team_leader'])) {
    $package_id = $_POST['package_id'];
    
    $update_query = "UPDATE Packages SET Team_Leader = NULL WHERE Package_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("i", $package_id);
    
    if($stmt->execute()) {
        header("Location: view_package.php?id=$package_id&success=leader_removed");
    } else {
        header("Location: view_package.php?id=$package_id&error=leader_remove_failed");
    }
    exit();
}

// Handle remove Task Team Leader
if(isset($_POST['remove_task_leader'])) {
    $maintenance_id = $_POST['maintenance_id'];
    
    // Update the assignment status to 'Completed' for the team leader
    $update_query = "UPDATE worker_assignments 
                     SET Status = 'Completed' 
                     WHERE Maintenance_ID = ? 
                     AND Role = 'Team Leader' 
                     AND Assignment_Type = 'maintenance' 
                     AND Status = 'Active'";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("i", $maintenance_id);
    
    if($stmt->execute()) {
        header("Location: view_maintenance.php?id=$maintenance_id&success=task_leader_removed");
    } else {
        header("Location: view_maintenance.php?id=$maintenance_id&error=task_leader_remove_failed");
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee - Smart DNCC</title>
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
            --red-header: #b91c1c;
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

        .header-left h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-left p {
            color: #64748b;
            font-size: 0.85rem;
            margin: 4px 0 0 0;
        }

        .content-area {
            padding: 20px 28px;
            overflow-y: auto;
            height: calc(100vh - 73px);
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
            padding: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }

        /* Context Banner */
        .context-banner {
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }

        .context-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .context-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .context-path {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            font-size: 0.8rem;
        }

        .hierarchy-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-project { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .badge-package { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; }
        .badge-maintenance { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }

        /* Current Director/Leader Info */
        .current-info {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }

        .current-info i {
            color: #d97706;
            font-size: 1.2rem;
        }

        /* Tabs */
        .tab-container {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 24px;
        }

        .tab {
            padding: 10px 24px;
            cursor: pointer;
            font-weight: 600;
            color: #718096;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }

        .tab:hover {
            color: var(--accent);
        }

        .tab.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Styling */
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
            font-size: 0.8rem;
            letter-spacing: 0.3px;
        }

        .form-control, .form-select {
            border-radius: 8px;
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

        .assignment-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            margin: 20px 0;
        }

        .preset-role-badge {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #92400e;
            padding: 8px 16px;
            border-radius: 30px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            margin-bottom: 16px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .modal-header {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
            color: white;
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

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 0 0 16px 16px;
        }

        .warning-banner {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .warning-banner i {
            color: #d97706;
            font-size: 1.5rem;
        }

        .warning-banner strong {
            color: #92400e;
        }

        .duplicate-warning-banner {
            background: #fee2e2;
            border: 1px solid #f87171;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .duplicate-warning-banner i {
            color: #b91c1c;
            font-size: 1.5rem;
        }

        .duplicate-warning-banner strong {
            color: #b91c1c;
        }

        .assignment-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .assignment-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .assignment-item:last-child {
            margin-bottom: 0;
        }

        .assignment-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .assignment-details {
            flex-grow: 1;
        }

        .assignment-title {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .assignment-meta {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            gap: 15px;
        }

        .same-level-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Active Assignments Badge */
        .active-assignments-badge {
            background: #e53e3e;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }

        /* Duplicate Warning Badge */
        .duplicate-warning-badge {
            background: #fbbf24;
            color: #92400e;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
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
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(49,130,206,0.2);
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

        .btn-danger {
            background: #e53e3e;
            color: white;
        }
        .btn-danger:hover {
            background: #c53030;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }
        .btn-success:hover {
            background: #2f855a;
        }

        .btn-warning {
            background: #d69e2e;
            color: white;
        }
        .btn-warning:hover {
            background: #b7791f;
        }

        .alert {
            border: 1px solid;
            border-radius: 10px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            padding: 12px 20px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }
        .alert-success {
            background: #f0fff4;
            color: #276749;
            border-color: #9ae6b4;
        }
        .alert-danger {
            background: #fff5f5;
            color: #c53030;
            border-color: #fc8181;
        }
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-color: #fcd34d;
        }

        h2 {
            font-size: 1.4rem;
        }

        h5 {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
        }

        .text-muted {
            color: #718096 !important;
        }

        hr {
            border-color: #e2e8f0;
            margin: 20px 0;
        }

        .red-dot {
            color: #dc2626;
            font-size: 0.8rem;
            margin-right: 4px;
        }

        /* Custom field styles */
        .custom-field {
            margin-top: 10px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 3px solid var(--accent);
        }

        /* Role section for assignment context */
        .role-section {
            background: #ebf8ff;
            border-left: 4px solid var(--accent);
            padding: 16px;
            border-radius: 8px;
            margin: 20px 0;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .content-area {
                padding: 16px;
            }
            
            .header-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .tab-container {
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
                        <i class="fas fa-user-cog" style="color: var(--accent);"></i>
                        Add Employee
                    </h2>
                    <p>
                        <?php 
                        if($maintenance_id) echo "Task Level Assignment";
                        elseif($package_id) echo "Package Level Assignment";
                        elseif($project_id) echo "Project Level Assignment";
                        else echo "Standalone Employee";
                        
                        if($preset_role) {
                            echo " · Role: " . $preset_role;
                        }
                        ?>
                    </p>
                </div>
                <div>
                    <?php if($maintenance_id): ?>
                        <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php elseif($package_id): ?>
                        <a href="view_package.php?id=<?php echo $package_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php elseif($project_id): ?>
                        <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php else: ?>
                        <a href="manage_workers.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Content Area -->
        <div class="content-area">
            
            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <!-- Show current Project Director only if coming from Project Level and has director -->
            <?php if($project_id && !$package_id && !$maintenance_id && $project && !empty($project['Project_Director'])): ?>
            <div class="current-info">
                <i class="fas fa-info-circle"></i>
                <div class="flex-grow-1">
                    <strong>Current Project Director:</strong> <?php echo htmlspecialchars($project['Project_Director']); ?>
                    <br>
                    <small>Assigning a new Project Director will replace the current one.</small>
                </div>
                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to remove the current Project Director?');">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <button type="submit" name="remove_project_director" class="btn btn-danger btn-sm">
                        <i class="fas fa-user-minus"></i> Remove
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Show current Package Team Leader only if coming from Package Level and has team leader -->
            <?php if($package_id && !$maintenance_id && $package && !empty($package['Team_Leader'])): ?>
            <div class="current-info">
                <i class="fas fa-info-circle"></i>
                <div class="flex-grow-1">
                    <strong>Current Package Team Leader:</strong> <?php echo htmlspecialchars($package['Team_Leader']); ?>
                    <br>
                    <small>Assigning a new Team Leader will replace the current one for this package.</small>
                </div>
                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to remove the current Team Leader?');">
                    <input type="hidden" name="package_id" value="<?php echo $package_id; ?>">
                    <button type="submit" name="remove_team_leader" class="btn btn-danger btn-sm">
                        <i class="fas fa-user-minus"></i> Remove
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Show current Task Team Leader only if coming from Task Level (maintenance) and has team leader -->
            <?php if($maintenance_id && $task_leader && !empty($task_leader['Worker_Name'])): ?>
            <div class="current-info">
                <i class="fas fa-info-circle"></i>
                <div class="flex-grow-1">
                    <strong>Current Task Team Leader:</strong> <?php echo htmlspecialchars($task_leader['Worker_Name']); ?>
                    <br>
                    <small>Assigning a new Team Leader will replace the current one for this task.</small>
                </div>
                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to remove the current Task Team Leader?');">
                    <input type="hidden" name="maintenance_id" value="<?php echo $maintenance_id; ?>">
                    <button type="submit" name="remove_task_leader" class="btn btn-danger btn-sm">
                        <i class="fas fa-user-minus"></i> Remove
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Context Banner -->
            <?php if($project || $package || $maintenance_task): ?>
            <div class="context-banner">
                <div class="context-icon" style="background: 
                    <?php 
                    if($maintenance_task) echo 'rgba(56,161,105,0.1); color: #38a169;';
                    elseif($package) echo 'rgba(128,90,213,0.1); color: #805ad5;';
                    elseif($project) echo 'rgba(49,130,206,0.1); color: #3182ce;';
                    ?>">
                    <i class="fas 
                        <?php 
                        if($maintenance_task) echo 'fa-tasks';
                        elseif($package) echo 'fa-cubes';
                        elseif($project) echo 'fa-folder-open';
                        ?>">
                    </i>
                </div>
                <div>
                    <div class="context-title">
                        <?php 
                        if($maintenance_task) {
                            echo "Task: " . htmlspecialchars($maintenance_task['Task_type']);
                        } elseif($package) {
                            echo "Package: " . htmlspecialchars($package['Package_Name']);
                        } elseif($project) {
                            echo "Project: " . htmlspecialchars($project['Project_Name']);
                        }
                        ?>
                    </div>
                    <div class="context-path">
                        <i class="fas fa-sitemap me-1"></i>
                        Hierarchy: 
                        <?php if($project): ?>
                            <span class="hierarchy-badge badge-project">Project</span>
                        <?php endif; ?>
                        <?php if($package): ?>
                            <i class="fas fa-chevron-right mx-1" style="font-size: 0.7rem;"></i>
                            <span class="hierarchy-badge badge-package">Package</span>
                        <?php endif; ?>
                        <?php if($maintenance_task): ?>
                            <i class="fas fa-chevron-right mx-1" style="font-size: 0.7rem;"></i>
                            <span class="hierarchy-badge badge-maintenance">Task</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Preset Role Badge - Only show when there's a specific preset role -->
            <?php if($preset_role): ?>
            <div class="preset-role-badge">
                <i class="fas fa-info-circle"></i>
                <span>Role pre-selected: <strong><?php echo $preset_role; ?></strong> for this assignment</span>
            </div>
            <?php endif; ?>

            <!-- Main Form Section -->
            <div class="content-section">
                <div class="tab-container">
                    <div class="tab active" onclick="switchTab('new')">
                        <i class="fas fa-user-plus me-2"></i>Add New Employee
                    </div>
                    
                    <?php if($project_id || $package_id || $maintenance_id): ?>
                    <div class="tab" onclick="switchTab('existing')">
                        <i class="fas fa-user-check me-2"></i>Assign Existing
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 1: Add New Employee -->
                <div id="tab-new" class="tab-content active">
                    <form method="POST" action="" id="newWorkerForm">
                        <h5 class="mb-3">Employee Information</h5>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Employee Name *</label>
                                <input type="text" class="form-control" name="worker_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Permanent Designation *</label>
                                <select class="form-select" name="designation" id="designation" required>
                                    <option value="">Select Designation</option>
                                    <?php foreach($designation_options as $value => $label): ?>
                                        <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="custom_designation_field" class="custom-field" style="display: none;">
                                    <label class="form-label">Specify Designation</label>
                                    <input type="text" class="form-control" name="custom_designation" placeholder="Enter designation">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Monthly Salary (BDT)</label>
                                <input type="number" class="form-control" name="worker_salary" step="0.01" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact</label>
                                <input type="text" class="form-control" name="contact" placeholder="01XXXXXXXXX">
                            </div>
                        </div>

                        <?php if($project_id || $package_id || $maintenance_id): ?>
                        <!-- Assignment Role Section - Only shown when there's an assignment context -->
                        <div class="role-section">
                            <h5 class="mb-3">Assignment Role</h5>
                            <p class="text-muted small mb-3">This role is specific to this assignment only</p>
                            
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Role for this Assignment *</label>
                                    <select class="form-select" name="role" id="role" required>
                                        <option value="">Select Role</option>
                                        
                                        <?php if($project_id && !$package_id && !$maintenance_id): ?>
                                            <!-- Project Level - Show Project Director -->
                                            <option value="Project Director" <?php echo ($preset_role == 'Project Director') ? 'selected' : ''; ?>>Project Director</option>
                                            
                                        <?php elseif($package_id && !$maintenance_id): ?>
                                            <!-- Package Level - Show Team Leader -->
                                            <option value="Team Leader" <?php echo ($preset_role == 'Team Leader') ? 'selected' : ''; ?>>Team Leader (Package Leader)</option>
                                            
                                        <?php elseif($maintenance_id): ?>
                                            <!-- Task Level - Show Team Leader for this task -->
                                            <option value="Team Leader" <?php echo ($preset_role == 'Team Leader') ? 'selected' : ''; ?>>Team Leader (Task Leader)</option>
                                            
                                        <?php endif; ?>
                                        
                                        <!-- Common roles for all levels -->
                                        <option value="Engineer">Engineer</option>
                                        <option value="Supervisor">Supervisor</option>
                                        <option value="Technician">Technician</option>
                                        <option value="Worker">Worker</option>
                                        <option value="Helper">Helper</option>
                                        <option value="Consultant">Consultant</option>
                                        <option value="Advisor">Advisor</option>
                                        <option value="Other">Other (Specify)</option>
                                    </select>
                                    <div id="custom_role_field" class="custom-field" style="display: none;">
                                        <label class="form-label">Specify Role</label>
                                        <input type="text" class="form-control" name="custom_role" placeholder="Enter role name">
                                    </div>
                                    <?php if($project_id && $project && !empty($project['Project_Director']) && $preset_role == 'Project Director'): ?>
                                        <small class="text-warning">
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            This will replace the current Project Director
                                        </small>
                                    <?php endif; ?>
                                    <?php if($package_id && !$maintenance_id && $package && !empty($package['Team_Leader']) && $preset_role == 'Team Leader'): ?>
                                        <small class="text-warning">
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            This will replace the current Package Team Leader
                                        </small>
                                    <?php endif; ?>
                                    <?php if($maintenance_id && $task_leader && !empty($task_leader['Worker_Name']) && $preset_role == 'Team Leader'): ?>
                                        <small class="text-warning">
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            This will replace the current Task Team Leader (<?php echo htmlspecialchars($task_leader['Worker_Name']); ?>)
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <hr>

                        <!-- Hidden fields from context -->
                        <?php if($project_id): ?>
                            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                        <?php endif; ?>
                        <?php if($package_id): ?>
                            <input type="hidden" name="package_id" value="<?php echo $package_id; ?>">
                        <?php endif; ?>
                        <?php if($maintenance_id): ?>
                            <input type="hidden" name="maintenance_id" value="<?php echo $maintenance_id; ?>">
                        <?php endif; ?>

                        <!-- Assignment Info Card -->
                        <div class="assignment-card">
                            <div class="d-flex align-items-start gap-3">
                                <i class="fas fa-info-circle mt-1" style="color: var(--accent);"></i>
                                <div>
                                    <strong>Assignment Level:</strong>
                                    <div class="mt-2">
                                        <?php if($maintenance_id): ?>
                                            <span class="hierarchy-badge badge-maintenance me-2">Task Level</span>
                                            Employee will be assigned to this maintenance task
                                            <?php if($preset_role == 'Team Leader'): ?>
                                                <div class="mt-2 text-warning">
                                                    <i class="fas fa-star"></i> 
                                                    This employee will be set as the <strong>Team Leader</strong> for this task
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif($package_id): ?>
                                            <span class="hierarchy-badge badge-package me-2">Package Level</span>
                                            Employee will be assigned to this package
                                            <?php if($preset_role == 'Team Leader'): ?>
                                                <div class="mt-2 text-warning">
                                                    <i class="fas fa-users"></i> 
                                                    This employee will be set as the <strong>Team Leader</strong> for this package
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif($project_id): ?>
                                            <span class="hierarchy-badge badge-project me-2">Project Level</span>
                                            Employee will be assigned to this project
                                            <?php if($preset_role == 'Project Director'): ?>
                                                <div class="mt-2 text-warning">
                                                    <i class="fas fa-crown"></i> 
                                                    This employee will be set as the <strong>Project Director</strong> for this project
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary me-2">Standalone</span>
                                            Employee will be added without any assignment
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 justify-content-end mt-4">
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="fas fa-undo me-1"></i>Reset
                            </button>
                            <button type="submit" name="add_worker" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Add Employee
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tab 2: Assign Existing Employee -->
                <?php if($project_id || $package_id || $maintenance_id): ?>
                <div id="tab-existing" class="tab-content">
                    <form method="POST" action="" id="existingWorkerForm">
                        <h5 class="mb-3">Select Existing Employee</h5>

                        <div class="mb-3">
                            <label class="form-label">Choose Employee *</label>
                            <select class="form-select" name="worker_id" id="existing_worker_select" required onchange="checkWorkerAssignment(this.value)">
                                <option value="">Select Employee</option>
                                <?php if($existing_workers_result): ?>
                                    <?php while($worker = $existing_workers_result->fetch_assoc()): ?>
                                        <option value="<?php echo $worker['Worker_ID']; ?>" 
                                                data-worker-name="<?php echo htmlspecialchars($worker['Worker_Name']); ?>"
                                                data-worker-designation="<?php echo htmlspecialchars($worker['permanent_designation']); ?>"
                                                data-worker-role="<?php echo htmlspecialchars($worker['current_roles']); ?>"
                                                data-active-count="<?php echo $worker['active_assignments']; ?>"
                                                data-already-assigned="<?php echo $worker['already_assigned_here']; ?>"
                                                style="<?php 
                                                    if($worker['already_assigned_here']) {
                                                        echo 'color: #fbbf24; font-weight: 500;';
                                                    } elseif($worker['active_assignments'] > 0) {
                                                        echo 'color: #dc2626; font-weight: 500;';
                                                    }
                                                ?>">
                                             <?php echo htmlspecialchars($worker['Worker_Name']); ?> 
                                            (<?php echo htmlspecialchars($worker['permanent_designation']); ?>)
                                            <?php if($worker['already_assigned_here']): ?>
                                                <span class="duplicate-warning-badge">Already in this</span>
                                            <?php elseif($worker['active_assignments'] > 0): ?>
                                                <span class="active-assignments-badge"><?php echo $worker['active_assignments']; ?> Active</span>
                                            <?php endif; ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <div id="duplicate_warning_message" class="alert alert-warning mt-2" style="display: none;">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                This employee is already assigned to this specific <span id="duplicate_level"></span>. They cannot be assigned again to the same work.
                            </div>
                        </div>

                        <hr>

                        <h5 class="mb-3">New Assignment Details</h5>

                        <!-- Hidden fields from context -->
                        <?php if($project_id): ?>
                            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                        <?php endif; ?>
                        <?php if($package_id): ?>
                            <input type="hidden" name="package_id" value="<?php echo $package_id; ?>">
                        <?php endif; ?>
                        <?php if($maintenance_id): ?>
                            <input type="hidden" name="maintenance_id" value="<?php echo $maintenance_id; ?>">
                        <?php endif; ?>

                        <!-- Role field takes full width -->
                        <div class="mb-3">
                            <label class="form-label">Role for this Assignment *</label>
                            <select class="form-select" name="role_existing" id="role_existing" required>
                                <option value="">Select Role</option>
                                
                                <?php if($project_id && !$package_id && !$maintenance_id): ?>
                                    <!-- Project Level - Show Project Director -->
                                    <option value="Project Director" <?php echo ($preset_role == 'Project Director') ? 'selected' : ''; ?>>Project Director</option>
                                    
                                <?php elseif($package_id && !$maintenance_id): ?>
                                    <!-- Package Level - Show Team Leader -->
                                    <option value="Team Leader" <?php echo ($preset_role == 'Team Leader') ? 'selected' : ''; ?>>Team Leader (Package Leader)</option>
                                    
                                <?php elseif($maintenance_id): ?>
                                    <!-- Task Level - Show Team Leader for this task -->
                                    <option value="Team Leader" <?php echo ($preset_role == 'Team Leader') ? 'selected' : ''; ?>>Team Leader (Task Leader)</option>
                                    
                                <?php endif; ?>
                                
                                <!-- Common roles for all levels -->
                                <option value="Engineer">Engineer</option>
                                <option value="Supervisor">Supervisor</option>
                                <option value="Technician">Technician</option>
                                <option value="Worker">Worker</option>
                                <option value="Helper">Helper</option>
                                <option value="Consultant">Consultant</option>
                                <option value="Advisor">Advisor</option>
                                <option value="Other">Other (Specify)</option>
                            </select>
                            <div id="custom_role_existing_field" class="custom-field" style="display: none;">
                                <label class="form-label">Specify Role</label>
                                <input type="text" class="form-control" name="custom_role_existing" placeholder="Enter role name">
                            </div>
                            <small class="text-muted">Employee will get this role for the new assignment</small>
                            <?php if($project_id && $project && !empty($project['Project_Director']) && $preset_role == 'Project Director'): ?>
                                <small class="text-warning d-block">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    This will replace the current Project Director
                                </small>
                            <?php endif; ?>
                            <?php if($package_id && !$maintenance_id && $package && !empty($package['Team_Leader']) && $preset_role == 'Team Leader'): ?>
                                <small class="text-warning d-block">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    This will replace the current Package Team Leader
                                </small>
                            <?php endif; ?>
                            <?php if($maintenance_id && $task_leader && !empty($task_leader['Worker_Name']) && $preset_role == 'Team Leader'): ?>
                                <small class="text-warning d-block">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    This will replace the current Task Team Leader (<?php echo htmlspecialchars($task_leader['Worker_Name']); ?>)
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex gap-2 justify-content-end mt-4">
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="fas fa-undo me-1"></i>Reset
                            </button>
                            <button type="submit" name="assign_existing_worker" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-user-check me-1"></i>Assign Employee
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Worker Assignment Modal -->
    <div class="modal fade" id="workerAssignmentModal" tabindex="-1" aria-labelledby="workerAssignmentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="workerAssignmentModalLabel">
                        <i class="fas fa-exclamation-triangle"></i>
                        Employee Has Active Assignments
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Simple duplicate message (shown when already assigned to this exact work) -->
                    <div id="duplicateMessage" style="display: none;">
                        <div class="duplicate-warning-banner">
                            <i class="fas fa-exclamation-circle"></i>
                            <div>
                                <strong>This employee is already assigned to this exact work</strong>
                                <br>
                                <span>They cannot be assigned again to the same project/package/task.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Regular content for other assignments (shown when they have other assignments but not this exact one) -->
                    <div id="regularContent">
                        <div class="warning-banner" id="sameLevelWarning" style="display: none;">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Notice:</strong> This employee is already part of the same 
                                <span id="sameLevelText"></span> level hierarchy.
                            </div>
                        </div>

                        <h6 class="mb-3">Current Active Assignments:</h6>
                        <div id="currentAssignmentList" class="assignment-list">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="cancelBtn">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal" id="proceedAssignBtn" style="display: none;">
                        <i class="fas fa-check-circle"></i> Still Assign?
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Store worker details from PHP
    const workersDetails = <?php echo json_encode($workers_details ?? []); ?>;
    
    // Current context
    const currentContext = {
        type: '<?php echo $assignment_level; ?>',
        projectId: '<?php echo $project_id; ?>',
        packageId: '<?php echo $package_id; ?>',
        maintenanceId: '<?php echo $maintenance_id; ?>',
        projectName: '<?php echo $project ? addslashes($project['Project_Name']) : ''; ?>',
        packageName: '<?php echo $package ? addslashes($package['Package_Name']) : ''; ?>',
        maintenanceTask: '<?php echo $maintenance_task ? addslashes($maintenance_task['Task_type']) : ''; ?>',
        assetName: '<?php echo $maintenance_task ? addslashes($maintenance_task['Asset_Name']) : ''; ?>'
    };

    let selectedWorkerId = null;
    let modalWorkerData = null;

    // Show/hide custom role field
    document.getElementById('role')?.addEventListener('change', function() {
        const customField = document.getElementById('custom_role_field');
        if (this.value === 'Other') {
            customField.style.display = 'block';
        } else {
            customField.style.display = 'none';
        }
    });

    document.getElementById('role_existing')?.addEventListener('change', function() {
        const customField = document.getElementById('custom_role_existing_field');
        if (this.value === 'Other') {
            customField.style.display = 'block';
        } else {
            customField.style.display = 'none';
        }
    });

    // Show/hide custom designation field
    document.getElementById('designation')?.addEventListener('change', function() {
        const customField = document.getElementById('custom_designation_field');
        if (this.value === 'Other') {
            customField.style.display = 'block';
        } else {
            customField.style.display = 'none';
        }
    });

    function checkWorkerAssignment(workerId) {
        if (!workerId) return;
        
        selectedWorkerId = workerId;
        const selectedOption = document.querySelector(`#existing_worker_select option[value="${workerId}"]`);
        
        if (!selectedOption) return;
        
        // Check if already assigned to this exact context
        const alreadyAssigned = selectedOption.dataset.alreadyAssigned === '1';
        const duplicateWarning = document.getElementById('duplicate_warning_message');
        const submitBtn = document.getElementById('submitBtn');
        
        if (alreadyAssigned) {
            let levelText = '';
            if (currentContext.type === 'project') levelText = 'project';
            else if (currentContext.type === 'package') levelText = 'package';
            else if (currentContext.type === 'maintenance') levelText = 'task';
            
            document.getElementById('duplicate_level').innerText = levelText;
            duplicateWarning.style.display = 'block';
            submitBtn.disabled = true;
        } else {
            duplicateWarning.style.display = 'none';
            submitBtn.disabled = false;
        }
        
        // Get worker data from workersDetails
        const workerData = workersDetails[workerId];
        
        if (workerData && workerData.assignments && workerData.assignments.length > 0) {
            showAssignmentModal(workerData, alreadyAssigned);
        }
    }

    function showAssignmentModal(workerData, isDuplicate) {
        const duplicateMessage = document.getElementById('duplicateMessage');
        const regularContent = document.getElementById('regularContent');
        const proceedBtn = document.getElementById('proceedAssignBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        
        if (isDuplicate) {
            // Show only the simple duplicate message
            duplicateMessage.style.display = 'block';
            regularContent.style.display = 'none';
            proceedBtn.style.display = 'none';
            cancelBtn.innerHTML = '<i class="fas fa-times"></i> Close';
        } else {
            // Show regular content with assignments
            duplicateMessage.style.display = 'none';
            regularContent.style.display = 'block';
            proceedBtn.style.display = 'inline-flex';
            cancelBtn.innerHTML = '<i class="fas fa-times"></i> Cancel';
            
            // Populate current assignments
            const assignmentList = document.getElementById('currentAssignmentList');
            let html = '';
            
            workerData.assignments.forEach(assignment => {
                let icon = '';
                let color = '';
                let typeText = '';
                
                if (assignment.Assignment_Type === 'project') {
                    icon = 'fa-folder-open';
                    color = '#3182ce';
                    typeText = 'Project';
                } else if (assignment.Assignment_Type === 'package') {
                    icon = 'fa-cubes';
                    color = '#805ad5';
                    typeText = 'Package';
                } else {
                    icon = 'fa-tasks';
                    color = '#38a169';
                    typeText = 'Task';
                }
                
                html += `
                    <div class="assignment-item">
                        <div class="assignment-icon" style="background: rgba(${color === '#3182ce' ? '49,130,206' : color === '#805ad5' ? '128,90,213' : '56,161,105'},0.1); color: ${color};">
                            <i class="fas ${icon}"></i>
                        </div>
                        <div class="assignment-details">
                            <div class="assignment-title">${typeText}: ${assignment.item_name}</div>
                            <div class="assignment-meta">
                                <span><i class="fas fa-tag"></i> Role: ${assignment.Role}</span>
                                <span><i class="fas fa-calendar"></i> Since: ${new Date(assignment.Assigned_Date).toLocaleDateString()}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            assignmentList.innerHTML = html;
            
            // Check if worker is in same hierarchy
            const sameLevelWarning = document.getElementById('sameLevelWarning');
            const sameLevelText = document.getElementById('sameLevelText');
            let isSameLevel = false;
            
            // Check if any of the worker's assignments match the current context
            workerData.assignments.forEach(assignment => {
                if (currentContext.type === 'project' && assignment.Project_ID == currentContext.projectId) {
                    isSameLevel = true;
                    sameLevelText.innerText = 'project';
                } else if (currentContext.type === 'package' && assignment.Package_ID == currentContext.packageId) {
                    isSameLevel = true;
                    sameLevelText.innerText = 'package';
                } else if (currentContext.type === 'maintenance' && assignment.Maintenance_ID == currentContext.maintenanceId) {
                    isSameLevel = true;
                    sameLevelText.innerText = 'task';
                }
            });
            
            sameLevelWarning.style.display = isSameLevel ? 'flex' : 'none';
        }
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('workerAssignmentModal'));
        modal.show();
    }

    // Handle proceed button - Just closes the modal, keeps selection
    document.getElementById('proceedAssignBtn')?.addEventListener('click', function() {
        // Just close the modal, keep the selection
        bootstrap.Modal.getInstance(document.getElementById('workerAssignmentModal')).hide();
    });

    // Handle cancel button - Just closes the modal, keeps selection
    document.getElementById('cancelBtn')?.addEventListener('click', function() {
        // Just close the modal, keep the selection
        bootstrap.Modal.getInstance(document.getElementById('workerAssignmentModal')).hide();
    });

    function switchTab(tab) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
        
        document.querySelector(`.tab[onclick="switchTab('${tab}')"]`).classList.add('active');
        document.getElementById(`tab-${tab}`).classList.add('active');
        
        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    }
    
    // Check URL for tab parameter on load
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');
        if (tab && (tab === 'new' || tab === 'existing')) {
            switchTab(tab);
        }
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>