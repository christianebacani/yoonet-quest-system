<?php
// Start session to store user preferences
session_start();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle reset request
    if (isset($_POST['reset'])) {
        session_unset();
        session_destroy();
        session_start();
        
        // Clear all preference cookies
        setcookie('theme', '', time() - 3600, "/");
        setcookie('theme_shade', '', time() - 3600, "/");
        setcookie('notifications', '', time() - 3600, "/");
        setcookie('font_size', '', time() - 3600, "/");
    } else {
        // Save theme preference to session and cookies
        if (isset($_POST['theme'])) {
            $_SESSION['theme'] = $_POST['theme'];
            setcookie('theme', $_POST['theme'], time() + (365 * 24 * 60 * 60), "/");
        }
        
        // Save theme shade preference
        if (isset($_POST['theme_shade'])) {
            $_SESSION['theme_shade'] = $_POST['theme_shade'];
            setcookie('theme_shade', $_POST['theme_shade'], time() + (365 * 24 * 60 * 60), "/");
        }
        
        // Save other settings to session and cookies
        $notifications_value = isset($_POST['notifications']) ? '1' : '0';
        $_SESSION['notifications'] = isset($_POST['notifications']);
        setcookie('notifications', $notifications_value, time() + (365 * 24 * 60 * 60), "/");
        
        if (isset($_POST['font_size'])) {
            $_SESSION['font_size'] = $_POST['font_size'];
            setcookie('font_size', $_POST['font_size'], time() + (365 * 24 * 60 * 60), "/");
        }
        
        // Set success flag for animation
        $_SESSION['settings_saved'] = true;
    }
    
    // Redirect to avoid form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Set default values if not set - check session first, then cookies
$current_theme = $_SESSION['theme'] ?? ($_COOKIE['theme'] ?? 'default');
$current_shade = $_SESSION['theme_shade'] ?? ($_COOKIE['theme_shade'] ?? 'default');
$notifications = isset($_SESSION['notifications']) ? $_SESSION['notifications'] : (isset($_COOKIE['notifications']) ? $_COOKIE['notifications'] === '1' : true);
$font_size = $_SESSION['font_size'] ?? ($_COOKIE['font_size'] ?? 'medium');

// Check if settings were just saved
$show_success = isset($_SESSION['settings_saved']);
unset($_SESSION['settings_saved']);

// Function to get the body class based on theme
function getBodyClass() {
    global $current_theme, $current_shade;
    
    $classes = [];
    
    if ($current_theme !== 'default') {
        $classes[] = $current_theme . '-theme';
        if ($current_shade !== 'default') {
            $classes[] = $current_shade;
        }
    }
    
    return implode(' ', $classes);
}

