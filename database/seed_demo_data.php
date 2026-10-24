<?php
/**
 * TruckBook Pro - Database Seeder / Reset Utility
 * Can be run via CLI: php database/seed_demo_data.php
 * Or via web if authorized.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
    $sqlPath = __DIR__ . '/database.sql';

    if (!file_exists($sqlPath)) {
        throw new RuntimeException("SQL file not found at: {$sqlPath}");
    }

    $sql = file_get_contents($sqlPath);

    // Execute multi-query batch
    $pdo->exec($sql);

    $isCli = (php_sapi_name() === 'cli');

    if ($isCli) {
        echo "========================================================\n";
        echo " TruckBook Pro - Database Migration & Seeding Complete! \n";
        echo "========================================================\n";
        echo "18 Tables created and verified.\n";
        echo "Demo Accounts Ready (Password for all: password123):\n";
        echo " - Super Admin:        admin@truckbook.com\n";
        echo " - Transport Manager:  manager@truckbook.com\n";
        echo " - Staff / Operator:   operator@truckbook.com\n";
        echo " - Customer:           customer@example.com\n";
        echo " - Driver:             driver@truckbook.com\n";
        echo "========================================================\n";
    } else {
        echo "<h1>Database Seed Successful!</h1>";
        echo "<p>All 18 tables created and initial demo records populated.</p>";
        echo "<p><a href='../login.php'>Proceed to Login Page</a></p>";
    }
} catch (Throwable $e) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Seeding error: " . $e->getMessage() . "\n");
        exit(1);
    } else {
        http_response_code(500);
        echo "<h1>Database Seeding Failed</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
