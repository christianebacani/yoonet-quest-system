-- =============================================================================
-- YooNet Quest System - Consolidated Database Updates
-- =============================================================================
-- This file combines all existing database update scripts in the correct order:
-- 1. database_updates.sql (Profile & Skills system)
-- 2. skill_progression_setup.sql (Skill progression tracking)
-- 3. role_rename_migration.sql (Role name updates)
-- 
-- Run this single file to apply all updates to an existing YooNet Quest System
-- =============================================================================

-- =============================================================================
-- PART 1: Profile Setup & Skills System (from database_updates.sql)
-- =============================================================================

-- SQL Script to add profile setup functionality to YooNet Quest System
-- Run these queries in your MySQL database

-- Add new columns to users table for profile completion
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS bio TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS preferred_role ENUM('participant', 'contributor') DEFAULT NULL,
ADD COLUMN IF NOT EXISTS quest_interests TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS availability ENUM('full_time', 'part_time', 'casual', 'project_based') DEFAULT NULL,
ADD COLUMN IF NOT EXISTS profile_completed BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Create user_skills table for storing user skills and proficiency levels
CREATE TABLE IF NOT EXISTS user_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    skill_level TINYINT DEFAULT 1 CHECK (skill_level BETWEEN 1 AND 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_skill (user_id, skill_name)
);

-- Create user_achievements table for tracking quest accomplishments
CREATE TABLE IF NOT EXISTS user_achievements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    achievement_type ENUM('quest_completed', 'skill_level_up', 'milestone_reached', 'badge_earned') NOT NULL,
    achievement_name VARCHAR(100) NOT NULL,
    achievement_description TEXT,
    points_earned INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create skill_categories table for organized skill management
CREATE TABLE IF NOT EXISTS skill_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    category_name VARCHAR(50) NOT NULL UNIQUE,
    category_icon VARCHAR(50) DEFAULT 'fas fa-cube'
);

-- Insert default skill categories
INSERT IGNORE INTO skill_categories (category_name, category_icon, display_order) VALUES
('Technical Skills', 'fas fa-code', 1),
('Design Skills', 'fas fa-palette', 2),
('Communication Skills', 'fas fa-comments', 3),
('Leadership Skills', 'fas fa-users', 4),
('Research Skills', 'fas fa-search', 5),
('Creative Skills', 'fas fa-lightbulb', 6),
('Analytical Skills', 'fas fa-chart-bar', 7),
('Project Management', 'fas fa-tasks', 8);

-- Create predefined_skills table for standard skills library
CREATE TABLE IF NOT EXISTS predefined_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    skill_description TEXT,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES skill_categories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_category_skill (category_id, skill_name)
);

-- Insert sample predefined skills
INSERT IGNORE INTO predefined_skills (category_id, skill_name, skill_description, display_order) VALUES
-- Technical Skills (category_id = 1)
(1, 'JavaScript', 'Client-side and server-side programming', 1),
(1, 'Python', 'General-purpose programming language', 2),
(1, 'PHP', 'Server-side web development', 3),
(1, 'SQL', 'Database management and queries', 4),
(1, 'HTML/CSS', 'Web markup and styling', 5),
(1, 'React', 'Frontend JavaScript framework', 6),
(1, 'Node.js', 'JavaScript runtime environment', 7),
(1, 'Git/GitHub', 'Version control systems', 8),
(1, 'Docker', 'Containerization platform', 9),
(1, 'AWS', 'Amazon Web Services cloud platform', 10),

-- Design Skills (category_id = 2)
(2, 'UI/UX Design', 'User interface and experience design', 1),
(2, 'Graphic Design', 'Visual communication design', 2),
(2, 'Adobe Photoshop', 'Image editing and manipulation', 3),
(2, 'Adobe Illustrator', 'Vector graphics design', 4),
(2, 'Figma', 'Collaborative design tool', 5),
(2, 'Sketch', 'Digital design toolkit', 6),
(2, 'InDesign', 'Desktop publishing software', 7),
(2, 'Web Design', 'Website visual design', 8),

-- Communication Skills (category_id = 3)
(3, 'Public Speaking', 'Presenting to audiences', 1),
(3, 'Technical Writing', 'Documentation and technical communication', 2),
(3, 'Team Collaboration', 'Working effectively in teams', 3),
(3, 'Client Communication', 'Professional client interaction', 4),
(3, 'Cross-cultural Communication', 'Multicultural interaction skills', 5),
(3, 'Presentation Skills', 'Creating and delivering presentations', 6),

-- Leadership Skills (category_id = 4)
(4, 'Team Management', 'Leading and managing teams', 1),
(4, 'Strategic Planning', 'Long-term planning and vision', 2),
(4, 'Conflict Resolution', 'Resolving disputes and conflicts', 3),
(4, 'Mentoring', 'Guiding and developing others', 4),
(4, 'Decision Making', 'Effective decision-making processes', 5),
(4, 'Change Management', 'Leading organizational change', 6);

