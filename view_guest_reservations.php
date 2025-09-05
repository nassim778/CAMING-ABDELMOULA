<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';
require_once 'includes/tariff_helper.php';

// Check if logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

// Get guest name and phone from URL parameters
$guest_name = $_GET['name'] ?? '';
$guest_phone = $_GET['phone'] ?? '';

if (empty($guest_name) || empty($guest_phone)) {
    header('Location: guests.php');
        exit();
}

// Get all guest IDs for this name and phone combination
$stmt = $pdo->prepare("SELECT id, name, email, phone, nationality, adults, kids, babies, total_people, created_at FROM guests WHERE name = ? AND phone = ? ORDER BY created_at DESC");
$stmt->execute([$guest_name, $guest_phone]);
$guest_records = $stmt->fetchAll();

if (empty($guest_records)) {
    header('Location: guests.php');
    exit();
}

// Get the first guest record for display (most recent)
$guest = $guest_records[0];

// Get all guest IDs for this person
$guest_ids = array_column($guest_records, 'id');
$placeholders = str_repeat('?,', count($guest_ids) - 1) . '?';

// Get all reservations for this person (all guest IDs with same name and phone)
$stmt = $pdo->prepare("
    SELECT 
        r.*,
        g.name as guest_name,
        g.email as guest_email,
        g.phone as guest_phone,
        g.nationality,
        g.adults,
        g.kids,
        g.babies,
        g.total_people
    FROM reservations r
    JOIN guests g ON r.guest_id = g.id
    WHERE r.guest_id IN ($placeholders)
    ORDER BY r.check_in_date DESC
");
$stmt->execute($guest_ids);
$reservations = $stmt->fetchAll();

// Calculate total statistics
$total_reservations = count($reservations);
$total_spent = 0;
$total_nights = 0;
$total_people = 0;

foreach ($reservations as &$reservation) {
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
    $reservation['correct_total_price'] = calculateTotalReservationPrice($pdo, $reservation, $guest_arr);
    $total_spent += $reservation['correct_total_price'];
    $total_nights += $reservation['nights'] ?? 0;
    $total_people += $reservation['total_people'] ?? 0;
}

// Remove duplicate reservations by ID
$reservations = array_values(array_reduce($reservations, function($carry, $item) {
    $carry[$item['id']] = $item;
    return $carry;
}, []));

// Function to format tent specifications for display
function formatTentSpecifications($tent_type, $tent_specifications) {
    if ($tent_type === 'MIXED' && !empty($tent_specifications)) {
        $specs = explode(', ', $tent_specifications);
        $formatted_specs = [];
        
        foreach ($specs as $spec) {
            if (preg_match('/Tent \d+: (\w+) - (\w+)/', $spec, $matches)) {
                $type = $matches[1];
                $beds = $matches[2];
                
                // Convert bed type to number
                $bed_numbers = [
                    'single' => '1 bed',
                    'double' => '2 beds', 
                    'triple' => '3 beds',
                    'quadruple' => '4 beds'
                ];
                
                $bed_text = $bed_numbers[$beds] ?? $beds;
                $formatted_specs[] = "1 $type $bed_text";
            }
        }
        
        return implode(' & ', $formatted_specs);
    } else {
        return $tent_type;
    }
}

// When displaying car cost, use:
    $car_price_per_day = getCar4x4Price($pdo, $reservation['car_days']);
$car_cost = $reservation['cars_4x4'] * $car_price_per_day * $reservation['car_days'];

// Translation loader and function
$lang = $_SESSION['lang'] ?? 'en';
$trans = [];
if ($lang === 'fr' && file_exists('languages/fr.php')) {
    $trans = include 'languages/fr.php';
}
function t($key, $default, $vars = []) {
    global $trans;
    $text = $trans[$key] ?? $default;
    
    // Replace variables in the text
    foreach ($vars as $var => $value) {
        $text = str_replace('{' . $var . '}', $value, $text);
    }
    
    return $text;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Reservations - ABDELMOULA CAMP</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="table-header">
            <h2><?php echo t('guest_reservations', 'Guest Reservations'); ?> - <?php echo htmlspecialchars($guest['name']); ?></h2>
            <div>
                <a href="guests.php" class="btn btn-secondary"><?php echo t('back_to_guests', 'Back to Guests'); ?></a>
                <a href="add_reservation.php?guest_name=<?php echo urlencode($guest['name']); ?>&phone=<?php echo urlencode($guest['phone']); ?>&email=<?php echo urlencode($guest['email']); ?>" class="btn btn-primary"><?php echo t('add_reservation_for_guest', 'Add Reservation for this Guest'); ?></a>
            </div>
        </div>

        <!-- Guest Information -->
        <div class="guest-info-section">
            <div class="guest-card">
                <h3><?php echo t('guest_information', 'Guest Information'); ?></h3>
                <div class="guest-details">
                    <div class="detail-row">
                        <strong><?php echo t('name', 'Name'); ?>:</strong> <?php echo htmlspecialchars($guest['name']); ?>
                    </div>
                    <?php if ($guest['email']): ?>
                    <div class="detail-row">
                        <strong><?php echo t('email', 'Email'); ?>:</strong> <?php echo htmlspecialchars($guest['email']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($guest['phone']): ?>
                    <div class="detail-row">
                        <strong><?php echo t('phone', 'Phone'); ?>:</strong> <?php echo htmlspecialchars($guest['phone']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($guest['nationality']): ?>
                    <div class="detail-row">
                        <strong><?php echo t('nationality', 'Nationality'); ?>:</strong> <?php echo htmlspecialchars($guest['nationality']); ?>
                    </div>
                    <?php endif; ?>
                    <div class="detail-row">
                        <strong><?php echo t('total_reservations', 'Total Reservations'); ?>:</strong> <?php echo $total_reservations; ?>
                    </div>
                    <div class="detail-row">
                        <strong><?php echo t('total_nights', 'Total Nights'); ?>:</strong> <?php echo $total_nights; ?>
                    </div>
                    <div class="detail-row">
                        <strong><?php echo t('total_spent', 'Total Spent'); ?>:</strong> <?php echo number_format($total_spent, 2); ?> TND
                    </div>
                    <div class="detail-row">
                        <strong><?php echo t('member_since', 'Member Since'); ?>:</strong> <?php echo date('d/m/Y', strtotime($guest['created_at'])); ?>
                    </div>
                    <?php if (count($guest_records) > 1): ?>
                    <div class="detail-row">
                        <strong><?php echo t('note', 'Note'); ?>:</strong> <?php echo t('guest_multiple_records', 'This guest has {count} separate guest records with the same name and phone number.', ['count' => count($guest_records)]); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Reservations Table -->
        <div class="table-container">
            <h3><?php echo t('reservation_history', 'Reservation History'); ?></h3>
            <?php if (empty($reservations)): ?>
                <div class="no-reservations">
                    <p><?php echo t('no_reservations_found', 'No reservations found for this guest.'); ?></p>
                    <a href="add_reservation.php" class="btn btn-primary"><?php echo t('add_first_reservation', 'Add First Reservation'); ?></a>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?php echo t('reservation_id', 'Reservation ID'); ?></th>
                            <th><?php echo t('check_in', 'Check-in'); ?></th>
                            <th><?php echo t('check_out', 'Check-out'); ?></th>
                            <th><?php echo t('nights', 'Nights'); ?></th>
                            <th><?php echo t('people_akb', 'People (A/K/B)'); ?></th>
                            <th><?php echo t('tariff_version', 'Tariff Version'); ?></th>
                            <th><?php echo t('source', 'Source'); ?></th>
                            <th><?php echo t('payment_status', 'Payment Status'); ?></th>
                            <th><?php echo t('total_price', 'Total Price'); ?></th>
                            <th><?php echo t('actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                        <tr>
                            <td>#<?php echo $reservation['id']; ?></td>
                            <td><?php echo date('d/m/Y', strtotime($reservation['check_in_date'])); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($reservation['check_out_date'])); ?></td>
                            <td><?php echo $reservation['nights']; ?></td>
                            <td class="people-count">
                                <span class="adults"><?php echo $reservation['adults'] ?? 0; ?><?php echo t('adults_short', 'A'); ?></span>
                                <span class="kids"><?php echo $reservation['kids'] ?? 0; ?><?php echo t('kids_short', 'K'); ?></span>
                                <span class="babies"><?php echo $reservation['babies'] ?? 0; ?><?php echo t('babies_short', 'B'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($reservation['tariff_version_name'] ?? t('default', 'Default')); ?></td>
                            <td><?php echo htmlspecialchars($reservation['reservation_source'] ?? '-'); ?></td>
                            <td>
                                <span class="payment-status <?php echo $reservation['payment_status']; ?>">
                                    <?php echo t($reservation['payment_status'], ucfirst($reservation['payment_status'])); ?>
                                </span>
                                <?php if ($reservation['payment_cash'] > 0): ?>
                                    <br><small><?php echo t('cash', 'Cash'); ?>: <?php echo number_format($reservation['payment_cash'], 2); ?> TND</small>
                                <?php endif; ?>
                                <?php if ($reservation['payment_bank_check'] > 0): ?>
                                    <br><small><?php echo t('check', 'Check'); ?>: <?php echo number_format($reservation['payment_bank_check'], 2); ?> TND</small>
                                <?php endif; ?>
                                <?php if (($reservation['payment_transfer'] ?? 0) > 0): ?>
                                    <br><small><?php echo t('transfer_payment', 'Transfer'); ?>: <?php echo number_format($reservation['payment_transfer'], 2); ?> TND</small>
                                <?php endif; ?>
                            </td>
                            <td class="total-price"><?php echo number_format($reservation['correct_total_price'], 2); ?> TND</td>
                            <td class="actions">
                                <a href="view_reservation_details.php?id=<?php echo $reservation['id']; ?>" class="btn btn-small btn-info"><?php echo t('view_details', 'View'); ?></a>
                                <a href="edit_reservation.php?id=<?php echo $reservation['id']; ?>" class="btn btn-small btn-primary"><?php echo t('edit', 'Edit'); ?></a>
                                <a href="delete_reservation.php?id=<?php echo $reservation['id']; ?>" class="btn btn-small btn-danger" onclick="return confirm('<?php echo t('confirm_delete', 'Are you sure you want to delete this reservation?'); ?>')"><?php echo t('delete', 'Delete'); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html> 