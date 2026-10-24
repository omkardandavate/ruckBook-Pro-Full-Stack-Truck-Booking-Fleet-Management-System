<?php
/**
 * TruckBook Pro - Authentication Middleware
 * Ensures user is authenticated before accessing protected pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    // Remember requested URL for post-login redirect
    if (!empty($_SERVER['REQUEST_URI'])) {
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'];
    }
    setFlash('warning', 'Please log in to access this page.');
    redirect('login.php');
    exit;
}
