<?php
/**
 * TruckBook Pro - Driver Management (Module E - Listing & Roster)
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
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(d.name LIKE ? OR d.phone LIKE ? OR d.licence_number LIKE ? OR d.email LIKE ?)";
    $searchWildcard = "%{$search}%";
    $params = array_merge($params, [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard]);
}

if ($statusFilter !== '') {
    $whereClauses[] = "d.status = ?";
    $params[] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM drivers d {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Drivers
$sql = "
    SELECT d.*,
           (SELECT COUNT(*) FROM trips t WHERE t.driver_id = d.id) AS total_trips,
           (SELECT t.trip_number FROM trips t WHERE t.driver_id = d.id AND t.delivery_status != 'delivered' LIMIT 1) AS active_trip_number
    FROM drivers d
    {$whereSql}
    ORDER BY d.status ASC, d.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$drivers = $stmt->fetchAll();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Driver Roster & Licensing</h2>
        <p class="text-muted mb-0">Monitor commercial drivers, license validities, and live journey dispatches.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/drivers/create.php" class="btn btn-primary">
            <i class="bi bi-person-plus-fill me-1"></i> Onboard New Driver
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
                           placeholder="Search by driver name, license number, phone..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available for Trip</option>
                    <option value="on_trip" <?= $statusFilter === 'on_trip' ? 'selected' : '' ?>>On Trip</option>
                    <option value="on_leave" <?= $statusFilter === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
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

<!-- Drivers Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Driver Name</th>
                    <th>Contact Phone & Email</th>
                    <th>Commercial Licence #</th>
                    <th>Licence Expiry</th>
                    <th>Experience</th>
                    <th>Trips</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($drivers)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-person-badge display-6 d-block mb-2 text-light"></i>
                            No drivers found matching the search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($drivers as $d): ?>
                        <?php
                            $today = date('Y-m-d');
                            $isExpired = $d['licence_expiry'] < $today;
                        ?>
                        <tr>
                            <td>
                                <a href="view.php?id=<?= $d['id'] ?>" class="fw-bold text-dark text-decoration-none hover-primary">
                                    <?= e($d['name']) ?>
                                </a>
                                <?php if ($d['user_id']): ?>
                                    <span class="badge bg-light text-primary border ms-1" title="Has Web Login Access"><i class="bi bi-key-fill"></i> Portal</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><i class="bi bi-telephone text-muted me-1 small"></i> <?= e($d['phone']) ?></div>
                                <?php if ($d['email']): ?>
                                    <div class="text-muted small"><i class="bi bi-envelope text-muted me-1 small"></i> <?= e($d['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace"><?= e($d['licence_number']) ?></span>
                            </td>
                            <td>
                                <strong class="<?= $isExpired ? 'text-danger' : 'text-dark' ?>">
                                    <?= formatDate($d['licence_expiry']) ?>
                                </strong>
                                <?php if ($isExpired): ?>
                                    <div class="badge bg-danger-subtle text-danger border border-danger-subtle small d-block mt-1">Expired</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e((string)$d['experience_years']) ?> Years
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= number_format($d['total_trips']) ?> Trips</span>
                                <?php if ($d['active_trip_number']): ?>
                                    <div class="small text-primary mt-1 fw-semibold"><i class="bi bi-geo-alt"></i> <?= e($d['active_trip_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= renderStatusBadge($d['status']) ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="view.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-light border" title="View Profile">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-light border" title="Edit Driver">
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> drivers</span>
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
