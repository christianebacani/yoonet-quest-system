<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Set default values if not set
$current_theme = $_SESSION['theme'] ?? 'default';
$dark_mode = $_SESSION['dark_mode'] ?? false;
$font_size = $_SESSION['font_size'] ?? 'medium';

// Function to get the body class based on theme
function getBodyClass() {
    global $current_theme, $dark_mode;
    
    $classes = [];
    
    if ($dark_mode) {
        $classes[] = 'dark-mode';
    }
    
    if ($current_theme !== 'default') {
        $classes[] = $current_theme . '-theme';
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Yoonet - Gamified Career Progression</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body {
      font-family: 'Inter', sans-serif;
    }
    .quest-card {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      transition: all 0.3s ease;
      transform: translateY(0);
    }
    .quest-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    }
    .hero-gradient {
      background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 50%, #06b6d4 100%);
    }
    .feature-icon {
      background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);
    }
    @keyframes float {
      0% { transform: translateY(0px); }
      50% { transform: translateY(-10px); }
      100% { transform: translateY(0px); }
    }
    .floating {
      animation: float 3s ease-in-out infinite;
    }
    .delay-1 {
      animation-delay: 0.2s;
    }
    .delay-2 {
      animation-delay: 0.4s;
    }
    .delay-3 {
      animation-delay: 0.6s;
    }
    .pulse {
      animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.8; }
    }

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

    /* Dark Mode */
    .dark-mode {
        --primary-color: #8ab4f8;
        --secondary-color: #81c995;
        --background-color: #121212;
        --text-color: #e0e0e0;
        --card-bg: #1e1e1e;
        --border-color: #333333;
        --shadow-color: rgba(0, 0, 0, 0.3);
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

    /* Forest Theme */
    .forest-theme {
        --primary-color: #228B22;
        --secondary-color: #2E8B57;
        --background-color: #f0fff0;
        --text-color: #013220;
        --card-bg: #e1fae1;
        --border-color: #98fb98;
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

    /* Animation for theme change */
    @keyframes fadeIn {
        from { opacity: 0.8; }
        to { opacity: 1; }
    }

    .theme-change {
        animation: fadeIn var(--transition-speed) ease;
    }

    /* Apply transitions to elements that change with theme */
    body {
        background-color: var(--background-color);
        color: var(--text-color);
        transition: background-color var(--transition-speed) ease, 
                    color var(--transition-speed) ease;
    }

    /* Add this to any element that uses theme colors */
    .card, .btn-primary, .btn-secondary, 
    .assignment-section, .section-header, 
    .user-card, .progress-bar, .rank-badge,
    .status-badge, .xp-badge {
        transition: all var(--transition-speed) ease;
    }
  </style>
</head>

<script language="javascript" type="text/javascript">
function DisableBackButton() {
    window.history.forward();
}
DisableBackButton();
window.onload = DisableBackButton;
window.onpageshow = function(evt) { if (evt.persisted) DisableBackButton(); }
window.onunload = function() { void (0); }
</script>

<body class="<?php echo getBodyClass(); ?>" style="font-size: <?php echo getFontSize(); ?>;">

  <!-- Navigation -->
  <nav class="bg-white shadow-sm border-b">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center h-16">
        <div class="flex items-center">
          <div class="flex-shrink-0 flex items-center space-x-2">
            <img src="assets/images/yoonet-logo.jpg" alt="Yoonet Logo" class="h-10 inline floating">
            <span class="text-2xl font-bold text-blue-600">Yoonet</span>
          </div>
        </div>
        <div class="hidden md:block">
          <div class="ml-10 flex items-center space-x-4">
            <span class="text-gray-600 px-3 py-2 text-sm font-medium">
              Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>
            </span>
            <a href="#how-it-works" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition-colors">How It Works</a>
            <form action="includes/logout.php" method="POST" class="inline">
              <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-md text-sm font-medium transition-colors">
                Sign Out
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <section class="hero-gradient text-white py-20" data-aos="fade">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center">
        <h1 class="text-4xl md:text-6xl font-bold mb-6" data-aos="fade-up">
          Level Up Your Career
          <span class="block text-yellow-300" data-aos="fade-up" data-aos-delay="100">Through Epic Quests</span>
        </h1>
        <p class="text-xl md:text-2xl mb-8 text-blue-100 max-w-3xl mx-auto" data-aos="fade-up" data-aos-delay="200">
          Transform your professional development into an engaging adventure. Complete quests, earn rewards, and unlock new career opportunities.
        </p>
        <div class="flex flex-col sm:flex-row flex-wrap gap-4 justify-center" data-aos="fade-up" data-aos-delay="300">
          <a href="dashboard.php" class="bg-yellow-500 hover:bg-yellow-400 text-gray-900 px-8 py-4 rounded-lg text-lg font-semibold transition-colors shadow-lg flex items-center justify-center pulse">
            🚀 Start Your Quest
          </a>
        
        </div>
      </div>
    </div>
  </section>

  <!-- Roles Section -->
  <section class="py-20 bg-gradient-to-b from-gray-50 to-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-16" data-aos="fade-up">
        <h2 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">Two Roles, One Unified System</h2>
        <p class="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed">Whether you create quests or complete them, we've got you covered</p>
      </div>
      <div class="grid md:grid-cols-2 gap-8 max-w-5xl mx-auto">
        <!-- Quest Leads Card -->
        <div class="bg-white rounded-2xl p-8 shadow-xl hover:shadow-2xl transition-all duration-300 border border-gray-100" data-aos="fade-up" data-aos-delay="100" role="article" aria-label="Quest Leads role information">
          <div class="flex items-center mb-6">
            <div class="w-14 h-14 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center mr-4 shadow-lg">
              <span class="text-3xl" role="img" aria-label="Quest Lead icon">👔</span>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">Quest Leads</h3>
          </div>
          <p class="text-gray-600 mb-6 leading-relaxed text-base">Create and assign quests to your team members, review submissions, and provide meaningful feedback on completed work.</p>
          <ul class="space-y-3" role="list">
            <li class="flex items-start text-gray-700">
              <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Create mandatory or optional quests</span>
            </li>
            <li class="flex items-start text-gray-700">
              <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Assign tasks to Skill Associates</span>
            </li>
            <li class="flex items-start text-gray-700">
              <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Review and grade submissions</span>
            </li>
            <li class="flex items-start text-gray-700">
              <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Manage team accounts</span>
            </li>
          </ul>
        </div>
        
        <!-- Skill Associates Card -->
        <div class="bg-white rounded-2xl p-8 shadow-xl hover:shadow-2xl transition-all duration-300 border border-gray-100" data-aos="fade-up" data-aos-delay="200" role="article" aria-label="Skill Associates role information">
          <div class="flex items-center mb-6">
            <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-500 to-cyan-600 flex items-center justify-center mr-4 shadow-lg">
              <span class="text-3xl" role="img" aria-label="Skill Associate icon">🎯</span>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">Skill Associates</h3>
          </div>
          <p class="text-gray-600 mb-6 leading-relaxed text-base">Accept assigned quests, submit your work, track your progress, and build your skills through task completion.</p>
          <ul class="space-y-3" role="list">
            <li class="flex items-start text-gray-700">
              <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>View assigned quests</span>
            </li>
            <li class="flex items-start text-gray-700">
              <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Accept optional assignments</span>
            </li>
            <li class="flex items-start text-gray-700">
              <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Submit completed work</span>
            </li>
            <li class="flex items-start text-gray-700">
              <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Track your achievements</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <!-- How It Works -->
  <section id="how-it-works" class="py-20 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-16" data-aos="fade-up">
        <h2 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">How It Works</h2>
        <p class="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed">Simple quest assignment and completion workflow in three steps</p>
      </div>
      <div class="grid md:grid-cols-3 gap-10 max-w-6xl mx-auto">
        <!-- Step 1 -->
        <div class="text-center" data-aos="fade-up" data-aos-delay="100" role="article" aria-label="Step 1: Create Tasks">
          <div class="relative mb-8">
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 w-24 h-24 rounded-full mx-auto flex items-center justify-center shadow-lg floating delay-1">
              <span class="text-4xl font-bold text-white">1</span>
            </div>
          </div>
          <h3 class="text-2xl font-bold text-gray-900 mb-4">Quest Leads Create Tasks</h3>
          <p class="text-gray-600 leading-relaxed text-base px-4">Quest Leads design quests and assign them as mandatory or optional tasks to specific team members.</p>
        </div>
        
        <!-- Step 2 -->
        <div class="text-center" data-aos="fade-up" data-aos-delay="200" role="article" aria-label="Step 2: Complete Work">
          <div class="relative mb-8">
            <div class="bg-gradient-to-br from-green-500 to-green-600 w-24 h-24 rounded-full mx-auto flex items-center justify-center shadow-lg floating delay-2">
              <span class="text-4xl font-bold text-white">2</span>
            </div>
          </div>
          <h3 class="text-2xl font-bold text-gray-900 mb-4">Skill Associates Complete</h3>
          <p class="text-gray-600 leading-relaxed text-base px-4">Skill Associates accept assignments, work on tasks, and submit their completed work for review.</p>
        </div>
        
        <!-- Step 3 -->
        <div class="text-center" data-aos="fade-up" data-aos-delay="300" role="article" aria-label="Step 3: Review and Track">
          <div class="relative mb-8">
            <div class="bg-gradient-to-br from-yellow-500 to-orange-600 w-24 h-24 rounded-full mx-auto flex items-center justify-center shadow-lg floating delay-3">
              <span class="text-4xl font-bold text-white">3</span>
            </div>
          </div>
          <h3 class="text-2xl font-bold text-gray-900 mb-4">Review & Track Progress</h3>
          <p class="text-gray-600 leading-relaxed text-base px-4">Quest Leads review submissions and provide grades with feedback while everyone tracks their achievements.</p>
        </div>
      </div>
      
      <!-- Visual Connector (Optional Enhancement) -->
      <div class="hidden md:block mt-16 text-center">
        <svg class="mx-auto" width="400" height="2" viewBox="0 0 400 2" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <line x1="0" y1="1" x2="400" y2="1" stroke="#E5E7EB" stroke-width="2" stroke-dasharray="8 8"/>
        </svg>
      </div>
    </div>
  </section>

  <!-- Call to Action -->
  <section class="py-16 bg-blue-600 text-white" data-aos="fade-up">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
      <h2 class="text-3xl font-bold mb-6">Ready to Begin Your Quest?</h2>
      <p class="text-xl mb-8 max-w-3xl mx-auto">Join hundreds of employees who are transforming their careers through gamified learning.</p>
      <a href="dashboard.php" class="inline-block bg-yellow-500 hover:bg-yellow-400 text-gray-900 px-8 py-4 rounded-lg text-lg font-semibold transition-colors shadow-lg">
        Start Your Adventure Now
      </a>
    </div>
  </section>

  <!-- Footer -->
  <footer class="bg-gray-900 text-white py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid md:grid-cols-4 gap-8">
        <div>
          <div class="flex items-center space-x-2 mb-4">
            <img src="assets/images/yoonet-logo.jpg" alt="Yoonet Logo" class="h-6">
            <h3 class="text-xl font-bold">Yoonet</h3>
          </div>
          <p class="text-gray-400">Gamifying career development for the modern professional.</p>
        </div>
        <div>
          <h4 class="font-semibold mb-4">Company</h4>
          <ul class="space-y-2 text-gray-400">
            <li><a href="https://www.yoonet.io/our-company" target="_blank" rel="noopener noreferrer" class="hover:text-white transition-colors">About</a></li>
            <li><a href="https://www.yoonet.io/ph" target="_blank" rel="noopener noreferrer" class="hover:text-white transition-colors">Careers</a></li>
            <li><a href="https://www.yoonet.io/get-in-touch" target="_blank" rel="noopener noreferrer" class="hover:text-white transition-colors">Contact</a></li>
          </ul>
        </div>
        <div>
          <h4 class="font-semibold mb-4">Support</h4>
          <ul class="space-y-2 text-gray-400">
            <li><a href="https://www.facebook.com/yoonet.io" target="_blank" rel="noopener noreferrer" class="hover:text-white transition-colors">Community</a></li>
          </ul>
        </div>
      </div>
      <div class="border-t border-gray-800 mt-8 pt-8 text-center text-gray-400">
        <p>© Yoonet Quest Career Progression</p>
      </div>
    </div>
  </footer>

  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script>
    // Initialize animations
    AOS.init({
      duration: 800,
      once: true
    });

    // Demo button functionality
    document.querySelectorAll('button').forEach(button => {
      button.addEventListener('click', function() {
        if (this.textContent.includes('Demo')) {
          alert('🎬 Demo video would play here. See how QuestCareer transforms careers!');
        } else if (this.textContent.includes('Career Paths')) {
          alert('📚 Discovering custom career paths based on your profile...');
        }
      });
    });

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }
      });
    });
  </script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add theme change animation class
        document.body.classList.add('theme-change');
        
        // Remove animation class after animation completes
        setTimeout(() => {
            document.body.classList.remove('theme-change');
        }, 400);
    });
</script>

</body>
</html>