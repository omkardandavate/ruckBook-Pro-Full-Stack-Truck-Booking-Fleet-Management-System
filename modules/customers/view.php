<?php
/**
 * TruckBook Pro - View Customer Profile (Module C)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer']);

$pdo = getDbConnection();
$customerId = (int)($_GET['id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

// If customer role, only allow viewing their own profile
if ($currentRole === 'customer') {
    $myCustStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
    $myCustStmt->execute([$userId]);
    $myCustId = (int)$myCustStmt->fetchColumn();
    if ($customerId !== $myCustId) {
        $customerId = $myCustId;
    }
}

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    setFlash('danger', 'Customer record not found.');
    redirect('modules/customers/index.php');
}

// 1. Compute Customer Metrics
$totalBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE customer_id = {$customerId}")->fetchColumn();
$activeBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE customer_id = {$customerId} AND status IN ('confirmed', 'in_progress')")->fetchColumn();
$totalInvoiced = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE customer_id = {$customerId} AND payment_status != 'cancelled'")->fetchColumn();
$totalPaid = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE customer_id = {$customerId} AND payment_status != 'cancelled'")->fetchColumn();
$outstandingBalance = $totalInvoiced - $totalPaid;

// 2. Fetch Customer Bookings
$bookStmt = $pdo->prepare("
    SELECT b.id, b.booking_number, b.pickup_city, b.delivery_city, b.planned_pickup_date,
           b.agreed_rate, b.status, v.registration_number, d.name AS driver_name
    FROM bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN drivers d ON b.driver_id = d.id
    WHERE b.customer_id = ?
    ORDER BY b.created_at DESC
");
$bookStmt->execute([$customerId]);
$bookings = $bookStmt->fetchAll();

// 3. Fetch Customer Invoices
$invStmt = $pdo->prepare("
    SELECT id, invoice_number, subtotal, tax_amount, total_amount, paid_amount, balance_amount, payment_status, issued_date, due_date
    FROM invoices
    WHERE customer_id = ?
    ORDER BY issued_date DESC
");
$invStmt->execute([$customerId]);
$invoices = $invStmt->fetchAll();

$pageTitle = 'Customer Profile: ' . $customer['company_name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($customer['company_name']) ?></h2>
            <?= renderStatusBadge($customer['status']) ?>
        </div>
        <p class="text-muted mb-0">Customer ID #<?= str_pad((string)$customer['id'], 5, '0', STR_PAD_LEFT) ?> | Registered on <?= formatDate($customer['created_at']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($currentRole !== 'customer'): ?>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Customers List
            </a>
            <a href="edit.php?id=<?= $customer['id'] ?>" class="btn btn-light border">
                <i class="bi bi-pencil me-1"></i> Edit Profile
            </a>
            <a href="<?= BASE_URL ?>/modules/bookings/create.php?customer_id=<?= $customer['id'] ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> New Booking
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Customer KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Total Bookings</div>
                    <div class="stat-value"><?= number_format($totalBookings) ?></div>
                    <small class="text-muted"><?= $activeBookings ?> Currently Active</small>
                </div>
                <div class="stat-icon primary"><i class="bi bi-calendar2-check-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Total Invoiced</div>
                    <div class="stat-value"><?= formatCurrency($totalInvoiced) ?></div>
                    <small class="text-muted">Lifetime Freight Spend</small>
                </div>
                <div class="stat-icon info"><i class="bi bi-receipt"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Total Payments</div>
                    <div class="stat-value text-success"><?= formatCurrency($totalPaid) ?></div>
                    <small class="text-muted">Received & Cleared</small>
                </div>
                <div class="stat-icon success"><i class="bi bi-cash-stack"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Outstanding Due</div>
                    <div class="stat-value <?= $outstandingBalance > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= formatCurrency($outstandingBalance) ?>
                    </div>
                    <small class="text-muted"><?= $outstandingBalance > 0 ? 'Pending Collection' : 'Zero Balance' ?></small>
                </div>
                <div class="stat-icon <?= $outstandingBalance > 0 ? 'danger' : 'success' ?>">
                    <i class="bi bi-credit-card-2-front-fill"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Contact Details Sidebar -->
    <div class="col-lg-4">
        <div class="content-card mb-4">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-info-circle me-2 text-primary"></i> Contact Profile</h5>
            </div>
            <div class="content-card-body">
                <div class="mb-3">
                    <span class="text-muted small d-block">Authorized Contact Person</span>
                    <strong class="fs-6"><?= e($customer['contact_person']) ?></strong>
                </div>
                <div class="mb-3">
                    <span class="text-muted small d-block">Official Email</span>
                    <a href="mailto:<?= e($customer['email']) ?>" class="text-decoration-none fw-semibold">
                        <?= e($customer['email']) ?>
                    </a>
                </div>
                <div class="mb-3">
                    <span class="text-muted small d-block">Phone Contact</span>
                    <span class="fw-semibold"><?= e($customer['phone']) ?></span>
                </div>
                <div class="mb-3">
                    <span class="text-muted small d-block">Tax / GST Number</span>
                    <span class="badge bg-light text-dark border"><?= e($customer['tax_gst_number'] ?: 'Not Specified') ?></span>
                </div>
                <div class="mb-0">
                    <span class="text-muted small d-block">Billing Address</span>
                    <div class="fw-semibold">
                        <?= e($customer['address']) ?><br>
                        <?= e($customer['city']) ?>, <?= e($customer['state']) ?> - <?= e($customer['postal_code']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- History Tabs -->
    <div class="col-lg-8">
        <div class="content-card mb-4">
            <div class="content-card-header">
                <ul class="nav nav-tabs card-header-tabs" id="customerTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active fw-semibold" id="bookings-tab" data-bs-toggle="tab" data-bs-target="#bookingsPane" type="button" role="tab">
                            <i class="bi bi-calendar2-range me-1"></i> Bookings (<?= count($bookings) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold" id="invoices-tab" data-bs-toggle="tab" data-bs-target="#invoicesPane" type="button" role="tab">
                            <i class="bi bi-receipt me-1"></i> Invoices & Billing (<?= count($invoices) ?>)
                        </button>
                    </li>
                </ul>
            </div>

            <div class="tab-content" id="customerTabsContent">
                <!-- Bookings Tab -->
                <div class="tab-pane fade show active p-0" id="bookingsPane" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Booking #</th>
                                    <th>Route</th>
                                    <th>Vehicle & Driver</th>
                                    <th>Planned Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bookings)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No bookings placed yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($bookings as $b): ?>
                                        <tr>
                                            <td class="fw-bold"><?= e($b['booking_number']) ?></td>
                                            <td><?= e($b['pickup_city']) ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= e($b['delivery_city']) ?></td>
                                            <td>
                                                <?= e($b['registration_number'] ?: 'Unassigned') ?><br>
                                                <small class="text-muted"><?= e($b['driver_name']) ?></small>
                                            </td>
                                            <td><?= formatDate($b['planned_pickup_date']) ?></td>
                                            <td class="fw-bold"><?= formatCurrency($b['agreed_rate']) ?></td>
                                            <td><?= renderStatusBadge($b['status']) ?></td>
                                            <td class="text-end">
                                                <a href="<?= BASE_URL ?>/modules/bookings/view.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-light border">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Invoices Tab -->
                <div class="tab-pane fade p-0" id="invoicesPane" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Issued Date</th>
                                    <th>Due Date</th>
                                    <th>Total Amount</th>
                                    <th>Paid Amount</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($invoices)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No invoices generated yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($invoices as $inv): ?>
                                        <tr>
                                            <td class="fw-bold"><?= e($inv['invoice_number']) ?></td>
                                            <td><?= formatDate($inv['issued_date']) ?></td>
                                            <td><?= formatDate($inv['due_date']) ?></td>
                                            <td class="fw-bold"><?= formatCurrency($inv['total_amount']) ?></td>
                                            <td class="text-success"><?= formatCurrency($inv['paid_amount']) ?></td>
                                            <td class="fw-bold <?= $inv['balance_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                                <?= formatCurrency($inv['balance_amount']) ?>
                                            </td>
                                            <td><?= renderStatusBadge($inv['payment_status']) ?></td>
                                            <td class="text-end">
                                                <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-light border">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
