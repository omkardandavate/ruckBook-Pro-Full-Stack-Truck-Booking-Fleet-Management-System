<?php
/**
 * TruckBook Pro - View Driver Profile & Assignment History (Module E)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'driver']);

$pdo = getDbConnection();
$driverId = (int)($_GET['id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

// If driver role, restrict to viewing their own record
if ($currentRole === 'driver') {
    $myDrvStmt = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ? LIMIT 1");
    $myDrvStmt->execute([$userId]);
    $myDrvId = (int)$myDrvStmt->fetchColumn();
    if ($driverId !== $myDrvId) {
        $driverId = $myDrvId;
    }
}

$stmt = $pdo->prepare("SELECT * FROM drivers WHERE id = ?");
$stmt->execute([$driverId]);
$driver = $stmt->fetch();

if (!$driver) {
    setFlash('danger', 'Driver record not found.');
    redirect('modules/drivers/index.php');
}

// 1. Historical Trips
$tripStmt = $pdo->prepare("
    SELECT t.id, t.trip_number, t.planned_departure, t.actual_departure, t.actual_arrival,
           t.distance_km, t.fuel_consumed_liters, t.delivery_status,
           b.booking_number, b.pickup_city, b.delivery_city,
           v.registration_number, v.model AS vehicle_model
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN vehicles v ON t.vehicle_id = v.id
    WHERE t.driver_id = ?
    ORDER BY t.created_at DESC
");
$tripStmt->execute([$driverId]);
$trips = $tripStmt->fetchAll();

// 2. Active Trip
$activeTripStmt = $pdo->prepare("
    SELECT t.id, t.trip_number, t.planned_departure, t.delivery_status,
           b.booking_number, b.pickup_city, b.delivery_city, b.cargo_description,
           v.registration_number, v.model AS vehicle_model
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN vehicles v ON t.vehicle_id = v.id
    WHERE t.driver_id = ? AND t.delivery_status != 'delivered'
    LIMIT 1
");
$activeTripStmt->execute([$driverId]);
$activeTrip = $activeTripStmt->fetch();

$today = date('Y-m-d');
$licenceExpired = $driver['licence_expiry'] < $today;

$pageTitle = 'Driver: ' . $driver['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($driver['name']) ?></h2>
            <?= renderStatusBadge($driver['status']) ?>
        </div>
        <p class="text-muted mb-0">Driver ID #<?= str_pad((string)$driver['id'], 5, '0', STR_PAD_LEFT) ?> &bull; <?= e((string)$driver['experience_years']) ?> Years Experience</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($currentRole !== 'driver'): ?>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Drivers Roster
            </a>
            <?php if (hasAnyRole(['super_admin', 'transport_manager'])): ?>
                <a href="edit.php?id=<?= $driver['id'] ?>" class="btn btn-primary">
                    <i class="bi bi-pencil me-1"></i> Edit Driver
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Active Trip Alert Banner if on Trip -->
<?php if ($activeTrip): ?>
    <div class="alert alert-primary d-flex align-items-center justify-content-between p-3 mb-4 shadow-sm" role="alert">
        <div class="d-flex align-items-center gap-3">
            <div class="stat-icon primary m-0" style="width: 44px; height: 44px; font-size: 1.25rem;">
                <i class="bi bi-geo-alt-fill"></i>
            </div>
            <div>
                <strong>Active Trip Dispatched: #<?= e($activeTrip['trip_number']) ?></strong>
                <div class="small text-muted">
                    Route: <?= e($activeTrip['pickup_city']) ?> &rarr; <?= e($activeTrip['delivery_city']) ?> | Vehicle: <?= e($activeTrip['registration_number']) ?> (<?= e($activeTrip['vehicle_model']) ?>)
                </div>
            </div>
        </div>
        <div>
            <?= renderStatusBadge($activeTrip['delivery_status']) ?>
            <a href="<?= BASE_URL ?>/modules/trips/view.php?id=<?= $activeTrip['id'] ?>" class="btn btn-sm btn-primary ms-2">
                Trip Operations
            </a>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Driver Profile -->
    <div class="col-lg-4">
        <div class="content-card h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-person-lines-fill me-2 text-primary"></i> Personal Information</h5>
            </div>
            <div class="content-card-body">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Phone Number</span>
                    <strong><?= e($driver['phone']) ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Email Address</span>
                    <strong><?= e($driver['email'] ?: 'Not Provided') ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Driving Experience</span>
                    <strong><?= e((string)$driver['experience_years']) ?> Years</strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Portal Login Linked</span>
                    <span><?= $driver['user_id'] ? '<span class="badge bg-success-subtle text-success">Active Account</span>' : '<span class="badge bg-light text-muted border">None</span>' ?></span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="text-muted">Total Trips Completed</span>
                    <strong><?= count($trips) ?> Trips</strong>
                </div>

                <?php if ($driver['address']): ?>
                    <div class="mt-3 p-3 bg-light rounded small">
                        <strong>Residential Address:</strong><br><?= nl2br(e($driver['address'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Commercial License Status -->
    <div class="col-lg-8">
        <div class="content-card h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-card-heading me-2 text-success"></i> Commercial Driver's Licence (CDL)</h5>
            </div>
            <div class="content-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border">
                            <span class="text-muted small d-block mb-1">CDL Licence Number</span>
                            <strong class="fs-5 text-dark font-monospace"><?= e($driver['licence_number']) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded border <?= $licenceExpired ? 'border-danger bg-danger-subtle' : 'bg-light' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small">Licence Expiration Date</span>
                                <i class="bi <?= $licenceExpired ? 'bi-exclamation-triangle-fill text-danger' : 'bi-check-circle-fill text-success' ?>"></i>
                            </div>
                            <div class="fs-5 fw-bold <?= $licenceExpired ? 'text-danger' : 'text-dark' ?>">
                                <?= formatDate($driver['licence_expiry']) ?>
                            </div>
                            <small class="<?= $licenceExpired ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                <?= $licenceExpired ? 'EXPIRED — Cannot assign to trips' : 'Valid and active' ?>
                            </small>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="bi bi-shield-check me-1"></i>
                    The dispatch validator cross-checks driver license expiration dates against planned trip delivery dates. Drivers with expired licenses are prevented from receiving active dispatches.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Historical Trips Table -->
<div class="content-card">
    <div class="content-card-header">
        <h5 class="m-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i> Driver Journey History</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Trip #</th>
                    <th>Booking Ref</th>
                    <th>Route</th>
                    <th>Vehicle</th>
                    <th>Departure Date</th>
                    <th>Distance</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($trips)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">No historical trips recorded for this driver.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($trips as $t): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= e($t['trip_number']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($t['booking_number']) ?></span></td>
                            <td><?= e($t['pickup_city']) ?> &rarr; <?= e($t['delivery_city']) ?></td>
                            <td><?= e($t['registration_number']) ?> <small class="text-muted">(<?= e($t['vehicle_model']) ?>)</small></td>
                            <td><?= formatDateTime($t['actual_departure'] ?: $t['planned_departure']) ?></td>
                            <td><?= number_format((float)$t['distance_km']) ?> KM</td>
                            <td><?= renderStatusBadge($t['delivery_status']) ?></td>
                            <td class="text-end">
                                <a href="<?= BASE_URL ?>/modules/trips/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-light border">
                                    <i class="bi bi-eye"></i> Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
