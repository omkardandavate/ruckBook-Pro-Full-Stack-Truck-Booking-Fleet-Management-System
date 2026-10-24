<?php
/**
 * TruckBook Pro - Official Payment Receipt Voucher (Module J)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer']);

$pdo = getDbConnection();
$paymentId = (int)($_GET['id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

$sql = "
    SELECT p.*, 
           c.company_name, c.contact_person, c.phone AS customer_phone, c.email AS customer_email,
           c.user_id AS customer_user_id,
           inv.invoice_number, inv.total_amount AS invoice_total, inv.paid_amount AS invoice_paid, inv.balance_amount AS invoice_balance,
           inv.payment_status AS invoice_status,
           b.booking_number, b.pickup_city, b.delivery_city,
           u.name AS recorder_name
    FROM payments p
    JOIN customers c ON p.customer_id = c.id
    JOIN invoices inv ON p.invoice_id = inv.id
    JOIN bookings b ON inv.booking_id = b.id
    JOIN users u ON p.recorded_by = u.id
    WHERE p.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    setFlash('danger', 'Payment record not found.');
    redirect('modules/payments/index.php');
}

// Ownership check for customer
if ($currentRole === 'customer' && (int)$payment['customer_user_id'] !== $userId) {
    setFlash('danger', 'Unauthorized access to payment receipt.');
    redirect('dashboard.php');
}

$methods = [
    'bank_transfer' => 'ACH / Wire Transfer (Direct Bank Deposit)',
    'credit_card' => 'Corporate Credit Card',
    'cheque' => 'Company Cheque',
    'upi' => 'UPI / Instant Payment',
    'cash' => 'Cash Voucher',
    'other' => 'Other Payment Method'
];

$pageTitle = 'Receipt ' . $payment['payment_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-print-none">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($payment['payment_number']) ?></h2>
            <span class="badge bg-success-subtle text-success border border-success-subtle">Payment Received & Cleared</span>
        </div>
        <p class="text-muted mb-0">Recorded on <?= formatDateTime($payment['created_at']) ?> by <?= e($payment['recorder_name']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Ledger
        </a>
        <button type="button" class="btn btn-light border" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Receipt
        </button>
        <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $payment['invoice_id'] ?>" class="btn btn-outline-primary">
            <i class="bi bi-receipt me-1"></i> View Invoice (<?= e($payment['invoice_number']) ?>)
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
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
                        Phone: <?= getSetting('company_phone') ?> | Email: <?= getSetting('company_email') ?>
                    </div>
                </div>

                <div class="text-sm-end">
                    <span class="badge bg-success fs-6 px-3 py-2 mb-2">OFFICIAL PAYMENT RECEIPT</span>
                    <div class="fw-bold fs-5 text-dark"><?= e($payment['payment_number']) ?></div>
                    <div class="text-muted small">Date: <strong><?= formatDate($payment['payment_date']) ?></strong></div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-sm-6">
                    <span class="text-muted small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Received From (Client)</span>
                    <h5 class="fw-bold text-dark mb-1"><?= e($payment['company_name']) ?></h5>
                    <div class="text-muted small">
                        Contact: <?= e($payment['contact_person']) ?><br>
                        Phone: <?= e($payment['customer_phone']) ?> | Email: <?= e($payment['customer_email']) ?>
                    </div>
                </div>

                <div class="col-sm-6 text-sm-end">
                    <span class="text-muted small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Invoice Credited</span>
                    <h5 class="fw-bold text-primary mb-1"><?= e($payment['invoice_number']) ?></h5>
                    <div class="text-muted small">
                        Booking Ref: <strong><?= e($payment['booking_number']) ?></strong><br>
                        Route: <?= e($payment['pickup_city']) ?> &rarr; <?= e($payment['delivery_city']) ?>
                    </div>
                </div>
            </div>

            <!-- Receipt Summary Box -->
            <div class="p-4 rounded-3 mb-4 bg-success-subtle border border-success-subtle text-center">
                <span class="text-muted text-uppercase fw-bold small d-block mb-1">Total Cleared Payment Amount</span>
                <h1 class="display-5 fw-bold text-success mb-2"><?= formatCurrency($payment['amount']) ?></h1>
                <div class="badge bg-success text-white px-3 py-1 fs-6">
                    Transaction Ref: <?= e($payment['transaction_reference']) ?>
                </div>
            </div>

            <!-- Transaction Details Table -->
            <div class="table-responsive mb-4">
                <table class="table table-bordered">
                    <tbody>
                        <tr>
                            <td class="w-50 text-muted">Payment Channel / Method:</td>
                            <td class="fw-semibold"><?= $methods[$payment['payment_method']] ?? $payment['payment_method'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Total Commercial Invoice Amount:</td>
                            <td class="fw-semibold"><?= formatCurrency($payment['invoice_total']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Cumulative Cleared Payments on Invoice:</td>
                            <td class="fw-semibold text-success"><?= formatCurrency($payment['invoice_paid']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Remaining Balance Due on Invoice:</td>
                            <td class="fw-bold <?= (float)$payment['invoice_balance'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                <?= formatCurrency($payment['invoice_balance']) ?>
                                (Status: <?= renderStatusBadge($payment['invoice_status']) ?>)
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if ($payment['notes']): ?>
                <div class="p-3 bg-light rounded small text-muted mb-4">
                    <strong>Payment Notes:</strong> <?= nl2br(e($payment['notes'])) ?>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center pt-4 border-top text-muted small">
                <div>Recorded by: <strong><?= e($payment['recorder_name']) ?></strong></div>
                <div>Authorized Cashier / Accounts Sign: _______________________</div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
