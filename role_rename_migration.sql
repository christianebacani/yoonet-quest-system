-- SQL Script to rename roles from 'Contributor'/'Participant' to 'Learning Architect'/'Skill Seeker'
-- Run these queries in your MySQL database

-- Update existing role data in users table
UPDATE users SET role = 'learning_architect' WHERE role = 'contributor';
UPDATE users SET role = 'skill_seeker' WHERE role = 'participant';

-- Update existing preferred_role data in users table
UPDATE users SET preferred_role = 'learning_architect' WHERE preferred_role = 'contributor';
UPDATE users SET preferred_role = 'skill_seeker' WHERE preferred_role = 'participant';

-- Drop and recreate the ENUM constraint for role column if it exists
-- Note: This will depend on your current table structure
-- You may need to adjust this based on your actual schema

-- Alternative approach - Add new temporary column, transfer data, drop old, rename new
-- This is safer for production environments

-- Step 1: Add temporary columns with new ENUM values
ALTER TABLE users ADD COLUMN new_role ENUM('skill_seeker', 'learning_architect') DEFAULT NULL;
ALTER TABLE users ADD COLUMN new_preferred_role ENUM('skill_seeker', 'learning_architect') DEFAULT NULL;

-- Step 2: Copy data to new columns with role mapping
UPDATE users SET new_role = 'learning_architect' WHERE role = 'contributor';
UPDATE users SET new_role = 'skill_seeker' WHERE role = 'participant';
UPDATE users SET new_preferred_role = 'learning_architect' WHERE preferred_role = 'contributor';
UPDATE users SET new_preferred_role = 'skill_seeker' WHERE preferred_role = 'participant';

-- Step 3: Drop old columns and rename new ones
ALTER TABLE users DROP COLUMN role;
ALTER TABLE users DROP COLUMN preferred_role;
ALTER TABLE users CHANGE COLUMN new_role role ENUM('skill_seeker', 'learning_architect') DEFAULT NULL;
ALTER TABLE users CHANGE COLUMN new_preferred_role preferred_role ENUM('skill_seeker', 'learning_architect') DEFAULT NULL;

-- Verify the changes
SELECT role, COUNT(*) as count FROM users GROUP BY role;
SELECT preferred_role, COUNT(*) as count FROM users WHERE preferred_role IS NOT NULL GROUP BY preferred_role;

-- Display completion message
SELECT 'Role rename completed successfully!' as message;
SELECT 'Old: Contributor -> New: Learning Architect' as mapping1;
SELECT 'Old: Participant -> New: Skill Seeker' as mapping2;