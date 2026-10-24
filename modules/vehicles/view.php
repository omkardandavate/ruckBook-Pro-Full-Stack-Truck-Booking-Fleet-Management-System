<?php
/**
 * TruckBook Pro - View Vehicle Profile & Trip History (Module D)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'driver']);

$pdo = getDbConnection();
$vehicleId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
$stmt->execute([$vehicleId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    setFlash('danger', 'Vehicle record not found.');
    redirect('modules/vehicles/index.php');
}

// 1. Vehicle Trip History
$tripStmt = $pdo->prepare("
    SELECT t.id, t.trip_number, t.planned_departure, t.actual_departure, t.distance_km,
           t.fuel_consumed_liters, t.delivery_status, b.booking_number, b.pickup_city, b.delivery_city,
           d.name AS driver_name
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN drivers d ON t.driver_id = d.id
    WHERE t.vehicle_id = ?
    ORDER BY t.created_at DESC
");
$tripStmt->execute([$vehicleId]);
$trips = $tripStmt->fetchAll();

// 2. Active Trip
$activeTripStmt = $pdo->prepare("
    SELECT t.id, t.trip_number, t.planned_departure, t.delivery_status,
           b.booking_number, b.pickup_city, b.delivery_city, b.cargo_description,
           d.name AS driver_name, d.phone AS driver_phone
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN drivers d ON t.driver_id = d.id
    WHERE t.vehicle_id = ? AND t.delivery_status != 'delivered'
    LIMIT 1
");
$activeTripStmt->execute([$vehicleId]);
$activeTrip = $activeTripStmt->fetch();

$today = date('Y-m-d');
$insuranceExpired = $vehicle['insurance_expiry'] < $today;
$permitExpired = $vehicle['permit_expiry'] < $today;
$fitnessExpired = $vehicle['fitness_expiry'] < $today;

$truckTypes = [
    'flatbed' => 'Flatbed',
    'container' => 'Container',
    'refrigerated' => 'Refrigerated',
    'tanker' => 'Tanker',
    'trailer' => 'Trailer',
    'box_truck' => 'Box Truck'
];

$pageTitle = 'Truck: ' . $vehicle['registration_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($vehicle['registration_number']) ?></h2>
            <?= renderStatusBadge($vehicle['status']) ?>
        </div>
        <p class="text-muted mb-0">
            <?= e($vehicle['model']) ?> (<?= $truckTypes[$vehicle['truck_type']] ?? $vehicle['truck_type'] ?>) &bull; Make Year <?= e((string)$vehicle['make_year']) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Fleet Roster
        </a>
        <?php if (hasAnyRole(['super_admin', 'transport_manager'])): ?>
            <a href="edit.php?id=<?= $vehicle['id'] ?>" class="btn btn-primary">
                <i class="bi bi-pencil me-1"></i> Edit Specifications
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Active Trip Alert Banner if on Trip -->
<?php if ($activeTrip): ?>
    <div class="alert alert-primary d-flex align-items-center justify-content-between p-3 mb-4 shadow-sm" role="alert">
        <div class="d-flex align-items-center gap-3">
            <div class="stat-icon primary m-0" style="width: 44px; height: 44px; font-size: 1.25rem;">
                <i class="bi bi-truck"></i>
            </div>
            <div>
                <strong>Active Dispatch: Trip #<?= e($activeTrip['trip_number']) ?></strong>
                <div class="small text-muted">
                    Route: <?= e($activeTrip['pickup_city']) ?> &rarr; <?= e($activeTrip['delivery_city']) ?> | Driver: <?= e($activeTrip['driver_name']) ?> (<?= e($activeTrip['driver_phone']) ?>)
                </div>
            </div>
        </div>
        <div>
            <?= renderStatusBadge($activeTrip['delivery_status']) ?>
            <a href="<?= BASE_URL ?>/modules/trips/view.php?id=<?= $activeTrip['id'] ?>" class="btn btn-sm btn-primary ms-2">
                Live Tracking
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Specifications & Statutory Cards -->
<div class="row g-4 mb-4">
    <!-- Specs -->
    <div class="col-lg-4">
        <div class="content-card h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-sliders me-2 text-primary"></i> Specifications</h5>
            </div>
            <div class="content-card-body">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Truck Body Type</span>
                    <strong><?= $truckTypes[$vehicle['truck_type']] ?? $vehicle['truck_type'] ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Certified Tonnage</span>
                    <strong class="text-primary"><?= number_format((float)$vehicle['capacity_tonnes'], 1) ?> Tonnes</strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Current Odometer</span>
                    <strong><?= number_format((int)$vehicle['current_odometer']) ?> KM</strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Manufacture Year</span>
                    <strong><?= e((string)$vehicle['make_year']) ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="text-muted">Total Completed Trips</span>
                    <strong><?= count($trips) ?> Trips</strong>
                </div>

                <?php if ($vehicle['notes']): ?>
                    <div class="mt-3 p-3 bg-light rounded small">
                        <strong>Notes:</strong> <?= nl2br(e($vehicle['notes'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Statutory Compliance Expirations -->
    <div class="col-lg-8">
        <div class="content-card h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-file-earmark-check-fill me-2 text-success"></i> Regulatory & Inspection Certificates</h5>
            </div>
            <div class="content-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 rounded border <?= $insuranceExpired ? 'border-danger bg-danger-subtle' : 'bg-light' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small">Commercial Insurance</span>
                                <i class="bi <?= $insuranceExpired ? 'bi-exclamation-triangle-fill text-danger' : 'bi-shield-check text-success' ?>"></i>
                            </div>
                            <div class="fs-6 fw-bold <?= $insuranceExpired ? 'text-danger' : 'text-dark' ?>">
                                <?= formatDate($vehicle['insurance_expiry']) ?>
                            </div>
                            <small class="<?= $insuranceExpired ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                <?= $insuranceExpired ? 'EXPIRED — Do not dispatch' : 'Active Policy' ?>
                            </small>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="p-3 rounded border <?= $permitExpired ? 'border-danger bg-danger-subtle' : 'bg-light' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small">Transport Permit</span>
                                <i class="bi <?= $permitExpired ? 'bi-exclamation-triangle-fill text-danger' : 'bi-shield-check text-success' ?>"></i>
                            </div>
                            <div class="fs-6 fw-bold <?= $permitExpired ? 'text-danger' : 'text-dark' ?>">
                                <?= formatDate($vehicle['permit_expiry']) ?>
                            </div>
                            <small class="<?= $permitExpired ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                <?= $permitExpired ? 'EXPIRED — Renewal Required' : 'Interstate Valid' ?>
                            </small>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="p-3 rounded border <?= $fitnessExpired ? 'border-danger bg-danger-subtle' : 'bg-light' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small">Road Fitness Certificate</span>
                                <i class="bi <?= $fitnessExpired ? 'bi-exclamation-triangle-fill text-danger' : 'bi-shield-check text-success' ?>"></i>
                            </div>
                            <div class="fs-6 fw-bold <?= $fitnessExpired ? 'text-danger' : 'text-dark' ?>">
                                <?= formatDate($vehicle['fitness_expiry']) ?>
                            </div>
                            <small class="<?= $fitnessExpired ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                <?= $fitnessExpired ? 'EXPIRED — Inspection Needed' : 'Certified Roadworthy' ?>
                            </small>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>
                    The dispatch engine blocks any vehicle from being assigned to bookings if any of its statutory inspection certificates are expired on the planned delivery date.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Historical Trips Table -->
<div class="content-card">
    <div class="content-card-header">
        <h5 class="m-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i> Vehicle Trip History</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Trip #</th>
                    <th>Booking Ref</th>
                    <th>Route</th>
                    <th>Driver</th>
                    <th>Departure Date</th>
                    <th>Distance</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($trips)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">No historical trips recorded for this vehicle.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($trips as $t): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= e($t['trip_number']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($t['booking_number']) ?></span></td>
                            <td><?= e($t['pickup_city']) ?> &rarr; <?= e($t['delivery_city']) ?></td>
                            <td><?= e($t['driver_name']) ?></td>
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
