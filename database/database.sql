-- ============================================================================
-- TRUCKBOOK PRO - FULL-STACK TRUCK BOOKING & FLEET MANAGEMENT SYSTEM
-- Complete Database Schema & Seed Data
-- Compatible with MySQL 8.0+ and MariaDB 10.4+
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `truckbook_pro` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `truckbook_pro`;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. ROLES TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. USERS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(25) NULL,
  `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
  `avatar` VARCHAR(255) NULL,
  `last_login` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role_id`),
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_status` (`status`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. CUSTOMERS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL UNIQUE,
  `company_name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100) NOT NULL,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `phone` VARCHAR(25) NOT NULL,
  `address` TEXT NOT NULL,
  `city` VARCHAR(100) NOT NULL,
  `state` VARCHAR(100) NOT NULL,
  `postal_code` VARCHAR(20) NOT NULL,
  `tax_gst_number` VARCHAR(50) NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_customers_company` (`company_name`),
  INDEX `idx_customers_status` (`status`),
  CONSTRAINT `fk_customers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. DRIVERS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `drivers`;
CREATE TABLE `drivers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(25) NOT NULL,
  `email` VARCHAR(120) NULL,
  `licence_number` VARCHAR(50) NOT NULL UNIQUE,
  `licence_expiry` DATE NOT NULL,
  `experience_years` INT UNSIGNED NOT NULL DEFAULT 0,
  `address` TEXT NULL,
  `status` ENUM('available', 'on_trip', 'on_leave', 'inactive') NOT NULL DEFAULT 'available',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_drivers_status` (`status`),
  INDEX `idx_drivers_licence` (`licence_number`),
  CONSTRAINT `fk_drivers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. VEHICLES TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `vehicles`;
CREATE TABLE `vehicles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `registration_number` VARCHAR(30) NOT NULL UNIQUE,
  `truck_type` ENUM('flatbed', 'container', 'refrigerated', 'tanker', 'trailer', 'box_truck') NOT NULL,
  `model` VARCHAR(100) NOT NULL,
  `capacity_tonnes` DECIMAL(6,2) NOT NULL,
  `make_year` INT UNSIGNED NOT NULL,
  `insurance_expiry` DATE NOT NULL,
  `permit_expiry` DATE NOT NULL,
  `fitness_expiry` DATE NOT NULL,
  `current_odometer` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('available', 'on_trip', 'maintenance', 'inactive') NOT NULL DEFAULT 'available',
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_vehicles_reg` (`registration_number`),
  INDEX `idx_vehicles_type` (`truck_type`),
  INDEX `idx_vehicles_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. FREIGHT REQUESTS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `freight_requests`;
CREATE TABLE `freight_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_reference` VARCHAR(30) NOT NULL UNIQUE,
  `customer_id` INT UNSIGNED NOT NULL,
  `pickup_address` TEXT NOT NULL,
  `delivery_address` TEXT NOT NULL,
  `pickup_city` VARCHAR(100) NOT NULL,
  `delivery_city` VARCHAR(100) NOT NULL,
  `cargo_description` VARCHAR(255) NOT NULL,
  `cargo_weight_tonnes` DECIMAL(8,2) NOT NULL,
  `preferred_date` DATE NOT NULL,
  `truck_type_required` ENUM('flatbed', 'container', 'refrigerated', 'tanker', 'trailer', 'box_truck') NOT NULL,
  `special_instructions` TEXT NULL,
  `status` ENUM('pending', 'quoted', 'booked', 'cancelled') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_freight_req_customer` (`customer_id`),
  INDEX `idx_freight_req_status` (`status`),
  CONSTRAINT `fk_freight_req_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. QUOTATIONS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `quotations`;
