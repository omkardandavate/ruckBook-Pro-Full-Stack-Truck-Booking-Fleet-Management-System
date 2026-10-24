<?php
/**
 * TruckBook Pro - Commercial Invoices Management (Module J - Listing)
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
    $whereClauses[] = "inv.customer_id = ?";
    $params[] = (int)$customerRecord['id'];
}

if ($search !== '') {
    $whereClauses[] = "(inv.invoice_number LIKE ? OR c.company_name LIKE ? OR b.booking_number LIKE ?)";
    $sw = "%{$search}%";
    $params = array_merge($params, [$sw, $sw, $sw]);
}

if ($statusFilter !== '') {
    $whereClauses[] = "inv.payment_status = ?";
    $params[] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN bookings b ON inv.booking_id = b.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT inv.*, c.company_name, b.booking_number, b.pickup_city, b.delivery_city
    FROM invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN bookings b ON inv.booking_id = b.id
    {$whereSql}
    ORDER BY inv.issued_date DESC, inv.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// KPI Figures
$kpiTotalBilled = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE payment_status != 'cancelled'")->fetchColumn();
$kpiTotalCollected = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE payment_status != 'cancelled'")->fetchColumn();
$kpiOutstanding = $kpiTotalBilled - $kpiTotalCollected;
$kpiUnpaidCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status IN ('unpaid', 'partially_paid')")->fetchColumn();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Invoices & Billing</h2>
        <p class="text-muted mb-0">Manage customer freight invoicing, tax reconciliation, and outstanding payment dues.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasAnyRole(['super_admin', 'transport_manager'])): ?>
            <a href="<?= BASE_URL ?>/modules/invoices/create.php" class="btn btn-primary">
                <i class="bi bi-file-earmark-plus me-1"></i> Generate Invoice
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Total Invoiced Billed</div>
                    <div class="stat-value"><?= formatCurrency($kpiTotalBilled) ?></div>
                    <small class="text-muted">Gross recognized freight billing</small>
                </div>
                <div class="stat-icon primary"><i class="bi bi-receipt"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Payments Collected</div>
                    <div class="stat-value text-success"><?= formatCurrency($kpiTotalCollected) ?></div>
                    <small class="text-muted">Cleared in bank ledger</small>
                </div>
                <div class="stat-icon success"><i class="bi bi-cash-stack"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Outstanding Receivables</div>
                    <div class="stat-value text-danger"><?= formatCurrency($kpiOutstanding) ?></div>
                    <small class="text-muted">Balance awaiting payment</small>
                </div>
                <div class="stat-icon danger"><i class="bi bi-exclamation-octagon-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Pending Invoices</div>
                    <div class="stat-value text-warning"><?= number_format($kpiUnpaidCount) ?></div>
                    <small class="text-muted">Unpaid or partially paid</small>
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
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="Search invoice #, customer, booking #..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Payment Statuses</option>
                    <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    <option value="partially_paid" <?= $statusFilter === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid (Cleared)</option>
                    <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
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

<!-- Invoices Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th>Booking / Route</th>
                    <th>Issued Date</th>
                    <th>Due Date</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance Due</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5 text-muted">
                            <i class="bi bi-receipt display-6 d-block mb-2 text-light"></i>
                            No invoices found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="fw-bold text-primary">
                                <a href="view.php?id=<?= $inv['id'] ?>" class="text-decoration-none">
                                    <?= e($inv['invoice_number']) ?>
                                </a>
                            </td>
                            <td><?= e($inv['company_name']) ?></td>
                            <td>
                                <div><?= e($inv['pickup_city']) ?> &rarr; <?= e($inv['delivery_city']) ?></div>
                                <small class="text-muted"><?= e($inv['booking_number']) ?></small>
                            </td>
                            <td><?= formatDate($inv['issued_date']) ?></td>
                            <td><?= formatDate($inv['due_date']) ?></td>
                            <td class="fw-bold"><?= formatCurrency($inv['total_amount']) ?></td>
                            <td class="text-success"><?= formatCurrency($inv['paid_amount']) ?></td>
                            <td class="fw-bold <?= $inv['balance_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                <?= formatCurrency($inv['balance_amount']) ?>
                            </td>
                            <td><?= renderStatusBadge($inv['payment_status']) ?></td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-light border" title="View Commercial Invoice">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($inv['payment_status'] !== 'paid' && hasAnyRole(['super_admin', 'transport_manager'])): ?>
                                        <a href="<?= BASE_URL ?>/modules/payments/record.php?invoice_id=<?= $inv['id'] ?>" class="btn btn-sm btn-success" title="Record Payment">
                                            <i class="bi bi-credit-card"></i> Pay
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> invoices</span>
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
