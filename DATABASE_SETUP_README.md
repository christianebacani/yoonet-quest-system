# YooNet Quest System - Database Setup Guide

## Overview
This directory contains SQL scripts for setting up and managing the YooNet Quest System database.

## Setup Options

### Option 1: Fresh Installation (Recommended for new users)
**Use this for setting up the system for the first time**

```sql
-- Run this single file to set up everything:
database_complete_setup.sql
```

This file includes:
- ✅ All table creation statements
- ✅ Default categories and sample data
- ✅ Proper indexes and foreign keys
- ✅ Role migration for compatibility
- ✅ Verification queries

### Option 2: Incremental Updates (For existing systems)
**Use these if you already have an existing database**

1. **For ALL updates at once (RECOMMENDED):**
   ```sql
   database_consolidated_updates.sql
   ```
   This single file combines all existing updates in the correct order:
   - Profile & skill system features
   - Skill progression tracking
   - Role name changes (Contributor → Learning Architect, Participant → Skill Seeker)

2. **For individual updates (if you need specific features only):**
   ```sql
   database_updates.sql           -- Profile & skill system
   skill_progression_setup.sql    -- Skill progression tracking
   role_rename_migration.sql      -- Role name changes only
   ```

## Database Requirements
- MySQL 5.7+ or MariaDB 10.2+
- Database collation: `utf8mb4_unicode_ci` (recommended)

## Setup Instructions

### Step 1: Create Database
```sql
CREATE DATABASE yoonet_quest_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE yoonet_quest_system;
```

### Step 2: Run Setup Script
```sql
SOURCE database_complete_setup.sql;
```

Or copy and paste the contents of `database_complete_setup.sql` into your MySQL client.

### Step 3: Verify Setup
After running the script, you should see:
- ✅ "Database setup completed successfully!" message
- ✅ Table count confirmation
- ✅ Default data insertion counts
- ✅ Current user roles summary

## File Descriptions

| File | Purpose | When to Use |
|------|---------|-------------|
| `database_complete_setup.sql` | Complete database setup | Fresh installations |
| `database_consolidated_updates.sql` | **All existing updates combined** | **Upgrading existing system (RECOMMENDED)** |
| `database_updates.sql` | Profile & skill features | Individual update (legacy) |
| `skill_progression_setup.sql` | Skill progression system | Individual update (legacy) |
| `role_rename_migration.sql` | Role name changes | Individual update (legacy) |

## Database Schema Overview

### Core Tables
- **users** - User accounts and profiles
- **quests** - Quest definitions
- **user_quests** - Quest assignments and submissions
- **quest_types** - Quest types (e.g. custom, client_support)

### Skill System Tables
- **user_skills** - User skill declarations
- **user_earned_skills** - Skill progression tracking
- **skill_categories** - Skill categorization
- **predefined_skills** - Available skills list
- **quest_skills** - Skills involved in quests

### Additional Tables
- **user_achievements** - Achievement tracking
- **quest_completions** - Completion records
- **groups** - User groups (optional)
- **group_members** - Group membership

## Default Roles
The system uses two main roles:

| Role | Database Value | Permissions |
|------|---------------|-------------|
| **Skill Seeker** | `skill_seeker` | Can accept and complete quests |
| **Learning Architect** | `learning_architect` | Can create quests + all Skill Seeker permissions |

## Troubleshooting

### Common Issues

1. **Foreign key constraints fail**
   - Ensure you run the complete setup script in order
   - Check that your MySQL version supports foreign keys

2. **Character encoding issues**
   - Make sure your database uses `utf8mb4` charset
   - Set `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`

3. **Permission errors**
   - Ensure your MySQL user has CREATE, ALTER, INSERT privileges
   - For production, create a dedicated database user

### Verification Queries
```sql
-- Check if all tables exist
SHOW TABLES;

-- Verify default data
SELECT COUNT(*) FROM quest_types;
SELECT COUNT(*) FROM skill_categories;
SELECT COUNT(*) FROM predefined_skills;

-- Check user roles (after adding users)
SELECT role, COUNT(*) FROM users GROUP BY role;
```

## Backup Recommendations
Before running any migration scripts on existing data:
```sql
-- Create backup
mysqldump -u username -p yoonet_quest_system > backup_before_migration.sql
```

## Support
If you encounter issues:
1. Check the verification queries in the setup script output
2. Review the MySQL error log
3. Ensure all prerequisites are met
4. Create a database backup before making changes