<?php
/**
 * TruckBook Pro - Trip Operations, Tracking & Proof of Delivery (Module H)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer', 'driver']);

$pdo = getDbConnection();
$tripId = (int)($_GET['id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

$sql = "
    SELECT t.*, 
           b.booking_number, b.pickup_city, b.delivery_city, b.pickup_address, b.delivery_address,
           b.cargo_description, b.cargo_weight_tonnes, b.agreed_rate, b.status AS booking_status,
           c.id AS customer_id, c.company_name, c.contact_person, c.phone AS customer_phone, c.email AS customer_email,
           c.user_id AS customer_user_id,
           v.registration_number, v.model AS vehicle_model, v.truck_type, v.current_odometer AS vehicle_odometer,
           d.name AS driver_name, d.phone AS driver_phone, d.licence_number, d.user_id AS driver_user_id,
           (SELECT inv.id FROM invoices inv WHERE inv.booking_id = b.id LIMIT 1) AS invoice_id,
           (SELECT inv.invoice_number FROM invoices inv WHERE inv.booking_id = b.id LIMIT 1) AS invoice_number
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN customers c ON b.customer_id = c.id
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
    WHERE t.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$tripId]);
$trip = $stmt->fetch();

if (!$trip) {
    setFlash('danger', 'Trip record not found.');
    redirect('modules/trips/index.php');
}

// Ownership checks
if ($currentRole === 'customer' && (int)$trip['customer_user_id'] !== $userId) {
    setFlash('danger', 'Unauthorized access to trip.');
    redirect('dashboard.php');
}
if ($currentRole === 'driver' && (int)$trip['driver_user_id'] !== $userId) {
    setFlash('danger', 'Unauthorized access to trip.');
    redirect('dashboard.php');
}

// Fetch Status Milestones
$hStmt = $pdo->prepare("
    SELECT h.*, u.name AS updater_name
    FROM trip_status_history h
    JOIN users u ON h.updated_by = u.id
    WHERE h.trip_id = ?
    ORDER BY h.created_at ASC
");
$hStmt->execute([$tripId]);
$history = $hStmt->fetchAll();

// Fetch Trip Expenses
$eStmt = $pdo->prepare("
    SELECT te.*, u.name AS creator_name
    FROM trip_expenses te
    JOIN users u ON te.created_by = u.id
    WHERE te.trip_id = ?
    ORDER BY te.expense_date DESC
");
$eStmt->execute([$tripId]);
$expenses = $eStmt->fetchAll();

$totalTripExpenses = 0.00;
foreach ($expenses as $e) {
    if ($e['status'] === 'approved') {
        $totalTripExpenses += (float)$e['amount'];
    }
}
$tripRevenue = (float)$trip['agreed_rate'];
$tripNetProfit = $tripRevenue - $totalTripExpenses;

$milestones = ['scheduled', 'dispatched', 'in_transit', 'arrived', 'unloading', 'delivered'];
$currentStatusIdx = array_search($trip['delivery_status'], $milestones, true);
if ($currentStatusIdx === false) $currentStatusIdx = 0;

$pageTitle = 'Trip Dispatch: ' . $trip['trip_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-print-none">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($trip['trip_number']) ?></h2>
            <?= renderStatusBadge($trip['delivery_status']) ?>
        </div>
        <p class="text-muted mb-0">
            Booking Ref: <a href="<?= BASE_URL ?>/modules/bookings/view.php?id=<?= $trip['booking_id'] ?>" class="fw-semibold text-primary"><?= e($trip['booking_number']) ?></a>
            &bull; Route: <?= e($trip['pickup_city']) ?> &rarr; <?= e($trip['delivery_city']) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Trips
        </a>

        <?php if ($trip['delivery_status'] !== 'delivered' && hasAnyRole(['super_admin', 'transport_manager', 'operator', 'driver'])): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#statusModal">
                <i class="bi bi-arrow-repeat me-1"></i> Update Milestone / POD
            </button>
        <?php endif; ?>

        <?php if (hasAnyRole(['super_admin', 'transport_manager', 'operator', 'driver'])): ?>
            <a href="<?= BASE_URL ?>/modules/expenses/create.php?trip_id=<?= $trip['id'] ?>" class="btn btn-light border">
                <i class="bi bi-receipt me-1"></i> Add Expense
            </a>
        <?php endif; ?>

        <?php if ($trip['delivery_status'] === 'delivered' && hasAnyRole(['super_admin', 'transport_manager']) && empty($trip['invoice_id'])): ?>
            <a href="<?= BASE_URL ?>/modules/invoices/create.php?booking_id=<?= $trip['booking_id'] ?>" class="btn btn-success">
                <i class="bi bi-cash-stack me-1"></i> Generate Invoice
            </a>
        <?php elseif ($trip['invoice_id']): ?>
            <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $trip['invoice_id'] ?>" class="btn btn-outline-success">
                <i class="bi bi-receipt me-1"></i> Invoice: <?= e($trip['invoice_number']) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Milestone Stepper Visual -->
<div class="content-card mb-4 p-4">
    <div class="row text-center g-2">
        <?php foreach ($milestones as $idx => $m): ?>
            <?php
                $isCompleted = $idx <= $currentStatusIdx && $trip['delivery_status'] !== 'cancelled';
                $isCurrent = ($idx === $currentStatusIdx) && $trip['delivery_status'] !== 'cancelled';
            ?>
            <div class="col-4 col-md-2">
                <div class="p-2 rounded <?= $isCurrent ? 'bg-primary text-white shadow-sm' : ($isCompleted ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-muted border') ?>">
                    <i class="bi <?= $isCompleted ? 'bi-check-circle-fill' : 'bi-circle' ?> d-block fs-5 mb-1"></i>
                    <strong class="small text-uppercase"><?= str_replace('_', ' ', $m) ?></strong>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Main Operations Grid -->
<div class="row g-4 mb-4">
    <!-- Journey & Odometer Details -->
    <div class="col-lg-8">
        <div class="content-card mb-4">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-geo-alt-fill me-2 text-primary"></i> Route & Dispatch Progress</h5>
            </div>
            <div class="content-card-body">
                <div class="p-3 bg-light rounded-3 mb-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                    <div>
                        <span class="text-muted small d-block">Origin Pickup</span>
                        <strong class="fs-5"><?= e($trip['pickup_city']) ?></strong>
                        <div class="text-muted small mt-1"><?= nl2br(e($trip['pickup_address'])) ?></div>
                        <div class="small mt-2"><strong>Departed:</strong> <?= formatDateTime($trip['actual_departure'] ?: $trip['planned_departure']) ?></div>
                    </div>
                    <div class="text-center text-primary px-3">
                        <i class="bi bi-arrow-right fs-3 d-none d-sm-block"></i>
                        <i class="bi bi-arrow-down fs-3 d-sm-none"></i>
                    </div>
                    <div>
                        <span class="text-muted small d-block">Destination Delivery</span>
                        <strong class="fs-5"><?= e($trip['delivery_city']) ?></strong>
                        <div class="text-muted small mt-1"><?= nl2br(e($trip['delivery_address'])) ?></div>
                        <div class="small mt-2">
                            <strong>Delivered:</strong> <?= $trip['actual_arrival'] ? formatDateTime($trip['actual_arrival']) : '<span class="text-muted">In Progress</span>' ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="border rounded p-3">
                            <span class="text-muted small d-block">Start Odometer</span>
                            <strong class="fs-6"><?= number_format((int)$trip['start_odometer']) ?> KM</strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3">
                            <span class="text-muted small d-block">End Odometer</span>
                            <strong class="fs-6"><?= $trip['end_odometer'] ? number_format((int)$trip['end_odometer']) . ' KM' : '<span class="text-muted">—</span>' ?></strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3">
                            <span class="text-muted small d-block">Total Distance Run</span>
                            <strong class="fs-6 text-primary"><?= number_format((float)$trip['distance_km']) ?> KM</strong>
                        </div>
                    </div>
                </div>

                <?php if ($trip['trip_notes']): ?>
                    <div class="mt-3 p-3 bg-light rounded small">
                        <strong>Trip Notes:</strong> <?= nl2br(e($trip['trip_notes'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Proof of Delivery (POD) Section -->
        <div class="content-card mb-4">
            <div class="content-card-header d-flex align-items-center justify-content-between">
                <h5 class="m-0 fw-bold"><i class="bi bi-file-earmark-check-fill me-2 text-success"></i> Proof of Delivery (POD)</h5>
                <?php if ($trip['proof_of_delivery_path']): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">Verified Delivery</span>
                <?php endif; ?>
            </div>
            <div class="content-card-body">
                <?php if ($trip['proof_of_delivery_path']): ?>
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <div class="mb-2"><strong>Receiver Name:</strong> <?= e($trip['receiver_name'] ?: 'Not recorded') ?></div>
                            <div class="mb-3 text-muted small">Delivered on <?= formatDateTime($trip['actual_arrival']) ?></div>
                            <a href="<?= BASE_URL ?>/<?= e($trip['proof_of_delivery_path']) ?>" target="_blank" class="btn btn-outline-primary">
                                <i class="bi bi-file-earmark-text me-1"></i> Open Official POD Document
                            </a>
                        </div>
                        <div class="col-md-6 text-center">
                            <img src="<?= BASE_URL ?>/<?= e($trip['proof_of_delivery_path']) ?>" alt="Proof of Delivery" 
                                 class="img-fluid rounded border shadow-sm" style="max-height: 180px;">
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-camera display-6 d-block mb-2 text-light"></i>
                        Proof of delivery not uploaded yet. The driver or dispatcher uploads consignee signature or stamped receipt upon delivery.
                        <?php if (hasAnyRole(['super_admin', 'transport_manager', 'driver'])): ?>
                            <div class="mt-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusModal">
                                    <i class="bi bi-upload me-1"></i> Upload POD Now
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Vehicle, Driver & Financials -->
    <div class="col-lg-4">
        <!-- Vehicle & Driver Card -->
        <div class="content-card mb-4">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-truck me-2 text-primary"></i> Assigned Resources</h5>
            </div>
            <div class="content-card-body">
                <div class="mb-3 pb-3 border-bottom">
                    <span class="text-muted small d-block">Vehicle</span>
                    <strong class="fs-6"><?= e($trip['registration_number']) ?></strong>
                    <div class="text-muted small"><?= e($trip['vehicle_model']) ?> (<?= ucwords(str_replace('_', ' ', $trip['truck_type'])) ?>)</div>
                    <a href="<?= BASE_URL ?>/modules/vehicles/view.php?id=<?= $trip['vehicle_id'] ?>" class="btn btn-sm btn-light border mt-2">
                        <i class="bi bi-truck-front me-1"></i> Truck Specs
                    </a>
                </div>

                <div class="mb-0">
                    <span class="text-muted small d-block">Assigned Driver</span>
                    <strong class="fs-6"><?= e($trip['driver_name']) ?></strong>
                    <div class="text-muted small mb-2">CDL: <?= e($trip['licence_number']) ?></div>
                    <a href="tel:<?= e($trip['driver_phone']) ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-telephone me-1"></i> Call Driver (<?= e($trip['driver_phone']) ?>)
                    </a>
                </div>
            </div>
        </div>

        <!-- Trip Net Profitability Card -->
        <?php if (hasAnyRole(['super_admin', 'transport_manager'])): ?>
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <h5 class="m-0 fw-bold"><i class="bi bi-cash-stack me-2 text-success"></i> Trip Net Profitability</h5>
                </div>
                <div class="content-card-body">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Freight Revenue:</span>
                        <strong class="text-dark"><?= formatCurrency($tripRevenue) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Approved Expenses:</span>
                        <strong class="text-danger">-<?= formatCurrency($totalTripExpenses) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between pt-2">
                        <span class="fw-bold fs-6">Net Margin:</span>
                        <strong class="fs-5 <?= $tripNetProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= formatCurrency($tripNetProfit) ?>
                        </strong>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Status Milestones History Timeline -->
<div class="content-card mb-4">
    <div class="content-card-header">
        <h5 class="m-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i> Journey Milestones History</h5>
    </div>
    <div class="content-card-body">
        <div class="list-group list-group-flush">
            <?php foreach ($history as $h): ?>
                <div class="list-group-item d-flex justify-content-between align-items-start px-0 py-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <?= renderStatusBadge($h['status']) ?>
                            <strong class="text-dark"><?= e($h['location_note'] ?: 'Checkpoint Logged') ?></strong>
                        </div>
                        <?php if ($h['remarks']): ?>
                            <div class="text-muted small"><?= e($h['remarks']) ?></div>
                        <?php endif; ?>
                        <small class="text-light" style="color: #94a3b8 !important;">Logged by <?= e($h['updater_name']) ?></small>
                    </div>
                    <div class="text-end text-muted small">
                        <?= formatDateTime($h['created_at']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Trip Expenses Table -->
<div class="content-card">
    <div class="content-card-header d-flex justify-content-between align-items-center">
        <h5 class="m-0 fw-bold"><i class="bi bi-receipt me-2 text-primary"></i> Recorded En-Route Expenses</h5>
        <a href="<?= BASE_URL ?>/modules/expenses/create.php?trip_id=<?= $trip['id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-circle me-1"></i> Add Expense
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Receipt Ref</th>
                    <th>Recorded By</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No expenses recorded for this trip.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td><?= formatDate($exp['expense_date']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= ucwords(str_replace('_', ' ', $exp['category'])) ?></span></td>
                            <td><?= e($exp['description']) ?></td>
                            <td><?= e($exp['receipt_reference'] ?: '—') ?></td>
                            <td><?= e($exp['creator_name']) ?></td>
                            <td class="fw-bold"><?= formatCurrency($exp['amount']) ?></td>
                            <td><?= renderStatusBadge($exp['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Update Milestone / POD -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="update_status.php" enctype="multipart/form-data" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="trip_id" value="<?= $trip['id'] ?>">

            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-arrow-repeat me-2 text-primary"></i> Update Trip Milestone</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">New Milestone Status <span class="text-danger">*</span></label>
                    <select name="status" id="milestoneSelect" class="form-select" required onchange="togglePodFields(this.value)">
                        <option value="dispatched" <?= $trip['delivery_status'] === 'scheduled' ? 'selected' : '' ?>>Dispatched (Vehicle Loaded & Departed)</option>
                        <option value="in_transit" <?= $trip['delivery_status'] === 'dispatched' ? 'selected' : '' ?>>In Transit (On Highway)</option>
                        <option value="arrived" <?= $trip['delivery_status'] === 'in_transit' ? 'selected' : '' ?>>Arrived at Destination Hub</option>
                        <option value="unloading" <?= $trip['delivery_status'] === 'arrived' ? 'selected' : '' ?>>Unloading Consignee Freight</option>
                        <option value="delivered" <?= $trip['delivery_status'] === 'unloading' ? 'selected' : '' ?>>Delivered & Complete (Requires POD)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Current Checkpoint / Location Note <span class="text-danger">*</span></label>
                    <input type="text" name="location_note" class="form-control" required placeholder="e.g. I-94 Kalamazoo Toll Plaza Mile 88">
                </div>

                <div class="mb-3">
                    <label class="form-label">Remarks / Weather / Journey Updates</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="Smooth transit, cargo tie-downs inspected, weather clear..."></textarea>
                </div>

                <!-- Fields displayed when marking Delivered -->
                <div id="podSection" class="p-3 bg-light rounded border mb-3">
                    <h6 class="fw-bold text-success mb-2"><i class="bi bi-check2-circle me-1"></i> Delivery Confirmation & POD</h6>
                    <div class="mb-2">
                        <label class="form-label small">Consignee / Receiver Name</label>
                        <input type="text" name="receiver_name" class="form-control" placeholder="e.g. John Miller, Receiving Dock 3">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Final Odometer Reading (KM)</label>
                        <input type="number" name="end_odometer" class="form-control" value="<?= (int)$trip['start_odometer'] + 460 ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label small">Upload Proof of Delivery Photo / Stamped Receipt</label>
                        <input type="file" name="pod_file" class="form-control" accept="image/*,.pdf">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Milestone</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePodFields(val) {
    const pod = document.getElementById('podSection');
    if (val === 'delivered') {
        pod.style.display = 'block';
    } else {
        pod.style.display = 'block'; // Keep accessible
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
