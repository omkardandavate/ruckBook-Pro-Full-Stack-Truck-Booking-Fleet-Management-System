<?php
/**
 * TruckBook Pro - Trip Milestone & POD Upload Processor (Module H)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'driver']);

verifyCsrfOrDie();

$pdo = getDbConnection();
$tripId = (int)($_POST['trip_id'] ?? 0);
$status = trim($_POST['status'] ?? '');
$locationNote = trim($_POST['location_note'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');
$receiverName = trim($_POST['receiver_name'] ?? '');
$endOdometer = !empty($_POST['end_odometer']) ? (int)$_POST['end_odometer'] : null;

$allowedStatuses = ['scheduled', 'dispatched', 'in_transit', 'arrived', 'unloading', 'delivered', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    setFlash('danger', 'Invalid trip status.');
    redirect('modules/trips/view.php?id=' . $tripId);
}

$stmt = $pdo->prepare("
    SELECT t.*, b.customer_id, c.user_id AS customer_user_id
    FROM trips t
    JOIN bookings b ON t.booking_id = b.id
    JOIN customers c ON b.customer_id = c.id
    WHERE t.id = ?
");
$stmt->execute([$tripId]);
$trip = $stmt->fetch();

if (!$trip) {
    setFlash('danger', 'Trip not found.');
    redirect('modules/trips/index.php');
}

// Ownership check if driver
if (currentRole() === 'driver') {
    $dCheck = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ? LIMIT 1");
    $dCheck->execute([currentUserId()]);
    $myDrvId = (int)$dCheck->fetchColumn();
    if ((int)$trip['driver_id'] !== $myDrvId) {
        setFlash('danger', 'Unauthorized operation on this trip.');
        redirect('dashboard.php');
    }
}

try {
    $pdo->beginTransaction();

    $podPath = $trip['proof_of_delivery_path'];

    // Handle File Upload if provided
    if (!empty($_FILES['pod_file']['name']) && $_FILES['pod_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['pod_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];

        if (!in_array($ext, $allowedExts, true)) {
            throw new RuntimeException("Invalid POD file type. Allowed formats: JPG, PNG, PDF.");
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException("POD file size cannot exceed 5MB.");
        }

        $uploadDir = APP_ROOT . '/storage/uploads/pod';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = sprintf("pod_trip_%d_%s.%s", $tripId, time(), $ext);
        $destPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException("Failed to move uploaded POD file to storage.");
        }

        $podPath = 'storage/uploads/pod/' . $filename;

        // Log document
        $docStmt = $pdo->prepare("
            INSERT INTO documents (related_entity_type, related_entity_id, document_type, document_name, file_path, file_size, mime_type, uploaded_by, created_at)
            VALUES ('trip', ?, 'proof_of_delivery', ?, ?, ?, ?, ?, NOW())
        ");
        $docStmt->execute([
            $tripId,
            $file['name'],
            $podPath,
            $file['size'],
            $file['type'] ?: 'image/jpeg',
            currentUserId()
        ]);
    }

    // Departure timestamp check
    $actualDeparture = $trip['actual_departure'];
    if (in_array($status, ['dispatched', 'in_transit'], true) && empty($actualDeparture)) {
        $actualDeparture = date('Y-m-d H:i:s');
    }

    // Distance computation
    $distance = (float)$trip['distance_km'];
    $actualArrival = $trip['actual_arrival'];

    if ($status === 'delivered') {
        $actualArrival = date('Y-m-d H:i:s');

        if ($endOdometer !== null && $endOdometer > (int)$trip['start_odometer']) {
            $distance = (float)($endOdometer - (int)$trip['start_odometer']);
        }

        // Release vehicle back to available and update its odometer
        $upVeh = $pdo->prepare("
            UPDATE vehicles 
            SET status = 'available', 
                current_odometer = GREATEST(current_odometer, ?),
                updated_at = NOW() 
            WHERE id = ?
        ");
        $upVeh->execute([$endOdometer ?: (int)$trip['start_odometer'], $trip['vehicle_id']]);

        // Release driver back to available
        $upDrv = $pdo->prepare("UPDATE drivers SET status = 'available', updated_at = NOW() WHERE id = ?");
        $upDrv->execute([$trip['driver_id']]);

        // Mark booking as completed
        $upBkg = $pdo->prepare("UPDATE bookings SET status = 'completed', updated_at = NOW() WHERE id = ?");
        $upBkg->execute([$trip['booking_id']]);

        // Notify customer
        if (!empty($trip['customer_user_id'])) {
            createNotification(
                (int)$trip['customer_user_id'],
                "Freight Delivered: Trip {$trip['trip_number']}",
                "Your shipment has been safely delivered to destination and Proof of Delivery logged.",
                "modules/trips/view.php?id={$tripId}",
                'success'
            );
        }
    }

    // Update Trip Record
    $upTrip = $pdo->prepare("
        UPDATE trips
        SET delivery_status = ?,
            actual_departure = ?,
            actual_arrival = ?,
            end_odometer = COALESCE(?, end_odometer),
            distance_km = ?,
            proof_of_delivery_path = ?,
            receiver_name = COALESCE(?, receiver_name),
            trip_notes = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $upTrip->execute([
        $status,
        $actualDeparture,
        $actualArrival,
        $endOdometer,
        $distance,
        $podPath,
        $receiverName ?: null,
        $remarks ?: $trip['trip_notes'],
        $tripId
    ]);

    // Insert Milestone History Entry
    $histStmt = $pdo->prepare("
        INSERT INTO trip_status_history (trip_id, status, location_note, remarks, updated_by, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $histStmt->execute([
        $tripId,
        $status,
        $locationNote ?: 'Status updated',
        $remarks ?: null,
        currentUserId()
    ]);

    logAudit(currentUserId(), 'update_trip_status', 'trips', $tripId, ['status' => $trip['delivery_status']], ['status' => $status]);

    $pdo->commit();

    setFlash('success', "Trip status updated to " . ucwords(str_replace('_', ' ', $status)) . ".");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Trip status update error: " . $e->getMessage());
    setFlash('danger', $e->getMessage());
}

redirect('modules/trips/view.php?id=' . $tripId);
