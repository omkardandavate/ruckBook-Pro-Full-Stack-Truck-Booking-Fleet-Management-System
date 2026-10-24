<?php
/**
 * TruckBook Pro - Generate Commercial Invoice (Module J)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager']);

$pdo = getDbConnection();
$bookingId = (int)($_GET['booking_id'] ?? 0);

// Fetch Completed or Active Bookings without an invoice yet
$eligibleBookings = $pdo->query("
    SELECT b.id, b.booking_number, b.agreed_rate, b.pickup_city, b.delivery_city, b.cargo_description,
           c.id AS customer_id, c.company_name
    FROM bookings b
    JOIN customers c ON b.customer_id = c.id
    WHERE b.id NOT IN (SELECT booking_id FROM invoices WHERE payment_status != 'cancelled')
      AND b.status IN ('confirmed', 'in_progress', 'completed')
    ORDER BY b.created_at DESC
")->fetchAll();

$selectedBooking = null;
if ($bookingId > 0) {
    $bStmt = $pdo->prepare("
        SELECT b.*, c.company_name, c.contact_person
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        WHERE b.id = ?
    ");
    $bStmt->execute([$bookingId]);
    $selectedBooking = $bStmt->fetch();
}

$defaultTaxPercent = (float)getSetting('default_tax_rate', '18.00');
$dueDays = (int)getSetting('invoice_due_days', '15');

$error = '';
$formData = [
    'booking_id' => $selectedBooking ? (string)$selectedBooking['id'] : '',
    'issued_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime("+{$dueDays} days")),
    'subtotal' => $selectedBooking ? (string)round((float)$selectedBooking['agreed_rate'] / (1 + ($defaultTaxPercent / 100)), 2) : '',
    'tax_rate_percent' => (string)$defaultTaxPercent,
    'discount_amount' => '0.00',
    'notes' => 'Net 15 days terms. Remittance via ACH / Wire Transfer or Company Cheque.'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    $bId = (int)$formData['booking_id'];

    $validator = (new Validator($_POST))
        ->required('booking_id', 'Booking')
        ->required('issued_date', 'Issued Date')
        ->date('issued_date', 'Issued Date')
        ->required('due_date', 'Due Date')
        ->date('due_date', 'Due Date')
        ->dateAfterOrEqual('due_date', 'issued_date', 'Due Date', 'Issued Date')
        ->required('subtotal', 'Subtotal Amount')
        ->numeric('subtotal', 'Subtotal Amount', 1.0);

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        // Fetch booking info
        $bInfoStmt = $pdo->prepare("
            SELECT b.customer_id, b.booking_number, b.pickup_city, b.delivery_city, b.cargo_description,
                   c.user_id AS customer_user_id
            FROM bookings b
            JOIN customers c ON b.customer_id = c.id
            WHERE b.id = ?
        ");
        $bInfoStmt->execute([$bId]);
        $bInfo = $bInfoStmt->fetch();

        if (!$bInfo) {
            $error = "Selected booking is invalid.";
        } else {
            try {
                $pdo->beginTransaction();

                $subtotal = (float)$formData['subtotal'];
                $taxRate = (float)$formData['tax_rate_percent'];
                $discount = (float)$formData['discount_amount'];
                $taxAmount = ($subtotal - $discount) * ($taxRate / 100);
                $totalAmount = ($subtotal - $discount) + $taxAmount;

                // Generate Invoice Number: INV-YYYY-XXXX
                $year = date('Y');
                $iCount = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE ?");
                $iCount->execute(["INV-{$year}-%"]);
                $seq = (int)$iCount->fetchColumn() + 1;
                $invoiceNumber = sprintf("INV-%s-%04d", $year, $seq);

                $stmt = $pdo->prepare("
                    INSERT INTO invoices (
                        invoice_number, booking_id, customer_id, subtotal, tax_rate_percent,
                        tax_amount, discount_amount, total_amount, paid_amount, balance_amount,
                        payment_status, issued_date, due_date, notes, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, 'unpaid', ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $invoiceNumber,
                    $bId,
                    $bInfo['customer_id'],
                    $subtotal,
                    $taxRate,
                    $taxAmount,
                    $discount,
                    $totalAmount,
                    $totalAmount, // balance = total
                    $formData['issued_date'],
                    $formData['due_date'],
                    $formData['notes'] ?: null,
                    currentUserId()
                ]);
                $invoiceId = (int)$pdo->lastInsertId();

                // Insert Itemized Invoice Item
                $itemStmt = $pdo->prepare("
                    INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total_amount)
                    VALUES (?, ?, 1.00, ?, ?)
                ");
                $itemDesc = "Freight Transportation Services: {$bInfo['pickup_city']} to {$bInfo['delivery_city']} ({$bInfo['cargo_description']})";
                $itemStmt->execute([$invoiceId, $itemDesc, $subtotal, $subtotal]);

                // Notify Customer
                if (!empty($bInfo['customer_user_id'])) {
                    createNotification(
                        (int)$bInfo['customer_user_id'],
                        "Invoice Generated: {$invoiceNumber}",
                        "Commercial invoice of " . formatCurrency($totalAmount) . " has been issued for booking {$bInfo['booking_number']}.",
                        "modules/invoices/view.php?id={$invoiceId}",
                        'info'
                    );
                }

                logAudit(currentUserId(), 'create_invoice', 'invoices', $invoiceId, null, [
                    'invoice_number' => $invoiceNumber,
                    'total_amount' => $totalAmount
                ]);

                $pdo->commit();

                setFlash('success', "Invoice {$invoiceNumber} created and billed to customer.");
                redirect('modules/invoices/view.php?id=' . $invoiceId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Invoice generation error: " . $e->getMessage());
                $error = "Database error: Could not generate invoice.";
            }
        }
    }
}

$pageTitle = 'Generate Commercial Invoice';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Generate Commercial Freight Invoice</h2>
        <p class="text-muted mb-0">Create tax-compliant billing invoice for billable booking.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Invoices
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="content-card">
            <div class="content-card-body p-4">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mb-4"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="create.php" class="needs-validation">
                    <?= csrfField() ?>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-calendar2-check me-2"></i> 1. Select Booking</h5>
                    <div class="mb-4">
                        <label class="form-label">Booking to Bill <span class="text-danger">*</span></label>
                        <select name="booking_id" class="form-select" required onchange="handleBookingSelect(this)">
                            <option value="">-- Choose Booking --</option>
                            <?php foreach ($eligibleBookings as $eb): ?>
                                <option value="<?= $eb['id'] ?>" 
                                        data-rate="<?= $eb['agreed_rate'] ?>"
                                        <?= $formData['booking_id'] === (string)$eb['id'] ? 'selected' : '' ?>>
                                    <?= e($eb['booking_number']) ?> &mdash; <?= e($eb['company_name']) ?> (<?= e($eb['pickup_city']) ?> &rarr; <?= e($eb['delivery_city']) ?> | Agreed: <?= formatCurrency($eb['agreed_rate']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-cash-coin me-2"></i> 2. Billing & Tax Calculation</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Freight Subtotal (Before Tax) (<?= getSetting('currency_symbol', '$') ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="subtotal" id="subtotalInput" class="form-control fw-bold" 
                                   value="<?= e($formData['subtotal']) ?>" required placeholder="2305.00" oninput="calculateInvoice()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tax / GST Rate (%)</label>
                            <input type="number" step="0.1" name="tax_rate_percent" id="taxRateInput" class="form-control" 
                                   value="<?= e($formData['tax_rate_percent']) ?>" required oninput="calculateInvoice()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Discount (<?= getSetting('currency_symbol', '$') ?>)</label>
                            <input type="number" step="0.01" name="discount_amount" id="discountInput" class="form-control" 
                                   value="<?= e($formData['discount_amount']) ?>" oninput="calculateInvoice()">
                        </div>
                    </div>

                    <!-- Live Breakdown Display -->
                    <div class="p-3 bg-light rounded-3 mb-4 border">
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Calculated Tax Amount:</span>
                            <span class="fw-bold text-dark" id="dispTaxAmount">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between pt-2">
                            <h5 class="fw-bold text-primary m-0">Total Invoice Amount:</h5>
                            <h4 class="fw-bold text-primary m-0" id="dispTotalInvoice">$0.00</h4>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-clock me-2"></i> 3. Terms & Due Date</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Invoice Issued Date <span class="text-danger">*</span></label>
                            <input type="date" name="issued_date" class="form-control" value="<?= e($formData['issued_date']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Due Date <span class="text-danger">*</span></label>
                            <input type="date" name="due_date" class="form-control" value="<?= e($formData['due_date']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Payment Terms / Notes</label>
                            <textarea name="notes" class="form-control" rows="2"><?= e($formData['notes']) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Issue Official Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const currency = '<?= getSetting("currency_symbol", "$") ?>';

function handleBookingSelect(select) {
    const opt = select.options[select.selectedIndex];
    const agreedRate = parseFloat(opt.getAttribute('data-rate')) || 0;
    if (agreedRate > 0) {
        const taxRate = parseFloat(document.getElementById('taxRateInput').value) || 18.0;
        // Approximate subtotal assuming agreedRate was tax-inclusive
        const sub = agreedRate / (1 + (taxRate / 100));
        document.getElementById('subtotalInput').value = sub.toFixed(2);
        calculateInvoice();
    }
}

function calculateInvoice() {
    const subtotal = parseFloat(document.getElementById('subtotalInput').value) || 0;
    const taxRate = parseFloat(document.getElementById('taxRateInput').value) || 0;
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;

    const taxable = Math.max(0, subtotal - discount);
    const tax = taxable * (taxRate / 100);
    const total = taxable + tax;

    document.getElementById('dispTaxAmount').innerText = currency + tax.toFixed(2);
    document.getElementById('dispTotalInvoice').innerText = currency + total.toFixed(2);
}

document.addEventListener('DOMContentLoaded', calculateInvoice);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
