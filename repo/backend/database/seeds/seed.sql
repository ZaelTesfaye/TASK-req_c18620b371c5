-- FieldOps Service & Environmental Analytics Suite
-- Seed Data: Roles, Stores, Workstations, Users
-- All passwords use salted hashing (seed passwords are for demo only)

SET NAMES utf8mb4;

-- ============================================================
-- Roles (canonical codes and display names)
-- ============================================================
INSERT IGNORE INTO `roles` (`id`, `code`, `name`) VALUES
(1, 'customer', 'Customer'),
(2, 'front_desk', 'Front Desk'),
(3, 'technician', 'Technician'),
(4, 'store_manager', 'Store Manager'),
(5, 'finance', 'Finance'),
(6, 'administrator', 'Administrator');

-- ============================================================
-- Stores
-- ============================================================
INSERT IGNORE INTO `stores` (`id`, `code`, `name`, `timezone`) VALUES
(1, 'STORE-001', 'Downtown Service Center', 'America/New_York'),
(2, 'STORE-002', 'Midtown Service Hub', 'America/Chicago');

-- ============================================================
-- Workstations
-- ============================================================
INSERT IGNORE INTO `workstations` (`id`, `store_id`, `code`, `name`, `active`) VALUES
(1, 1, 'WS-001', 'Front Desk Terminal 1', 1),
(2, 1, 'WS-002', 'Front Desk Terminal 2', 1),
(3, 1, 'WS-003', 'Kiosk Station 1', 1),
(4, 2, 'WS-001', 'Front Desk Terminal 1', 1),
(5, 2, 'WS-002', 'Technician Station 1', 1);

-- ============================================================
-- Users (password for all demo users: "Demo12345678!")
-- Salt: 'fieldops_demo_salt_v1' (demo only)
-- Hash: SHA256(password + salt) - in production, use bcrypt via PHP
-- Actual hash computed: password_hash will be set by application seeder
-- For SQL seed, we use pre-computed bcrypt values
-- ============================================================
INSERT IGNORE INTO `users` (`id`, `username`, `password_hash`, `password_salt`, `status`, `failed_attempts`, `lockout_until`, `default_role_id`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fieldops_demo_salt_v1', 'active', 0, NULL, 6),
(2, 'frontdesk1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fieldops_demo_salt_v1', 'active', 0, NULL, 2),
(3, 'tech1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fieldops_demo_salt_v1', 'active', 0, NULL, 3),
(4, 'manager1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fieldops_demo_salt_v1', 'active', 0, NULL, 4),
(5, 'finance1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fieldops_demo_salt_v1', 'active', 0, NULL, 5),
(6, 'customer1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fieldops_demo_salt_v1', 'active', 0, NULL, 1),
(7, 'tech2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fieldops_demo_salt_v1', 'active', 0, NULL, 3),
(8, 'frontdesk2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fieldops_demo_salt_v1', 'active', 0, NULL, 2);

-- ============================================================
-- User-Role Assignments
-- ============================================================
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 6), -- admin -> Administrator
(2, 2), -- frontdesk1 -> Front Desk
(3, 3), -- tech1 -> Technician
(4, 4), -- manager1 -> Store Manager
(5, 5), -- finance1 -> Finance
(6, 1), -- customer1 -> Customer
(7, 3), -- tech2 -> Technician
(8, 2); -- frontdesk2 -> Front Desk

-- ============================================================
-- User Store/Workstation Bindings
-- ============================================================
INSERT IGNORE INTO `user_store_workstation_bindings` (`user_id`, `store_id`, `workstation_id`, `active`, `assigned_by`) VALUES
(1, 1, 1, 1, 1),  -- admin at Store 1, WS 1
(2, 1, 1, 1, 1),  -- frontdesk1 at Store 1, WS 1
(3, 1, 2, 1, 1),  -- tech1 at Store 1, WS 2
(4, 1, 1, 1, 1),  -- manager1 at Store 1, WS 1
(5, 1, 1, 1, 1),  -- finance1 at Store 1, WS 1
(6, 1, 3, 1, 1),  -- customer1 at Store 1, Kiosk
(7, 2, 5, 1, 1),  -- tech2 at Store 2, WS 2
(8, 2, 4, 1, 1);  -- frontdesk2 at Store 2, WS 1

-- ============================================================
-- Store Zones (for environmental data)
-- ============================================================
INSERT IGNORE INTO `store_zones` (`id`, `store_id`, `zone_code`, `zone_name`) VALUES
(1, 1, 'ZONE-A', 'Main Service Area'),
(2, 1, 'ZONE-B', 'Waiting Room'),
(3, 2, 'ZONE-A', 'Main Service Area');

