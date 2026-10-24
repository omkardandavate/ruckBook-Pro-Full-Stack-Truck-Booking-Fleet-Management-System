<?php
/**
 * TruckBook Pro - Customer Management (Module C - Listing & Filtering)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/header.php';

// Only Admin, Transport Manager, and Staff can manage customers
requireRole(['super_admin', 'transport_manager', 'operator']);

$pdo = getDbConnection();

// Filters & Search
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(c.company_name LIKE ? OR c.contact_person LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.city LIKE ?)";
    $searchWildcard = "%{$search}%";
    $params = array_merge($params, [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard]);
}

if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive'], true)) {
    $whereClauses[] = "c.status = ?";
    $params[] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records with Booking Counts & Total Spend
$sql = "
    SELECT c.*, 
           COUNT(DISTINCT b.id) AS total_bookings,
           COALESCE(SUM(inv.total_amount), 0) AS total_spend
    FROM customers c
    LEFT JOIN bookings b ON c.id = b.customer_id
    LEFT JOIN invoices inv ON c.id = inv.customer_id AND inv.payment_status != 'cancelled'
    {$whereSql}
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Customer Management</h2>
        <p class="text-muted mb-0">Manage registered freight clients, billing details, and booking histories.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/customers/create.php" class="btn btn-primary">
            <i class="bi bi-person-plus-fill me-1"></i> Add New Customer
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="content-card mb-4">
    <div class="content-card-body p-3">
        <form method="GET" action="index.php" class="row g-2 align-items-center">
            <div class="col-md-6 col-lg-5">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="Search by company, contact person, email, city..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3 col-lg-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                </select>
            </div>

            <div class="col-md-3 col-lg-4 d-flex gap-2">
                <button type="submit" class="btn btn-light border">
                    <i class="bi bi-filter me-1"></i> Filter
                </button>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Customer Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Customer / Company</th>
                    <th>Contact Person</th>
                    <th>Phone & Email</th>
                    <th>Location</th>
                    <th>Bookings</th>
                    <th>Total Spend</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-people display-6 d-block mb-2 text-light"></i>
                            No customers found matching the search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td>
                                <a href="view.php?id=<?= $c['id'] ?>" class="fw-bold text-dark text-decoration-none hover-primary">
                                    <?= e($c['company_name']) ?>
                                </a>
                                <?php if ($c['tax_gst_number']): ?>
                                    <div class="text-muted small">GST/Tax: <?= e($c['tax_gst_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($c['contact_person']) ?></td>
                            <td>
                                <div><i class="bi bi-telephone text-muted me-1 small"></i> <?= e($c['phone']) ?></div>
                                <div class="text-muted small"><i class="bi bi-envelope text-muted me-1 small"></i> <?= e($c['email']) ?></div>
                            </td>
                            <td>
                                <div><?= e($c['city']) ?>, <?= e($c['state']) ?></div>
                                <div class="text-muted small"><?= e($c['postal_code']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= number_format($c['total_bookings']) ?></span>
                            </td>
                            <td class="fw-bold text-dark">
                                <?= formatCurrency($c['total_spend']) ?>
                            </td>
                            <td>
                                <?= renderStatusBadge($c['status']) ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light border" title="View Profile">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light border" title="Edit Customer">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </div>
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
            <span class="text-muted small">
                Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> customers
            </span>
            <nav>
                <ul class="pagination pagination-sm m-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">
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
