<?php
/**
 * TruckBook Pro - View Freight Request Details (Module F)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/require_role.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['super_admin', 'transport_manager', 'operator', 'customer']);

$pdo = getDbConnection();
$requestId = (int)($_GET['id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

$sql = "
    SELECT fr.*, c.company_name, c.contact_person, c.phone AS customer_phone, c.email AS customer_email
    FROM freight_requests fr
    JOIN customers c ON fr.customer_id = c.id
    WHERE fr.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$requestId]);
$req = $stmt->fetch();

if (!$req) {
    setFlash('danger', 'Freight request not found.');
    redirect('modules/freight_requests/index.php');
}

// Check quotation if already generated
$qStmt = $pdo->prepare("SELECT id, quotation_number, total_amount, status FROM quotations WHERE freight_request_id = ? LIMIT 1");
$qStmt->execute([$requestId]);
$quote = $qStmt->fetch();

$truckTypes = [
    'flatbed' => 'Flatbed',
    'container' => 'Container Carrier',
    'refrigerated' => 'Refrigerated Reefer',
    'tanker' => 'Liquid Bulk Tanker',
    'trailer' => 'Heavy Equipment Trailer',
    'box_truck' => 'Box Truck'
];

$pageTitle = 'Freight Request: ' . $req['request_reference'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="fw-bold mb-0"><?= e($req['request_reference']) ?></h2>
            <?= renderStatusBadge($req['status']) ?>
        </div>
        <p class="text-muted mb-0">Submitted on <?= formatDateTime($req['created_at']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> All Requests
        </a>
        <?php if ($req['status'] === 'pending' && hasAnyRole(['super_admin', 'transport_manager'])): ?>
            <a href="<?= BASE_URL ?>/modules/quotations/create.php?request_id=<?= $req['id'] ?>" class="btn btn-primary">
                <i class="bi bi-calculator me-1"></i> Generate Quotation
            </a>
        <?php elseif ($quote): ?>
            <a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $quote['id'] ?>" class="btn btn-outline-primary">
                <i class="bi bi-file-earmark-text me-1"></i> View Quotation (<?= e($quote['quotation_number']) ?>)
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Main Request Details -->
    <div class="col-lg-8">
        <div class="content-card mb-4">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-geo-alt-fill me-2 text-primary"></i> Route & Cargo Details</h5>
            </div>
            <div class="content-card-body">
                <!-- Route Banner -->
                <div class="p-3 bg-light rounded-3 mb-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                    <div>
                        <span class="text-muted small d-block">Origin / Pickup Location</span>
                        <strong class="fs-5"><?= e($req['pickup_city']) ?></strong>
                        <div class="text-muted small mt-1"><?= nl2br(e($req['pickup_address'])) ?></div>
                    </div>
                    <div class="text-center text-primary px-3">
                        <i class="bi bi-arrow-right fs-3 d-none d-sm-block"></i>
                        <i class="bi bi-arrow-down fs-3 d-sm-none"></i>
                    </div>
                    <div>
                        <span class="text-muted small d-block">Destination / Delivery Location</span>
                        <strong class="fs-5"><?= e($req['delivery_city']) ?></strong>
                        <div class="text-muted small mt-1"><?= nl2br(e($req['delivery_address'])) ?></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <span class="text-muted small d-block">Cargo Manifest Description</span>
                            <strong class="fs-6"><?= e($req['cargo_description']) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <span class="text-muted small d-block">Total Weight</span>
                            <strong class="fs-6 text-primary"><?= number_format((float)$req['cargo_weight_tonnes'], 2) ?> Tonnes</strong>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <span class="text-muted small d-block">Required Vehicle</span>
                            <strong class="fs-6"><?= $truckTypes[$req['truck_type_required']] ?? $req['truck_type_required'] ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <span class="text-muted small d-block">Target / Preferred Departure Date</span>
                            <strong class="fs-6"><?= formatDate($req['preferred_date']) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <span class="text-muted small d-block">Request Status</span>
                            <?= renderStatusBadge($req['status']) ?>
                        </div>
                    </div>
                </div>

                <?php if ($req['special_instructions']): ?>
                    <div class="mt-4 p-3 rounded bg-warning-subtle text-dark border border-warning-subtle">
                        <h6 class="fw-bold mb-1"><i class="bi bi-exclamation-circle me-1"></i> Special Handling Instructions</h6>
                        <div><?= nl2br(e($req['special_instructions'])) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Customer Sidebar -->
    <div class="col-lg-4">
        <div class="content-card mb-4">
            <div class="content-card-header">
                <h5 class="m-0 fw-bold"><i class="bi bi-building me-2 text-primary"></i> Customer Information</h5>
            </div>
            <div class="content-card-body">
                <h5 class="fw-bold mb-1"><?= e($req['company_name']) ?></h5>
                <p class="text-muted mb-3">Contact: <?= e($req['contact_person']) ?></p>

                <div class="mb-3">
                    <span class="text-muted small d-block">Phone Contact</span>
                    <a href="tel:<?= e($req['customer_phone']) ?>" class="fw-semibold text-decoration-none">
                        <?= e($req['customer_phone']) ?>
                    </a>
                </div>
                <div class="mb-3">
                    <span class="text-muted small d-block">Email</span>
                    <a href="mailto:<?= e($req['customer_email']) ?>" class="fw-semibold text-decoration-none">
                        <?= e($req['customer_email']) ?>
                    </a>
                </div>

                <div class="pt-3 border-top">
                    <a href="<?= BASE_URL ?>/modules/customers/view.php?id=<?= $req['customer_id'] ?>" class="btn btn-sm btn-light border w-100">
                        <i class="bi bi-person-lines-fill me-1"></i> Customer Full Profile
                    </a>
                </div>
            </div>
        </div>

        <?php if ($quote): ?>
            <div class="content-card bg-primary text-white border-0">
                <div class="content-card-body p-4 text-center">
                    <i class="bi bi-check-circle-fill display-4 text-info mb-2 d-block"></i>
                    <h5 class="fw-bold text-white mb-1">Quotation Issued</h5>
                    <p class="text-light small mb-3">Quote #<?= e($quote['quotation_number']) ?> has been generated.</p>
                    <div class="fs-3 fw-bold text-white mb-3"><?= formatCurrency($quote['total_amount']) ?></div>
                    <a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $quote['id'] ?>" class="btn btn-light text-primary fw-bold w-100">
                        View Official Quotation
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