CREATE TABLE `quotations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quotation_number` VARCHAR(30) NOT NULL UNIQUE,
  `freight_request_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `estimated_distance_km` DECIMAL(8,2) NOT NULL,
  `base_rate` DECIMAL(10,2) NOT NULL,
  `fuel_surcharge` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `toll_estimate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `driver_allowance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `tax_rate_percent` DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  `tax_amount` DECIMAL(10,2) NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `valid_until` DATE NOT NULL,
  `status` ENUM('draft', 'sent', 'accepted', 'rejected', 'expired') NOT NULL DEFAULT 'sent',
  `notes` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_quotations_customer` (`customer_id`),
  INDEX `idx_quotations_status` (`status`),
  CONSTRAINT `fk_quotations_req` FOREIGN KEY (`freight_request_id`) REFERENCES `freight_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quotations_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quotations_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. BOOKINGS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `bookings`;
CREATE TABLE `bookings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_number` VARCHAR(30) NOT NULL UNIQUE,
  `quotation_id` INT UNSIGNED NULL,
  `freight_request_id` INT UNSIGNED NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `vehicle_id` INT UNSIGNED NULL,
  `driver_id` INT UNSIGNED NULL,
  `pickup_address` TEXT NOT NULL,
  `delivery_address` TEXT NOT NULL,
  `pickup_city` VARCHAR(100) NOT NULL,
  `delivery_city` VARCHAR(100) NOT NULL,
  `cargo_description` VARCHAR(255) NOT NULL,
  `cargo_weight_tonnes` DECIMAL(8,2) NOT NULL,
  `planned_pickup_date` DATE NOT NULL,
  `planned_delivery_date` DATE NOT NULL,
  `agreed_rate` DECIMAL(10,2) NOT NULL,
  `status` ENUM('pending_assignment', 'confirmed', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending_assignment',
  `cancellation_reason` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_bookings_customer` (`customer_id`),
  INDEX `idx_bookings_vehicle` (`vehicle_id`),
  INDEX `idx_bookings_driver` (`driver_id`),
  INDEX `idx_bookings_status` (`status`),
  CONSTRAINT `fk_bookings_quote` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_request` FOREIGN KEY (`freight_request_id`) REFERENCES `freight_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bookings_veh` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_drv` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. TRIPS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `trips`;
CREATE TABLE `trips` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trip_number` VARCHAR(30) NOT NULL UNIQUE,
  `booking_id` INT UNSIGNED NOT NULL UNIQUE,
  `vehicle_id` INT UNSIGNED NOT NULL,
  `driver_id` INT UNSIGNED NOT NULL,
  `planned_departure` DATETIME NOT NULL,
  `actual_departure` DATETIME NULL,
  `estimated_arrival` DATETIME NOT NULL,
  `actual_arrival` DATETIME NULL,
  `start_odometer` INT UNSIGNED NOT NULL DEFAULT 0,
  `end_odometer` INT UNSIGNED NULL,
  `distance_km` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `fuel_consumed_liters` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `delivery_status` ENUM('scheduled', 'dispatched', 'in_transit', 'arrived', 'unloading', 'delivered', 'cancelled') NOT NULL DEFAULT 'scheduled',
  `proof_of_delivery_path` VARCHAR(255) NULL,
  `receiver_name` VARCHAR(100) NULL,
  `receiver_signature_path` VARCHAR(255) NULL,
  `trip_notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_trips_vehicle` (`vehicle_id`),
  INDEX `idx_trips_driver` (`driver_id`),
  INDEX `idx_trips_status` (`delivery_status`),
  CONSTRAINT `fk_trips_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_trips_veh` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_trips_drv` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. TRIP STATUS HISTORY TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `trip_status_history`;
