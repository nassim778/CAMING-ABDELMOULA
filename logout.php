<?php
require_once 'config/session_config.php';

// Perform secure logout
secure_logout();

// Redirect to login page
header('Location: index.php?message=logged_out');
exit();
?>