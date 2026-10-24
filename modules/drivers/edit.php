<?php
/**
 * TruckBook Pro - Edit Driver (Module E)
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
$driverId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM drivers WHERE id = ?");
$stmt->execute([$driverId]);
$driver = $stmt->fetch();

if (!$driver) {
    setFlash('danger', 'Driver record not found.');
    redirect('modules/drivers/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $licenceNumber = strtoupper(trim($_POST['licence_number'] ?? ''));
    $licenceExpiry = trim($_POST['licence_expiry'] ?? '');
    $experienceYears = (int)($_POST['experience_years'] ?? 0);
    $address = trim($_POST['address'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['available', 'on_trip', 'on_leave', 'inactive'], true) ? $_POST['status'] : 'available';

    $validator = (new Validator($_POST))
        ->required('name', 'Driver Full Name')
        ->required('phone', 'Phone Number')
        ->phone('phone', 'Phone Number')
        ->required('licence_number', 'Commercial Licence Number')
        ->required('licence_expiry', 'Licence Expiry Date')
        ->date('licence_expiry', 'Licence Expiry Date')
        ->required('experience_years', 'Experience (Years)')
        ->integer('experience_years', 'Experience (Years)', 0, 50);

    if (!empty($email)) {
        $validator->email('email', 'Email Address');
    }

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        // Prevent duplicate licence number excluding this driver
        $dupStmt = $pdo->prepare("SELECT id FROM drivers WHERE licence_number = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$licenceNumber, $driverId]);
        if ($dupStmt->fetch()) {
            $error = "Another driver is registered with licence number '{$licenceNumber}'.";
        } else {
            try {
                $upStmt = $pdo->prepare("
                    UPDATE drivers
                    SET name = ?, phone = ?, email = ?, licence_number = ?, licence_expiry = ?,
                        experience_years = ?, address = ?, status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $upStmt->execute([
                    $name, $phone, $email ?: null, $licenceNumber, $licenceExpiry,
                    $experienceYears, $address ?: null, $status, $driverId
                ]);

                // Also sync user name and phone if linked
                if ($driver['user_id']) {
                    $uSync = $pdo->prepare("UPDATE users SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?");
                    $uSync->execute([$name, $phone, $driver['user_id']]);
                }

                logAudit(currentUserId(), 'update_driver', 'drivers', $driverId, $driver, $_POST);

                setFlash('success', "Driver '{$name}' updated successfully.");
                redirect('modules/drivers/view.php?id=' . $driverId);
            } catch (Throwable $e) {
                error_log("Driver update error: " . $e->getMessage());
                $error = "Database error: Could not update driver.";
            }
        }
    }
} else {
    $name = $driver['name'];
    $phone = $driver['phone'];
    $email = $driver['email'] ?? '';
    $licenceNumber = $driver['licence_number'];
    $licenceExpiry = $driver['licence_expiry'];
    $experienceYears = $driver['experience_years'];
    $address = $driver['address'] ?? '';
    $status = $driver['status'];
}

$pageTitle = 'Edit Driver: ' . $driver['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Edit Driver Profile</h2>
        <p class="text-muted mb-0">Update contact and commercial license details for <?= e($driver['name']) ?></p>
    </div>
    <div>
        <a href="view.php?id=<?= $driverId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> View Profile
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

                <form method="POST" action="edit.php?id=<?= $driverId ?>" class="needs-validation">
                    <?= csrfField() ?>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-person me-2"></i> Driver Personal Details</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= e($name) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" value="<?= e($phone) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?= e($email) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Experience (Years) <span class="text-danger">*</span></label>
                            <input type="number" name="experience_years" class="form-control" value="<?= e((string)$experienceYears) ?>" required min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="available" <?= $status === 'available' ? 'selected' : '' ?>>Available</option>
                                <option value="on_trip" <?= $status === 'on_trip' ? 'selected' : '' ?>>On Trip</option>
                                <option value="on_leave" <?= $status === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-card-heading me-2"></i> Commercial Driver's License (CDL)</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Commercial Licence Number <span class="text-danger">*</span></label>
                            <input type="text" name="licence_number" class="form-control text-uppercase" value="<?= e($licenceNumber) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Licence Expiration Date <span class="text-danger">*</span></label>
                            <input type="date" name="licence_expiry" class="form-control" value="<?= e($licenceExpiry) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Residential Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= e($address) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="view.php?id=<?= $driverId ?>" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Update Driver
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
