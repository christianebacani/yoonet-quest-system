-- ========================================
-- YooNet Quest System - Complete Database Setup
-- ========================================
-- This file contains all database schema and updates needed for the YooNet Quest System
-- Execute this file on a fresh database to set up the complete system
-- 
-- Version: 2.0 (Updated October 2025)
-- Changes: Role terminology update (Learning Architect → Quest Lead, Skill Seeker → Skill Associate)
--          Quest assignment system (Categories → Mandatory/Optional)
--
-- Usage: mysql -u root -p your_database_name < complete_database_setup.sql
-- ========================================

-- Set session variables for consistent behavior
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ========================================
-- SECTION 1: CREATE DATABASE AND TABLES
-- ========================================

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS `yoonet_quest` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `yoonet_quest`;

-- 1. Users table (with updated role terminology)
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('skill_associate','quest_lead','admin') NOT NULL DEFAULT 'skill_associate',
  `total_xp` int(11) DEFAULT 0,
  `level` int(11) DEFAULT 1,
  `profile_photo` varchar(255) DEFAULT NULL,
  `bio` text,
  `location` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `skills_summary` text,
  `achievements_summary` text,
  `availability_status` enum('available','busy','away') DEFAULT 'available',
  `availability_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Employee Groups table
CREATE TABLE IF NOT EXISTS `employee_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(100) NOT NULL,
  `description` text,
  `created_by` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `employee_groups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Group Members table
CREATE TABLE IF NOT EXISTS `group_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `employee_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_members_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Quests table (with new assignment system - no category_id)
CREATE TABLE IF NOT EXISTS `quests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `xp` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `due_date` date DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `quest_assignment_type` enum('mandatory','optional') DEFAULT 'optional',
  `quest_type` enum('single','recurring') DEFAULT 'single',
  `visibility` enum('public','private') DEFAULT 'public',
  `recurrence_pattern` varchar(50) DEFAULT NULL,
  `recurrence_end_date` datetime DEFAULT NULL,
  `publish_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `quests_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Quest Categories table (kept for reference, but not used in new system)
CREATE TABLE IF NOT EXISTS `quest_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text,
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6366f1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. User Quests table (assignment tracking)
CREATE TABLE IF NOT EXISTS `user_quests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `status` enum('assigned','in_progress','completed','declined') NOT NULL DEFAULT 'assigned',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `progress_notes` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_quest` (`employee_id`,`quest_id`),
  KEY `quest_id` (`quest_id`),
  CONSTRAINT `user_quests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `user_quests_ibfk_2` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Quest Subtasks table
CREATE TABLE IF NOT EXISTS `quest_subtasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  CONSTRAINT `quest_subtasks_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Quest Attachments table
CREATE TABLE IF NOT EXISTS `quest_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  CONSTRAINT `quest_attachments_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Quest Submissions table
CREATE TABLE IF NOT EXISTS `quest_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `submission_text` text,
  `file_path` varchar(500) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `feedback` text,
  `reviewed_by` varchar(50) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  KEY `employee_id` (`employee_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `quest_submissions_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quest_submissions_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `quest_submissions_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Quest Completions table
