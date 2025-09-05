<?php
require_once 'config/session_config.php';
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lang'])) {
    $_SESSION['lang'] = $_POST['lang'];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Check if already logged in
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'inactive':
            $error = 'Your account is currently inactive. Please contact the Director.';
            break;
        case 'session_expired':
            $error = 'Your session has expired. Please login again.';
            break;
        case 'security_violation':
            $error = 'Security violation detected. Please login again.';
            break;
        case 'not_logged_in':
            $error = 'Please login to access the system.';
            break;
        default:
            $error = 'An error occurred. Please try again.';
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (strlen(trim($username)) > 0 && strlen(trim($password)) > 0) {
        // Check if user exists and is active
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
        $stmt->execute([$username, $password]);
        $user = $stmt->fetch();
        if ($user) {
            if ($user['is_active'] != 1) {
                $error = 'Your account is currently inactive. Please contact the Director.';
            } else {
                // Set secure session data
                set_secure_session_data($user);
                header('Location: dashboard.php');
                exit();
            }
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        $error = 'Please enter both username and password';
    }
}

if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}
$lang = $_SESSION['lang'];
$trans = [];
if ($lang === 'fr' && file_exists('languages/fr.php')) {
    $trans = include 'languages/fr.php';
}
function t($key, $default) {
    global $trans;
    return $trans[$key] ?? $default;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ABDELMOULA CAMP - Login</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <!-- PWA: Manifest + Icons -->
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/icon-180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/icons/icon-512.png">
    <!-- Theme + Mobile Settings -->
    <meta name="theme-color" content="#b8860b">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ABDELMOULA CAMP">
    <meta name="application-name" content="ABDELMOULA CAMP">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
    .lang-switcher-btn {
      background: #fffbe6;
      border: 1.5px solid #e0c97f;
      border-radius: 8px;
      padding: 6px 16px 6px 12px;
      font-size: 1.08em;
      color: #b8860b;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(184,134,11,0.08);
      transition: border-color 0.18s, box-shadow 0.18s;
      outline: none;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .lang-switcher-btn:hover, .lang-switcher-btn:focus {
      border-color: #b8860b;
      box-shadow: 0 4px 16px rgba(184,134,11,0.13);
    }
    .lang-switcher-dropdown {
      min-width: 120px;
      background: #fff;
      border: 1px solid #ccc;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      z-index: 1000;
      padding: 0.5em 0;
      position: absolute;
      top: 110%;
      right: 0;
      display: none;
    }
    .lang-option {
      display: block;
      width: 100%;
      background: none;
      border: none;
      padding: 8px 18px;
      text-align: left;
      font-size: 1em;
      cursor: pointer;
    }
    .lang-option:hover, .lang-option:focus {
      background: #fffbe6;
      color: #b8860b;
    }
    .lang-switcher-container {
      position: absolute;
      top: 18px;
      right: 32px;
      z-index: 2000;
    }
    </style>
</head>
<body class="login-page">
    <div class="lang-switcher-container">
      <button type="button" id="langSwitcherBtn" class="lang-switcher-btn" aria-label="Switch language">
        <span style="font-size:1.3em;vertical-align:middle;">🌐</span>
        <span style="margin-left:6px;font-weight:600;letter-spacing:1px;">
          <?php echo strtoupper($_SESSION['lang']); ?>
        </span>
        <span style="margin-left:4px;font-size:0.9em;">▼</span>
      </button>
      <form method="post" action="" id="langSwitcherForm" class="lang-switcher-dropdown">
        <button type="submit" name="lang" value="en" class="lang-option" style="<?php if ($_SESSION['lang']==='en') echo 'font-weight:bold;color:#b8860b;'; ?>">🇬🇧 English</button>
        <button type="submit" name="lang" value="fr" class="lang-option" style="<?php if ($_SESSION['lang']==='fr') echo 'font-weight:bold;color:#b8860b;'; ?>">🇫🇷 Français</button>
      </form>
    </div>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <div class="nav-brand">
                    <img src="assets/images/logo.png" alt="ABDELMOULA CAMP" class="nav-logo">
                    <h1>ABDELMOULA CAMP</h1>
                </div>
                <p>System Login</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="username"><?php echo t('username', 'Username'); ?>:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password"><?php echo t('password', 'Password'); ?>:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary"><?php echo t('login', 'Login'); ?></button>
            </form>
            
            <div class="login-footer">
                <p><?php echo t('login_footer', 'Login to access the system dashboard!'); ?></p>
            </div>
        </div>
    </div>
    <script>
// Language switcher dropdown logic
const langBtn = document.getElementById('langSwitcherBtn');
const langForm = document.getElementById('langSwitcherForm');
if (langBtn && langForm) {
  langBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    langForm.style.display = langForm.style.display === 'block' ? 'none' : 'block';
  });
  document.addEventListener('click', function(e) {
    if (!langForm.contains(e.target) && e.target !== langBtn) {
      langForm.style.display = 'none';
    }
  });
}
</script>
    <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function() {
        navigator.serviceWorker.register('sw.js').catch(function(){});
      });
    }
    </script>
</body>
</html> 