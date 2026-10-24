<?php
/**
 * TruckBook Pro - Core Application Configuration & Bootstrapper
 */

declare(strict_types=1);

// Prevent direct execution header leaks
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');

    // Secure cookie if HTTPS is detected
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
}

// Ensure timezone
date_default_timezone_set('Asia/Kolkata');

// Base Paths
define('APP_ROOT', dirname(__DIR__));
define('CONFIG_PATH', APP_ROOT . '/config');
define('INCLUDES_PATH', APP_ROOT . '/includes');
define('MIDDLEWARE_PATH', APP_ROOT . '/middleware');
define('MODULES_PATH', APP_ROOT . '/modules');
define('STORAGE_PATH', APP_ROOT . '/storage');
define('UPLOADS_PATH', STORAGE_PATH . '/uploads');

// Calculate base URL dynamically
if (!function_exists('getBaseUrl')) {
    function getBaseUrl(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = str_replace('\\', '/', $scriptDir);
        
        // Strip module or api subdirectories if script is deep in folder
        $cleanSubdir = preg_replace('#/(modules|api|includes)/.*$#', '', $scriptDir);
        $cleanSubdir = rtrim($cleanSubdir, '/');
        
        return "{$scheme}://{$host}{$cleanSubdir}";
    }
}

define('BASE_URL', getBaseUrl());

// App Metadata Defaults
define('APP_NAME', 'TruckBook Pro');
define('APP_VERSION', '1.0.0');

// Load settings from database with memoization
if (!function_exists('getSetting')) {
    function getSetting(string $key, string $default = ''): string {
        static $settings = null;
        if ($settings === null) {
            $settings = [];
            try {
                require_once __DIR__ . '/database.php';
                $pdo = getDbConnection();
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM company_settings");
                while ($row = $stmt->fetch()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Throwable $e) {
                // Fallback gracefully during migrations or setup
            }
        }
        return $settings[$key] ?? $default;
    }
}
