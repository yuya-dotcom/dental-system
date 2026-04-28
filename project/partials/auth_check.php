<?php
// =============================================================
//  partials/auth_check.php
//  Include at the TOP of every protected admin page.
//
//  Usage (basic - any logged in role):
//    require_once __DIR__ . '/partials/auth_check.php';
//
//  Usage (owner only):
//    define('REQUIRED_ROLE', 'owner');
//    require_once __DIR__ . '/partials/auth_check.php';
//
//  Usage (owner or admin):
//    define('REQUIRED_ROLES', ['owner', 'admin']);
//    require_once __DIR__ . '/partials/auth_check.php';
// =============================================================

require_once __DIR__ . '/../controllers/auth_controller.php';

requireLogin();

// Role restriction if defined by the page
if (defined('REQUIRED_ROLE')) {
    requireRole(REQUIRED_ROLE);
} elseif (defined('REQUIRED_ROLES')) {
    requireRole(...REQUIRED_ROLES);
}

$currentUser = getCurrentUser();