<?php
session_start();
include("connect.php");

// Check if user is logged in and is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

$success_msg = '';
$error_msg = '';

// Get resource ID from URL
if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_resources.php");
    exit();
}

$resource_id = $_GET['id'];

// Get resource data with full context (project, package, task)
$resource_query = "SELECT r.*, 
                          m.Maintenance_ID, m.Task_type, m.Status as Task_Status,
                          a.Asset_ID, a.Asset_Name, a.Asset_Type,
                          p.Package_ID, p.Package_Name,
                          pr.Project_ID, pr.Project_Name
                   FROM Resources r 
                   LEFT JOIN Maintenance m ON r.Maintenance_ID = m.Maintenance_ID 
                   LEFT JOIN Assets a ON m.Asset_ID = a.Asset_ID 
                   LEFT JOIN Packages p ON m.Package_ID = p.Package_ID
                   LEFT JOIN Projects pr ON m.Project_ID = pr.Project_ID
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

// Get maintenance ID for back navigation
$maintenance_id = $resource['Maintenance_ID'];

// Handle form submission
if(isset($_POST['update_resource'])) {
    $resource_type = trim($_POST['resource_type']);
    $quantity = $_POST['quantity'];
    $unit_cost = $_POST['unit_cost'];

    $update_query = "UPDATE Resources SET Resource_Type = ?, Quantity = ?, Unit_Cost = ? WHERE Resource_ID = ?";
    $stmt = $conn->prepare($update_query);
    if($stmt) {
        $stmt->bind_param("sidi", $resource_type, $quantity, $unit_cost, $resource_id);
        if($stmt->execute()) {
            header("Location: view_resource.php?id=$resource_id&success=resource_updated");
            exit();
        } else {
            $error_msg = "Error updating resource: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Resource - Smart DNCC</title>
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
            border: 1px solid #e2e8f0;
        }

        /* Hierarchy Banner - matches manage_projects.php style */
        .hierarchy-banner {
            background: linear-gradient(to right, #f8fafc, #edf2f7);
            border-left: 6px solid var(--success);
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .hierarchy-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .hierarchy-path {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .hierarchy-link {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            gap: 6px;
        }

        .badge-project { 
            background: #ebf8ff; 
            color: #2c5282; 
            border: 1px solid #90cdf4; 
        }
        .badge-project:hover { 
            background: #bee3f8; 
            color: #1a365d; 
        }
        
        .badge-package { 
            background: #faf5ff; 
            color: #6b46c1; 
            border: 1px solid #d6bcfa; 
        }
        .badge-package:hover { 
            background: #e9d8fd; 
            color: #553c9a; 
        }
        
        .badge-task { 
            background: #f0fff4; 
            color: #276749; 
            border: 1px solid #9ae6b4; 
        }
        .badge-task:hover { 
            background: #c6f6d5; 
            color: #22543d; 
        }
        
        .badge-resource { 
            background: #e2e8f0; 
            color: #4a5568; 
            border: 1px solid #cbd5e0; 
            cursor: default;
        }

        .badge-current { 
            background: #d69e2e; 
            color: white; 
            border: 1px solid #b7791f; 
            cursor: default;
        }

        .chevron {
            color: #a0aec0;
            font-size: 0.8rem;
            margin: 0 4px;
        }

        /* Info Cards - matches manage_assets.php */
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            height: 100%;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #718096;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #2d3748;
        }

        /* Form Styling */
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
            font-size: 0.8rem;
            letter-spacing: 0.3px;
        }

        .form-control, .form-select {
            border-radius: 6px;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            font-size: 0.85rem;
            width: 100%;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }

        .cost-preview {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 8px;
            padding: 16px 20px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .cost-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success);
        }

        /* Buttons - matches manage_projects.php */
        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 8px 16px;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: #2c5282;
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

        .btn-warning {
            background: #d69e2e;
            color: white;
        }
        .btn-warning:hover {
            background: #b7791f;
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

        h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
        }
        h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 16px;
        }

        .text-muted {
            color: #64748b !important;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .hierarchy-banner {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .hierarchy-path {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <!-- Header -->
        <!-- Header -->
<div class="admin-header">
    <div class="header-title" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
        <div>
            <h2>
                <i class="fas fa-edit me-2" style="color: var(--warning);"></i>
                Edit Resource
            </h2>
            <p class="text-muted">Update information for resource #<?php echo $resource['Resource_ID']; ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="view_resource.php?id=<?php echo $resource_id; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-eye me-1"></i>View Resource
            </a>
            <?php if($maintenance_id): ?>
            <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Task
            </a>
            <?php else: ?>
            <a href="manage_resources.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Resources
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

        <!-- Scrollable Content Area -->
        <div class="content-area">
            
            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <!-- Hierarchy Banner - matches manage_projects.php style -->
            <div class="hierarchy-banner">
                <div class="hierarchy-icon" style="background: rgba(128,90,213,0.1); color: #805ad5;">
                    <i class="fas fa-cube"></i>
                </div>
                <div class="hierarchy-path">
                    <?php if(!empty($resource['Project_ID'])): ?>
                        <a href="view_project.php?id=<?php echo $resource['Project_ID']; ?>" class="hierarchy-link badge-project">
                            <i class="fas fa-folder-open"></i>
                            <?php echo htmlspecialchars($resource['Project_Name']); ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if(!empty($resource['Package_ID'])): ?>
                        <?php if(!empty($resource['Project_ID'])): ?>
                            <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                        <a href="view_package.php?id=<?php echo $resource['Package_ID']; ?>" class="hierarchy-link badge-package">
                            <i class="fas fa-cubes"></i>
                            <?php echo htmlspecialchars($resource['Package_Name']); ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if(!empty($resource['Maintenance_ID'])): ?>
                        <?php if(!empty($resource['Project_ID']) || !empty($resource['Package_ID'])): ?>
                            <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                        <a href="view_maintenance.php?id=<?php echo $resource['Maintenance_ID']; ?>" class="hierarchy-link badge-task">
                            <i class="fas fa-tasks"></i>
                            Task #<?php echo $resource['Maintenance_ID']; ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if(!empty($resource['Project_ID']) || !empty($resource['Package_ID']) || !empty($resource['Maintenance_ID'])): ?>
                        <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                    
                    <span class="hierarchy-link badge-resource">
                        <i class="fas fa-cube"></i>
                        Resource #<?php echo $resource['Resource_ID']; ?>
                    </span>
                    
                    <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                    <span class="hierarchy-link badge-current">
                        <i class="fas fa-edit"></i>
                        Edit
                    </span>
                </div>
            </div>

            <!-- Current Assignment Info - matches manage_assets.php card style -->
            <?php if($resource['Maintenance_ID']): ?>
            <div class="content-section">
                <h4>Current Assignment</h4>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="info-card">
                            <span class="info-label">Task ID</span>
                            <span class="info-value">#<?php echo $resource['Maintenance_ID']; ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <span class="info-label">Task Type</span>
                            <span class="info-value"><?php echo htmlspecialchars($resource['Task_type']); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <span class="info-label">Asset</span>
                            <span class="info-value"><?php echo htmlspecialchars($resource['Asset_Name']); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card">
                            <span class="info-label">Asset Type</span>
                            <span class="info-value"><?php echo htmlspecialchars($resource['Asset_Type']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Edit Resource Form -->
            <div class="content-section">
                <form method="POST" action="" id="resourceForm">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label">Resource Type *</label>
                            <select class="form-select" name="resource_type" required>
                                <option value="">Select Resource Type</option>
                                <optgroup label="Construction Materials">
                                    <option value="Cement" <?php echo $resource['Resource_Type'] == 'Cement' ? 'selected' : ''; ?>>Cement</option>
                                    <option value="Steel Rods" <?php echo $resource['Resource_Type'] == 'Steel Rods' ? 'selected' : ''; ?>>Steel Rods</option>
                                    <option value="Bricks" <?php echo $resource['Resource_Type'] == 'Bricks' ? 'selected' : ''; ?>>Bricks</option>
                                    <option value="Sand" <?php echo $resource['Resource_Type'] == 'Sand' ? 'selected' : ''; ?>>Sand</option>
                                    <option value="Gravel" <?php echo $resource['Resource_Type'] == 'Gravel' ? 'selected' : ''; ?>>Gravel</option>
                                    <option value="Pipes" <?php echo $resource['Resource_Type'] == 'Pipes' ? 'selected' : ''; ?>>Pipes</option>
                                    <option value="Tiles" <?php echo $resource['Resource_Type'] == 'Tiles' ? 'selected' : ''; ?>>Tiles</option>
                                    <option value="Wood" <?php echo $resource['Resource_Type'] == 'Wood' ? 'selected' : ''; ?>>Wood</option>
                                    <option value="Glass" <?php echo $resource['Resource_Type'] == 'Glass' ? 'selected' : ''; ?>>Glass</option>
                                </optgroup>
                                <optgroup label="Electrical">
                                    <option value="Electrical Wires" <?php echo $resource['Resource_Type'] == 'Electrical Wires' ? 'selected' : ''; ?>>Electrical Wires</option>
                                    <option value="Switches" <?php echo $resource['Resource_Type'] == 'Switches' ? 'selected' : ''; ?>>Switches</option>
                                    <option value="Fittings" <?php echo $resource['Resource_Type'] == 'Fittings' ? 'selected' : ''; ?>>Fittings</option>
                                </optgroup>
                                <optgroup label="Tools & Equipment">
                                    <option value="Tools" <?php echo $resource['Resource_Type'] == 'Tools' ? 'selected' : ''; ?>>Tools</option>
                                    <option value="Safety Equipment" <?php echo $resource['Resource_Type'] == 'Safety Equipment' ? 'selected' : ''; ?>>Safety Equipment</option>
                                    <option value="Heavy Machinery" <?php echo $resource['Resource_Type'] == 'Heavy Machinery' ? 'selected' : ''; ?>>Heavy Machinery</option>
                                </optgroup>
                                <optgroup label="Other">
                                    <option value="Paint" <?php echo $resource['Resource_Type'] == 'Paint' ? 'selected' : ''; ?>>Paint</option>
                                    <option value="Other" <?php echo $resource['Resource_Type'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                </optgroup>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" class="form-control" name="quantity" id="quantity" 
                                   min="0.01" step="0.01" 
                                   value="<?php echo $resource['Quantity']; ?>" 
                                   placeholder="Enter quantity" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Unit Cost (BDT) *</label>
                            <input type="number" class="form-control" name="unit_cost" id="unitCost" 
                                   min="0" step="0.01" 
                                   value="<?php echo $resource['Unit_Cost']; ?>" 
                                   placeholder="Enter unit cost" required>
                        </div>
                    </div>

                    <!-- Live Cost Preview -->
                    <div class="cost-preview">
                        <div>
                            <i class="fas fa-calculator me-2" style="color: var(--success);"></i>
                            <strong>Total Cost:</strong>
                            <small class="text-muted ms-2">(Quantity × Unit Cost)</small>
                        </div>
                        <span class="cost-amount" id="totalCost">
                            ৳<?php echo number_format($resource['Quantity'] * $resource['Unit_Cost'], 2); ?>
                        </span>
                    </div>

                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <a href="view_resource.php?id=<?php echo $resource_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>Cancel
                        </a>
                        <button type="submit" name="update_resource" class="btn btn-warning">
                            <i class="fas fa-save me-1"></i>Update Resource
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Live total cost calculation
    document.addEventListener('DOMContentLoaded', function() {
        const quantityInput = document.getElementById('quantity');
        const unitCostInput = document.getElementById('unitCost');
        const totalCostSpan = document.getElementById('totalCost');

        function calculateTotalCost() {
            const quantity = parseFloat(quantityInput.value) || 0;
            const unitCost = parseFloat(unitCostInput.value) || 0;
            const totalCost = quantity * unitCost;

            totalCostSpan.textContent = '৳' + totalCost.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        quantityInput.addEventListener('input', calculateTotalCost);
        unitCostInput.addEventListener('input', calculateTotalCost);
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>