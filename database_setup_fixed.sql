-- ========================================
-- YooNet Quest System - Database Setup (Fixed Foreign Keys)
-- ========================================
-- This file creates tables first, then adds foreign key constraints
-- Execute this file to set up the complete skill assessment system
--
-- Version: 3.1 (October 2025) - Fixed foreign key constraint issues
-- Usage: Import this file through phpMyAdmin or MySQL command line
-- ========================================

-- Set session variables for consistent behavior
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS `yoonet_quest` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `yoonet_quest`;

-- Disable foreign key checks to allow dropping tables
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables if they exist (in reverse dependency order)
DROP TABLE IF EXISTS `user_preferences`;
DROP TABLE IF EXISTS `user_profile_views`;
DROP TABLE IF EXISTS `user_achievements`;
DROP TABLE IF EXISTS `skill_points_history`;
DROP TABLE IF EXISTS `skill_assessments`;
DROP TABLE IF EXISTS `quest_submissions`;
DROP TABLE IF EXISTS `user_quests`;
DROP TABLE IF EXISTS `quest_skills`;
DROP TABLE IF EXISTS `quests`;
DROP TABLE IF EXISTS `group_members`;
DROP TABLE IF EXISTS `employee_groups`;
DROP TABLE IF EXISTS `user_skill_progress`;
DROP TABLE IF EXISTS `comprehensive_skills`;
DROP TABLE IF EXISTS `skill_categories`;
DROP TABLE IF EXISTS `users`;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ========================================
-- SECTION 1: CREATE TABLES (WITHOUT FOREIGN KEYS)
-- ========================================

