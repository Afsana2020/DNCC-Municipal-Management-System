<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}

$is_admin = true;

$success_msg = '';
$error_msg = '';

// Get context from URL
$maintenance_id = isset($_GET['maintenance_id']) ? $_GET['maintenance_id'] : null;
$project_id = isset($_GET['project_id']) ? $_GET['project_id'] : null;
$package_id = isset($_GET['package_id']) ? $_GET['package_id'] : null;

if(!isset($maintenance_id) || empty($maintenance_id)) {
    header("Location: manage_maintenance.php");
    exit();
}

// Get maintenance task info with full context
$maintenance_query = "SELECT m.*, a.Asset_Name, a.Asset_Type, a.Location,
                             pr.Project_ID, pr.Project_Name,
                             p.Package_ID, p.Package_Name
                      FROM Maintenance m 
                      JOIN Assets a ON m.Asset_ID = a.Asset_ID 
                      LEFT JOIN Projects pr ON m.Project_ID = pr.Project_ID
                      LEFT JOIN Packages p ON m.Package_ID = p.Package_ID
                      WHERE m.Maintenance_ID = ?";
$stmt = $conn->prepare($maintenance_query);
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$maintenance_result = $stmt->get_result();

if($maintenance_result->num_rows === 0) {
    header("Location: manage_maintenance.php");
    exit();
}

$maintenance_task = $maintenance_result->fetch_assoc();
$stmt->close();

// Get project_id and package_id from task if not provided
if(!$project_id && !empty($maintenance_task['Project_ID'])) {
    $project_id = $maintenance_task['Project_ID'];
}
if(!$package_id && !empty($maintenance_task['Package_ID'])) {
    $package_id = $maintenance_task['Package_ID'];
}

// Get project info if available
$project = null;
if($project_id) {
    $project_query = "SELECT * FROM Projects WHERE Project_ID = ?";
    $stmt = $conn->prepare($project_query);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $project_result = $stmt->get_result();
    $project = $project_result->fetch_assoc();
    $stmt->close();
}

// Get package info if available
$package = null;
if($package_id) {
    $package_query = "SELECT p.*, pr.Project_Name FROM Packages p 
                      JOIN Projects pr ON p.Project_ID = pr.Project_ID 
                      WHERE p.Package_ID = ?";
    $stmt = $conn->prepare($package_query);
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $package_result = $stmt->get_result();
    $package = $package_result->fetch_assoc();
    $stmt->close();
}

