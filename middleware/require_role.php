<?php
/**
 * TruckBook Pro - Role-Based Authorization Middleware
 * Enforces role restrictions at the server level.
 */

declare(strict_types=1);

require_once __DIR__ . '/require_login.php';

/**
 * Enforce role access on current execution script
 *
 * @param array $allowedRoles Array of permitted role slugs (e.g. ['super_admin', 'transport_manager'])
 */
function requireRole(array $allowedRoles): void {
    $currentRole = currentRole();

    if (!in_array($currentRole, $allowedRoles, true)) {
        // If it's an AJAX request, respond with JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            jsonResponse([
                'success' => false,
                'error' => 'Forbidden: You do not possess the required permissions.'
            ], 403);
        }

        // Render 403 page
        http_response_code(403);
        $user = currentUser();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>403 Access Denied | <?= APP_NAME ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
            <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
        </head>
        <body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
            <div class="card shadow border-0 p-4 text-center" style="max-width: 500px;">
                <div class="mb-3 text-danger display-4">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h3 class="fw-bold text-dark">Access Denied (403)</h3>
                <p class="text-muted">
                    Your current role <strong><?= e($user['role_name'] ?? $currentRole) ?></strong> is not authorized to access this section or perform this operation.
                </p>
                <div class="d-flex justify-content-center gap-2 mt-3">
                    <a href="javascript:history.back()" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Go Back
                    </a>
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary">
                        <i class="bi bi-house-door me-1"></i> Return to Dashboard
                    </a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
