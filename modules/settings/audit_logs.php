<?php
/**
 * TruckBook Pro - System Audit Trail & Security Logs (Module M)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/header.php';

requireRole(['super_admin']);

$pdo = getDbConnection();

$search = trim($_GET['search'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(al.action LIKE ? OR al.entity_type LIKE ? OR u.name LIKE ? OR al.ip_address LIKE ?)";
    $sw = "%{$search}%";
    $params = array_merge($params, [$sw, $sw, $sw, $sw]);
}

if ($actionFilter !== '') {
    $whereClauses[] = "al.action = ?";
    $params[] = $actionFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT al.*, u.name AS user_name, u.email AS user_email
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    {$whereSql}
    ORDER BY al.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">System Security Audit Trail</h2>
        <p class="text-muted mb-0">Immutable chronological ledger of administrative operations, logins, and transactional modifications.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/settings/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Settings
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="content-card mb-4">
    <div class="content-card-body p-3">
        <form method="GET" action="audit_logs.php" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="Search action, entity, user, IP..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <input type="text" name="action" class="form-control" placeholder="Filter by action code..." value="<?= e($actionFilter) ?>">
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-light border w-100">Filter</button>
                <?php if ($search !== '' || $actionFilter !== ''): ?>
                    <a href="audit_logs.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Audit Log Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Target Entity</th>
                    <th>Entity ID</th>
                    <th>Details / Metadata</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-shield-check display-6 d-block mb-2 text-light"></i>
                            No audit log records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="text-nowrap small text-muted">
                                <?= formatDateTime($l['created_at']) ?>
                            </td>
                            <td>
                                <?php if ($l['user_name']): ?>
                                    <strong><?= e($l['user_name']) ?></strong>
                                    <div class="text-muted small"><?= e($l['user_email']) ?></div>
                                <?php else: ?>
                                    <span class="text-muted italic">System / Console</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace"><?= e($l['action']) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= e($l['entity_type']) ?></span>
                            </td>
                            <td>
                                <?= $l['entity_id'] ? '#' . $l['entity_id'] : '—' ?>
                            </td>
                            <td>
                                <?php if ($l['new_values']): ?>
                                    <pre class="m-0 small text-truncate" style="max-width: 280px;"><?= e($l['new_values']) ?></pre>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small font-monospace text-muted">
                                <?= e($l['ip_address'] ?: '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="content-card-footer p-3 border-top d-flex align-items-center justify-content-between">
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> audit logs</span>
            <nav>
                <ul class="pagination pagination-sm m-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&action=<?= urlencode($actionFilter) ?>">
                                <?= $p ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
