<?php
/**
 * TruckBook Pro - Register New Vehicle (Module D)
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
$error = '';

$truckTypes = [
    'flatbed' => 'Flatbed (Heavy steel, construction)',
    'container' => 'Container Carrier (Standard ISO)',
    'refrigerated' => 'Refrigerated Reefer (Pharma, perishable food)',
    'tanker' => 'Liquid Tanker (Fuel, chemical)',
    'trailer' => 'Heavy Haul Trailer (>30T)',
    'box_truck' => 'Box Truck (City & retail distribution)'
];

$formData = [
    'registration_number' => '',
    'truck_type' => 'container',
    'model' => '',
    'capacity_tonnes' => '',
    'make_year' => (string)date('Y'),
    'insurance_expiry' => date('Y-m-d', strtotime('+1 year')),
    'permit_expiry' => date('Y-m-d', strtotime('+1 year')),
    'fitness_expiry' => date('Y-m-d', strtotime('+1 year')),
    'current_odometer' => '0',
    'status' => 'available',
    'notes' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    $validator = (new Validator($_POST))
        ->required('registration_number', 'Registration Number')
        ->required('truck_type', 'Truck Type')
        ->in('truck_type', array_keys($truckTypes), 'Truck Type')
        ->required('model', 'Make & Model')
        ->required('capacity_tonnes', 'Tonnage Capacity')
        ->numeric('capacity_tonnes', 'Tonnage Capacity', 0.5, 100.0)
        ->required('make_year', 'Make Year')
        ->integer('make_year', 'Make Year', 1990, (int)date('Y') + 1)
        ->required('insurance_expiry', 'Insurance Expiry Date')
        ->date('insurance_expiry', 'Insurance Expiry Date')
        ->required('permit_expiry', 'Permit Expiry Date')
        ->date('permit_expiry', 'Permit Expiry Date')
        ->required('fitness_expiry', 'Fitness Expiry Date')
        ->date('fitness_expiry', 'Fitness Expiry Date')
        ->numeric('current_odometer', 'Current Odometer Reading', 0);

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        // Check duplicate registration number
        $dupStmt = $pdo->prepare("SELECT id FROM vehicles WHERE registration_number = ? LIMIT 1");
        $dupStmt->execute([strtoupper($formData['registration_number'])]);
        if ($dupStmt->fetch()) {
            $error = "A vehicle with registration number '{$formData['registration_number']}' already exists in the fleet.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles (
                        registration_number, truck_type, model, capacity_tonnes, make_year,
                        insurance_expiry, permit_expiry, fitness_expiry, current_odometer, status, notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    strtoupper($formData['registration_number']),
                    $formData['truck_type'],
                    $formData['model'],
                    (float)$formData['capacity_tonnes'],
                    (int)$formData['make_year'],
                    $formData['insurance_expiry'],
                    $formData['permit_expiry'],
                    $formData['fitness_expiry'],
                    (int)$formData['current_odometer'],
                    $formData['status'],
                    $formData['notes'] ?: null
                ]);
                $vehicleId = (int)$pdo->lastInsertId();

                logAudit(currentUserId(), 'create_vehicle', 'vehicles', $vehicleId, null, $formData);

                setFlash('success', "Vehicle '{$formData['registration_number']}' added to the fleet.");
                redirect('modules/vehicles/view.php?id=' . $vehicleId);
            } catch (Throwable $e) {
                error_log("Vehicle creation error: " . $e->getMessage());
                $error = "Database error: Could not save vehicle.";
            }
        }
    }
}

$pageTitle = 'Register New Truck';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Register New Fleet Truck</h2>
        <p class="text-muted mb-0">Add a commercial transport vehicle with inspection compliance certificates.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Fleet
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

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-truck-front me-2"></i> Vehicle Specifications</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Registration / License Plate Number <span class="text-danger">*</span></label>
                            <input type="text" name="registration_number" class="form-control text-uppercase" 
                                   value="<?= e($formData['registration_number']) ?>" required placeholder="e.g. TRK-IL-2026">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Truck Body Type <span class="text-danger">*</span></label>
                            <select name="truck_type" class="form-select" required>
                                <?php foreach ($truckTypes as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= $formData['truck_type'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Make & Model <span class="text-danger">*</span></label>
                            <input type="text" name="model" class="form-control" 
                                   value="<?= e($formData['model']) ?>" required placeholder="e.g. Volvo FH16 Globetrotter 540">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Capacity (Tonnes) <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" name="capacity_tonnes" class="form-control" 
                                   value="<?= e($formData['capacity_tonnes']) ?>" required placeholder="25.0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Manufacture Year <span class="text-danger">*</span></label>
                            <input type="number" name="make_year" class="form-control" 
                                   value="<?= e($formData['make_year']) ?>" required placeholder="2023">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Odometer (KM)</label>
                            <input type="number" name="current_odometer" class="form-control" 
                                   value="<?= e($formData['current_odometer']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Initial Availability Status</label>
                            <select name="status" class="form-select">
                                <option value="available" <?= $formData['status'] === 'available' ? 'selected' : '' ?>>Available for Dispatch</option>
                                <option value="maintenance" <?= $formData['status'] === 'maintenance' ? 'selected' : '' ?>>In Maintenance</option>
                                <option value="inactive" <?= $formData['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-shield-check me-2"></i> Statutory & Inspection Expiry Dates</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Insurance Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" name="insurance_expiry" class="form-control" 
                                   value="<?= e($formData['insurance_expiry']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Commercial Permit Expiry <span class="text-danger">*</span></label>
                            <input type="date" name="permit_expiry" class="form-control" 
                                   value="<?= e($formData['permit_expiry']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Road Fitness Expiry <span class="text-danger">*</span></label>
                            <input type="date" name="fitness_expiry" class="form-control" 
                                   value="<?= e($formData['fitness_expiry']) ?>" required>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-journal-text me-2"></i> Maintenance Notes & Equipment</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <textarea name="notes" class="form-control" rows="3" placeholder="Equipment details, tire specs, hydraulic liftgate notes..."><?= e($formData['notes']) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Register Vehicle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
