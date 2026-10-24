<?php
/**
 * TruckBook Pro - View Booking Details & Confirmation Manifest (Module G)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer']);

$pdo = getDbConnection();
$bookingId = (int)($_GET['id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

$sql = "
    SELECT b.*, 
           c.company_name, c.contact_person, c.phone AS customer_phone, c.email AS customer_email,
           c.user_id AS customer_user_id,
           v.registration_number, v.truck_type, v.model AS vehicle_model, v.capacity_tonnes,
           d.name AS driver_name, d.phone AS driver_phone, d.licence_number,
           u.name AS creator_name,
           (SELECT t.id FROM trips t WHERE t.booking_id = b.id LIMIT 1) AS trip_id,
           (SELECT t.trip_number FROM trips t WHERE t.booking_id = b.id LIMIT 1) AS trip_number,
           (SELECT t.delivery_status FROM trips t WHERE t.booking_id = b.id LIMIT 1) AS delivery_status,
           (SELECT inv.id FROM invoices inv WHERE inv.booking_id = b.id LIMIT 1) AS invoice_id,
           (SELECT inv.invoice_number FROM invoices inv WHERE inv.booking_id = b.id LIMIT 1) AS invoice_number
    FROM bookings b
    JOIN customers c ON b.customer_id = c.id
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN drivers d ON b.driver_id = d.id
    JOIN users u ON b.created_by = u.id
    WHERE b.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('danger', 'Booking record not found.');
    redirect('modules/bookings/index.php');
}

// Ownership check for customer
if ($currentRole === 'customer' && (int)$booking['customer_user_id'] !== $userId) {
    setFlash('danger', 'Unauthorized access to booking.');
    redirect('dashboard.php');
}

// Handle Cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
    verifyCsrfOrDie();

    if (!hasAnyRole(['super_admin', 'transport_manager'])) {
        setFlash('danger', 'Unauthorized to cancel confirmed bookings.');
        redirect('modules/bookings/view.php?id=' . $bookingId);
    }

    $reason = trim($_POST['cancellation_reason'] ?? '');
    if ($reason === '') {
        $reason = 'Cancelled by operations manager.';
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_reason = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$reason, $bookingId]);

        // Release vehicle and driver if allocated
        if ($booking['vehicle_id']) {
            $pdo->prepare("UPDATE vehicles SET status = 'available', updated_at = NOW() WHERE id = ?")->execute([$booking['vehicle_id']]);
        }
        if ($booking['driver_id']) {
            $pdo->prepare("UPDATE drivers SET status = 'available', updated_at = NOW() WHERE id = ?")->execute([$booking['driver_id']]);
        }
        if ($booking['trip_id']) {
            $pdo->prepare("UPDATE trips SET delivery_status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$booking['trip_id']]);
        }

        logAudit($userId, 'cancel_booking', 'bookings', $bookingId, ['status' => $booking['status']], ['status' => 'cancelled', 'reason' => $reason]);

        $pdo->commit();

        setFlash('warning', "Booking {$booking['booking_number']} has been cancelled and resources returned to availability.");
        redirect('modules/bookings/view.php?id=' . $bookingId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Cancellation error: " . $e->getMessage());
        setFlash('danger', 'Failed to cancel booking.');
    }
}

$pageTitle = 'Booking Manifest: ' . $booking['booking_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-print-none">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($booking['booking_number']) ?></h2>
            <?= renderStatusBadge($booking['status']) ?>
        </div>
        <p class="text-muted mb-0">Confirmed by <?= e($booking['creator_name']) ?> on <?= formatDateTime($booking['created_at']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="button" class="btn btn-light border" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Manifest
        </button>

        <?php if ($booking['trip_id']): ?>
            <a href="<?= BASE_URL ?>/modules/trips/view.php?id=<?= $booking['trip_id'] ?>" class="btn btn-primary">
                <i class="bi bi-geo-alt-fill me-1"></i> Trip Tracking (#<?= e($booking['trip_number']) ?>)
            </a>
        <?php endif; ?>

        <?php if (in_array($booking['status'], ['pending_assignment', 'confirmed'], true) && hasAnyRole(['super_admin', 'transport_manager'])): ?>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                <i class="bi bi-x-circle me-1"></i> Cancel Booking
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Printable Booking Manifest Card -->
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
                        Dispatch Hotline: <?= getSetting('company_phone') ?> | Email: <?= getSetting('company_email') ?>
                    </div>
                </div>

                <div class="text-sm-end">
                    <span class="badge bg-success fs-6 px-3 py-2 mb-2">TRANSPORT BOOKING CONFIRMATION</span>
                    <div class="fw-bold fs-5 text-dark"><?= e($booking['booking_number']) ?></div>
                    <div class="text-muted small">Status: <strong><?= strtoupper(str_replace('_', ' ', $booking['status'])) ?></strong></div>
                </div>
            </div>

            <!-- Client & Shipment Overview -->
            <div class="row g-4 mb-4">
                <div class="col-sm-6">
                    <span class="text-muted small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Customer / Consignor</span>
                    <h5 class="fw-bold text-dark mb-1"><?= e($booking['company_name']) ?></h5>
                    <div class="text-muted small">
                        Representative: <strong><?= e($booking['contact_person']) ?></strong><br>
                        Phone: <?= e($booking['customer_phone']) ?> | Email: <?= e($booking['customer_email']) ?>
                    </div>
                </div>

                <div class="col-sm-6 text-sm-end">
                    <span class="text-muted small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Agreed Freight Tariff</span>
                    <div class="fs-4 fw-bold text-primary"><?= formatCurrency($booking['agreed_rate']) ?></div>
                    <small class="text-muted">Includes line-haul, tolls, and scheduled staging</small>
                </div>
            </div>

            <!-- Route Details -->
            <div class="p-3 bg-light rounded-3 mb-4">
                <div class="row g-3">
                    <div class="col-md-6 border-end-md">
                        <span class="badge bg-primary-subtle text-primary mb-2">ORIGIN PICKUP</span>
                        <div class="fs-5 fw-bold text-dark"><?= e($booking['pickup_city']) ?></div>
                        <div class="text-muted small mb-2"><?= nl2br(e($booking['pickup_address'])) ?></div>
                        <div class="small"><strong>Scheduled Pickup:</strong> <?= formatDate($booking['planned_pickup_date']) ?></div>
                    </div>

                    <div class="col-md-6 ps-md-4">
                        <span class="badge bg-success-subtle text-success mb-2">DESTINATION UNLOADING</span>
                        <div class="fs-5 fw-bold text-dark"><?= e($booking['delivery_city']) ?></div>
                        <div class="text-muted small mb-2"><?= nl2br(e($booking['delivery_address'])) ?></div>
                        <div class="small"><strong>Target Delivery:</strong> <?= formatDate($booking['planned_delivery_date']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Cargo & Allocated Fleet Resources -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <h6 class="fw-bold text-primary mb-2"><i class="bi bi-box-seam me-1"></i> Cargo Manifest</h6>
                        <div class="mb-2"><strong>Cargo:</strong> <?= e($booking['cargo_description']) ?></div>
                        <div class="mb-0"><strong>Certified Weight:</strong> <?= number_format((float)$booking['cargo_weight_tonnes'], 2) ?> Metric Tonnes</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <h6 class="fw-bold text-success mb-2"><i class="bi bi-truck me-1"></i> Allocated Fleet Resources</h6>
                        <?php if ($booking['vehicle_id']): ?>
                            <div class="mb-1">
                                <strong>Vehicle:</strong> <?= e($booking['registration_number']) ?> &bull; <?= e($booking['vehicle_model']) ?> (<?= $booking['capacity_tonnes'] ?>T)
                            </div>
                        <?php else: ?>
                            <div class="text-warning mb-1"><i class="bi bi-exclamation-triangle"></i> Vehicle Unassigned</div>
                        <?php endif; ?>

                        <?php if ($booking['driver_id']): ?>
                            <div class="mb-0">
                                <strong>Assigned Driver:</strong> <?= e($booking['driver_name']) ?> (Ph: <?= e($booking['driver_phone']) ?> | CDL: <?= e($booking['licence_number']) ?>)
                            </div>
                        <?php else: ?>
                            <div class="text-warning mb-0"><i class="bi bi-exclamation-triangle"></i> Driver Unassigned</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($booking['cancellation_reason']): ?>
                <div class="alert alert-danger mb-4">
                    <strong>Cancellation Record:</strong> <?= e($booking['cancellation_reason']) ?>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center pt-4 border-top text-muted small">
                <div>Consignor Signature: _______________________</div>
                <div>Carrier Dispatch Signature: _______________________</div>
            </div>
        </div>
    </div>
</div>

<!-- Cancellation Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="view.php?id=<?= $booking['id'] ?>" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_booking">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-octagon me-1"></i> Cancel Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Cancelling this booking will release the assigned vehicle <strong><?= e($booking['registration_number'] ?: 'None') ?></strong> and driver <strong><?= e($booking['driver_name'] ?: 'None') ?></strong> back to available status.</p>
                <div class="mb-3">
                    <label class="form-label">Reason for Cancellation <span class="text-danger">*</span></label>
                    <textarea name="cancellation_reason" class="form-control" rows="3" required placeholder="e.g. Client requested postponement, cargo not ready, etc."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
