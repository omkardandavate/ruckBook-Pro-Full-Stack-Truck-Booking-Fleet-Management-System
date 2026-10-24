<?php
/**
 * TruckBook Pro - System Configuration & Business Settings (Module N)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin']);

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $settingsToUpdate = [
        'company_name' => trim($_POST['company_name'] ?? ''),
        'company_email' => trim($_POST['company_email'] ?? ''),
        'company_phone' => trim($_POST['company_phone'] ?? ''),
        'company_address' => trim($_POST['company_address'] ?? ''),
        'currency_symbol' => trim($_POST['currency_symbol'] ?? '$'),
        'default_tax_rate' => trim($_POST['default_tax_rate'] ?? '18.00'),
        'base_rate_per_km' => trim($_POST['base_rate_per_km'] ?? '4.00'),
        'base_rate_per_tonne' => trim($_POST['base_rate_per_tonne'] ?? '15.00'),
        'fuel_surcharge_percent' => trim($_POST['fuel_surcharge_percent'] ?? '10.00'),
        'driver_daily_allowance' => trim($_POST['driver_daily_allowance'] ?? '150.00'),
        'invoice_due_days' => trim($_POST['invoice_due_days'] ?? '15'),
        'max_login_attempts' => trim($_POST['max_login_attempts'] ?? '5')
    ];

    try {
        $upStmt = $pdo->prepare("UPDATE company_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
        foreach ($settingsToUpdate as $key => $val) {
            $upStmt->execute([$val, $key]);
        }

        logAudit(currentUserId(), 'update_settings', 'company_settings', 1, null, $settingsToUpdate);
        setFlash('success', 'System and pricing configuration updated successfully.');
        redirect('modules/settings/index.php');
    } catch (Throwable $e) {
        error_log("Settings update error: " . $e->getMessage());
        setFlash('danger', 'Failed to update system settings.');
    }
}

// Fetch all settings
$settings = [];
$sRows = $pdo->query("SELECT setting_key, setting_value FROM company_settings")->fetchAll();
foreach ($sRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$pageTitle = 'System & Tariff Settings';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">System Configuration & Business Rules</h2>
        <p class="text-muted mb-0">Configure company corporate profile, quote tariff formulas, and billing defaults.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/settings/audit_logs.php" class="btn btn-outline-primary">
            <i class="bi bi-shield-check me-1"></i> System Audit Trail
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="content-card">
            <div class="content-card-body p-4">
                <form method="POST" action="index.php" class="needs-validation">
                    <?= csrfField() ?>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-building me-2"></i> Corporate Legal Entity Profile</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Registered Business Legal Name</label>
                            <input type="text" name="company_name" class="form-control" value="<?= e($settings['company_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Support Email</label>
                            <input type="email" name="company_email" class="form-control" value="<?= e($settings['company_email'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Dispatch Hotline Phone</label>
                            <input type="text" name="company_phone" class="form-control" value="<?= e($settings['company_phone'] ?? '') ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Headquarters Corporate Address</label>
                            <textarea name="company_address" class="form-control" rows="2" required><?= e($settings['company_address'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-calculator me-2"></i> Freight Quotation & Pricing Engine Defaults</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Base Rate per KM (<?= e($settings['currency_symbol'] ?? '$') ?>)</label>
                            <input type="number" step="0.1" name="base_rate_per_km" class="form-control" value="<?= e($settings['base_rate_per_km'] ?? '4.00') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cargo Surcharge / Tonne</label>
                            <input type="number" step="0.1" name="base_rate_per_tonne" class="form-control" value="<?= e($settings['base_rate_per_tonne'] ?? '15.00') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fuel Surcharge Index (%)</label>
                            <input type="number" step="0.1" name="fuel_surcharge_percent" class="form-control" value="<?= e($settings['fuel_surcharge_percent'] ?? '10.00') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driver Daily Allowance</label>
                            <input type="number" step="1" name="driver_daily_allowance" class="form-control" value="<?= e($settings['driver_daily_allowance'] ?? '150.00') ?>" required>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-cash-stack me-2"></i> Financial & Invoicing Terms</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Sales Tax / GST / VAT Rate (%)</label>
                            <input type="number" step="0.1" name="default_tax_rate" class="form-control" value="<?= e($settings['default_tax_rate'] ?? '18.00') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Display Currency Symbol</label>
                            <input type="text" name="currency_symbol" class="form-control" value="<?= e($settings['currency_symbol'] ?? '$') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Invoice Due Terms (Days)</label>
                            <input type="number" name="invoice_due_days" class="form-control" value="<?= e($settings['invoice_due_days'] ?? '15') ?>" required>
                        </div>
                    </div>

                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-shield-lock me-2"></i> Security Safeguards</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Max Failed Login Attempts (Brute-Force Lockout)</label>
                            <input type="number" name="max_login_attempts" class="form-control" value="<?= e($settings['max_login_attempts'] ?? '5') ?>" required min="3" max="10">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
