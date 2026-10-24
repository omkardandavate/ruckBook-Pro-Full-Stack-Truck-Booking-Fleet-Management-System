<?php
/**
 * TruckBook Pro - Bookings Management (Module G - Listing)
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
    $whereClauses[] = "b.customer_id = ?";
    $params[] = (int)$customerRecord['id'];
}

if ($search !== '') {
    $whereClauses[] = "(b.booking_number LIKE ? OR c.company_name LIKE ? OR b.pickup_city LIKE ? OR b.delivery_city LIKE ? OR v.registration_number LIKE ? OR d.name LIKE ?)";
    $sw = "%{$search}%";
    $params = array_merge($params, [$sw, $sw, $sw, $sw, $sw, $sw]);
}

if ($statusFilter !== '') {
    $whereClauses[] = "b.status = ?";
    $params[] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM bookings b
    JOIN customers c ON b.customer_id = c.id
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN drivers d ON b.driver_id = d.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT b.*, c.company_name, v.registration_number, v.truck_type, d.name AS driver_name, d.phone AS driver_phone,
           (SELECT t.id FROM trips t WHERE t.booking_id = b.id LIMIT 1) AS trip_id,
           (SELECT t.trip_number FROM trips t WHERE t.booking_id = b.id LIMIT 1) AS trip_number,
           (SELECT t.delivery_status FROM trips t WHERE t.booking_id = b.id LIMIT 1) AS delivery_status
    FROM bookings b
    JOIN customers c ON b.customer_id = c.id
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN drivers d ON b.driver_id = d.id
    {$whereSql}
    ORDER BY b.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Truck Bookings</h2>
        <p class="text-muted mb-0">Confirmed freight bookings, assigned vehicles, drivers, and dispatch progress.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($currentRole !== 'customer'): ?>
            <a href="<?= BASE_URL ?>/modules/bookings/create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Create Booking
            </a>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/modules/freight_requests/create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Request Freight Booking
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
                           placeholder="Search booking #, customer, truck, driver, route..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Booking Statuses</option>
                    <option value="pending_assignment" <?= $statusFilter === 'pending_assignment' ? 'selected' : '' ?>>Pending Assignment</option>
                    <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Transit / Progress</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
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

<!-- Bookings Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Booking #</th>
                    <th>Customer</th>
                    <th>Route</th>
                    <th>Cargo & Weight</th>
                    <th>Vehicle & Driver</th>
                    <th>Planned Date</th>
                    <th>Agreed Rate</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookings)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-calendar2-check display-6 d-block mb-2 text-light"></i>
                            No freight bookings found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <td class="fw-bold text-primary">
                                <a href="view.php?id=<?= $b['id'] ?>" class="text-decoration-none">
                                    <?= e($b['booking_number']) ?>
                                </a>
                                <?php if ($b['trip_number']): ?>
                                    <div class="small text-muted font-monospace"><i class="bi bi-truck"></i> <?= e($b['trip_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($b['company_name']) ?></td>
                            <td><?= e($b['pickup_city']) ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= e($b['delivery_city']) ?></td>
                            <td>
                                <div class="text-truncate" style="max-width: 180px;" title="<?= e($b['cargo_description']) ?>">
                                    <?= e($b['cargo_description']) ?>
                                </div>
                                <small class="text-muted"><?= number_format((float)$b['cargo_weight_tonnes'], 1) ?> Tonnes</small>
                            </td>
                            <td>
                                <?php if ($b['registration_number']): ?>
                                    <div class="fw-semibold"><?= e($b['registration_number']) ?></div>
                                    <small class="text-muted"><i class="bi bi-person"></i> <?= e($b['driver_name']) ?></small>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                        <i class="bi bi-exclamation-triangle"></i> Unassigned
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($b['planned_pickup_date']) ?></td>
                            <td class="fw-bold"><?= formatCurrency($b['agreed_rate']) ?></td>
                            <td><?= renderStatusBadge($b['status']) ?></td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="view.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-light border" title="View Booking Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($b['trip_id']): ?>
                                        <a href="<?= BASE_URL ?>/modules/trips/view.php?id=<?= $b['trip_id'] ?>" class="btn btn-sm btn-outline-primary" title="Live Trip Tracking">
                                            <i class="bi bi-geo-alt"></i>
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> bookings</span>
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
