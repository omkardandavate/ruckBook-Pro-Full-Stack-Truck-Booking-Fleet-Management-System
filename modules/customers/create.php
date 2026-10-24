<?php
/**
 * TruckBook Pro - Add New Customer (Module C)
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
$error = '';

$formData = [
    'company_name' => '',
    'contact_person' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'tax_gst_number' => '',
    'status' => 'active'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    foreach ($formData as $k => $v) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }

    $validator = (new Validator($_POST))
        ->required('company_name', 'Company Name')
        ->required('contact_person', 'Contact Person')
        ->required('email', 'Email Address')
        ->email('email', 'Email Address')
        ->required('phone', 'Phone Number')
        ->phone('phone', 'Phone Number')
        ->required('address', 'Physical Address')
        ->required('city', 'City')
        ->required('state', 'State')
        ->required('postal_code', 'Postal / Zip Code');

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        // Duplicate check
        $dupStmt = $pdo->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        $dupStmt->execute([$formData['email']]);
        if ($dupStmt->fetch()) {
            $error = "A customer with this email address already exists.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (company_name, contact_person, email, phone, address, city, state, postal_code, tax_gst_number, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $formData['company_name'],
                    $formData['contact_person'],
                    $formData['email'],
                    $formData['phone'],
                    $formData['address'],
                    $formData['city'],
                    $formData['state'],
                    $formData['postal_code'],
                    $formData['tax_gst_number'] ?: null,
                    $formData['status']
                ]);
                $customerId = (int)$pdo->lastInsertId();

                logAudit(currentUserId(), 'create_customer', 'customers', $customerId, null, $formData);

                setFlash('success', "Customer '{$formData['company_name']}' created successfully.");
                redirect('modules/customers/view.php?id=' . $customerId);
            } catch (Throwable $e) {
                error_log("Customer creation failed: " . $e->getMessage());
                $error = "Database error: Could not save customer.";
            }
        }
    }
}

$pageTitle = 'Add New Customer';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Add New Customer</h2>
        <p class="text-muted mb-0">Register a new client company for freight logistics and billing.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Customers
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

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-building me-2"></i> Company & Tax Profile</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" class="form-control" value="<?= e($formData['company_name']) ?>" required placeholder="e.g. Acme Industrial Freight Corp">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tax / GST Number</label>
                            <input type="text" name="tax_gst_number" class="form-control" value="<?= e($formData['tax_gst_number']) ?>" placeholder="e.g. US-TAX-98442">
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-person-circle me-2"></i> Contact Representative</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Contact Person Name <span class="text-danger">*</span></label>
                            <input type="text" name="contact_person" class="form-control" value="<?= e($formData['contact_person']) ?>" required placeholder="e.g. Sarah Jenkins">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= e($formData['email']) ?>" required placeholder="sarah@acme.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" value="<?= e($formData['phone']) ?>" required placeholder="+1 555-0199">
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-geo-alt me-2"></i> Physical & Billing Address</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">Street Address <span class="text-danger">*</span></label>
                            <input type="text" name="address" class="form-control" value="<?= e($formData['address']) ?>" required placeholder="100 Commercial Way, Suite 400">
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

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-toggle-on me-2"></i> Account Status</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Initial Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= $formData['status'] === 'active' ? 'selected' : '' ?>>Active (Can Book)</option>
                                <option value="inactive" <?= $formData['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
