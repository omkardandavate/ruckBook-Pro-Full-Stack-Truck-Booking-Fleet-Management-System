<?php
/**
 * TruckBook Pro - Cross-Site Request Forgery (CSRF) Protection
 * Generates cryptographically secure tokens and validates incoming requests.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get the current CSRF token, generating one if not yet set in session
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Regenerate the CSRF token
 */
function regenerateCsrfToken(): string {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden HTML input field with CSRF token
 */
function csrfField(): string {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Render a meta tag with the CSRF token for AJAX / Fetch requests
 */
function csrfMeta(): string {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<meta name="csrf-token" content="' . $token . '">';
}

/**
 * Validate submitted CSRF token against session token
 */
function validateCsrfToken(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] 
            ?? $_SERVER['HTTP_X_CSRF_TOKEN'] 
            ?? '';
    }

    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Abort with 403 Forbidden if CSRF token is invalid on state-changing requests
 */
function verifyCsrfOrDie(): void {
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        if (!validateCsrfToken()) {
            http_response_code(403);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'CSRF validation failed or token expired. Please refresh the page.']);
            } else {
                die('<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>Security Warning: Invalid CSRF Token</h2><p>Your session may have expired or this request was blocked for your security.</p><p><a href="javascript:history.back()">Go Back</a> or <a href="' . BASE_URL . '/login.php">Log In</a></p></body></html>');
            }
            exit;
        }
    }
}
