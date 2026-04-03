<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

// Handle user deletion
if(isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    

    $check_query = "SELECT role FROM users WHERE id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    
    if($check_result->num_rows > 0) {
        $user_to_delete = $check_result->fetch_assoc();
        
     
        $current_user_id = $_SESSION['id'] ?? null;
        if (!$current_user_id) {
     
            $user_query = "SELECT id FROM users WHERE email = ?";
            $stmt2 = $conn->prepare($user_query);
            $stmt2->bind_param("s", $_SESSION['email']);
            $stmt2->execute();
            $user_result = $stmt2->get_result();
            if ($user_result->num_rows > 0) {
                $current_user = $user_result->fetch_assoc();
                $current_user_id = $current_user['id'];
                $_SESSION['id'] = $current_user_id; 
            }
            $stmt2->close();
        }
        

        if($delete_id != $current_user_id && $user_to_delete['role'] != 'admin') {
            $delete_query = "DELETE FROM users WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param("i", $delete_id);
            if($stmt->execute()) {
                $success_msg = "User deleted successfully!";
                
                header("Location: manage_users.php?success=deleted");
                exit();
            } else {
                $error_msg = "Error deleting user: " . $stmt->error;
            }
            $stmt->close();
        } else {
            if($delete_id == $current_user_id) {
                $error_msg = "You cannot delete your own account!";
            } else {
                $error_msg = "You cannot delete other administrators!";
            }
        }
    } else {
        $error_msg = "User not found!";
    }
    $stmt->close();
}


if(isset($_GET['success']) && $_GET['success'] == 'deleted') {
    $success_msg = "User deleted successfully!";
}

//all users with all details 
$users_query = "SELECT id, firstName, lastName, email, role, created_at FROM users ORDER BY created_at DESC";
$users_result = $conn->query($users_query);

