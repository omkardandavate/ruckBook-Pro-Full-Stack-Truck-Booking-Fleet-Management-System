<?php
/**
 * TruckBook Pro - Edit Customer (Module C)
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
$customerId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    setFlash('danger', 'Customer record not found.');
    redirect('modules/customers/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $companyName = trim($_POST['company_name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');
    $taxGstNumber = trim($_POST['tax_gst_number'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

    $validator = (new Validator($_POST))
        ->required('company_name', 'Company Name')
        ->required('contact_person', 'Contact Person')
        ->required('email', 'Email Address')
        ->email('email', 'Email Address')
        ->required('phone', 'Phone Number')
        ->phone('phone', 'Phone Number')
        ->required('address', 'Address')
        ->required('city', 'City')
        ->required('state', 'State')
        ->required('postal_code', 'Postal Code');

    if (!$validator->isValid()) {
        $error = $validator->getFirstError();
    } else {
        // Duplicate email check excluding current customer
        $dupStmt = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$email, $customerId]);
        if ($dupStmt->fetch()) {
            $error = "Another customer is already registered with this email address.";
        } else {
            try {
                $upStmt = $pdo->prepare("
                    UPDATE customers
                    SET company_name = ?, contact_person = ?, email = ?, phone = ?,
                        address = ?, city = ?, state = ?, postal_code = ?, tax_gst_number = ?,
                        status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $upStmt->execute([
                    $companyName, $contactPerson, $email, $phone,
                    $address, $city, $state, $postalCode, $taxGstNumber ?: null,
                    $status, $customerId
                ]);

                logAudit(currentUserId(), 'update_customer', 'customers', $customerId, $customer, $_POST);

                setFlash('success', "Customer '{$companyName}' updated successfully.");
                redirect('modules/customers/view.php?id=' . $customerId);
            } catch (Throwable $e) {
                error_log("Customer update failed: " . $e->getMessage());
                $error = "Database error: Could not update customer.";
            }
        }
    }
} else {
    $companyName = $customer['company_name'];
    $contactPerson = $customer['contact_person'];
    $email = $customer['email'];
    $phone = $customer['phone'];
    $address = $customer['address'];
    $city = $customer['city'];
    $state = $customer['state'];
    $postalCode = $customer['postal_code'];
    $taxGstNumber = $customer['tax_gst_number'] ?? '';
    $status = $customer['status'];
}

$pageTitle = 'Edit Customer: ' . $customer['company_name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Edit Customer Profile</h2>
        <p class="text-muted mb-0">Update contact and billing details for <?= e($customer['company_name']) ?></p>
    </div>
    <div>
        <a href="view.php?id=<?= $customerId ?>" class="btn btn-outline-secondary">
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

                <form method="POST" action="edit.php?id=<?= $customerId ?>" class="needs-validation">
                    <?= csrfField() ?>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-building me-2"></i> Company & Tax Profile</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" class="form-control" value="<?= e($companyName) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tax / GST Number</label>
                            <input type="text" name="tax_gst_number" class="form-control" value="<?= e($taxGstNumber) ?>">
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-person-circle me-2"></i> Contact Representative</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Contact Person Name <span class="text-danger">*</span></label>
                            <input type="text" name="contact_person" class="form-control" value="<?= e($contactPerson) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= e($email) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" value="<?= e($phone) ?>" required>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-geo-alt me-2"></i> Address Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">Street Address <span class="text-danger">*</span></label>
                            <input type="text" name="address" class="form-control" value="<?= e($address) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" name="city" class="form-control" value="<?= e($city) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">State / Province <span class="text-danger">*</span></label>
                            <input type="text" name="state" class="form-control" value="<?= e($state) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Postal / Zip Code <span class="text-danger">*</span></label>
                            <input type="text" name="postal_code" class="form-control" value="<?= e($postalCode) ?>" required>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-toggle-on me-2"></i> Account Status</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active (Can Book)</option>
                                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive (Blocked)</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="view.php?id=<?= $customerId ?>" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Update Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
