-- SQL Script to add profile setup functionality to YooNet Quest System
-- Run these queries in your MySQL database

-- Add new columns to users table for profile completion
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS bio TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS preferred_role ENUM('quest_taker', 'quest_giver', 'hybrid') DEFAULT NULL,
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
    id INT AUTO_INCREMENT PRIMARY KEY-tools',
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    category_name VARCHAR(50) NOT NULL UNIQUE,
    category_icon VARCHAR(50) DEFAULT 'fas fa
);

-- Insert default skill categories
INSERT IGNORE INTO skill_categories (category_name, category_icon, display_order) VALUES
('Technical Skills', 'fas fa-code', 1),
('Design Skills', 'fas fa-palette', 2),
('Business Skills', 'fas fa-chart-line', 3),
('Soft Skills', 'fas fa-heart', 4);

-- Create predefined_skills table for skill suggestions
CREATE TABLE IF NOT EXISTS predefined_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    skill_name VARCHAR(100) NOT NULL UNIQUE,
    category_id INT NOT NULL,
    description TEXT,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES skill_categories(id) ON DELETE CASCADE
);

-- Insert predefined skills
INSERT IGNORE INTO predefined_skills (skill_name, category_id, display_order) VALUES
-- Technical Skills (category_id = 1)
('PHP', 1, 1), ('JavaScript', 1, 2), ('Python', 1, 3), ('Java', 1, 4), ('C++', 1, 5),
('HTML/CSS', 1, 6), ('SQL', 1, 7), ('React', 1, 8), ('Vue.js', 1, 9), ('Node.js', 1, 10),
('Laravel', 1, 11), ('WordPress', 1, 12), ('Git', 1, 13), ('Docker', 1, 14), ('AWS', 1, 15),
('Linux', 1, 16), ('MongoDB', 1, 17), ('MySQL', 1, 18),

-- Design Skills (category_id = 2)
('UI/UX Design', 2, 1), ('Graphic Design', 2, 2), ('Adobe Photoshop', 2, 3), ('Adobe Illustrator', 2, 4),
('Figma', 2, 5), ('Sketch', 2, 6), ('InDesign', 2, 7), ('After Effects', 2, 8), ('Blender', 2, 9),
('3D Modeling', 2, 10), ('Typography', 2, 11), ('Branding', 2, 12),

-- Business Skills (category_id = 3)
('Project Management', 3, 1), ('Team Leadership', 3, 2), ('Strategic Planning', 3, 3), ('Data Analysis', 3, 4),
('Marketing', 3, 5), ('Sales', 3, 6), ('Customer Service', 3, 7), ('Public Speaking', 3, 8),
('Negotiation', 3, 9), ('Financial Analysis', 3, 10),

-- Soft Skills (category_id = 4)
('Communication', 4, 1), ('Problem Solving', 4, 2), ('Critical Thinking', 4, 3), ('Creativity', 4, 4),
('Adaptability', 4, 5), ('Time Management', 4, 6), ('Teamwork', 4, 7), ('Leadership', 4, 8),
('Emotional Intelligence', 4, 9), ('Mentoring', 4, 10);

-- Create user_profile_views table for tracking profile visits
CREATE TABLE IF NOT EXISTS user_profile_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_user_id INT NOT NULL,
    viewer_user_id INT,
    view_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (viewer_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_user_skills_user_id ON user_skills(user_id);
CREATE INDEX IF NOT EXISTS idx_user_skills_skill_name ON user_skills(skill_name);
CREATE INDEX IF NOT EXISTS idx_user_achievements_user_id ON user_achievements(user_id);
CREATE INDEX IF NOT EXISTS idx_user_achievements_type ON user_achievements(achievement_type);
CREATE INDEX IF NOT EXISTS idx_predefined_skills_category ON predefined_skills(category_id);
CREATE INDEX IF NOT EXISTS idx_profile_views_profile_user ON user_profile_views(profile_user_id);

-- Add sample user data (optional - for testing)
-- UPDATE users SET profile_completed = FALSE WHERE profile_completed IS NULL;

-- Display current table structure
SELECT 'Users table columns:' as info;
DESCRIBE users;

SELECT 'User Skills table structure:' as info;
DESCRIBE user_skills;

SELECT 'Skill Categories:' as info;
SELECT * FROM skill_categories ORDER BY display_order;

SELECT 'Sample Predefined Skills:' as info;
SELECT sc.category_name, ps.skill_name 
FROM predefined_skills ps 
JOIN skill_categories sc ON ps.category_id = sc.id 
ORDER BY sc.display_order, ps.display_order 
LIMIT 20;