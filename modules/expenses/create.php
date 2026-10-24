<?php
/**
 * TruckBook Pro - Record Trip Expense (Module I)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'driver']);

$pdo = getDbConnection();
$tripId = (int)($_GET['trip_id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

// Selectable Trips
$tripQuery = "
    SELECT t.id, t.trip_number, b.pickup_city, b.delivery_city, v.registration_number, d.name AS driver_name
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
";
if ($currentRole === 'driver') {
    $dCheck = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ? LIMIT 1");
    $dCheck->execute([$userId]);
    $myDrvId = (int)$dCheck->fetchColumn();
    $tripQuery .= " WHERE t.driver_id = {$myDrvId}";
} else {
    $tripQuery .= " WHERE t.delivery_status != 'cancelled'";
}
$tripQuery .= " ORDER BY t.created_at DESC LIMIT 30";
$availableTrips = $pdo->query($tripQuery)->fetchAll();

$categories = [
    'fuel' => 'Diesel Fuel Purchase',
    'toll' => 'Highway Toll Plaza / Fastag',
    'driver_allowance' => 'Driver Overnight Allowance & Meals',
    'loading_unloading' => 'Loading / Unloading Labor Fee',
    'repairs' => 'En-Route Repairs & Tire Spares',
    'parking' => 'Commercial Parking & Weighbridge',
    'other' => 'Other Incidental Transit Cost'
];

$error = '';
$formData = [
    'trip_id' => $tripId > 0 ? (string)$tripId : '',
    'category' => 'fuel',
    'amount' => '',
    'expense_date' => date('Y-m-d'),
    'description' => '',
    'receipt_reference' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    $validator = (new Validator($_POST))
        ->required('trip_id', 'Assigned Trip')
        ->required('category', 'Expense Category')
        ->in('category', array_keys($categories), 'Expense Category')
        ->required('amount', 'Expense Amount')
        ->numeric('amount', 'Expense Amount', 0.5)
        ->required('expense_date', 'Expense Date')
        ->date('expense_date', 'Expense Date')
        ->required('description', 'Expense Description');

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        try {
            $pdo->beginTransaction();

            $receiptPath = null;
            if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['receipt_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'pdf'];

                if (!in_array($ext, $allowed, true)) {
                    throw new RuntimeException("Invalid receipt file type. Only JPG, PNG, and PDF accepted.");
                }
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException("Receipt file size cannot exceed 5MB.");
                }

                $dir = APP_ROOT . '/storage/uploads/receipts';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                $fname = sprintf("rcpt_%d_%s.%s", (int)$formData['trip_id'], time(), $ext);
                $dest = $dir . '/' . $fname;
                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    throw new RuntimeException("Failed to upload receipt file.");
                }

                $receiptPath = 'storage/uploads/receipts/' . $fname;
            }

            // Driver submissions default to pending approval; managers auto-approve
            $status = ($currentRole === 'driver') ? 'pending' : 'approved';
            $approvedBy = ($status === 'approved') ? $userId : null;

            $stmt = $pdo->prepare("
                INSERT INTO trip_expenses (
                    trip_id, category, amount, expense_date, description,
                    receipt_reference, receipt_file_path, status, created_by, approved_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                (int)$formData['trip_id'],
                $formData['category'],
                (float)$formData['amount'],
                $formData['expense_date'],
                $formData['description'],
                $formData['receipt_reference'] ?: null,
                $receiptPath,
                $status,
                $userId,
                $approvedBy
            ]);
            $expenseId = (int)$pdo->lastInsertId();

            logAudit($userId, 'create_expense', 'trip_expenses', $expenseId, null, $formData);

            $pdo->commit();

            setFlash('success', "Expense of " . formatCurrency($formData['amount']) . " recorded successfully" . ($status === 'pending' ? " (Awaiting manager approval)." : "."));
            redirect('modules/trips/view.php?id=' . $formData['trip_id']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Expense creation error: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Record Trip Expense';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Record Trip Expense</h2>
        <p class="text-muted mb-0">Submit commercial trip operational expenditures with receipt references.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/expenses/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Expenses
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="content-card-body p-4">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mb-4"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="create.php" enctype="multipart/form-data" class="needs-validation">
                    <?= csrfField() ?>

                    <div class="mb-3">
                        <label class="form-label">Associated Trip <span class="text-danger">*</span></label>
                        <select name="trip_id" class="form-select" required>
                            <option value="">-- Choose Operational Trip --</option>
                            <?php foreach ($availableTrips as $tr): ?>
                                <option value="<?= $tr['id'] ?>" <?= $formData['trip_id'] === (string)$tr['id'] ? 'selected' : '' ?>>
                                    <?= e($tr['trip_number']) ?> &mdash; <?= e($tr['pickup_city']) ?> &rarr; <?= e($tr['delivery_city']) ?> (<?= e($tr['registration_number']) ?> - <?= e($tr['driver_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Expense Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select" required>
                                <?php foreach ($categories as $k => $lbl): ?>
                                    <option value="<?= $k ?>" <?= $formData['category'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount (<?= getSetting('currency_symbol', '$') ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" class="form-control fw-bold" 
                                   value="<?= e($formData['amount']) ?>" required placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expense Incurred Date <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" class="form-control" 
                                   value="<?= e($formData['expense_date']) ?>" required max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Receipt / Voucher Reference #</label>
                            <input type="text" name="receipt_reference" class="form-control" 
                                   value="<?= e($formData['receipt_reference']) ?>" placeholder="e.g. RCP-SHELL-98214">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Expense Description & Purpose <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="2" required placeholder="e.g. Diesel fuel 75 Gallons at Shell Truck Stop I-94 Kalamazoo..."><?= e($formData['description']) ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Upload Receipt Photo / Bill Scan (Optional)</label>
                        <input type="file" name="receipt_file" class="form-control" accept="image/*,.pdf">
                        <small class="text-muted">Accepted formats: JPG, PNG, PDF (Up to 5MB)</small>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="<?= BASE_URL ?>/modules/expenses/index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Expense Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