CREATE TABLE `trip_status_history` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trip_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(50) NOT NULL,
  `location_note` VARCHAR(255) NULL,
  `remarks` TEXT NULL,
  `updated_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_trip_history_trip` (`trip_id`),
  CONSTRAINT `fk_trip_history_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trip_history_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. TRIP EXPENSES TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `trip_expenses`;
CREATE TABLE `trip_expenses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trip_id` INT UNSIGNED NOT NULL,
  `category` ENUM('fuel', 'toll', 'driver_allowance', 'loading_unloading', 'repairs', 'parking', 'other') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `expense_date` DATE NOT NULL,
  `description` TEXT NOT NULL,
  `receipt_reference` VARCHAR(100) NULL,
  `receipt_file_path` VARCHAR(255) NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
  `created_by` INT UNSIGNED NOT NULL,
  `approved_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_expenses_trip` (`trip_id`),
  INDEX `idx_expenses_category` (`category`),
  INDEX `idx_expenses_status` (`status`),
  CONSTRAINT `fk_expenses_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_expenses_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_expenses_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. INVOICES TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(30) NOT NULL UNIQUE,
  `booking_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `tax_rate_percent` DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  `tax_amount` DECIMAL(10,2) NOT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `balance_amount` DECIMAL(10,2) NOT NULL,
  `payment_status` ENUM('unpaid', 'partially_paid', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'unpaid',
  `issued_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `notes` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_invoices_customer` (`customer_id`),
  INDEX `idx_invoices_status` (`payment_status`),
  CONSTRAINT `fk_invoices_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_invoices_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_invoices_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. INVOICE ITEMS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `invoice_items`;
CREATE TABLE `invoice_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT UNSIGNED NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL,
  INDEX `idx_invoice_items_invoice` (`invoice_id`),
  CONSTRAINT `fk_invoice_items_inv` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 14. PAYMENTS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `payment_number` VARCHAR(30) NOT NULL UNIQUE,
  `invoice_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `payment_method` ENUM('bank_transfer', 'credit_card', 'cheque', 'upi', 'cash', 'other') NOT NULL,
  `transaction_reference` VARCHAR(100) NOT NULL,
  `status` ENUM('completed', 'pending', 'failed') NOT NULL DEFAULT 'completed',
  `notes` TEXT NULL,
  `recorded_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_payments_invoice` (`invoice_id`),
  INDEX `idx_payments_customer` (`customer_id`),
  CONSTRAINT `fk_payments_inv` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payments_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payments_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 15. NOTIFICATIONS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(255) NULL,
  `type` ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notifications_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 16. DOCUMENTS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `related_entity_type` ENUM('trip', 'vehicle', 'driver', 'booking', 'customer') NOT NULL,
  `related_entity_id` INT UNSIGNED NOT NULL,
  `document_type` VARCHAR(50) NOT NULL,
  `document_name` VARCHAR(150) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_documents_entity` (`related_entity_type`, `related_entity_id`),
  CONSTRAINT `fk_documents_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 17. AUDIT LOGS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_logs_user` (`user_id`),
  INDEX `idx_audit_logs_action` (`action`),
  INDEX `idx_audit_logs_entity` (`entity_type`, `entity_id`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 18. COMPANY SETTINGS TABLE
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `company_settings`;
CREATE TABLE `company_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NOT NULL,
  `setting_group` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED DATA INSERTION
-- ============================================================================

-- Roles
INSERT INTO `roles` (`id`, `name`, `slug`, `description`) VALUES
(1, 'Super Admin', 'super_admin', 'Complete system control, audit logs, financials, user management and system settings'),
(2, 'Transport Manager', 'transport_manager', 'Full operational control over fleet, drivers, bookings, trips, quotes, and reports'),
(3, 'Staff / Operator', 'operator', 'Day-to-day data entry: freight requests, bookings, expenses, and driver coordination'),
(4, 'Customer', 'customer', 'Clients requesting freight, viewing quotes, confirming bookings, and tracking shipments'),
(5, 'Driver', 'driver', 'Fleet drivers viewing trip instructions, odometer logging, and submitting delivery POD');

