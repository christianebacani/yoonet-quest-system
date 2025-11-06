# YooNet Quest System - Database Setup Guide

## Quick Setup Instructions

### Prerequisites
- MySQL/MariaDB server running
- Database user with CREATE, DROP, INSERT, UPDATE, DELETE privileges
- phpMyAdmin or MySQL command line access

### Installation Steps

#### Option 1: Using MySQL Command Line
```bash
# 1. Create the database and import the complete setup
mysql -u root -p < complete_database_setup.sql

# 2. Verify installation
mysql -u root -p yoonet_quest -e "SHOW TABLES;"
```

#### Option 2: Using phpMyAdmin
1. Open phpMyAdmin in your browser
2. Create a new database named `yoonet_quest`
3. Select the database
4. Go to the "Import" tab
5. Choose the `complete_database_setup.sql` file
6. Click "Go" to execute

### What This Setup Includes

#### ✅ **Complete Database Schema**
- All 21 tables with proper relationships
- Updated role terminology (Quest Lead/Skill Associate)
- New quest assignment system (mandatory/optional)
- Skill progression system
- XP tracking and leaderboard
- User management and preferences

#### ✅ **Default Data**
- Skill categories (Technical, Design, Project Management, etc.)
- Predefined skills library
- Sample user accounts for testing
 - Quest types (for reference)

#### ✅ **System Features**
- **Quest Assignment Types**:
  - **Mandatory**: Auto-assigned as 'in_progress'
  - **Optional**: Requires user acceptance
- **Role-Based Access**:
  - **Quest Lead**: Can create and manage quests
  - **Skill Associate**: Can accept and complete quests
  - **Admin**: Full system access
- **Skill Progression**: Track and verify user skills
- **XP System**: Points and leveling system
- **Leaderboard**: Competitive rankings

### Sample User Accounts

| Role | Employee ID | Email | Password | Purpose |
|------|-------------|--------|----------|---------|
| Admin | ADMIN001 | admin@yoonet.com | admin123 | System administration |
| Quest Lead | QL001 | questlead@yoonet.com | questlead123 | Quest creation/management |
| Skill Associate | SA001 | skillassoc@yoonet.com | skillassoc123 | Quest participation |

### Configuration

After database setup, update your `includes/config.php` file:

```php
<?php
$host = 'localhost';
$dbname = 'yoonet_quest';
$username = 'root';
$password = 'your_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
```

### Verification

After setup, verify the installation by:

1. **Check Tables**: Ensure all 21 tables are created
2. **Test Login**: Use sample accounts to log in
3. **Create Quest**: Test quest creation with mandatory/optional types
4. **Check Assignment**: Verify quest assignment logic works

### Troubleshooting

#### Common Issues:
- **Permission denied**: Ensure database user has sufficient privileges
- **Table exists**: Drop existing database before importing
- **Foreign key errors**: Import complete file in one transaction

#### Need Help?
- Check the verification queries at the end of the SQL file
- Review the migration notes in Section 4
- Examine the sample data in Section 3

---

**Version**: 2.0 (October 2025)  
**Last Updated**: Quest assignment system overhaul completed