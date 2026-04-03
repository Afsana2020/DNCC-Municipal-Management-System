<?php
session_start();
include("connect.php");

// Check if user is admin
if(!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: logout.php");
    exit();
}


$success_msg = '';
$error_msg = '';
$preselected_asset_id = null;
$preselected_asset_name = null;


if(isset($_GET['asset_id']) && !empty($_GET['asset_id'])) {
    $preselected_asset_id = $_GET['asset_id'];
    
    // asset details for the preselected asset
    $asset_query = "SELECT Asset_Name, Asset_Type FROM Assets WHERE Asset_ID = ?";
    $stmt = $conn->prepare($asset_query);
    $stmt->bind_param("i", $preselected_asset_id);
    $stmt->execute();
    $asset_result = $stmt->get_result();
    
    if($asset_result->num_rows > 0) {
        $asset_data = $asset_result->fetch_assoc();
        $preselected_asset_name = $asset_data['Asset_Name'] . ' - ' . $asset_data['Asset_Type'];
    }
    $stmt->close();
}

// all assets for dropdown 
if($preselected_asset_id) {
    $assets_query = "SELECT Asset_ID, Asset_Name, Asset_Type FROM Assets WHERE Asset_ID != ? ORDER BY Asset_Name";
    $stmt = $conn->prepare($assets_query);
    $stmt->bind_param("i", $preselected_asset_id);
    $stmt->execute();
    $assets_result = $stmt->get_result();
} else {
    $assets_query = "SELECT Asset_ID, Asset_Name, Asset_Type FROM Assets ORDER BY Asset_Name";
    $assets_result = $conn->query($assets_query);
}

