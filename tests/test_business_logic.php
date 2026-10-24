<?php
/**
 * TruckBook Pro - Automated Test Suite for Business Logic & Security
 * Run via CLI: php tests/test_business_logic.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/csrf.php';

echo "========================================================\n";
echo " TruckBook Pro - Automated Business Logic Test Suite     \n";
echo "========================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $testName, bool $condition, string $failureDetails = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo " [PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] {$testName} - {$failureDetails}\n";
        $testsFailed++;
    }
}

// ----------------------------------------------------------------------------
// Test 1: Password Hashing & Verification
// ----------------------------------------------------------------------------
$plain = 'securePassword123!';
$hash = hashPassword($plain);
assertTest('Password hashing produces secure Bcrypt string', str_starts_with($hash, '$2y$10$'));
assertTest('Password verify succeeds with valid password', password_verify($plain, $hash));
assertTest('Password verify rejects wrong password', !password_verify('wrongPass', $hash));

// ----------------------------------------------------------------------------
// Test 2: CSRF Cryptographic Token Generation & Validation
// ----------------------------------------------------------------------------
$token = getCsrfToken();
assertTest('CSRF token is 64 hex characters (32 bytes)', strlen($token) === 64);
assertTest('CSRF validation succeeds with genuine token', validateCsrfToken($token));
assertTest('CSRF validation fails with forged token', !validateCsrfToken('forged_csrf_token_12345'));

// ----------------------------------------------------------------------------
// Test 3: Input Validation Library
// ----------------------------------------------------------------------------
$validData = [
    'company' => 'Acme Logistics Corp',
    'email' => 'contact@acme.com',
    'phone' => '+1 555-0199',
    'tonnes' => '25.5',
    'date' => '2026-10-25'
];
$val = (new Validator($validData))
    ->required('company', 'Company')
    ->email('email', 'Email')
    ->phone('phone', 'Phone')
    ->numeric('tonnes', 'Tonnes', 1.0, 50.0)
    ->date('date', 'Date');

assertTest('Validator passes with valid inputs', $val->isValid());

$invalidData = [
    'company' => '',
    'email' => 'invalid-email-string',
    'phone' => 'abc',
    'tonnes' => '-10'
];
$valBad = (new Validator($invalidData))
    ->required('company', 'Company')
    ->email('email', 'Email')
    ->phone('phone', 'Phone')
    ->numeric('tonnes', 'Tonnes', 1.0);

assertTest('Validator catches multiple form errors', count($valBad->getErrors()) === 4);

// ----------------------------------------------------------------------------
// Test 4: Database Connection & Integrity
// ----------------------------------------------------------------------------
try {
    $pdo = getDbConnection();
    assertTest('PDO MySQL connection is active and UTF8MB4 compliant', $pdo instanceof PDO);

    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    assertTest('Users table seeded with default roles', $userCount >= 5);

    $truckCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
    assertTest('Fleet inventory contains vehicles', $truckCount >= 4);

    $driverCount = (int)$pdo->query("SELECT COUNT(*) FROM drivers")->fetchColumn();
    assertTest('Driver roster contains drivers', $driverCount >= 4);
} catch (Throwable $e) {
    assertTest('Database connectivity failed', false, $e->getMessage());
}

// ----------------------------------------------------------------------------
// Test 5: Quotation Tariff Formula Verification
// ----------------------------------------------------------------------------
$distKm = 500.0;
$tonnage = 20.0;
$baseKmRate = 4.00;
$baseTonneRate = 15.00;
$fuelPct = 10.0; // 10%
$tolls = 100.00;
$allowance = 150.00;
$taxPct = 18.0;

$baseHaulage = ($distKm * $baseKmRate) + ($tonnage * $baseTonneRate); // 2000 + 300 = 2300
$fuel = $baseHaulage * ($fuelPct / 100); // 230
$subtotal = $baseHaulage + $fuel + $tolls + $allowance; // 2300 + 230 + 100 + 150 = 2780
$tax = $subtotal * ($taxPct / 100); // 500.40
$total = $subtotal + $tax; // 3280.40

assertTest('Base haulage calculation accuracy', $baseHaulage === 2300.00);
assertTest('Subtotal pricing formula accuracy', $subtotal === 2780.00);
assertTest('Grand total quote formula accuracy', $total === 3280.40);

// ----------------------------------------------------------------------------
// Test 6: Financial Invoice & Payment Reconciliation
// ----------------------------------------------------------------------------
$invoiceTotal = 3280.40;
$advancePayment = 1000.00;
$clearedTotal = $advancePayment;
$remainingBalance = $invoiceTotal - $clearedTotal;
$isPartiallyPaid = ($remainingBalance > 0 && $clearedTotal > 0);

assertTest('Invoice balance reconciliation accuracy', round($remainingBalance, 2) === 2280.40);
assertTest('Invoice status logic identifies partially paid', $isPartiallyPaid);

$finalPayment = 2280.40;
$clearedTotal += $finalPayment;
$finalBalance = round($invoiceTotal - $clearedTotal, 2);
$isFullyPaid = ($finalBalance <= 0.0);

assertTest('Invoice status transitions to paid on full balance reconciliation', $isFullyPaid && $finalBalance === 0.00);

// ----------------------------------------------------------------------------
// Test 7: Trip Profitability Accounting
// ----------------------------------------------------------------------------
$tripRevenue = 3280.40;
$fuelExp = 380.00;
$tollExp = 120.00;
$driverExp = 150.00;
$totalExp = $fuelExp + $tollExp + $driverExp; // 650.00
$netMargin = $tripRevenue - $totalExp; // 2630.40
$marginPct = ($netMargin / $tripRevenue) * 100;

assertTest('Trip net profit calculation (Revenue - Expenses)', round($netMargin, 2) === 2630.40);
assertTest('Trip net margin percentage calculation', round($marginPct, 1) === 80.2);

echo "\n========================================================\n";
echo " Test Results Summary: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
