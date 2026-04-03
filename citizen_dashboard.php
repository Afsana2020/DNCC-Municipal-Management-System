<?php
session_start();
include("connect.php");

// Check if user is citizen
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'citizen') {
    header("Location: logout.php");
    exit();
}

//user info 
$email = $_SESSION['email'];
$user_query = "SELECT u.id, u.firstName, u.lastName, u.email, u.created_at,
                      (SELECT COUNT(*) FROM Assets) as assets_count,
                      (SELECT COUNT(*) FROM Citizen_Reports WHERE user_id = u.id) as reports_count,
                      (SELECT COUNT(*) FROM Citizen_Reports WHERE user_id = u.id AND Status = 'Resolved') as resolved_count
               FROM users u 
               WHERE u.email = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("s", $email);
$stmt->execute();
$user_result = $stmt->get_result();

if($user_result->num_rows === 0) {
    header("Location: logout.php");
    exit();
}

$user_info = $user_result->fetch_assoc();
$user_id = $user_info['id'];
$_SESSION['id'] = $user_id;

$assets_count = $user_info['assets_count'];
$reports_count = $user_info['reports_count'];
$resolved_count = $user_info['resolved_count'];

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Dashboard - Smart DNCC</title>
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

        .citizen-main {
            margin-left: 220px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            background: #f8fafc;
        }

        .citizen-header {
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

        /* Welcome Card */
        .welcome-card {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 28px;
            position: relative;
        }

        .welcome-card h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .welcome-card p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .welcome-icon {
            font-size: 5rem;
            opacity: 0.3;
            position: absolute;
            bottom: 10px;
            right: 20px;
        }

        /* Stats - 2 columns exactly */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 16px;
            min-height: 120px;
        }

        .stats-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .stats-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            flex-shrink: 0;
        }

        .stats-content {
            flex: 1;
        }

        .stats-number {
            font-size: 2.2rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 4px;
            color: #1a202c;
        }

        .stats-label {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Quick Actions - 3 columns */
        .actions-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .quick-action-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
            min-height: 160px;
        }

        .quick-action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            border-color: var(--accent);
        }

        .action-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: rgba(49, 130, 206, 0.1);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 16px;
        }

        .quick-action-card h5 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #1a202c;
        }

        .quick-action-card p {
            color: #64748b;
            font-size: 0.8rem;
            margin: 0;
            line-height: 1.4;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: var(--accent);
            font-size: 1rem;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 500;
            display: inline-block;
        }

        .badge-blue {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #90cdf4;
        }

        .badge-green {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #9ae6b4;
        }

        .text-muted {
            color: #718096 !important;
        }

        @media (max-width: 768px) {
            .citizen-main {
                margin-left: 0;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .actions-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'citizen_sidebar.php'; ?>

    <div class="citizen-main">
        <div class="citizen-header">
            <div class="header-title">
                <h2>Dashboard</h2>
                <p>Welcome back, <?php echo htmlspecialchars($user_info['firstName']); ?>!</p>
            </div>
        </div>

        <div class="content-area">
            
            <!-- Welcome Card -->
            <div class="welcome-card">
                <h3>Hello, <?php echo htmlspecialchars($user_info['firstName'] . ' ' . $user_info['lastName']); ?>!</h3>
                <p class="mb-0">Track public assets, submit reports, and stay connected with your city.</p>
                <div class="welcome-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
            </div>

            <!-- Stats - 2 columns -->
            <div class="stats-row">
                <div class="stats-card">
                    <div class="stats-icon" style="background: #eff6ff; color: #2563eb;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-number"><?php echo $assets_count; ?></div>
                        <div class="stats-label">Public Assets</div>
                    </div>
                </div>

                <div class="stats-card">
                    <div class="stats-icon" style="background: #f0fdf4; color: #16a34a;">
                        <i class="fas fa-flag"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-number"><?php echo $reports_count; ?></div>
                        <div class="stats-label">My Reports</div>
                        <?php if($resolved_count > 0): ?>
                            <div class="text-muted mt-1" style="font-size: 0.7rem;">
                                <?php echo $resolved_count; ?> resolved
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions - 3 columns -->
            <div class="section-title">
                <i class="fas fa-bolt"></i>
                Quick Actions
            </div>

            <div class="actions-row">
                <a href="citizen_assets.php" class="quick-action-card">
                    <div class="action-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <h5>View Assets</h5>
                    <p>Browse all public assets and their current status</p>
                </a>

                <a href="citizen_reports.php" class="quick-action-card">
                    <div class="action-icon">
                        <i class="fas fa-flag"></i>
                    </div>
                    <h5>Submit Report</h5>
                    <p>Report issues or concerns to city authorities</p>
                    <span class="badge badge-blue mt-2">New</span>
                </a>

                <a href="citizen_profile.php" class="quick-action-card">
                    <div class="action-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <h5>My Profile</h5>
                    <p>View and manage your account information</p>
                </a>
            </div>

            <!-- Member Since -->
            <div class="text-center mt-4">
                <span class="text-muted" style="font-size: 0.7rem;">
                    <i class="fas fa-clock me-1"></i>
                    Member since <?php echo date('F Y', strtotime($user_info['created_at'])); ?>
                </span>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>