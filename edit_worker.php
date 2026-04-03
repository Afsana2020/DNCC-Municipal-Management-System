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

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_workers.php");
    exit();
}

$worker_id = $_GET['id'];

// Get worker basic info (Role removed from Workers table)
$worker_query = "SELECT Worker_ID, Worker_Name, Worker_Salary, Contact, Designation 
                 FROM Workers 
                 WHERE Worker_ID = ?";
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

// Handle form submission
if(isset($_POST['update_worker'])) {
    $worker_name = trim($_POST['worker_name']);
    $worker_salary = $_POST['worker_salary'];
    $designation = trim($_POST['designation']);
    $contact = trim($_POST['contact']);
    
    // Handle custom designation if "Other" was selected
    if($designation == 'Other' && !empty($_POST['custom_designation'])) {
        $designation = trim($_POST['custom_designation']);
    }
    
    $update_query = "UPDATE Workers SET Worker_Name = ?, Worker_Salary = ?, Designation = ?, Contact = ? WHERE Worker_ID = ?";
    $stmt = $conn->prepare($update_query);
    if($stmt) {
        $stmt->bind_param("sdssi", $worker_name, $worker_salary, $designation, $contact, $worker_id);
        if($stmt->execute()) {
            header("Location: view_worker.php?id=$worker_id&success=worker_updated");
            exit();
        } else {
            $error_msg = "Error updating employee: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Common designation options (same as add_worker.php)
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Employee - <?php echo htmlspecialchars($worker['Worker_Name']); ?></title>
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

        .header-title h2 {
            font-size: 1.6rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0 0 4px 0;
        }

        .header-title p {
            color: #64748b;
            font-size: 0.95rem;
            margin: 0;
        }

        .content-area {
            padding: 24px 28px;
            overflow-y: auto;
            height: calc(100vh - 89px);
            display: flex;
            flex-direction: column;
            justify-content: center;
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

        /* Form Container */
        .form-container {
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 900px;
            margin: 0 auto;
            box-shadow: 0 8px 24px rgba(0,0,0,0.05);
            width: 100%;
        }

        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 0.95rem;
            letter-spacing: 0.3px;
        }

        .form-control, .form-select {
            border-radius: 10px;
            padding: 14px 18px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            font-size: 1rem;
            width: 100%;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(49, 130, 206, 0.15);
        }

        /* Custom field for Other option */
        .custom-field {
            margin-top: 10px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 3px solid var(--accent);
        }

        /* Buttons */
        .btn {
            border-radius: 10px;
            font-weight: 500;
            padding: 12px 24px;
            font-size: 0.95rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: #2c5282;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(49,130,206,0.25);
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .alert {
            border: 1px solid;
            border-radius: 12px;
            font-size: 0.95rem;
            margin-bottom: 30px;
            padding: 16px 24px;
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

        h2 {
            font-size: 1.6rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
        }

        .text-muted {
            color: #64748b !important;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .form-card {
                padding: 24px;
            }
            
            .content-area {
                justify-content: flex-start;
                padding: 16px;
            }
            
            .form-container {
                max-width: 100%;
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
                <div>
                    <h2>
                        <i class="fas fa-edit me-2" style="color: var(--warning);"></i>
                        Edit Employee
                    </h2>
                    <p class="text-muted">Update information for <?php echo htmlspecialchars($worker['Worker_Name']); ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="view_worker.php?id=<?php echo $worker_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-eye me-1"></i>View Profile
                    </a>
                    <a href="manage_workers.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to List
                    </a>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="content-area">
            
            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Edit Employee Form -->
            <div class="form-container">
                <div class="form-card">
                    <form method="POST" action="" id="editWorkerForm">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Employee Name *</label>
                                <input type="text" class="form-control" name="worker_name" 
                                       value="<?php echo htmlspecialchars($worker['Worker_Name']); ?>" 
                                       required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Permanent Designation *</label>
                                <select class="form-select" name="designation" id="designation" required>
                                    <option value="">Select Designation</option>
                                    <?php foreach($designation_options as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" 
                                            <?php echo ($worker['Designation'] == $value) ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="custom_designation_field" class="custom-field" style="display: <?php echo ($worker['Designation'] == 'Other') ? 'block' : 'none'; ?>;">
                                    <label class="form-label">Specify Designation</label>
                                    <input type="text" class="form-control" name="custom_designation" 
                                           value="<?php echo ($worker['Designation'] == 'Other') ? htmlspecialchars($worker['Designation']) : ''; ?>" 
                                           placeholder="Enter designation">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Monthly Salary (BDT) *</label>
                                <input type="number" class="form-control" name="worker_salary" step="0.01" min="0"
                                       value="<?php echo $worker['Worker_Salary']; ?>" 
                                       required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Contact Number</label>
                                <input type="text" class="form-control" name="contact" 
                                       value="<?php echo htmlspecialchars($worker['Contact'] ?: ''); ?>" 
                                       placeholder="01XXXXXXXXX">
                            </div>
                        </div>

                        <div class="d-flex gap-2 justify-content-end mt-5">
                            <a href="view_worker.php?id=<?php echo $worker_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Cancel
                            </a>
                            <button type="submit" name="update_worker" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Employee
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Show/hide custom designation field
    document.getElementById('designation')?.addEventListener('change', function() {
        const customField = document.getElementById('custom_designation_field');
        if (this.value === 'Other') {
            customField.style.display = 'block';
        } else {
            customField.style.display = 'none';
        }
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>