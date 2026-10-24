<?php
/**
 * TruckBook Pro - View Official Quotation (Module F)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer']);

$pdo = getDbConnection();
$quoteId = (int)($_GET['id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

$sql = "
    SELECT q.*, 
           c.company_name, c.contact_person, c.phone AS customer_phone, c.email AS customer_email,
           c.address AS customer_address, c.city AS customer_city, c.state AS customer_state, c.postal_code AS customer_postal_code,
           c.tax_gst_number, c.user_id AS customer_user_id,
           fr.request_reference, fr.pickup_city, fr.delivery_city, fr.pickup_address, fr.delivery_address,
           fr.cargo_description, fr.cargo_weight_tonnes, fr.truck_type_required, fr.preferred_date,
           (SELECT b.id FROM bookings b WHERE b.quotation_id = q.id LIMIT 1) AS booking_id,
           u.name AS creator_name
    FROM quotations q
    JOIN customers c ON q.customer_id = c.id
    JOIN freight_requests fr ON q.freight_request_id = fr.id
    JOIN users u ON q.created_by = u.id
    WHERE q.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$quoteId]);
$quote = $stmt->fetch();

if (!$quote) {
    setFlash('danger', 'Quotation record not found.');
    redirect('modules/quotations/index.php');
}

// Security: If Customer, verify ownership
if ($currentRole === 'customer') {
    if ((int)$quote['customer_user_id'] !== $userId) {
        setFlash('danger', 'Unauthorized access to quotation.');
        redirect('dashboard.php');
    }
}

$pageTitle = 'Quotation ' . $quote['quotation_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-print-none">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($quote['quotation_number']) ?></h2>
            <?= renderStatusBadge($quote['status']) ?>
        </div>
        <p class="text-muted mb-0">Generated on <?= formatDateTime($quote['created_at']) ?> by <?= e($quote['creator_name']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="button" class="btn btn-light border" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print / Save PDF
        </button>

        <?php if ($quote['status'] === 'sent' && $currentRole === 'customer'): ?>
            <form method="POST" action="action.php" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $quote['id'] ?>">
                <input type="hidden" name="action" value="accept">
                <button type="submit" class="btn btn-success" onclick="return confirm('Do you accept this quotation and agree to transport terms?')">
                    <i class="bi bi-check2-circle me-1"></i> Accept Quotation
                </button>
            </form>
            <form method="POST" action="action.php" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $quote['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to decline this quotation?')">
                    <i class="bi bi-x-circle me-1"></i> Decline
                </button>
            </form>
        <?php endif; ?>

        <?php if ($quote['status'] === 'accepted' && hasAnyRole(['super_admin', 'transport_manager', 'operator'])): ?>
            <?php if (empty($quote['booking_id'])): ?>
                <a href="<?= BASE_URL ?>/modules/bookings/create.php?quote_id=<?= $quote['id'] ?>" class="btn btn-success">
                    <i class="bi bi-calendar2-check-fill me-1"></i> Convert to Confirmed Booking
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/modules/bookings/view.php?id=<?= $quote['booking_id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-calendar2-check me-1"></i> View Booking
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Printable Commercial Quotation Sheet -->
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="content-card p-4 p-md-5">
            <!-- Document Header -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start border-bottom pb-4 mb-4 gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="brand-icon" style="width: 36px; height: 36px; font-size: 1.1rem;">
                            <i class="bi bi-truck"></i>
                        </div>
                        <h4 class="fw-bold m-0 brand-font"><?= getSetting('company_name', 'TruckBook Pro Logistics Inc.') ?></h4>
                    </div>
                    <div class="text-muted small">
                        <?= getSetting('company_address', '100 North Riverside Plaza, Suite 2400, Chicago, IL 60606') ?><br>
                        Phone: <?= getSetting('company_phone', '+1 (800) 555-TRUCK') ?> | Email: <?= getSetting('company_email', 'support@truckbook.com') ?>
                    </div>
                </div>

                <div class="text-sm-end">
                    <span class="badge bg-primary fs-6 px-3 py-2 mb-2">COMMERCIAL FREIGHT QUOTATION</span>
                    <div class="fw-bold fs-5 text-dark"><?= e($quote['quotation_number']) ?></div>
                    <div class="text-muted small">Valid Until: <strong><?= formatDate($quote['valid_until']) ?></strong></div>
                </div>
            </div>

            <!-- Customer & Route Metadata Grid -->
            <div class="row g-4 mb-4">
                <div class="col-sm-6">
                    <span class="text-muted small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Quotation Prepared For</span>
                    <h5 class="fw-bold text-dark mb-1"><?= e($quote['company_name']) ?></h5>
                    <div class="text-muted small">
                        Attn: <?= e($quote['contact_person']) ?><br>
                        <?= e($quote['customer_address']) ?><br>
                        <?= e($quote['customer_city']) ?>, <?= e($quote['customer_state']) ?> - <?= e($quote['customer_postal_code']) ?><br>
                        Phone: <?= e($quote['customer_phone']) ?> | Email: <?= e($quote['customer_email']) ?><br>
                        Tax/GST: <?= e($quote['tax_gst_number'] ?: 'N/A') ?>
                    </div>
                </div>

                <div class="col-sm-6 text-sm-end">
                    <span class="text-muted small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Freight Route & Manifest</span>
                    <div class="fw-bold fs-6 text-primary"><?= e($quote['pickup_city']) ?> &rarr; <?= e($quote['delivery_city']) ?></div>
                    <div class="text-muted small mt-1">
                        Est. Transit Distance: <strong><?= number_format((float)$quote['estimated_distance_km']) ?> KM</strong><br>
                        Cargo: <strong><?= e($quote['cargo_description']) ?></strong><br>
                        Weight: <strong><?= number_format((float)$quote['cargo_weight_tonnes'], 2) ?> Metric Tonnes</strong><br>
                        Vehicle Type: <strong><?= ucwords(str_replace('_', ' ', $quote['truck_type_required'])) ?></strong><br>
                        Preferred Date: <strong><?= formatDate($quote['preferred_date']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Itemized Pricing Table -->
            <div class="table-responsive mb-4">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Tariff Component Description</th>
                            <th class="text-center" style="width: 140px;">Quantity / Basis</th>
                            <th class="text-end" style="width: 160px;">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>Line-Haul Base Freight Haulage</strong>
                                <div class="text-muted small">Distance (<?= number_format((float)$quote['estimated_distance_km']) ?> KM) + Cargo Tonnage (<?= $quote['cargo_weight_tonnes'] ?>T) tariff rate</div>
                            </td>
                            <td class="text-center">Line Haul</td>
                            <td class="text-end fw-semibold"><?= formatCurrency($quote['base_rate']) ?></td>
                        </tr>
                        <tr>
                            <td>
                                <strong>Fuel Surcharge Index</strong>
                                <div class="text-muted small">Standard fleet diesel cost index adjustment</div>
                            </td>
                            <td class="text-center">Variable</td>
                            <td class="text-end fw-semibold"><?= formatCurrency($quote['fuel_surcharge']) ?></td>
                        </tr>
                        <tr>
                            <td>
                                <strong>Interstate Highway Tolls & Turnpikes</strong>
                                <div class="text-muted small">Estimated electronic toll plazas & weigh-station bridge passes</div>
                            </td>
                            <td class="text-center">Turnpikes</td>
                            <td class="text-end fw-semibold"><?= formatCurrency($quote['toll_estimate']) ?></td>
                        </tr>
                        <tr>
                            <td>
                                <strong>Driver Staging & Overnight Allowance</strong>
                                <div class="text-muted small">Driver lodging and meals allowance for multi-day route</div>
                            </td>
                            <td class="text-center">Staging</td>
                            <td class="text-end fw-semibold"><?= formatCurrency($quote['driver_allowance']) ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end fw-bold">Subtotal:</td>
                            <td class="text-end fw-bold"><?= formatCurrency($quote['subtotal']) ?></td>
                        </tr>
                        <tr>
                            <td colspan="2" class="text-end text-muted">Applicable Sales Tax / GST (<?= e((string)$quote['tax_rate_percent']) ?>%):</td>
                            <td class="text-end text-muted"><?= formatCurrency($quote['tax_amount']) ?></td>
                        </tr>
                        <tr class="table-primary">
                            <td colspan="2" class="text-end fs-5 fw-bold text-dark">GRAND TOTAL ESTIMATED TARIFF:</td>
                            <td class="text-end fs-5 fw-bold text-primary"><?= formatCurrency($quote['total_amount']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Notes & Terms -->
            <div class="p-3 bg-light rounded small text-muted mb-4">
                <strong>Terms & Conditions:</strong><br>
                <?= nl2br(e($quote['notes'] ?? '')) ?>
                <ul class="mb-0 mt-2 ps-3">
                    <li>Rate includes 2 hours for loading and 2 hours for unloading. Detention fees apply thereafter at standard tariff.</li>
                    <li>Payment terms are net 15 days upon issuance of commercial invoice following certified Proof of Delivery.</li>
                    <li>Cargo insurance covers up to statutory liability limits unless additional transit insurance is declared.</li>
                </ul>
            </div>

            <div class="d-flex justify-content-between align-items-center pt-3 border-top text-muted small">
                <div>Prepared by: <strong><?= e($quote['creator_name']) ?></strong> (Fleet Operations)</div>
                <div>Authorized Signature: ____________________________________</div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