// Function to get font size CSS
function getFontSize() {
    global $font_size;
    
    switch ($font_size) {
        case 'small': return '14px';
        case 'large': return '18px';
        default: return '16px';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <link rel="stylesheet" href="assets/css/buttons.css">
    <style>
        :root {
            --primary-color: #4285f4;
            --secondary-color: #34a853;
            --background-color: #ffffff;
            --text-color: #333333;
            --card-bg: #f8f9fa;
            --border-color: #e0e0e0;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --transition-speed: 0.4s;
        }

        /* Default Theme */
        .default-theme {
            --primary-color: #4285f4;
            --secondary-color: #34a853;
        }

        /* Ocean Theme */
        .ocean-theme {
            --primary-color: #00a1f1;
            --secondary-color: #00c1d4;
            --background-color: #f0f8ff;
            --text-color: #003366;
            --card-bg: #e1f0fa;
            --border-color: #b3d4ff;
        }

        /* Ocean Theme Shades */
        .ocean-light {
            --primary-color: #66b5f8;
            --secondary-color: #66d1e4;
        }
        .ocean-dark {
            --primary-color: #0077cc;
            --secondary-color: #0091b4;
        }

        /* Forest Theme */
        .forest-theme {
            --primary-color: #228B22;
            --secondary-color: #2E8B57;
            --background-color: #f0fff0;
            --text-color: #013220;
            --card-bg: #e1fae1;
            --border-color: #98fb98;
        }

        /* Forest Theme Shades */
        .forest-light {
            --primary-color: #3cb371;
            --secondary-color: #48d1cc;
        }
        .forest-dark {
            --primary-color: #006400;
            --secondary-color: #1e6b52;
        }

        /* Sunset Theme */
        .sunset-theme {
            --primary-color: #FF6B6B;
            --secondary-color: #FFA07A;
            --background-color: #FFF5E6;
            --text-color: #8B0000;
            --card-bg: #FFE8D6;
            --border-color: #FFB347;
        }

        /* Sunset Theme Shades */
        .sunset-light {
            --primary-color: #ff8c8c;
            --secondary-color: #ffb89a;
        }
        .sunset-dark {
            --primary-color: #e64c4c;
            --secondary-color: #e68a5a;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
            transition: all var(--transition-speed) ease;
            font-size: <?php echo getFontSize(); ?>;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            transition: all var(--transition-speed) ease;
        }

        h1 {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 30px;
            transition: color var(--transition-speed) ease;
        }

        .settings-card {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px var(--shadow-color);
            border: 1px solid var(--border-color);
            transition: all var(--transition-speed) ease;
        }

        .settings-group {
            margin-bottom: 25px;
        }

        .settings-group h2 {
            margin-top: 0;
            color: var(--secondary-color);
            font-size: 1.2rem;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            transition: all var(--transition-speed) ease;
        }

        .option {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px dashed var(--border-color);
            transition: all var(--transition-speed) ease;
        }

        .option:last-child {
            border-bottom: none;
        }

        .option-label {
            font-weight: 500;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--primary-color);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        select {
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: all var(--transition-speed) ease;
        }

        .theme-options {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .theme-option {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px var(--shadow-color);
            position: relative;
        }

        .theme-option:hover {
            transform: scale(1.1);
        }

        .theme-option.selected {
            border-color: var(--primary-color);
            transform: scale(1.1);
            box-shadow: 0 0 0 2px var(--primary-color);
        }

        .theme-option::after {
            content: attr(title);
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--card-bg);
            color: var(--text-color);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
            box-shadow: 0 2px 4px var(--shadow-color);
        }

        .theme-option:hover::after {
            opacity: 1;
        }

        .shade-options {
            display: none;
            margin-top: 10px;
            padding-left: 0;
            justify-content: center;
        }

        .shade-options.active {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .shade-option {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px var(--shadow-color);
            position: relative;
        }

        .shade-option.selected {
            border-color: var(--primary-color);
            transform: scale(1.1);
        }

        .shade-option::after {
            content: attr(title);
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--card-bg);
            color: var(--text-color);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
            box-shadow: 0 1px 3px var(--shadow-color);
        }

        .shade-option:hover::after {
            opacity: 1;
        }

        #defaultTheme { background-color: #ffffff; border: 1px solid #ddd; }
        #oceanTheme { background-color: #00a1f1; }
        #forestTheme { background-color: #228B22; }
        #sunsetTheme { background-color: #FF6B6B; }

        /* Shade colors */
        .ocean-default-shade { background-color: #00a1f1; }
        .ocean-light-shade { background-color: #66b5f8; }
        .ocean-dark-shade { background-color: #0077cc; }

        .forest-default-shade { background-color: #228B22; }
        .forest-light-shade { background-color: #3cb371; }
        .forest-dark-shade { background-color: #006400; }

        .sunset-default-shade { background-color: #FF6B6B; }
        .sunset-light-shade { background-color: #ff8c8c; }
        .sunset-dark-shade { background-color: #e64c4c; }

        .reset-btn {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: all var(--transition-speed) ease;
        }

        .reset-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* Save button specific styles */
        .save-btn {
            background-color: var(--primary-color);
        }

        /* Back button styles - Remove custom styles, use framework */
        .settings-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Animation for theme change */
        @keyframes fadeIn {
            from { opacity: 0.8; }
            to { opacity: 1; }
        }

        .theme-change {
            animation: fadeIn var(--transition-speed) ease;
        }

        /* Success animation */
        @keyframes successPulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(52, 168, 83, 0.7); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(52, 168, 83, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(52, 168, 83, 0); }
        }

        .success-animation {
            animation: successPulse 1s;
        }

        /* Success message */
        .success-message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--secondary-color);
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .success-message.show {
            opacity: 1;
        }
    </style>
</head>
<body class="<?php echo getBodyClass(); ?>">
    <?php if ($show_success): ?>
    <div class="success-message show" id="successMessage">
        Settings saved successfully!
    </div>
    <?php endif; ?>
    
    <div class="container">
        <!-- Navigation Header -->
        <header class="nav-header">
            <div class="nav-left">
                <a href="dashboard.php" class="btn btn-navigation btn-back">
                    <i class="fas fa-arrow-left btn-icon"></i>
                    <span class="btn-text">Back to Dashboard</span>
                </a>
            </div>
            <div class="nav-right">
                <h1 class="nav-title">Settings</h1>
            </div>
        </header>
        
        <div class="settings-container">
        <h1>Preferences</h1>
        
        <form method="POST" action="">
            <div class="settings-card">
                <div class="settings-group">
                    <h2>Theme</h2>
                    
                    <div class="theme-options">
                        <div class="theme-option <?php echo $current_theme === 'default' ? 'selected' : ''; ?>" 
                             id="defaultTheme" 
                             data-theme="default" 
                             title="Default Theme"
                             onclick="selectTheme('default')"></div>
                             
                        <div class="theme-option <?php echo $current_theme === 'ocean' ? 'selected' : ''; ?>" 
                             id="oceanTheme" 
                             data-theme="ocean" 
                             title="Ocean Theme"
                             onclick="selectTheme('ocean')"></div>
                             
                        <div class="theme-option <?php echo $current_theme === 'forest' ? 'selected' : ''; ?>" 
                             id="forestTheme" 
                             data-theme="forest" 
                             title="Forest Theme"
                             onclick="selectTheme('forest')"></div>
                             
                        <div class="theme-option <?php echo $current_theme === 'sunset' ? 'selected' : ''; ?>" 
                             id="sunsetTheme" 
                             data-theme="sunset" 
                             title="Sunset Theme"
                             onclick="selectTheme('sunset')"></div>
                    </div>
                    
                    <!-- Shade options container -->
                    <div id="shadeOptionsContainer">
                        <?php if ($current_theme !== 'default'): ?>
                        <div class="shade-options active" id="<?php echo $current_theme; ?>Shades">
                            <div class="shade-option <?php echo $current_shade === 'default' ? 'selected' : ''; ?> 
                                 <?php echo $current_theme; ?>-default-shade" 
                                 title="Default Shade"
                                 onclick="selectShade('default', '<?php echo $current_theme; ?>')"></div>
                                 
                            <div class="shade-option <?php echo $current_shade === $current_theme.'-light' ? 'selected' : ''; ?> 
                                 <?php echo $current_theme; ?>-light-shade" 
                                 title="Light Shade"
                                 onclick="selectShade('<?php echo $current_theme; ?>-light', '<?php echo $current_theme; ?>')"></div>
                                 
                            <div class="shade-option <?php echo $current_shade === $current_theme.'-dark' ? 'selected' : ''; ?> 
                                 <?php echo $current_theme; ?>-dark-shade" 
                                 title="Dark Shade"
                                 onclick="selectShade('<?php echo $current_theme; ?>-dark', '<?php echo $current_theme; ?>')"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="theme" id="themeInput" value="<?php echo $current_theme; ?>">
                    <input type="hidden" name="theme_shade" id="themeShadeInput" value="<?php echo $current_shade; ?>">
                </div>
            </div>
            
            <div class="settings-card">
                <div class="settings-group">
                    <h2>Other Settings</h2>
                    
                    <div class="option">
                        <span class="option-label">Notifications</span>
                        <label class="toggle-switch">
                            <input type="checkbox" name="notifications" id="notificationsToggle" <?php echo $notifications ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="option">
                        <span class="option-label">Font Size</span>
                        <select name="font_size" id="fontSizeSelector">
                            <option value="small" <?php echo $font_size === 'small' ? 'selected' : ''; ?>>Small</option>
                            <option value="medium" <?php echo $font_size === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="large" <?php echo $font_size === 'large' ? 'selected' : ''; ?>>Large</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="settings-card" style="text-align: center;">
                <div class="btn-group btn-group-center">
                    <button type="submit" class="btn btn-success" id="saveSettings">
                        <i class="fas fa-save"></i>
                        Save Settings
                    </button>
                    <button type="button" class="btn btn-danger" id="resetSettings">
                        <i class="fas fa-undo"></i>
                        Reset to Defaults
                    </button>
                </div>
            </div>
        </form>
        </div>
    </div>

    <script>
        // Store original theme for reset
        const originalTheme = "<?php echo $current_theme; ?>";
        const originalShade = "<?php echo $current_shade; ?>";
        let previewTheme = originalTheme;
        let previewShade = originalShade;

        document.addEventListener('DOMContentLoaded', function() {
            // Add theme change animation class
            document.body.classList.add('theme-change');
            
            // Remove animation class after animation completes
            setTimeout(() => {
                document.body.classList.remove('theme-change');
            }, 400);
            
            // Handle success message fade out
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.classList.remove('show');
                }, 3000);
            }
            
            // Add animation to save button on click
            document.getElementById('saveSettings').addEventListener('click', function() {
                this.classList.add('success-animation');
                setTimeout(() => {
                    this.classList.remove('success-animation');
                }, 1000);
            });
            
            // Reset Settings
            document.getElementById('resetSettings').addEventListener('click', function() {
                if (confirm('Are you sure you want to reset all settings to default?')) {
                    // Reset preview to original values
                    previewTheme = originalTheme;
                    previewShade = originalShade;
                    
                    // Update the form inputs
                    document.getElementById('themeInput').value = originalTheme;
                    document.getElementById('themeShadeInput').value = originalShade;
                    
                    // Create a form and submit it to reset settings
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    // Add reset parameter
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'reset';
                    input.value = 'true';
                    form.appendChild(input);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
        
        function selectTheme(theme) {
            // Update preview theme
            previewTheme = theme;
            
            // Reset shade to default when changing themes
            previewShade = 'default';
            
            // Update the hidden inputs
            document.getElementById('themeInput').value = theme;
            document.getElementById('themeShadeInput').value = previewShade;
            
            // Update selected state for theme options
            const themeOptions = document.querySelectorAll('.theme-option');
            themeOptions.forEach(option => {
                option.classList.remove('selected');
                if (option.getAttribute('data-theme') === theme) {
                    option.classList.add('selected');
                }
            });
            
            // Update shade options
            const shadeContainer = document.getElementById('shadeOptionsContainer');
            shadeContainer.innerHTML = '';
            
            if (theme !== 'default') {
                const shadeOptions = document.createElement('div');
                shadeOptions.className = 'shade-options active';
                shadeOptions.id = theme + 'Shades';
                
                // Create shade options
                const defaultShade = document.createElement('div');
                defaultShade.className = 'shade-option selected ' + theme + '-default-shade';
                defaultShade.title = 'Default Shade';
                defaultShade.onclick = function() { selectShade('default', theme); };
                
                const lightShade = document.createElement('div');
                lightShade.className = 'shade-option ' + theme + '-light-shade';
                lightShade.title = 'Light Shade';
                lightShade.onclick = function() { selectShade(theme + '-light', theme); };
                
                const darkShade = document.createElement('div');
                darkShade.className = 'shade-option ' + theme + '-dark-shade';
                darkShade.title = 'Dark Shade';
                darkShade.onclick = function() { selectShade(theme + '-dark', theme); };
                
                shadeOptions.appendChild(defaultShade);
                shadeOptions.appendChild(lightShade);
                shadeOptions.appendChild(darkShade);
                
                shadeContainer.appendChild(shadeOptions);
            }
            
            // Update body class for preview
            updateBodyClass();
        }
        
        function selectShade(shade, theme) {
            // Update preview shade
            previewShade = shade;
            
            // Update the hidden input
            document.getElementById('themeShadeInput').value = shade;
            
            // Update selected state for shade options
            const shadeOptions = document.querySelectorAll('#' + theme + 'Shades .shade-option');
            shadeOptions.forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to clicked shade
            event.target.classList.add('selected');
            
            // Update body class for preview
            updateBodyClass();
        }
        
        function updateBodyClass() {
            // Remove all theme-related classes
            const body = document.body;
            body.classList.remove('default-theme', 'ocean-theme', 'forest-theme', 'sunset-theme',
                                'ocean-light', 'ocean-dark', 'forest-light', 'forest-dark',
                                'sunset-light', 'sunset-dark');
            
            // Add the preview theme classes
            if (previewTheme !== 'default') {
                body.classList.add(previewTheme + '-theme');
                if (previewShade !== 'default') {
                    body.classList.add(previewShade);
                }
            }
            
            // Add animation class
            body.classList.add('theme-change');
            
            // Remove animation class after animation completes
            setTimeout(() => {
                body.classList.remove('theme-change');
            }, 400);
        }
    </script>
</body>
</html>