<?php
/**
 * TruckBook Pro - Authentication / Login Portal
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errorMessage = '';
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $emailInput = trim($_POST['email'] ?? '');
    $passwordInput = $_POST['password'] ?? '';

    $validator = (new Validator($_POST))
        ->required('email', 'Email Address')
        ->email('email', 'Email Address')
        ->required('password', 'Password');

    if (!$validator->isValid()) {
        $errorMessage = $validator->getFirstError();
    } else {
        $authResult = authenticateUser($emailInput, $passwordInput);
        if ($authResult['success']) {
            $intended = $_SESSION['intended_url'] ?? 'dashboard.php';
            unset($_SESSION['intended_url']);
            setFlash('success', "Welcome back, " . htmlspecialchars($authResult['user']['name']) . "!");
            redirect($intended);
        } else {
            $errorMessage = $authResult['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= APP_NAME ?></title>
    <?= csrfMeta() ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
        }
        .login-hero {
            flex: 1.1;
            background: linear-gradient(145deg, #0b132b 0%, #1c2541 60%, #1e3a8a 100%);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 3.5rem;
            position: relative;
            overflow: hidden;
        }
        .login-hero::before {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.15) 0%, rgba(0,0,0,0) 70%);
            border-radius: 50%;
        }
        .login-form-side {
            flex: 0.9;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem;
        }
        .login-box {
            width: 100%;
            max-width: 440px;
        }
        @media (max-width: 991.98px) {
            .login-hero {
                display: none;
            }
            .login-form-side {
                flex: 1;
                background: #f8fafc;
            }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <!-- Hero Branding Side -->
    <div class="login-hero">
        <div>
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="brand-icon" style="width: 46px; height: 46px; font-size: 1.5rem;">
                    <i class="bi bi-truck"></i>
                </div>
                <h3 class="text-white m-0 brand-font">TruckBook <span style="color: #38bdf8;">Pro</span></h3>
            </div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 rounded-pill fw-semibold">
                Fleet Management & Logistics Cloud
            </span>
        </div>

        <div class="my-5">
            <h1 class="display-6 fw-bold text-white mb-3">Enterprise-Grade Dispatch, Trips & Freight Operations</h1>
            <p class="text-light lead" style="color: #cbd5e1 !important; font-size: 1.05rem;">
                A synchronized platform connecting transport managers, dispatchers, drivers, and freight clients. Monitor vehicle health, automate quotations, track journeys in real-time, and calculate net trip profitability with complete audit integrity.
            </p>
            <div class="row g-3 mt-4">
                <div class="col-sm-6">
                    <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);">
                        <i class="bi bi-shield-check text-info fs-4"></i>
                        <h6 class="text-white mt-2 mb-1">Role-Based Security</h6>
                        <small class="text-light" style="color: #94a3b8 !important;">5 discrete operational roles with server enforcement.</small>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);">
                        <i class="bi bi-cash-coin text-success fs-4"></i>
                        <h6 class="text-white mt-2 mb-1">Financial Reconciliation</h6>
                        <small class="text-light" style="color: #94a3b8 !important;">Invoices, expense audits, and trip net profit margins.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-between text-muted border-top pt-3" style="border-color: rgba(255, 255, 255, 0.1) !important;">
            <small style="color: #94a3b8 !important;">TruckBook Pro &copy; <?= date('Y') ?></small>
            <small style="color: #94a3b8 !important;"><i class="bi bi-shield-lock me-1"></i> 256-bit Session Security</small>
        </div>
    </div>

    <!-- Login Form Side -->
    <div class="login-form-side">
        <div class="login-box">
            <div class="mb-4">
                <h2 class="fw-bold text-dark mb-1">Welcome Back</h2>
                <p class="text-muted">Sign in to your authorized portal account.</p>
            </div>

            <?= renderFlashAlerts() ?>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger d-flex align-items-center shadow-sm" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                    <div><?= e($errorMessage) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" id="loginForm" class="needs-validation">
                <?= csrfField() ?>

                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" id="email" class="form-control border-start-0" 
                               value="<?= e($emailInput) ?>" placeholder="user@truckbook.com" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <label for="password" class="form-label">Password</label>
                        <a href="#" class="text-decoration-none small text-muted" onclick="alert('For demo evaluation, use the one-click role buttons below or default password: password123'); return false;">Forgot?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="password" class="form-control border-start-0" 
                               placeholder="••••••••••••" required>
                    </div>
                </div>

                <div class="mb-4 d-flex align-items-center justify-content-between">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe">
                        <label class="form-check-label small text-muted" for="rememberMe">Remember session</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                    <i class="bi bi-box-arrow-in-right me-2"></i> Sign In to Portal
                </button>
            </form>

            <div class="text-center mt-3">
                <span class="text-muted small">New freight client?</span>
                <a href="register.php" class="text-primary text-decoration-none fw-semibold small">Register as Customer</a>
            </div>

            <!-- Instant Demo Role Account Quick-Picker for Testing & Viva -->
            <div class="mt-4 pt-3 border-top">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-uppercase text-muted fw-bold" style="font-size: 0.725rem; letter-spacing: 0.05em;">
                        Quick Demo Account Selector
                    </span>
                    <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.675rem;">Password: password123</span>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <button type="button" class="role-quick-btn" onclick="fillDemo('admin@truckbook.com')">
                            <span class="badge bg-danger text-white rounded-pill px-2">Admin</span>
                            <span class="text-truncate">Super Admin</span>
                        </button>
                    </div>
                    <div class="col-6">
                        <button type="button" class="role-quick-btn" onclick="fillDemo('manager@truckbook.com')">
                            <span class="badge bg-primary text-white rounded-pill px-2">Manager</span>
                            <span class="text-truncate">Transport Mgr</span>
                        </button>
                    </div>
                    <div class="col-6">
                        <button type="button" class="role-quick-btn" onclick="fillDemo('operator@truckbook.com')">
                            <span class="badge bg-info text-white rounded-pill px-2">Staff</span>
                            <span class="text-truncate">Operator</span>
                        </button>
                    </div>
                    <div class="col-6">
                        <button type="button" class="role-quick-btn" onclick="fillDemo('customer@example.com')">
                            <span class="badge bg-success text-white rounded-pill px-2">Client</span>
                            <span class="text-truncate">Customer</span>
                        </button>
                    </div>
                    <div class="col-12">
                        <button type="button" class="role-quick-btn" onclick="fillDemo('driver@truckbook.com')">
                            <span class="badge bg-warning text-dark rounded-pill px-2">Driver</span>
                            <span class="text-truncate">Fleet Driver (John Doe)</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function fillDemo(email) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = 'password123';
    document.getElementById('loginForm').submit();
}
</script>
</body>
</html>
