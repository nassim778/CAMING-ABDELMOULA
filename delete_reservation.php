<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';
require_once 'includes/translation.php';

// Check if logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$id = $_GET['id'] ?? 0;

if ($id) {
    try {
        // Get guest_id first
        $stmt = $pdo->prepare("SELECT guest_id FROM reservations WHERE id = ?");
        $stmt->execute([$id]);
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            // DELETE ALL ASSOCIATED RESOURCES BEFORE DELETING THE RESERVATION
            
            // Delete assigned cars
            $pdo->prepare("DELETE FROM reservation_cars WHERE reservation_id = ?")->execute([$id]);
            
            // Delete assigned drivers and guides
            $pdo->prepare("DELETE FROM reservation_humans WHERE reservation_id = ?")->execute([$id]);
            
            // Delete assigned tents
            $pdo->prepare("DELETE FROM reservation_tents WHERE reservation_id = ?")->execute([$id]);
            
            // Delete reservation exceptions
            $pdo->prepare("DELETE FROM reservation_exceptions WHERE reservation_id = ?")->execute([$id]);
            
            // Now delete the reservation (this will cascade to guest due to foreign key)
            $delete_stmt = $pdo->prepare("DELETE FROM reservations WHERE id = ?");
            $delete_stmt->execute([$id]);
           
        }
    } catch (Exception $e) {
        // Handle error silently
    }
}

header('Location: dashboard.php');
exit();
?>