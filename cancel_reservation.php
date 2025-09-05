<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';

// Check if logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$id = $_GET['id'] ?? 0;

if ($id) {
    try {
        // Update reservation status to canceled
        $stmt = $pdo->prepare("UPDATE reservations SET reservation_status = 'canceled' WHERE id = ?");
        $stmt->execute([$id]);
        
        $message = 'Reservation canceled successfully!';
    } catch (Exception $e) {
        $message = 'Error canceling reservation: ' . $e->getMessage();
    }
}

// Redirect back to dashboard with message
header('Location: dashboard.php?message=' . urlencode($message));
exit();
?>