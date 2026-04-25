<?php
require_once __DIR__ . '/controllers/auth_controller.php';
requireLogin();
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Access Denied</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <style>
        body { background: #f4f5f7; }
        .error-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="error-wrapper">
        <div class="text-center px-4">
            <div class="avatar-text avatar-xxl bg-soft-danger text-danger mx-auto mb-4" style="width:80px;height:80px;font-size:2rem;">
                <i class="feather-lock"></i>
            </div>
            <h3 class="fw-bold text-dark mb-2">Access Denied</h3>
            <p class="text-muted mb-4">
                Your account <strong>(<?= ucfirst($user['role']) ?>)</strong> does not have permission to view this page.
            </p>
            <a href="javascript:history.back()" class="btn btn-light-brand me-2">
                <i class="feather-arrow-left me-1"></i> Go Back
            </a>
            <a href="dashboard.php" class="btn btn-primary">
                <i class="feather-home me-1"></i> Dashboard
            </a>
        </div>
    </div>
    <script src="assets/vendors/js/vendors.min.js"></script>
</body>
</html>