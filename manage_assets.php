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

// Get filter from URL
$filter_condition = isset($_GET['condition']) ? $_GET['condition'] : 'all';

// Handle asset deletion (admin only)
if($is_admin && isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    $delete_query = "DELETE FROM Assets WHERE Asset_ID = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $delete_id);
    
    if($stmt->execute()) {
        $success_msg = "Asset deleted successfully!";
    } else {
        $error_msg = "Error deleting asset: " . $conn->error;
    }
}

// mark as checked (admin only)
if($is_admin && isset($_GET['mark_checked'])) {
    $asset_id = $_GET['mark_checked'];
    $today = date('Y-m-d');
    
    $update_query = "UPDATE Assets SET Checking_Date = ? WHERE Asset_ID = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $today, $asset_id);
    
    if($stmt->execute()) {
        $success_msg = "Asset marked as checked! Next check scheduled.";
    } else {
        $error_msg = "Error checking asset: " . $conn->error;
    }
}

// Get all assets for citizen dropdown
if($is_citizen) {
    $all_assets_query = "SELECT Asset_ID, Asset_Name, Asset_Type, Location FROM Assets ORDER BY Asset_Name";
    $all_assets_result = $conn->query($all_assets_query);
}

// Get counts for active maintenance tasks
$active_tasks_count_query = "SELECT COUNT(DISTINCT Asset_ID) as count FROM Maintenance WHERE Status IN ('Active', 'Paused')";
$active_tasks_result = $conn->query($active_tasks_count_query);
$active_tasks_count = $active_tasks_result->fetch_assoc()['count'];

//assets setails
if($is_admin) {
    // Admin view - removed Expenses, Installation_Date, and Check_Status columns to prevent scrolling
    $assets_query = "SELECT 
                    a.Asset_ID,
                    a.Asset_Type,
                    a.Asset_Name,
                    a.Asset_Condition,
                    a.Location,
                    a.Checking_Date,
                    a.Maintenance_Interval,
                    (SELECT COUNT(*) FROM Maintenance WHERE Asset_ID = a.Asset_ID AND Status IN ('Active', 'Paused')) as active_maintenance_count
                    FROM Assets a";
    
    // Apply filter for admin
    if($filter_condition != 'all') {
        $assets_query .= " WHERE Asset_Condition = '$filter_condition'";
    }
    
    $assets_query .= " ORDER BY a.Asset_ID DESC";
} else {
    // Citizen view - removed Installation_Date column
    $assets_query = "SELECT 
                    a.Asset_ID,
                    a.Asset_Type,
                    a.Asset_Name,
                    a.Asset_Condition,
                    a.Location,
                    (SELECT COUNT(*) FROM Maintenance WHERE Asset_ID = a.Asset_ID AND Status IN ('Active', 'Paused')) as active_tasks
                    FROM Assets a";
    
    // Apply filter for citizen
    if($filter_condition != 'all') {
        $assets_query .= " WHERE a.Asset_Condition = '$filter_condition'";
    }
    
    $assets_query .= " ORDER BY 
                        CASE 
                            WHEN a.Asset_Condition IN ('Under Maintenance', 'Needs Maintenance') THEN 1
                            ELSE 2
                        END,
                    a.Asset_ID DESC";
}

$assets_result = $conn->query($assets_query);

$overdue_count = 0;
$due_soon_count = 0;
$not_checked_count = 0;
$total_assets = 0;
$needs_maintenance_count = 0;
$under_maintenance_count = 0;
$due_for_check_count = 0;

