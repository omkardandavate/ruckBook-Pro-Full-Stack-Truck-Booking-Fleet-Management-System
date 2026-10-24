<?php
/**
 * TruckBook Pro - View Commercial Invoice & Payment Reconciliation (Module J)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer']);

$pdo = getDbConnection();
$invoiceId = (int)($_GET['id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

$sql = "
    SELECT inv.*, 
           c.company_name, c.contact_person, c.phone AS customer_phone, c.email AS customer_email,
           c.address AS customer_address, c.city AS customer_city, c.state AS customer_state, c.postal_code AS customer_postal_code,
           c.tax_gst_number AS customer_tax_number, c.user_id AS customer_user_id,
           b.booking_number, b.pickup_city, b.delivery_city, b.cargo_description, b.cargo_weight_tonnes,
           u.name AS creator_name
    FROM invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN bookings b ON inv.booking_id = b.id
    JOIN users u ON inv.created_by = u.id
    WHERE inv.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    setFlash('danger', 'Invoice not found.');
    redirect('modules/invoices/index.php');
}

// Security: If Customer, verify ownership
if ($currentRole === 'customer' && (int)$invoice['customer_user_id'] !== $userId) {
    setFlash('danger', 'Unauthorized access to invoice.');
    redirect('dashboard.php');
}

// Fetch Invoice Items
$itemsStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$itemsStmt->execute([$invoiceId]);
$items = $itemsStmt->fetchAll();

// Fetch Recorded Payments Reconciled to this Invoice
$payStmt = $pdo->prepare("
    SELECT p.*, u.name AS recorder_name
    FROM payments p
    JOIN users u ON p.recorded_by = u.id
    WHERE p.invoice_id = ?
    ORDER BY p.payment_date ASC
");
$payStmt->execute([$invoiceId]);
$payments = $payStmt->fetchAll();

$pageTitle = 'Invoice ' . $invoice['invoice_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-print-none">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($invoice['invoice_number']) ?></h2>
            <?= renderStatusBadge($invoice['payment_status']) ?>
        </div>
        <p class="text-muted mb-0">Issued on <?= formatDate($invoice['issued_date']) ?> &bull; Due by <?= formatDate($invoice['due_date']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Invoices
        </a>
        <button type="button" class="btn btn-light border" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Invoice
        </button>

        <?php if ($invoice['payment_status'] !== 'paid' && hasAnyRole(['super_admin', 'transport_manager'])): ?>
            <a href="<?= BASE_URL ?>/modules/payments/record.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-success">
                <i class="bi bi-credit-card-2-front-fill me-1"></i> Record Payment
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Printable Commercial Invoice Card -->
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="content-card p-4 p-md-5">
            <!-- Header -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start border-bottom pb-4 mb-4 gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="brand-icon" style="width: 36px; height: 36px; font-size: 1.1rem;">
                            <i class="bi bi-truck"></i>
                        </div>
                        <h4 class="fw-bold m-0 brand-font"><?= getSetting('company_name', 'TruckBook Pro Logistics Inc.') ?></h4>
                    </div>
                    <div class="text-muted small">
                        <?= getSetting('company_address') ?><br>
                        Phone: <?= getSetting('company_phone') ?> | Email: <?= getSetting('company_email') ?><br>
                        Corporate Tax ID: <strong>US-EIN-98441209</strong>
                    </div>
                </div>

                <div class="text-sm-end">
                    <span class="badge bg-primary fs-6 px-3 py-2 mb-2">TAX INVOICE</span>
                    <div class="fw-bold fs-5 text-dark"><?= e($invoice['invoice_number']) ?></div>
                    <div class="text-muted small">Issued: <strong><?= formatDate($invoice['issued_date']) ?></strong></div>
                    <div class="text-muted small">Due Date: <strong><?= formatDate($invoice['due_date']) ?></strong></div>
                </div>
            </div>

            <!-- Billed To & Shipment Context -->
            <div class="row g-4 mb-4">
                <div class="col-sm-6">
                    <span class="text-muted small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Billed To (Customer)</span>
                    <h5 class="fw-bold text-dark mb-1"><?= e($invoice['company_name']) ?></h5>
                    <div class="text-muted small">
                        Attn: <?= e($invoice['contact_person']) ?><br>
                        <?= e($invoice['customer_address']) ?><br>
                        <?= e($invoice['customer_city']) ?>, <?= e($invoice['customer_state']) ?> - <?= e($invoice['customer_postal_code']) ?><br>
                        Phone: <?= e($invoice['customer_phone']) ?> | Email: <?= e($invoice['customer_email']) ?><br>
                        Customer Tax/GST: <strong><?= e($invoice['customer_tax_number'] ?: 'N/A') ?></strong>
                    </div>
                </div>

                <div class="col-sm-6 text-sm-end">
                    <span class="text-muted small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Shipment Information</span>
                    <div class="fw-bold fs-6 text-primary"><?= e($invoice['pickup_city']) ?> &rarr; <?= e($invoice['delivery_city']) ?></div>
                    <div class="text-muted small mt-1">
                        Booking Reference: <strong><?= e($invoice['booking_number']) ?></strong><br>
                        Cargo Manifest: <strong><?= e($invoice['cargo_description']) ?></strong><br>
                        Certified Weight: <strong><?= number_format((float)$invoice['cargo_weight_tonnes'], 2) ?> Tonnes</strong>
                    </div>
                </div>
            </div>

            <!-- Itemized Table -->
            <div class="table-responsive mb-4">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Item & Service Description</th>
                            <th class="text-center" style="width: 100px;">Qty</th>
                            <th class="text-end" style="width: 150px;">Unit Price</th>
                            <th class="text-end" style="width: 160px;">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td>1</td>
                                <td>Freight Transport: <?= e($invoice['pickup_city']) ?> to <?= e($invoice['delivery_city']) ?></td>
                                <td class="text-center">1.00</td>
                                <td class="text-end"><?= formatCurrency($invoice['subtotal']) ?></td>
                                <td class="text-end fw-semibold"><?= formatCurrency($invoice['subtotal']) ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $idx => $it): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><?= e($it['description']) ?></td>
                                    <td class="text-center"><?= number_format((float)$it['quantity'], 2) ?></td>
                                    <td class="text-end"><?= formatCurrency($it['unit_price']) ?></td>
                                    <td class="text-end fw-semibold"><?= formatCurrency($it['total_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end fw-bold">Subtotal:</td>
                            <td class="text-end fw-bold"><?= formatCurrency($invoice['subtotal']) ?></td>
                        </tr>
                        <?php if ((float)$invoice['discount_amount'] > 0): ?>
                            <tr>
                                <td colspan="4" class="text-end text-success">Promotional Discount:</td>
                                <td class="text-end text-success">-<?= formatCurrency($invoice['discount_amount']) ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="4" class="text-end text-muted">Sales Tax / GST (<?= e((string)$invoice['tax_rate_percent']) ?>%):</td>
                            <td class="text-end text-muted"><?= formatCurrency($invoice['tax_amount']) ?></td>
                        </tr>
                        <tr class="table-light">
                            <td colspan="4" class="text-end fs-5 fw-bold text-dark">Total Invoiced Amount:</td>
                            <td class="text-end fs-5 fw-bold text-primary"><?= formatCurrency($invoice['total_amount']) ?></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-end text-success fw-bold">Total Payments Received:</td>
                            <td class="text-end text-success fw-bold"><?= formatCurrency($invoice['paid_amount']) ?></td>
                        </tr>
                        <tr class="<?= (float)$invoice['balance_amount'] > 0 ? 'table-danger' : 'table-success' ?>">
                            <td colspan="4" class="text-end fs-5 fw-bold">REMAINING BALANCE DUE:</td>
                            <td class="text-end fs-5 fw-bold"><?= formatCurrency($invoice['balance_amount']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Notes & Terms -->
            <?php if ($invoice['notes']): ?>
                <div class="p-3 bg-light rounded small text-muted mb-4">
                    <strong>Payment Instructions:</strong><br>
                    <?= nl2br(e($invoice['notes'])) ?>
                </div>
            <?php endif; ?>

            <!-- Reconciled Payment History Table -->
            <div class="mt-4 pt-3 border-top">
                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-clock-history me-1"></i> Recorded Reconciled Payments</h6>
                <?php if (empty($payments)): ?>
                    <div class="text-muted small py-2">No payments recorded yet for this invoice.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Payment Date</th>
                                    <th>Payment Method</th>
                                    <th>Transaction Ref</th>
                                    <th>Recorded By</th>
                                    <th class="text-end">Amount Cleared</th>
                                    <th class="text-end">Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= e($p['payment_number']) ?></td>
                                        <td><?= formatDate($p['payment_date']) ?></td>
                                        <td><?= ucwords(str_replace('_', ' ', $p['payment_method'])) ?></td>
                                        <td class="font-monospace small"><?= e($p['transaction_reference']) ?></td>
                                        <td><?= e($p['recorder_name']) ?></td>
                                        <td class="text-end fw-bold text-success"><?= formatCurrency($p['amount']) ?></td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>/modules/payments/receipt.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-light border py-0 px-2" title="Print Receipt">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
