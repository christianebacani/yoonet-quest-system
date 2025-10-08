# YooNet Quest System

A modern gamified task management and skill development platform that allows users to create, assign, and complete quests while tracking their skill progression.

## Features

### 🎯 Quest Management
- **Create & Assign Quests**: Quest Leads can create quests and assign them to Skill Associates
- **Assignment Types**: Choose between Mandatory (auto-assigned) or Optional (requires acceptance)
- **Smart Assignment**: Mandatory quests automatically appear in user's active list
- **Submission System**: File and text-based quest submissions with feedback
- **Progress Tracking**: Real-time quest status updates and completion tracking

### 👥 User Roles
- **Skill Associates**: Can accept, complete, and submit quests
- **Quest Leads**: Can create and manage quests + all Skill Associate capabilities
- **Admin**: Full system access and user management

### 📈 Skill Development
- **Skill Tracking**: Monitor individual skill progression
- **Achievement System**: Earn points and achievements
- **Skill Categories**: Organized skill development paths
- **Proficiency Levels**: Track skill advancement over time

### 🎨 User Experience
- **Responsive Design**: Works on desktop and mobile devices
- **Profile Management**: Customizable user profiles with photos
- **Theme Support**: Light/dark mode options
- **Interactive Dashboard**: Comprehensive quest and progress overview

## Quick Start

### Prerequisites
- **Web Server**: Apache/Nginx with PHP 7.4+
- **Database**: MySQL 5.7+ or MariaDB 10.2+
- **PHP Extensions**: PDO, MySQL, GD (for image handling)

### Installation

1. **Clone/Download the project**
   ```bash
   git clone https://github.com/christianebacani/yoonet-quest-system.git
   cd yoonet-quest-system
   ```

2. **One-Click Database Setup** ⚡
   ```sql
   -- Create database and import everything in one step
   mysql -u root -p < complete_database_setup.sql
   ```
   
   Or using phpMyAdmin:
   - Create database `yoonet_quest` 
   - Import `complete_database_setup.sql`

3. **Verify Setup** ✅
   ```bash
   # Visit the verification page
   http://localhost/yoonet-quest-system/verify_setup.php
   ```

4. **Configure Database Connection**
   Edit `includes/config.php` with your database credentials:
   ```php
   $host = 'localhost';
   $dbname = 'yoonet_quest';
   $username = 'your_username';
   $password = 'your_password';
   ```

### Sample Accounts (Ready to Use)
| Role | Employee ID | Email | Password |
|------|-------------|--------|----------|
| Admin | ADMIN001 | admin@yoonet.com | admin123 |
| Quest Lead | QL001 | questlead@yoonet.com | questlead123 |
| Skill Associate | SA001 | skillassoc@yoonet.com | skillassoc123 |

## ✨ What's New in Version 2.0

### 🚀 **Simplified Quest Assignment**
- **Mandatory Quests**: Automatically assigned to users (no acceptance needed)
- **Optional Quests**: Users can accept or decline
- **No More Categories**: Streamlined quest creation process

### 👥 **Updated Role Terminology**
- `Learning Architect` → `Quest Lead`
- `Skill Seeker` → `Skill Associate`  
- Clear role-based permissions

### 🛠️ **One-Click Database Setup**
- Single `complete_database_setup.sql` file
- No complex migration steps
- All sample data included
- Perfect for developers joining the project

📋 See [DATABASE_SETUP_GUIDE.md](DATABASE_SETUP_GUIDE.md) for detailed instructions

4. **Set File Permissions**
   ```bash
   chmod 755 uploads/
   chmod 755 uploads/profile_photos/
   chmod 755 uploads/quest_submissions/
   ```

5. **Access the System**
   - Open `http://your-domain/yoonet-quest-system/` in your browser
   - Register a new account or use the login system

## File Structure

