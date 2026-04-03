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
$project_budget = $project['Budget'] ?? 0;

// ============================================
// PROJECT LEVEL WORKERS (from worker_assignments)
// ============================================

$project_workers_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Designation,
                                 wa.Role, wa.Assignment_ID, wa.Status as Participation_Status, wa.Assigned_Date
                          FROM worker_assignments wa
                          JOIN Workers w ON wa.Worker_ID = w.Worker_ID
                          WHERE wa.Project_ID = ? AND wa.Status IN ('Active', 'Paused')
                          ORDER BY 
                              CASE wa.Role
                                  WHEN 'Project Director' THEN 1
                                  ELSE 2
                              END,
                              w.Worker_Name";
$stmt = $conn->prepare($project_workers_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project_workers_result = $stmt->get_result();
$project_workers = [];
$total_project_workers_cost = 0;

while($worker = $project_workers_result->fetch_assoc()) {
    $project_workers[] = $worker;
    $total_project_workers_cost += $worker['Worker_Salary'];
}

// ============================================
// PACKAGE LEVEL (with worker costs from worker_assignments)
// ============================================

$packages_query = "SELECT p.*,
                   (SELECT COALESCE(SUM(w.Worker_Salary), 0) 
                    FROM worker_assignments wa 
                    JOIN Workers w ON wa.Worker_ID = w.Worker_ID 
                    WHERE wa.Package_ID = p.Package_ID AND wa.Status IN ('Active', 'Paused')) as package_workers_cost,
                   (SELECT COUNT(*) 
                    FROM worker_assignments 
                    WHERE Package_ID = p.Package_ID AND Status IN ('Active', 'Paused')) as package_workers_count,
                   (SELECT COALESCE(SUM(Cost), 0) 
                    FROM Maintenance 
                    WHERE Package_ID = p.Package_ID) as segments_planned,
                   (SELECT COUNT(*) 
                    FROM Maintenance 
                    WHERE Package_ID = p.Package_ID) as segments_count
                   FROM Packages p
                   WHERE p.Project_ID = ?
                   ORDER BY p.Package_Name";

$stmt = $conn->prepare($packages_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$packages_result = $stmt->get_result();

// ============================================
// SEGMENT LEVEL (with worker costs from worker_assignments)
// ============================================

$segments_query = "SELECT m.*, a.Asset_Name, a.Asset_Type, p.Package_ID, p.Package_Name,
                          (SELECT COALESCE(SUM(w.Worker_Salary), 0) 
                           FROM worker_assignments wa 
                           JOIN Workers w ON wa.Worker_ID = w.Worker_ID 
                           WHERE wa.Maintenance_ID = m.Maintenance_ID AND wa.Status IN ('Active', 'Paused', 'Completed')) as worker_actual,
                          (SELECT COUNT(*) 
                           FROM worker_assignments 
                           WHERE Maintenance_ID = m.Maintenance_ID) as worker_count,
                          (SELECT COALESCE(SUM(Quantity * Unit_Cost), 0) 
                           FROM Resources 
                           WHERE Maintenance_ID = m.Maintenance_ID) as resource_actual,
                          (SELECT COUNT(*) 
                           FROM Resources 
                           WHERE Maintenance_ID = m.Maintenance_ID) as resource_count
                   FROM Maintenance m
                   LEFT JOIN Assets a ON m.Asset_ID = a.Asset_ID
                   LEFT JOIN Packages p ON m.Package_ID = p.Package_ID
                   WHERE m.Project_ID = ?
                   ORDER BY p.Package_Name, m.Start_Date DESC";

$stmt = $conn->prepare($segments_query);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$segments_result = $stmt->get_result();

// Organize segments by package
$segments_by_package = [];
while($segment = $segments_result->fetch_assoc()) {
    $pkg_id = $segment['Package_ID'] ?? 0;
    if(!isset($segments_by_package[$pkg_id])) {
        $segments_by_package[$pkg_id] = [];
    }
    
    $segment['worker_actual'] = $segment['worker_actual'] ?? 0;
    $segment['resource_actual'] = $segment['resource_actual'] ?? 0;
    $segment['actual_total'] = $segment['worker_actual'] + $segment['resource_actual'];
    
    $segments_by_package[$pkg_id][] = $segment;
}

// ============================================
// CALCULATE TOTALS
// ============================================

$packages_data = [];
$total_package_budgets = 0;
$total_package_workers_cost = 0;
$total_segments_planned = 0;
$total_segments_actual = 0;
$total_actual_cost = $total_project_workers_cost;
$alerts = [];

while($package = $packages_result->fetch_assoc()) {
    $pkg_id = $package['Package_ID'];
    $package_segments = $segments_by_package[$pkg_id] ?? [];
    
    $package['package_workers_cost'] = $package['package_workers_cost'] ?? 0;
    $package['segments_planned'] = $package['segments_planned'] ?? 0;
    
    // Calculate package actual from its segments
    $package_segments_actual = 0;
    foreach($package_segments as $seg) {
        $package_segments_actual += $seg['actual_total'];
    }
    
    $package['segments_actual'] = $package_segments_actual;
    $package['package_actual'] = $package['package_workers_cost'] + $package_segments_actual;
    $package['package_planned'] = $package['segments_planned'] + $package['package_workers_cost'];
    $package['segments'] = $package_segments;
    
    // Check if package planned exceeds package budget
    if($package['Budget'] > 0 && $package['package_planned'] > $package['Budget']) {
        $over_by = $package['package_planned'] - $package['Budget'];
        $alerts[] = [
            'type' => 'danger',
            'package' => $package['Package_Name'],
            'message' => "Package '{$package['Package_Name']}' planned spending (৳" . number_format($package['package_planned']) . 
                        ") exceeds budget (৳" . number_format($package['Budget']) . ") by ৳" . number_format($over_by)
        ];
    }
    
    // Check if segment actual exceeds segment planned
    foreach($package_segments as $seg) {
        if($seg['Cost'] > 0 && $seg['actual_total'] > $seg['Cost']) {
            $over_by = $seg['actual_total'] - $seg['Cost'];
            $alerts[] = [
                'type' => 'warning',
                'package' => $package['Package_Name'],
                'segment' => $seg['Maintenance_ID'],
                'task' => $seg['Task_type'],
                'message' => "Segment #{$seg['Maintenance_ID']} ({$seg['Task_type']}) in package '{$package['Package_Name']}' actual (৳" . 
                            number_format($seg['actual_total']) . ") exceeds planned (৳" . number_format($seg['Cost']) . 
                            ") by ৳" . number_format($over_by)
            ];
        }
    }
    
    $packages_data[] = $package;
    
    $total_package_budgets += $package['Budget'] ?? 0;
    $total_package_workers_cost += $package['package_workers_cost'];
    $total_segments_planned += $package['segments_planned'];
    $total_segments_actual += $package_segments_actual;
    $total_actual_cost += $package['package_actual'];
}

$total_planned = $total_project_workers_cost + $total_package_workers_cost + $total_segments_planned;
$project_variance = $project_budget - $total_actual_cost;
$is_over_budget = $project_variance < 0;
$planned_exceeds_budget = $total_planned > $project_budget;

// Helper function for status badges
function getParticipationBadgeClass($status) {
    switch($status) {
        case 'Active': return 'badge-active';
        case 'Paused': return 'badge-paused';
        case 'Completed': return 'badge-completed';
        case 'Fired': return 'badge-cancelled';
        default: return 'badge-secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Budget - <?php echo htmlspecialchars($project['Project_Name']); ?> - Smart DNCC</title>
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
            --purple: #805ad5;
            --info: #00a3c4;
            --not-started: #94a3b8;
            --paused: #fbbf24;
            --cancelled: #6b7280;
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
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            min-width: 100px;
            text-align: center;
            display: inline-block;
        }

        /* Status Badges */
        .status-badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
            display: inline-block;
        }
        .badge-active {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .badge-paused {
            background: #fed7aa;
            color: #92400e;
            border: 1px solid #fdba74;
        }
        .badge-completed {
            background: #e9d8fd;
            color: #553c9a;
            border: 1px solid #d6bcfa;
        }
        .badge-cancelled {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .badge-secondary {
            background: #e2e8f0;
            color: #475569;
            border: 1px solid #cbd5e0;
        }

        /* Alert Cards */
        .alert-card {
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 10px;
            font-size: 0.85rem;
            border-left: 4px solid;
        }
        .alert-card.danger {
            background: #fff5f5;
            border-left-color: var(--danger);
            color: #c53030;
        }
        .alert-card.warning {
            background: #feebc8;
            border-left-color: var(--warning);
            color: #744210;
        }

        /* Budget Cards - UNIFORM SIZE */
        .budget-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .budget-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            border-left: 4px solid;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .budget-card.project { border-left-color: var(--accent); }
        .budget-card.planned { border-left-color: var(--purple); }
        .budget-card.actual { border-left-color: var(--success); }
        .budget-card.variance { border-left-color: <?php echo $is_over_budget ? 'var(--danger)' : 'var(--warning)'; ?>; }

        .budget-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #718096;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .budget-amount {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.2;
            margin: 4px 0;
        }

        /* Zero Value Style */
        .zero-value {
            color: #a0aec0;
            font-style: italic;
        }
        .zero-badge {
            background: #edf2f7;
            color: #718096;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            display: inline-block;
            min-width: 80px;
            text-align: center;
        }

        /* Worker Row - UNIFORM */
        .worker-row {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 50px;
        }

        .worker-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            flex-wrap: wrap;
        }

        .worker-id {
            background: #e2e8f0;
            color: #2d3748;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            min-width: 45px;
            text-align: center;
        }

        /* Package Row - UNIFORM */
        .package-row {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .package-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            flex-wrap: wrap;
            gap: 10px;
        }

        .package-title {
            font-size: 1rem;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 200px;
        }

        .package-title i {
            color: var(--purple);
            margin-right: 8px;
            min-width: 16px;
        }

        .expand-hint {
            display: flex;
            align-items: center;
            font-size: 0.7rem;
            color: #718096;
            font-weight: normal;
            min-width: 90px;
        }

        .package-header-right {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        /* Budget Summary in Package Header - UNIFORM */
        .budget-summary {
            display: flex;
            gap: 10px;
            background: #f1f5f9;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            min-width: 200px;
            justify-content: center;
        }

        .budget-item {
            display: flex;
            align-items: center;
            gap: 4px;
            min-width: 70px;
        }

        .budget-item .label {
            color: #64748b;
            font-weight: 500;
            min-width: 45px;
        }

        .budget-item .value {
            font-weight: 700;
            min-width: 60px;
            text-align: right;
        }

        .value.planned {
            color: var(--purple);
        }

        .value.actual {
            color: var(--success);
        }

        .value.actual.over-budget {
            color: var(--danger);
        }

        .budget-divider {
            width: 1px;
            background: #cbd5e1;
        }

        .package-details {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #e2e8f0;
            display: none;
        }
        .package-details.show { display: block; }

        /* Package Workers Section */
        .package-workers-section {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
        }

        .section-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Segment Row - UNIFORM */
        .segment-row {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            margin-left: 15px;
        }

        .segment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            flex-wrap: wrap;
            gap: 10px;
        }

        .segment-title {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .segment-id {
            background: #e2e8f0;
            color: #2d3748;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.65rem;
            min-width: 45px;
            text-align: center;
        }

        .segment-costs {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .segment-costs span {
            min-width: 80px;
            text-align: right;
        }

        .segment-details {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #e2e8f0;
            display: none;
        }
        .segment-details.show { display: block; }

        .sub-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0 4px 20px;
            font-size: 0.8rem;
        }

        .sub-item.zero {
            color: #a0aec0;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            min-width: 70px;
            text-align: center;
            display: inline-block;
        }
        .badge-success { background: #f0fff4; color: #276749; }
        .badge-warning { background: #feebc8; color: #744210; }
        .badge-danger { background: #fff5f5; color: #c53030; }
        .badge-zero { background: #edf2f7; color: #718096; }

        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 8px 16px;
            font-size: 0.85rem;
            transition: all 0.2s;
            min-width: 100px;
            text-align: center;
        }

        .btn-outline-primary {
            background: white;
            border: 1px solid var(--accent);
            color: var(--accent);
        }
        .btn-outline-primary:hover {
            background: var(--accent);
            color: white;
        }

        .btn-outline-secondary {
            background: white;
            border: 1px solid #cbd5e0;
            color: #4a5568;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            border-color: #718096;
            color: white;
        }

        .btn-outline-purple {
            background: white;
            border: 1px solid var(--purple);
            color: var(--purple);
            font-weight: 500;
            padding: 4px 12px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
            font-size: 0.75rem;
            min-width: 80px;
            justify-content: center;
        }
        .btn-outline-purple:hover {
            background: var(--purple);
            color: white;
        }

        .btn-outline-success {
            background: white;
            border: 1px solid var(--success);
            color: var(--success);
            min-width: 70px;
        }
        .btn-outline-success:hover {
            background: var(--success);
            color: white;
        }

        .btn-outline-info {
            background: white;
            border: 1px solid var(--info);
            color: var(--info);
            min-width: 70px;
        }
        .btn-outline-info:hover {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 4px 12px;
            font-size: 0.75rem;
            min-width: 80px;
        }

        .view-btn {
            padding: 4px 10px;
            font-size: 0.7rem;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-width: 70px;
            justify-content: center;
        }

        /* Action buttons for segments */
        .segment-actions {
            display: flex;
            gap: 6px;
            margin-left: 10px;
        }

        .btn-task-view {
            background: #ebf8ff;
            color: #3182ce;
            border: 1px solid #bee3f8;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.7rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-width: 60px;
            justify-content: center;
        }
        .btn-task-view:hover {
            background: #3182ce;
            color: white;
        }

        h2, h5 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.4rem; }
        h5 { font-size: 1rem; }

        .text-muted {
            color: #718096 !important;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .budget-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .project-header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .package-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .package-header-right {
                width: 100%;
                justify-content: space-between;
            }
            
            .budget-summary {
                width: 100%;
                justify-content: space-around;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Project Budget</h2>
                <p class="text-muted mb-0">Complete budget breakdown at all levels</p>
            </div>
            <div>
                <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Project
                </a>
            </div>
        </div>

        <div class="content-area">
            <!-- Project Banner -->
            <div class="project-header">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="project-badge">
                            <i class="fas fa-folder-open me-1"></i><?php echo $project_type; ?>
                        </span>
                        <span class="project-badge">
                            <i class="fas fa-hashtag me-1"></i>ID: <?php echo $project_id; ?>
                        </span>
                    </div>
                    <h1><?php echo htmlspecialchars($project['Project_Name']); ?></h1>
                </div>
                <div>
                    <span class="badge bg-white text-dark fs-6 p-3">Budget: ৳<?php echo number_format($project_budget); ?></span>
                </div>
            </div>

            <!-- Budget Summary Cards -->
            <div class="budget-cards">
                <div class="budget-card project">
                    <div class="budget-label">Project Budget</div>
                    <div class="budget-amount <?php echo $project_budget == 0 ? 'zero-value' : ''; ?>">
                        <?php echo $project_budget == 0 ? '৳0' : '৳' . number_format($project_budget); ?>
                    </div>
                    <small class="text-muted">Total allocated budget</small>
                </div>

                <div class="budget-card planned">
                    <div class="budget-label">Total Planned</div>
                    <div class="budget-amount <?php 
                        echo $total_planned == 0 ? 'zero-value' : '';
                        if($planned_exceeds_budget) echo ' text-danger';
                    ?>">
                        <?php echo $total_planned == 0 ? '৳0' : '৳' . number_format($total_planned); ?>
                    </div>
                    <small class="text-muted">Project Workers: ৳<?php echo number_format($total_project_workers_cost); ?></small>
                </div>

                <div class="budget-card actual">
                    <div class="budget-label">Total Actual</div>
                    <div class="budget-amount <?php echo $total_actual_cost == 0 ? 'zero-value' : ''; ?>">
                        <?php echo $total_actual_cost == 0 ? '৳0' : '৳' . number_format($total_actual_cost); ?>
                    </div>
                    <small class="text-muted">
                        <?php 
                        $percent = $project_budget > 0 ? round(($total_actual_cost / $project_budget) * 100, 1) : 0;
                        echo $percent . '% of budget utilized';
                        ?>
                    </small>
                </div>

                <div class="budget-card variance">
                    <div class="budget-label">Variance</div>
                    <div class="budget-amount <?php 
                        if($project_variance == 0) echo 'zero-value';
                        elseif($is_over_budget) echo 'text-danger';
                        else echo 'text-success';
                    ?>">
                        <?php 
                        if($project_variance == 0) echo '৳0';
                        else echo ($is_over_budget ? '- ' : '+ ') . '৳' . number_format(abs($project_variance));
                        ?>
                    </div>
                    <small class="text-muted"><?php echo $is_over_budget ? 'Over budget' : 'Under budget'; ?></small>
                </div>
            </div>

            <!-- Budget Alerts -->
            <?php if(count($alerts) > 0): ?>
                <div class="mb-4">
                    <h5 class="mb-2">Budget Alerts</h5>
                    <?php foreach($alerts as $alert): ?>
                        <div class="alert-card <?php echo $alert['type']; ?>">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo $alert['message']; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- LEVEL 1: PROJECT WORKERS -->
            <div class="content-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="fas fa-folder-open me-2" style="color: #3182ce;"></i>
                        Project Level Workers
                    </h5>
                    <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn-outline-purple btn-sm">
                        <i class="fas fa-eye me-1"></i>View Project
                    </a>
                </div>
                
                <?php if(count($project_workers) > 0): ?>
                    <?php foreach($project_workers as $worker): ?>
                        <div class="worker-row">
                            <div class="worker-info">
                                <span class="worker-id">#<?php echo $worker['Worker_ID']; ?></span>
                                <span><i class="fas fa-user-tie me-1" style="color: #3182ce;"></i> <?php echo htmlspecialchars($worker['Worker_Name']); ?></span>
                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($worker['Role']); ?></span>
                                <?php if($worker['Designation']): ?>
                                    <span class="badge bg-light text-dark"><?php echo htmlspecialchars($worker['Designation']); ?></span>
                                <?php endif; ?>
                                <?php if(isset($worker['Participation_Status'])): ?>
                                    <span class="status-badge <?php echo getParticipationBadgeClass($worker['Participation_Status']); ?>">
                                        <?php echo $worker['Participation_Status']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="fw-bold text-success" style="min-width: 80px; text-align: right;">৳<?php echo number_format($worker['Worker_Salary']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="text-end mt-2">
                        <span class="fw-bold" style="min-width: 150px; display: inline-block;">Total: ৳<?php echo number_format($total_project_workers_cost); ?></span>
                    </div>
                <?php else: ?>
                    <div class="text-muted py-2 fst-italic">
                        <i class="fas fa-info-circle me-2"></i>No workers assigned directly to project
                    </div>
                <?php endif; ?>
            </div>

            <!-- LEVEL 2: PACKAGES -->
            <div class="content-section">
                <h5 class="mb-3">
                    <i class="fas fa-cubes me-2" style="color: #805ad5;"></i>
                    Packages
                </h5>

                <?php if(count($packages_data) > 0): ?>
                    <?php foreach($packages_data as $pkg_index => $package): ?>
                        <div class="package-row">
                            <div class="package-header" onclick="togglePackage(<?php echo $pkg_index; ?>)">
                                <div class="package-title">
                                    <i class="fas fa-cube"></i>
                                    <?php echo htmlspecialchars($package['Package_Name']); ?>
                                    <span class="expand-hint">
                                        <i class="fas fa-chevron-down me-1"></i>
                                        <small>Click to expand</small>
                                    </span>
                                </div>
                                <div class="package-header-right">
                                    <!-- Budget Summary (Planned vs Actual) -->
                                    <div class="budget-summary">
                                        <div class="budget-item">
                                            <span class="label">Planned:</span>
                                            <span class="value planned">৳<?php echo number_format($package['package_planned']); ?></span>
                                        </div>
                                        <span class="budget-divider"></span>
                                        <div class="budget-item">
                                            <span class="label">Actual:</span>
                                            <span class="value actual <?php 
                                                if($package['package_actual'] > $package['package_planned']) echo 'over-budget';
                                            ?>">৳<?php echo number_format($package['package_actual']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <!-- View Package Button -->
                                    <a href="view_package.php?id=<?php echo $package['Package_ID']; ?>" 
                                       class="btn-outline-purple btn-sm" 
                                       onclick="event.stopPropagation();">
                                        <i class="fas fa-external-link-alt"></i> View
                                    </a>
                                </div>
                            </div>

                            <div id="package-<?php echo $pkg_index; ?>" class="package-details">
                                
                                <!-- Package Level Workers -->
                                <div class="package-workers-section">
                                    <div class="section-title">
                                        <i class="fas fa-users me-2" style="color: #e53e3e;"></i>
                                        Package Workers
                                    </div>
                                    
                                    <?php if($package['package_workers_cost'] > 0): ?>
                                        <?php
                                        // Fetch actual package workers from worker_assignments
                                        $pkg_workers_query = "SELECT w.Worker_ID, w.Worker_Name, w.Worker_Salary, w.Designation,
                                                                     wa.Role, wa.Status as Participation_Status, wa.Assigned_Date
                                                              FROM worker_assignments wa
                                                              JOIN Workers w ON wa.Worker_ID = w.Worker_ID
                                                              WHERE wa.Package_ID = ? AND wa.Status IN ('Active', 'Paused')
                                                              ORDER BY w.Worker_Name";
                                        $stmt = $conn->prepare($pkg_workers_query);
                                        $stmt->bind_param("i", $package['Package_ID']);
                                        $stmt->execute();
                                        $pkg_workers = $stmt->get_result();
                                        while($pw = $pkg_workers->fetch_assoc()):
                                        ?>
                                        <div class="worker-row" style="margin-left: 0;">
                                            <div class="worker-info">
                                                <span class="worker-id">#<?php echo $pw['Worker_ID']; ?></span>
                                                <span><i class="fas fa-user-tie me-1"></i> <?php echo htmlspecialchars($pw['Worker_Name']); ?></span>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($pw['Role']); ?></span>
                                                <?php if(isset($pw['Participation_Status'])): ?>
                                                    <span class="status-badge <?php echo getParticipationBadgeClass($pw['Participation_Status']); ?>">
                                                        <?php echo $pw['Participation_Status']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="text-success" style="min-width: 80px; text-align: right;">৳<?php echo number_format($pw['Worker_Salary']); ?></span>
                                        </div>
                                        <?php endwhile; ?>
                                        <div class="text-end mt-1">
                                            <span class="fw-bold" style="min-width: 150px; display: inline-block;">Total: ৳<?php echo number_format($package['package_workers_cost']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted py-1 small">No package workers assigned</div>
                                    <?php endif; ?>
                                </div>

                                <!-- Segments -->
                                <div class="mt-2">
                                    <div class="section-title">
                                        <i class="fas fa-tasks me-2" style="color: #38a169;"></i>
                                        Segments
                                    </div>
                                    
                                    <?php if(count($package['segments']) > 0): ?>
                                        <?php foreach($package['segments'] as $seg_index => $segment): ?>
                                            <div class="segment-row">
                                                <div class="segment-header" onclick="toggleSegment('<?php echo $pkg_index; ?>-<?php echo $seg_index; ?>')">
                                                    <div class="segment-title">
                                                        <span class="segment-id">#<?php echo $segment['Maintenance_ID']; ?></span>
                                                        <span><i class="fas fa-tasks me-1" style="color: #38a169;"></i> <?php echo htmlspecialchars($segment['Task_type']); ?></span>
                                                        <?php if($segment['Asset_Name']): ?>
                                                            <small class="text-muted">(<?php echo htmlspecialchars($segment['Asset_Name']); ?>)</small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="segment-costs">
                                                        <span class="text-muted" style="min-width: 80px;">Planned: ৳<?php echo number_format($segment['Cost']); ?></span>
                                                        <span class="<?php 
                                                            if($segment['actual_total'] == 0) echo 'text-muted';
                                                            elseif($segment['actual_total'] > $segment['Cost']) echo 'text-danger fw-bold';
                                                            elseif($segment['actual_total'] < $segment['Cost']) echo 'text-success fw-bold';
                                                        ?>" style="min-width: 90px;">
                                                            Actual: <?php echo $segment['actual_total'] == 0 ? '৳0' : '৳' . number_format($segment['actual_total']); ?>
                                                        </span>
                                                        <div class="segment-actions">
                                                            <a href="view_maintenance.php?id=<?php echo $segment['Maintenance_ID']; ?>" class="btn-task-view">
                                                                <i class="fas fa-eye"></i> View
                                                            </a>
                                                        </div>
                                                        <i class="fas fa-chevron-down ms-1" style="font-size: 0.7rem;"></i>
                                                    </div>
                                                </div>
                                                
                                                <!-- Segment Details -->
                                                <div id="segment-<?php echo $pkg_index; ?>-<?php echo $seg_index; ?>" class="segment-details">
                                                    <!-- Segment Workers -->
                                                    <div class="sub-item <?php echo ($segment['worker_actual'] ?? 0) == 0 ? 'zero' : ''; ?>">
                                                        <span><i class="fas fa-user me-2"></i> Workers (<?php echo $segment['worker_count'] ?? 0; ?>)</span>
                                                        <span style="min-width: 80px; text-align: right;"><?php echo ($segment['worker_actual'] ?? 0) == 0 ? '৳0' : '৳' . number_format($segment['worker_actual']); ?></span>
                                                    </div>
                                                    
                                                    <!-- Segment Resources -->
                                                    <div class="sub-item <?php echo ($segment['resource_actual'] ?? 0) == 0 ? 'zero' : ''; ?>">
                                                        <span><i class="fas fa-boxes me-2"></i> Resources (<?php echo $segment['resource_count'] ?? 0; ?>)</span>
                                                        <span style="min-width: 80px; text-align: right;"><?php echo ($segment['resource_actual'] ?? 0) == 0 ? '৳0' : '৳' . number_format($segment['resource_actual']); ?></span>
                                                    </div>
                                                    
                                                    <!-- Segment Total -->
                                                    <div class="sub-item fw-bold border-top mt-1 pt-1">
                                                        <span>Segment Total</span>
                                                        <span class="<?php 
                                                            if($segment['actual_total'] > $segment['Cost']) echo 'text-danger';
                                                            elseif($segment['actual_total'] < $segment['Cost']) echo 'text-success';
                                                        ?>" style="min-width: 80px; text-align: right;">
                                                            <?php echo $segment['actual_total'] == 0 ? '৳0' : '৳' . number_format($segment['actual_total']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-muted py-2 fst-italic">No segments in this package</div>
                                    <?php endif; ?>
                                </div>

                                <!-- Package Total -->
                                <div class="mt-3 pt-2 border-top d-flex justify-content-between fw-bold">
                                    <span>Package Total</span>
                                    <span class="<?php 
                                        if($package['package_actual'] == 0) echo 'text-muted';
                                        elseif($package['package_actual'] > $package['package_planned']) echo 'text-danger';
                                        elseif($package['package_actual'] < $package['package_planned']) echo 'text-success';
                                    ?>" style="min-width: 100px; text-align: right;">
                                        <?php echo $package['package_actual'] == 0 ? '৳0' : '৳' . number_format($package['package_actual']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted py-3">No packages in this project</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function togglePackage(index) {
        const element = document.getElementById('package-' + index);
        element.classList.toggle('show');
        const chevron = element.closest('.package-row').querySelector('.package-header .fa-chevron-down');
        if(chevron) {
            chevron.style.transform = element.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0)';
        }
    }
    
    function toggleSegment(id) {
        const element = document.getElementById('segment-' + id);
        element.classList.toggle('show');
        const chevron = element.closest('.segment-row').querySelector('.segment-header .fa-chevron-down');
        if(chevron) {
            chevron.style.transform = element.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0)';
        }
    }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>