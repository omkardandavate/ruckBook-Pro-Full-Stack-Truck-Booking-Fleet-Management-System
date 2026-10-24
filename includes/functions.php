<?php
/**
 * TruckBook Pro - Reusable Core Helper Functions
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/validation.php';

/**
 * Set a session flash message
 */
function setFlash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_messages'][] = [
        'type' => in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info',
        'message' => $message
    ];
}

/**
 * Check if flash messages exist
 */
function hasFlash(): bool {
    return !empty($_SESSION['flash_messages']);
}

/**
 * Retrieve and clear flash messages
 */
function getFlash(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Render flash messages as Bootstrap alerts
 */
function renderFlashAlerts(): string {
    $messages = getFlash();
    if (empty($messages)) {
        return '';
    }

    $html = '<div class="flash-messages mb-3">';
    foreach ($messages as $msg) {
        $icon = match ($msg['type']) {
            'success' => '<i class="bi bi-check-circle-fill me-2"></i>',
            'danger' => '<i class="bi bi-exclamation-triangle-fill me-2"></i>',
            'warning' => '<i class="bi bi-exclamation-circle-fill me-2"></i>',
            default => '<i class="bi bi-info-circle-fill me-2"></i>',
        };
        $alertType = htmlspecialchars($msg['type']);
        $text = htmlspecialchars($msg['message']);
        $html .= "
        <div class='alert alert-{$alertType} alert-dismissible fade show shadow-sm d-flex align-items-center' role='alert'>
            {$icon}
            <div>{$text}</div>
            <button type='button' class='btn-close ms-auto' data-bs-dismiss='alert' aria-label='Close'></button>
        </div>";
    }
    $html .= '</div>';
    return $html;
}

/**
 * Redirect to a specific URL
 */
function redirect(string $url): void {
    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        $url = BASE_URL . '/' . ltrim($url, '/');
    }
    header("Location: {$url}");
    exit;
}

/**
 * Emit JSON response and exit
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Record an audit log entry into the database
 */
function logAudit(?int $userId, string $action, string $entityType, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null): void {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldValues !== null ? json_encode($oldValues) : null,
            $newValues !== null ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    } catch (Throwable $e) {
        error_log("Audit log recording failed: " . $e->getMessage());
    }
}

/**
 * Create an in-app notification for a user
 */
function createNotification(int $userId, string $title, string $message, ?string $link = null, string $type = 'info'): bool {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, link, type, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        return $stmt->execute([$userId, $title, $message, $link, $type]);
    } catch (Throwable $e) {
        error_log("Failed to insert notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Format currency amount with configured symbol
 */
function formatCurrency(float|int|string|null $amount): string {
    $symbol = getSetting('currency_symbol', '$');
    $val = (float)($amount ?? 0);
    return $symbol . number_format($val, 2);
}

/**
 * Format date nicely
 */
function formatDate(?string $date, string $format = 'M d, Y'): string {
    if (empty($date)) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

/**
 * Format date & time
 */
function formatDateTime(?string $dateTime, string $format = 'M d, Y h:i A'): string {
    if (empty($dateTime)) {
        return '—';
    }
    $ts = strtotime($dateTime);
    return $ts ? date($format, $ts) : '—';
}

/**
 * Render a visual status badge with semantic styling
 */
function renderStatusBadge(string $status): string {
    $normalized = strtolower(trim($status));
    $badgeClass = match ($normalized) {
        'active', 'completed', 'delivered', 'accepted', 'approved', 'paid', 'available' => 'bg-success-subtle text-success border border-success-subtle',
        'in_progress', 'in_transit', 'dispatched', 'quoted', 'partially_paid', 'on_trip' => 'bg-primary-subtle text-primary border border-primary-subtle',
        'pending', 'pending_assignment', 'scheduled', 'draft', 'sent', 'arrived', 'unloading' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        'inactive', 'cancelled', 'rejected', 'unpaid', 'overdue', 'maintenance', 'suspended', 'on_leave' => 'bg-danger-subtle text-danger border border-danger-subtle',
        default => 'bg-secondary-subtle text-secondary border border-secondary-subtle'
    };

    $display = ucwords(str_replace('_', ' ', $normalized));
    return "<span class='badge {$badgeClass} px-2 py-1 rounded-pill'>{$display}</span>";
}

/**
 * Check if the current page URL matches a nav path for active menu highlight
 */
function isActiveRoute(string $path): string {
    $currentUri = $_SERVER['REQUEST_URI'] ?? '';
    return (str_contains($currentUri, $path)) ? 'active' : '';
}
