<?php
/**
 * TruckBook Pro - Export Profitability & Trip Report to CSV (Module K)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';

requireRole(['super_admin', 'transport_manager']);

$pdo = getDbConnection();

$startDate = trim($_GET['start_date'] ?? date('Y-m-01', strtotime('-5 months')));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$customerFilter = (int)($_GET['customer_id'] ?? 0);
$vehicleFilter = (int)($_GET['vehicle_id'] ?? 0);

$sql = "
    SELECT t.trip_number, b.booking_number, c.company_name,
           b.pickup_city, b.delivery_city, b.cargo_description,
           v.registration_number, d.name AS driver_name,
           t.planned_departure, t.actual_arrival, t.distance_km,
           b.agreed_rate AS trip_revenue,
           COALESCE((
               SELECT SUM(amount) FROM trip_expenses te 
               WHERE te.trip_id = t.id AND te.status = 'approved'
           ), 0) AS trip_expenses,
           t.delivery_status
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN customers c ON b.customer_id = c.id
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
    WHERE t.created_at BETWEEN ? AND ?
";
$params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];

if ($customerFilter > 0) {
    $sql .= " AND b.customer_id = ?";
    $params[] = $customerFilter;
}
if ($vehicleFilter > 0) {
    $sql .= " AND t.vehicle_id = ?";
    $params[] = $vehicleFilter;
}

$sql .= " ORDER BY t.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// CSV Headers
$filename = sprintf("truckbook_profitability_report_%s.csv", date('Ymd_His'));
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"{$filename}\"");

$out = fopen('php://output', 'w');

// Header Row
fputcsv($out, [
    'Trip Number',
    'Booking Reference',
    'Customer Company',
    'Origin City',
    'Destination City',
    'Cargo Description',
    'Vehicle Plate',
    'Assigned Driver',
    'Departure Date',
    'Arrival Date',
    'Distance (KM)',
    'Trip Revenue ($)',
    'Approved Expenses ($)',
    'Net Margin ($)',
    'Profit Margin (%)',
    'Delivery Status'
]);

foreach ($rows as $r) {
    $rev = (float)$r['trip_revenue'];
    $exp = (float)$r['trip_expenses'];
    $net = $rev - $exp;
    $marginPct = ($rev > 0) ? round(($net / $rev) * 100, 2) : 0.0;

    fputcsv($out, [
        $r['trip_number'],
        $r['booking_number'],
        $r['company_name'],
        $r['pickup_city'],
        $r['delivery_city'],
        $r['cargo_description'],
        $r['registration_number'],
        $r['driver_name'],
        $r['planned_departure'],
        $r['actual_arrival'] ?: 'In Progress',
        $r['distance_km'],
        number_format($rev, 2, '.', ''),
        number_format($exp, 2, '.', ''),
        number_format($net, 2, '.', ''),
        $marginPct . '%',
        $r['delivery_status']
    ]);
}

fclose($out);
exit;
