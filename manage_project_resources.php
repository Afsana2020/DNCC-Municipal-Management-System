<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

// Get project ID from URL
$project_id = isset($_GET['id']) ? $_GET['id'] : 0;

if($project_id == 0) {
    header("Location: manage_projects.php");
    exit();
}

// Fetch project details
$project_query = "SELECT * FROM Projects WHERE Project_ID = ?";
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

// Handle success messages
if(isset($_GET['success'])) {
    if($_GET['success'] == 'resource_updated') {
        $success_msg = "Resource updated successfully!";
    } elseif($_GET['success'] == 'resource_deleted') {
        $success_msg = "Resource deleted successfully!";
    }
}

// Get filter parameters
$filter_package = isset($_GET['package_id']) ? $_GET['package_id'] : '';
$filter_segment = isset($_GET['segment_id']) ? $_GET['segment_id'] : '';
$filter_resource_type = isset($_GET['resource_type']) ? $_GET['resource_type'] : '';

// Get all packages for this project (for filter dropdown)
$packages_query = "SELECT Package_ID, Package_Name, Status 
                   FROM Packages 
                   WHERE Project_ID = ? 
                   ORDER BY Package_Name";
$stmt = $conn->prepare($packages_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$packages_result = $stmt->get_result();
$package_count = $packages_result->num_rows;

// Get all segments for this project (for filter dropdown)
$segments_query = "SELECT m.Maintenance_ID, m.Task_type, a.Asset_Name, m.Package_ID,
                          p.Package_Name
                   FROM Maintenance m
                   LEFT JOIN Assets a ON m.Asset_ID = a.Asset_ID
                   LEFT JOIN Packages p ON m.Package_ID = p.Package_ID
                   WHERE m.Project_ID = ? 
                   ORDER BY m.Start_Date DESC";
$stmt = $conn->prepare($segments_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$segments_result = $stmt->get_result();
$segment_count = $segments_result->num_rows;

// Get all resource types for filter
$resource_types_query = "SELECT DISTINCT Resource_Type FROM Resources ORDER BY Resource_Type";
$resource_types_result = $conn->query($resource_types_query);

// Build resources query with filters
$resources_query = "SELECT r.*, 
                           m.Maintenance_ID, m.Task_type, m.Status as Task_Status,
                           a.Asset_ID, a.Asset_Name, a.Asset_Type,
                           p.Package_ID, p.Package_Name,
                           pr.Project_ID, pr.Project_Name,
                           (r.Quantity * r.Unit_Cost) as Total_Cost
                    FROM Resources r
                    LEFT JOIN Maintenance m ON r.Maintenance_ID = m.Maintenance_ID
                    LEFT JOIN Assets a ON m.Asset_ID = a.Asset_ID
                    LEFT JOIN Packages p ON m.Package_ID = p.Package_ID
                    LEFT JOIN Projects pr ON m.Project_ID = pr.Project_ID
                    WHERE m.Project_ID = ?";

if(!empty($filter_package)) {
    $resources_query .= " AND m.Package_ID = " . intval($filter_package);
}

if(!empty($filter_segment)) {
    $resources_query .= " AND m.Maintenance_ID = " . intval($filter_segment);
}

if(!empty($filter_resource_type)) {
    $resources_query .= " AND r.Resource_Type = '" . $conn->real_escape_string($filter_resource_type) . "'";
}

$resources_query .= " ORDER BY r.Resource_ID DESC";

$stmt = $conn->prepare($resources_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$resources_result = $stmt->get_result();
$resource_count = $resources_result->num_rows;

// Calculate totals
$total_resources_cost = 0;
$resources_data = [];
if($resource_count > 0) {
    $resources_result->data_seek(0);
    while($resource = $resources_result->fetch_assoc()) {
        $resources_data[] = $resource;
        $total_resources_cost += $resource['Total_Cost'];
    }
    $resources_result->data_seek(0);
}

// Get stats
$stats_query = "SELECT 
                (SELECT COUNT(*) FROM Resources r 
                 JOIN Maintenance m ON r.Maintenance_ID = m.Maintenance_ID 
                 WHERE m.Project_ID = ?) as total_resources,
                (SELECT COUNT(DISTINCT r.Resource_Type) FROM Resources r 
                 JOIN Maintenance m ON r.Maintenance_ID = m.Maintenance_ID 
                 WHERE m.Project_ID = ?) as resource_types,
                (SELECT SUM(r.Quantity * r.Unit_Cost) FROM Resources r 
                 JOIN Maintenance m ON r.Maintenance_ID = m.Maintenance_ID 
                 WHERE m.Project_ID = ?) as total_cost
                FROM dual";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("iii", $project_id, $project_id, $project_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Resources - <?php echo htmlspecialchars($project['Project_Name']); ?> - Smart DNCC</title>
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

        .project-banner h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            color: white;
        }

        .project-badge {
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

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.2s;
            min-height: 80px;
        }
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-label {
            color: #718096;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            text-decoration: none;
            min-width: 70px;
            text-align: center;
        }

        .badge-project { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .badge-package { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; }
        .badge-task { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-resource { background: #e2e8f0; color: #4a5568; border: 1px solid #cbd5e0; }

        /* Resource Row - Clean uniform style */
        .resource-row {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            min-height: 70px;
        }
        .resource-row:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
        }

        .resource-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            flex: 1;
        }

        .resource-details {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .resource-id {
            background: #e2e8f0;
            color: #2d3748;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 50px;
            text-align: center;
        }

        .resource-type {
            font-weight: 600;
            color: #2d3748;
            min-width: 80px;
        }

        .resource-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #718096;
            font-size: 0.8rem;
        }

        .resource-meta span {
            min-width: 60px;
            text-align: right;
        }

        .resource-cost {
            color: var(--success);
            font-weight: 600;
            min-width: 80px;
            text-align: right;
        }

        .resource-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.7rem;
            border-radius: 6px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: none;
            min-width: 36px;
            justify-content: center;
        }

        .btn-view {
            background: #3182ce;
            color: white;
        }
        .btn-view:hover {
            background: #2c5282;
        }

        .btn-edit {
            background: #d69e2e;
            color: white;
        }
        .btn-edit:hover {
            background: #b7791f;
        }

        .btn-delete {
            background: #e53e3e;
            color: white;
        }
        .btn-delete:hover {
            background: #c53030;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
            padding: 8px 16px;
            font-size: 0.85rem;
            border-radius: 6px;
            min-width: 100px;
        }
        .btn-primary:hover {
            background: #2c5282;
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            padding: 8px 16px;
            font-size: 0.85rem;
            border-radius: 6px;
            min-width: 120px;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            color: white;
        }

        .btn-outline-secondary.btn-sm {
            padding: 5px 10px;
            min-width: 100px;
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
            
            .project-banner {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .resource-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .resource-actions {
                margin-top: 8px;
            }
            
            .resource-row {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Project Resources</h2>
                <p class="text-muted mb-0">Manage resources across all packages and segments</p>
            </div>
            <div>
                <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Project
                </a>
            </div>
        </div>

        <div class="content-area">

            <?php if(isset($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Project Banner -->
            <div class="project-banner">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="project-badge">
                            <i class="fas fa-folder-open me-1"></i>
                            <?php echo $project_type; ?>
                        </span>
                        <span class="project-badge">
                            <i class="fas fa-hashtag me-1"></i>ID: <?php echo $project_id; ?>
                        </span>
                    </div>
                    <h1><?php echo htmlspecialchars($project['Project_Name']); ?></h1>
                </div>
                <div>
                    <span class="badge fs-6 p-3
                        <?php 
                        if($project['Status'] == 'Planning') echo 'bg-secondary';
                        elseif($project['Status'] == 'Active') echo 'bg-success';
                        elseif($project['Status'] == 'Completed') echo 'bg-info';
                        else echo 'bg-warning';
                        ?>">
                        <?php echo $project['Status']; ?>
                    </span>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(49,130,206,0.1); color: #3182ce;">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['total_resources'] ?? 0; ?></div>
                            <div class="stat-label">Total Resources</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                            <i class="fas fa-tag"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $stats['resource_types'] ?? 0; ?></div>
                            <div class="stat-label">Resource Types</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                            <i class="fas fa-money-bill"></i>
                        </div>
                        <div>
                            <div class="stat-number">৳<?php echo number_format($stats['total_cost'] ?? 0); ?></div>
                            <div class="stat-label">Total Cost</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
        <!-- Filter Section - 3 Equal Columns -->
<div class="filter-section">
    <form method="GET" action="manage_project_resources.php" id="filterForm">
        <input type="hidden" name="id" value="<?php echo $project_id; ?>">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="filter-label">Package</div>
                <select name="package_id" class="filter-control" onchange="this.form.submit()">
                    <option value="">All Packages</option>
                    <?php 
                    $packages_result->data_seek(0);
                    while($package = $packages_result->fetch_assoc()): 
                    ?>
                        <option value="<?php echo $package['Package_ID']; ?>" 
                            <?php echo ($filter_package == $package['Package_ID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($package['Package_Name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-4">
                <div class="filter-label">Segment (Task)</div>
                <select name="segment_id" class="filter-control" onchange="this.form.submit()">
                    <option value="">All Segments</option>
                    <?php 
                    $segments_result->data_seek(0);
                    while($segment = $segments_result->fetch_assoc()): 
                    ?>
                        <option value="<?php echo $segment['Maintenance_ID']; ?>" 
                            <?php echo ($filter_segment == $segment['Maintenance_ID']) ? 'selected' : ''; ?>>
                            #<?php echo $segment['Maintenance_ID']; ?> - <?php echo $segment['Task_type']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-4">
                <div class="filter-label">Resource Type</div>
                <select name="resource_type" class="filter-control" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <?php 
                    $resource_types_result->data_seek(0);
                    while($type = $resource_types_result->fetch_assoc()): 
                    ?>
                        <option value="<?php echo htmlspecialchars($type['Resource_Type']); ?>" 
                            <?php echo ($filter_resource_type == $type['Resource_Type']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['Resource_Type']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>
    </form>

    <!-- Active Filters Display - Red Clear All link -->
    <?php if(!empty($filter_package) || !empty($filter_segment) || !empty($filter_resource_type)): ?>
        <div class="mt-3 pt-2 border-top">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-light text-dark">Active:</span>
                <?php if(!empty($filter_package)): 
                    $pkg_name = "";
                    $packages_result->data_seek(0);
                    while($p = $packages_result->fetch_assoc()) {
                        if($p['Package_ID'] == $filter_package) {
                            $pkg_name = $p['Package_Name'];
                            break;
                        }
                    }
                ?>
                    <span class="badge badge-package">Package: <?php echo htmlspecialchars($pkg_name); ?></span>
                <?php endif; ?>

                <?php if(!empty($filter_segment)): ?>
                    <span class="badge badge-task">Segment #<?php echo $filter_segment; ?></span>
                <?php endif; ?>

                <?php if(!empty($filter_resource_type)): ?>
                    <span class="badge bg-info text-dark">Type: <?php echo htmlspecialchars($filter_resource_type); ?></span>
                <?php endif; ?>

                <a href="manage_project_resources.php?id=<?php echo $project_id; ?>" class="clear-filter">
                    <i class="fas fa-times-circle me-1"></i>Clear All
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>
            <!-- Resources List - Uniform Style -->
            <div class="content-section">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-boxes me-2" style="color: #805ad5;"></i>
                        Resources
                    </h4>
                    <div>
                        <span class="badge bg-secondary me-2"><?php echo $resource_count; ?> found</span>
                        <span class="badge bg-success">Total: ৳<?php echo number_format($total_resources_cost); ?></span>
                    </div>
                </div>

                <?php if($resource_count > 0): ?>
                    <?php foreach($resources_data as $resource): ?>
                        <div class="resource-row">
                            <div class="resource-info">
                                <span class="resource-id">#<?php echo $resource['Resource_ID']; ?></span>
                                
                                <div class="resource-details">
                                    <span class="resource-type">
                                        <i class="fas fa-cube me-1" style="color: #805ad5;"></i>
                                        <?php echo htmlspecialchars($resource['Resource_Type']); ?>
                                    </span>
                                    
                                    <div class="resource-meta">
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-hashtag"></i> <?php echo number_format($resource['Quantity']); ?>
                                        </span>
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-money-bill"></i> ৳<?php echo number_format($resource['Unit_Cost']); ?>/u
                                        </span>
                                        <span class="resource-cost">
                                            <i class="fas fa-calculator"></i> ৳<?php echo number_format($resource['Total_Cost']); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Package Badge with Link -->
                                <?php if(!empty($resource['Package_ID'])): ?>
                                    <a href="?id=<?php echo $project_id; ?>&package_id=<?php echo $resource['Package_ID']; ?>" 
                                       class="badge badge-package text-decoration-none">
                                        <i class="fas fa-cubes"></i> <?php echo htmlspecialchars($resource['Package_Name']); ?>
                                    </a>
                                <?php endif; ?>

                                <!-- Task Badge with Link -->
                                <?php if(!empty($resource['Maintenance_ID'])): ?>
                                    <a href="?id=<?php echo $project_id; ?>&segment_id=<?php echo $resource['Maintenance_ID']; ?>" 
                                       class="badge badge-task text-decoration-none">
                                        <i class="fas fa-tasks"></i> Task #<?php echo $resource['Maintenance_ID']; ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <div class="resource-actions">
                                <a href="view_resource.php?id=<?php echo $resource['Resource_ID']; ?>" class="btn btn-sm btn-view" title="View Resource">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit_resource.php?id=<?php echo $resource['Resource_ID']; ?>&maintenance_id=<?php echo $resource['Maintenance_ID']; ?>" 
                                   class="btn btn-sm btn-edit" title="Edit Resource">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="delete_resource.php?id=<?php echo $resource['Resource_ID']; ?>&return=project&project_id=<?php echo $project_id; ?>" 
                                   class="btn btn-sm btn-delete" 
                                   onclick="return confirm('Delete this resource?')"
                                   title="Delete Resource">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-boxes"></i>
                        <h5>No Resources Found</h5>
                        <p class="text-muted">No resources match your current filter criteria.</p>
                        <?php if(!empty($filter_package) || !empty($filter_segment) || !empty($filter_resource_type)): ?>
                            <a href="manage_project_resources.php?id=<?php echo $project_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-times me-1"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('filterForm').addEventListener('change', function() {
        this.submit();
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>