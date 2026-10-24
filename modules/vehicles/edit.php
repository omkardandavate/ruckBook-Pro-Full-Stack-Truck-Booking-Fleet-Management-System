<?php
/**
 * TruckBook Pro - Edit Vehicle (Module D)
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
$vehicleId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
$stmt->execute([$vehicleId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    setFlash('danger', 'Vehicle not found.');
    redirect('modules/vehicles/index.php');
}

$truckTypes = [
    'flatbed' => 'Flatbed (Heavy steel, construction)',
    'container' => 'Container Carrier (Standard ISO)',
    'refrigerated' => 'Refrigerated Reefer (Pharma, perishable food)',
    'tanker' => 'Liquid Tanker (Fuel, chemical)',
    'trailer' => 'Heavy Haul Trailer (>30T)',
    'box_truck' => 'Box Truck (City & retail distribution)'
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $regNumber = strtoupper(trim($_POST['registration_number'] ?? ''));
    $truckType = trim($_POST['truck_type'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $capacityTonnes = (float)($_POST['capacity_tonnes'] ?? 0);
    $makeYear = (int)($_POST['make_year'] ?? 0);
    $insuranceExpiry = trim($_POST['insurance_expiry'] ?? '');
    $permitExpiry = trim($_POST['permit_expiry'] ?? '');
    $fitnessExpiry = trim($_POST['fitness_expiry'] ?? '');
    $currentOdometer = (int)($_POST['current_odometer'] ?? 0);
    $status = trim($_POST['status'] ?? 'available');
    $notes = trim($_POST['notes'] ?? '');

    $validator = (new Validator($_POST))
        ->required('registration_number', 'Registration Number')
        ->required('truck_type', 'Truck Type')
        ->in('truck_type', array_keys($truckTypes), 'Truck Type')
        ->required('model', 'Make & Model')
        ->required('capacity_tonnes', 'Tonnage Capacity')
        ->numeric('capacity_tonnes', 'Tonnage Capacity', 0.5, 100.0)
        ->required('make_year', 'Make Year')
        ->required('insurance_expiry', 'Insurance Expiry Date')
        ->date('insurance_expiry', 'Insurance Expiry Date')
        ->required('permit_expiry', 'Permit Expiry Date')
        ->date('permit_expiry', 'Permit Expiry Date')
        ->required('fitness_expiry', 'Fitness Expiry Date')
        ->date('fitness_expiry', 'Fitness Expiry Date');

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        // Prevent duplicate registration number excluding this vehicle
        $dupStmt = $pdo->prepare("SELECT id FROM vehicles WHERE registration_number = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$regNumber, $vehicleId]);
        if ($dupStmt->fetch()) {
            $error = "Another vehicle with registration number '{$regNumber}' is already registered.";
        } else {
            try {
                $upStmt = $pdo->prepare("
                    UPDATE vehicles
                    SET registration_number = ?, truck_type = ?, model = ?, capacity_tonnes = ?,
                        make_year = ?, insurance_expiry = ?, permit_expiry = ?, fitness_expiry = ?,
                        current_odometer = ?, status = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $upStmt->execute([
                    $regNumber, $truckType, $model, $capacityTonnes,
                    $makeYear, $insuranceExpiry, $permitExpiry, $fitnessExpiry,
                    $currentOdometer, $status, $notes ?: null, $vehicleId
                ]);

                logAudit(currentUserId(), 'update_vehicle', 'vehicles', $vehicleId, $vehicle, $_POST);

                setFlash('success', "Vehicle '{$regNumber}' updated successfully.");
                redirect('modules/vehicles/view.php?id=' . $vehicleId);
            } catch (Throwable $e) {
                error_log("Vehicle update failed: " . $e->getMessage());
                $error = "Database error: Could not update vehicle.";
            }
        }
    }
}

$pageTitle = 'Edit Vehicle: ' . $vehicle['registration_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Edit Vehicle Details</h2>
        <p class="text-muted mb-0">Update specifications and compliance certificates for <?= e($vehicle['registration_number']) ?></p>
    </div>
    <div>
        <a href="view.php?id=<?= $vehicleId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> View Vehicle
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

                <form method="POST" action="edit.php?id=<?= $vehicleId ?>" class="needs-validation">
                    <?= csrfField() ?>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-truck-front me-2"></i> Vehicle Specifications</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Registration Number <span class="text-danger">*</span></label>
                            <input type="text" name="registration_number" class="form-control text-uppercase" 
                                   value="<?= e($vehicle['registration_number']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Truck Body Type <span class="text-danger">*</span></label>
                            <select name="truck_type" class="form-select" required>
                                <?php foreach ($truckTypes as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= $vehicle['truck_type'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Make & Model <span class="text-danger">*</span></label>
                            <input type="text" name="model" class="form-control" value="<?= e($vehicle['model']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Capacity (Tonnes) <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" name="capacity_tonnes" class="form-control" 
                                   value="<?= e((string)$vehicle['capacity_tonnes']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Manufacture Year <span class="text-danger">*</span></label>
                            <input type="number" name="make_year" class="form-control" 
                                   value="<?= e((string)$vehicle['make_year']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Odometer (KM)</label>
                            <input type="number" name="current_odometer" class="form-control" 
                                   value="<?= e((string)$vehicle['current_odometer']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Operational Status</label>
                            <select name="status" class="form-select">
                                <option value="available" <?= $vehicle['status'] === 'available' ? 'selected' : '' ?>>Available for Dispatch</option>
                                <option value="on_trip" <?= $vehicle['status'] === 'on_trip' ? 'selected' : '' ?>>On Trip</option>
                                <option value="maintenance" <?= $vehicle['status'] === 'maintenance' ? 'selected' : '' ?>>In Maintenance</option>
                                <option value="inactive" <?= $vehicle['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-shield-check me-2"></i> Statutory & Inspection Expiry Dates</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Insurance Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" name="insurance_expiry" class="form-control" 
                                   value="<?= e($vehicle['insurance_expiry']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Commercial Permit Expiry <span class="text-danger">*</span></label>
                            <input type="date" name="permit_expiry" class="form-control" 
                                   value="<?= e($vehicle['permit_expiry']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Road Fitness Expiry <span class="text-danger">*</span></label>
                            <input type="date" name="fitness_expiry" class="form-control" 
                                   value="<?= e($vehicle['fitness_expiry']) ?>" required>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-journal-text me-2"></i> Maintenance Notes</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <textarea name="notes" class="form-control" rows="3"><?= e($vehicle['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="view.php?id=<?= $vehicleId ?>" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