-- ============================================================
-- Encryption Keys (demo key - in production, generate securely)
-- ============================================================
INSERT IGNORE INTO `encryption_keys` (`key_version`, `key_material_encrypted`, `status`) VALUES
(1, 'base64:ZmllbGRvcHNfZGVtb19lbmNyeXB0aW9uX2tleV92MQ==', 'active');

-- ============================================================
-- Demo Coupons
-- ============================================================
INSERT IGNORE INTO `coupons` (`code`, `store_id`, `title`, `discount_type`, `discount_value`, `min_spend`, `usage_limit_total`, `usage_limit_per_user`, `valid_from`, `valid_to`, `active`) VALUES
('WELCOME10', 1, 'Welcome 10% Off', 'percent', 10.00, 50.00, 100, 1, '2025-01-01 00:00:00', '2027-12-31 23:59:59', 1),
('SAVE5', NULL, 'Save $5', 'fixed', 5.00, 25.00, NULL, 3, '2025-01-01 00:00:00', '2027-12-31 23:59:59', 1);

-- ============================================================
-- Metric Definitions
-- ============================================================
INSERT IGNORE INTO `metric_definitions` (`metric_key`, `numerator_definition`, `denominator_definition`, `aggregation_window`, `active`) VALUES
('transaction_volume', 'count(orders where created_at in range)', '1', 'daily', 1),
('avg_fulfillment_time', 'sum(completed_at - confirmed_at for completed orders)', 'count(completed orders)', 'daily', 1),
('cancellation_rate', 'count(cancelled orders)', 'count(total orders)', 'daily', 1),
('complaint_rate', 'count(complaint-linked completed orders)', 'count(total completed orders)', 'daily', 1),
('activity', 'count(active users in range)', 'count(total enabled users)', 'daily', 1),
('conversion', 'count(confirmed orders)', 'count(created orders)', 'daily', 1),
('retention', 'count(returning customers)', 'count(prior period customers)', 'daily', 1),
('content_quality', 'sum(announcements.quality_score)', 'count(announcements in range)', 'daily', 1),
('zero_result_search_rate', 'count(searches with zero results)', 'count(total searches)', 'daily', 1);

-- ============================================================
-- Formula Versions (comfort index default)
-- ============================================================
INSERT IGNORE INTO `formula_versions` (`formula_key`, `version_no`, `formula_expression`, `threshold_json`, `effective_from`, `created_by`) VALUES
('comfort_index', 1, '0.4 * normalized_temperature + 0.3 * normalized_humidity + 0.3 * normalized_air_quality', '{"comfortable": {"min": 0.7, "max": 1.0}, "moderate": {"min": 0.4, "max": 0.7}, "uncomfortable": {"min": 0.0, "max": 0.4}}', '2025-01-01 00:00:00', 1),
('moving_average', 1, 'AVG(values) OVER (window_size)', '{"window_size": 5}', '2025-01-01 00:00:00', 1),
('rate_of_change', 1, '(current - previous) / previous', '{}', '2025-01-01 00:00:00', 1);

-- ============================================================
-- Sensor Sources (demo)
-- ============================================================
INSERT IGNORE INTO `sensor_sources` (`store_id`, `zone_id`, `source_type`, `source_name`, `active`) VALUES
(1, 1, 'sensor', 'Temperature Sensor A1', 1),
(1, 1, 'sensor', 'Humidity Sensor A1', 1),
(1, 2, 'sensor', 'Temperature Sensor B1', 1),
(2, 3, 'csv', 'Monthly CSV Import', 1);

-- ============================================================
-- Cash Drawers (demo, per store)
-- RbacApiTest and StoreIsolationTest both GET
-- /finance/cash-drawer/daily?date=2025-01-01 and expect 200; without a row
-- for that store+date the controller returns 404 NOT_FOUND and the test
-- fails. Seeding an open drawer here gives those auth/isolation tests a
-- deterministic target without forcing them to create their own drawer
-- (which would couple them to the open-drawer endpoint's behaviour).
-- ============================================================
INSERT IGNORE INTO `cash_drawer_daily` (`store_id`, `business_date`, `opened_by`, `open_amount`, `status`) VALUES
(1, '2025-01-01', 5, 100.00, 'open'),
(2, '2025-01-01', 5, 100.00, 'open');
