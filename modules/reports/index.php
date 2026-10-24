<?php
/**
 * TruckBook Pro - Trip Profitability & Business Intelligence Dashboard (Module K)
 * Calculates true net profits: Net Profit = Recognized Trip Revenue - Approved Trip Expenses
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/header.php';

requireRole(['super_admin', 'transport_manager']);

$pdo = getDbConnection();

// Filters
$startDate = trim($_GET['start_date'] ?? date('Y-m-01', strtotime('-5 months')));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$customerFilter = (int)($_GET['customer_id'] ?? 0);
$vehicleFilter = (int)($_GET['vehicle_id'] ?? 0);

$customers = $pdo->query("SELECT id, company_name FROM customers ORDER BY company_name ASC")->fetchAll();
$vehicles = $pdo->query("SELECT id, registration_number, model FROM vehicles ORDER BY registration_number ASC")->fetchAll();

// 1. Overall Financial Aggregates
$revSql = "
    SELECT COALESCE(SUM(inv.total_amount), 0) AS total_revenue
    FROM invoices inv
    JOIN bookings b ON inv.booking_id = b.id
    WHERE inv.payment_status != 'cancelled'
      AND inv.issued_date BETWEEN ? AND ?
";
$revParams = [$startDate, $endDate];
if ($customerFilter > 0) {
    $revSql .= " AND inv.customer_id = ?";
    $revParams[] = $customerFilter;
}
if ($vehicleFilter > 0) {
    $revSql .= " AND b.vehicle_id = ?";
    $revParams[] = $vehicleFilter;
}
$revStmt = $pdo->prepare($revSql);
$revStmt->execute($revParams);
$totalRevenue = (float)$revStmt->fetchColumn();

// Approved Expenses
$expSql = "
    SELECT COALESCE(SUM(te.amount), 0) AS total_expenses
    FROM trip_expenses te
    JOIN trips t ON te.trip_id = t.id
    JOIN bookings b ON t.booking_id = b.id
    WHERE te.status = 'approved'
      AND te.expense_date BETWEEN ? AND ?
";
$expParams = [$startDate, $endDate];
if ($customerFilter > 0) {
    $expSql .= " AND b.customer_id = ?";
    $expParams[] = $customerFilter;
}
if ($vehicleFilter > 0) {
    $expSql .= " AND t.vehicle_id = ?";
    $expParams[] = $vehicleFilter;
}
$expStmt = $pdo->prepare($expSql);
$expStmt->execute($expParams);
$totalExpenses = (float)$expStmt->fetchColumn();

$netProfit = $totalRevenue - $totalExpenses;
$profitMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0.0;

// 2. Expense Category Breakdown for Chart
$catStmt = $pdo->prepare("
    SELECT te.category, COALESCE(SUM(te.amount), 0) AS cat_total
    FROM trip_expenses te
    WHERE te.status = 'approved' AND te.expense_date BETWEEN ? AND ?
    GROUP BY te.category
");
$catStmt->execute([$startDate, $endDate]);
$expenseCategories = $catStmt->fetchAll();

$catLabels = [];
$catValues = [];
foreach ($expenseCategories as $c) {
    $catLabels[] = ucwords(str_replace('_', ' ', $c['category']));
    $catValues[] = (float)$c['cat_total'];
}

// 3. Most Profitable Routes
$routeStmt = $pdo->prepare("
    SELECT * FROM (
        SELECT b.pickup_city, b.delivery_city,
               COUNT(DISTINCT t.id) AS total_trips,
               COALESCE(SUM(b.agreed_rate), 0) AS route_revenue,
               COALESCE((
                   SELECT SUM(te.amount) 
                   FROM trip_expenses te 
                   JOIN trips t2 ON te.trip_id = t2.id 
                   JOIN bookings b2 ON t2.booking_id = b2.id
                   WHERE b2.pickup_city = b.pickup_city 
                     AND b2.delivery_city = b.delivery_city 
                     AND te.status = 'approved'
               ), 0) AS route_expenses
        FROM bookings b
        JOIN trips t ON b.id = t.booking_id
        WHERE t.delivery_status = 'delivered'
        GROUP BY b.pickup_city, b.delivery_city
    ) AS route_sub
    ORDER BY (route_revenue - route_expenses) DESC
    LIMIT 5
");
$routeStmt->execute();
$profitableRoutes = $routeStmt->fetchAll();

// 4. Vehicle Utilization & Performance
$vehPerfStmt = $pdo->prepare("
    SELECT v.registration_number, v.model, v.truck_type,
           COUNT(t.id) AS trips_completed,
           COALESCE(SUM(t.distance_km), 0) AS total_distance,
           COALESCE(SUM(b.agreed_rate), 0) AS vehicle_revenue
    FROM vehicles v
    LEFT JOIN trips t ON v.id = t.vehicle_id AND t.delivery_status = 'delivered'
    LEFT JOIN bookings b ON t.booking_id = b.id
    GROUP BY v.id
    ORDER BY vehicle_revenue DESC
");
$vehPerfStmt->execute();
$vehiclePerformance = $vehPerfStmt->fetchAll();
?>

<div class="page-header">
    <div>
        <h2 class="fw-bold mb-1">Trip Profitability & Analytics</h2>
        <p class="text-muted mb-0">Reconciled business profitability, lane margins, and fleet utilization metrics.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/reports/export_csv.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&customer_id=<?= $customerFilter ?>&vehicle_id=<?= $vehicleFilter ?>" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export to CSV
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="content-card mb-4">
    <div class="content-card-body p-3">
        <form method="GET" action="index.php" class="row g-2 align-items-center">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= e($startDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= e($endDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Customer Filter</label>
                <select name="customer_id" class="form-select">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $customerFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Vehicle Filter</label>
                <select name="vehicle_id" class="form-select">
                    <option value="">All Vehicles</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $vehicleFilter === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['registration_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100" style="margin-top: 24px;">Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Financial KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Recognized Revenue</div>
                    <div class="stat-value text-primary"><?= formatCurrency($totalRevenue) ?></div>
                    <small class="text-muted">Total invoiced freight billing</small>
                </div>
                <div class="stat-icon primary"><i class="bi bi-cash-stack"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Approved Trip Expenses</div>
                    <div class="stat-value text-danger"><?= formatCurrency($totalExpenses) ?></div>
                    <small class="text-muted">Fuel, tolls & allowances</small>
                </div>
                <div class="stat-icon danger"><i class="bi bi-receipt-cutoff"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Net Profit Margin</div>
                    <div class="stat-value <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= formatCurrency($netProfit) ?>
                    </div>
                    <small class="text-muted"><?= number_format($profitMargin, 1) ?>% Net Margin</small>
                </div>
                <div class="stat-icon <?= $netProfit >= 0 ? 'success' : 'danger' ?>"><i class="bi bi-graph-up-arrow"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Profitability Ratio</div>
                    <div class="stat-value text-dark"><?= number_format($profitMargin, 1) ?>%</div>
                    <small class="text-muted"><?= $profitMargin >= 20 ? 'Healthy Transport Margin' : 'Standard Operations' ?></small>
                </div>
                <div class="stat-icon purple"><i class="bi bi-pie-chart"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Section -->
<div class="row g-4 mb-4">
    <!-- Financial Comparison Bar Chart -->
    <div class="col-lg-8">
        <div class="content-card h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-bar-chart-fill me-2 text-primary"></i> Revenue vs Operational Expenses</h5>
            </div>
            <div class="content-card-body">
                <div style="height: 280px;">
                    <canvas id="profitComparisonChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Expense Category Breakdown Doughnut -->
    <div class="col-lg-4">
        <div class="content-card h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-pie-chart-fill me-2 text-info"></i> Expense Category Share</h5>
            </div>
            <div class="content-card-body d-flex flex-column justify-content-center">
                <div style="height: 250px;">
                    <canvas id="expenseCategoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Lane Profitability & Fleet Utilization Tables -->
<div class="row g-4">
    <!-- Profitable Routes -->
    <div class="col-lg-6">
        <div class="content-card h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-geo-alt-fill me-2 text-primary"></i> High-Yield Freight Lanes / Routes</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Freight Lane (Route)</th>
                            <th>Trips</th>
                            <th>Revenue</th>
                            <th>Expenses</th>
                            <th>Net Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($profitableRoutes)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No completed delivery routes found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($profitableRoutes as $r): ?>
                                <?php
                                    $rProfit = (float)$r['route_revenue'] - (float)$r['route_expenses'];
                                ?>
                                <tr>
                                    <td><strong><?= e($r['pickup_city']) ?> &rarr; <?= e($r['delivery_city']) ?></strong></td>
                                    <td><?= number_format($r['total_trips']) ?></td>
                                    <td class="fw-semibold"><?= formatCurrency($r['route_revenue']) ?></td>
                                    <td class="text-danger"><?= formatCurrency($r['route_expenses']) ?></td>
                                    <td class="fw-bold <?= $rProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= formatCurrency($rProfit) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Vehicle Utilization Performance -->
    <div class="col-lg-6">
        <div class="content-card h-100">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-truck me-2 text-primary"></i> Vehicle Performance & Revenue</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Type</th>
                            <th>Trips Run</th>
                            <th>Total KM</th>
                            <th>Gross Billed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vehiclePerformance)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No vehicle performance data.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vehiclePerformance as $vp): ?>
                                <tr>
                                    <td><strong><?= e($vp['registration_number']) ?></strong></td>
                                    <td><span class="badge bg-light text-dark border"><?= ucwords(str_replace('_', ' ', $vp['truck_type'])) ?></span></td>
                                    <td><?= number_format($vp['trips_completed']) ?></td>
                                    <td><?= number_format((float)$vp['total_distance']) ?> KM</td>
                                    <td class="fw-bold text-success"><?= formatCurrency($vp['vehicle_revenue']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sym = '<?= getSetting("currency_symbol", "$") ?>';

    // 1. Profit Comparison Chart
    const compCtx = document.getElementById('profitComparisonChart');
    if (compCtx) {
        new Chart(compCtx, {
            type: 'bar',
            data: {
                labels: ['Gross Revenue', 'Approved Expenses', 'Net Profit Margin'],
                datasets: [{
                    data: [<?= $totalRevenue ?>, <?= $totalExpenses ?>, <?= $netProfit ?>],
                    backgroundColor: ['#2563eb', '#ef4444', '#10b981'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        ticks: {
                            callback: function(v) { return sym + v.toLocaleString(); }
                        }
                    }
                }
            }
        });
    }

    // 2. Expense Category Doughnut Chart
    const catCtx = document.getElementById('expenseCategoryChart');
    if (catCtx) {
        new Chart(catCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($catLabels) ?>,
                datasets: [{
                    data: <?= json_encode($catValues) ?>,
                    backgroundColor: ['#2563eb', '#06b6d4', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#64748b'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
                },
                cutout: '65%'
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