-- Users (All default passwords: password123)
-- Hash: $2y$10$nD0szkzT/6fyStcKDtIkTOVHUZpZIX8nEj2IBLSU6rbwa8adb7f3G
INSERT INTO `users` (`id`, `role_id`, `name`, `email`, `password_hash`, `phone`, `status`, `avatar`, `created_at`) VALUES
(1, 1, 'Alex Mercer (Admin)', 'admin@truckbook.com', '$2y$10$nD0szkzT/6fyStcKDtIkTOVHUZpZIX8nEj2IBLSU6rbwa8adb7f3G', '+1 555-0100', 'active', 'assets/images/avatar-admin.png', NOW()),
(2, 2, 'Marcus Vance (Manager)', 'manager@truckbook.com', '$2y$10$nD0szkzT/6fyStcKDtIkTOVHUZpZIX8nEj2IBLSU6rbwa8adb7f3G', '+1 555-0101', 'active', 'assets/images/avatar-manager.png', NOW()),
(3, 3, 'Sarah Connor (Staff)', 'operator@truckbook.com', '$2y$10$nD0szkzT/6fyStcKDtIkTOVHUZpZIX8nEj2IBLSU6rbwa8adb7f3G', '+1 555-0102', 'active', 'assets/images/avatar-staff.png', NOW()),
(4, 4, 'David Sterling (Customer)', 'customer@example.com', '$2y$10$nD0szkzT/6fyStcKDtIkTOVHUZpZIX8nEj2IBLSU6rbwa8adb7f3G', '+1 555-0103', 'active', 'assets/images/avatar-customer.png', NOW()),
(5, 5, 'John Doe (Driver)', 'driver@truckbook.com', '$2y$10$nD0szkzT/6fyStcKDtIkTOVHUZpZIX8nEj2IBLSU6rbwa8adb7f3G', '+1 555-0104', 'active', 'assets/images/avatar-driver.png', NOW());

-- Customers
INSERT INTO `customers` (`id`, `user_id`, `company_name`, `contact_person`, `email`, `phone`, `address`, `city`, `state`, `postal_code`, `tax_gst_number`, `status`) VALUES
(1, 4, 'Sterling Industrial Logistics', 'David Sterling', 'customer@example.com', '+1 555-0103', '742 Evergreen Industrial Corridor', 'Chicago', 'IL', '60601', 'US-GST-892144', 'active'),
(2, NULL, 'Apex Global Supply Chain', 'Jennifer Lopez', 'jennifer@apexsupply.com', '+1 555-0188', '1200 Harbor Boulevard, Suite 400', 'Long Beach', 'CA', '90802', 'US-GST-441209', 'active'),
(3, NULL, 'BlueWave Agro Commodities', 'Robert Chen', 'robert@bluewaveagro.com', '+1 555-0199', '88 Grain Terminal Way', 'Des Moines', 'IA', '50309', 'US-GST-992110', 'active');

-- Drivers
INSERT INTO `drivers` (`id`, `user_id`, `name`, `phone`, `email`, `licence_number`, `licence_expiry`, `experience_years`, `address`, `status`) VALUES
(1, 5, 'John Doe', '+1 555-0104', 'driver@truckbook.com', 'CDL-IL-984421', DATE_ADD(CURDATE(), INTERVAL 2 YEAR), 8, '44 Truckers Lane, Chicago, IL', 'on_trip'),
(2, NULL, 'Carlos Rodriguez', '+1 555-0211', 'carlos.r@truckbook.com', 'CDL-IN-552190', DATE_ADD(CURDATE(), INTERVAL 18 MONTH), 5, '120 Interstate Ave, Indianapolis, IN', 'available'),
(3, NULL, 'Michael Chang', '+1 555-0222', 'mchang@truckbook.com', 'CDL-OH-771234', DATE_ADD(CURDATE(), INTERVAL 3 YEAR), 12, '90 Highway Plaza, Columbus, OH', 'available'),
(4, NULL, 'Vikram Patel', '+1 555-0233', 'vikram.p@truckbook.com', 'CDL-MI-110293', DATE_SUB(CURDATE(), INTERVAL 10 DAY), 4, '300 Motor Parkway, Detroit, MI', 'inactive');

