-- ========================================================
-- OutagePulse – Crowdsourced Neighborhood Power Cut Reporter
-- Database Schema & Initial Seed Data
-- ========================================================

CREATE DATABASE IF NOT EXISTS `outagepulse_db` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `outagepulse_db`;

-- 1. Outage Reports Table
CREATE TABLE IF NOT EXISTS `outage_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `area_zipcode` VARCHAR(10) NOT NULL,
    `area_name` VARCHAR(100) NOT NULL,
    `status` ENUM('power_out', 'power_restored') NOT NULL DEFAULT 'power_out',
    `issue_type` ENUM('voltage_drop', 'complete_blackout', 'transformer_spark') NOT NULL DEFAULT 'complete_blackout',
    `reported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_zipcode` (`area_zipcode`),
    INDEX `idx_status` (`status`),
    INDEX `idx_reported_at` (`reported_at`),
    INDEX `idx_active_reports` (`status`, `reported_at`, `area_zipcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Subscribed Users Table
CREATE TABLE IF NOT EXISTS `subscribed_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `phone_or_email` VARCHAR(150) NOT NULL,
    `area_zipcode` VARCHAR(10) NOT NULL,
    `alert_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sub_zipcode` (`area_zipcode`),
    UNIQUE KEY `uniq_sub_contact_zip` (`phone_or_email`, `area_zipcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================
-- Seed Data: Simulated Neighborhood Power Outages
-- (Includes active reports within the last 2 hours and restored events)
-- ========================================================

-- Active Critical Blackout in Downtown Metro (ZIP: 94102)
INSERT INTO `outage_reports` (`area_zipcode`, `area_name`, `status`, `issue_type`, `reported_at`, `resolved_at`) VALUES
('94102', 'Downtown Metro', 'power_out', 'complete_blackout', NOW() - INTERVAL 12 MINUTE, NULL),
('94102', 'Downtown Metro', 'power_out', 'complete_blackout', NOW() - INTERVAL 18 MINUTE, NULL),
('94102', 'Downtown Metro', 'power_out', 'transformer_spark', NOW() - INTERVAL 22 MINUTE, NULL),
('94102', 'Downtown Metro', 'power_out', 'complete_blackout', NOW() - INTERVAL 35 MINUTE, NULL),
('94102', 'Downtown Metro', 'power_out', 'voltage_drop', NOW() - INTERVAL 45 MINUTE, NULL),
('94102', 'Downtown Metro', 'power_out', 'complete_blackout', NOW() - INTERVAL 50 MINUTE, NULL);

-- Active Partial Outage in Sunset District (ZIP: 94122)
INSERT INTO `outage_reports` (`area_zipcode`, `area_name`, `status`, `issue_type`, `reported_at`, `resolved_at`) VALUES
('94122', 'Sunset District', 'power_out', 'voltage_drop', NOW() - INTERVAL 25 MINUTE, NULL),
('94122', 'Sunset District', 'power_out', 'voltage_drop', NOW() - INTERVAL 40 MINUTE, NULL);

-- Isolated Spark Hazard in Industrial East (ZIP: 94107)
INSERT INTO `outage_reports` (`area_zipcode`, `area_name`, `status`, `issue_type`, `reported_at`, `resolved_at`) VALUES
('94107', 'Industrial East', 'power_out', 'transformer_spark', NOW() - INTERVAL 15 MINUTE, NULL);

-- Recently Restored Outages (Highland Park - ZIP: 94110)
INSERT INTO `outage_reports` (`area_zipcode`, `area_name`, `status`, `issue_type`, `reported_at`, `resolved_at`) VALUES
('94110', 'Highland Park', 'power_restored', 'complete_blackout', NOW() - INTERVAL 75 MINUTE, NOW() - INTERVAL 10 MINUTE),
('94110', 'Highland Park', 'power_restored', 'complete_blackout', NOW() - INTERVAL 80 MINUTE, NOW() - INTERVAL 10 MINUTE),
('94110', 'Highland Park', 'power_restored', 'voltage_drop', NOW() - INTERVAL 90 MINUTE, NOW() - INTERVAL 10 MINUTE);

-- Normal Area with past resolved report (Marina Heights - ZIP: 94123)
INSERT INTO `outage_reports` (`area_zipcode`, `area_name`, `status`, `issue_type`, `reported_at`, `resolved_at`) VALUES
('94123', 'Marina Heights', 'power_restored', 'voltage_drop', NOW() - INTERVAL 3 HOUR, NOW() - INTERVAL 2 HOUR);

-- Sample Subscribers
INSERT INTO `subscribed_users` (`phone_or_email`, `area_zipcode`, `alert_enabled`, `created_at`) VALUES
('resident.downtown@example.com', '94102', 1, NOW() - INTERVAL 2 DAY),
('+1-555-019-2834', '94122', 1, NOW() - INTERVAL 1 DAY),
('alert.team@highland.org', '94110', 1, NOW() - INTERVAL 5 HOUR);