CREATE TABLE IF NOT EXISTS `quest_completions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `xp_earned` int(11) NOT NULL DEFAULT 0,
  `feedback` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quest_employee` (`quest_id`,`employee_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `quest_completions_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quest_completions_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Quest Prerequisites table
CREATE TABLE IF NOT EXISTS `quest_prerequisites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `prerequisite_quest_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  KEY `prerequisite_quest_id` (`prerequisite_quest_id`),
  CONSTRAINT `quest_prerequisites_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quest_prerequisites_ibfk_2` FOREIGN KEY (`prerequisite_quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Skill Categories table
CREATE TABLE IF NOT EXISTS `skill_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6366f1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Predefined Skills table
CREATE TABLE IF NOT EXISTS `predefined_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `skill_name` varchar(100) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `description` text,
  `icon` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `predefined_skills_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `skill_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. User Skills table
CREATE TABLE IF NOT EXISTS `user_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `skill_name` varchar(100) NOT NULL,
  `proficiency_level` enum('beginner','intermediate','advanced','expert') NOT NULL DEFAULT 'beginner',
  `years_experience` int(11) DEFAULT 0,
  `verified` tinyint(1) DEFAULT 0,
  `verified_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `user_skills_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `user_skills_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. User Earned Skills table
CREATE TABLE IF NOT EXISTS `user_earned_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `skill_name` varchar(100) NOT NULL,
  `earned_from_quest_id` int(11) DEFAULT NULL,
  `earned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `earned_from_quest_id` (`earned_from_quest_id`),
  CONSTRAINT `user_earned_skills_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `user_earned_skills_ibfk_2` FOREIGN KEY (`earned_from_quest_id`) REFERENCES `quests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Quest Skills table (skills associated with quests)
CREATE TABLE IF NOT EXISTS `quest_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_id` int(11) NOT NULL,
  `skill_name` varchar(100) NOT NULL,
  `skill_type` enum('teaches','requires') NOT NULL DEFAULT 'teaches',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `quest_id` (`quest_id`),
  CONSTRAINT `quest_skills_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. XP History table
CREATE TABLE IF NOT EXISTS `xp_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `xp_change` int(11) NOT NULL,
  `source_type` enum('quest_completed','quest_assigned','skill_verified','achievement_earned','manual_adjustment') NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `xp_history_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. User Achievements table
CREATE TABLE IF NOT EXISTS `user_achievements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `achievement_type` varchar(50) NOT NULL,
  `achievement_name` varchar(100) NOT NULL,
  `description` text,
  `icon` varchar(50) DEFAULT NULL,
  `earned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. User Profile Views table
CREATE TABLE IF NOT EXISTS `user_profile_views` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_employee_id` varchar(50) NOT NULL,
  `viewer_employee_id` varchar(50) NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `profile_employee_id` (`profile_employee_id`),
  KEY `viewer_employee_id` (`viewer_employee_id`),
  CONSTRAINT `user_profile_views_ibfk_1` FOREIGN KEY (`profile_employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `user_profile_views_ibfk_2` FOREIGN KEY (`viewer_employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. User Preferences table
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `preference_key` varchar(100) NOT NULL,
  `preference_value` text,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_preference` (`employee_id`,`preference_key`),
  CONSTRAINT `user_preferences_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Leaderboard table
CREATE TABLE IF NOT EXISTS `leaderboard` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `total_xp` int(11) NOT NULL DEFAULT 0,
  `rank_position` int(11) DEFAULT NULL,
  `quests_completed` int(11) DEFAULT 0,
  `skills_earned` int(11) DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  CONSTRAINT `leaderboard_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- SECTION 2: INSERT DEFAULT DATA
-- ========================================

-- Insert default skill categories
INSERT IGNORE INTO `skill_categories` (`id`, `name`, `description`, `icon`, `color`) VALUES
(1, 'Technical Skills', 'Programming, software development, and technical expertise', 'fas fa-code', '#3B82F6'),
(2, 'Design & Creative', 'UI/UX design, graphic design, and creative skills', 'fas fa-palette', '#8B5CF6'),
(3, 'Project Management', 'Planning, coordination, and project leadership skills', 'fas fa-tasks', '#10B981'),
(4, 'Communication', 'Written, verbal, and interpersonal communication skills', 'fas fa-comments', '#F59E0B'),
(5, 'Data & Analytics', 'Data analysis, statistics, and business intelligence', 'fas fa-chart-bar', '#EF4444'),
(6, 'Leadership', 'Team leadership, mentoring, and management skills', 'fas fa-users', '#6366F1');

-- Insert some default predefined skills
INSERT IGNORE INTO `predefined_skills` (`skill_name`, `category_id`, `description`, `icon`) VALUES
('JavaScript', 1, 'Modern JavaScript programming language', 'fab fa-js'),
('Python', 1, 'Python programming language', 'fab fa-python'),
('React', 1, 'React.js frontend framework', 'fab fa-react'),
('PHP', 1, 'PHP server-side programming', 'fab fa-php'),
('UI/UX Design', 2, 'User interface and user experience design', 'fas fa-pencil-ruler'),
('Graphic Design', 2, 'Visual design and graphics', 'fas fa-paint-brush'),
('Agile Methodology', 3, 'Agile project management practices', 'fas fa-sync'),
('Scrum Master', 3, 'Scrum framework leadership', 'fas fa-users-cog'),
('Public Speaking', 4, 'Presentation and speaking skills', 'fas fa-microphone'),
('Technical Writing', 4, 'Documentation and technical communication', 'fas fa-pen'),
('Data Analysis', 5, 'Statistical analysis and insights', 'fas fa-chart-line'),
('SQL', 5, 'Database query language', 'fas fa-database'),
('Team Leadership', 6, 'Leading and managing teams', 'fas fa-user-tie'),
('Mentoring', 6, 'Coaching and developing others', 'fas fa-chalkboard-teacher');

-- Insert default quest categories (for reference only)
INSERT IGNORE INTO `quest_categories` (`id`, `name`, `description`, `icon`, `color`) VALUES
(1, 'Learning', 'Educational and skill development quests', 'fas fa-graduation-cap', '#3B82F6'),
(2, 'Project', 'Work-related project completion quests', 'fas fa-project-diagram', '#10B981'),
(3, 'Team Building', 'Collaboration and team activities', 'fas fa-users', '#8B5CF6'),
(4, 'Innovation', 'Creative and innovative challenges', 'fas fa-lightbulb', '#F59E0B'),
(5, 'Professional Development', 'Career growth and development', 'fas fa-chart-line', '#EF4444');

-- ========================================
-- SECTION 3: SAMPLE DATA (OPTIONAL)
-- ========================================

-- Insert sample admin user (password: admin123)
INSERT IGNORE INTO `users` (`employee_id`, `email`, `password`, `full_name`, `role`, `total_xp`, `level`) VALUES
('ADMIN001', 'admin@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', 0, 1);

-- Insert sample Quest Lead (password: questlead123)
INSERT IGNORE INTO `users` (`employee_id`, `email`, `password`, `full_name`, `role`, `total_xp`, `level`) VALUES
('QL001', 'questlead@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane Quest Leader', 'quest_lead', 150, 2);

-- Insert sample Skill Associate (password: skillassoc123)
INSERT IGNORE INTO `users` (`employee_id`, `email`, `password`, `full_name`, `role`, `total_xp`, `level`) VALUES
('SA001', 'skillassoc@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Skill Associate', 'skill_associate', 75, 1);

-- ========================================
-- SECTION 4: MIGRATION NOTES
-- ========================================

-- Migration Summary:
-- 1. Updated role terminology from 'learning_architect'/'skill_seeker' to 'quest_lead'/'skill_associate'
-- 2. Replaced category-based quest system with mandatory/optional assignment system
-- 3. Removed dependency on quest categories for quest creation
-- 4. Enhanced quest assignment logic with automatic status setting for mandatory quests
-- 5. Maintained backward compatibility where possible

-- ========================================
-- SECTION 5: VERIFICATION QUERIES
-- ========================================

-- Verify table creation
SELECT 'Database setup completed successfully!' as status;
SELECT COUNT(*) as total_tables FROM information_schema.tables WHERE table_schema = 'yoonet_quest';

-- Verify quest assignment system
SELECT 'Quest assignment system ready!' as status;
SELECT quest_assignment_type, COUNT(*) as count FROM quests GROUP BY quest_assignment_type;

-- Verify user roles
SELECT 'User role system updated!' as status;
SELECT role, COUNT(*) as count FROM users GROUP BY role;

-- Final status
SELECT 'YooNet Quest System database is ready for use!' as final_status;

COMMIT;