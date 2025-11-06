-- ====================================================================
-- YooNet Quest System - Complete Database Setup (Consolidated)
-- ====================================================================
-- This file contains the complete database schema for the YooNet Quest System
-- Execute this entire file to set up or update your database
-- 
-- IMPORTANT: This will create tables if they don't exist and add missing columns
-- It's safe to run on existing databases - it won't delete existing data
-- Date: October 9, 2025
-- ====================================================================

-- Set SQL mode and character set
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- Set character set
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ====================================================================
-- 1. DATABASE CREATION (if needed)
-- ====================================================================

CREATE DATABASE IF NOT EXISTS `yoonet_quest` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `yoonet_quest`;

-- ====================================================================
-- 2. CORE USER MANAGEMENT TABLES
-- ====================================================================

-- Users table (main user accounts)
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('skill_associate','quest_lead','admin','quest_taker','hybrid','quest_giver','participant','contributor') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'skill_associate',
  `job_position` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profile_completed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_profile_completed` (`profile_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add missing columns to users table if they don't exist
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `job_position` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `profile_completed` tinyint(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Add split name columns for compatibility (idempotent). These are nullable and will be
-- populated from `full_name` where possible. Keeping `full_name` as the canonical column.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `last_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `first_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `middle_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- Try to populate split name columns from `full_name` when full_name follows the
-- convention 'Last, First Middle'. This update is conservative and only runs where
-- the split columns are empty and full_name contains a comma.
UPDATE `users` u
SET u.`last_name` = TRIM(SUBSTRING_INDEX(u.`full_name`, ',', 1)),
    u.`first_name` = TRIM(SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(u.`full_name`, ',', -1)), ' ', 1)),
    u.`middle_name` = NULLIF(TRIM(SUBSTRING(TRIM(SUBSTRING_INDEX(u.`full_name`, ',', -1)), CHAR_LENGTH(TRIM(SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(u.`full_name`, ',', -1)), ' ', 1))) + 2)), '')
WHERE (u.`last_name` IS NULL OR u.`last_name` = '')
  AND (u.`first_name` IS NULL OR u.`first_name` = '')
  AND (u.`full_name` LIKE '%,%');

-- ------------------------------------------------------------------
-- PREVIEW: Show current vs computed normalized full_name (read-only)
-- This SELECT is safe to run and helps colleagues review how names will be
-- normalized before the UPDATE below is applied. It will output the first
-- 200 users with their current stored full_name and the computed target.
-- ------------------------------------------------------------------
SELECT
  id,
  employee_id,
  full_name AS current_full_name,
  TRIM(last_name) AS last_name,
  TRIM(first_name) AS first_name,
  TRIM(middle_name) AS middle_name,
  CONCAT(
    TRIM(COALESCE(last_name, '')),
    CASE WHEN TRIM(COALESCE(last_name, '')) = '' THEN '' ELSE ', ' END,
    TRIM(COALESCE(first_name, '')),
    CASE WHEN TRIM(COALESCE(middle_name, '')) <> '' THEN CONCAT(', ', UPPER(LEFT(TRIM(middle_name), 1)), '.') ELSE '' END
  ) AS computed_full_name
FROM users
ORDER BY id
LIMIT 200;

-- End preview

-- Normalize stored full_name to the standard 'Surname, Firstname, MI.' format for existing rows
-- Only update when explicit split name columns are present and full_name doesn't already match the pattern
UPDATE `users` u
SET u.`full_name` = CONCAT(TRIM(u.`last_name`), ', ', TRIM(u.`first_name`),
    IF(TRIM(COALESCE(u.`middle_name`, '')) <> '', CONCAT(', ', UPPER(LEFT(TRIM(u.`middle_name`), 1)), '.'), ''))
WHERE (u.`last_name` IS NOT NULL AND TRIM(u.`last_name`) <> '')
  AND (u.`first_name` IS NOT NULL AND TRIM(u.`first_name`) <> '')
  AND (u.`full_name` IS NULL OR u.`full_name` NOT REGEXP '^[^,]+,\\s+[^,]+(,\\s+[A-Z]\\.)?$');

-- ====================================================================
-- Ensure canonical availability column exists and migrate legacy values
-- Adds `availability` ENUM and migrates from older columns (`availability_status`, `availability_hours`) where possible.
-- Mapping from numeric hours to status is conservative and can be adjusted later:
--   >=30 hours -> full_time
--   8-29 hours  -> part_time
--   0-7 hours   -> casual
-- project_based cannot be inferred from hours and must be set manually.
-- ====================================================================
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `availability` enum('full_time','part_time','project_based','casual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- Ensure legacy availability columns exist (safe no-op if already present).
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `availability_hours` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `availability_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- If availability_status already holds canonical values, copy them across
UPDATE `users` u
SET u.`availability` = u.`availability_status`
WHERE (u.`availability` IS NULL OR u.`availability` = '')
  AND u.`availability_status` IN ('full_time','part_time','project_based','casual');

-- Migrate numeric availability_hours into conservative buckets when availability is not set
UPDATE `users` u
SET u.`availability` = 'full_time'
WHERE (u.`availability` IS NULL OR u.`availability` = '')
  AND (u.`availability_hours` IS NOT NULL)
  AND (CAST(u.`availability_hours` AS UNSIGNED) >= 30);

UPDATE `users` u
SET u.`availability` = 'part_time'
WHERE (u.`availability` IS NULL OR u.`availability` = '')
  AND (u.`availability_hours` IS NOT NULL)
  AND (CAST(u.`availability_hours` AS UNSIGNED) BETWEEN 8 AND 29);

UPDATE `users` u
SET u.`availability` = 'casual'
WHERE (u.`availability` IS NULL OR u.`availability` = '')
  AND (u.`availability_hours` IS NOT NULL)
  AND (CAST(u.`availability_hours` AS UNSIGNED) < 8);

-- Note: we intentionally leave legacy columns (`availability_hours`, `availability_status`) in place
-- so existing processes continue to work. Admins may drop them later after verifying the migration.

-- Add commonly used profile columns if missing (bio, profile photo, interests, availability)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `bio` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `profile_photo` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `quest_interests` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `availability_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- Update role enum to include all possible values for backwards compatibility
ALTER TABLE `users` 
MODIFY COLUMN `role` enum('skill_associate','quest_lead','admin','quest_taker','hybrid','quest_giver','participant','contributor') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'skill_associate';

-- User settings table
CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `theme` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `dark_mode` tinyint(1) NOT NULL DEFAULT 0,
  `font_size` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 3. SKILL MANAGEMENT SYSTEM
-- ====================================================================

-- Skill categories table
CREATE TABLE IF NOT EXISTS `skill_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure display_order exists on older databases
ALTER TABLE `skill_categories`
  ADD COLUMN IF NOT EXISTS `display_order` int(11) NOT NULL DEFAULT 0;

-- Comprehensive skills table
CREATE TABLE IF NOT EXISTS `comprehensive_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `skill_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tier_1_points` int(11) DEFAULT 25,
  `tier_2_points` int(11) DEFAULT 40,
  `tier_3_points` int(11) DEFAULT 60,
  `tier_4_points` int(11) DEFAULT 85,
  `tier_5_points` int(11) DEFAULT 115,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `idx_skill_name` (`skill_name`),
  CONSTRAINT `comprehensive_skills_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `skill_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add tier points columns if they don't exist
ALTER TABLE `comprehensive_skills` 
ADD COLUMN IF NOT EXISTS `tier_1_points` int(11) DEFAULT 25,
ADD COLUMN IF NOT EXISTS `tier_2_points` int(11) DEFAULT 40,
ADD COLUMN IF NOT EXISTS `tier_3_points` int(11) DEFAULT 60,
ADD COLUMN IF NOT EXISTS `tier_4_points` int(11) DEFAULT 85,
ADD COLUMN IF NOT EXISTS `tier_5_points` int(11) DEFAULT 115;

-- User skills table (tracks user's skills and proficiency)
CREATE TABLE IF NOT EXISTS `user_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `proficiency_level` enum('beginner','intermediate','advanced','expert') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'beginner',
  `years_experience` decimal(3,1) DEFAULT 0.0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_skill_unique` (`user_id`, `skill_id`),
  KEY `skill_id` (`skill_id`),
  KEY `idx_proficiency` (`proficiency_level`),
  CONSTRAINT `user_skills_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Earned skills from quests (dynamic XP per user/skill)
CREATE TABLE IF NOT EXISTS `user_earned_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `skill_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `current_level` int(11) NOT NULL DEFAULT 1,
  `current_stage` enum('Learning','Applying','Mastering','Innovating') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Learning',
  `last_used` timestamp NULL DEFAULT NULL,
  `recent_points` int(11) NOT NULL DEFAULT 0,
  `status` enum('ACTIVE','STALE','RUSTY') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_skill_name` (`user_id`,`skill_name`),
  KEY `idx_user` (`user_id`),
  KEY `idx_skill_name` (`skill_name`),
  CONSTRAINT `ues_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- Skill Progression Configuration (Bundle 2 - October 2025)
-- --------------------------------------------------------------------
-- New 12-level stretched thresholds (cumulative XP):
-- Level | XP
--   1   | 0
--   2   | 100
--   3   | 260
--   4   | 510
--   5   | 900

-- Quest completions summary (per user per quest)
CREATE TABLE IF NOT EXISTS `quest_completions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_points_awarded` int(11) NOT NULL DEFAULT 0,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_quest` (`quest_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_quest` (`quest_id`),
  CONSTRAINT `qc_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qc_quest_fk` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Quest skills relationship table
CREATE TABLE IF NOT EXISTS `quest_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `required_level` enum('beginner','intermediate','advanced','expert') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'beginner',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quest_skill_unique` (`quest_id`, `skill_id`),
  KEY `skill_id` (`skill_id`),
  KEY `idx_required_level` (`required_level`),
  CONSTRAINT `quest_skills_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quest_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quest subtasks table
CREATE TABLE IF NOT EXISTS `quest_subtasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `order_index` int(11) NOT NULL DEFAULT 0,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  KEY `idx_order` (`order_index`),
  CONSTRAINT `quest_subtasks_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quest attachments table
CREATE TABLE IF NOT EXISTS `quest_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int(11) NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  KEY `idx_file_type` (`file_type`),
  CONSTRAINT `quest_attachments_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 6. QUEST ASSIGNMENT AND TRACKING
-- ====================================================================

-- User quest assignments and progress tracking
CREATE TABLE IF NOT EXISTS `user_quests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quest_id` int(11) NOT NULL,
  `status` enum('assigned','in_progress','submitted','completed','failed','cancelled','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'assigned',
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `progress_percentage` decimal(5,2) DEFAULT 0.00,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attempts` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_quest_unique` (`employee_id`, `quest_id`),
  KEY `quest_id` (`quest_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_at` (`assigned_at`),
  CONSTRAINT `user_quests_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add missing columns to user_quests table if they don't exist
ALTER TABLE `user_quests` 
ADD COLUMN IF NOT EXISTS `started_at` timestamp NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `progress_percentage` decimal(5,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
ADD COLUMN IF NOT EXISTS `attempts` int(11) DEFAULT 1;

-- ====================================================================
-- 7. SUBMISSION AND REVIEW SYSTEM
-- ====================================================================

-- Quest submissions table
CREATE TABLE IF NOT EXISTS `quest_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quest_id` int(11) NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `drive_link` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submission_type` enum('file','link','text') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','approved','rejected','needs_revision') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `feedback` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `additional_xp` int(11) DEFAULT 0,
  `grade` enum('A','B','C','D','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_quest_id` (`quest_id`),
  KEY `idx_status` (`status`),
  KEY `idx_reviewed_by` (`reviewed_by`),
  KEY `idx_submitted_at` (`submitted_at`),
  CONSTRAINT `quest_submissions_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add missing columns to quest_submissions table if they don't exist
ALTER TABLE `quest_submissions` 
ADD COLUMN IF NOT EXISTS `drive_link` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `additional_xp` int(11) DEFAULT 0,
ADD COLUMN IF NOT EXISTS `grade` enum('A','B','C','D','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `text_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- Update submission_type enum to include 'text' if not already present
ALTER TABLE `quest_submissions`
ADD COLUMN IF NOT EXISTS `submission_type` enum('file','link','text') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- Update status enum to include 'needs_revision' if not already present
ALTER TABLE `quest_submissions` 
MODIFY COLUMN `status` enum('pending','approved','rejected','needs_revision') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending';

-- ====================================================================
-- 8. GAMIFICATION AND PROGRESSION
-- ====================================================================

-- XP history tracking table
CREATE TABLE IF NOT EXISTS `xp_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `xp_change` int(11) NOT NULL,
  `source_type` enum('quest_complete','quest_submit','quest_review','bonus','penalty','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_source_type` (`source_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Achievements table
CREATE TABLE IF NOT EXISTS `achievements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `badge_color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#FFD700',
  `xp_reward` int(11) DEFAULT 0,
  `criteria_type` enum('quest_count','xp_total','skill_mastery','submission_streak','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `criteria_value` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_criteria_type` (`criteria_type`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User achievements table
CREATE TABLE IF NOT EXISTS `user_achievements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `achievement_id` int(11) NOT NULL,
  `earned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `progress` decimal(5,2) DEFAULT 100.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_achievement_unique` (`user_id`, `achievement_id`),
  KEY `achievement_id` (`achievement_id`),
  KEY `idx_earned_at` (`earned_at`),
  CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 9. SYSTEM CONFIGURATION AND LOGS
-- ====================================================================

-- System settings table
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `setting_type` enum('string','integer','boolean','json') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_user_configurable` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity logs table
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity_type` (`entity_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 10. INSERT DEFAULT DATA
-- ====================================================================

-- Insert default skill categories (using INSERT IGNORE to prevent duplicates)
INSERT IGNORE INTO `skill_categories` (`id`, `category_name`, `description`) VALUES
(1, 'Technical Skills', 'Programming, software development, and technical competencies'),
(2, 'Communication', 'Verbal, written, and interpersonal communication skills'),
(3, 'Soft Skills', 'Personal skills and interpersonal abilities'),
(4, 'Business Skills', 'Business knowledge, strategy, and commercial skills');

-- Insert comprehensive skills with tier points
INSERT IGNORE INTO `comprehensive_skills` (`skill_name`, `category_id`, `description`, `tier_1_points`, `tier_2_points`, `tier_3_points`, `tier_4_points`, `tier_5_points`) VALUES
-- Technical Skills (Category ID: 1)
('Python Programming', 1, 'Proficiency in Python programming language', 25, 40, 60, 85, 115),
('JavaScript', 1, 'Proficiency in JavaScript programming', 25, 40, 60, 85, 115),
('Java Development', 1, 'Proficiency in Java programming', 25, 40, 60, 85, 115),
('C# .NET', 1, 'Proficiency in C# and .NET framework', 25, 40, 60, 85, 115),
('PHP Development', 1, 'Proficiency in PHP web development', 25, 40, 60, 85, 115),
('React.js', 1, 'Frontend development with React', 25, 40, 60, 85, 115),
('Angular', 1, 'Frontend development with Angular', 25, 40, 60, 85, 115),
('Vue.js', 1, 'Frontend development with Vue', 25, 40, 60, 85, 115),
('Node.js', 1, 'Backend development with Node.js', 25, 40, 60, 85, 115),
('SQL & Database', 1, 'Database design and SQL queries', 25, 40, 60, 85, 115),
('MongoDB', 1, 'NoSQL database with MongoDB', 25, 40, 60, 85, 115),
('Git Version Control', 1, 'Version control with Git', 25, 40, 60, 85, 115),
('Docker', 1, 'Containerization with Docker', 25, 40, 60, 85, 115),
('Kubernetes', 1, 'Container orchestration', 25, 40, 60, 85, 115),
('AWS Cloud', 1, 'Amazon Web Services', 25, 40, 60, 85, 115),
('Azure Cloud', 1, 'Microsoft Azure platform', 25, 40, 60, 85, 115),
('CI/CD Pipelines', 1, 'Continuous Integration/Deployment', 25, 40, 60, 85, 115),
('REST API Design', 1, 'RESTful API development', 25, 40, 60, 85, 115),
('GraphQL', 1, 'GraphQL API development', 25, 40, 60, 85, 115),
('Microservices', 1, 'Microservices architecture', 25, 40, 60, 85, 115),

-- Communication Skills (Category ID: 2)
('Public Speaking', 2, 'Effective public speaking skills', 25, 40, 60, 85, 115),
('Written Communication', 2, 'Clear and professional writing', 25, 40, 60, 85, 115),
('Active Listening', 2, 'Attentive and empathetic listening', 25, 40, 60, 85, 115),
('Presentation Skills', 2, 'Creating and delivering presentations', 25, 40, 60, 85, 115),
('Email Etiquette', 2, 'Professional email communication', 25, 40, 60, 85, 115),
('Meeting Facilitation', 2, 'Leading effective meetings', 25, 40, 60, 85, 115),
('Negotiation', 2, 'Negotiation and persuasion skills', 25, 40, 60, 85, 115),
('Conflict Resolution', 2, 'Resolving workplace conflicts', 25, 40, 60, 85, 115),
('Cross-Cultural Communication', 2, 'Communicating across cultures', 25, 40, 60, 85, 115),
('Technical Writing', 2, 'Writing technical documentation', 25, 40, 60, 85, 115),
('Stakeholder Management', 2, 'Managing stakeholder relationships', 25, 40, 60, 85, 115),
('Client Relations', 2, 'Building client relationships', 25, 40, 60, 85, 115),
('Team Communication', 2, 'Effective team communication', 25, 40, 60, 85, 115),
('Feedback Delivery', 2, 'Constructive feedback skills', 25, 40, 60, 85, 115),
('Storytelling', 2, 'Narrative and storytelling abilities', 25, 40, 60, 85, 115),

-- Soft Skills (Category ID: 3)
('Leadership', 3, 'Leading and inspiring teams', 25, 40, 60, 85, 115),
('Teamwork', 3, 'Collaborative team participation', 25, 40, 60, 85, 115),
('Problem Solving', 3, 'Analytical problem-solving skills', 25, 40, 60, 85, 115),
('Critical Thinking', 3, 'Logical and analytical thinking', 25, 40, 60, 85, 115),
('Time Management', 3, 'Effective time management', 25, 40, 60, 85, 115),
('Adaptability', 3, 'Adapting to change', 25, 40, 60, 85, 115),
('Emotional Intelligence', 3, 'Understanding and managing emotions', 25, 40, 60, 85, 115),
('Decision Making', 3, 'Making informed decisions', 25, 40, 60, 85, 115),
('Creativity', 3, 'Creative thinking and innovation', 25, 40, 60, 85, 115),
('Attention to Detail', 3, 'Precision and accuracy', 25, 40, 60, 85, 115),
('Work Ethic', 3, 'Professional work ethic', 25, 40, 60, 85, 115),
('Self-Motivation', 3, 'Internal drive and initiative', 25, 40, 60, 85, 115),
('Stress Management', 3, 'Managing workplace stress', 25, 40, 60, 85, 115),
('Mentoring', 3, 'Guiding and developing others', 25, 40, 60, 85, 115),
('Empathy', 3, 'Understanding others perspectives', 25, 40, 60, 85, 115),

-- Business Skills (Category ID: 4)
('Project Management', 4, 'Planning and managing projects', 25, 40, 60, 85, 115),
('Agile Methodologies', 4, 'Agile and Scrum practices', 25, 40, 60, 85, 115),
('Strategic Planning', 4, 'Long-term strategic thinking', 25, 40, 60, 85, 115),
('Budget Management', 4, 'Financial planning and budgeting', 25, 40, 60, 85, 115),
('Business Analysis', 4, 'Analyzing business processes', 25, 40, 60, 85, 115),
('Risk Management', 4, 'Identifying and mitigating risks', 25, 40, 60, 85, 115),
('Sales & Marketing', 4, 'Sales and marketing strategies', 25, 40, 60, 85, 115),
('Customer Service', 4, 'Excellent customer service', 25, 40, 60, 85, 115),
('Vendor Management', 4, 'Managing vendor relationships', 25, 40, 60, 85, 115),
('Quality Assurance', 4, 'Quality control and testing', 25, 40, 60, 85, 115),
('Change Management', 4, 'Managing organizational change', 25, 40, 60, 85, 115),
('Process Improvement', 4, 'Optimizing business processes', 25, 40, 60, 85, 115),
('Financial Analysis', 4, 'Financial data analysis', 25, 40, 60, 85, 115),
('Compliance & Governance', 4, 'Regulatory compliance', 25, 40, 60, 85, 115),
('Performance Metrics', 4, 'KPI tracking and reporting', 25, 40, 60, 85, 115);

-- Create quest_types table and insert the two supported quest types
CREATE TABLE IF NOT EXISTS `quest_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_type_key` (`type_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `quest_types` (`type_key`, `name`, `description`, `icon`) VALUES
('custom', 'Custom', 'User-defined/custom quests', '�'),
('client_support', 'Client & Support Operations', 'Client support related quests (auto-attached skills)', '🎧');

-- If an old `quest_categories` table exists, migrate its rows into `quest_types`.
-- We generate a `type_key` from the existing name by lowercasing and replacing spaces with underscores.
-- This preserves existing category names in the new table while ensuring the two
-- supported types remain present. Duplicate keys are ignored.
INSERT IGNORE INTO `quest_types` (`type_key`, `name`, `description`, `icon`, `created_at`)
SELECT LOWER(REPLACE(name, ' ', '_')) AS type_key, name, description, icon, created_at
FROM `quest_categories`;

-- Drop the legacy quest_categories table now that data is migrated
DROP TABLE IF EXISTS `quest_categories`;

-- Ensure only the two supported quest types exist (remove any migrated or legacy extras).
-- WARNING: destructive. Please BACKUP your database before running this section.
DELETE FROM `quest_types` WHERE `type_key` NOT IN ('custom', 'client_support');

-- Ensure the two canonical types exist after cleanup
INSERT IGNORE INTO `quest_types` (`type_key`, `name`, `description`, `icon`) VALUES
('custom', 'Custom', 'User-defined/custom quests', '📦'),
('client_support', 'Client & Support Operations', 'Client support related quests (auto-attached skills)', '🎧');

-- Insert default system settings
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `is_user_configurable`) VALUES
('system_name', 'YooNet Quest System', 'string', 'The name of the application', 1),
('max_file_upload_size', '5242880', 'integer', 'Maximum file upload size in bytes (5MB)', 1),
('default_quest_xp', '10', 'integer', 'Default XP value for new quests', 1),
('enable_achievements', 'true', 'boolean', 'Enable achievement system', 1),
('default_theme', 'default', 'string', 'Default theme for new users', 1),
('email_notifications', 'true', 'boolean', 'Enable email notifications', 1),
('session_timeout', '3600', 'integer', 'Session timeout in seconds', 0),
('password_min_length', '8', 'integer', 'Minimum password length', 0),
('max_login_attempts', '5', 'integer', 'Maximum login attempts before lockout', 0),
('backup_retention_days', '30', 'integer', 'Number of days to retain backups', 0);

-- Insert default achievements
INSERT IGNORE INTO `achievements` (`name`, `description`, `icon`, `badge_color`, `xp_reward`, `criteria_type`, `criteria_value`) VALUES
('First Steps', 'Complete your first quest', '🌟', '#FFD700', 25, 'quest_count', 1),
('Getting Started', 'Complete 5 quests', '⭐', '#C0C0C0', 50, 'quest_count', 5),
('Quest Enthusiast', 'Complete 10 quests', '🏅', '#CD7F32', 100, 'quest_count', 10),
('Quest Master', 'Complete 25 quests', '🏆', '#FFD700', 250, 'quest_count', 25),
('Legend', 'Complete 50 quests', '👑', '#9B59B6', 500, 'quest_count', 50),
('XP Collector', 'Earn 100 XP', '💎', '#3498DB', 20, 'xp_total', 100),
('XP Hunter', 'Earn 500 XP', '💰', '#E74C3C', 50, 'xp_total', 500),
('XP Champion', 'Earn 1000 XP', '🔥', '#E67E22', 100, 'xp_total', 1000),
('Skill Builder', 'Master 3 different skills', '🎯', '#2ECC71', 75, 'skill_mastery', 3),
('Renaissance', 'Master 10 different skills', '🧠', '#8E44AD', 200, 'skill_mastery', 10);

-- ====================================================================
-- 11. CREATE INDEXES FOR PERFORMANCE
-- ====================================================================

-- Additional performance indexes
CREATE INDEX IF NOT EXISTS `idx_users_role_profile` ON `users` (`role`, `profile_completed`);
CREATE INDEX IF NOT EXISTS `idx_quests_status_created` ON `quests` (`status`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_user_quests_status_assigned` ON `user_quests` (`status`, `assigned_at`);
CREATE INDEX IF NOT EXISTS `idx_submissions_status_submitted` ON `quest_submissions` (`status`, `submitted_at`);
CREATE INDEX IF NOT EXISTS `idx_xp_history_employee_created` ON `xp_history` (`employee_id`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_activity_logs_user_created` ON `activity_logs` (`user_id`, `created_at`);

-- ====================================================================
-- 12. UPDATE EXISTING DATA FOR CONSISTENCY
-- ====================================================================

-- Update existing data to ensure consistency
-- Ensure `quests` table has the columns the application expects (idempotent)
-- Adds `quest_type`, display and client support fields, recurrence and publish timestamps.
ALTER TABLE `quests`
  ADD COLUMN IF NOT EXISTS `quest_type` enum('routine','minor','standard','major','project','recurring') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'routine',
  ADD COLUMN IF NOT EXISTS `display_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'custom',
  ADD COLUMN IF NOT EXISTS `client_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `client_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sla_priority` enum('low','medium','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'medium',
  ADD COLUMN IF NOT EXISTS `expected_response` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `client_contact_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `client_contact_phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sla_due_hours` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `external_ticket_link` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `service_level_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `vendor_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `estimated_hours` decimal(6,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `visibility` enum('public','private') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'public',
  ADD COLUMN IF NOT EXISTS `recurrence_pattern` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recurrence_end_date` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `publish_at` datetime DEFAULT NULL;

-- Normalize any legacy values and set sensible defaults
UPDATE `quests` SET `quest_type` = 'routine' WHERE `quest_type` IS NULL OR `quest_type` = 'single';
-- Leave existing 'recurring' values untouched if present

-- Ensure `quest_skills.required_level` exists for older databases.
-- This is idempotent and safe to run. Recommended: BACKUP your DB before running the consolidated script.
ALTER TABLE `quest_skills`
  ADD COLUMN IF NOT EXISTS `required_level` enum('beginner','intermediate','advanced','expert') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'beginner';

-- Add an index for quest_type to help filtering by type
-- NOTE: Creating an index can require creating a temporary copy of the table
-- which may fail on low-disk-space or limited InnoDB tablespace with error #1114
-- To avoid blocking the full consolidated run, the CREATE INDEX is commented out
-- here. Run the following commands manually after ensuring sufficient disk
-- space or adjusting MySQL tmpdir/innodb settings.

-- CREATE INDEX `idx_quests_type` ON `quests` (`quest_type`);

-- Optional: conditional check (run manually in the MySQL client) to avoid
-- attempting to create an index that already exists. Replace `yoonet_quest`
-- with your database name if different.
--
-- SELECT COUNT(*) AS idx_exists
-- FROM information_schema.STATISTICS
-- WHERE TABLE_SCHEMA = 'yoonet_quest' AND TABLE_NAME = 'quests' AND INDEX_NAME = 'idx_quests_type';
--
-- If the above returns 0, create the index manually (ensure you have disk space):
-- CREATE INDEX `idx_quests_type` ON `quests` (`quest_type`);

-- If you want me to create this index for you, free up space or tell me and
-- I'll walk you through checking tmpdir/datadir and retrying safely.

UPDATE users SET profile_completed = 1 WHERE profile_completed IS NULL;
UPDATE quests SET quest_assignment_type = 'optional' WHERE quest_assignment_type IS NULL;
UPDATE quest_submissions SET additional_xp = 0 WHERE additional_xp IS NULL;

-- ====================================================================
-- 13. OPTIMIZE DATABASE
-- ====================================================================

-- Analyze tables for query optimization
ANALYZE TABLE users, quests, user_quests, quest_submissions, comprehensive_skills, quest_skills, xp_history;

-- ====================================================================
-- 14. COMPLETION MESSAGE
-- ====================================================================

-- Create a temporary table to show completion status
CREATE TEMPORARY TABLE IF NOT EXISTS setup_status (
    component VARCHAR(100),
    status VARCHAR(20),
    description TEXT
);

INSERT INTO setup_status VALUES
('Database Schema', 'COMPLETE', 'All tables created successfully'),
('Indexes', 'COMPLETE', 'Performance indexes created'),
('Default Data', 'COMPLETE', 'Categories, skills, and settings populated'),
('Skill System', 'COMPLETE', 'Comprehensive skills with tier points'),
('Quest System', 'COMPLETE', 'Full quest management system'),
('Gamification', 'COMPLETE', 'XP tracking and achievements'),
('Optimization', 'COMPLETE', 'Database analyzed and optimized');

SELECT 
  '=====================================================================' AS `separator`,
  'YooNet Quest System Database Setup Complete!' AS `message`,
  '=====================================================================' AS `separator2`
FROM DUAL;

SELECT * FROM setup_status;

SELECT 
  '=====================================================================' AS `separator`,
  'Database is ready for use!' AS `message`,
  'All tables, indexes, and default data have been set up.' AS `details`,
-- Commit all changes
COMMIT;

-- Restore SQL settings
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;