<?php
session_start();
if(!isset($_SESSION['email'])) {
    header("Location: index.php");
    exit();
}

// Redirect based on role
if($_SESSION['role'] == 'admin') {
    header("Location: admin_dashboard.php");
    exit();
} else {
    header("Location: citizen_dashboard.php");
    exit();
}
?>