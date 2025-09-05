<?php
require_once 'config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Status - ABDELMOULA CAMP</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .status-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #ddd;
        }
        .status-ok {
            background: #d4edda;
            border-left-color: #28a745;
        }
        .status-error {
            background: #f8d7da;
            border-left-color: #dc3545;
        }
        .status-warning {
            background: #fff3cd;
            border-left-color: #ffc107;
        }
        .copy-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        }
        .copy-btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="status-container">
        <h1>🌐 Network Status - ABDELMOULA CAMP</h1>
        
        <div class="status-item <?php echo $pdo ? 'status-ok' : 'status-error'; ?>">
            <div>
                <strong>Database Connection</strong>
                <br><small>MySQL connection status</small>
            </div>
            <div>
                <?php echo $pdo ? '✅ Connected' : '❌ Failed'; ?>
            </div>
        </div>

        <div class="status-item status-ok">
            <div>
                <strong>Server IP Address</strong>
                <br><small>Use this IP to access from other devices</small>
            </div>
            <div>
                <code id="server-ip"><?php echo getServerIP(); ?></code>
                <button class="copy-btn" onclick="copyToClipboard('server-ip')">Copy</button>
            </div>
        </div>

        <div class="status-item status-ok">
            <div>
                <strong>Network URL</strong>
                <br><small>Full URL for network access</small>
            </div>
            <div>
                <code id="network-url">http://<?php echo getServerIP(); ?>/ZZw</code>
                <button class="copy-btn" onclick="copyToClipboard('network-url')">Copy</button>
            </div>
        </div>

        <div class="status-item <?php echo (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) ? 'status-warning' : 'status-ok'; ?>">
            <div>
                <strong>Current Access Method</strong>
                <br><small>How you're currently accessing the application</small>
            </div>
            <div>
                <?php 
                $currentHost = $_SERVER['HTTP_HOST'];
                if (strpos($currentHost, 'localhost') !== false || strpos($currentHost, '127.0.0.1') !== false) {
                    echo '⚠️ Local access only';
                } else {
                    echo '✅ Network access';
                }
                ?>
            </div>
        </div>

        <div class="status-item status-ok">
            <div>
                <strong>Server Information</strong>
                <br><small>Technical details</small>
            </div>
            <div>
                <small>
                    PHP: <?php echo PHP_VERSION; ?><br>
                    Server: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?><br>
                    Port: <?php echo $_SERVER['SERVER_PORT'] ?? '80'; ?>
                </small>
            </div>
        </div>

        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 5px;">
            <h3>📋 Setup Instructions for Other PCs:</h3>
            <ol>
                <li>Ensure both PCs are connected to the same WiFi network</li>
                <li>On the other PC, open a web browser</li>
                <li>Navigate to: <code>http://<?php echo getServerIP(); ?>/ZZw</code></li>
                <li>If you can't access, check Windows Firewall settings</li>
                <li>Make sure Apache is configured to accept network connections</li>
            </ol>
        </div>

        <div style="margin-top: 20px; text-align: center;">
            <a href="index.php" class="btn btn-primary">← Back to Login</a>
        </div>
    </div>

    <script>
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            
            navigator.clipboard.writeText(text).then(function() {
                const btn = element.nextElementSibling;
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                btn.style.background = '#28a745';
                
                setTimeout(function() {
                    btn.textContent = originalText;
                    btn.style.background = '#007bff';
                }, 2000);
            }).catch(function(err) {
                console.error('Could not copy text: ', err);
                alert('Could not copy to clipboard. Please copy manually: ' + text);
            });
        }
    </script>
</body>
</html>
