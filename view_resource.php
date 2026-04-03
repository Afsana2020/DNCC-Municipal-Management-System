<?php
session_start();
include("connect.php");

if(isset($_GET['success'])) {
    if($_GET['success'] == 'resource_updated') {
        $success_msg = "Resource updated successfully!";
    }
}

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_resources.php");
    exit();
}

$resource_id = $_GET['id'];

//  resource information with total cost
$resource_query = "SELECT r.*, m.Maintenance_ID, m.Task_type, m.Status as Maintenance_Status,
                          a.Asset_ID, a.Asset_Name, a.Asset_Type, a.Location,
                          (r.Quantity * r.Unit_Cost) as Total_Cost
                   FROM Resources r 
                   LEFT JOIN Maintenance m ON r.Maintenance_ID = m.Maintenance_ID 
                   LEFT JOIN Assets a ON m.Asset_ID = a.Asset_ID 
                   WHERE r.Resource_ID = ?";
$stmt = $conn->prepare($resource_query);
$stmt->bind_param("i", $resource_id);
$stmt->execute();
$resource_result = $stmt->get_result();

if($resource_result->num_rows === 0) {
    header("Location: manage_resources.php");
    exit();
}

$resource = $resource_result->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Details - Smart DNCC</title>
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
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* UNIFORM BADGES */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 90px;
            text-align: center;
            display: inline-block;
        }

        .badge-good { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
        .badge-fair { background: #fffaf0; color: #744210; border: 1px solid #faf089; }
        .badge-poor { background: #fff5f5; color: #c53030; border: 1px solid #fc8181; }
        .badge-new-construction { background: #ebf8ff; color: #2c5aa0; border: 1px solid #90cdf4; }
        .badge-needs-maintenance { background: #fffaf0; color: #744210; border: 1px solid #faf089; }
        .badge-under-maintenance { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; }
        .badge-allocated { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; }
        .badge-available { background: #edf2f7; color: #4a5568; border: 1px solid #cbd5e0; }

        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            border-left: 4px solid var(--accent);
            min-height: 80px;
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

        .cost-card {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 8px;
            padding: 16px;
            min-height: 80px;
            border-left: 4px solid var(--success);
        }

        .cost-highlight {
            font-weight: 700;
            color: #276749;
            font-size: 1.2rem;
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
            min-width: 120px;
            justify-content: center;
        }

        .btn-edit {
            background: #fffaf0;
            color: #d69e2e;
            border-color: #faf089;
        }
        .btn-edit:hover {
            background: #d69e2e;
            color: white;
            border-color: #d69e2e;
        }

        .btn-view {
            background: #ebf8ff;
            color: #3182ce;
            border-color: #bee3f8;
        }
        .btn-view:hover {
            background: #3182ce;
            color: white;
            border-color: #3182ce;
        }

        .btn-maintenance {
            background: #f0fff4;
            color: #38a169;
            border-color: #9ae6b4;
        }
        .btn-maintenance:hover {
            background: #38a169;
            color: white;
            border-color: #38a169;
        }

        .btn-allocate {
            background: var(--success);
            color: white;
            border: none;
            min-width: 120px;
            padding: 6px 16px;
        }
        .btn-allocate:hover {
            background: #2f855a;
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
        h2 { font-size: 1.35rem; }
        h4 { font-size: 1.1rem; }

        .text-muted {
            color: #718096 !important;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .header-buttons {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .admin-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .header-buttons {
                width: 100%;
                justify-content: space-between;
            }
            
            .action-buttons {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <div class="header-title">
                <h2>Resource Details</h2>
                <p>Resource #<?php echo $resource['Resource_ID']; ?></p>
            </div>
            <div class="header-buttons">
                <a href="edit_resource.php?id=<?php echo $resource['Resource_ID']; ?>" class="action-btn btn-edit">
                    <i class="fas fa-edit me-1"></i> Edit Resource
                </a>
                <a href="manage_resources.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <div class="content-area">
            
            <?php if(!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Resource Information -->
            <div class="content-section">
                <h4 class="mb-3">Resource Information</h4>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Resource ID</div>
                            <div class="info-value">#<?php echo $resource['Resource_ID']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Resource Type</div>
                            <div class="info-value"><?php echo htmlspecialchars($resource['Resource_Type']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <?php if($resource['Maintenance_ID']): ?>
                                    <span class="status-badge badge-allocated">Allocated</span>
                                <?php else: ?>
                                    <span class="status-badge badge-available">Available</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Quantity</div>
                            <div class="info-value"><?php echo number_format($resource['Quantity']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Unit Cost</div>
                            <div class="info-value">৳<?php echo number_format($resource['Unit_Cost'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="cost-card">
                            <div class="info-label">Total Cost</div>
                            <div class="cost-highlight">৳<?php echo number_format($resource['Total_Cost'], 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Allocation -->
            <div class="content-section">
                <h4 class="mb-3">Current Allocation</h4>
                <?php if($resource['Maintenance_ID']): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="info-card">
                                <div class="info-label">Maintenance ID</div>
                                <div class="info-value">#<?php echo $resource['Maintenance_ID']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-card">
                                <div class="info-label">Task Type</div>
                                <div class="info-value"><?php echo htmlspecialchars($resource['Task_type']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-card">
                                <div class="info-label">Task Status</div>
                                <div class="info-value">
                                    <span class="status-badge <?php 
                                        switch($resource['Maintenance_Status']) {
                                            case 'Completed': echo 'badge-good'; break;
                                            case 'In Progress': echo 'badge-under-maintenance'; break;
                                            case 'Pending': echo 'badge-needs-maintenance'; break;
                                            default: echo 'badge-fair';
                                        }
                                    ?>">
                                        <?php echo htmlspecialchars($resource['Maintenance_Status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-card">
                                <div class="info-label">Asset Name</div>
                                <div class="info-value">
                                    <a href="view_asset.php?id=<?php echo $resource['Asset_ID']; ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($resource['Asset_Name']); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-card">
                                <div class="info-label">Asset Type</div>
                                <div class="info-value"><?php echo htmlspecialchars($resource['Asset_Type']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-card">
                                <div class="info-label">Location</div>
                                <div class="info-value"><?php echo htmlspecialchars($resource['Location']); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- View Maintenance Button Only -->
                    <div class="action-buttons">
                        <a href="view_maintenance.php?id=<?php echo $resource['Maintenance_ID']; ?>" class="action-btn btn-maintenance">
                            <i class="fas fa-tools me-1"></i> View Maintenance
                        </a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-box"></i>
                        <h5>No Current Allocation</h5>
                        <p class="text-muted">This resource is not currently allocated to any maintenance task.</p>
                        <a href="manage_maintenance.php" class="btn-allocate">
                            <i class="fas fa-tools me-1"></i> Allocate to Task
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>