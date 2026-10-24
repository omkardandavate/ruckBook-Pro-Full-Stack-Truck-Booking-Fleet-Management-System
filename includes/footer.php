<?php
/**
 * TruckBook Pro - Reusable Footer Component
 */

declare(strict_types=1);
?>
        </div> <!-- End .page-container -->

        <footer id="app-footer" class="mt-auto">
            <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-2">
                <div>
                    &copy; <?= date('Y') ?> <strong><?= APP_NAME ?></strong> — Enterprise Fleet Logistics Management System.
                </div>
                <div class="text-muted small">
                    Version <?= APP_VERSION ?> | Built for High-Performance Fleet Operations
                </div>
            </div>
        </footer>
    </div> <!-- End #main-content -->
</div> <!-- End #app-wrapper -->

<!-- Bootstrap 5.3 Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Chart.js for Visual Reports -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<!-- Application Core Scripts -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