//current user ID 
$current_user_id = $_SESSION['id'] ?? null;
if (!$current_user_id) {
    
    $user_query = "SELECT id FROM users WHERE email = ?";
    $stmt = $conn->prepare($user_query);
    $stmt->bind_param("s", $_SESSION['email']);
    $stmt->execute();
    $user_result = $stmt->get_result();
    if ($user_result->num_rows > 0) {
        $current_user = $user_result->fetch_assoc();
        $current_user_id = $current_user['id'];
        $_SESSION['id'] = $current_user_id;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Smart DNCC</title>
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
            border: 1px solid #e2e8f0;
        }

        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            text-align: center;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stats-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            transform: translateY(-2px);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 600;
            color: #1a202c;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .stats-label {
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
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
            text-align: left;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .table td {
            padding: 12px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.85rem;
        }

        .table tr:hover {
            background: #fafbfc;
        }

        .current-user {
            background: #f0fff4 !important;
            border-left: 4px solid var(--success);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
            min-width: 70px;
            text-align: center;
            display: inline-block;
        }

        .badge-admin {
            background: var(--accent);
            color: white;
            border: 1px solid #3182ce;
        }

        .badge-citizen {
            background: var(--success);
            color: white;
            border: 1px solid #38a169;
        }

        /* Actions column styling */
        .table td:last-child {
            text-align: right;
            padding-right: 16px;
        }

        .d-flex.gap-1 {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
        }

        .btn-sm {
            padding: 4px 12px;
            font-size: 0.75rem;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }

        .btn-outline-primary {
            border: 1px solid var(--accent);
            color: var(--accent);
            background: white;
        }
        .btn-outline-primary:hover {
            background: var(--accent);
            color: white;
        }

        .btn-outline-danger {
            border: 1px solid var(--danger);
            color: var(--danger);
            background: white;
        }
        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
        }

        /* Hide text on smaller screens */
        @media (max-width: 992px) {
            .btn-sm span {
                display: none !important;
            }
            .btn-sm i {
                margin-right: 0;
            }
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

        .btn {
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-danger {
            background: var(--danger);
            border-color: var(--danger);
            color: white;
        }
        .btn-danger:hover {
            background: #c53030;
            border-color: #c53030;
        }

        .btn-secondary {
            background: #edf2f7;
            border-color: #e2e8f0;
            color: #4a5568;
        }
        .btn-secondary:hover {
            background: #e2e8f0;
            border-color: #cbd5e0;
            color: #1a202c;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            color: #cbd5e0;
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
            
            .stats-card {
                min-height: 100px;
                padding: 15px;
            }
            
            .stats-number {
                font-size: 1.5rem;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
        }

        /* ===== FIX FOR CURRENT BADGE - FULL WIDTH AND CENTERED ===== */
        /* This makes the Current badge take up the entire actions column space and center it */
        .current-user td:last-child {
            text-align: center;
            vertical-align: middle;
        }

        .current-user td:last-child .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-width: 165px;
            margin: 0 auto;
            gap: 6px;
            font-size: 0.75rem;
            padding: 8px 16px;
            background: #38a169;
            color: white;
            border: none;
            margin-right: 0.8px;
        }
        
        /* Keep original styling for other badges */
        .badge.bg-success {
            background: #38a169;
        }
        
        /* Ensure the d-flex doesn't interfere with current user row */
        .current-user td:last-child .d-flex {
            justify-content: center;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Manage Users</h2>
                <p>View and manage all system users</p>
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

            <!-- Stats Cards - UNIFORM SIZE -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stats-card">
                        <div class="stats-number text-primary"><?php echo $users_result->num_rows; ?></div>
                        <div class="stats-label">Total Users</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <div class="stats-number text-warning">
                            <?php 
                            $admin_count = 0;
                            $users_result->data_seek(0);
                            while($user = $users_result->fetch_assoc()) {
                                if($user['role'] == 'admin') $admin_count++;
                            }
                            echo $admin_count;
                            ?>
                        </div>
                        <div class="stats-label">Administrators</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <div class="stats-number text-success">
                            <?php 
                            $citizen_count = 0;
                            $users_result->data_seek(0);
                            while($user = $users_result->fetch_assoc()) {
                                if($user['role'] == 'citizen') $citizen_count++;
                            }
                            echo $citizen_count;
                            ?>
                        </div>
                        <div class="stats-label">Citizens</div>
                    </div>
                </div>
            </div>

            <!-- Users Table -->
            <div class="content-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>All Users</h4>
                    <span class="badge bg-secondary"><?php echo $users_result->num_rows; ?> found</span>
                </div>

                <?php if($users_result && $users_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $users_result->data_seek(0);
                                while($user = $users_result->fetch_assoc()): 
                                    $is_current_user = ($user['id'] == $current_user_id);
                                    $can_delete = (!$is_current_user && $user['role'] == 'citizen');
                                    $has_profile = ($user['role'] == 'citizen');
                                ?>
                                    <tr class="<?php echo $is_current_user ? 'current-user' : ''; ?>">
                                        <td><strong>#<?php echo $user['id']; ?></strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $user['role'] == 'admin' ? 'badge-admin' : 'badge-citizen'; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        <td class="text-end pe-4">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <?php if($has_profile): ?>
                                                    <a href="citizen_profile.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-primary btn-sm" title="View Profile">
                                                        <i class="fas fa-user"></i>
                                                        <span class="d-none d-lg-inline ms-1">Profile</span>
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <?php if($can_delete): ?>
                                                    <button type="button" class="btn btn-outline-danger btn-sm" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deleteModal<?php echo $user['id']; ?>"
                                                            title="Remove User">
                                                        <i class="fas fa-trash"></i>
                                                        <span class="d-none d-lg-inline ms-1">Remove</span>
                                                    </button>
                                                <?php elseif($is_current_user): ?>
                                                    <span class="badge bg-success text-white py-2 px-3">
                                                        <i class="fas fa-check-circle me-1"></i>Current
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Delete Confirmation Modal -->
                                    <?php if($can_delete): ?>
                                    <div class="modal fade" id="deleteModal<?php echo $user['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Confirm User Removal</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Are you sure you want to remove <strong><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></strong>?</p>
                                                    <p class="text-danger mb-0">
                                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                                        This action cannot be undone. All user data will be permanently deleted.
                                                    </p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <a href="manage_users.php?delete_id=<?php echo $user['id']; ?>" class="btn btn-danger">
                                                        <i class="fas fa-trash me-1"></i>Delete User
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No Users Found</h5>
                        <p class="text-muted">There are no users in the system yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Admin Note -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Administrator Note:</strong> 
                Administrator accounts are protected and cannot be deleted.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>