// Handle form submission
if(isset($_POST['add_budget'])) {
    $asset_id = $_POST['asset_id'];
    $amount_allocation = $_POST['amount_allocation'];

    // Check if asset already has a budget
    $check_query = "SELECT Budget_ID FROM Budgets WHERE Asset_ID = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    
    if($check_result->num_rows > 0) {
        $error_msg = "This asset already has a budget allocated. Please edit the existing budget instead.";
    } else {
       
        $insert_query = "INSERT INTO Budgets (Asset_ID, Amount_Allocation, Amount_Spent) VALUES (?, ?, 0)";
        $stmt = $conn->prepare($insert_query);
        if($stmt) {
            $stmt->bind_param("id", $asset_id, $amount_allocation);
            if($stmt->execute()) {
               
                header("Location: manage_budgets.php");
                exit();
            } else {
                $error_msg = "Error adding budget: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Budget - Smart DNCC</title>
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

        .admin-body {
            background: #f8f9fa;
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            font-size: 0.875rem;
        }

        .admin-sidebar {
            background: var(--primary);
            min-height: 100vh;
            position: fixed;
            width: 220px;
            z-index: 1000;
        }

        .admin-main {
            margin-left: 220px;
            padding: 0;
            min-height: 100vh;
        }

        .admin-nav-link {
            color: #cbd5e0;
            padding: 10px 15px;
            margin: 1px 8px;
            border-radius: 4px;
            transition: all 0.2s;
            font-weight: 500;
            display: flex;
            align-items: center;
            text-decoration: none;
            font-size: 0.8rem;
        }

        .admin-nav-link:hover, .admin-nav-link.active {
            background: var(--accent);
            color: white;
        }

        .admin-nav-link i {
            width: 16px;
            margin-right: 8px;
            font-size: 12px;
        }

        .content-section {
            background: white;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid #dee2e6;
        }

        .admin-header {
            background: white;
            border-bottom: 1px solid #dee2e6;
            padding: 15px 20px;
            margin-bottom: 0;
        }

        .alert {
            border: none;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-bottom: 12px;
            padding: 8px 12px;
        }

        .btn {
            border-radius: 4px;
            font-weight: 500;
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
        }

        .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
        }

        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
            font-size: 0.8rem;
        }

        .form-control, .form-select {
            border-radius: 4px;
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            transition: all 0.2s;
            font-size: 0.8rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(49, 130, 206, 0.25);
        }

        h2 {
            color: #2d3748;
            font-weight: 600;
            font-size: 1.25rem;
            margin: 0;
        }

        .text-muted {
            color: #6c757d !important;
            font-size: 0.8rem;
        }

        .sidebar-brand {
            padding: 15px 10px;
        }

        .sidebar-brand h3 {
            font-size: 1rem;
        }

        .sidebar-brand small {
            font-size: 0.7rem;
        }

        .info-card {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 16px;
            border-left: 4px solid var(--info);
        }

        .preselected-card {
            background: #f0fff4;
            border-left: 4px solid var(--success);
        }

        .info-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.75rem;
            margin-bottom: 2px;
        }

        .info-value {
            color: #2d3748;
            font-size: 0.8rem;
            font-weight: 500;
        }

        html, body {
            overflow-x: hidden;
            width: 100%;
            max-width: 100%;
        }

        .admin-main {
            overflow-x: hidden;
            width: calc(100% - 220px);
        }
    </style>
</head>
<body class="admin-body">

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main -->
        <div class="admin-main">
            <!-- Header -->
            <div class="admin-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h2>Add New Budget</h2>
                        <p class="text-muted mb-0">Create a new yearly budget allocation for a specific asset</p>
                    </div>
                    <div class="col-auto">
                        <a href="manage_budgets.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Budgets
                        </a>
                    </div>
                </div>
            </div>

            <div class="container-fluid p-3">
                <?php if(!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if(!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Budget Information -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Budget Information:</strong> 
                    This is a <strong>yearly budget</strong> for asset maintenance. 
                    <strong>Amount Spent</strong> will be automatically calculated from all maintenance tasks (resources + workers) 
                    and cannot be manually edited.
                </div>

                <!-- Add Budget Form -->
                <div class="content-section">
                    <form method="POST" action="">
                        <div class="row">
                            <?php if($preselected_asset_id): ?>
                            <!-- Preselected Asset Section -->
                            <div class="col-12 mb-3">
                                <div class="info-card preselected-card">
                                    <div class="info-label">Selected Asset</div>
                                    <div class="info-value">
                                        <i class="fas fa-check-circle me-1 text-success"></i>
                                        <strong><?php echo htmlspecialchars($preselected_asset_name); ?></strong>
                                        <br>
                                        <small class="text-muted">This asset was automatically selected from your previous action.</small>
                                    </div>
                                </div>
                                <input type="hidden" name="asset_id" value="<?php echo $preselected_asset_id; ?>">
                            </div>
                            <?php else: ?>
                            <!-- Asset Selection Dropdown -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asset <span class="text-danger">*</span></label>
                                <select class="form-select" name="asset_id" required>
                                    <option value="">Select an Asset</option>
                                    <?php while($asset = $assets_result->fetch_assoc()): ?>
                                        <option value="<?php echo $asset['Asset_ID']; ?>" 
                                            <?php echo (isset($_POST['asset_id']) && $_POST['asset_id'] == $asset['Asset_ID']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($asset['Asset_Name']); ?> - <?php echo htmlspecialchars($asset['Asset_Type']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <div class="form-text">Select the asset for this budget allocation</div>
                            </div>
                            <?php endif; ?>

                            <div class="<?php echo $preselected_asset_id ? 'col-md-12' : 'col-md-6'; ?> mb-3">
                                <label class="form-label">Yearly Budget Allocation (BDT) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="amount_allocation" step="0.01" min="0"
                                       value="<?php echo isset($_POST['amount_allocation']) ? $_POST['amount_allocation'] : ''; ?>" 
                                       required>
                                <div class="form-text">Total yearly budget amount allocated for this asset</div>
                            </div>
                        </div>

                     

                        <div class="d-flex gap-2 justify-content-end">
                            <a href="manage_budgets.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Cancel
                            </a>
                            <button type="submit" name="add_budget" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Add Budget
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>