-- Create user_profile_views table for tracking profile visits
CREATE TABLE IF NOT EXISTS user_profile_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_user_id INT NOT NULL,
    viewer_user_id INT NOT NULL,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (viewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_profile_user (profile_user_id),
    INDEX idx_viewer_user (viewer_user_id)
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_user_skills_user_id ON user_skills(user_id);
CREATE INDEX IF NOT EXISTS idx_user_skills_skill_name ON user_skills(skill_name);
CREATE INDEX IF NOT EXISTS idx_user_achievements_user_id ON user_achievements(user_id);
CREATE INDEX IF NOT EXISTS idx_user_achievements_type ON user_achievements(achievement_type);
CREATE INDEX IF NOT EXISTS idx_predefined_skills_category ON predefined_skills(category_id);
CREATE INDEX IF NOT EXISTS idx_profile_views_profile_user ON user_profile_views(profile_user_id);

-- =============================================================================
-- PART 2: Skill Progression System (from skill_progression_setup.sql)
-- =============================================================================

-- SQL to set up the new skill progression system
-- Run this to create the table for tracking user earned skills

-- Create the user_earned_skills table
CREATE TABLE IF NOT EXISTS user_earned_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    total_points INT DEFAULT 0,
    current_level INT DEFAULT 1,
    current_stage VARCHAR(20) DEFAULT 'Learning',
    last_used TIMESTAMP NULL,
    recent_points INT DEFAULT 0,
    status ENUM('ACTIVE', 'STALE', 'RUSTY') DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_skill (user_id, skill_name),
    INDEX idx_user_id (user_id),
    INDEX idx_skill_name (skill_name),
    INDEX idx_status (status)
);

-- Create quest_skills table to define what skills are involved in each quest
CREATE TABLE IF NOT EXISTS quest_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quest_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    base_points INT DEFAULT 25,
    tier_level ENUM('T1', 'T2', 'T3', 'T4', 'T5') DEFAULT 'T1',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_quest_id (quest_id),
    INDEX idx_skill_name (skill_name)
);

-- Create quest_completions table to track when users complete quests
CREATE TABLE IF NOT EXISTS quest_completions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quest_id INT NOT NULL,
    user_id INT NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_points_awarded INT DEFAULT 0,
    notes TEXT,
    
    UNIQUE KEY unique_quest_completion (quest_id, user_id),
    INDEX idx_quest_id (quest_id),
    INDEX idx_user_id (user_id),
    INDEX idx_completed_at (completed_at)
);

-- Function to update skill status based on last_used (you can call this periodically)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS UpdateSkillStatus()
BEGIN
    -- Mark skills as STALE if not used in 30 days
    UPDATE user_earned_skills 
    SET status = 'STALE' 
    WHERE last_used < DATE_SUB(NOW(), INTERVAL 30 DAY) 
    AND last_used >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND status = 'ACTIVE';
    
    -- Mark skills as RUSTY if not used in 90 days
    UPDATE user_earned_skills 
    SET status = 'RUSTY' 
    WHERE last_used < DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND status IN ('ACTIVE', 'STALE');
END //
DELIMITER ;

-- =============================================================================
-- PART 3: Role System Updates (from role_rename_migration.sql)
-- =============================================================================

-- First update the ENUM values in preferred_role column
-- Update preferred_role ENUM to include new values temporarily
ALTER TABLE users MODIFY COLUMN preferred_role ENUM('participant', 'contributor', 'skill_seeker', 'learning_architect') DEFAULT NULL;

-- Update existing preferred_role data
UPDATE users SET preferred_role = 'learning_architect' WHERE preferred_role = 'contributor';
UPDATE users SET preferred_role = 'skill_seeker' WHERE preferred_role = 'participant';

-- Now update the main role column
-- Add temporary column with new ENUM values
ALTER TABLE users ADD COLUMN new_role ENUM('skill_seeker', 'learning_architect') DEFAULT NULL;

-- Copy data to new column with role mapping
UPDATE users SET new_role = 'learning_architect' WHERE role = 'contributor';
UPDATE users SET new_role = 'skill_seeker' WHERE role = 'participant';
UPDATE users SET new_role = 'learning_architect' WHERE role = 'hybrid';
UPDATE users SET new_role = 'learning_architect' WHERE role = 'quest_giver';
UPDATE users SET new_role = 'skill_seeker' WHERE role = 'quest_taker';

-- Drop old role column and rename new one
ALTER TABLE users DROP COLUMN role;
ALTER TABLE users CHANGE COLUMN new_role role ENUM('skill_seeker', 'learning_architect') DEFAULT 'skill_seeker';

-- Update preferred_role ENUM to only include new values
ALTER TABLE users MODIFY COLUMN preferred_role ENUM('skill_seeker', 'learning_architect') DEFAULT NULL;

-- =============================================================================
-- VERIFICATION & COMPLETION
-- =============================================================================

-- Display table structure information
SELECT 'Profile & Skills system setup completed!' as message;

-- Show skill categories
SELECT 'Skill Categories:' as info;
SELECT category_name, category_icon, display_order FROM skill_categories ORDER BY display_order;

-- Show sample predefined skills
SELECT 'Sample Predefined Skills (first 10):' as info;
SELECT sc.category_name, ps.skill_name, ps.skill_description
FROM predefined_skills ps 
JOIN skill_categories sc ON ps.category_id = sc.id 
ORDER BY sc.display_order, ps.display_order 
LIMIT 10;

-- Verify role changes
SELECT 'Current user roles after migration:' as info;
SELECT role, COUNT(*) as count FROM users GROUP BY role;

-- Verify preferred roles
SELECT 'Preferred roles distribution:' as info;
SELECT preferred_role, COUNT(*) as count FROM users WHERE preferred_role IS NOT NULL GROUP BY preferred_role;

-- Final completion message
SELECT '========================================' as message;
SELECT 'All database updates completed successfully!' as message;
SELECT '========================================' as message;
SELECT 'New roles: Skill Seeker & Learning Architect' as message;
SELECT 'Profile system: ✓ Enabled' as message;
SELECT 'Skills tracking: ✓ Enabled' as message;
SELECT 'Skill progression: ✓ Enabled' as message;