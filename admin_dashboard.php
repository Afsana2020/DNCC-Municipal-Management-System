<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

// Asset Statistics (only asset stuff)
$total_assets_result = $conn->query("SELECT COUNT(*) as total FROM Assets");
$total_assets = $total_assets_result ? $total_assets_result->fetch_assoc()['total'] : 0;

// Project Statistics (only Large, Routine, Urgent)
$projects_by_type = $conn->query("SELECT Project_Type, COUNT(*) as count FROM Projects GROUP BY Project_Type");

$large_projects = 0;
$routine_projects = 0;
$urgent_projects = 0;
if($projects_by_type) {
    while($row = $projects_by_type->fetch_assoc()) {
        if($row['Project_Type'] == 'Large') $large_projects = $row['count'];
        if($row['Project_Type'] == 'Routine') $routine_projects = $row['count'];
        if($row['Project_Type'] == 'Urgent') $urgent_projects = $row['count'];
    }
}

// Package Statistics
$total_packages_result = $conn->query("SELECT COUNT(*) as total FROM Packages");
$total_packages = $total_packages_result ? $total_packages_result->fetch_assoc()['total'] : 0;

$dpp_packages_result = $conn->query("SELECT COUNT(*) as total FROM Packages WHERE Package_Type = 'DPP'");
$dpp_packages = $dpp_packages_result ? $dpp_packages_result->fetch_assoc()['total'] : 0;

$maintenance_packages_result = $conn->query("SELECT COUNT(*) as total FROM Packages WHERE Package_Type = 'Maintenance'");
$maintenance_packages = $maintenance_packages_result ? $maintenance_packages_result->fetch_assoc()['total'] : 0;

// Segment Statistics
$total_segments_result = $conn->query("SELECT COUNT(*) as total FROM Maintenance");
$total_segments = $total_segments_result ? $total_segments_result->fetch_assoc()['total'] : 0;

// Worker Statistics
$total_workers_result = $conn->query("SELECT COUNT(*) as total FROM Workers");
$total_workers = $total_workers_result ? $total_workers_result->fetch_assoc()['total'] : 0;

// Citizen Statistics (keep as is)
$total_citizens_result = $conn->query("SELECT COUNT(*) as total FROM Users where role='citizen'");
$total_citizens = $total_citizens_result ? $total_citizens_result->fetch_assoc()['total'] : 0;

$total_reports_result = $conn->query("SELECT COUNT(*) as total FROM Citizen_Reports");
$total_reports = $total_reports_result ? $total_reports_result->fetch_assoc()['total'] : 0;

$pending_reports_result = $conn->query("SELECT COUNT(*) as total FROM Citizen_Reports WHERE Status IN ('Submitted', 'Under Review')");
$pending_reports = $pending_reports_result ? $pending_reports_result->fetch_assoc()['total'] : 0;

