-- FieldOps Service & Environmental Analytics Suite
-- Database Migration - All Tables
-- MySQL 8.0+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- Core Identity and Access
-- ============================================================

CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `password_salt` VARCHAR(64) NOT NULL,
    `display_name` VARCHAR(200) NULL DEFAULT NULL,
    `status` ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
    `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `lockout_until` DATETIME NULL DEFAULT NULL,
    `default_role_id` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`default_role_id`) REFERENCES `roles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_role` (`user_id`, `role_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stores` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(200) NOT NULL,
    `timezone` VARCHAR(100) NOT NULL DEFAULT 'America/New_York',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `workstations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_store_ws_code` (`store_id`, `code`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_store_workstation_bindings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `store_id` INT UNSIGNED NOT NULL,
    `workstation_id` INT UNSIGNED NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `effective_from` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `effective_to` DATETIME NULL DEFAULT NULL,
    `assigned_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`workstation_id`) REFERENCES `workstations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL UNIQUE,
    `store_id` INT UNSIGNED NOT NULL,
    `workstation_id` INT UNSIGNED NOT NULL,
    `login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    `logout_at` DATETIME NULL DEFAULT NULL,
    `source_type` VARCHAR(50) NOT NULL DEFAULT 'web',
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`workstation_id`) REFERENCES `workstations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shift_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `role_code` VARCHAR(50) NOT NULL,
    `store_id` INT UNSIGNED NOT NULL,
    `workstation_id` INT UNSIGNED NOT NULL,
    `shift_start_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `shift_end_at` DATETIME NULL DEFAULT NULL,
    `opened_by` INT UNSIGNED NOT NULL,
    `closed_by` INT UNSIGNED NULL DEFAULT NULL,
    `close_reason` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`workstation_id`) REFERENCES `workstations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`opened_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Orders and Fulfillment
-- ============================================================

CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(50) NOT NULL UNIQUE,
    `store_id` INT UNSIGNED NOT NULL,
    `workstation_id` INT UNSIGNED NOT NULL,
    `channel` ENUM('kiosk','front_desk') NOT NULL DEFAULT 'front_desk',
    `status` ENUM('draft','confirmed','assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft',
    `customer_name` VARCHAR(200) NOT NULL,
    `customer_phone_enc` TEXT NULL,
    `complaint_flag` TINYINT(1) NOT NULL DEFAULT 0,
    `complaint_reason_code` VARCHAR(100) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `assigned_technician_id` INT UNSIGNED NULL DEFAULT NULL,
    `confirmed_at` DATETIME NULL DEFAULT NULL,
    `completed_at` DATETIME NULL DEFAULT NULL,
    `cancelled_at` DATETIME NULL DEFAULT NULL,
    `cancellation_reason` TEXT NULL DEFAULT NULL,
    `cancellation_by` INT UNSIGNED NULL DEFAULT NULL,
    `subtotal_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `amount_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `invoice_requested` TINYINT(1) NOT NULL DEFAULT 0,
    `invoice_taxpayer_id_enc` TEXT NULL DEFAULT NULL,
    `invoice_entity_name` VARCHAR(300) NULL DEFAULT NULL,
    `invoice_identifier_enc` TEXT NULL DEFAULT NULL,
    `receipt_no` VARCHAR(50) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_orders_store_status` (`store_id`, `status`, `created_at`),
    INDEX `idx_orders_order_no` (`order_no`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`workstation_id`) REFERENCES `workstations`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`assigned_technician_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`cancellation_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `service_code` VARCHAR(100) NOT NULL,
    `service_name` VARCHAR(300) NOT NULL,
    `qty` INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `line_subtotal` DECIMAL(12,2) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_status_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `from_status` VARCHAR(50) NULL,
    `to_status` VARCHAR(50) NOT NULL,
    `changed_by` INT UNSIGNED NOT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `note` TEXT NULL DEFAULT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_work_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `technician_id` INT UNSIGNED NOT NULL,
    `note` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`technician_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `technician_id` INT UNSIGNED NOT NULL,
    `assigned_by` INT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `unassigned_at` DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`technician_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Coupons and Pricing
-- ============================================================

CREATE TABLE IF NOT EXISTS `coupons` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL UNIQUE,
    `store_id` INT UNSIGNED NULL DEFAULT NULL,
    `title` VARCHAR(300) NOT NULL,
    `discount_type` ENUM('fixed','percent') NOT NULL,
    `discount_value` DECIMAL(12,2) NOT NULL,
    `min_spend` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `usage_limit_total` INT UNSIGNED NULL DEFAULT NULL,
    `usage_limit_per_user` INT UNSIGNED NULL DEFAULT NULL,
    `valid_from` DATETIME NOT NULL,
    `valid_to` DATETIME NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `coupon_id` INT UNSIGNED NOT NULL,
    `order_id` INT UNSIGNED NOT NULL,
    `redeemed_by` INT UNSIGNED NOT NULL,
    `redeemed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `rejection_reason` VARCHAR(500) NULL DEFAULT NULL,
    FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`redeemed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Payments and Finance
-- ============================================================

CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `tender_type` ENUM('cash','card_present_recorded','house_account') NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
    `recorded_by` INT UNSIGNED NOT NULL,
    `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reference_note` TEXT NULL DEFAULT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `refunds` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `refund_no` VARCHAR(50) NOT NULL UNIQUE,
    `order_id` INT UNSIGNED NOT NULL,
    `original_payment_id` INT UNSIGNED NOT NULL,
    `refund_type` ENUM('full','partial') NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('pending','approved','processed','rejected') NOT NULL DEFAULT 'pending',
    `initiated_by` INT UNSIGNED NOT NULL,
    `approved_by` INT UNSIGNED NULL DEFAULT NULL,
    `processed_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`original_payment_id`) REFERENCES `payments`(`id`),
    FOREIGN KEY (`initiated_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cash_drawer_daily` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL,
    `business_date` DATE NOT NULL,
    `opened_by` INT UNSIGNED NOT NULL,
    `closed_by` INT UNSIGNED NULL DEFAULT NULL,
    `open_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `expected_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `counted_total` DECIMAL(12,2) NULL DEFAULT NULL,
    `variance` DECIMAL(12,2) NULL DEFAULT NULL,
    `discrepancy_flag` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('open','closed','reopened') NOT NULL DEFAULT 'open',
    `closed_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_store_date` (`store_id`, `business_date`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`opened_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`closed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reconciliation_actions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `cash_drawer_daily_id` INT UNSIGNED NOT NULL,
    `action_type` ENUM('close','reopen') NOT NULL,
    `reason` TEXT NOT NULL,
    `acted_by` INT UNSIGNED NOT NULL,
    `acted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`cash_drawer_daily_id`) REFERENCES `cash_drawer_daily`(`id`),
    FOREIGN KEY (`acted_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reconciliation_statements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `cash_drawer_daily_id` INT UNSIGNED NOT NULL,
    `store_id` INT UNSIGNED NOT NULL,
    `business_date` DATE NOT NULL,
    `expected_total` DECIMAL(12,2) NOT NULL,
    `counted_total` DECIMAL(12,2) NOT NULL,
    `variance` DECIMAL(12,2) NOT NULL,
    `discrepancy_flag` TINYINT(1) NOT NULL DEFAULT 0,
    `statement_json` JSON NOT NULL,
    `generated_by` INT UNSIGNED NOT NULL,
    `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`cash_drawer_daily_id`) REFERENCES `cash_drawer_daily`(`id`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`generated_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Announcements, Events, Experiments
-- ============================================================

CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(500) NOT NULL,
    `body` TEXT NOT NULL,
    `category` VARCHAR(100) NULL DEFAULT NULL,
    `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `store_id` INT UNSIGNED NULL DEFAULT NULL,
    `published` TINYINT(1) NOT NULL DEFAULT 0,
    `quality_score` DECIMAL(5,2) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `event_key` VARCHAR(100) NOT NULL UNIQUE,
    `name` VARCHAR(300) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `category` VARCHAR(100) NULL DEFAULT NULL,
    `definition_json` JSON NULL DEFAULT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `experiments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `name` VARCHAR(300) NOT NULL,
    `status` ENUM('draft','running','stopped','completed') NOT NULL DEFAULT 'draft',
    `start_at` DATETIME NULL DEFAULT NULL,
    `end_at` DATETIME NULL DEFAULT NULL,
    `holdout_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `randomization_unit` ENUM('user','session') NOT NULL DEFAULT 'user',
    `created_by` INT UNSIGNED NOT NULL,
    `stopped_by` INT UNSIGNED NULL DEFAULT NULL,
    `stopped_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`stopped_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `experiment_variants` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `experiment_id` INT UNSIGNED NOT NULL,
    `variant_key` VARCHAR(100) NOT NULL,
    `ui_copy_json` JSON NULL DEFAULT NULL,
    `coupon_presentation_json` JSON NULL DEFAULT NULL,
    `traffic_percent` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_experiment_variant` (`experiment_id`, `variant_key`),
    FOREIGN KEY (`experiment_id`) REFERENCES `experiments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `experiment_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `experiment_id` INT UNSIGNED NOT NULL,
    `sticky_key` VARCHAR(255) NOT NULL,
    `assigned_variant` VARCHAR(100) NULL DEFAULT NULL,
    `is_holdout` TINYINT(1) NOT NULL DEFAULT 0,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_experiment_sticky` (`experiment_id`, `sticky_key`),
    FOREIGN KEY (`experiment_id`) REFERENCES `experiments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Metrics and Search Analytics
-- ============================================================

CREATE TABLE IF NOT EXISTS `metric_definitions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `metric_key` VARCHAR(100) NOT NULL UNIQUE,
    `formula_version_id` INT UNSIGNED NULL DEFAULT NULL,
    `numerator_definition` TEXT NOT NULL,
    `denominator_definition` TEXT NOT NULL,
    `aggregation_window` VARCHAR(50) NOT NULL DEFAULT 'daily',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `metric_points` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `metric_key` VARCHAR(100) NOT NULL,
    `store_id` INT UNSIGNED NOT NULL,
    `period_start` DATETIME NOT NULL,
    `period_end` DATETIME NOT NULL,
    `metric_value` DECIMAL(15,6) NOT NULL,
    `numerator_value` DECIMAL(15,6) NULL DEFAULT NULL,
    `denominator_value` DECIMAL(15,6) NULL DEFAULT NULL,
    `computed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_metric_points_key_store` (`metric_key`, `store_id`, `period_start`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `search_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `role_code` VARCHAR(50) NOT NULL,
    `store_id` INT UNSIGNED NOT NULL,
    `workstation_id` INT UNSIGNED NOT NULL,
    `query_text` VARCHAR(500) NOT NULL,
    `target_domain` ENUM('order','catalog') NOT NULL,
    `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_search_logs_domain` (`target_domain`, `created_at`, `result_count`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`workstation_id`) REFERENCES `workstations`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `event_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT UNSIGNED NULL DEFAULT NULL,
    `event_key` VARCHAR(100) NOT NULL,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `role_code` VARCHAR(50) NOT NULL,
    `store_id` INT UNSIGNED NOT NULL,
    `workstation_id` INT UNSIGNED NOT NULL,
    `properties_json` JSON NULL DEFAULT NULL,
    `session_key` VARCHAR(128) NULL DEFAULT NULL,
    `payload_json` JSON NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`workstation_id`) REFERENCES `workstations`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Environmental Ingestion and Derived Analytics
-- ============================================================

CREATE TABLE IF NOT EXISTS `store_zones` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL,
    `zone_code` VARCHAR(50) NOT NULL,
    `zone_name` VARCHAR(200) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_store_zone` (`store_id`, `zone_code`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sensor_sources` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL,
    `zone_id` INT UNSIGNED NULL DEFAULT NULL,
    `source_type` ENUM('sensor','csv') NOT NULL,
    `source_name` VARCHAR(300) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`zone_id`) REFERENCES `store_zones`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sensor_raw_records` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `source_id` INT UNSIGNED NOT NULL,
    `zone_id` INT UNSIGNED NULL DEFAULT NULL,
    `metric_type` VARCHAR(100) NOT NULL,
    `metric_value` DECIMAL(15,6) NOT NULL,
    `observed_at` DATETIME NOT NULL,
    `ingested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `batch_id` INT UNSIGNED NULL DEFAULT NULL,
    INDEX `idx_sensor_raw_source` (`source_id`, `observed_at`),
    FOREIGN KEY (`source_id`) REFERENCES `sensor_sources`(`id`),
    FOREIGN KEY (`zone_id`) REFERENCES `store_zones`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sensor_fusion_records` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL,
    `zone_id` INT UNSIGNED NULL DEFAULT NULL,
    `bucket_start` DATETIME NOT NULL,
    `metric_type` VARCHAR(100) NOT NULL,
    `fused_value` DECIMAL(15,6) NOT NULL,
    `source_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `fusion_method` VARCHAR(100) NOT NULL DEFAULT 'weighted_median',
    `source_priority_json` JSON NULL DEFAULT NULL,
    `raw_refs_json` JSON NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`zone_id`) REFERENCES `store_zones`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sensor_aligned_buckets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL,
    `zone_id` INT UNSIGNED NULL DEFAULT NULL,
    `bucket_start` DATETIME NOT NULL,
    `completeness_ratio` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    `consistency_score` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    `alignment_score` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    `confidence_label` ENUM('High','Medium','Low') NOT NULL DEFAULT 'Low',
    `confidence_score` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_aligned_buckets` (`store_id`, `zone_id`, `bucket_start`),
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`zone_id`) REFERENCES `store_zones`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `formula_versions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `formula_key` VARCHAR(100) NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `formula_expression` TEXT NOT NULL,
    `threshold_json` JSON NULL DEFAULT NULL,
    `effective_from` DATETIME NOT NULL,
    `effective_to` DATETIME NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_formula_version` (`formula_key`, `version_no`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `derived_metrics` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL,
    `zone_id` INT UNSIGNED NULL DEFAULT NULL,
    `bucket_start` DATETIME NOT NULL,
    `metric_key` VARCHAR(100) NOT NULL,
    `metric_value` DECIMAL(15,6) NOT NULL,
    `formula_version_id` INT UNSIGNED NOT NULL,
    `lineage_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`),
    FOREIGN KEY (`zone_id`) REFERENCES `store_zones`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`formula_version_id`) REFERENCES `formula_versions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `derived_lineage` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `derived_metric_id` INT UNSIGNED NOT NULL,
    `raw_record_refs_json` JSON NOT NULL,
    `transformation_steps_json` JSON NOT NULL,
    `formula_version_id` INT UNSIGNED NOT NULL,
    `reproducibility_hash` VARCHAR(128) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`derived_metric_id`) REFERENCES `derived_metrics`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`formula_version_id`) REFERENCES `formula_versions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Data Cleansing and Governance
-- ============================================================

CREATE TABLE IF NOT EXISTS `cleansing_batches` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `batch_no` VARCHAR(50) NOT NULL UNIQUE,
    `source_name` VARCHAR(300) NOT NULL,
    `dataset_profile` ENUM('customer_entered','partner_provided') NOT NULL DEFAULT 'customer_entered',
    `status` ENUM('pending_review','approved','rejected','rolled_back') NOT NULL DEFAULT 'pending_review',
    `store_id` INT UNSIGNED NOT NULL,
    `submitted_by` INT UNSIGNED NOT NULL,
    `reviewed_by` INT UNSIGNED NULL DEFAULT NULL,
    `reviewed_at` DATETIME NULL DEFAULT NULL,
    `rollback_by` INT UNSIGNED NULL DEFAULT NULL,
    `rollback_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cleansing_batches_status` (`status`, `submitted_by`, `reviewed_at`),
    FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`rollback_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cleansing_raw_rows` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT UNSIGNED NOT NULL,
    `raw_payload_json` JSON NOT NULL,
    `row_no` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`batch_id`) REFERENCES `cleansing_batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cleansing_results` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT UNSIGNED NOT NULL,
    `raw_row_id` INT UNSIGNED NOT NULL,
    `normalized_job_title` VARCHAR(500) NULL DEFAULT NULL,
    `normalized_company` VARCHAR(500) NULL DEFAULT NULL,
    `normalized_city` VARCHAR(300) NULL DEFAULT NULL,
    `normalized_salary` VARCHAR(200) NULL DEFAULT NULL,
    `normalized_education` VARCHAR(500) NULL DEFAULT NULL,
    `normalized_experience` VARCHAR(500) NULL DEFAULT NULL,
    `dedupe_key` VARCHAR(255) NULL DEFAULT NULL,
    `alignment_confidence` DECIMAL(5,4) NULL DEFAULT NULL,
    `review_required_flag` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('proposed','approved','rejected') NOT NULL DEFAULT 'proposed',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`batch_id`) REFERENCES `cleansing_batches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`raw_row_id`) REFERENCES `cleansing_raw_rows`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cleansing_change_journal` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `before_json` JSON NULL DEFAULT NULL,
    `after_json` JSON NOT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`batch_id`) REFERENCES `cleansing_batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `manual_review_queue` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT UNSIGNED NOT NULL,
    `row_id` INT UNSIGNED NOT NULL,
    `reason_code` VARCHAR(100) NOT NULL,
    `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_by` INT UNSIGNED NULL DEFAULT NULL,
    `resolved_at` DATETIME NULL DEFAULT NULL,
    `resolution_note` TEXT NULL DEFAULT NULL,
    FOREIGN KEY (`batch_id`) REFERENCES `cleansing_batches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`row_id`) REFERENCES `cleansing_raw_rows`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Audit and Security (Immutable - No UPDATE/DELETE allowed by app)