-- 1. Users table
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('skill_associate','quest_lead','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'skill_associate',
  `profile_photo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `location` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `availability_status` enum('available','busy','away') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'available',
  `availability_message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Skill Categories table
CREATE TABLE `skill_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#6366f1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Comprehensive Skills table
CREATE TABLE `comprehensive_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `skill_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int(11) NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tier_1_points` int(11) DEFAULT 25,
  `tier_2_points` int(11) DEFAULT 40,
  `tier_3_points` int(11) DEFAULT 60,
  `tier_4_points` int(11) DEFAULT 85,
  `tier_5_points` int(11) DEFAULT 115,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. User Skill Progress table
CREATE TABLE `user_skill_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `skill_id` int(11) NOT NULL,
  `total_points` int(11) DEFAULT 0,
  `skill_level` int(11) DEFAULT 1,
  `current_stage` enum('LEARNING','APPLYING','MASTERING','INNOVATING') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'LEARNING',
  `last_activity` timestamp NULL DEFAULT NULL,
  `activity_status` enum('ACTIVE','STALE','RUSTY') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_skill` (`employee_id`, `skill_id`),
  KEY `skill_id` (`skill_id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Employee Groups table
CREATE TABLE `employee_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Group Members table
CREATE TABLE `group_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Quests table
CREATE TABLE `quests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `due_date` date DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `quest_assignment_type` enum('mandatory','optional') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'optional',
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Quest Skills table
CREATE TABLE `quest_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `tier_level` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  KEY `skill_id` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. User Quests table
CREATE TABLE `user_quests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quest_id` int(11) NOT NULL,
  `status` enum('assigned','in_progress','submitted','completed','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'assigned',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `progress_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_quest` (`employee_id`,`quest_id`),
  KEY `quest_id` (`quest_id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Quest Submissions table
CREATE TABLE `quest_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `submission_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','under_review','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `feedback` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  KEY `employee_id` (`employee_id`),
  KEY `reviewed_by` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Skill Assessments table
CREATE TABLE `skill_assessments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `submission_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `base_tier_level` int(11) NOT NULL,
  `performance_modifier` enum('below_expectations','meets_expectations','exceeds_expectations','exceptional') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'meets_expectations',
  `points_awarded` int(11) NOT NULL DEFAULT 0,
  `assessor_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `assessed_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `assessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `submission_id` (`submission_id`),
  KEY `skill_id` (`skill_id`),
  KEY `assessed_by` (`assessed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Skill Points History table
CREATE TABLE `skill_points_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `skill_id` int(11) NOT NULL,
  `points_change` int(11) NOT NULL,
  `source_type` enum('quest_completion','assessment_adjustment','manual_adjustment','decay') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `skill_id` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. User Achievements table
CREATE TABLE `user_achievements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `achievement_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `achievement_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skill_id` int(11) DEFAULT NULL,
  `earned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `skill_id` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. User Profile Views table
CREATE TABLE `user_profile_views` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `viewer_employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `profile_employee_id` (`profile_employee_id`),
  KEY `viewer_employee_id` (`viewer_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. User Preferences table
CREATE TABLE `user_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `preference_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `preference_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_preference` (`employee_id`,`preference_key`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- SECTION 2: ADD FOREIGN KEY CONSTRAINTS
-- ========================================

-- Add foreign keys for comprehensive_skills
ALTER TABLE `comprehensive_skills` 
ADD CONSTRAINT `comprehensive_skills_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `skill_categories` (`id`) ON DELETE CASCADE;

-- Add foreign keys for user_skill_progress
ALTER TABLE `user_skill_progress` 
ADD CONSTRAINT `user_skill_progress_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
ADD CONSTRAINT `user_skill_progress_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE;

-- Add foreign keys for employee_groups
ALTER TABLE `employee_groups` 
ADD CONSTRAINT `employee_groups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

-- Add foreign keys for group_members
ALTER TABLE `group_members` 
ADD CONSTRAINT `group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `employee_groups` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT `group_members_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

-- Add foreign keys for quests
ALTER TABLE `quests` 
ADD CONSTRAINT `quests_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

-- Add foreign keys for quest_skills
ALTER TABLE `quest_skills` 
ADD CONSTRAINT `quest_skills_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT `quest_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE;

-- Add foreign keys for user_quests
ALTER TABLE `user_quests` 
ADD CONSTRAINT `user_quests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
ADD CONSTRAINT `user_quests_ibfk_2` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE;

-- Add foreign keys for quest_submissions
ALTER TABLE `quest_submissions` 
ADD CONSTRAINT `quest_submissions_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT `quest_submissions_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
ADD CONSTRAINT `quest_submissions_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`employee_id`) ON DELETE SET NULL;

-- Add foreign keys for skill_assessments
ALTER TABLE `skill_assessments` 
ADD CONSTRAINT `skill_assessments_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `quest_submissions` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT `skill_assessments_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT `skill_assessments_ibfk_3` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

-- Add foreign keys for skill_points_history
ALTER TABLE `skill_points_history` 
ADD CONSTRAINT `skill_points_history_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
ADD CONSTRAINT `skill_points_history_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE;

-- Add foreign keys for user_achievements
ALTER TABLE `user_achievements` 
ADD CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
ADD CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE SET NULL;

-- Add foreign keys for user_profile_views
ALTER TABLE `user_profile_views` 
ADD CONSTRAINT `user_profile_views_ibfk_1` FOREIGN KEY (`profile_employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
ADD CONSTRAINT `user_profile_views_ibfk_2` FOREIGN KEY (`viewer_employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

-- Add foreign keys for user_preferences
ALTER TABLE `user_preferences` 
ADD CONSTRAINT `user_preferences_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

-- ========================================
-- SECTION 3: INSERT SKILL CATEGORIES
-- ========================================

INSERT INTO `skill_categories` (`id`, `category_name`, `description`, `icon`, `color`) VALUES
(1, 'Technical Skills', 'Programming, software development, and technical expertise', 'fas fa-code', '#3B82F6'),
(2, 'Communication Skills', 'Written, verbal, and interpersonal communication abilities', 'fas fa-comments', '#F59E0B'),
(3, 'Soft Skills', 'Personal attributes and social abilities', 'fas fa-heart', '#EF4444'),
(4, 'Business Skills', 'Management, strategy, and business acumen', 'fas fa-briefcase', '#10B981');

-- ========================================
-- SECTION 4: INSERT COMPREHENSIVE SKILLS
-- ========================================

-- TECHNICAL SKILLS (Category ID: 1)
INSERT INTO `comprehensive_skills` (`skill_name`, `category_id`, `description`, `tier_1_points`, `tier_2_points`, `tier_3_points`, `tier_4_points`, `tier_5_points`) VALUES

-- Programming Languages
('JavaScript', 1, 'Modern JavaScript programming and ES6+ features', 25, 40, 60, 85, 115),
('Python', 1, 'Python programming language and frameworks', 25, 40, 60, 85, 115),
('Java', 1, 'Java programming language and ecosystem', 30, 45, 65, 90, 120),
('C#', 1, 'C# and .NET development', 30, 45, 65, 90, 120),
('C++', 1, 'C++ programming and system development', 35, 50, 70, 95, 125),
('PHP', 1, 'PHP web development and frameworks', 25, 40, 60, 85, 115),
('Ruby', 1, 'Ruby programming language and Rails', 25, 40, 60, 85, 115),
('Go', 1, 'Go programming language', 30, 45, 65, 90, 120),
('Rust', 1, 'Rust systems programming', 40, 60, 80, 105, 135),
('Swift', 1, 'Swift iOS development', 30, 45, 65, 90, 120),
('Kotlin', 1, 'Kotlin Android development', 30, 45, 65, 90, 120),

-- Web Technologies
('HTML5', 1, 'Modern HTML5 and semantic markup', 20, 35, 50, 75, 100),
('CSS3', 1, 'CSS3 styling and responsive design', 25, 40, 60, 85, 115),
('React', 1, 'React.js frontend framework', 30, 45, 65, 90, 120),
('Angular', 1, 'Angular frontend framework', 35, 50, 70, 95, 125),
('Vue.js', 1, 'Vue.js frontend framework', 30, 45, 65, 90, 120),
('Node.js', 1, 'Node.js backend development', 30, 45, 65, 90, 120),
('Express.js', 1, 'Express.js web framework', 25, 40, 60, 85, 115),
('REST API Development', 1, 'RESTful API design and implementation', 30, 45, 65, 90, 120),
('GraphQL', 1, 'GraphQL API development', 35, 50, 70, 95, 125),
('WebSockets', 1, 'Real-time web communication', 35, 50, 70, 95, 125),

-- Database Technologies
('SQL', 1, 'Structured Query Language', 25, 40, 60, 85, 115),
('MySQL', 1, 'MySQL database administration', 30, 45, 65, 90, 120),
('PostgreSQL', 1, 'PostgreSQL database development', 30, 45, 65, 90, 120),
('MongoDB', 1, 'MongoDB NoSQL database', 30, 45, 65, 90, 120),
('Redis', 1, 'Redis in-memory database', 25, 40, 60, 85, 115),
('Database Design', 1, 'Relational database design principles', 35, 50, 70, 95, 125),

-- DevOps & Infrastructure
('Docker', 1, 'Containerization with Docker', 30, 45, 65, 90, 120),
('Kubernetes', 1, 'Container orchestration', 40, 60, 80, 105, 135),
('AWS', 1, 'Amazon Web Services', 35, 50, 70, 95, 125),
('Azure', 1, 'Microsoft Azure platform', 35, 50, 70, 95, 125),
('Google Cloud Platform', 1, 'GCP services and deployment', 35, 50, 70, 95, 125),
('CI/CD', 1, 'Continuous Integration/Deployment', 35, 50, 70, 95, 125),
('Jenkins', 1, 'Jenkins automation server', 30, 45, 65, 90, 120),
('Git Version Control', 1, 'Git workflows and collaboration', 25, 40, 60, 85, 115),
('Linux Administration', 1, 'Linux system administration', 35, 50, 70, 95, 125),
('Network Security', 1, 'Network security implementation', 40, 60, 80, 105, 135),

-- Testing & Quality
('Unit Testing', 1, 'Writing and maintaining unit tests', 25, 40, 60, 85, 115),
('Test Automation', 1, 'Automated testing frameworks', 35, 50, 70, 95, 125),
('Performance Testing', 1, 'Application performance testing', 35, 50, 70, 95, 125),
('Security Testing', 1, 'Application security testing', 40, 60, 80, 105, 135),
('Code Review', 1, 'Effective code review practices', 30, 45, 65, 90, 120),

-- Specialized Technologies
('Machine Learning', 1, 'ML algorithms and implementations', 40, 60, 80, 105, 135),
('Data Science', 1, 'Data analysis and visualization', 35, 50, 70, 95, 125),
('Blockchain Development', 1, 'Blockchain and smart contracts', 45, 65, 85, 110, 140),
('Mobile Development', 1, 'Native and cross-platform mobile apps', 35, 50, 70, 95, 125),
('Game Development', 1, 'Video game programming', 40, 60, 80, 105, 135),
('Cybersecurity', 1, 'Information security practices', 40, 60, 80, 105, 135),

-- COMMUNICATION SKILLS (Category ID: 2)
('Public Speaking', 2, 'Effective presentation and speaking skills', 30, 45, 65, 90, 120),
('Technical Writing', 2, 'Documentation and technical communication', 25, 40, 60, 85, 115),
('Business Writing', 2, 'Professional business communication', 25, 40, 60, 85, 115),
('Email Communication', 2, 'Effective email writing and etiquette', 20, 35, 50, 75, 100),
('Meeting Facilitation', 2, 'Running effective meetings', 30, 45, 65, 90, 120),
('Presentation Design', 2, 'Creating compelling presentations', 25, 40, 60, 85, 115),
('Cross-Cultural Communication', 2, 'Communicating across cultures', 35, 50, 70, 95, 125),
('Conflict Resolution', 2, 'Resolving workplace conflicts', 35, 50, 70, 95, 125),
('Active Listening', 2, 'Effective listening techniques', 25, 40, 60, 85, 115),
('Persuasion', 2, 'Influencing and persuasion skills', 30, 45, 65, 90, 120),
('Negotiation', 2, 'Business negotiation strategies', 35, 50, 70, 95, 125),
('Storytelling', 2, 'Narrative communication techniques', 30, 45, 65, 90, 120),
('Visual Communication', 2, 'Communicating through visuals', 25, 40, 60, 85, 115),
('Remote Communication', 2, 'Effective virtual team communication', 25, 40, 60, 85, 115),
('Customer Communication', 2, 'Client and customer interaction skills', 30, 45, 65, 90, 120),

-- SOFT SKILLS (Category ID: 3)
('Time Management', 3, 'Effective time planning and execution', 25, 40, 60, 85, 115),
('Problem Solving', 3, 'Analytical problem-solving approaches', 30, 45, 65, 90, 120),
('Critical Thinking', 3, 'Logical analysis and reasoning', 30, 45, 65, 90, 120),
('Creativity', 3, 'Innovative thinking and ideation', 25, 40, 60, 85, 115),
('Adaptability', 3, 'Flexibility and change management', 25, 40, 60, 85, 115),
('Emotional Intelligence', 3, 'Understanding and managing emotions', 30, 45, 65, 90, 120),
('Stress Management', 3, 'Handling pressure and stress', 25, 40, 60, 85, 115),
('Work-Life Balance', 3, 'Maintaining healthy work boundaries', 25, 40, 60, 85, 115),
('Self-Motivation', 3, 'Internal drive and goal achievement', 25, 40, 60, 85, 115),
('Continuous Learning', 3, 'Commitment to ongoing development', 25, 40, 60, 85, 115),
('Attention to Detail', 3, 'Thoroughness and accuracy', 25, 40, 60, 85, 115),
('Multitasking', 3, 'Managing multiple tasks effectively', 25, 40, 60, 85, 115),
('Decision Making', 3, 'Effective decision-making processes', 30, 45, 65, 90, 120),
('Risk Assessment', 3, 'Evaluating and managing risks', 30, 45, 65, 90, 120),
('Initiative Taking', 3, 'Proactive approach and self-starting', 25, 40, 60, 85, 115),
('Resilience', 3, 'Bouncing back from setbacks', 25, 40, 60, 85, 115),
('Collaboration', 3, 'Working effectively with others', 25, 40, 60, 85, 115),
('Mentoring', 3, 'Guiding and developing others', 30, 45, 65, 90, 120),
('Cultural Awareness', 3, 'Understanding diverse perspectives', 25, 40, 60, 85, 115),
('Ethics and Integrity', 3, 'Maintaining professional standards', 25, 40, 60, 85, 115),

-- BUSINESS SKILLS (Category ID: 4)
('Project Management', 4, 'Planning and executing projects', 35, 50, 70, 95, 125),
('Agile Methodology', 4, 'Agile project management practices', 30, 45, 65, 90, 120),
('Scrum Master', 4, 'Scrum framework facilitation', 35, 50, 70, 95, 125),
('Product Management', 4, 'Product strategy and development', 35, 50, 70, 95, 125),
('Business Analysis', 4, 'Requirements gathering and analysis', 30, 45, 65, 90, 120),
('Strategic Planning', 4, 'Long-term business strategy', 40, 60, 80, 105, 135),
('Financial Analysis', 4, 'Financial planning and analysis', 35, 50, 70, 95, 125),
('Budget Management', 4, 'Budget planning and control', 30, 45, 65, 90, 120),
('Risk Management', 4, 'Business risk assessment and mitigation', 35, 50, 70, 95, 125),
('Vendor Management', 4, 'Managing external partnerships', 30, 45, 65, 90, 120),
('Quality Assurance', 4, 'Quality management systems', 30, 45, 65, 90, 120),
('Process Improvement', 4, 'Optimizing business processes', 30, 45, 65, 90, 120),
('Change Management', 4, 'Managing organizational change', 35, 50, 70, 95, 125),
('Team Leadership', 4, 'Leading and managing teams', 35, 50, 70, 95, 125),
('Performance Management', 4, 'Managing team performance', 30, 45, 65, 90, 120),
('Recruitment', 4, 'Talent acquisition and hiring', 25, 40, 60, 85, 115),
('Training and Development', 4, 'Employee skill development', 30, 45, 65, 90, 120),
('Market Research', 4, 'Market analysis and research', 30, 45, 65, 90, 120),
('Competitive Analysis', 4, 'Analyzing market competition', 30, 45, 65, 90, 120),
('Customer Relationship Management', 4, 'CRM strategies and implementation', 30, 45, 65, 90, 120),
('Sales Management', 4, 'Sales strategy and team management', 35, 50, 70, 95, 125),
('Marketing Strategy', 4, 'Marketing planning and execution', 35, 50, 70, 95, 125),
('Digital Marketing', 4, 'Online marketing and social media', 30, 45, 65, 90, 120),
('Data Analytics', 4, 'Business intelligence and analytics', 35, 50, 70, 95, 125),
('Compliance Management', 4, 'Regulatory compliance and governance', 30, 45, 65, 90, 120);

-- ========================================
-- SECTION 5: INSERT SAMPLE DATA
-- ========================================

-- Insert sample admin user (password: admin123)
INSERT INTO `users` (`employee_id`, `email`, `password`, `full_name`, `role`) VALUES
('ADMIN001', 'admin@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin');

-- Insert sample Quest Lead (password: questlead123)
INSERT INTO `users` (`employee_id`, `email`, `password`, `full_name`, `role`) VALUES
('QL001', 'questlead@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane Quest Leader', 'quest_lead');

-- Insert sample Skill Associate (password: skillassoc123)
INSERT INTO `users` (`employee_id`, `email`, `password`, `full_name`, `role`) VALUES
('SA001', 'skillassoc@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Skill Associate', 'skill_associate');

-- Sample quest with skills
INSERT INTO `quests` (`id`, `title`, `description`, `status`, `created_by`, `quest_assignment_type`) VALUES
(1, 'Build a Dockerized Python API', 'Create a REST API using Python and Flask, containerized with Docker', 'active', 'QL001', 'optional');

-- Sample quest skills
INSERT INTO `quest_skills` (`quest_id`, `skill_id`, `tier_level`) VALUES
(1, 2, 2), -- Python Tier 2
(1, 27, 1), -- Docker Tier 1
(1, 33, 2); -- Git Version Control Tier 2

-- ========================================
-- SECTION 6: VERIFICATION QUERIES
-- ========================================

-- Verify table creation
SELECT 'Skill Assessment Database setup completed successfully!' as status;
SELECT COUNT(*) as total_tables FROM information_schema.tables WHERE table_schema = 'yoonet_quest';

-- Verify skill system
SELECT 'Comprehensive skill system ready!' as status;
SELECT category_name, COUNT(*) as skill_count FROM comprehensive_skills cs
JOIN skill_categories sc ON cs.category_id = sc.id
GROUP BY sc.category_name;

-- Verify user roles
SELECT 'User role system updated!' as status;
SELECT role, COUNT(*) as count FROM users GROUP BY role;

-- Final status
SELECT 'YooNet Quest System v3.1 with Skill Assessment is ready for use!' as final_status;

COMMIT;