<?php
/**
 * TruckBook Pro - Reusable Sidebar Component
 */

declare(strict_types=1);

$currentRole = currentRole();
$user = currentUser();
?>
<aside id="app-sidebar">
    <div class="sidebar-header">
        <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-brand">
            <div class="brand-icon">
                <i class="bi bi-truck"></i>
            </div>
            <span>TruckBook <span class="text-primary" style="color: #38bdf8 !important;">Pro</span></span>
        </a>
    </div>

    <div class="sidebar-menu">
        <div class="menu-heading">Main</div>
        <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link-custom <?= isActiveRoute('dashboard.php') ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <?php if (in_array($currentRole, ['super_admin', 'transport_manager', 'operator'], true)): ?>
            <div class="menu-heading">Operations</div>
            
            <a href="<?= BASE_URL ?>/modules/customers/index.php" class="nav-link-custom <?= isActiveRoute('/customers/') ?>">
                <i class="bi bi-people-fill"></i>
                <span>Customers</span>
            </a>

            <a href="<?= BASE_URL ?>/modules/vehicles/index.php" class="nav-link-custom <?= isActiveRoute('/vehicles/') ?>">
                <i class="bi bi-truck-front-fill"></i>
                <span>Fleet / Vehicles</span>
            </a>

            <a href="<?= BASE_URL ?>/modules/drivers/index.php" class="nav-link-custom <?= isActiveRoute('/drivers/') ?>">
                <i class="bi bi-person-badge-fill"></i>
                <span>Drivers</span>
            </a>
        <?php endif; ?>

        <div class="menu-heading">Booking & Dispatch</div>

        <a href="<?= BASE_URL ?>/modules/freight_requests/index.php" class="nav-link-custom <?= isActiveRoute('/freight_requests/') ?>">
            <i class="bi bi-box-seam-fill"></i>
            <span>Freight Requests</span>
        </a>

        <?php if (in_array($currentRole, ['super_admin', 'transport_manager', 'customer'], true)): ?>
            <a href="<?= BASE_URL ?>/modules/quotations/index.php" class="nav-link-custom <?= isActiveRoute('/quotations/') ?>">
                <i class="bi bi-file-earmark-text-fill"></i>
                <span>Quotations</span>
            </a>
        <?php endif; ?>

        <?php if ($currentRole !== 'driver'): ?>
            <a href="<?= BASE_URL ?>/modules/bookings/index.php" class="nav-link-custom <?= isActiveRoute('/bookings/') ?>">
                <i class="bi bi-calendar2-check-fill"></i>
                <span>Truck Bookings</span>
            </a>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/modules/trips/index.php" class="nav-link-custom <?= isActiveRoute('/trips/') ?>">
            <i class="bi bi-geo-alt-fill"></i>
            <span>Trips & Tracking</span>
        </a>

        <?php if (in_array($currentRole, ['super_admin', 'transport_manager', 'operator', 'driver'], true)): ?>
            <a href="<?= BASE_URL ?>/modules/expenses/index.php" class="nav-link-custom <?= isActiveRoute('/expenses/') ?>">
                <i class="bi bi-receipt-cutoff"></i>
                <span>Trip Expenses</span>
            </a>
        <?php endif; ?>

        <?php if (in_array($currentRole, ['super_admin', 'transport_manager', 'customer'], true)): ?>
            <div class="menu-heading">Billing & Finance</div>

            <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="nav-link-custom <?= isActiveRoute('/invoices/') ?>">
                <i class="bi bi-cash-stack"></i>
                <span>Invoices</span>
            </a>

            <a href="<?= BASE_URL ?>/modules/payments/index.php" class="nav-link-custom <?= isActiveRoute('/payments/') ?>">
                <i class="bi bi-credit-card-2-front-fill"></i>
                <span>Payments</span>
            </a>
        <?php endif; ?>

        <?php if (in_array($currentRole, ['super_admin', 'transport_manager'], true)): ?>
            <div class="menu-heading">Analytics & Settings</div>

            <a href="<?= BASE_URL ?>/modules/reports/index.php" class="nav-link-custom <?= isActiveRoute('/reports/') ?>">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Profitability & Reports</span>
            </a>

            <?php if ($currentRole === 'super_admin'): ?>
                <a href="<?= BASE_URL ?>/modules/settings/index.php" class="nav-link-custom <?= isActiveRoute('/settings/') ?>">
                    <i class="bi bi-sliders"></i>
                    <span>System Settings</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="sidebar-user">
        <div class="user-avatar-circle">
            <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
        </div>
        <div class="user-info text-truncate">
            <div class="fw-bold text-white text-truncate" style="font-size: 0.9rem;">
                <?= e($user['name'] ?? 'Guest') ?>
            </div>
            <div class="text-light" style="font-size: 0.75rem; color: #94a3b8 !important;">
                <?= e($user['role_name'] ?? 'Role') ?>
            </div>
        </div>
    </div>
</aside>
<div id="sidebar-backdrop" class="sidebar-backdrop"></div>