```
yoonet-quest-system/
├── 📄 index.php                 # Landing page
├── 📄 dashboard.php             # Main dashboard
├── 📄 register.php              # User registration
├── 📄 login.php                 # User authentication
├── 📄 profile_setup.php         # Profile configuration
├── 📄 create_quest.php          # Quest creation
├── 📄 edit_quest.php            # Quest editing
├── 📄 leaderboard.php           # Progress rankings
├── 📁 includes/
│   ├── 📄 config.php            # Database configuration
│   ├── 📄 functions.php         # Helper functions
│   └── 📄 auth.php              # Authentication logic
├── 📁 assets/
│   ├── 📁 css/                  # Stylesheets
│   ├── 📁 js/                   # JavaScript files
│   └── 📁 images/               # Static images
├── 📁 uploads/
│   ├── 📁 profile_photos/       # User profile images
│   └── 📁 quest_submissions/    # Quest submission files
└── 📁 Database Files/
    ├── 📄 database_complete_setup.sql         # Complete setup (new installations)
    ├── 📄 database_consolidated_updates.sql   # All updates combined (existing systems)
    ├── 📄 database_updates.sql               # Profile & skills (individual)
    ├── 📄 skill_progression_setup.sql        # Skill progression (individual)
    └── 📄 role_rename_migration.sql          # Role updates (individual)
```

## Database Setup Options

### 🆕 New Installation (Recommended)
Use the complete setup file for fresh installations:
```sql
SOURCE database_complete_setup.sql;
```

### 🔄 Upgrading Existing System
For existing databases, use the consolidated update file:
```sql
SOURCE database_consolidated_updates.sql;
```

Or apply individual updates if needed:
```sql
SOURCE database_updates.sql;
SOURCE skill_progression_setup.sql;
SOURCE role_rename_migration.sql;
```

## User Roles & Permissions

| Role | Description | Permissions |
|------|-------------|-------------|
| **Skill Seeker** | Quest participants | ✅ Accept quests<br>✅ Submit completions<br>✅ View progress<br>✅ Manage profile |
| **Learning Architect** | Quest creators | ✅ All Skill Seeker permissions<br>✅ Create quests<br>✅ Edit/delete quests<br>✅ Review submissions<br>✅ Create user accounts |

## Configuration

### Database Configuration
Edit `includes/config.php`:
```php
$host = 'localhost';        // Database host
$dbname = 'your_database';  // Database name
$username = 'your_user';    // Database username
$password = 'your_pass';    // Database password
```

### File Upload Settings
Adjust upload limits in `php.ini` if needed:
```ini
upload_max_filesize = 50M
post_max_size = 50M
max_file_uploads = 20
```

## Security Features
- 🔐 Password hashing with PHP's `password_hash()`
- 🛡️ SQL injection prevention with prepared statements
- 🧹 Input sanitization for all user data
- 📝 File upload validation and type checking
- 🔒 Session-based authentication
- 🚫 Role-based access control

## Development

### Adding New Features
1. Follow the existing code structure
2. Use prepared statements for database queries
3. Sanitize all user inputs
4. Implement proper error handling
5. Update database schema if needed

### Contributing
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## Troubleshooting

### Common Issues

**Database Connection Error**
- Verify database credentials in `includes/config.php`
- Ensure MySQL service is running
- Check database permissions

**File Upload Issues**
- Verify `uploads/` directory permissions (755)
- Check PHP upload settings
- Ensure sufficient disk space

**Role/Permission Problems**
- Run the complete database setup script
- Verify user roles in the database
- Check session data

### Getting Help
1. Check the [DATABASE_SETUP_README.md](DATABASE_SETUP_README.md) for database issues
2. Review the PHP error logs
3. Verify all prerequisites are installed
4. Check file and directory permissions

## License
This project is open source. Feel free to use and modify according to your needs.

## Version History
- **v2.0** - Role system update (Skill Seekers & Learning Architects)
- **v1.5** - Added skill progression system
- **v1.0** - Initial release with basic quest management

---
**Note**: This system is designed for internal organizational use and includes features for educational institutions, corporate training, and team development programs.