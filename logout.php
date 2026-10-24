<?php
/**
 * TruckBook Pro - Logout Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

logoutUser();
setFlash('info', 'You have been safely signed out.');
redirect('login.php');
