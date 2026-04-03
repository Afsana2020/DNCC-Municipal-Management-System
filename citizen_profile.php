<?php
session_start();
include("connect.php");

// Check if user is logged in
if(!isset($_SESSION['email'])) {
    header("Location: logout.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_citizen = ($_SESSION['role'] == 'citizen');

if($is_admin && isset($_GET['id'])) {
    $profile_user_id = intval($_GET['id']);
    $viewing_own_profile = false;
} else {
    $profile_user_id = $_SESSION['id'];
    $viewing_own_profile = true;
}

// Handle profile update (inline edit)
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $citizen_name = $_POST['citizen_name'];
    $address = $_POST['address'];
    $contact = $_POST['contact'];
    
    // Check if profile exists
    $check_query = "SELECT * FROM Citizens WHERE user_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $profile_user_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    
    if($exists) {
        $update_query = "UPDATE Citizens SET Citizen_name = ?, Address = ?, Contact = ? WHERE user_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sssi", $citizen_name, $address, $contact, $profile_user_id);
    } else {
        $update_query = "INSERT INTO Citizens (Citizen_name, Address, Contact, user_id) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sssi", $citizen_name, $address, $contact, $profile_user_id);
    }
    
    if($stmt->execute()) {
        // Redirect to remove edit mode and show success
        header("Location: citizen_profile.php?success=1");
        exit();
    } else {
        $error_msg = "Error updating profile: " . $conn->error;
    }
    $stmt->close();
}

// Handle success message
if(isset($_GET['success'])) {
    $success_msg = "Profile updated successfully!";
}

// user details from users table
$user_query = "SELECT id, firstName, lastName, email, role, created_at FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $profile_user_id);
$stmt->execute();
$user_result = $stmt->get_result();

if($user_result->num_rows === 0) {
    if($is_admin) {
        header("Location: manage_users.php?error=user_not_found");
    } else {
        header("Location: logout.php");
    }
    exit();
}

$user = $user_result->fetch_assoc();
$stmt->close();

//citizen profile details
$citizen_query = "SELECT * FROM Citizens WHERE user_id = ?";
$stmt = $conn->prepare($citizen_query);
$stmt->bind_param("i", $profile_user_id);
$stmt->execute();
$citizen_result = $stmt->get_result();
$citizen_profile = $citizen_result->fetch_assoc();
$stmt->close();

$reports_count_query = "SELECT COUNT(*) as total_reports FROM Citizen_Reports WHERE user_id = ?";
$stmt = $conn->prepare($reports_count_query);
$stmt->bind_param("i", $profile_user_id);
$stmt->execute();
$reports_count_result = $stmt->get_result();
$reports_count_data = $reports_count_result->fetch_assoc();
$reports_count = $reports_count_data['total_reports'];
$stmt->close();