-- Vehicles
INSERT INTO `vehicles` (`id`, `registration_number`, `truck_type`, `model`, `capacity_tonnes`, `make_year`, `insurance_expiry`, `permit_expiry`, `fitness_expiry`, `current_odometer`, `status`, `notes`) VALUES
(1, 'TRK-IL-1001', 'container', 'Volvo FH16 Globetrotter 540', 25.00, 2022, DATE_ADD(CURDATE(), INTERVAL 10 MONTH), DATE_ADD(CURDATE(), INTERVAL 8 MONTH), DATE_ADD(CURDATE(), INTERVAL 11 MONTH), 142500, 'on_trip', 'Primary heavy container carrier. In peak condition.'),
(2, 'TRK-IL-1002', 'flatbed', 'Freightliner Cascadia 126', 22.00, 2021, DATE_ADD(CURDATE(), INTERVAL 6 MONTH), DATE_ADD(CURDATE(), INTERVAL 7 MONTH), DATE_ADD(CURDATE(), INTERVAL 9 MONTH), 198200, 'available', 'Equipped with heavy tie-down straps and steel racks.'),
(3, 'TRK-IN-2001', 'refrigerated', 'Kenworth T680 ThermoKing', 20.00, 2023, DATE_ADD(CURDATE(), INTERVAL 11 MONTH), DATE_ADD(CURDATE(), INTERVAL 12 MONTH), DATE_ADD(CURDATE(), INTERVAL 11 MONTH), 78400, 'available', 'Temperature control -25C to +20C certified for pharmaceuticals & frozen foods.'),
(4, 'TRK-OH-3001', 'box_truck', 'Isuzu FTR Heavy Delivery', 12.00, 2020, DATE_ADD(CURDATE(), INTERVAL 4 MONTH), DATE_ADD(CURDATE(), INTERVAL 5 MONTH), DATE_ADD(CURDATE(), INTERVAL 6 MONTH), 230100, 'available', 'Ideal for regional distribution and palletized retail cargo.'),
(5, 'TRK-MI-4001', 'trailer', 'Peterbilt 579 UltraLoft', 30.00, 2019, DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 2 MONTH), DATE_ADD(CURDATE(), INTERVAL 3 MONTH), 312000, 'maintenance', 'Insurance renewal pending inspection. Scheduled brake pad replacement.');

-- Freight Requests
INSERT INTO `freight_requests` (`id`, `request_reference`, `customer_id`, `pickup_address`, `delivery_address`, `pickup_city`, `delivery_city`, `cargo_description`, `cargo_weight_tonnes`, `preferred_date`, `truck_type_required`, `special_instructions`, `status`) VALUES
(1, 'REQ-2026-0001', 1, 'Chicago South Rail Logistics Depot, Gate 4', 'Detroit Assembly Plant 3, Warren Ave', 'Chicago', 'Detroit', 'Precision Automotive Stamping Dies & Tooling', 18.50, CURDATE(), 'container', 'Fragile electronic sensors inside crates. Keep upright.', 'booked'),
(2, 'REQ-2026-0002', 2, 'Long Beach Harbor Pier G Berth 23', 'Salt Lake City Regional Fulfillment Hub', 'Long Beach', 'Salt Lake City', 'Imported Consumer Electronics & Lithium Powerbanks', 21.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'container', 'Hazardous Materials Class 9 (Lithium-ion). Fire extinguisher required.', 'quoted'),
(3, 'REQ-2026-0003', 3, 'Cedar Rapids Agro Grain Silo Complex 2', 'Louisville Distillery Loading Dock 9', 'Cedar Rapids', 'Louisville', 'Organic Malted Barley & Wheat Grain (Moisture Sensitive)', 16.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'flatbed', 'Tarpaulin must be completely weatherproof and inspected.', 'pending');

