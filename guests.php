<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';
require_once 'includes/tariff_helper.php';
require_once 'includes/translation.php';

// Check if logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$search = $_GET['search'] ?? '';

// Build query with search
$where_clause = '';
$params = [];

if (!empty($search)) {
    $where_clause = 'WHERE g.name LIKE ? OR g.email LIKE ? OR g.phone LIKE ?';
    $params = ["%$search%", "%$search%", "%$search%"];
}

// Pagination setup
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Count total guests for pagination
$count_query = "SELECT COUNT(DISTINCT CONCAT(g.name, '-', g.phone)) as total FROM guests g ".($where_clause ? $where_clause : '');
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_guests = $count_stmt->fetchColumn();
$total_pages = ceil($total_guests / $per_page);

// Get guests with combined reservation count and total spent (paginated)
$query = "SELECT 
    MIN(g.id) as id,
    g.name,
    g.email,
    g.phone,
    MAX(g.adults) as adults,
    MAX(g.kids) as kids,
    MAX(g.babies) as babies,
    (MAX(g.adults) + MAX(g.kids) + MAX(g.babies)) as total_people,
    COUNT(DISTINCT r.id) as reservation_count,
    MIN(g.created_at) as created_at,
    GROUP_CONCAT(DISTINCT r.id ORDER BY r.check_in_date DESC) as reservation_ids
    FROM guests g 
    LEFT JOIN reservations r ON g.id = r.guest_id 
    $where_clause 
    GROUP BY g.name, g.phone 
    ORDER BY created_at DESC
    LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$guests = $stmt->fetchAll();

// After fetching $guests:
foreach ($guests as &$guest) {
    $reservation_ids = !empty($guest['reservation_ids']) ? explode(',', $guest['reservation_ids']) : [];
    $total_spent = 0;
    foreach ($reservation_ids as $res_id) {
        if (!$res_id) continue;
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
        $stmt->execute([$res_id]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($reservation) {
            // Fetch assigned cars
            $stmt2 = $pdo->prepare("SELECT start_date, end_date FROM reservation_cars WHERE reservation_id = ?");
            $stmt2->execute([$reservation['id']]);
            $car_days_total = 0;
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $car) {
                $car_days_total += (new DateTime($car['end_date']))->diff(new DateTime($car['start_date']))->days;
            }
            $reservation['car_days_total'] = $car_days_total;
            // Fetch assigned drivers
            $stmt2 = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'driver'");
            $stmt2->execute([$reservation['id']]);
            $driver_days_total = 0;
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $driver) {
                $driver_days_total += (new DateTime($driver['end_date']))->diff(new DateTime($driver['start_date']))->days + 1;
            }
            $reservation['driver_days_total'] = $driver_days_total;
            // Fetch assigned guides
            $stmt2 = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'guide'");
            $stmt2->execute([$reservation['id']]);
            $guide_days_total = 0;
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $guide) {
                $guide_days_total += (new DateTime($guide['end_date']))->diff(new DateTime($guide['start_date']))->days + 1;
            }
            $reservation['guide_days_total'] = $guide_days_total;
            // Build guest array for price calculation
            $guest_arr = [
                'adults' => $reservation['adults'] ?? 0,
                'kids' => $reservation['kids'] ?? 0,
                'babies' => $reservation['babies'] ?? 0,
            ];
            $total_spent += calculateTotalReservationPrice($pdo, $reservation, $guest_arr);
        }
    }
    $guest['total_spent'] = $total_spent;
}

