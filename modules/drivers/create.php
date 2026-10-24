<?php
/**
 * TruckBook Pro - Onboard New Driver (Module E)
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

$formData = [
    'name' => '',
    'phone' => '',
    'email' => '',
    'licence_number' => '',
    'licence_expiry' => date('Y-m-d', strtotime('+2 years')),
    'experience_years' => '5',
    'address' => '',
    'status' => 'available',
    'create_user_account' => '0',
    'account_password' => 'password123'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    $validator = (new Validator($_POST))
        ->required('name', 'Driver Full Name')
        ->required('phone', 'Phone Number')
        ->phone('phone', 'Phone Number')
        ->required('licence_number', 'Commercial Licence Number')
        ->required('licence_expiry', 'Licence Expiry Date')
        ->date('licence_expiry', 'Licence Expiry Date')
        ->required('experience_years', 'Experience (Years)')
        ->integer('experience_years', 'Experience (Years)', 0, 50);

    if (!empty($formData['email'])) {
        $validator->email('email', 'Email Address');
    }

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        // Prevent duplicate licence number
        $dupStmt = $pdo->prepare("SELECT id FROM drivers WHERE licence_number = ? LIMIT 1");
        $dupStmt->execute([strtoupper($formData['licence_number'])]);
        if ($dupStmt->fetch()) {
            $error = "A driver with licence number '{$formData['licence_number']}' is already registered.";
        } else {
            try {
                $pdo->beginTransaction();

                $linkedUserId = null;
                if ($formData['create_user_account'] === '1' && !empty($formData['email'])) {
                    // Check duplicate user email
                    $uCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $uCheck->execute([$formData['email']]);
                    if ($uCheck->fetch()) {
                        throw new RuntimeException("An authentication user account with email '{$formData['email']}' already exists.");
                    }

                    $uStmt = $pdo->prepare("
                        INSERT INTO users (role_id, name, email, password_hash, phone, status, created_at)
                        VALUES (5, ?, ?, ?, ?, 'active', NOW())
                    ");
                    $hash = password_hash($formData['account_password'] ?: 'password123', PASSWORD_BCRYPT);
                    $uStmt->execute([
                        $formData['name'],
                        $formData['email'],
                        $hash,
                        $formData['phone']
                    ]);
                    $linkedUserId = (int)$pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("
                    INSERT INTO drivers (user_id, name, phone, email, licence_number, licence_expiry, experience_years, address, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $linkedUserId,
                    $formData['name'],
                    $formData['phone'],
                    $formData['email'] ?: null,
                    strtoupper($formData['licence_number']),
                    $formData['licence_expiry'],
                    (int)$formData['experience_years'],
                    $formData['address'] ?: null,
                    $formData['status']
                ]);
                $driverId = (int)$pdo->lastInsertId();

                logAudit(currentUserId(), 'create_driver', 'drivers', $driverId, null, $formData);

                $pdo->commit();

                setFlash('success', "Driver '{$formData['name']}' added to roster.");
                redirect('modules/drivers/view.php?id=' . $driverId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Driver onboarding error: " . $e->getMessage());
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Onboard New Driver';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Onboard New Fleet Driver</h2>
        <p class="text-muted mb-0">Register commercial drivers and configure optional mobile portal access.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Drivers
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

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-person me-2"></i> Driver Personal Details</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= e($formData['name']) ?>" required placeholder="e.g. John Doe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" value="<?= e($formData['phone']) ?>" required placeholder="+1 555-0104">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address (Optional)</label>
                            <input type="email" name="email" class="form-control" value="<?= e($formData['email']) ?>" placeholder="driver@truckbook.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driving Experience (Years) <span class="text-danger">*</span></label>
                            <input type="number" name="experience_years" class="form-control" value="<?= e($formData['experience_years']) ?>" required min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Initial Status</label>
                            <select name="status" class="form-select">
                                <option value="available" <?= $formData['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                                <option value="on_leave" <?= $formData['status'] === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                                <option value="inactive" <?= $formData['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-card-heading me-2"></i> Commercial Driver's License (CDL)</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Commercial Licence Number <span class="text-danger">*</span></label>
                            <input type="text" name="licence_number" class="form-control text-uppercase" 
                                   value="<?= e($formData['licence_number']) ?>" required placeholder="e.g. CDL-IL-984421">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Licence Expiration Date <span class="text-danger">*</span></label>
                            <input type="date" name="licence_expiry" class="form-control" 
                                   value="<?= e($formData['licence_expiry']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Residential Address</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="Full residential street address..."><?= e($formData['address']) ?></textarea>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-shield-lock me-2"></i> Driver Portal Mobile Login (Optional)</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="create_user_account" value="1" id="createAccountCheck"
                                       <?= $formData['create_user_account'] === '1' ? 'checked' : '' ?> onchange="toggleAccountFields(this.checked)">
                                <label class="form-check-label fw-semibold" for="createAccountCheck">
                                    Create web/mobile portal login account for this driver
                                </label>
                            </div>
                            <small class="text-muted d-block mt-1">Allows driver to view trip instructions, update odometers, and upload proof of delivery directly.</small>
                        </div>
                        <div class="col-md-6 account-field <?= $formData['create_user_account'] === '1' ? '' : 'd-none' ?>">
                            <label class="form-label">Temporary Portal Password</label>
                            <input type="text" name="account_password" class="form-control" value="<?= e($formData['account_password']) ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Driver
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleAccountFields(show) {
    document.querySelectorAll('.account-field').forEach(el => {
        if (show) el.classList.remove('d-none');
        else el.classList.add('d-none');
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