-- Quotations
INSERT INTO `quotations` (`id`, `quotation_number`, `freight_request_id`, `customer_id`, `estimated_distance_km`, `base_rate`, `fuel_surcharge`, `toll_estimate`, `driver_allowance`, `subtotal`, `tax_rate_percent`, `tax_amount`, `total_amount`, `valid_until`, `status`, `notes`, `created_by`) VALUES
(1, 'QUO-2026-0001', 1, 1, 460.00, 1840.00, 230.00, 85.00, 150.00, 2305.00, 18.00, 414.90, 2719.90, DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'accepted', 'Quotation approved by Customer Sterling Logistics via portal.', 2),
(2, 'QUO-2026-0002', 2, 2, 1120.00, 4480.00, 560.00, 140.00, 300.00, 5480.00, 18.00, 986.40, 6466.40, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'sent', 'Standard expedited tariff applied for hazardous cargo handling.', 2);

-- Bookings
INSERT INTO `bookings` (`id`, `booking_number`, `quotation_id`, `freight_request_id`, `customer_id`, `vehicle_id`, `driver_id`, `pickup_address`, `delivery_address`, `pickup_city`, `delivery_city`, `cargo_description`, `cargo_weight_tonnes`, `planned_pickup_date`, `planned_delivery_date`, `agreed_rate`, `status`, `created_by`) VALUES
(1, 'BKG-2026-0001', 1, 1, 1, 1, 1, 'Chicago South Rail Logistics Depot, Gate 4', 'Detroit Assembly Plant 3, Warren Ave', 'Chicago', 'Detroit', 'Precision Automotive Stamping Dies & Tooling', 18.50, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 2719.90, 'in_progress', 2);

-- Trips
INSERT INTO `trips` (`id`, `trip_number`, `booking_id`, `vehicle_id`, `driver_id`, `planned_departure`, `actual_departure`, `estimated_arrival`, `actual_arrival`, `start_odometer`, `end_odometer`, `distance_km`, `fuel_consumed_liters`, `delivery_status`, `trip_notes`) VALUES
(1, 'TRP-2026-0001', 1, 1, 1, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 8 HOUR), NULL, 142500, NULL, 460.00, 0.00, 'in_transit', 'Departed Chicago terminal on schedule. Highway weather clear.');

-- Trip Status History
INSERT INTO `trip_status_history` (`id`, `trip_id`, `status`, `location_note`, `remarks`, `updated_by`) VALUES
(1, 1, 'scheduled', 'Chicago Fleet Yard', 'Trip scheduled and driver John Doe assigned.', 2),
(2, 1, 'dispatched', 'Chicago South Gate', 'Vehicle TRK-IL-1001 inspected and loaded.', 3),
(3, 1, 'in_transit', 'I-94 East near Kalamazoo, MI', 'Cruising at 62 mph. Cargo latches secure.', 5);

-- Trip Expenses
INSERT INTO `trip_expenses` (`id`, `trip_id`, `category`, `amount`, `expense_date`, `description`, `receipt_reference`, `status`, `created_by`, `approved_by`) VALUES
(1, 1, 'fuel', 280.00, CURDATE(), 'Diesel fuel 85 Gallons at Shell Truck Stop I-94 Kalamazoo', 'RCP-SH-9921', 'approved', 5, 2),
(2, 1, 'toll', 45.50, CURDATE(), 'Indiana Toll Road Electronic Transponder Plaza 3', 'RCP-IN-4402', 'approved', 5, 2);

-- Invoices
INSERT INTO `invoices` (`id`, `invoice_number`, `booking_id`, `customer_id`, `subtotal`, `tax_rate_percent`, `tax_amount`, `discount_amount`, `total_amount`, `paid_amount`, `balance_amount`, `payment_status`, `issued_date`, `due_date`, `notes`, `created_by`) VALUES
(1, 'INV-2026-0001', 1, 1, 2305.00, 18.00, 414.90, 0.00, 2719.90, 1000.00, 1719.90, 'partially_paid', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 'Net 15 days terms. Partial advance payment received upon loading.', 2);

