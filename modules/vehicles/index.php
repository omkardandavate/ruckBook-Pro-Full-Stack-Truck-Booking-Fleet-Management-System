<?php
/**
 * TruckBook Pro - Fleet & Vehicle Management (Module D - Listing & Status)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/header.php';

requireRole(['super_admin', 'transport_manager', 'operator']);

$pdo = getDbConnection();

// Filters & Search
$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(registration_number LIKE ? OR model LIKE ?)";
    $searchWildcard = "%{$search}%";
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
}

if ($typeFilter !== '') {
    $whereClauses[] = "truck_type = ?";
    $params[] = $typeFilter;
}

if ($statusFilter !== '') {
    $whereClauses[] = "status = ?";
    $params[] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT v.*, 
           (SELECT COUNT(*) FROM trips t WHERE t.vehicle_id = v.id) AS total_trips,
           (SELECT t.trip_number FROM trips t WHERE t.vehicle_id = v.id AND t.delivery_status != 'delivered' LIMIT 1) AS active_trip_number
    FROM vehicles v
    {$whereSql}
    ORDER BY v.status ASC, v.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

// Vehicle Types Helper
$truckTypes = [
    'flatbed' => 'Flatbed',
    'container' => 'Container',
    'refrigerated' => 'Refrigerated',
    'tanker' => 'Tanker',
    'trailer' => 'Trailer',
    'box_truck' => 'Box Truck'
];
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Fleet & Vehicle Management</h2>
        <p class="text-muted mb-0">Track commercial trucks, capacities, inspection validity, and active dispatches.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/vehicles/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Register New Truck
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="content-card mb-4">
    <div class="content-card-body p-3">
        <form method="GET" action="index.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="Search by registration number, model..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="type" class="form-select">
                    <option value="">All Truck Types</option>
                    <?php foreach ($truckTypes as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $typeFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="on_trip" <?= $statusFilter === 'on_trip' ? 'selected' : '' ?>>On Trip</option>
                    <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-light border w-100">Filter</button>
                <?php if ($search !== '' || $typeFilter !== '' || $statusFilter !== ''): ?>
                    <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Vehicles Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Registration #</th>
                    <th>Type & Model</th>
                    <th>Capacity</th>
                    <th>Odometer</th>
                    <th>Inspection Expirations</th>
                    <th>Trips</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vehicles)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-truck display-6 d-block mb-2 text-light"></i>
                            No trucks found matching the criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vehicles as $v): ?>
                        <?php
                            $today = date('Y-m-d');
                            $insuranceExpired = $v['insurance_expiry'] < $today;
                            $permitExpired = $v['permit_expiry'] < $today;
                            $fitnessExpired = $v['fitness_expiry'] < $today;
                            $hasComplianceIssue = $insuranceExpired || $permitExpired || $fitnessExpired;
                        ?>
                        <tr>
                            <td>
                                <a href="view.php?id=<?= $v['id'] ?>" class="fw-bold text-dark text-decoration-none hover-primary">
                                    <?= e($v['registration_number']) ?>
                                </a>
                                <div class="text-muted small">Year: <?= e((string)$v['make_year']) ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($v['model']) ?></div>
                                <span class="badge bg-light text-dark border"><?= $truckTypes[$v['truck_type']] ?? $v['truck_type'] ?></span>
                            </td>
                            <td>
                                <div class="fw-bold"><?= number_format((float)$v['capacity_tonnes'], 1) ?> Tonnes</div>
                            </td>
                            <td>
                                <div><?= number_format((int)$v['current_odometer']) ?> KM</div>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-1 small">
                                    <span>
                                        Insurance: 
                                        <strong class="<?= $insuranceExpired ? 'text-danger' : 'text-muted' ?>">
                                            <?= formatDate($v['insurance_expiry']) ?>
                                            <?= $insuranceExpired ? '<i class="bi bi-exclamation-triangle-fill text-danger"></i>' : '' ?>
                                        </strong>
                                    </span>
                                    <span>
                                        Permit: 
                                        <strong class="<?= $permitExpired ? 'text-danger' : 'text-muted' ?>">
                                            <?= formatDate($v['permit_expiry']) ?>
                                            <?= $permitExpired ? '<i class="bi bi-exclamation-triangle-fill text-danger"></i>' : '' ?>
                                        </strong>
                                    </span>
                                    <span>
                                        Fitness: 
                                        <strong class="<?= $fitnessExpired ? 'text-danger' : 'text-muted' ?>">
                                            <?= formatDate($v['fitness_expiry']) ?>
                                            <?= $fitnessExpired ? '<i class="bi bi-exclamation-triangle-fill text-danger"></i>' : '' ?>
                                        </strong>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= number_format($v['total_trips']) ?> Trips</span>
                                <?php if ($v['active_trip_number']): ?>
                                    <div class="small text-primary mt-1 fw-semibold"><i class="bi bi-play-circle"></i> <?= e($v['active_trip_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= renderStatusBadge($v['status']) ?>
                                <?php if ($hasComplianceIssue): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle d-block mt-1">Doc Expired</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="view.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-light border" title="View Vehicle Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-light border" title="Edit Vehicle">
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> vehicles</span>
            <nav>
                <ul class="pagination pagination-sm m-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>">
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
