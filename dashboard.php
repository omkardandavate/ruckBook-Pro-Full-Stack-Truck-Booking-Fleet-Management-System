<?php
/**
 * TruckBook Pro - Main Operational & Analytics Dashboard (Module B)
 * Computes all operational metrics, revenue, expenses, and Chart.js feeds dynamically from MySQL.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/require_login.php';
require_once __DIR__ . '/includes/header.php';

$pdo = getDbConnection();
$currentRole = currentRole();
$userId = currentUserId();

// Customer Context filter if Customer is viewing
$customerRecord = null;
if ($currentRole === 'customer') {
    $custStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
    $custStmt->execute([$userId]);
    $customerRecord = $custStmt->fetch();
}

// Driver Context filter if Driver is viewing
$driverRecord = null;
if ($currentRole === 'driver') {
    $drvStmt = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ? LIMIT 1");
    $drvStmt->execute([$userId]);
    $driverRecord = $drvStmt->fetch();
}

// ----------------------------------------------------------------------------
// 1. Fetch Metrics based on Role
// ----------------------------------------------------------------------------
$metrics = [
    'total_bookings' => 0,
    'pending_requests' => 0,
    'confirmed_bookings' => 0,
    'available_trucks' => 0,
    'unavailable_trucks' => 0,
    'active_trips' => 0,
    'completed_deliveries' => 0,
    'total_revenue' => 0.00,
    'total_expenses' => 0.00,
    'net_profit' => 0.00,
];

try {
    if (in_array($currentRole, ['super_admin', 'transport_manager', 'operator'], true)) {
        // Fleet-wide aggregates
        $metrics['total_bookings'] = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
        $metrics['pending_requests'] = (int)$pdo->query("SELECT COUNT(*) FROM freight_requests WHERE status = 'pending'")->fetchColumn();
        $metrics['confirmed_bookings'] = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed', 'in_progress')")->fetchColumn();
        
        $metrics['available_trucks'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'available'")->fetchColumn();
        $metrics['unavailable_trucks'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status != 'available'")->fetchColumn();
        
        $metrics['active_trips'] = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE delivery_status IN ('dispatched', 'in_transit', 'arrived', 'unloading')")->fetchColumn();
        $metrics['completed_deliveries'] = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE delivery_status = 'delivered'")->fetchColumn();
        
        // Revenue is recognized from generated Invoices (or agreed rates of active/completed bookings)
        $metrics['total_revenue'] = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE payment_status != 'cancelled'")->fetchColumn();
        
        // Approved Expenses
        $metrics['total_expenses'] = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM trip_expenses WHERE status = 'approved'")->fetchColumn();
        $metrics['net_profit'] = $metrics['total_revenue'] - $metrics['total_expenses'];

    } elseif ($currentRole === 'customer' && $customerRecord) {
        $cId = (int)$customerRecord['id'];
        $metrics['total_bookings'] = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE customer_id = {$cId}")->fetchColumn();
        $metrics['pending_requests'] = (int)$pdo->query("SELECT COUNT(*) FROM freight_requests WHERE customer_id = {$cId} AND status = 'pending'")->fetchColumn();
        $metrics['confirmed_bookings'] = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE customer_id = {$cId} AND status IN ('confirmed', 'in_progress')")->fetchColumn();
        $metrics['completed_deliveries'] = (int)$pdo->query("
            SELECT COUNT(*) FROM trips t 
            JOIN bookings b ON t.booking_id = b.id 
            WHERE b.customer_id = {$cId} AND t.delivery_status = 'delivered'
        ")->fetchColumn();
        $metrics['total_revenue'] = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE customer_id = {$cId} AND payment_status != 'cancelled'")->fetchColumn();
        $metrics['active_trips'] = (int)$pdo->query("
            SELECT COUNT(*) FROM trips t 
            JOIN bookings b ON t.booking_id = b.id 
            WHERE b.customer_id = {$cId} AND t.delivery_status IN ('dispatched', 'in_transit')
        ")->fetchColumn();
    } elseif ($currentRole === 'driver' && $driverRecord) {
        $dId = (int)$driverRecord['id'];
        $metrics['active_trips'] = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE driver_id = {$dId} AND delivery_status != 'delivered'")->fetchColumn();
        $metrics['completed_deliveries'] = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE driver_id = {$dId} AND delivery_status = 'delivered'")->fetchColumn();
        $metrics['total_expenses'] = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM trip_expenses WHERE created_by = {$userId}")->fetchColumn();
    }
} catch (Throwable $e) {
    error_log("Dashboard metrics error: " . $e->getMessage());
}

// ----------------------------------------------------------------------------
// 2. Fetch Recent Activities / Bookings
// ----------------------------------------------------------------------------
$recentBookings = [];
try {
    if (in_array($currentRole, ['super_admin', 'transport_manager', 'operator'], true)) {
        $recentBookings = $pdo->query("
            SELECT b.id, b.booking_number, b.pickup_city, b.delivery_city, b.planned_pickup_date,
                   b.agreed_rate, b.status, c.company_name, v.registration_number, d.name AS driver_name
            FROM bookings b
            JOIN customers c ON b.customer_id = c.id
            LEFT JOIN vehicles v ON b.vehicle_id = v.id
            LEFT JOIN drivers d ON b.driver_id = d.id
            ORDER BY b.created_at DESC
            LIMIT 5
        ")->fetchAll();
    } elseif ($customerRecord) {
        $cId = (int)$customerRecord['id'];
        $recentBookings = $pdo->query("
            SELECT b.id, b.booking_number, b.pickup_city, b.delivery_city, b.planned_pickup_date,
                   b.agreed_rate, b.status, c.company_name, v.registration_number, d.name AS driver_name
            FROM bookings b
            JOIN customers c ON b.customer_id = c.id
            LEFT JOIN vehicles v ON b.vehicle_id = v.id
            LEFT JOIN drivers d ON b.driver_id = d.id
            WHERE b.customer_id = {$cId}
            ORDER BY b.created_at DESC
            LIMIT 5
        ")->fetchAll();
    }
} catch (Throwable $e) {
    error_log("Dashboard recent bookings error: " . $e->getMessage());
}

// ----------------------------------------------------------------------------
// 3. Fetch Active Trips with Live Status
// ----------------------------------------------------------------------------
$activeTrips = [];
try {
    $tripQuery = "
        SELECT t.id, t.trip_number, t.delivery_status, t.planned_departure, t.actual_departure,
               b.booking_number, b.pickup_city, b.delivery_city,
               v.registration_number, v.truck_type,
               d.name AS driver_name, d.phone AS driver_phone
        FROM trips t
        JOIN bookings b ON t.booking_id = b.id
        JOIN vehicles v ON t.vehicle_id = v.id
        JOIN drivers d ON t.driver_id = d.id
    ";

    if ($currentRole === 'driver' && $driverRecord) {
        $tripQuery .= " WHERE t.driver_id = " . (int)$driverRecord['id'];
    } elseif ($currentRole === 'customer' && $customerRecord) {
        $tripQuery .= " WHERE b.customer_id = " . (int)$customerRecord['id'];
    } else {
        $tripQuery .= " WHERE t.delivery_status != 'delivered'";
    }

    $tripQuery .= " ORDER BY t.created_at DESC LIMIT 5";
    $activeTrips = $pdo->query($tripQuery)->fetchAll();
} catch (Throwable $e) {
    error_log("Dashboard active trips error: " . $e->getMessage());
}

// ----------------------------------------------------------------------------
// 4. Monthly Revenue & Booking Distribution Data for Chart.js
// ----------------------------------------------------------------------------
$monthlyMonths = [];
$monthlyRevenue = [];
$monthlyExpenses = [];

try {
    $revStmt = $pdo->query("
        SELECT DATE_FORMAT(issued_date, '%b %Y') AS month_label,
               COALESCE(SUM(total_amount), 0) AS revenue
        FROM invoices
        WHERE payment_status != 'cancelled'
        GROUP BY DATE_FORMAT(issued_date, '%Y-%m'), month_label
        ORDER BY MIN(issued_date) ASC
        LIMIT 6
    ");
    while ($r = $revStmt->fetch()) {
        $monthlyMonths[] = $r['month_label'];
        $monthlyRevenue[] = (float)$r['revenue'];
        $monthlyExpenses[] = 0.00; // placeholder to align
    }

    if (empty($monthlyMonths)) {
        $monthlyMonths = [date('M Y')];
        $monthlyRevenue = [$metrics['total_revenue']];
    }
} catch (Throwable $e) {
    $monthlyMonths = [date('M Y')];
    $monthlyRevenue = [0];
}

// Vehicle status distribution
$vehicleStats = ['available' => 0, 'on_trip' => 0, 'maintenance' => 0];
try {
    $vStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM vehicles GROUP BY status");
    while ($v = $vStmt->fetch()) {
        if (isset($vehicleStats[$v['status']])) {
            $vehicleStats[$v['status']] = (int)$v['cnt'];
        }
    }
} catch (Throwable $e) {}
?>

<!-- Dashboard Top Banner -->
<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">
            <?php if ($currentRole === 'customer'): ?>
                Customer Portal Overview
            <?php elseif ($currentRole === 'driver'): ?>
                Driver Dispatch Center
            <?php else: ?>
                Fleet Operations & Logistics Dashboard
            <?php endif; ?>
        </h2>
        <p class="text-muted mb-0">
            Real-time status of freight movements, capacity, dispatches, and financial balances.
        </p>
    </div>

    <div class="d-flex align-items-center gap-2">
        <?php if ($currentRole === 'customer'): ?>
            <a href="<?= BASE_URL ?>/modules/freight_requests/create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Request Freight Quote
            </a>
        <?php elseif (in_array($currentRole, ['super_admin', 'transport_manager', 'operator'], true)): ?>
            <a href="<?= BASE_URL ?>/modules/freight_requests/index.php" class="btn btn-outline-primary">
                <i class="bi bi-box-seam me-1"></i> Freight Requests
            </a>
            <a href="<?= BASE_URL ?>/modules/bookings/create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Create Booking
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Primary Statistics Grid -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Total Bookings</div>
                    <div class="stat-value"><?= number_format($metrics['total_bookings']) ?></div>
                    <small class="text-muted"><i class="bi bi-check2 text-success"></i> <?= $metrics['confirmed_bookings'] ?> In Progress/Confirmed</small>
                </div>
                <div class="stat-icon primary">
                    <i class="bi bi-calendar2-check-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Active Trips</div>
                    <div class="stat-value"><?= number_format($metrics['active_trips']) ?></div>
                    <small class="text-muted"><i class="bi bi-truck text-primary"></i> <?= $metrics['completed_deliveries'] ?> Delivered</small>
                </div>
                <div class="stat-icon warning">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <?php if (in_array($currentRole, ['super_admin', 'transport_manager', 'operator'], true)): ?>
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card">
                <div class="stat-card-inner">
                    <div>
                        <div class="stat-label">Fleet Availability</div>
                        <div class="stat-value"><?= number_format($metrics['available_trucks']) ?> <span class="fs-6 text-muted font-normal">Ready</span></div>
                        <small class="text-muted"><i class="bi bi-cone-striped text-warning"></i> <?= $metrics['unavailable_trucks'] ?> On Trip/Maint.</small>
                    </div>
                    <div class="stat-icon success">
                        <i class="bi bi-truck-front-fill"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card">
                <div class="stat-card-inner">
                    <div>
                        <div class="stat-label">Estimated Net Profit</div>
                        <div class="stat-value text-success"><?= formatCurrency($metrics['net_profit']) ?></div>
                        <small class="text-muted">Rev: <?= formatCurrency($metrics['total_revenue']) ?> | Exp: <?= formatCurrency($metrics['total_expenses']) ?></small>
                    </div>
                    <div class="stat-icon purple">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card">
                <div class="stat-card-inner">
                    <div>
                        <div class="stat-label">Pending Quotes/Reqs</div>
                        <div class="stat-value"><?= number_format($metrics['pending_requests']) ?></div>
                        <small class="text-muted">Awaiting dispatch review</small>
                    </div>
                    <div class="stat-icon info">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card">
                <div class="stat-card-inner">
                    <div>
                        <div class="stat-label">Total Invoiced</div>
                        <div class="stat-value"><?= formatCurrency($metrics['total_revenue']) ?></div>
                        <small class="text-muted">Across all bookings</small>
                    </div>
                    <div class="stat-icon purple">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Charts Section (for Managers & Admins) -->
<?php if (in_array($currentRole, ['super_admin', 'transport_manager'], true)): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="content-card mb-0 h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-bar-chart-line-fill me-2 text-primary"></i> Monthly Freight Revenue</h5>
                <span class="badge bg-light text-dark border">MySQL Live Data</span>
            </div>
            <div class="content-card-body">
                <div style="height: 280px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="content-card mb-0 h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-pie-chart-fill me-2 text-info"></i> Fleet Status Breakdown</h5>
            </div>
            <div class="content-card-body d-flex flex-column justify-content-center">
                <div style="height: 240px;">
                    <canvas id="fleetChart"></canvas>
                </div>
                <div class="d-flex justify-content-around text-center mt-3 pt-2 border-top">
                    <div>
                        <span class="d-block fw-bold text-success"><?= $vehicleStats['available'] ?></span>
                        <small class="text-muted">Available</small>
                    </div>
                    <div>
                        <span class="d-block fw-bold text-primary"><?= $vehicleStats['on_trip'] ?></span>
                        <small class="text-muted">On Trip</small>
                    </div>
                    <div>
                        <span class="d-block fw-bold text-danger"><?= $vehicleStats['maintenance'] ?></span>
                        <small class="text-muted">Maintenance</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Active Trips & Recent Activity Table -->
<div class="row g-4">
    <div class="col-lg-12">
        <div class="content-card mb-4">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-truck me-2 text-primary"></i> Real-Time Trip Dispatches & Status</h5>
                <a href="<?= BASE_URL ?>/modules/trips/index.php" class="btn btn-sm btn-outline-primary">View All Trips</a>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Trip #</th>
                            <th>Booking Ref</th>
                            <th>Route</th>
                            <th>Vehicle & Type</th>
                            <th>Assigned Driver</th>
                            <th>Departure</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activeTrips)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="bi bi-truck display-6 d-block mb-2 text-light"></i>
                                    No active trips at this moment.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activeTrips as $t): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= e($t['trip_number']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= e($t['booking_number']) ?></span></td>
                                    <td>
                                        <div class="fw-semibold"><?= e($t['pickup_city']) ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= e($t['delivery_city']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= e($t['registration_number']) ?></div>
                                        <small class="text-muted"><?= ucwords(str_replace('_', ' ', $t['truck_type'])) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= e($t['driver_name']) ?></div>
                                        <small class="text-muted"><?= e($t['driver_phone']) ?></small>
                                    </td>
                                    <td><?= formatDateTime($t['actual_departure'] ?: $t['planned_departure']) ?></td>
                                    <td><?= renderStatusBadge($t['delivery_status']) ?></td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/modules/trips/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-light border">
                                            <i class="bi bi-eye"></i> Details
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

    <!-- Recent Bookings Table -->
    <?php if ($currentRole !== 'driver'): ?>
    <div class="col-lg-12">
        <div class="content-card mb-0">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-calendar2-range me-2 text-primary"></i> Recent Freight Bookings</h5>
                <a href="<?= BASE_URL ?>/modules/bookings/index.php" class="btn btn-sm btn-outline-primary">View All Bookings</a>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Booking #</th>
                            <th>Customer</th>
                            <th>Route</th>
                            <th>Vehicle / Driver</th>
                            <th>Planned Date</th>
                            <th>Rate</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentBookings)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    No recent bookings found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentBookings as $b): ?>
                                <tr>
                                    <td class="fw-bold"><?= e($b['booking_number']) ?></td>
                                    <td><?= e($b['company_name']) ?></td>
                                    <td><?= e($b['pickup_city']) ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= e($b['delivery_city']) ?></td>
                                    <td>
                                        <?php if ($b['registration_number']): ?>
                                            <div><?= e($b['registration_number']) ?></div>
                                            <small class="text-muted"><?= e($b['driver_name']) ?></small>
                                        <?php else: ?>
                                            <span class="text-warning small"><i class="bi bi-exclamation-circle"></i> Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatDate($b['planned_pickup_date']) ?></td>
                                    <td class="fw-bold"><?= formatCurrency($b['agreed_rate']) ?></td>
                                    <td><?= renderStatusBadge($b['status']) ?></td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/modules/bookings/view.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-light border">
                                            <i class="bi bi-eye"></i> View
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
    <?php endif; ?>
</div>

<?php if (in_array($currentRole, ['super_admin', 'transport_manager'], true)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Monthly Revenue Chart
    const revCtx = document.getElementById('revenueChart');
    if (revCtx) {
        new Chart(revCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($monthlyMonths) ?>,
                datasets: [{
                    label: 'Revenue (<?= getSetting("currency_symbol", "$") ?>)',
                    data: <?= json_encode($monthlyRevenue) ?>,
                    backgroundColor: 'rgba(37, 99, 235, 0.85)',
                    borderColor: '#2563eb',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(val) {
                                return '<?= getSetting("currency_symbol", "$") ?>' + val.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Fleet Status Donut Chart
    const fleetCtx = document.getElementById('fleetChart');
    if (fleetCtx) {
        new Chart(fleetCtx, {
            type: 'doughnut',
            data: {
                labels: ['Available', 'On Trip', 'Maintenance'],
                datasets: [{
                    data: [
                        <?= $vehicleStats['available'] ?>,
                        <?= $vehicleStats['on_trip'] ?>,
                        <?= $vehicleStats['maintenance'] ?>
                    ],
                    backgroundColor: ['#10b981', '#2563eb', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    }
                },
                cutout: '70%'
            }
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
