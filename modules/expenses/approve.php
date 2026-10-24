<?php
/**
 * TruckBook Pro - Approve / Reject Trip Expense (Module I)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager']);

verifyCsrfOrDie();

$pdo = getDbConnection();
$expenseId = (int)($_POST['expense_id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$userId = currentUserId();

$stmt = $pdo->prepare("SELECT * FROM trip_expenses WHERE id = ?");
$stmt->execute([$expenseId]);
$expense = $stmt->fetch();

if (!$expense) {
    setFlash('danger', 'Expense record not found.');
    redirect('modules/expenses/index.php');
}

if ($action === 'approve') {
    $upStmt = $pdo->prepare("UPDATE trip_expenses SET status = 'approved', approved_by = ?, updated_at = NOW() WHERE id = ?");
    $upStmt->execute([$userId, $expenseId]);

    logAudit($userId, 'approve_expense', 'trip_expenses', $expenseId, ['status' => $expense['status']], ['status' => 'approved']);
    setFlash('success', "Expense of " . formatCurrency($expense['amount']) . " approved.");
} elseif ($action === 'reject') {
    $upStmt = $pdo->prepare("UPDATE trip_expenses SET status = 'rejected', approved_by = ?, updated_at = NOW() WHERE id = ?");
    $upStmt->execute([$userId, $expenseId]);

    logAudit($userId, 'reject_expense', 'trip_expenses', $expenseId, ['status' => $expense['status']], ['status' => 'rejected']);
    setFlash('warning', "Expense of " . formatCurrency($expense['amount']) . " rejected.");
}

redirect($_SERVER['HTTP_REFERER'] ?? 'modules/expenses/index.php');