if($assets_result && $assets_result->num_rows > 0) {
    $total_assets = $assets_result->num_rows;
    
    if($is_admin) {
        while($asset = $assets_result->fetch_assoc()) {
            if($asset['Asset_Condition'] == 'Needs Maintenance') $needs_maintenance_count++;
            if($asset['Asset_Condition'] == 'Under Maintenance') $under_maintenance_count++;
            if($asset['Asset_Condition'] == 'New Construction') $needs_maintenance_count++;
        }
        $assets_result->data_seek(0); 
    } else {
        while($asset = $assets_result->fetch_assoc()) {
            if($asset['Asset_Condition'] == 'Needs Maintenance') $needs_maintenance_count++;
            if($asset['Asset_Condition'] == 'Under Maintenance') $under_maintenance_count++;
        }
        $assets_result->data_seek(0); 
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_admin ? 'Manage Assets' : 'Public Assets'; ?> - Smart DNCC</title>
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

        /* Unified main content area */
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

        /* Scrollbar styling */
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
            border: 1px solid #e2e8f0;
        }

        /* Filter bar - FULL WIDTH */
        .filter-bar {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 24px;
            margin-bottom: 20px;
            width: 100%;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .filter-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .filter-select {
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
            background: white;
            min-width: 250px;
            flex: 1;
        }

        .clear-filter {
            color: var(--danger);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 8px 16px;
            border: 1px solid #fc8181;
            border-radius: 6px;
            white-space: nowrap;
        }
        .clear-filter:hover {
            background: #fff5f5;
        }

        /* Status badges - ALL SAME WIDTH 120px */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 120px;
            width: 120px;
            text-align: center;
            display: inline-block;
            white-space: nowrap;
        }

        .badge-good { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-fair { background: #fffaf0; color: #744210; border: 1px solid #fbd38d; }
        .badge-poor { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        .badge-new-construction { background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; }
        .badge-needs-maintenance { background: #fffaf0; color: #744210; border: 1px solid #fbd38d; }
        .badge-under-maintenance { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; }
        
        /* UNIFORM BUTTONS */
        .action-btn {
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
            transition: all 0.2s;
            min-width: 70px;
            justify-content: center;
        }

        .btn-view { 
            background: #ebf8ff; 
            color: #3182ce; 
            border: 1px solid #bee3f8; 
        }
        .btn-view:hover { 
            background: #3182ce; 
            color: white; 
            border-color: #3182ce; 
        }

        .btn-report { 
            background: #fff5f5; 
            color: #e53e3e; 
            border: 1px solid #fc8181; 
        }
        .btn-report:hover { 
            background: #e53e3e; 
            color: white; 
            border-color: #e53e3e; 
        }

        .btn-delete {
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #fc8181;
        }
        .btn-delete:hover {
            background: #c53030;
            color: white;
            border-color: #c53030;
        }

        .btn-new-asset {
            background: var(--accent);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-new-asset:hover {
            background: #2c5282;
        }

        /* Red border for assets WITH ACTIVE MAINTENANCE */
        .active-maintenance-row {
            border-left: 4px solid var(--danger) !important;
            background-color: #fff5f5;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            min-width: 70px;
            text-align: center;
            display: inline-block;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        /* Table styling - now fits without horizontal scroll */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        .table th {
            background: #f8fafc;
            color: #4a5568;
            font-weight: 600;
            font-size: 0.7rem;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 8px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        /* Action header - aligned right */
        .table th:last-child {
            text-align: right;
            padding-right: 16px;
        }

        .table td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .table tr:hover {
            background: #fafbfc;
        }

        .table td:last-child {
            text-align: right;
            padding-right: 16px;
            white-space: nowrap;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            min-height: 90px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--accent);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #718096;
            text-transform: uppercase;
        }

        .alert {
            border: 1px solid;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            padding: 12px 20px;
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

        /* Modal styling from citizen_reports.php */
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

        .form-label {
            font-weight: 500;
            color: #4a5568;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }

        .form-control, .form-select {
            border-radius: 6px;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(49,130,206,0.1);
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
            min-width: 120px;
        }
        .btn-primary:hover {
            background: #2c5282;
        }

        .btn-outline-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            min-width: 100px;
        }
        .btn-outline-secondary:hover {
            background: #718096;
            color: white;
        }

        .image-upload-preview {
            max-width: 200px;
            max-height: 150px;
            margin-top: 10px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        h2, h4 {
            color: #1a202c;
            font-weight: 600;
        }
        h2 { font-size: 1.35rem; }
        h4 { font-size: 1rem; }

        .text-muted {
            color: #718096 !important;
        }

        @media (max-width: 768px) {
            .admin-main, .citizen-main {
                margin-left: 0;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-select {
                width: 100%;
            }
            
            .status-badge {
                min-width: 100px;
                width: 100px;
                font-size: 0.65rem;
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
        <!-- Header -->
        <div class="<?php echo $is_admin ? 'admin-header' : 'citizen-header'; ?>">
            <div class="header-title">
                <div>
                    <h2><?php echo $is_admin ? 'Assets Management' : 'Public Assets'; ?></h2>
                    <p class="text-muted"><?php echo $is_admin ? 'Manage city infrastructure assets' : 'View and report issues on public assets'; ?></p>
                </div>
                <?php if($is_admin): ?>
                    <a href="add_asset.php" class="btn-new-asset">
                        <i class="fas fa-plus"></i>New Asset
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-area">
            
            <?php if(isset($success_msg)): ?>
                <div class="alert alert-success"><?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <?php if($is_admin): ?>
            <div class="stats-row">
                <div class="stat-card"><div class="stat-number"><?php echo $total_assets; ?></div><div class="stat-label">Total Assets</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $needs_maintenance_count; ?></div><div class="stat-label">Needs Work</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $under_maintenance_count; ?></div><div class="stat-label">Under Maint</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $total_assets - ($needs_maintenance_count + $under_maintenance_count); ?></div><div class="stat-label">Good/Fair</div></div>
            </div>
            <?php else: ?>
            <div class="stats-row">
                <div class="stat-card"><div class="stat-number"><?php echo $active_tasks_count; ?></div><div class="stat-label">Active Tasks</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $needs_maintenance_count; ?></div><div class="stat-label">Needs Attention</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $under_maintenance_count; ?></div><div class="stat-label">Under Maint</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $total_assets; ?></div><div class="stat-label">Total Assets</div></div>
            </div>
            <?php endif; ?>

            <!-- Filter Bar - FULL WIDTH -->
            <div class="filter-bar">
                <span class="filter-label">Filter by Condition:</span>
                <select class="filter-select" onchange="window.location.href='?condition=' + this.value">
                    <option value="all" <?php echo $filter_condition == 'all' ? 'selected' : ''; ?>>All Conditions</option>
                    <option value="Good" <?php echo $filter_condition == 'Good' ? 'selected' : ''; ?>>Good</option>
                    <option value="Fair" <?php echo $filter_condition == 'Fair' ? 'selected' : ''; ?>>Fair</option>
                    <option value="Poor" <?php echo $filter_condition == 'Poor' ? 'selected' : ''; ?>>Poor</option>
                    <option value="Needs Maintenance" <?php echo $filter_condition == 'Needs Maintenance' ? 'selected' : ''; ?>>Needs Maintenance</option>
                    <option value="Under Maintenance" <?php echo $filter_condition == 'Under Maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                    <option value="New Construction" <?php echo $filter_condition == 'New Construction' ? 'selected' : ''; ?>>New Construction</option>
                </select>
                
                <?php if($filter_condition != 'all'): ?>
                    <a href="?" class="clear-filter">Clear</a>
                <?php endif; ?>
            </div>

            <!-- Assets Table -->
            <div class="content-section">
                <div class="d-flex justify-content-between mb-3">
                    <h4><?php echo $is_admin ? 'Assets List' : 'Public Assets'; ?></h4>
                    <span class="badge">Total: <?php echo $total_assets; ?></span>
                </div>

                <?php if($total_assets > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Name</th>
                                    <th>Condition</th>
                                    <th>Location</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($asset = $assets_result->fetch_assoc()): 
                                    // Check if asset has active maintenance (Active or Paused)
                                    $has_active_maintenance = false;
                                    if($is_admin) {
                                        $has_active_maintenance = ($asset['active_maintenance_count'] > 0);
                                    } else {
                                        $has_active_maintenance = ($asset['active_tasks'] > 0);
                                    }
                                    $row_class = $has_active_maintenance ? 'active-maintenance-row' : '';
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>#<?php echo $asset['Asset_ID']; ?></td>
                                    <td><?php echo $asset['Asset_Type']; ?></td>
                                    <td><?php echo $asset['Asset_Name']; ?></td>
                                    <td>
                                        <?php 
                                        $class = '';
                                        switch($asset['Asset_Condition']) {
                                            case 'Good': $class = 'badge-good'; break;
                                            case 'Fair': $class = 'badge-fair'; break;
                                            case 'Poor': $class = 'badge-poor'; break;
                                            case 'New Construction': $class = 'badge-new-construction'; break;
                                            case 'Needs Maintenance': $class = 'badge-needs-maintenance'; break;
                                            case 'Under Maintenance': $class = 'badge-under-maintenance'; break;
                                            default: $class = 'badge-fair';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $class; ?>">
                                            <?php echo $asset['Asset_Condition']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $asset['Location']; ?></td>
                                    <td>
                                        <a href="view_asset.php?id=<?php echo $asset['Asset_ID']; ?>" class="action-btn btn-view">View</a>
                                        <?php if($is_admin): ?>
                                            <a href="?delete_id=<?php echo $asset['Asset_ID']; ?>" class="action-btn btn-delete" onclick="return confirm('Are you sure you want to delete this asset? This action cannot be undone.');">
                                                 Delete
                                            </a>
                                        <?php else: ?>
                                            <button class="action-btn btn-report" onclick="openReportModal(<?php echo $asset['Asset_ID']; ?>, '<?php echo htmlspecialchars($asset['Asset_Name']); ?>')">Report</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-building fa-3x text-muted mb-3"></i>
                        <h5>No Assets Found</h5>
                        <p class="text-muted"><?php echo $is_admin ? 'No assets match your filter criteria.' : 'No public assets available.'; ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if($is_citizen): ?>
    <!-- Report Modal - Updated with styling from citizen_reports.php -->
    <div class="modal fade" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Report Issue for <span id="modalAssetName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="citizen_reports.php" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="asset_id" id="modalAssetId">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Report Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="report_type" required>
                                    <option value="">Select Type</option>
                                    <option value="Infrastructure Issue">Infrastructure Issue</option>
                                    <option value="Maintenance Request">Maintenance Request</option>
                                    <option value="Safety Concern">Safety Concern</option>
                                    <option value="Cleanliness Issue">Cleanliness Issue</option>
                                    <option value="Damage Report">Damage Report</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Related Asset <span class="text-danger">*</span></label>
                                <select class="form-select" name="asset_id_fixed" id="assetSelect" disabled>
                                    <option value="" id="selectedAssetOption">Loading...</option>
                                </select>
                                <!-- Hidden input to submit the actual asset_id -->
                                <input type="hidden" name="asset_id" id="hiddenAssetId">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="description" rows="5" 
                                          placeholder="Please provide detailed description of the issue..." 
                                          required></textarea>
                            </div>
                            
                            <!-- Image Upload Field -->
                            <div class="col-12">
                                <label class="form-label">Upload Image (Optional)</label>
                                <input type="file" class="form-control" name="report_image" id="report_image" 
                                       accept="image/jpeg,image/png,image/jpg,image/gif,image/webp">
                                <small class="text-muted">Max: 5MB. Allowed: JPG, PNG, GIF, WEBP</small>
                                
                                <!-- Image Preview -->
                                <div id="imagePreview" class="mt-2" style="display: none;">
                                    <img src="" alt="Preview" class="image-upload-preview">
                                    <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="clearImage()">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_report" class="btn btn-primary">Submit Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openReportModal(id, name) {
        document.getElementById('modalAssetId').value = id;
        document.getElementById('hiddenAssetId').value = id;
        document.getElementById('modalAssetName').textContent = name;
        
        // Update the disabled select field to show the selected asset
        const assetSelect = document.getElementById('assetSelect');
        const selectedOption = document.getElementById('selectedAssetOption');
        selectedOption.value = id;
        selectedOption.textContent = name + ' (Selected Asset)';
        
        // Clear any previous image preview
        clearImage();
        
        new bootstrap.Modal(document.getElementById('reportModal')).show();
    }

    // Image preview functionality
    document.getElementById('report_image').addEventListener('change', function(e) {
        const preview = document.getElementById('imagePreview');
        const previewImg = preview.querySelector('img');
        const file = e.target.files[0];
        
        if(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                preview.style.display = 'block';
            }
            reader.readAsDataURL(file);
        } else {
            clearImage();
        }
    });

    function clearImage() {
        document.getElementById('report_image').value = '';
        document.getElementById('imagePreview').style.display = 'none';
        document.getElementById('imagePreview').querySelector('img').src = '';
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 500);
        });
    }, 5000);
    </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>