<?php
/**
 * TruckBook Pro - Quotation Status Action Handler (Module F)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'customer']);

verifyCsrfOrDie();

$pdo = getDbConnection();
$quoteId = (int)($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$currentRole = currentRole();
$userId = currentUserId();

$stmt = $pdo->prepare("
    SELECT q.*, c.company_name, c.user_id AS customer_user_id
    FROM quotations q
    JOIN customers c ON q.customer_id = c.id
    WHERE q.id = ?
");
$stmt->execute([$quoteId]);
$quote = $stmt->fetch();

if (!$quote) {
    setFlash('danger', 'Quotation not found.');
    redirect('modules/quotations/index.php');
}

// Ownership check for customer
if ($currentRole === 'customer' && (int)$quote['customer_user_id'] !== $userId) {
    setFlash('danger', 'Unauthorized quotation modification.');
    redirect('dashboard.php');
}

if ($action === 'accept') {
    $upStmt = $pdo->prepare("UPDATE quotations SET status = 'accepted', updated_at = NOW() WHERE id = ?");
    $upStmt->execute([$quoteId]);

    // Notify Managers
    $mgrs = $pdo->query("SELECT id FROM users WHERE role_id IN (1, 2)")->fetchAll();
    foreach ($mgrs as $mgr) {
        createNotification(
            (int)$mgr['id'],
            "Quotation Accepted: {$quote['quotation_number']}",
            "{$quote['company_name']} has accepted the quotation. Ready for truck and driver assignment.",
            "modules/bookings/create.php?quote_id={$quoteId}",
            'success'
        );
    }

    logAudit($userId, 'accept_quotation', 'quotations', $quoteId, ['status' => $quote['status']], ['status' => 'accepted']);
    setFlash('success', "Quotation {$quote['quotation_number']} accepted! Our dispatch team is assigning your truck and driver.");

} elseif ($action === 'reject') {
    $upStmt = $pdo->prepare("UPDATE quotations SET status = 'rejected', updated_at = NOW() WHERE id = ?");
    $upStmt->execute([$quoteId]);

    logAudit($userId, 'reject_quotation', 'quotations', $quoteId, ['status' => $quote['status']], ['status' => 'rejected']);
    setFlash('info', "Quotation {$quote['quotation_number']} was marked as declined.");
}

redirect('modules/quotations/view.php?id=' . $quoteId);