// Get user details with error handling
$user_firstName = '';
$user_lastName = '';
if(isset($_SESSION['email'])) {
    $email = $_SESSION['email'];
    $query = mysqli_query($conn, "SELECT users.* FROM `users` WHERE users.email='$email'");
    if($query && mysqli_num_rows($query) > 0) {
        $user = mysqli_fetch_array($query);
        $user_firstName = isset($user['firstName']) ? $user['firstName'] : '';
        $user_lastName = isset($user['lastName']) ? $user['lastName'] : '';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Smart DNCC</title>
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

        /* Key Metrics - 5 columns */
        .row-key-metrics {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            color: #1a202c;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: var(--accent);
            font-size: 1rem;
        }

        .row-dynamic {
            display: grid;
            gap: 16px;
            margin-bottom: 32px;
        }

        .row-2cols { grid-template-columns: repeat(2, 1fr); }
        .row-3cols { grid-template-columns: repeat(3, 1fr); }
        .row-4cols { grid-template-columns: repeat(4, 1fr); }
        .row-5cols { grid-template-columns: repeat(5, 1fr); }

        .metric-item {
            background: white;
            border-radius: 10px;
            padding: 16px;
            border: 1px solid #e2e8f0;
        }

        .metric-label {
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 6px;
        }

        .metric-value {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            line-height: 1.2;
            margin-bottom: 2px;
        }

        .metric-sub {
            color: #94a3b8;
            font-size: 0.65rem;
        }

        .badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 500;
        }

        .badge-blue { background: #eff6ff; color: #2563eb; }
        .badge-green { background: #f0fdf4; color: #16a34a; }
        .badge-red { background: #fef2f2; color: #dc2626; }
        .badge-purple { background: #faf5ff; color: #9333ea; }
        .badge-orange { background: #fff7ed; color: #c2410c; }

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

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            .row-key-metrics {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="admin-main">
        <!-- Header -->
        <div class="admin-header">
            <div class="header-title">
                <h2>Dashboard</h2>
                <p>Welcome back, <?php echo htmlspecialchars($user_firstName . ' ' . $user_lastName); ?></p>
            </div>
        </div>

        <!-- Scrollable Content -->
        <div class="content-area">
            
            <!-- Key Metrics - Top row -->
            <div class="row-key-metrics">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #eff6ff; color: #2563eb;">
                            <i class="fas fa-building"></i>
                        </div>
                        <span class="badge badge-blue">Assets</span>
                    </div>
                    <div class="stat-value"><?php echo $total_assets; ?></div>
                    <div class="stat-label">Total Assets</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #fef2f2; color: #dc2626;">
                            <i class="fas fa-folder-open"></i>
                        </div>
                        <span class="badge badge-red">Projects</span>
                    </div>
                    <div class="stat-value"><?php echo $large_projects + $routine_projects + $urgent_projects; ?></div>
                    <div class="stat-label">Total Projects</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #faf5ff; color: #9333ea;">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <span class="badge badge-purple">Packages</span>
                    </div>
                    <div class="stat-value"><?php echo $total_packages; ?></div>
                    <div class="stat-label">Total Packages</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #f0fdf4; color: #16a34a;">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <span class="badge badge-green">Segments</span>
                    </div>
                    <div class="stat-value"><?php echo $total_segments; ?></div>
                    <div class="stat-label">Maintenance Tasks</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #fff7ed; color: #c2410c;">
                            <i class="fas fa-users"></i>
                        </div>
                        <span class="badge badge-orange">Citizens</span>
                    </div>
                    <div class="stat-value"><?php echo $total_citizens; ?></div>
                    <div class="stat-label">Registered Users</div>
                </div>
            </div>

            <!-- Projects - Only Large, Routine, Urgent -->
            <div class="section-title">
                <i class="fas fa-folder-open"></i>
                Projects by Type
            </div>
            <div class="row-dynamic row-3cols">
                <div class="metric-item">
                    <div class="metric-label">Large Projects</div>
                    <div class="metric-value"><?php echo $large_projects; ?></div>
                    <div class="metric-sub">Multi-zone development</div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Routine Projects</div>
                    <div class="metric-value"><?php echo $routine_projects; ?></div>
                    <div class="metric-sub">Single zone maintenance</div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Urgent Projects</div>
                    <div class="metric-value"><?php echo $urgent_projects; ?></div>
                    <div class="metric-sub">Emergency response</div>
                </div>
            </div>

            <!-- Assets - Only asset stuff -->
            <div class="section-title">
                <i class="fas fa-building"></i>
                Assets Overview
            </div>
            <div class="row-dynamic row-4cols">
                <div class="metric-item">
                    <div class="metric-label">Total Assets</div>
                    <div class="metric-value"><?php echo $total_assets; ?></div>
                    <div class="metric-sub">Infrastructure</div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Employees</div>
                    <div class="metric-value"><?php echo $total_workers; ?></div>
                    <div class="metric-sub">Assigned Employees</div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">DPP Packages</div>
                    <div class="metric-value"><?php echo $dpp_packages; ?></div>
                    <div class="metric-sub">Development</div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Maintenance Packages</div>
                    <div class="metric-value"><?php echo $maintenance_packages; ?></div>
                    <div class="metric-sub">Regular upkeep</div>
                </div>
            </div>

            <!-- Citizen Engagement - Keep as is -->
            <div class="section-title">
                <i class="fas fa-users" style="color: #00a3c4;"></i>
                Citizen Engagement
            </div>
            <div class="row-dynamic row-3cols">
                <div class="metric-item">
                    <div class="metric-label">Registered Citizens</div>
                    <div class="metric-value"><?php echo $total_citizens; ?></div>
                    <div class="metric-sub">Active users</div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Total Reports</div>
                    <div class="metric-value"><?php echo $total_reports; ?></div>
                    <div class="metric-sub">All time submissions</div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Pending Reports</div>
                    <div class="metric-value"><?php echo $pending_reports; ?></div>
                    <div class="metric-sub">Awaiting review</div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>