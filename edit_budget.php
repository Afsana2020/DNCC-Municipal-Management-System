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

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_budgets.php");
    exit();
}

$budget_id = $_GET['id'];

//budget data
$budget_query = "SELECT b.*, a.Asset_Name, a.Asset_Type 
                 FROM Budgets b 
                 LEFT JOIN Assets a ON b.Asset_ID = a.Asset_ID 
                 WHERE b.Budget_ID = ?";
$stmt = $conn->prepare($budget_query);
$stmt->bind_param("i", $budget_id);
$stmt->execute();
$budget_result = $stmt->get_result();

if($budget_result->num_rows === 0) {
    header("Location: manage_budgets.php");
    exit();
}

$budget_data = $budget_result->fetch_assoc();
$stmt->close();

$assets_query = "SELECT Asset_ID, Asset_Name, Asset_Type FROM Assets ORDER BY Asset_Name";
$assets_result = $conn->query($assets_query);

// form submission
if(isset($_POST['update_budget'])) {
    $asset_id = $_POST['asset_id'];
    $amount_allocation = $_POST['amount_allocation'];

    //if asset already has a budget
    $check_query = "SELECT Budget_ID FROM Budgets WHERE Asset_ID = ? AND Budget_ID != ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ii", $asset_id, $budget_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    
    if($check_result->num_rows > 0) {
        $error_msg = "This asset already has a budget allocated. Please choose a different asset.";
    } else {
        
        $update_query = "UPDATE Budgets SET Asset_ID = ?, Amount_Allocation = ? WHERE Budget_ID = ?";
        $stmt = $conn->prepare($update_query);
        if($stmt) {
            $stmt->bind_param("idi", $asset_id, $amount_allocation, $budget_id);
            if($stmt->execute()) {
               
                header("Location: view_budget.php?id=$budget_id");
                exit();
            } else {
                $error_msg = "Error updating budget: " . $stmt->error;
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
    <title>Edit Budget - Smart DNCC</title>
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
      
        <?php include 'sidebar.php'; ?>

        <!-- Main -->
        <div class="admin-main">
            <!-- Header -->
            <div class="admin-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h2>Edit Budget</h2>
                        <p class="text-muted mb-0">Update yearly budget information for #<?php echo $budget_data['Budget_ID']; ?></p>
                    </div>
                    <div class="col-auto">
                        <a href="view_budget.php?id=<?php echo $budget_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Budget Details
                        </a>
                    </div>
                </div>
            </div>

            <div class="container-fluid p-3">
                <?php if(!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

             
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Budget Information:</strong> 
                    This is a <strong>yearly budget</strong>. <strong>Amount Spent (৳<?php echo number_format($budget_data['Amount_Spent'], 2); ?>)</strong> 
                    is automatically calculated from maintenance tasks and cannot be manually edited.
                </div>

                <!-- budget Info -->
                <div class="content-section">
                    <h4 class="mb-3">
                        <i class="fas fa-info-circle me-2 text-info"></i>
                        Current Budget Information
                    </h4>
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <div class="info-card">
                                <div class="info-label">Budget ID</div>
                                <div class="info-value">#<?php echo $budget_data['Budget_ID']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="info-card">
                                <div class="info-label">Amount Spent (Auto-calculated)</div>
                                <div class="info-value fw-bold text-success">
                                    ৳<?php echo number_format($budget_data['Amount_Spent'], 2); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="info-card">
                                <div class="info-label">Remaining Budget</div>
                                <div class="info-value fw-bold text-info">
                                    ৳<?php echo number_format($budget_data['Amount_Allocation'] - $budget_data['Amount_Spent'], 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit form -->
                <div class="content-section">
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asset <span class="text-danger">*</span></label>
                                <select class="form-select" name="asset_id" required>
                                    <option value="">Select an Asset</option>
                                    <?php 
                                    $assets_result->data_seek(0); 
                                    while($asset = $assets_result->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $asset['Asset_ID']; ?>" 
                                            <?php echo ($budget_data['Asset_ID'] == $asset['Asset_ID']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($asset['Asset_Name']); ?> - <?php echo htmlspecialchars($asset['Asset_Type']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <div class="form-text">Select the asset for this budget allocation</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Yearly Budget Allocation (BDT) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="amount_allocation" step="0.01" min="0"
                                       value="<?php echo $budget_data['Amount_Allocation']; ?>" 
                                       required>
                                <div class="form-text">Total yearly budget amount allocated for this asset</div>
                            </div>
                        </div>

                       

                        <div class="d-flex gap-2 justify-content-end">
                            <a href="view_budget.php?id=<?php echo $budget_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Cancel
                            </a>
                            <button type="submit" name="update_budget" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Budget
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