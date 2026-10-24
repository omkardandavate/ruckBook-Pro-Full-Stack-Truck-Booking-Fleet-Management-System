<?php
/**
 * TruckBook Pro - Freight Requests Management (Module F - Listing)
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

// Search and Filters
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($currentRole === 'customer' && $customerRecord) {
    $whereClauses[] = "fr.customer_id = ?";
    $params[] = (int)$customerRecord['id'];
}

if ($search !== '') {
    $whereClauses[] = "(fr.request_reference LIKE ? OR fr.cargo_description LIKE ? OR fr.pickup_city LIKE ? OR fr.delivery_city LIKE ? OR c.company_name LIKE ?)";
    $sw = "%{$search}%";
    $params = array_merge($params, [$sw, $sw, $sw, $sw, $sw]);
}

if ($statusFilter !== '') {
    $whereClauses[] = "fr.status = ?";
    $params[] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM freight_requests fr
    JOIN customers c ON fr.customer_id = c.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT fr.*, c.company_name, c.contact_person,
           (SELECT q.id FROM quotations q WHERE q.freight_request_id = fr.id LIMIT 1) AS quote_id,
           (SELECT q.quotation_number FROM quotations q WHERE q.freight_request_id = fr.id LIMIT 1) AS quotation_number,
           (SELECT q.total_amount FROM quotations q WHERE q.freight_request_id = fr.id LIMIT 1) AS quote_amount
    FROM freight_requests fr
    JOIN customers c ON fr.customer_id = c.id
    {$whereSql}
    ORDER BY fr.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Freight Requests</h2>
        <p class="text-muted mb-0">Customer freight shipment requests awaiting quotations or dispatch.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/freight_requests/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Submit Freight Request
        </a>
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
                           placeholder="Search reference, cargo, route, customer..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending Review</option>
                    <option value="quoted" <?= $statusFilter === 'quoted' ? 'selected' : '' ?>>Quotation Sent</option>
                    <option value="booked" <?= $statusFilter === 'booked' ? 'selected' : '' ?>>Booked</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
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

<!-- Requests Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Route</th>
                    <th>Cargo Description</th>
                    <th>Weight</th>
                    <th>Required Truck</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-box-seam display-6 d-block mb-2 text-light"></i>
                            No freight requests found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td class="fw-bold text-primary">
                                <a href="view.php?id=<?= $r['id'] ?>" class="text-decoration-none">
                                    <?= e($r['request_reference']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($r['company_name']) ?></div>
                                <small class="text-muted"><?= e($r['contact_person']) ?></small>
                            </td>
                            <td>
                                <?= e($r['pickup_city']) ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= e($r['delivery_city']) ?>
                            </td>
                            <td class="text-truncate" style="max-width: 220px;" title="<?= e($r['cargo_description']) ?>">
                                <?= e($r['cargo_description']) ?>
                            </td>
                            <td class="fw-bold"><?= number_format((float)$r['cargo_weight_tonnes'], 1) ?> T</td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= ucwords(str_replace('_', ' ', $r['truck_type_required'])) ?></span>
                            </td>
                            <td><?= formatDate($r['preferred_date']) ?></td>
                            <td><?= renderStatusBadge($r['status']) ?></td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-light border" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($r['status'] === 'pending' && hasAnyRole(['super_admin', 'transport_manager'])): ?>
                                        <a href="<?= BASE_URL ?>/modules/quotations/create.php?request_id=<?= $r['id'] ?>" class="btn btn-sm btn-primary" title="Calculate & Generate Quote">
                                            <i class="bi bi-calculator"></i> Quote
                                        </a>
                                    <?php elseif ($r['quote_id']): ?>
                                        <a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $r['quote_id'] ?>" class="btn btn-sm btn-outline-info" title="View Quotation">
                                            <i class="bi bi-file-earmark-text"></i>
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> requests</span>
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
