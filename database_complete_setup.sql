-- =============================================================================
-- YooNet Quest System - Complete Database Setup Script
-- =============================================================================
-- This script contains all necessary database schema and data updates
-- Run this script on a fresh MySQL database to set up the complete system
-- =============================================================================

-- Create the main users table (if it doesn't exist)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('skill_seeker', 'learning_architect') DEFAULT 'skill_seeker',
    profile_photo VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    preferred_role ENUM('skill_seeker', 'learning_architect') DEFAULT NULL,
    quest_interests TEXT DEFAULT NULL,
    availability ENUM('full_time', 'part_time', 'casual', 'project_based') DEFAULT NULL,
    profile_completed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create quests table
CREATE TABLE IF NOT EXISTS quests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    category_id INT,
    giver_id VARCHAR(50) NOT NULL,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
    points INT DEFAULT 0,
    deadline DATE,
    recurrence_type ENUM('none', 'daily', 'weekly', 'monthly') DEFAULT 'none',
    recurrence_end DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (giver_id) REFERENCES users(employee_id) ON DELETE CASCADE
);

-- Create quest_categories table
CREATE TABLE IF NOT EXISTS quest_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(50) DEFAULT 'fas fa-folder',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create user_quests table (tracks quest assignments and submissions)
CREATE TABLE IF NOT EXISTS user_quests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    quest_id INT NOT NULL,
    status ENUM('assigned', 'in_progress', 'submitted', 'completed', 'rejected') DEFAULT 'assigned',
    submission_file VARCHAR(255) DEFAULT NULL,
    submission_text TEXT DEFAULT NULL,
    submitted_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    points_earned INT DEFAULT 0,
    feedback TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE,
    FOREIGN KEY (quest_id) REFERENCES quests(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_quest (employee_id, quest_id)
);

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

-- Create predefined_skills table
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

-- Create user_earned_skills table for skill progression system
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

-- Create groups table (if using group functionality)
CREATE TABLE IF NOT EXISTS groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(employee_id) ON DELETE CASCADE
);

-- Create group_members table
CREATE TABLE IF NOT EXISTS group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    employee_id VARCHAR(50) NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE,
    UNIQUE KEY unique_group_member (group_id, employee_id)
);

-- =============================================================================
-- DEFAULT DATA INSERTION
-- =============================================================================

-- Insert default quest categories
INSERT IGNORE INTO quest_categories (name, icon) VALUES
('Technical Skills', 'fas fa-code'),
('Creative Projects', 'fas fa-palette'),
('Research & Analysis', 'fas fa-search'),
('Communication', 'fas fa-comments'),
('Leadership', 'fas fa-users'),
('Professional Development', 'fas fa-chart-line'),
('Problem Solving', 'fas fa-lightbulb'),
('Documentation', 'fas fa-file-alt');

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

-- Insert sample predefined skills
INSERT IGNORE INTO predefined_skills (category_id, skill_name, skill_description, display_order) VALUES
-- Technical Skills (category_id = 1)
(1, 'JavaScript', 'Programming language for web development', 1),
(1, 'Python', 'General-purpose programming language', 2),
(1, 'PHP', 'Server-side scripting language', 3),
(1, 'SQL', 'Database query language', 4),
(1, 'HTML/CSS', 'Web markup and styling languages', 5),
(1, 'React', 'JavaScript library for building user interfaces', 6),
(1, 'Node.js', 'JavaScript runtime for server-side development', 7),

-- Design Skills (category_id = 2)
(2, 'UI/UX Design', 'User interface and experience design', 1),
(2, 'Graphic Design', 'Visual communication design', 2),
(2, 'Adobe Photoshop', 'Image editing and manipulation', 3),
(2, 'Adobe Illustrator', 'Vector graphics design', 4),
(2, 'Figma', 'Collaborative design tool', 5),
(2, 'Web Design', 'Website layout and visual design', 6),

-- Communication Skills (category_id = 3)
(3, 'Public Speaking', 'Presenting to audiences effectively', 1),
(3, 'Technical Writing', 'Clear documentation and instruction writing', 2),
(3, 'Team Collaboration', 'Working effectively in team environments', 3),
(3, 'Client Communication', 'Professional client interaction skills', 4),
(3, 'Cross-cultural Communication', 'Communicating across cultural boundaries', 5),

-- Leadership Skills (category_id = 4)
(4, 'Team Management', 'Leading and managing teams effectively', 1),
(4, 'Strategic Planning', 'Long-term planning and vision development', 2),
(4, 'Conflict Resolution', 'Resolving disputes and conflicts', 3),
(4, 'Mentoring', 'Guiding and developing others', 4),
(4, 'Decision Making', 'Making effective decisions under pressure', 5);

-- =============================================================================
-- ROLE MIGRATION (for existing systems)
-- =============================================================================

-- Update any existing legacy roles to new role names
-- This ensures compatibility if upgrading from an older version
UPDATE users SET role = 'skill_seeker' WHERE role IN ('participant', 'quest_taker');
UPDATE users SET role = 'learning_architect' WHERE role IN ('contributor', 'hybrid', 'quest_giver');

-- Update preferred_role as well
UPDATE users SET preferred_role = 'skill_seeker' WHERE preferred_role IN ('participant', 'quest_taker');
UPDATE users SET preferred_role = 'learning_architect' WHERE preferred_role IN ('contributor', 'hybrid', 'quest_giver');

-- =============================================================================
-- VERIFICATION QUERIES
-- =============================================================================

-- Verify table creation
SELECT 'Database setup completed successfully!' as message;

-- Show table counts
SELECT 
    'Tables created:' as info,
    (SELECT COUNT(*) FROM information_schema.tables 
     WHERE table_schema = DATABASE() 
     AND table_name IN ('users', 'quests', 'quest_categories', 'user_quests', 'user_skills', 
                        'user_achievements', 'skill_categories', 'predefined_skills', 
                        'user_earned_skills', 'quest_skills', 'quest_completions', 
                        'groups', 'group_members')) as table_count;

-- Show default data counts
SELECT 'Quest Categories inserted:' as info, COUNT(*) as count FROM quest_categories;
SELECT 'Skill Categories inserted:' as info, COUNT(*) as count FROM skill_categories;
SELECT 'Predefined Skills inserted:' as info, COUNT(*) as count FROM predefined_skills;

-- Show current user roles (if any users exist)
SELECT 'Current user roles:' as info;
SELECT role, COUNT(*) as count FROM users GROUP BY role;

-- =============================================================================
-- SETUP COMPLETE
-- =============================================================================
SELECT '============================================' as message;
SELECT 'YooNet Quest System Database Setup Complete' as message;
SELECT '============================================' as message;
SELECT 'You can now use the application!' as message;