-- Invoice Items
INSERT INTO `invoice_items` (`id`, `invoice_id`, `description`, `quantity`, `unit_price`, `total_amount`) VALUES
(1, 1, 'Freight Haulage: Chicago, IL to Detroit, MI (460 KM)', 1.00, 1840.00, 1840.00),
(2, 1, 'Standard Fuel Surcharge (12.5%)', 1.00, 230.00, 230.00),
(3, 1, 'Interstate Highway Tolls & Turnpikes', 1.00, 85.00, 85.00),
(4, 1, 'Overnight Driver Allowance & Staging', 1.00, 150.00, 150.00);

-- Payments
INSERT INTO `payments` (`id`, `payment_number`, `invoice_id`, `customer_id`, `amount`, `payment_date`, `payment_method`, `transaction_reference`, `status`, `notes`, `recorded_by`) VALUES
(1, 'PAY-2026-0001', 1, 1, 1000.00, CURDATE(), 'bank_transfer', 'ACH-WIRE-88912401', 'completed', 'Advance freight deposit received via Chase Business ACH.', 2);

-- Notifications
INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `link`, `type`, `is_read`, `created_at`) VALUES
(1, 1, 'System Initialized', 'TruckBook Pro database, security rules, and initial fleet operational data configured.', 'dashboard.php', 'success', 0, NOW()),
(2, 2, 'New Booking In Transit', 'Trip TRP-2026-0001 is now In Transit heading towards Detroit, MI.', 'modules/trips/view.php?id=1', 'info', 0, NOW()),
(3, 4, 'Invoice INV-2026-0001 Issued', 'Your invoice for Chicago to Detroit freight has been generated with a partial payment balance of $1,719.90.', 'modules/invoices/view.php?id=1', 'warning', 0, NOW()),
(4, 5, 'Trip Assignment Dispatched', 'You have been assigned to Trip TRP-2026-0001. Please monitor departure and odometer.', 'modules/trips/view.php?id=1', 'info', 0, NOW());

-- Audit Logs
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'system_seed', 'system', 1, NULL, JSON_OBJECT('status', 'initialized', 'version', '1.0.0'), '127.0.0.1', 'Console/Setup Script', NOW()),
(2, 2, 'create_booking', 'booking', 1, NULL, JSON_OBJECT('booking_number', 'BKG-2026-0001', 'agreed_rate', 2719.90), '127.0.0.1', 'Mozilla/5.0', NOW());

-- Company Settings
INSERT INTO `company_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('company_name', 'TruckBook Pro Logistics Inc.', 'general', 'Registered company business legal name'),
('company_email', 'support@truckbook.com', 'general', 'Customer and operational support contact email'),
('company_phone', '+1 (800) 555-TRUCK', 'general', 'Central dispatch hotline'),
('company_address', '100 North Riverside Plaza, Suite 2400, Chicago, IL 60606', 'general', 'Headquarters corporate address'),
('currency_symbol', '$', 'financial', 'Display currency symbol ($ / INR / EUR)'),
('currency_code', 'USD', 'financial', 'ISO 4217 Currency Code'),
('default_tax_rate', '18.00', 'financial', 'Default sales / GST / VAT tax percentage'),
('base_rate_per_km', '4.00', 'quotations', 'Base pricing rate charged per kilometer'),
('base_rate_per_tonne', '15.00', 'quotations', 'Base surcharge per metric tonne of cargo'),
('fuel_surcharge_percent', '10.00', 'quotations', 'Default fuel surcharge percentage on base rate'),
('driver_daily_allowance', '150.00', 'quotations', 'Standard per-day driver lodging and meal allowance'),
('booking_prefix', 'BKG-', 'system', 'Prefix for newly generated booking references'),
('trip_prefix', 'TRP-', 'system', 'Prefix for newly dispatched trip identifiers'),
('invoice_prefix', 'INV-', 'system', 'Prefix for commercial billing invoices'),
('quote_prefix', 'QUO-', 'system', 'Prefix for freight estimates and quotes'),
('payment_prefix', 'PAY-', 'system', 'Prefix for recorded payment receipts'),
('invoice_due_days', '15', 'financial', 'Standard net payment terms in days'),
('max_login_attempts', '5', 'security', 'Number of failed logins before temporary throttling');
