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
CREATE PROCEDURE UpdateSkillStatus()
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