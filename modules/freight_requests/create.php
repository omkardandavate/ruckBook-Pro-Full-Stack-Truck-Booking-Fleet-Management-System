<?php
/**
 * TruckBook Pro - Submit Freight Request (Module F)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer']);

$pdo = getDbConnection();
$currentRole = currentRole();
$userId = currentUserId();

// Customer Context
$customerRecord = null;
if ($currentRole === 'customer') {
    $cStmt = $pdo->prepare("SELECT * FROM customers WHERE user_id = ? LIMIT 1");
    $cStmt->execute([$userId]);
    $customerRecord = $cStmt->fetch();
    if (!$customerRecord) {
        setFlash('danger', 'No customer entity associated with your account.');
        redirect('dashboard.php');
    }
}

// Available Customers for Staff/Admin
$allCustomers = [];
if ($currentRole !== 'customer') {
    $allCustomers = $pdo->query("SELECT id, company_name, contact_person, city FROM customers WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
}

$truckTypes = [
    'flatbed' => 'Flatbed (Heavy machinery, pipes, timber, steel)',
    'container' => 'Standard ISO Container (Dry cargo, crates, tools)',
    'refrigerated' => 'Refrigerated Reefer (Cold chain, pharmaceutical, dairy)',
    'tanker' => 'Liquid Bulk Tanker (Petroleum, chemicals, edible oil)',
    'trailer' => 'Heavy Equipment Trailer (Overweight loads > 25 Tonnes)',
    'box_truck' => 'Box Truck (City freight, pallets, retail goods)'
];

$error = '';
$formData = [
    'customer_id' => $customerRecord ? (string)$customerRecord['id'] : '',
    'pickup_city' => '',
    'pickup_address' => '',
    'delivery_city' => '',
    'delivery_address' => '',
    'cargo_description' => '',
    'cargo_weight_tonnes' => '',
    'preferred_date' => date('Y-m-d', strtotime('+1 day')),
    'truck_type_required' => 'container',
    'special_instructions' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    if ($customerRecord) {
        $formData['customer_id'] = (string)$customerRecord['id'];
    }

    $validator = (new Validator($_POST))
        ->required('customer_id', 'Customer')
        ->required('pickup_city', 'Pickup City')
        ->required('pickup_address', 'Pickup Address')
        ->required('delivery_city', 'Delivery City')
        ->required('delivery_address', 'Delivery Address')
        ->required('cargo_description', 'Cargo Description')
        ->required('cargo_weight_tonnes', 'Cargo Weight')
        ->numeric('cargo_weight_tonnes', 'Cargo Weight', 0.1, 150.0)
        ->required('preferred_date', 'Preferred Date')
        ->date('preferred_date', 'Preferred Date')
        ->required('truck_type_required', 'Truck Type Required')
        ->in('truck_type_required', array_keys($truckTypes), 'Truck Type');

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        try {
            // Generate unique request reference: REQ-YYYY-XXXX
            $year = date('Y');
            $refStmt = $pdo->prepare("SELECT COUNT(*) FROM freight_requests WHERE request_reference LIKE ?");
            $refStmt->execute(["REQ-{$year}-%"]);
            $seq = (int)$refStmt->fetchColumn() + 1;
            $requestReference = sprintf("REQ-%s-%04d", $year, $seq);

            $stmt = $pdo->prepare("
                INSERT INTO freight_requests (
                    request_reference, customer_id, pickup_address, delivery_address,
                    pickup_city, delivery_city, cargo_description, cargo_weight_tonnes,
                    preferred_date, truck_type_required, special_instructions, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $requestReference,
                (int)$formData['customer_id'],
                $formData['pickup_address'],
                $formData['delivery_address'],
                $formData['pickup_city'],
                $formData['delivery_city'],
                $formData['cargo_description'],
                (float)$formData['cargo_weight_tonnes'],
                $formData['preferred_date'],
                $formData['truck_type_required'],
                $formData['special_instructions'] ?: null
            ]);
            $requestId = (int)$pdo->lastInsertId();

            // Notify Transport Managers
            $mgrs = $pdo->query("SELECT id FROM users WHERE role_id IN (1, 2)")->fetchAll();
            foreach ($mgrs as $mgr) {
                createNotification(
                    (int)$mgr['id'],
                    'New Freight Request ' . $requestReference,
                    "Customer requested quote for {$formData['cargo_weight_tonnes']}T from {$formData['pickup_city']} to {$formData['delivery_city']}.",
                    "modules/freight_requests/view.php?id={$requestId}",
                    'info'
                );
            }

            logAudit(currentUserId(), 'create_freight_request', 'freight_requests', $requestId, null, $formData);

            setFlash('success', "Freight request {$requestReference} submitted successfully. Our dispatch team will review and calculate your quote.");
            redirect('modules/freight_requests/view.php?id=' . $requestId);
        } catch (Throwable $e) {
            error_log("Freight request creation error: " . $e->getMessage());
            $error = "Database error: Could not submit freight request.";
        }
    }
}

$pageTitle = 'Submit Freight Request';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Submit Freight Request</h2>
        <p class="text-muted mb-0">Specify cargo specifications, weight, routes, and required truck type for quoting.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Requests
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

                    <?php if ($customerRecord): ?>
                        <div class="alert alert-light border d-flex align-items-center justify-content-between mb-4">
                            <div>
                                <small class="text-muted d-block">Booking on behalf of:</small>
                                <strong class="fs-6"><?= e($customerRecord['company_name']) ?></strong>
                            </div>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Client Account</span>
                        </div>
                    <?php else: ?>
                        <div class="mb-4">
                            <label class="form-label">Select Customer / Client Company <span class="text-danger">*</span></label>
                            <select name="customer_id" class="form-select" required>
                                <option value="">-- Choose Customer --</option>
                                <?php foreach ($allCustomers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $formData['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>>
                                        <?= e($c['company_name']) ?> (<?= e($c['contact_person']) ?> - <?= e($c['city']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-geo-alt me-2"></i> Route & Locations</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Pickup City <span class="text-danger">*</span></label>
                            <input type="text" name="pickup_city" class="form-control" value="<?= e($formData['pickup_city']) ?>" required placeholder="e.g. Chicago, IL">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Destination City <span class="text-danger">*</span></label>
                            <input type="text" name="delivery_city" class="form-control" value="<?= e($formData['delivery_city']) ?>" required placeholder="e.g. Detroit, MI">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Complete Pickup Address / Gate <span class="text-danger">*</span></label>
                            <textarea name="pickup_address" class="form-control" rows="2" required placeholder="Street, dock number, warehouse gate..."><?= e($formData['pickup_address']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Complete Delivery Address / Gate <span class="text-danger">*</span></label>
                            <textarea name="delivery_address" class="form-control" rows="2" required placeholder="Delivery depot, receiving bay, street..."><?= e($formData['delivery_address']) ?></textarea>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-box-seam me-2"></i> Cargo Specifications</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label class="form-label">Cargo Description <span class="text-danger">*</span></label>
                            <input type="text" name="cargo_description" class="form-control" value="<?= e($formData['cargo_description']) ?>" required placeholder="e.g. Precision CNC Machined Tooling & Stamping Dies">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Estimated Cargo Weight (Tonnes) <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" name="cargo_weight_tonnes" class="form-control" value="<?= e($formData['cargo_weight_tonnes']) ?>" required placeholder="e.g. 18.5">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Preferred Departure Date <span class="text-danger">*</span></label>
                            <input type="date" name="preferred_date" class="form-control" value="<?= e($formData['preferred_date']) ?>" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Required Truck Body Type <span class="text-danger">*</span></label>
                            <select name="truck_type_required" class="form-select" required>
                                <?php foreach ($truckTypes as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= $formData['truck_type_required'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Special Handling Instructions / Notes</label>
                            <textarea name="special_instructions" class="form-control" rows="2" placeholder="Fragile electronic cargo, hazardous class, tarp required, forklift on-site..."><?= e($formData['special_instructions']) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-send me-1"></i> Submit Freight Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
