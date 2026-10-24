<?php
/**
 * TruckBook Pro - Authentication and Authorization System
 * Provides role verification, secure session management, login throttling, and audit hooks.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Check if a user is currently logged in
 */
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role_slug']);
}

/**
 * Get current authenticated user ID
 */
function currentUserId(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Get current user array from session or database
 */
function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }

    static $cachedUser = null;
    if ($cachedUser !== null) {
        return $cachedUser;
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT u.id, u.role_id, u.name, u.email, u.phone, u.status, u.avatar, u.last_login,
                   r.name AS role_name, r.slug AS role_slug
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            $cachedUser = $user;
            return $user;
        }
    } catch (Throwable $e) {
        error_log("Error fetching current user: " . $e->getMessage());
    }

    return null;
}

/**
 * Get current user role slug
 */
function currentRole(): string {
    return $_SESSION['role_slug'] ?? 'guest';
}

/**
 * Check if the user has a specific role
 */
function hasRole(string $roleSlug): bool {
    if (!isLoggedIn()) {
        return false;
    }
    return ($_SESSION['role_slug'] ?? '') === $roleSlug;
}

/**
 * Check if user matches any of the given roles
 */
function hasAnyRole(array $roleSlugs): bool {
    if (!isLoggedIn()) {
        return false;
    }
    return in_array($_SESSION['role_slug'] ?? '', $roleSlugs, true);
}

/**
 * Throttle login attempts to defend against brute force
 */
function checkLoginThrottling(string $email): bool {
    $key = 'login_attempts_' . md5($email . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_attempt' => time()];

    $maxAttempts = (int)getSetting('max_login_attempts', '5');
    $lockoutTime = 300; // 5 minutes

    if ($attempts['count'] >= $maxAttempts) {
        if (time() - $attempts['first_attempt'] < $lockoutTime) {
            return false; // Throttled
        }
        // Reset after lockout window
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }

    return true;
}

/**
 * Record a failed login attempt
 */
function recordFailedLogin(string $email): void {
    $key = 'login_attempts_' . md5($email . ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
    } else {
        $_SESSION[$key]['count']++;
    }
}

/**
 * Clear login attempts after successful authentication
 */
function clearLoginAttempts(string $email): void {
    $key = 'login_attempts_' . md5($email . ($_SERVER['REMOTE_ADDR'] ?? ''));
    unset($_SESSION[$key]);
}

/**
 * Authenticate user with credentials
 */
function authenticateUser(string $email, string $password): array {
    $email = trim($email);

    if (!checkLoginThrottling($email)) {
        return [
            'success' => false,
            'message' => 'Too many failed login attempts. Please wait 5 minutes before trying again.'
        ];
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT u.id, u.role_id, u.name, u.email, u.password_hash, u.status,
                   r.name AS role_name, r.slug AS role_slug
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            recordFailedLogin($email);
            return [
                'success' => false,
                'message' => 'Invalid email or password.'
            ];
        }

        if ($user['status'] !== 'active') {
            return [
                'success' => false,
                'message' => 'Your account is currently inactive or suspended. Please contact administrator.'
            ];
        }

        // Authentication Successful: Regenerate session to prevent fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role_id'] = (int)$user['role_id'];
        $_SESSION['role_slug'] = $user['role_slug'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['logged_in_time'] = time();

        // Update last login in database
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);

        clearLoginAttempts($email);

        // Audit log
        require_once __DIR__ . '/functions.php';
        logAudit($user['id'], 'user_login', 'users', $user['id'], null, ['email' => $email]);

        return [
            'success' => true,
            'user' => $user
        ];
    } catch (Throwable $e) {
        error_log("Login error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An unexpected server error occurred during login. Please try again.'
        ];
    }
}

/**
 * Log out user safely
 */
function logoutUser(): void {
    if (isLoggedIn()) {
        require_once __DIR__ . '/functions.php';
        logAudit(currentUserId(), 'user_logout', 'users', currentUserId());
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

/**
 * Secure password hashing helper
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}
