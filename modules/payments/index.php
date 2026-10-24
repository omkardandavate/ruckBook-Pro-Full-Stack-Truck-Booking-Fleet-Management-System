<?php
/**
 * TruckBook Pro - Payments Transaction Ledger (Module J - Listing)
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
$methodFilter = trim($_GET['method'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($currentRole === 'customer' && $customerRecord) {
    $whereClauses[] = "p.customer_id = ?";
    $params[] = (int)$customerRecord['id'];
}

if ($search !== '') {
    $whereClauses[] = "(p.payment_number LIKE ? OR p.transaction_reference LIKE ? OR c.company_name LIKE ? OR inv.invoice_number LIKE ?)";
    $sw = "%{$search}%";
    $params = array_merge($params, [$sw, $sw, $sw, $sw]);
}

if ($methodFilter !== '') {
    $whereClauses[] = "p.payment_method = ?";
    $params[] = $methodFilter;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

// Count Total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM payments p
    JOIN customers c ON p.customer_id = c.id
    JOIN invoices inv ON p.invoice_id = inv.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Records
$sql = "
    SELECT p.*, c.company_name, inv.invoice_number, inv.total_amount AS invoice_total,
           u.name AS recorder_name
    FROM payments p
    JOIN customers c ON p.customer_id = c.id
    JOIN invoices inv ON p.invoice_id = inv.id
    JOIN users u ON p.recorded_by = u.id
    {$whereSql}
    ORDER BY p.payment_date DESC, p.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$methods = [
    'bank_transfer' => 'ACH / Wire Transfer',
    'credit_card' => 'Credit Card',
    'cheque' => 'Company Cheque',
    'upi' => 'UPI / Instant Payment',
    'cash' => 'Cash Voucher',
    'other' => 'Other Payment'
];
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Payments & Cash Ledger</h2>
        <p class="text-muted mb-0">Record of cleared client payments, bank wires, and invoice reconciliations.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasAnyRole(['super_admin', 'transport_manager'])): ?>
            <a href="<?= BASE_URL ?>/modules/payments/record.php" class="btn btn-primary">
                <i class="bi bi-credit-card-2-front-fill me-1"></i> Record New Payment
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
                           placeholder="Search payment #, ref, customer, invoice..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="method" class="form-select">
                    <option value="">All Payment Methods</option>
                    <?php foreach ($methods as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $methodFilter === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-light border w-100">Filter</button>
                <?php if ($search !== '' || $methodFilter !== ''): ?>
                    <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Payment #</th>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th>Payment Date</th>
                    <th>Method</th>
                    <th>Transaction Reference</th>
                    <th>Amount Cleared</th>
                    <th>Recorded By</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-credit-card display-6 d-block mb-2 text-light"></i>
                            No payments found matching the search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="fw-bold text-primary">
                                <a href="receipt.php?id=<?= $p['id'] ?>" class="text-decoration-none">
                                    <?= e($p['payment_number']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $p['invoice_id'] ?>" class="text-dark fw-semibold text-decoration-none">
                                    <?= e($p['invoice_number']) ?>
                                </a>
                            </td>
                            <td><?= e($p['company_name']) ?></td>
                            <td><?= formatDate($p['payment_date']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= $methods[$p['payment_method']] ?? $p['payment_method'] ?></span></td>
                            <td class="font-monospace small"><?= e($p['transaction_reference']) ?></td>
                            <td class="fw-bold fs-6 text-success"><?= formatCurrency($p['amount']) ?></td>
                            <td><?= e($p['recorder_name']) ?></td>
                            <td class="text-end">
                                <a href="receipt.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-light border" title="Print Official Receipt">
                                    <i class="bi bi-printer"></i>
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
            <span class="text-muted small">Showing <?= $offset + 1 ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> payments</span>
            <nav>
                <ul class="pagination pagination-sm m-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&method=<?= urlencode($methodFilter) ?>">
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
