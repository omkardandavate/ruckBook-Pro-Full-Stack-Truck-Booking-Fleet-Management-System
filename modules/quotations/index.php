<?php
/**
 * TruckBook Pro - Quotations Management (Module F - Listing)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/header.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer']);

$pdo = getDbConnection();
$currentRole = currentRole();
$userId = currentUserId();

// Customer Context
$customerRecord = null;
if ($currentRole === 'customer') {
    $cStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
    $cStmt->execute([$userId]);
    $customerRecord = $cStmt->fetch();
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($currentRole === 'customer' && $customerRecord) {
    $whereClauses[] = "q.customer_id = ?";
    $params[] = (int)$customerRecord['id'];
}

if ($search !== '') {
    $whereClauses[] = "(q.quotation_number LIKE ? OR c.company_name LIKE ? OR fr.pickup_city LIKE ? OR fr.delivery_city LIKE ?)";
    $sw = "%{$search}%";
    $params = array_merge($params, [$sw, $sw, $sw, $sw]);
}

if ($statusFilter !== '') {
    $whereClauses[] = "q.status = ?";
    $params[] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM quotations q
    JOIN customers c ON q.customer_id = c.id
    JOIN freight_requests fr ON q.freight_request_id = fr.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT q.*, c.company_name, fr.request_reference, fr.pickup_city, fr.delivery_city, fr.cargo_description,
           (SELECT b.id FROM bookings b WHERE b.quotation_id = q.id LIMIT 1) AS booking_id
    FROM quotations q
    JOIN customers c ON q.customer_id = c.id
    JOIN freight_requests fr ON q.freight_request_id = fr.id
    {$whereSql}
    ORDER BY q.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$quotations = $stmt->fetchAll();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Freight Quotations</h2>
        <p class="text-muted mb-0">Review computed tariffs, fuel surcharges, driver allowances, and quote statuses.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasAnyRole(['super_admin', 'transport_manager'])): ?>
            <a href="<?= BASE_URL ?>/modules/freight_requests/index.php" class="btn btn-outline-primary">
                <i class="bi bi-box-seam me-1"></i> Pending Requests
            </a>
            <a href="<?= BASE_URL ?>/modules/quotations/create.php" class="btn btn-primary">
                <i class="bi bi-calculator me-1"></i> New Tariff Quote
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Bar -->
<div class="content-card mb-4">
    <div class="content-card-body p-3">
        <form method="GET" action="index.php" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="Search quote number, customer, route..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent / Pending Decision</option>
                    <option value="accepted" <?= $statusFilter === 'accepted' ? 'selected' : '' ?>>Accepted by Client</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-light border w-100">Filter</button>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Quotations Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Quote #</th>
                    <th>Customer</th>
                    <th>Route</th>
                    <th>Distance</th>
                    <th>Subtotal</th>
                    <th>Tax (18%)</th>
                    <th>Total Quote</th>
                    <th>Valid Until</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($quotations)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5 text-muted">
                            <i class="bi bi-file-earmark-text display-6 d-block mb-2 text-light"></i>
                            No quotations found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($quotations as $q): ?>
                        <tr>
                            <td class="fw-bold text-primary">
                                <a href="view.php?id=<?= $q['id'] ?>" class="text-decoration-none">
                                    <?= e($q['quotation_number']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($q['company_name']) ?></div>
                                <small class="text-muted">Ref: <?= e($q['request_reference']) ?></small>
                            </td>
                            <td><?= e($q['pickup_city']) ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= e($q['delivery_city']) ?></td>
                            <td><?= number_format((float)$q['estimated_distance_km']) ?> KM</td>
                            <td><?= formatCurrency($q['subtotal']) ?></td>
                            <td class="text-muted"><?= formatCurrency($q['tax_amount']) ?></td>
                            <td class="fw-bold fs-6 text-dark"><?= formatCurrency($q['total_amount']) ?></td>
                            <td><?= formatDate($q['valid_until']) ?></td>
                            <td><?= renderStatusBadge($q['status']) ?></td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="view.php?id=<?= $q['id'] ?>" class="btn btn-sm btn-light border" title="View Full Quote">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($q['status'] === 'accepted' && hasAnyRole(['super_admin', 'transport_manager', 'operator']) && empty($q['booking_id'])): ?>
                                        <a href="<?= BASE_URL ?>/modules/bookings/create.php?quote_id=<?= $q['id'] ?>" class="btn btn-sm btn-success" title="Convert to Confirmed Booking">
                                            <i class="bi bi-calendar2-check"></i> Book
                                        </a>
                                    <?php endif; ?>
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> quotations</span>
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