// Function to get the latest reservation details for a guest
function getLatestReservationDetails($pdo, $reservation_ids) {
    if (empty($reservation_ids)) return null;
    $ids = explode(',', $reservation_ids);
    $latest_id = $ids[0]; // First ID is the most recent due to ORDER BY in main query
    
    $stmt = $pdo->prepare("SELECT check_in_date, check_out_date, tent_type, payment_status 
                          FROM reservations 
                          WHERE id = ?");
    $stmt->execute([$latest_id]);
    return $stmt->fetch();
}

// Handle guest deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_guest_id'])) {
    $allowed_roles = ['Admin', 'Accountant', 'Director'];
    if (in_array($_SESSION['user_role'], $allowed_roles)) {
        $guest_id = intval($_POST['delete_guest_id']);
        // Check if guest has reservations
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE guest_id = ?");
        $stmt->execute([$guest_id]);
        $count = $stmt->fetchColumn();
        if ($count == 0) {
            $del = $pdo->prepare("DELETE FROM guests WHERE id = ?");
            $del->execute([$guest_id]);
            $message = 'Guest deleted successfully!';
        } else {
            $error = 'Cannot delete guest with existing reservations.';
        }
    } else {
        $error = 'You do not have permission to delete guests.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guests - ABDELMOULA CAMP</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="table-header">
            <h2><?php echo t('Guests Management', 'Guests Management'); ?></h2>
            <a href="dashboard.php" class="btn btn-secondary"><?php echo t('Back to Dashboard', 'Back to Dashboard'); ?></a>
        </div>

        <!-- Search -->
        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <input type="text" name="search" placeholder="<?php echo t('Search by name, email, or phone', 'Search by name, email, or phone'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn btn-primary"><?php echo t('Search', 'Search'); ?></button>
                <a href="guests.php" class="btn btn-secondary"><?php echo t('Clear', 'Clear'); ?></a>
            </form>
        </div>

        <!-- Guests Table -->
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('Guest Name', 'Guest Name'); ?></th>
                        <th><?php echo t('Contact Information', 'Contact Information'); ?></th>
                        <th><?php echo t('People (A/K/B)', 'People (A/K/B)'); ?></th>
                        <th><?php echo t('Total People', 'Total People'); ?></th>
                        <th><?php echo t('Reservations', 'Reservations'); ?></th>
                        <th><?php echo t('Latest Stay', 'Latest Stay'); ?></th>
                        <th><?php echo t('Total Spent', 'Total Spent'); ?></th>
                        <th><?php echo t('Member Since', 'Member Since'); ?></th>
                        <th><?php echo t('Actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guests as $guest): 
                        $latest_reservation = getLatestReservationDetails($pdo, $guest['reservation_ids']);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($guest['name']); ?></td>
                        <td>
                            <?php if ($guest['email']): ?>
                                <strong><?php echo t('Email', 'Email'); ?>:</strong> <?php echo htmlspecialchars($guest['email']); ?><br>
                            <?php endif; ?>
                            <?php if ($guest['phone']): ?>
                                <strong><?php echo t('Phone', 'Phone'); ?>:</strong> <?php echo htmlspecialchars($guest['phone']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $guest['adults']; ?>/<?php echo $guest['kids']; ?>/<?php echo $guest['babies']; ?></td>
                        <td><?php echo $guest['total_people']; ?></td>
                        <td>
                            <?php echo $guest['reservation_count']; ?> <?php echo t('reservations', 'reservations'); ?>
                            <?php if ($latest_reservation): ?>
                                <br>
                                <small class="latest-reservation">
                                    <?php echo t('Latest', 'Latest'); ?>: <?php echo date('d/m/Y', strtotime($latest_reservation['check_in_date'])); ?>
                                    (<?php echo htmlspecialchars($latest_reservation['tent_type']); ?>)
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($latest_reservation): ?>
                                <?php echo date('d/m/Y', strtotime($latest_reservation['check_in_date'])); ?> <?php echo t('to', 'to'); ?>
                                <?php echo date('d/m/Y', strtotime($latest_reservation['check_out_date'])); ?>
                                <br>
                                <span class="payment-status <?php echo $latest_reservation['payment_status']; ?>">
                                    <?php echo ucfirst($latest_reservation['payment_status']); ?>
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($guest['total_spent'], 2); ?> TND</td>
                        <td><?php echo date('d/m/Y', strtotime($guest['created_at'])); ?></td>
                        <td>
                            <a href="view_guest_reservations.php?name=<?php echo urlencode($guest['name']); ?>&phone=<?php echo urlencode($guest['phone']); ?>" class="btn btn-small btn-primary"><?php echo t('View Reservations', 'View Reservations'); ?></a>
                            <?php if (in_array($_SESSION['user_role'], ['Admin', 'Accountant', 'Director']) && $guest['reservation_count'] == 0): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('Are you sure you want to delete this guest?', 'Are you sure you want to delete this guest?'); ?>');">
                                    <input type="hidden" name="delete_guest_id" value="<?php echo $guest['id']; ?>">
                                    <button type="submit" class="btn btn-small btn-danger"><?php echo t('Delete', 'Delete'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination Controls -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination" style="margin: 1.5rem 0; text-align: center;">
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-small btn-secondary">&laquo; <?php echo t('Prev', 'Prev'); ?></a>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>" class="btn btn-small <?php echo $p == $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-small btn-secondary"><?php echo t('Next', 'Next'); ?> &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html> 