-- ============================================================

CREATE TABLE IF NOT EXISTS `operation_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `actor_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `actor_role_code` VARCHAR(50) NOT NULL,
    `store_id` INT UNSIGNED NOT NULL,
    `workstation_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(200) NOT NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `entity_id` VARCHAR(100) NULL DEFAULT NULL,
    `before_json` JSON NULL DEFAULT NULL,
    `after_json` JSON NULL DEFAULT NULL,
    `request_id` VARCHAR(64) NOT NULL,
    `ip` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_oplog_created` (`created_at`),
    INDEX `idx_oplog_action` (`action`),
    INDEX `idx_oplog_actor` (`actor_user_id`),
    INDEX `idx_oplog_store` (`store_id`),
    INDEX `idx_oplog_workstation` (`workstation_id`),
    INDEX `idx_oplog_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `encryption_keys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `key_version` INT UNSIGNED NOT NULL UNIQUE,
    `key_material_encrypted` TEXT NOT NULL,
    `status` ENUM('active','retired') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `retired_at` DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `security_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(100) NOT NULL,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `details_json` JSON NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Audit Immutability Triggers
-- ============================================================
-- Application code already refuses to expose UPDATE/DELETE routes for the
-- audit tables, but belt-and-suspenders: a UPDATE/DELETE issued directly
-- against `operation_logs` or `security_events` (via SQL client, ORM, or a
-- future bug) is rejected at the DB layer via SIGNAL. INSERT is
-- unaffected — audit rows remain append-only.

DROP TRIGGER IF EXISTS `trg_operation_logs_no_update`;
DROP TRIGGER IF EXISTS `trg_operation_logs_no_delete`;
DROP TRIGGER IF EXISTS `trg_security_events_no_update`;
DROP TRIGGER IF EXISTS `trg_security_events_no_delete`;

DELIMITER //

CREATE TRIGGER `trg_operation_logs_no_update`
BEFORE UPDATE ON `operation_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'operation_logs is append-only; UPDATE is not permitted';
END//

CREATE TRIGGER `trg_operation_logs_no_delete`
BEFORE DELETE ON `operation_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'operation_logs is append-only; DELETE is not permitted';
END//

CREATE TRIGGER `trg_security_events_no_update`
BEFORE UPDATE ON `security_events`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'security_events is append-only; UPDATE is not permitted';
END//

CREATE TRIGGER `trg_security_events_no_delete`
BEFORE DELETE ON `security_events`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'security_events is append-only; DELETE is not permitted';
END//

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
