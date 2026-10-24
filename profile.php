<?php
/**
 * TruckBook Pro - User Profile & Security Settings
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/require_login.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Profile & Security';
$user = currentUser();
$pdo = getDbConnection();

$profileError = '';
$profileSuccess = '';
$passwordError = '';
$passwordSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $validator = (new Validator($_POST))
            ->required('name', 'Full Name')
            ->phone('phone', 'Phone Number');

        if (!$validator->isValid()) {
            $profileError = $validator->getFirstError();
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$name, $phone, $user['id']]);

                $_SESSION['user_name'] = $name;
                logAudit($user['id'], 'update_profile', 'users', $user['id'], null, ['name' => $name, 'phone' => $phone]);

                setFlash('success', 'Profile details updated successfully.');
                redirect('profile.php');
            } catch (Throwable $e) {
                $profileError = 'Failed to update profile: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $validator = (new Validator($_POST))
            ->required('current_password', 'Current Password')
            ->required('new_password', 'New Password')
            ->minLength('new_password', 6, 'New Password');

        if (!$validator->isValid()) {
            $passwordError = $validator->getFirstError();
        } elseif ($newPassword !== $confirmPassword) {
            $passwordError = 'New password and confirmation do not match.';
        } else {
            // Verify current password
            $pwdStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $pwdStmt->execute([$user['id']]);
            $hash = $pwdStmt->fetchColumn();

            if (!password_verify($currentPassword, (string)$hash)) {
                $passwordError = 'Current password is incorrect.';
            } else {
                try {
                    $newHash = hashPassword($newPassword);
                    $upStmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                    $upStmt->execute([$newHash, $user['id']]);

                    logAudit($user['id'], 'password_change', 'users', $user['id']);
                    setFlash('success', 'Password changed successfully.');
                    redirect('profile.php');
                } catch (Throwable $e) {
                    $passwordError = 'Failed to update password: ' . $e->getMessage();
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-4">
    <!-- User Summary Card -->
    <div class="col-lg-4">
        <div class="content-card text-center p-4">
            <div class="user-avatar-circle mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
            </div>
            <h4 class="fw-bold mb-1"><?= e($user['name']) ?></h4>
            <p class="text-muted mb-2"><?= e($user['email']) ?></p>
            <div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 rounded-pill">
                    <?= e($user['role_name']) ?>
                </span>
            </div>

            <hr class="my-4">

            <div class="text-start">
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-muted">Account ID:</span>
                    <span class="fw-semibold">#<?= str_pad((string)$user['id'], 5, '0', STR_PAD_LEFT) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-muted">Phone:</span>
                    <span class="fw-semibold"><?= e($user['phone'] ?? 'Not set') ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-muted">Last Login:</span>
                    <span class="fw-semibold"><?= formatDateTime($user['last_login'] ?? null) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-0 small">
                    <span class="text-muted">Status:</span>
                    <?= renderStatusBadge($user['status']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Forms Column -->
    <div class="col-lg-8">
        <!-- Edit Profile Form -->
        <div class="content-card mb-4">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-person-lines-fill me-2 text-primary"></i> Personal Details</h5>
            </div>
            <div class="content-card-body">
                <?php if (!empty($profileError)): ?>
                    <div class="alert alert-danger mb-3"><?= e($profileError) ?></div>
                <?php endif; ?>

                <form method="POST" action="profile.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address (Read-Only)</label>
                            <input type="email" class="form-control bg-light" value="<?= e($user['email']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Contact</label>
                            <input type="text" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assigned Role</label>
                            <input type="text" class="form-control bg-light" value="<?= e($user['role_name']) ?>" readonly>
                        </div>
                    </div>

                    <div class="mt-4 text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Change Password Form -->
        <div class="content-card">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-key-fill me-2 text-warning"></i> Security & Password</h5>
            </div>
            <div class="content-card-body">
                <?php if (!empty($passwordError)): ?>
                    <div class="alert alert-danger mb-3"><?= e($passwordError) ?></div>
                <?php endif; ?>

                <form method="POST" action="profile.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required placeholder="Enter your current password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" required placeholder="Minimum 6 characters">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required placeholder="Re-type new password">
                        </div>
                    </div>

                    <div class="mt-4 text-end">
                        <button type="submit" class="btn btn-warning text-dark fw-semibold">
                            <i class="bi bi-shield-lock me-1"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
