<?php
/**
 * TruckBook Pro - Trip Management & Tracking (Module H - Listing)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/header.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer', 'driver']);

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

// Driver Context
$driverRecord = null;
if ($currentRole === 'driver') {
    $dStmt = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ? LIMIT 1");
    $dStmt->execute([$userId]);
    $driverRecord = $dStmt->fetch();
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

if ($currentRole === 'driver' && $driverRecord) {
    $whereClauses[] = "t.driver_id = ?";
    $params[] = (int)$driverRecord['id'];
}

if ($search !== '') {
    $whereClauses[] = "(t.trip_number LIKE ? OR b.booking_number LIKE ? OR b.pickup_city LIKE ? OR b.delivery_city LIKE ? OR v.registration_number LIKE ? OR d.name LIKE ?)";
    $sw = "%{$search}%";
    $params = array_merge($params, [$sw, $sw, $sw, $sw, $sw, $sw]);
}

if ($statusFilter !== '') {
    $whereClauses[] = "t.delivery_status = ?";
    $params[] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
    JOIN customers c ON b.customer_id = c.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT t.*, b.booking_number, b.pickup_city, b.delivery_city, b.cargo_description,
           v.registration_number, v.truck_type,
           d.name AS driver_name, d.phone AS driver_phone,
           c.company_name,
           (SELECT COALESCE(SUM(amount), 0) FROM trip_expenses te WHERE te.trip_id = t.id AND te.status = 'approved') AS total_expenses
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
    JOIN customers c ON b.customer_id = c.id
    {$whereSql}
    ORDER BY t.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$trips = $stmt->fetchAll();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Trips & Fleet Tracking</h2>
        <p class="text-muted mb-0">Live journey dispatches, milestone progressions, odometers, and delivery confirmations.</p>
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
                           placeholder="Search trip #, booking #, truck, driver, route..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Delivery Statuses</option>
                    <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                    <option value="dispatched" <?= $statusFilter === 'dispatched' ? 'selected' : '' ?>>Dispatched</option>
                    <option value="in_transit" <?= $statusFilter === 'in_transit' ? 'selected' : '' ?>>In Transit</option>
                    <option value="arrived" <?= $statusFilter === 'arrived' ? 'selected' : '' ?>>Arrived at Depot</option>
                    <option value="unloading" <?= $statusFilter === 'unloading' ? 'selected' : '' ?>>Unloading Cargo</option>
                    <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered (Completed)</option>
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

<!-- Trips Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Trip #</th>
                    <th>Booking / Customer</th>
                    <th>Route</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Departure</th>
                    <th>Distance</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($trips)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-truck display-6 d-block mb-2 text-light"></i>
                            No trips found matching the criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($trips as $t): ?>
                        <tr>
                            <td class="fw-bold text-primary">
                                <a href="view.php?id=<?= $t['id'] ?>" class="text-decoration-none">
                                    <?= e($t['trip_number']) ?>
                                </a>
                                <?php if ($t['proof_of_delivery_path']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-1" title="Proof of Delivery Captured">
                                        <i class="bi bi-check-circle"></i> POD
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($t['company_name']) ?></div>
                                <span class="badge bg-light text-muted border"><?= e($t['booking_number']) ?></span>
                            </td>
                            <td>
                                <div><?= e($t['pickup_city']) ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= e($t['delivery_city']) ?></div>
                                <small class="text-muted text-truncate d-inline-block" style="max-width: 150px;" title="<?= e($t['cargo_description']) ?>">
                                    <?= e($t['cargo_description']) ?>
                                </small>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($t['registration_number']) ?></div>
                                <small class="text-muted"><?= ucwords(str_replace('_', ' ', $t['truck_type'])) ?></small>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($t['driver_name']) ?></div>
                                <small class="text-muted"><i class="bi bi-telephone"></i> <?= e($t['driver_phone']) ?></small>
                            </td>
                            <td>
                                <?= formatDateTime($t['actual_departure'] ?: $t['planned_departure']) ?>
                            </td>
                            <td>
                                <?= number_format((float)$t['distance_km']) ?> KM
                            </td>
                            <td>
                                <?= renderStatusBadge($t['delivery_status']) ?>
                            </td>
                            <td class="text-end">
                                <a href="view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-light border" title="Live Trip Operations">
                                    <i class="bi bi-eye"></i> Details
                                </a>
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> trips</span>
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
