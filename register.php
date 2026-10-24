<?php
/**
 * TruckBook Pro - Customer Self-Registration
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errorMessage = '';
$formData = [
    'company_name' => '',
    'contact_person' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'tax_gst_number' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    $validator = (new Validator($_POST))
        ->required('company_name', 'Company Name')
        ->required('contact_person', 'Contact Person Name')
        ->required('email', 'Email Address')
        ->email('email', 'Email Address')
        ->required('phone', 'Phone Number')
        ->phone('phone', 'Phone Number')
        ->required('address', 'Physical Address')
        ->required('city', 'City')
        ->required('state', 'State')
        ->required('postal_code', 'Postal / Zip Code')
        ->required('password', 'Password')
        ->minLength('password', 6, 'Password');

    if (!$validator->isValid()) {
        $errorMessage = $validator->getFirstError();
    } elseif ($password !== $passwordConfirm) {
        $errorMessage = 'Password and confirmation password do not match.';
    } else {
        try {
            $pdo = getDbConnection();

            // Check duplicate email in users and customers
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $checkStmt->execute([$formData['email']]);
            if ($checkStmt->fetch()) {
                $errorMessage = 'An account with this email address already exists. Please log in.';
            } else {
                $pdo->beginTransaction();

                // 1. Create user account (role_id 4 = customer)
                $userStmt = $pdo->prepare("
                    INSERT INTO users (role_id, name, email, password_hash, phone, status, created_at)
                    VALUES (4, ?, ?, ?, ?, 'active', NOW())
                ");
                $hash = hashPassword($password);
                $userStmt->execute([
                    $formData['contact_person'],
                    $formData['email'],
                    $hash,
                    $formData['phone']
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                // 2. Create customer entity
                $custStmt = $pdo->prepare("
                    INSERT INTO customers (user_id, company_name, contact_person, email, phone, address, city, state, postal_code, tax_gst_number, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                ");
                $custStmt->execute([
                    $newUserId,
                    $formData['company_name'],
                    $formData['contact_person'],
                    $formData['email'],
                    $formData['phone'],
                    $formData['address'],
                    $formData['city'],
                    $formData['state'],
                    $formData['postal_code'],
                    $formData['tax_gst_number'] ?: null
                ]);
                $newCustomerId = (int)$pdo->lastInsertId();

                // 3. Welcome Notification
                createNotification(
                    $newUserId,
                    'Welcome to TruckBook Pro',
                    'Your customer account is now active. You can request freight quotes and book trucks instantly.',
                    'modules/freight_requests/create.php',
                    'success'
                );

                // 4. Audit Log
                logAudit($newUserId, 'customer_registration', 'customers', $newCustomerId, null, ['company' => $formData['company_name']]);

                $pdo->commit();

                // Auto-login
                authenticateUser($formData['email'], $password);
                setFlash('success', 'Registration successful! Welcome to your customer portal.');
                redirect('dashboard.php');
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Registration error: " . $e->getMessage());
            $errorMessage = 'A database error occurred during registration. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Registration | <?= APP_NAME ?></title>
    <?= csrfMeta() ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="bg-light py-5">

<div class="container" style="max-width: 760px;">
    <div class="text-center mb-4">
        <div class="brand-icon mx-auto mb-2" style="width: 50px; height: 50px; font-size: 1.6rem;">
            <i class="bi bi-truck"></i>
        </div>
        <h2 class="fw-bold text-dark">Register Freight Customer Account</h2>
        <p class="text-muted">Create a company account to submit freight requests, track shipments, and receive quotes.</p>
    </div>

    <?= renderFlashAlerts() ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger d-flex align-items-center shadow-sm mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div><?= e($errorMessage) ?></div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm p-4 p-md-5 rounded-4">
        <form method="POST" action="register.php" class="needs-validation">
            <?= csrfField() ?>

            <h5 class="fw-bold text-primary mb-3"><i class="bi bi-building me-2"></i> Company Information</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-7">
                    <label class="form-label">Company / Business Name <span class="text-danger">*</span></label>
                    <input type="text" name="company_name" class="form-control" value="<?= e($formData['company_name']) ?>" required placeholder="Acme Logistics Corp">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Tax / GST Number (Optional)</label>
                    <input type="text" name="tax_gst_number" class="form-control" value="<?= e($formData['tax_gst_number']) ?>" placeholder="US-TAX-88912">
                </div>
            </div>

            <h5 class="fw-bold text-primary mb-3"><i class="bi bi-person-circle me-2"></i> Authorized Representative</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Contact Person Name <span class="text-danger">*</span></label>
                    <input type="text" name="contact_person" class="form-control" value="<?= e($formData['contact_person']) ?>" required placeholder="Full Name">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Official Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" value="<?= e($formData['email']) ?>" required placeholder="contact@company.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                    <input type="text" name="phone" class="form-control" value="<?= e($formData['phone']) ?>" required placeholder="+1 555-0199">
                </div>
            </div>

            <h5 class="fw-bold text-primary mb-3"><i class="bi bi-geo-alt me-2"></i> Business Address</h5>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <label class="form-label">Street Address <span class="text-danger">*</span></label>
                    <input type="text" name="address" class="form-control" value="<?= e($formData['address']) ?>" required placeholder="123 Industrial Way, Suite 100">
                </div>
                <div class="col-md-4">
                    <label class="form-label">City <span class="text-danger">*</span></label>
                    <input type="text" name="city" class="form-control" value="<?= e($formData['city']) ?>" required placeholder="Chicago">
                </div>
                <div class="col-md-4">
                    <label class="form-label">State / Province <span class="text-danger">*</span></label>
                    <input type="text" name="state" class="form-control" value="<?= e($formData['state']) ?>" required placeholder="IL">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Postal / Zip Code <span class="text-danger">*</span></label>
                    <input type="text" name="postal_code" class="form-control" value="<?= e($formData['postal_code']) ?>" required placeholder="60601">
                </div>
            </div>

            <h5 class="fw-bold text-primary mb-3"><i class="bi bi-lock me-2"></i> Account Security</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Create Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required placeholder="Min 6 characters">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" name="password_confirm" class="form-control" required placeholder="Repeat password">
                </div>
            </div>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-primary py-2 fw-semibold fs-6">
                    <i class="bi bi-check2-circle me-2"></i> Complete Registration & Enter Portal
                </button>
            </div>
        </form>

        <div class="text-center mt-4">
            <span class="text-muted">Already have a customer or staff account?</span>
            <a href="login.php" class="text-primary fw-semibold text-decoration-none">Sign In Here</a>
        </div>
    </div>
</div>

</body>
</html>
