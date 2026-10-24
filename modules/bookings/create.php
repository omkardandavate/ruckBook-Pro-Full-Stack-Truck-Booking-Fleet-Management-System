<?php
/**
 * TruckBook Pro - Create Booking & Dispatch Assignment (Module G)
 * Incorporates strict collision prevention and automated trip generation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator']);

$pdo = getDbConnection();
$quoteId = (int)($_GET['quote_id'] ?? 0);

// If converting from quotation, fetch quote details
$quoteData = null;
if ($quoteId > 0) {
    $qStmt = $pdo->prepare("
        SELECT q.*, c.company_name, 
               fr.pickup_address, fr.delivery_address, fr.pickup_city, fr.delivery_city,
               fr.cargo_description, fr.cargo_weight_tonnes, fr.preferred_date, fr.truck_type_required
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        JOIN freight_requests fr ON q.freight_request_id = fr.id
        WHERE q.id = ?
    ");
    $qStmt->execute([$quoteId]);
    $quoteData = $qStmt->fetch();
}

$customers = $pdo->query("SELECT id, company_name, contact_person, city FROM customers WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();

// Available Vehicles (Only Available & Inspection Compliant)
$today = date('Y-m-d');
$availableVehicles = $pdo->prepare("
    SELECT id, registration_number, truck_type, model, capacity_tonnes, insurance_expiry, permit_expiry, fitness_expiry
    FROM vehicles
    WHERE status = 'available'
      AND insurance_expiry >= ?
      AND permit_expiry >= ?
      AND fitness_expiry >= ?
    ORDER BY capacity_tonnes ASC
");
$availableVehicles->execute([$today, $today, $today]);
$vehicles = $availableVehicles->fetchAll();

// Available Drivers (Only Available & Active License)
$availableDrivers = $pdo->prepare("
    SELECT id, name, phone, licence_number, licence_expiry, experience_years
    FROM drivers
    WHERE status = 'available'
      AND licence_expiry >= ?
    ORDER BY name ASC
");
$availableDrivers->execute([$today]);
$drivers = $availableDrivers->fetchAll();

$error = '';
$formData = [
    'quotation_id' => $quoteData ? (string)$quoteData['id'] : '',
    'freight_request_id' => $quoteData ? (string)$quoteData['freight_request_id'] : '',
    'customer_id' => $quoteData ? (string)$quoteData['customer_id'] : '',
    'pickup_city' => $quoteData ? $quoteData['pickup_city'] : '',
    'delivery_city' => $quoteData ? $quoteData['delivery_city'] : '',
    'pickup_address' => $quoteData ? $quoteData['pickup_address'] : '',
    'delivery_address' => $quoteData ? $quoteData['delivery_address'] : '',
    'cargo_description' => $quoteData ? $quoteData['cargo_description'] : '',
    'cargo_weight_tonnes' => $quoteData ? (string)$quoteData['cargo_weight_tonnes'] : '',
    'planned_pickup_date' => $quoteData ? $quoteData['preferred_date'] : date('Y-m-d'),
    'planned_delivery_date' => $quoteData ? date('Y-m-d', strtotime($quoteData['preferred_date'] . ' +1 day')) : date('Y-m-d', strtotime('+1 day')),
    'agreed_rate' => $quoteData ? (string)$quoteData['total_amount'] : '',
    'vehicle_id' => '',
    'driver_id' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    $validator = (new Validator($_POST))
        ->required('customer_id', 'Customer')
        ->required('pickup_city', 'Pickup City')
        ->required('pickup_address', 'Pickup Address')
        ->required('delivery_city', 'Delivery City')
        ->required('delivery_address', 'Delivery Address')
        ->required('cargo_description', 'Cargo Description')
        ->required('cargo_weight_tonnes', 'Cargo Weight')
        ->numeric('cargo_weight_tonnes', 'Cargo Weight', 0.1)
        ->required('planned_pickup_date', 'Pickup Date')
        ->date('planned_pickup_date', 'Pickup Date')
        ->required('planned_delivery_date', 'Delivery Date')
        ->date('planned_delivery_date', 'Delivery Date')
        ->dateAfterOrEqual('planned_delivery_date', 'planned_pickup_date', 'Delivery Date', 'Pickup Date')
        ->required('agreed_rate', 'Agreed Freight Rate')
        ->numeric('agreed_rate', 'Agreed Freight Rate', 1.0);

    $vehId = !empty($formData['vehicle_id']) ? (int)$formData['vehicle_id'] : null;
    $drvId = !empty($formData['driver_id']) ? (int)$formData['driver_id'] : null;

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Collision Prevention Check: Vehicle
            if ($vehId !== null) {
                $vCheck = $pdo->prepare("SELECT status, capacity_tonnes, insurance_expiry FROM vehicles WHERE id = ? FOR UPDATE");
                $vCheck->execute([$vehId]);
                $vRow = $vCheck->fetch();

                if (!$vRow || $vRow['status'] !== 'available') {
                    throw new RuntimeException("Selected vehicle is no longer available or was assigned in parallel.");
                }
                if ((float)$vRow['capacity_tonnes'] < (float)$formData['cargo_weight_tonnes']) {
                    throw new RuntimeException("Vehicle capacity ({$vRow['capacity_tonnes']}T) is insufficient for cargo weight ({$formData['cargo_weight_tonnes']}T).");
                }
                if ($vRow['insurance_expiry'] < $formData['planned_delivery_date']) {
                    throw new RuntimeException("Vehicle insurance policy expires before the planned delivery date.");
                }
            }

            // 2. Collision Prevention Check: Driver
            if ($drvId !== null) {
                $dCheck = $pdo->prepare("SELECT status, licence_expiry, name, user_id FROM drivers WHERE id = ? FOR UPDATE");
                $dCheck->execute([$drvId]);
                $dRow = $dCheck->fetch();

                if (!$dRow || $dRow['status'] !== 'available') {
                    throw new RuntimeException("Selected driver is no longer available or was assigned to another active trip.");
                }
                if ($dRow['licence_expiry'] < $formData['planned_delivery_date']) {
                    throw new RuntimeException("Driver's commercial licence expires before the planned delivery date.");
                }
            }

            // 3. Generate Booking Reference: BKG-YYYY-XXXX
            $year = date('Y');
            $bCount = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_number LIKE ?");
            $bCount->execute(["BKG-{$year}-%"]);
            $seq = (int)$bCount->fetchColumn() + 1;
            $bookingNumber = sprintf("BKG-%s-%04d", $year, $seq);

            $status = ($vehId && $drvId) ? 'confirmed' : 'pending_assignment';

            $bStmt = $pdo->prepare("
                INSERT INTO bookings (
                    booking_number, quotation_id, freight_request_id, customer_id, vehicle_id, driver_id,
                    pickup_address, delivery_address, pickup_city, delivery_city, cargo_description,
                    cargo_weight_tonnes, planned_pickup_date, planned_delivery_date, agreed_rate,
                    status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $bStmt->execute([
                $bookingNumber,
                $formData['quotation_id'] ? (int)$formData['quotation_id'] : null,
                $formData['freight_request_id'] ? (int)$formData['freight_request_id'] : null,
                (int)$formData['customer_id'],
                $vehId,
                $drvId,
                $formData['pickup_address'],
                $formData['delivery_address'],
                $formData['pickup_city'],
                $formData['delivery_city'],
                $formData['cargo_description'],
                (float)$formData['cargo_weight_tonnes'],
                $formData['planned_pickup_date'],
                $formData['planned_delivery_date'],
                (float)$formData['agreed_rate'],
                $status,
                currentUserId()
            ]);
            $bookingId = (int)$pdo->lastInsertId();

            // 4. If Vehicle & Driver are assigned, automatically provision Trip record
            if ($vehId && $drvId) {
                $tCount = $pdo->prepare("SELECT COUNT(*) FROM trips WHERE trip_number LIKE ?");
                $tCount->execute(["TRP-{$year}-%"]);
                $tSeq = (int)$tCount->fetchColumn() + 1;
                $tripNumber = sprintf("TRP-%s-%04d", $year, $tSeq);

                // Fetch vehicle current odometer
                $odoStmt = $pdo->prepare("SELECT current_odometer FROM vehicles WHERE id = ?");
                $odoStmt->execute([$vehId]);
                $startOdometer = (int)$odoStmt->fetchColumn();

                $tripStmt = $pdo->prepare("
                    INSERT INTO trips (
                        trip_number, booking_id, vehicle_id, driver_id, planned_departure,
                        estimated_arrival, start_odometer, delivery_status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled', NOW())
                ");
                $plannedDep = $formData['planned_pickup_date'] . ' 08:00:00';
                $estArr = $formData['planned_delivery_date'] . ' 18:00:00';
                $tripStmt->execute([
                    $tripNumber,
                    $bookingId,
                    $vehId,
                    $drvId,
                    $plannedDep,
                    $estArr,
                    $startOdometer
                ]);
                $tripId = (int)$pdo->lastInsertId();

                // Lock vehicle and driver statuses
                $pdo->prepare("UPDATE vehicles SET status = 'on_trip', updated_at = NOW() WHERE id = ?")->execute([$vehId]);
                $pdo->prepare("UPDATE drivers SET status = 'on_trip', updated_at = NOW() WHERE id = ?")->execute([$drvId]);

                // Initial milestone log
                $pdo->prepare("
                    INSERT INTO trip_status_history (trip_id, status, location_note, remarks, updated_by, created_at)
                    VALUES (?, 'scheduled', ?, 'Trip auto-provisioned upon booking confirmation.', ?, NOW())
                ")->execute([$tripId, $formData['pickup_city'], currentUserId()]);

                // Notify assigned driver if portal login exists
                if (!empty($dRow['user_id'])) {
                    createNotification(
                        (int)$dRow['user_id'],
                        "New Trip Assignment: {$tripNumber}",
                        "You have been assigned to trip {$tripNumber} ({$formData['pickup_city']} to {$formData['delivery_city']}). Departure: {$formData['planned_pickup_date']}.",
                        "modules/trips/view.php?id={$tripId}",
                        'info'
                    );
                }
            }

            // 5. If linked to quotation or freight request, update their statuses
            if (!empty($formData['quotation_id'])) {
                $pdo->prepare("UPDATE quotations SET status = 'accepted', updated_at = NOW() WHERE id = ?")->execute([(int)$formData['quotation_id']]);
            }
            if (!empty($formData['freight_request_id'])) {
                $pdo->prepare("UPDATE freight_requests SET status = 'booked', updated_at = NOW() WHERE id = ?")->execute([(int)$formData['freight_request_id']]);
            }

            logAudit(currentUserId(), 'create_booking', 'bookings', $bookingId, null, [
                'booking_number' => $bookingNumber,
                'vehicle_id' => $vehId,
                'driver_id' => $drvId
            ]);

            $pdo->commit();

            setFlash('success', "Booking {$bookingNumber} created successfully!" . ($vehId && $drvId ? " Trip {$tripNumber} auto-scheduled." : ""));
            redirect('modules/bookings/view.php?id=' . $bookingId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Booking creation error: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Booking & Dispatch';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Create Booking & Assign Dispatch</h2>
        <p class="text-muted mb-0">Confirm transport booking and allocate verified available vehicles and licensed drivers.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Bookings
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

                <?php if ($quoteData): ?>
                    <div class="alert alert-success d-flex align-items-center justify-content-between mb-4">
                        <div>
                            <strong>Converting Accepted Quotation #<?= e($quoteData['quotation_number']) ?></strong>
                            <div class="small">Customer: <?= e($quoteData['company_name']) ?> | Agreed Rate: <?= formatCurrency($quoteData['total_amount']) ?></div>
                        </div>
                        <span class="badge bg-success">Pre-Filled</span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="create.php<?= $quoteId ? '?quote_id=' . $quoteId : '' ?>" class="needs-validation">
                    <?= csrfField() ?>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-building me-2"></i> 1. Customer & Cargo Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Client / Customer Company <span class="text-danger">*</span></label>
                            <select name="customer_id" class="form-select" required>
                                <option value="">-- Choose Customer --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $formData['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>>
                                        <?= e($c['company_name']) ?> (<?= e($c['contact_person']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Agreed Freight Rate (<?= getSetting('currency_symbol', '$') ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="agreed_rate" class="form-control fw-bold fs-6 text-primary" 
                                   value="<?= e($formData['agreed_rate']) ?>" required placeholder="2719.90">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Cargo Description <span class="text-danger">*</span></label>
                            <input type="text" name="cargo_description" class="form-control" 
                                   value="<?= e($formData['cargo_description']) ?>" required placeholder="e.g. Industrial Automation Equipment">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cargo Weight (Tonnes) <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" name="cargo_weight_tonnes" class="form-control" 
                                   value="<?= e($formData['cargo_weight_tonnes']) ?>" required placeholder="18.5">
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-geo-alt me-2"></i> 2. Journey Locations & Scheduled Dates</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Pickup City <span class="text-danger">*</span></label>
                            <input type="text" name="pickup_city" class="form-control" value="<?= e($formData['pickup_city']) ?>" required placeholder="Chicago, IL">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Destination City <span class="text-danger">*</span></label>
                            <input type="text" name="delivery_city" class="form-control" value="<?= e($formData['delivery_city']) ?>" required placeholder="Detroit, MI">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Complete Pickup Facility Address <span class="text-danger">*</span></label>
                            <textarea name="pickup_address" class="form-control" rows="2" required><?= e($formData['pickup_address']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Complete Delivery Facility Address <span class="text-danger">*</span></label>
                            <textarea name="delivery_address" class="form-control" rows="2" required><?= e($formData['delivery_address']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Planned Loading / Pickup Date <span class="text-danger">*</span></label>
                            <input type="date" name="planned_pickup_date" class="form-control" value="<?= e($formData['planned_pickup_date']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Planned Arrival / Delivery Date <span class="text-danger">*</span></label>
                            <input type="date" name="planned_delivery_date" class="form-control" value="<?= e($formData['planned_delivery_date']) ?>" required>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-shield-check me-2"></i> 3. Fleet Resource Allocation (Collision-Free)</h5>
                    <div class="p-3 bg-light rounded-3 mb-4 border">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Assign Roadworthy Truck</label>
                                <select name="vehicle_id" class="form-select">
                                    <option value="">-- Leave Unassigned (Assign Later) --</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?= $v['id'] ?>" <?= $formData['vehicle_id'] === (string)$v['id'] ? 'selected' : '' ?>>
                                            <?= e($v['registration_number']) ?> &mdash; <?= e($v['model']) ?> (<?= $v['capacity_tonnes'] ?>T | <?= ucwords(str_replace('_', ' ', $v['truck_type'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block mt-1">Filtered: Only ready trucks with active insurance, road permit, and fitness certificates.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Assign Licensed Fleet Driver</label>
                                <select name="driver_id" class="form-select">
                                    <option value="">-- Leave Unassigned (Assign Later) --</option>
                                    <?php foreach ($drivers as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= $formData['driver_id'] === (string)$d['id'] ? 'selected' : '' ?>>
                                            <?= e($d['name']) ?> &mdash; <?= e($d['licence_number']) ?> (<?= $d['experience_years'] ?> Yrs Exp &bull; Ph: <?= e($d['phone']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block mt-1">Filtered: Only unassigned drivers with commercial licenses valid through trip completion.</small>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-calendar2-check-fill me-1"></i> Confirm & Schedule Dispatch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
