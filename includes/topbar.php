<?php
/**
 * TruckBook Pro - Reusable Topbar Component
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$userId = currentUserId();
$unreadNotifications = [];

if ($userId) {
    try {
        $pdo = getDbConnection();
        $notifStmt = $pdo->prepare("
            SELECT id, title, message, link, type, created_at
            FROM notifications
            WHERE user_id = ? AND is_read = 0
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $notifStmt->execute([$userId]);
        $unreadNotifications = $notifStmt->fetchAll();
    } catch (Throwable $e) {
        // Soft fail
    }
}
$unreadCount = count($unreadNotifications);
?>
<header id="app-topbar">
    <div class="d-flex align-items-center gap-3">
        <button type="button" id="sidebar-toggle" class="btn btn-light d-lg-none p-2 border">
            <i class="bi bi-list fs-5"></i>
        </button>
        <div class="d-none d-md-block">
            <span class="text-muted small">Platform</span>
            <span class="mx-1 text-muted">/</span>
            <span class="fw-semibold text-dark"><?= e($pageTitle ?? 'Overview') ?></span>
        </div>
    </div>

    <div class="topbar-actions">
        <!-- Notification Dropdown -->
        <div class="dropdown">
            <button class="notification-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                <i class="bi bi-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="notification-badge"></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-0" style="width: 320px;">
                <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light rounded-top">
                    <span class="fw-bold">Notifications</span>
                    <span class="badge bg-primary rounded-pill"><?= $unreadCount ?> New</span>
                </div>
                <div class="list-group list-group-flush" style="max-height: 280px; overflow-y: auto;">
                    <?php if (empty($unreadNotifications)): ?>
                        <div class="p-3 text-center text-muted small">No new notifications.</div>
                    <?php else: ?>
                        <?php foreach ($unreadNotifications as $n): ?>
                            <a href="<?= e($n['link'] ? BASE_URL . '/' . ltrim($n['link'], '/') : '#') ?>" class="list-group-item list-group-item-action p-3">
                                <div class="d-flex w-100 justify-content-between mb-1">
                                    <strong class="text-dark small"><?= e($n['title']) ?></strong>
                                    <small class="text-muted"><?= date('H:i', strtotime($n['created_at'])) ?></small>
                                </div>
                                <p class="mb-0 text-muted small text-truncate"><?= e($n['message']) ?></p>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- User Profile Dropdown -->
        <div class="dropdown">
            <button class="btn btn-light border d-flex align-items-center gap-2 p-1 pe-3 rounded-pill" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="user-avatar-circle" style="width: 32px; height: 32px; font-size: 0.8rem;">
                    <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
                </div>
                <span class="topbar-user-name fw-semibold small text-dark"><?= e($user['name'] ?? 'Account') ?></span>
                <i class="bi bi-chevron-down small text-muted"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2">
                <li class="px-3 py-2 border-bottom">
                    <div class="fw-bold text-dark"><?= e($user['name'] ?? '') ?></div>
                    <div class="text-muted small"><?= e($user['email'] ?? '') ?></div>
                    <span class="badge bg-primary-subtle text-primary mt-1"><?= e($user['role_name'] ?? '') ?></span>
                </li>
                <li>
                    <a class="dropdown-item py-2" href="<?= BASE_URL ?>/profile.php">
                        <i class="bi bi-person me-2 text-muted"></i> My Profile & Security
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item py-2 text-danger" href="<?= BASE_URL ?>/logout.php">
                        <i class="bi bi-box-arrow-right me-2"></i> Log Out
                    </a>
                </li>
            </ul>
        </div>
    </div>
</header>
