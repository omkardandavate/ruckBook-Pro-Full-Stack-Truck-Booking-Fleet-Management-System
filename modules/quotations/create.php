<?php
/**
 * TruckBook Pro - Automated Quotation Generator (Module F)
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
$requestId = (int)($_GET['request_id'] ?? 0);

// Fetch Pending Requests
$pendingRequests = $pdo->query("
    SELECT fr.id, fr.request_reference, fr.pickup_city, fr.delivery_city, fr.cargo_weight_tonnes, fr.cargo_description,
           c.id AS customer_id, c.company_name
    FROM freight_requests fr
    JOIN customers c ON fr.customer_id = c.id
    WHERE fr.status IN ('pending', 'quoted')
    ORDER BY fr.created_at DESC
")->fetchAll();

$selectedReq = null;
if ($requestId > 0) {
    $sStmt = $pdo->prepare("
        SELECT fr.*, c.company_name, c.contact_person
        FROM freight_requests fr
        JOIN customers c ON fr.customer_id = c.id
        WHERE fr.id = ?
    ");
    $sStmt->execute([$requestId]);
    $selectedReq = $sStmt->fetch();
}

// Configurable pricing settings
$baseRatePerKm = (float)getSetting('base_rate_per_km', '4.00');
$baseRatePerTonne = (float)getSetting('base_rate_per_tonne', '15.00');
$fuelSurchargePercent = (float)getSetting('fuel_surcharge_percent', '10.00');
$defaultDriverAllowance = (float)getSetting('driver_daily_allowance', '150.00');
$defaultTaxPercent = (float)getSetting('default_tax_rate', '18.00');

$error = '';
$formData = [
    'freight_request_id' => $selectedReq ? (string)$selectedReq['id'] : '',
    'estimated_distance_km' => '450',
    'base_rate' => '',
    'fuel_surcharge' => '',
    'toll_estimate' => '75.00',
    'driver_allowance' => (string)$defaultDriverAllowance,
    'subtotal' => '',
    'tax_rate_percent' => (string)$defaultTaxPercent,
    'tax_amount' => '',
    'total_amount' => '',
    'valid_until' => date('Y-m-d', strtotime('+14 days')),
    'notes' => 'Quotation valid for 14 days. Pricing includes fuel surcharge and interstate toll allowances.'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    $reqId = (int)$formData['freight_request_id'];

    // Verify request
    $rCheck = $pdo->prepare("SELECT customer_id, cargo_weight_tonnes FROM freight_requests WHERE id = ?");
    $rCheck->execute([$reqId]);
    $reqData = $rCheck->fetch();

    if (!$reqData) {
        $error = "Selected freight request is invalid.";
    } else {
        $distance = (float)$formData['estimated_distance_km'];
        $tonnes = (float)$reqData['cargo_weight_tonnes'];
        $toll = (float)$formData['toll_estimate'];
        $allowance = (float)$formData['driver_allowance'];
        $taxRate = (float)$formData['tax_rate_percent'];

        // Backend Pricing Verification: Formula Execution
        $calcBaseRate = ($distance * $baseRatePerKm) + ($tonnes * $baseRatePerTonne);
        $calcFuel = $calcBaseRate * ($fuelSurchargePercent / 100);
        $calcSubtotal = $calcBaseRate + $calcFuel + $toll + $allowance;
        $calcTax = $calcSubtotal * ($taxRate / 100);
        $calcTotal = $calcSubtotal + $calcTax;

        try {
            $pdo->beginTransaction();

            $year = date('Y');
            $qCount = $pdo->prepare("SELECT COUNT(*) FROM quotations WHERE quotation_number LIKE ?");
            $qCount->execute(["QUO-{$year}-%"]);
            $seq = (int)$qCount->fetchColumn() + 1;
            $quotationNumber = sprintf("QUO-%s-%04d", $year, $seq);

            $stmt = $pdo->prepare("
                INSERT INTO quotations (
                    quotation_number, freight_request_id, customer_id, estimated_distance_km,
                    base_rate, fuel_surcharge, toll_estimate, driver_allowance, subtotal,
                    tax_rate_percent, tax_amount, total_amount, valid_until, status, notes, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?, NOW())
            ");
            $stmt->execute([
                $quotationNumber,
                $reqId,
                $reqData['customer_id'],
                $distance,
                $calcBaseRate,
                $calcFuel,
                $toll,
                $allowance,
                $calcSubtotal,
                $taxRate,
                $calcTax,
                $calcTotal,
                $formData['valid_until'],
                $formData['notes'] ?: null,
                currentUserId()
            ]);
            $quotationId = (int)$pdo->lastInsertId();

            // Update freight request status to quoted
            $upReq = $pdo->prepare("UPDATE freight_requests SET status = 'quoted', updated_at = NOW() WHERE id = ?");
            $upReq->execute([$reqId]);

            // Notify Customer if user account is linked
            $custUserStmt = $pdo->prepare("SELECT user_id FROM customers WHERE id = ?");
            $custUserStmt->execute([$reqData['customer_id']]);
            $cUserId = $custUserStmt->fetchColumn();
            if ($cUserId) {
                createNotification(
                    (int)$cUserId,
                    "Quotation {$quotationNumber} Generated",
                    "A formal freight tariff of " . formatCurrency($calcTotal) . " is now ready for your review.",
                    "modules/quotations/view.php?id={$quotationId}",
                    'success'
                );
            }

            logAudit(currentUserId(), 'create_quotation', 'quotations', $quotationId, null, [
                'quotation_number' => $quotationNumber,
                'total_amount' => $calcTotal
            ]);

            $pdo->commit();

            setFlash('success', "Quotation {$quotationNumber} generated and transmitted to client.");
            redirect('modules/quotations/view.php?id=' . $quotationId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Quotation calculation error: " . $e->getMessage());
            $error = "Database error: Could not generate quotation.";
        }
    }
}

$pageTitle = 'Generate Freight Quotation';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Generate Freight Tariff Quotation</h2>
        <p class="text-muted mb-0">Automated freight tariff calculator incorporating distance, tonnage, tolls, and fuel index.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Quotations
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="content-card">
            <div class="content-card-body p-4">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mb-4"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="create.php" id="quoteForm" class="needs-validation">
                    <?= csrfField() ?>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-box-seam me-2"></i> 1. Link to Freight Request</h5>
                    <div class="mb-4">
                        <label class="form-label">Freight Request <span class="text-danger">*</span></label>
                        <select name="freight_request_id" id="reqSelect" class="form-select" required onchange="handleRequestChange(this)">
                            <option value="">-- Choose Pending Freight Request --</option>
                            <?php foreach ($pendingRequests as $pr): ?>
                                <option value="<?= $pr['id'] ?>" 
                                        data-weight="<?= $pr['cargo_weight_tonnes'] ?>"
                                        data-pickup="<?= e($pr['pickup_city']) ?>"
                                        data-delivery="<?= e($pr['delivery_city']) ?>"
                                        <?= $formData['freight_request_id'] === (string)$pr['id'] ? 'selected' : '' ?>>
                                    <?= e($pr['request_reference']) ?> &mdash; <?= e($pr['company_name']) ?> (<?= e($pr['pickup_city']) ?> &rarr; <?= e($pr['delivery_city']) ?> | <?= $pr['cargo_weight_tonnes'] ?>T)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-calculator me-2"></i> 2. Route & Pricing Variables</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Estimated Route Distance (KM) <span class="text-danger">*</span></label>
                            <input type="number" step="1" name="estimated_distance_km" id="distKm" class="form-control" 
                                   value="<?= e($formData['estimated_distance_km']) ?>" required min="5" oninput="calculateQuote()">
                            <small class="text-muted">Rate: <?= formatCurrency($baseRatePerKm) ?> per KM</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Estimated Highway Tolls (<?= getSetting('currency_symbol', '$') ?>)</label>
                            <input type="number" step="0.5" name="toll_estimate" id="tollEst" class="form-control" 
                                   value="<?= e($formData['toll_estimate']) ?>" required min="0" oninput="calculateQuote()">
                            <small class="text-muted">Turnpikes & state transit gates</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Driver Overnight Allowance (<?= getSetting('currency_symbol', '$') ?>)</label>
                            <input type="number" step="0.5" name="driver_allowance" id="driverAllow" class="form-control" 
                                   value="<?= e($formData['driver_allowance']) ?>" required min="0" oninput="calculateQuote()">
                            <small class="text-muted">Lodging & food allowance</small>
                        </div>
                    </div>

                    <!-- Live Calculation Breakdown Card -->
                    <div class="p-4 rounded-3 mb-4" style="background: #f8fafc; border: 2px dashed #cbd5e1;">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-receipt-cutoff me-2"></i> Live Tariff Calculation Breakdown</h6>
                        
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Base Haulage (Distance + Tonnage Rate):</span>
                            <span class="fw-semibold" id="dispBaseRate">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Fuel Surcharge (<?= $fuelSurchargePercent ?>% Index):</span>
                            <span class="fw-semibold" id="dispFuelSurcharge">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Interstate Tolls & Driver Allowance:</span>
                            <span class="fw-semibold" id="dispTollsAndAllow">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <strong class="text-dark">Net Subtotal:</strong>
                            <strong class="text-dark" id="dispSubtotal">$0.00</strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">GST / Sales Tax (<?= $defaultTaxPercent ?>%):</span>
                            <span class="fw-semibold text-muted" id="dispTax">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between pt-2">
                            <h5 class="fw-bold text-primary m-0">Total Freight Quotation:</h5>
                            <h4 class="fw-bold text-primary m-0" id="dispTotal">$0.00</h4>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-calendar2-check me-2"></i> 3. Validity & Terms</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Quotation Valid Until <span class="text-danger">*</span></label>
                            <input type="date" name="valid_until" class="form-control" 
                                   value="<?= e($formData['valid_until']) ?>" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Quotation Notes / Terms</label>
                            <input type="text" name="notes" class="form-control" value="<?= e($formData['notes']) ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-file-earmark-check me-1"></i> Issue Official Quotation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const ratePerKm = <?= $baseRatePerKm ?>;
const ratePerTonne = <?= $baseRatePerTonne ?>;
const fuelPercent = <?= $fuelSurchargePercent ?>;
const taxPercent = <?= $defaultTaxPercent ?>;
const currencySymbol = '<?= getSetting("currency_symbol", "$") ?>';

function handleRequestChange() {
    calculateQuote();
}

function calculateQuote() {
    const reqSelect = document.getElementById('reqSelect');
    const selectedOption = reqSelect.options[reqSelect.selectedIndex];
    const weight = parseFloat(selectedOption?.getAttribute('data-weight')) || 15.0;

    const distance = parseFloat(document.getElementById('distKm').value) || 0;
    const toll = parseFloat(document.getElementById('tollEst').value) || 0;
    const allowance = parseFloat(document.getElementById('driverAllow').value) || 0;

    const baseHaulage = (distance * ratePerKm) + (weight * ratePerTonne);
    const fuel = baseHaulage * (fuelPercent / 100);
    const subtotal = baseHaulage + fuel + toll + allowance;
    const tax = subtotal * (taxPercent / 100);
    const grandTotal = subtotal + tax;

    document.getElementById('dispBaseRate').innerText = currencySymbol + baseHaulage.toFixed(2);
    document.getElementById('dispFuelSurcharge').innerText = currencySymbol + fuel.toFixed(2);
    document.getElementById('dispTollsAndAllow').innerText = currencySymbol + (toll + allowance).toFixed(2);
    document.getElementById('dispSubtotal').innerText = currencySymbol + subtotal.toFixed(2);
    document.getElementById('dispTax').innerText = currencySymbol + tax.toFixed(2);
    document.getElementById('dispTotal').innerText = currencySymbol + grandTotal.toFixed(2);
}

document.addEventListener('DOMContentLoaded', calculateQuote);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
