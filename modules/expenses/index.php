<?php
/**
 * TruckBook Pro - Trip Expense Management (Module I - Listing & Approvals)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/header.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'driver']);

$pdo = getDbConnection();
$currentRole = currentRole();
$userId = currentUserId();

$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$tripFilter = (int)($_GET['trip_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($currentRole === 'driver') {
    $whereClauses[] = "te.created_by = ?";
    $params[] = $userId;
}

if ($tripFilter > 0) {
    $whereClauses[] = "te.trip_id = ?";
    $params[] = $tripFilter;
}

if ($categoryFilter !== '') {
    $whereClauses[] = "te.category = ?";
    $params[] = $categoryFilter;
}

if ($statusFilter !== '') {
    $whereClauses[] = "te.status = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $whereClauses[] = "(te.description LIKE ? OR te.receipt_reference LIKE ? OR t.trip_number LIKE ?)";
    $sw = "%{$search}%";
    $params = array_merge($params, [$sw, $sw, $sw]);
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM trip_expenses te
    JOIN trips t ON te.trip_id = t.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT te.*, t.trip_number, b.booking_number, b.pickup_city, b.delivery_city,
           u.name AS creator_name,
           appr.name AS approver_name
    FROM trip_expenses te
    JOIN trips t ON te.trip_id = t.id
    JOIN bookings b ON t.booking_id = b.id
    JOIN users u ON te.created_by = u.id
    LEFT JOIN users appr ON te.approved_by = appr.id
    {$whereSql}
    ORDER BY te.expense_date DESC, te.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// KPI Summary Figures
$kpiTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM trip_expenses WHERE status = 'approved'")->fetchColumn();
$kpiFuel = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM trip_expenses WHERE category = 'fuel' AND status = 'approved'")->fetchColumn();
$kpiTolls = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM trip_expenses WHERE category = 'toll' AND status = 'approved'")->fetchColumn();
$kpiPending = (int)$pdo->query("SELECT COUNT(*) FROM trip_expenses WHERE status = 'pending'")->fetchColumn();

$categories = [
    'fuel' => 'Diesel Fuel',
    'toll' => 'Highway Tolls',
    'driver_allowance' => 'Driver Allowance',
    'loading_unloading' => 'Loading / Unloading',
    'repairs' => 'Repairs & Spares',
    'parking' => 'Parking & Weighbridge',
    'other' => 'Other Incidental'
];
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Trip Expenses & Operational Costs</h2>
        <p class="text-muted mb-0">Record and approve en-route diesel, tolls, driver staging, and maintenance expenditures.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/expenses/create.php" class="btn btn-primary">
            <i class="bi bi-receipt me-1"></i> Record New Expense
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Total Approved Expenses</div>
                    <div class="stat-value text-danger"><?= formatCurrency($kpiTotal) ?></div>
                    <small class="text-muted">Across all operational trips</small>
                </div>
                <div class="stat-icon danger"><i class="bi bi-receipt-cutoff"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Diesel Fuel Spend</div>
                    <div class="stat-value"><?= formatCurrency($kpiFuel) ?></div>
                    <small class="text-muted">Fleet diesel fuel purchases</small>
                </div>
                <div class="stat-icon primary"><i class="bi bi-fuel-pump-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Tolls & Turnpikes</div>
                    <div class="stat-value"><?= formatCurrency($kpiTolls) ?></div>
                    <small class="text-muted">Interstate toll plaza fees</small>
                </div>
                <div class="stat-icon info"><i class="bi bi-signpost-split-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Pending Approval</div>
                    <div class="stat-value text-warning"><?= number_format($kpiPending) ?></div>
                    <small class="text-muted">Driver receipts awaiting audit</small>
                </div>
                <div class="stat-icon warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>
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
                           placeholder="Search description, receipt ref, trip..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $categoryFilter === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending Approval</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-light border w-100">Filter</button>
                <?php if ($search !== '' || $categoryFilter !== '' || $statusFilter !== ''): ?>
                    <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Expenses Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Trip Ref</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Receipt Reference</th>
                    <th>Amount</th>
                    <th>Recorded By</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-receipt display-6 d-block mb-2 text-light"></i>
                            No trip expenses found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td><?= formatDate($exp['expense_date']) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/trips/view.php?id=<?= $exp['trip_id'] ?>" class="fw-bold text-primary text-decoration-none">
                                    <?= e($exp['trip_number']) ?>
                                </a>
                                <div class="text-muted small"><?= e($exp['pickup_city']) ?> &rarr; <?= e($exp['delivery_city']) ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= $categories[$exp['category']] ?? $exp['category'] ?></span></td>
                            <td>
                                <div class="text-truncate" style="max-width: 220px;" title="<?= e($exp['description']) ?>">
                                    <?= e($exp['description']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($exp['receipt_reference']): ?>
                                    <span class="badge bg-light text-muted border"><?= e($exp['receipt_reference']) ?></span>
                                <?php endif; ?>
                                <?php if ($exp['receipt_file_path']): ?>
                                    <a href="<?= BASE_URL ?>/<?= e($exp['receipt_file_path']) ?>" target="_blank" class="ms-1 text-primary" title="View Receipt File">
                                        <i class="bi bi-paperclip"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold fs-6 text-dark"><?= formatCurrency($exp['amount']) ?></td>
                            <td>
                                <div><?= e($exp['creator_name']) ?></div>
                                <?php if ($exp['approver_name']): ?>
                                    <small class="text-muted">Appr: <?= e($exp['approver_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= renderStatusBadge($exp['status']) ?></td>
                            <td class="text-end">
                                <?php if ($exp['status'] === 'pending' && hasAnyRole(['super_admin', 'transport_manager'])): ?>
                                    <form method="POST" action="approve.php" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-sm btn-success" title="Approve Expense">
                                            <i class="bi bi-check2"></i>
                                        </button>
                                    </form>
                                    <form method="POST" action="approve.php" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject Expense">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> expenses</span>
            <nav>
                <ul class="pagination pagination-sm m-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($categoryFilter) ?>&status=<?= urlencode($statusFilter) ?>">
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