// Check if edit mode is active
$edit_mode = isset($_GET['edit']) && !$is_admin && $viewing_own_profile;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_admin ? 'View Citizen Profile' : 'My Profile'; ?> - Smart DNCC</title>
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

        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 0;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent) 0%, #2c5282 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            margin-right: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            flex-shrink: 0;
        }

        .profile-info h3 {
            margin: 0;
            color: #1a202c;
            font-size: 1.2rem;
        }

        .profile-info p {
            margin: 2px 0;
            color: #718096;
        }

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

        /* Editable fields */
        .edit-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .edit-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .edit-textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            resize: vertical;
        }
        .edit-textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .edit-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            justify-content: flex-end;
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

        .btn-edit {
            background: #fffaf0;
            color: #d69e2e;
            border: 1px solid #faf089;
        }
        .btn-edit:hover {
            background: #d69e2e;
            color: white;
            border-color: #d69e2e;
        }

        .btn-save {
            background: var(--success);
            color: white;
            border: none;
            min-width: 100px;
        }
        .btn-save:hover {
            background: #2f855a;
        }

        .btn-cancel {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            min-width: 100px;
        }
        .btn-cancel:hover {
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

        .btn-success {
            background: var(--success);
            color: white;
            border: none;
            min-width: 120px;
        }
        .btn-success:hover {
            background: #2f855a;
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

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.7rem;
            min-width: 80px;
        }

        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stats-number {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 5px;
            line-height: 1;
        }

        .stats-label {
            font-size: 0.8rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .view-only-badge {
            background: #edf2f7;
            color: #4a5568;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #cbd5e0;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
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
        .alert-info {
            background: #ebf8ff;
            color: #2c5282;
            border: 1px solid #90cdf4;
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

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-header h4 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        h2, h4 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.35rem; }
        h4 { font-size: 1.1rem; }

        .text-muted {
            color: #718096 !important;
        }

        /* 2x2 grid - BALANCED */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .grid-span-2 {
            grid-column: span 2;
        }

        /* Edit mode highlight */
        .edit-section {
            border: 2px solid var(--accent);
            box-shadow: 0 0 0 3px rgba(49,130,206,0.1);
            transition: all 0.3s;
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
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }

            .profile-avatar {
                margin-right: 0;
                margin-bottom: 15px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .grid-span-2 {
                grid-column: span 1;
            }
            
            .content-area {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <?php if($is_admin): ?>
        <?php include 'sidebar.php'; ?>
    <?php else: ?>
        <?php include 'citizen_sidebar.php'; ?>
    <?php endif; ?>

    <div class="<?php echo $is_admin ? 'admin-main' : 'citizen-main'; ?>">
        <div class="<?php echo $is_admin ? 'admin-header' : 'citizen-header'; ?>">
            <div class="header-title">
                <div>
                    <h2>
                        <?php 
                        if($is_admin) {
                            echo 'Citizen Profile';
                        } else {
                            echo 'My Profile';
                        }
                        ?>
                    </h2>
                    <p class="text-muted mb-0">
                        <?php 
                        if($is_admin) {
                            echo 'Viewing citizen profile information';
                        } else {
                            echo 'Manage your personal information and view your reports';
                        }
                        ?>
                    </p>
                </div>
                <?php if($is_admin): ?>
                    <span class="view-only-badge">
                        <i class="fas fa-eye me-1"></i>View Only
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-area">
        
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

            <!-- Profile Header -->
            <div class="content-section">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="profile-info">
                        <h3><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></h3>
                        <p><?php echo $is_admin ? 'Citizen User' : 'Citizen Member'; ?></p>
                        <p class="text-muted">
                            <i class="fas fa-calendar-alt me-1"></i>Member since <?php echo date('F Y', strtotime($user['created_at'])); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex justify-content-end gap-2 mb-3">
                <?php if($is_admin): ?>
                    <a href="manage_users.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Users
                    </a>
                <?php else: ?>
                    <a href="citizen_dashboard.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                <?php endif; ?>
            </div>

            <!-- User Account Information - Read Only -->
            <div class="content-section">
                <h4 class="mb-3"><i class="fas fa-user-circle me-2 text-primary"></i>Account Information</h4>
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label">First Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['firstName']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Last Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['lastName']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Email Address</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Account Role</div>
                        <div class="info-value">
                            <span class="badge bg-primary"><?php echo ucfirst($user['role']); ?></span>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Account Created</div>
                        <div class="info-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">User ID</div>
                        <div class="info-value">#<?php echo $user['id']; ?></div>
                    </div>
                </div>
            </div>

           <!-- Profile Details - Editable for citizen -->
<div class="content-section <?php echo $edit_mode ? 'edit-section' : ''; ?>" id="profile-section">
    <div class="section-header">
        <h4><i class="fas fa-id-card me-2 text-success"></i>Citizen Profile Details</h4>
        <?php if(!$is_admin && $viewing_own_profile && !$edit_mode): ?>
            <a href="?edit=1#profile-section" class="action-btn btn-edit">
                <i class="fas fa-edit"></i> Edit Profile
            </a>
        <?php endif; ?>
    </div>

    <?php if($is_admin): ?>
        <!-- Admin view - read only -->
        <?php if($citizen_profile): ?>
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($citizen_profile['Citizen_name']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Contact Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($citizen_profile['Contact'] ?: 'Not provided'); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Citizen ID</div>
                    <div class="info-value">#<?php echo $citizen_profile['Citizen_ID']; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($citizen_profile['Address'] ?: 'Not provided'); ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-id-card"></i>
                <h5>No Profile Details</h5>
                <p class="text-muted">This citizen has not set up their profile yet.</p>
            </div>
        <?php endif; ?>

    <?php elseif($edit_mode): ?>
        <!-- Edit Mode - Form will scroll to this section -->
        <form method="POST" id="editForm">
            <div class="info-grid">
                <div class="info-card grid-span-2">
                    <div class="info-label">Full Name</div>
                    <input type="text" class="edit-input" name="citizen_name" 
                           value="<?php echo $citizen_profile ? htmlspecialchars($citizen_profile['Citizen_name']) : htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?>" 
                           required>
                </div>
                <div class="info-card grid-span-2">
                    <div class="info-label">Contact Number</div>
                    <input type="text" class="edit-input" name="contact" 
                           value="<?php echo $citizen_profile ? htmlspecialchars($citizen_profile['Contact']) : ''; ?>" 
                           placeholder="Enter your phone number">
                </div>
                <div class="info-card">
                    <div class="info-label">Address</div>
                    <textarea class="edit-textarea" name="address" rows="3" placeholder="Enter your complete address"><?php echo $citizen_profile ? htmlspecialchars($citizen_profile['Address']) : ''; ?></textarea>
                </div>
                <div class="info-card">
                    <div class="info-label">Citizen ID</div>
                    <div class="info-value" style="line-height: 38px;">#<?php echo $citizen_profile ? $citizen_profile['Citizen_ID'] : 'Will be generated'; ?></div>
                </div>
            </div>
            
            <div class="edit-actions">
                <a href="citizen_profile.php" class="action-btn btn-cancel">
                    Cancel
                </a>
                <button type="submit" name="update_profile" class="action-btn btn-save">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>

    <?php else: ?>
        <!-- View Mode (citizen) -->
        <?php if($citizen_profile): ?>
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($citizen_profile['Citizen_name']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Contact Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($citizen_profile['Contact'] ?: 'Not provided'); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Citizen ID</div>
                    <div class="info-value">#<?php echo $citizen_profile['Citizen_ID']; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($citizen_profile['Address'] ?: 'Not provided'); ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-id-card"></i>
                <h5>No Profile Details</h5>
                <p class="text-muted">You haven't set up your citizen profile yet.</p>
                <a href="?edit=1#profile-section" class="btn btn-primary btn-sm mt-2">
                    <i class="fas fa-plus me-1"></i>Create Profile Now
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

            <!-- Reports Summary -->
            <div class="content-section">
                <h4 class="mb-3"><i class="fas fa-flag me-2 text-warning"></i><?php echo $is_admin ? 'Citizen Reports' : 'My Reports'; ?></h4>
                
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo $reports_count; ?></div>
                            <div class="stats-label">Total Reports</div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="info-card" style="border-left-color: var(--warning);">
                            <div class="info-label">Reports Summary</div>
                            <div class="info-value">
                                <?php if($is_admin): ?>
                                    This citizen has submitted <strong><?php echo $reports_count; ?></strong> report(s) to the DNCC authority.
                                <?php else: ?>
                                    You have submitted <strong><?php echo $reports_count; ?></strong> report(s) to the DNCC authority.
                                <?php endif; ?>
                            </div>
                            
                            <?php if($reports_count > 0): ?>
                                <div class="mt-3 text-end">
                                    <a href="<?php echo $is_admin ? 'citizen_reports.php?user_id=' . $profile_user_id : 'citizen_reports.php'; ?>" class="btn btn-success">
                                        <i class="fas fa-list me-1"></i> View All Reports
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="mt-3 text-muted small">
                                    <i class="fas fa-info-circle me-1"></i>No reports have been submitted yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Scroll to profile section if in edit mode
    <?php if($edit_mode): ?>
    window.onload = function() {
        var element = document.getElementById('profile-section');
        if(element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>