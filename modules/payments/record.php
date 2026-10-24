<?php
/**
 * TruckBook Pro - Record Customer Payment (Module J)
 * Fully reconciles invoice balances and payment statuses using database transactions.
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
$invoiceId = (int)($_GET['invoice_id'] ?? 0);

// Invoices with outstanding balance
$unpaidInvoices = $pdo->query("
    SELECT inv.id, inv.invoice_number, inv.total_amount, inv.paid_amount, inv.balance_amount,
           c.id AS customer_id, c.company_name
    FROM invoices inv
    JOIN customers c ON inv.customer_id = c.id
    WHERE inv.payment_status IN ('unpaid', 'partially_paid', 'overdue')
      AND inv.balance_amount > 0
    ORDER BY inv.issued_date ASC
")->fetchAll();

$selectedInvoice = null;
if ($invoiceId > 0) {
    $iStmt = $pdo->prepare("
        SELECT inv.*, c.company_name
        FROM invoices inv
        JOIN customers c ON inv.customer_id = c.id
        WHERE inv.id = ?
    ");
    $iStmt->execute([$invoiceId]);
    $selectedInvoice = $iStmt->fetch();
}

$methods = [
    'bank_transfer' => 'ACH / Wire Transfer (Direct Bank Deposit)',
    'credit_card' => 'Corporate Credit Card',
    'cheque' => 'Company Cheque',
    'upi' => 'UPI / Instant Payment QR',
    'cash' => 'Cash Receipt Voucher',
    'other' => 'Other Payment Gateway / Instrument'
];

$error = '';
$formData = [
    'invoice_id' => $selectedInvoice ? (string)$selectedInvoice['id'] : '',
    'amount' => $selectedInvoice ? (string)$selectedInvoice['balance_amount'] : '',
    'payment_date' => date('Y-m-d'),
    'payment_method' => 'bank_transfer',
    'transaction_reference' => '',
    'notes' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    $invId = (int)$formData['invoice_id'];
    $amount = (float)$formData['amount'];

    $validator = (new Validator($_POST))
        ->required('invoice_id', 'Invoice')
        ->required('amount', 'Payment Amount')
        ->numeric('amount', 'Payment Amount', 0.01)
        ->required('payment_date', 'Payment Date')
        ->date('payment_date', 'Payment Date')
        ->required('payment_method', 'Payment Method')
        ->in('payment_method', array_keys($methods), 'Payment Method')
        ->required('transaction_reference', 'Transaction Reference #');

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        try {
            $pdo->beginTransaction();

            // Lock invoice record for update
            $invCheck = $pdo->prepare("SELECT * FROM invoices WHERE id = ? FOR UPDATE");
            $invCheck->execute([$invId]);
            $inv = $invCheck->fetch();

            if (!$inv) {
                throw new RuntimeException("Selected invoice is invalid.");
            }

            $currentBalance = (float)$inv['balance_amount'];
            if ($amount > $currentBalance + 0.01) {
                throw new RuntimeException("Payment amount (" . formatCurrency($amount) . ") cannot exceed invoice remaining balance (" . formatCurrency($currentBalance) . ").");
            }

            // Generate Payment Number: PAY-YYYY-XXXX
            $year = date('Y');
            $pCount = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_number LIKE ?");
            $pCount->execute(["PAY-{$year}-%"]);
            $seq = (int)$pCount->fetchColumn() + 1;
            $paymentNumber = sprintf("PAY-%s-%04d", $year, $seq);

            // 1. Insert Payment Record
            $pStmt = $pdo->prepare("
                INSERT INTO payments (
                    payment_number, invoice_id, customer_id, amount, payment_date,
                    payment_method, transaction_reference, status, notes, recorded_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, NOW())
            ");
            $pStmt->execute([
                $paymentNumber,
                $invId,
                $inv['customer_id'],
                $amount,
                $formData['payment_date'],
                $formData['payment_method'],
                $formData['transaction_reference'],
                $formData['notes'] ?: null,
                currentUserId()
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // 2. Reconcile Invoice Financials
            $newPaid = (float)$inv['paid_amount'] + $amount;
            $newBalance = max(0.00, (float)$inv['total_amount'] - $newPaid);
            $newStatus = ($newBalance <= 0.005) ? 'paid' : 'partially_paid';

            $upInv = $pdo->prepare("
                UPDATE invoices
                SET paid_amount = ?,
                    balance_amount = ?,
                    payment_status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $upInv->execute([$newPaid, $newBalance, $newStatus, $invId]);

            // 3. Notify Customer
            $cUser = $pdo->prepare("SELECT user_id FROM customers WHERE id = ?");
            $cUser->execute([$inv['customer_id']]);
            $custUserId = $cUser->fetchColumn();
            if ($custUserId) {
                createNotification(
                    (int)$custUserId,
                    "Payment Received: {$paymentNumber}",
                    "Your payment of " . formatCurrency($amount) . " against invoice {$inv['invoice_number']} has been cleared.",
                    "modules/payments/receipt.php?id={$paymentId}",
                    'success'
                );
            }

            logAudit(currentUserId(), 'record_payment', 'payments', $paymentId, null, [
                'payment_number' => $paymentNumber,
                'amount' => $amount,
                'invoice_number' => $inv['invoice_number'],
                'new_invoice_status' => $newStatus
            ]);

            $pdo->commit();

            setFlash('success', "Payment {$paymentNumber} recorded successfully. Invoice balance reconciled.");
            redirect('modules/payments/receipt.php?id=' . $paymentId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Payment recording error: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Record Customer Payment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Record Customer Payment</h2>
        <p class="text-muted mb-0">Reconcile customer bank remittances, wire deposits, and cheques against billing invoices.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Ledger
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="content-card-body p-4">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mb-4"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="record.php" class="needs-validation">
                    <?= csrfField() ?>

                    <div class="mb-4">
                        <label class="form-label">Select Invoice to Credit <span class="text-danger">*</span></label>
                        <select name="invoice_id" id="invoiceSelect" class="form-select" required onchange="handleInvoiceChange(this)">
                            <option value="">-- Choose Outstanding Invoice --</option>
                            <?php foreach ($unpaidInvoices as $ui): ?>
                                <option value="<?= $ui['id'] ?>" 
                                        data-balance="<?= $ui['balance_amount'] ?>"
                                        data-total="<?= $ui['total_amount'] ?>"
                                        <?= $formData['invoice_id'] === (string)$ui['id'] ? 'selected' : '' ?>>
                                    <?= e($ui['invoice_number']) ?> &mdash; <?= e($ui['company_name']) ?> (Balance: <?= formatCurrency($ui['balance_amount']) ?> of <?= formatCurrency($ui['total_amount']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Payment Amount (<?= getSetting('currency_symbol', '$') ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" id="amountInput" class="form-control fw-bold fs-6 text-success" 
                                   value="<?= e($formData['amount']) ?>" required placeholder="0.00">
                            <small class="text-muted" id="balanceHint">Enter amount up to invoice balance</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Remittance / Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control" value="<?= e($formData['payment_date']) ?>" required max="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Payment Method / Channel <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <?php foreach ($methods as $k => $lbl): ?>
                                    <option value="<?= $k ?>" <?= $formData['payment_method'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Transaction Reference # / Cheque # <span class="text-danger">*</span></label>
                            <input type="text" name="transaction_reference" class="form-control font-monospace" 
                                   value="<?= e($formData['transaction_reference']) ?>" required placeholder="e.g. WIRE-CHASE-889104">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Payment Notes / Bank Details</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Bank branch, remitter reference, notes..."><?= e($formData['notes']) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-success px-4">
                            <i class="bi bi-check-lg me-1"></i> Record & Reconcile Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function handleInvoiceChange(select) {
    const opt = select.options[select.selectedIndex];
    const bal = opt.getAttribute('data-balance');
    if (bal) {
        document.getElementById('amountInput').value = parseFloat(bal).toFixed(2);
        document.getElementById('balanceHint').innerText = 'Full balance amount: ' + '<?= getSetting("currency_symbol", "$") ?>' + parseFloat(bal).toFixed(2);
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