// Handle form submission
if(isset($_POST['add_resource'])) {
    $resource_type = trim($_POST['resource_type']);
    $quantity = $_POST['quantity'];
    $unit_cost = $_POST['unit_cost'];

    // Add new resource
    $insert_query = "INSERT INTO Resources (Resource_Type, Quantity, Unit_Cost, Maintenance_ID) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_query);
    if($stmt) {
        $stmt->bind_param("sidi", $resource_type, $quantity, $unit_cost, $maintenance_id);
        if($stmt->execute()) {
            header("Location: view_resource_maintenance.php?id=$maintenance_id&success=resource_added");
            exit();
        } else {
            $error_msg = "Error adding resource: " . $stmt->error;
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
    <title>Add Resource - Smart DNCC</title>
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

        .header-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .header-left h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-left p {
            color: #64748b;
            font-size: 0.85rem;
            margin: 4px 0 0 0;
        }

        .content-area {
            padding: 24px 28px;
            overflow-y: auto;
            height: calc(100vh - 89px);
            display: flex;
            flex-direction: column;
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
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }

        /* Hierarchy Banner */
        .hierarchy-banner {
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }

        .hierarchy-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
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
        
        .badge-current { 
            background: #e2e8f0; 
            color: #4a5568; 
            border: 1px solid #cbd5e0; 
            cursor: default;
        }

        .chevron {
            color: #a0aec0;
            font-size: 0.8rem;
            margin: 0 4px;
        }

        /* Task Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            min-height: 80px;
            border-left: 4px solid var(--success);
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #718096;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
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
            border-radius: 8px;
            padding: 10px 14px;
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

        /* Cost Preview */
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

        .cost-preview.hidden {
            display: none;
        }

        .cost-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success);
        }

        /* Buttons */
        .btn {
            border-radius: 8px;
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
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(49,130,206,0.2);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }
        .btn-success:hover {
            background: #2f855a;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(56,161,105,0.2);
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

        .alert {
            border: 1px solid;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            padding: 12px 20px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
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
        }

        h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 16px;
        }

        .text-muted {
            color: #718096 !important;
        }

        .row.g-3 {
            --bs-gutter-y: 1rem;
        }
        .row.g-4 {
            --bs-gutter-y: 1.5rem;
        }

        @media (max-width: 768px) {
            .admin-main {
                margin-left: 0;
            }
            
            .content-area {
                padding: 16px;
            }
            
            .header-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <!-- Header -->
        <div class="admin-header">
            <div class="header-title">
                <div class="header-left">
                    <h2>
                        <i class="fas fa-boxes" style="color: var(--success);"></i>
                        Add Resource
                    </h2>
                    <p>Add a new resource to maintenance task #<?php echo $maintenance_id; ?></p>
                </div>
                <div>
                    <a href="view_resource_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="content-area">
            
            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Hierarchy Banner -->
            <div class="hierarchy-banner">
                <div class="hierarchy-icon" style="background: rgba(56,161,105,0.1); color: #38a169;">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="hierarchy-path">
                    <?php if(!empty($maintenance_task['Project_ID'])): ?>
                        <a href="view_project.php?id=<?php echo $maintenance_task['Project_ID']; ?>" class="hierarchy-link badge-project">
                            <i class="fas fa-folder-open"></i>
                            <?php echo htmlspecialchars($maintenance_task['Project_Name']); ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if(!empty($maintenance_task['Package_ID'])): ?>
                        <?php if(!empty($maintenance_task['Project_ID'])): ?>
                            <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                        <a href="view_package.php?id=<?php echo $maintenance_task['Package_ID']; ?>" class="hierarchy-link badge-package">
                            <i class="fas fa-cubes"></i>
                            <?php echo htmlspecialchars($maintenance_task['Package_Name']); ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if(!empty($maintenance_task['Project_ID']) || !empty($maintenance_task['Package_ID'])): ?>
                        <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                    
                    <a href="view_maintenance.php?id=<?php echo $maintenance_id; ?>" class="hierarchy-link badge-task">
                        <i class="fas fa-tasks"></i>
                        Task #<?php echo $maintenance_id; ?>
                    </a>
                    
                    <span class="chevron"><i class="fas fa-chevron-right"></i></span>
                    <span class="hierarchy-link badge-current">
                        <i class="fas fa-plus-circle"></i>
                        Add Resource
                    </span>
                </div>
            </div>

            <!-- Task Summary -->
            <div class="content-section">
                <h4>Task Information</h4>
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label">Task ID</div>
                        <div class="info-value">#<?php echo $maintenance_task['Maintenance_ID']; ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Task Type</div>
                        <div class="info-value"><?php echo htmlspecialchars($maintenance_task['Task_type']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Asset</div>
                        <div class="info-value"><?php echo htmlspecialchars($maintenance_task['Asset_Name']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Asset Type</div>
                        <div class="info-value"><?php echo htmlspecialchars($maintenance_task['Asset_Type']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Add Resource Form -->
            <div class="content-section">
                <form method="POST" action="" id="resourceForm">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label">Resource Type *</label>
                            <select class="form-select" name="resource_type" required>
                                <option value="">Select Resource Type</option>
                                <optgroup label="Construction Materials">
                                    <option value="Cement">Cement</option>
                                    <option value="Steel Rods">Steel Rods</option>
                                    <option value="Bricks">Bricks</option>
                                    <option value="Sand">Sand</option>
                                    <option value="Gravel">Gravel</option>
                                    <option value="Pipes">Pipes</option>
                                    <option value="Tiles">Tiles</option>
                                    <option value="Wood">Wood</option>
                                    <option value="Glass">Glass</option>
                                </optgroup>
                                <optgroup label="Electrical">
                                    <option value="Electrical Wires">Electrical Wires</option>
                                    <option value="Switches">Switches</option>
                                    <option value="Fittings">Fittings</option>
                                </optgroup>
                                <optgroup label="Tools & Equipment">
                                    <option value="Tools">Tools</option>
                                    <option value="Safety Equipment">Safety Equipment</option>
                                    <option value="Heavy Machinery">Heavy Machinery</option>
                                </optgroup>
                                <optgroup label="Other">
                                    <option value="Paint">Paint</option>
                                    <option value="Other">Other</option>
                                </optgroup>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" class="form-control" name="quantity" id="quantity" 
                                   min="0.01" step="0.01" 
                                   placeholder="Enter quantity" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Unit Cost (BDT) *</label>
                            <input type="number" class="form-control" name="unit_cost" id="unitCost" 
                                   min="0" step="0.01" 
                                   placeholder="Enter unit cost" required>
                        </div>
                    </div>

                    <!-- Cost Preview -->
                    <div id="costPreview" class="cost-preview hidden">
                        <div>
                            <i class="fas fa-calculator me-2"></i>
                            <strong>Total Cost:</strong>
                            <small class="text-muted ms-2">(Quantity × Unit Cost)</small>
                        </div>
                        <span class="cost-amount" id="totalCost">৳0.00</span>
                    </div>

                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <a href="view_resource_maintenance.php?id=<?php echo $maintenance_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>Cancel
                        </a>
                        <button type="submit" name="add_resource" class="btn btn-success">
                            <i class="fas fa-plus-circle me-1"></i>Add Resource
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Calculate total cost dynamically
    document.addEventListener('DOMContentLoaded', function() {
        const quantityInput = document.getElementById('quantity');
        const unitCostInput = document.getElementById('unitCost');
        const costPreview = document.getElementById('costPreview');
        const totalCostSpan = document.getElementById('totalCost');

        function calculateTotalCost() {
            const quantity = parseFloat(quantityInput.value) || 0;
            const unitCost = parseFloat(unitCostInput.value) || 0;
            const totalCost = quantity * unitCost;

            if (quantity > 0 && unitCost > 0) {
                totalCostSpan.textContent = '৳' + totalCost.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                costPreview.classList.remove('hidden');
            } else {
                costPreview.classList.add('hidden');
            }
        }

        quantityInput.addEventListener('input', calculateTotalCost);
        unitCostInput.addEventListener('input', calculateTotalCost);
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>