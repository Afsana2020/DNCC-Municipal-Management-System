<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

//report details
$reports_query = "SELECT cr.*, 
                         a.Asset_Name,
                         a.Asset_Type,
                         a.Location as Asset_Location,
                         u.firstName, 
                         u.lastName,
                         u.email,
                         COUNT(m.Maintenance_ID) as maintenance_count
                  FROM Citizen_Reports cr 
                  LEFT JOIN Assets a ON cr.Asset_ID = a.Asset_ID
                  LEFT JOIN users u ON cr.user_id = u.id
                  LEFT JOIN Maintenance m ON m.Report_ID = cr.Report_ID
                  WHERE 1=1";

$params = [];
$types = '';

if(!empty($filter_status) && $filter_status != 'all') {
    $reports_query .= " AND cr.Status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

$reports_query .= " GROUP BY cr.Report_ID ORDER BY cr.Report_Date DESC";

$stmt = $conn->prepare($reports_query);
if(!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reports_result = $stmt->get_result();
$stmt->close();

$status_counts_query = "SELECT Status, COUNT(*) as count FROM Citizen_Reports GROUP BY Status";
$status_counts_result = $conn->query($status_counts_query);
$status_counts = [];
$total_reports = 0;
while($row = $status_counts_result->fetch_assoc()) {
    $status_counts[$row['Status']] = $row['count'];
    $total_reports += $row['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reports - Smart DNCC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --accent: #3182ce;
            --success: #38a169;
            --info: #00a3c4;
            --warning: #d69e2e;
            --danger: #e53e3e;
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
            border: 1px solid #e2e8f0;
        }

        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }

        .filter-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.75rem;
            margin-bottom: 4px;
        }

        .filter-control {
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
            width: 100%;
        }
        .filter-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        /* Stats cards - UNIFORM SIZE */
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 20px 15px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            text-align: center;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
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
            white-space: nowrap;
        }

        /* Status badges - UNIFORM WIDTH */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
            display: inline-block;
            min-width: 110px;
            text-align: center;
            border: 1px solid transparent;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .status-submitted { background: #e3f2fd; color: #1565c0; border-color: #90cdf4; }
        .status-under-review { background: #fff3e0; color: #ef6c00; border-color: #fbd38d; }
        .status-in-progress { background: #e8f5e8; color: #2e7d32; border-color: #9ae6b4; }
        .status-resolved { background: #f3e5f5; color: #7b1fa2; border-color: #d6bcfa; }
        .status-closed { background: #f5f5f5; color: #616161; border-color: #cbd5e0; }

        /* User badge - UNIFORM WIDTH */
        .user-badge {
            background: #f0fff4;
            color: #2d3748;
            font-size: 0.75rem;
            padding: 6px 10px;
            border-radius: 6px;
            white-space: nowrap;
            display: inline-block;
            min-width: 120px;
            text-align: center;
            border: 1px solid #c6f5d5;
            font-weight: 500;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        /* Asset badge - UNIFORM WIDTH */
        .asset-badge {
            background: #ebf8ff;
            color: #2d3748;
            font-size: 0.75rem;
            padding: 6px 10px;
            border-radius: 6px;
            white-space: nowrap;
            display: inline-block;
            min-width: 120px;
            text-align: center;
            border: 1px solid #bee3f8;
            font-weight: 500;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 8px 16px;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: #2c5282;
            border-color: #2c5282;
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            border-color: #718096;
            color: white;
        }

        .btn-sm {
            padding: 4px 12px;
            font-size: 0.75rem;
        }

        .btn-view {
            padding: 6px 12px;
            font-size: 0.75rem;
            border-radius: 4px;
            background: var(--accent);
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-width: 70px;
            justify-content: center;
        }
        .btn-view:hover {
            background: #2c5282;
            color: white;
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

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 500;
            min-width: 60px;
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

        .clear-filter {
            color: var(--danger);
            text-decoration: none;
            font-size: 0.75rem;
            padding: 4px 8px;
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
                padding: 15px 10px;
            }
            
            .stats-number {
                font-size: 1.5rem;
            }
            
            .stats-label {
                font-size: 0.7rem;
            }
            
            .status-badge {
                min-width: 90px;
                font-size: 0.7rem;
                padding: 4px 8px;
            }
            
            .user-badge, .asset-badge {
                min-width: 100px;
                font-size: 0.7rem;
                padding: 4px 8px;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
            
            .admin-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
        }

        @media (max-width: 576px) {
            .status-badge {
                min-width: 80px;
                font-size: 0.65rem;
            }
            
            .user-badge, .asset-badge {
                min-width: 90px;
                font-size: 0.65rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Manage Reports</h2>
                <p>View and manage all citizen reports</p>
            </div>
            <div>
                <span class="badge bg-primary fs-6 px-3 py-2">
                    <i class="fas fa-chart-bar me-1"></i>
                    Total: <?php echo $total_reports; ?>
                </span>
            </div>
        </div>

        <div class="content-area">
           
            <!-- Stats Cards - UNIFORM SIZE -->
            <div class="row g-3 mb-4">
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-primary"><?php echo $total_reports; ?></div>
                        <div class="stats-label">Total Reports</div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-info"><?php echo $status_counts['Submitted'] ?? 0; ?></div>
                        <div class="stats-label">Submitted</div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-warning"><?php echo $status_counts['Under Review'] ?? 0; ?></div>
                        <div class="stats-label">Under Review</div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-primary"><?php echo $status_counts['In Progress'] ?? 0; ?></div>
                        <div class="stats-label">In Progress</div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-success"><?php echo $status_counts['Resolved'] ?? 0; ?></div>
                        <div class="stats-label">Resolved</div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-secondary"><?php echo $status_counts['Closed'] ?? 0; ?></div>
                        <div class="stats-label">Closed</div>
                    </div>
                </div>
            </div>

           <!-- Filter Section - Clean with red Clear All link -->
<div class="filter-section">
    <form method="GET" action="">
        <div class="row g-3">
            <div class="col-md-12">
                <div class="filter-label">Filter by Status</div>
                <select class="filter-control" name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="Submitted" <?php echo $filter_status == 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                    <option value="Under Review" <?php echo $filter_status == 'Under Review' ? 'selected' : ''; ?>>Under Review</option>
                    <option value="In Progress" <?php echo $filter_status == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="Resolved" <?php echo $filter_status == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="Closed" <?php echo $filter_status == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
            </div>
        </div>
    </form>
    
    <!-- Active Filter Display - RED CLEAR ALL LINK (matches other pages) -->
    <?php if(!empty($filter_status) && $filter_status != 'all'): ?>
        <div class="mt-3 pt-2 border-top">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-light text-dark">Active:</span>
                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $filter_status)); ?>">
                    <?php echo $filter_status; ?>
                </span>
                <a href="manage_reports.php" class="clear-filter">
                    <i class="fas fa-times-circle me-1"></i>Clear All
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

            <!-- Reports Table -->
            <div class="content-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>All Reports</h4>
                    <span class="badge bg-secondary"><?php echo $reports_result->num_rows; ?> found</span>
                </div>

                <?php if($reports_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Report ID</th>
                                    <th>Type</th>
                                    <th>Citizen</th>
                                    <th>Asset</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Tasks</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($report = $reports_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong>#<?php echo $report['Report_ID']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($report['Report_type']); ?></td>
                                        <td>
                                            <a href="view_user.php?id=<?php echo $report['user_id']; ?>" class="text-decoration-none">
                                                <span class="user-badge" title="<?php echo htmlspecialchars($report['firstName'] . ' ' . $report['lastName']); ?>">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?php echo htmlspecialchars(mb_strimwidth($report['firstName'] . ' ' . $report['lastName'], 0, 12, '...')); ?>
                                                </span>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if($report['Asset_Name']): ?>
                                                <a href="view_asset.php?id=<?php echo $report['Asset_ID']; ?>" class="text-decoration-none">
                                                    <span class="asset-badge" title="<?php echo htmlspecialchars($report['Asset_Name'] . ' - ' . $report['Asset_Location']); ?>">
                                                        <i class="fas fa-building me-1"></i>
                                                        <?php echo htmlspecialchars(mb_strimwidth($report['Asset_Name'], 0, 12, '...')); ?>
                                                    </span>
                                                </a>
                                            <?php else: ?>
                                                <span class="asset-badge" style="background: #f5f5f5; color: #999;">No Asset</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($report['Report_Date'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $report['Status'])); ?>">
                                                <?php echo $report['Status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if($report['maintenance_count'] > 0): ?>
                                                <a href="manage_maintenance.php?report_id=<?php echo $report['Report_ID']; ?>" class="text-decoration-none">
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-tools me-1"></i>
                                                        <?php echo $report['maintenance_count']; ?>
                                                    </span>
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="view_report.php?id=<?php echo $report['Report_ID']; ?>" class="btn-view">
                                                <i class="fas fa-eye me-1"></i>View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-flag"></i>
                        <h5>No Reports Found</h5>
                        <p class="text-muted">
                            <?php if(!empty($filter_status)): ?>
                                No reports match your current filter. Try selecting a different status.
                            <?php else: ?>
                                There are no reports in the system yet.
                            <?php endif; ?>
                        </p>
                        <?php if(!empty($filter_status)): ?>
                            <a href="manage_reports.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-times me-1"></i>Clear Filter
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>