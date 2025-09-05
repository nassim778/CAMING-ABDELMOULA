<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';
require_once 'includes/tariff_helper.php';

// Add the formatTentSpecifications function for tent type formatting
function formatTentSpecifications($tent_type, $tent_specifications) {
    global $trans;
    
    if ($tent_type === 'MIXED' && !empty($tent_specifications)) {
        $specs = explode(', ', $tent_specifications);
        $formatted_specs = [];
        foreach ($specs as $spec) {
            if (preg_match('/Tent \d+: (\w+) - (\w+)/', $spec, $matches)) {
                $type = $matches[1];
                $beds = $matches[2];
                $bed_numbers = [
                    'single' => '1 ' . ($trans['bed'] ?? 'bed'),
                    'double' => '2 ' . ($trans['beds'] ?? 'beds'),
                    'triple' => '3 ' . ($trans['beds'] ?? 'beds'),
                    'quadruple' => '4 ' . ($trans['beds'] ?? 'beds')
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

// Add this function to count tent types
function countTentTypes($tent_specifications) {
    if (empty($tent_specifications)) {
        return '';
    }
    
    $royal_count = 0;
    $normal_count = 0;
    
    // Count ROYAL and NORMAL tents from the specifications
    if (preg_match_all('/Tent \d+: (ROYAL|NORMAL) - \w+/i', $tent_specifications, $matches)) {
        foreach ($matches[1] as $tent_type) {
            if (strtoupper($tent_type) === 'ROYAL') {
                $royal_count++;
            } elseif (strtoupper($tent_type) === 'NORMAL') {
                $normal_count++;
            }
        }
    }
    
    $result = [];
    if ($royal_count > 0) {
        $result[] = $royal_count . ' ROYAL';
    }
    if ($normal_count > 0) {
        $result[] = $normal_count . ' NORMAL';
    }
    
    return implode(', ', $result);
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$id = $_GET['id'] ?? 0;
if (!$id) {
    die('No reservation ID provided.');
}

$stmt = $pdo->prepare("SELECT r.*, g.name as guest_name, g.email, g.phone, g.adults, g.kids, g.babies FROM reservations r JOIN guests g ON r.guest_id = g.id WHERE r.id = ?");
$stmt->execute([$id]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reservation) {
    die('Reservation not found.');
}

$guest = [
    'name' => $reservation['guest_name'],
    'email' => $reservation['email'],
    'phone' => $reservation['phone'],
    'adults' => $reservation['adults'],
    'kids' => $reservation['kids'],
    'babies' => $reservation['babies']
];

// Fetch assigned cars, drivers, and guides for accurate resource days
$stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_cars WHERE reservation_id = ?");
$stmt->execute([$reservation['id']]);
$car_days_total = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $car) {
            $car_days_total += (new DateTime($car['end_date']))->diff(new DateTime($car['start_date']))->days;
}
$reservation['car_days_total'] = $car_days_total;
$stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'driver'");
$stmt->execute([$reservation['id']]);
$driver_days_total = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $driver) {
    $driver_days_total += (new DateTime($driver['end_date']))->diff(new DateTime($driver['start_date']))->days + 1;
}
$reservation['driver_days_total'] = $driver_days_total;
$stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'guide'");
$stmt->execute([$reservation['id']]);
$guide_days_total = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $guide) {
    $guide_days_total += (new DateTime($guide['end_date']))->diff(new DateTime($guide['start_date']))->days + 1;
}
$reservation['guide_days_total'] = $guide_days_total;

// Translation loader and function
$lang = $_SESSION['lang'] ?? 'en';
$trans = [];
if ($lang === 'fr' && file_exists('languages/fr.php')) {
    $trans = include 'languages/fr.php';
}
function t($key, $default) {
    global $trans;
    return $trans[$key] ?? $default;
}

// Create translations array for price breakdown
$translations = [
    'accommodation' => t('accommodation', 'Accommodation'),
    'cars_4x4' => t('cars_4x4', '4x4 Cars'),
    'car_days' => t('car_days', 'car-days'),
    'car' => t('car', 'car'),
    'cars' => t('cars', 'cars'),
    'staff' => t('staff', 'Staff'),
    'driver_days' => t('driver_days', 'driver-days'),
    'guide_days' => t('guide_days', 'guide-days'),
    'services' => t('services', 'Services'),
    'discount' => t('discount', 'Discount'),
    'fixed_amount' => t('fixed_amount', 'Fixed Amount'),
    'tent' => t('tent', 'Tent'),
                        'night' => t('night', 'night'),
                    'nights' => t('nights', 'nights'),
                    'night_abbr' => t('night_abbr', 'N'),
    'day' => t('day', 'day'),
    'days' => t('days', 'days'),
    'exception' => t('exception', 'Exception'),
    'free' => t('free', 'FREE'),
    'effective' => t('effective', 'effective'),
    'due_to' => t('due_to', 'due to'),
    'exception_s' => t('exception_s', 'exception(s)'),
    'all_beds_are_exceptions' => t('all_beds_are_exceptions', 'all beds are exceptions'),
    'all_beds_occupied_by_exceptions_and_kids' => t('all_beds_occupied_by_exceptions_and_kids', 'all beds occupied by exceptions and kids'),
    'effective_single_due_to' => t('effective_single_due_to', 'effective: single due to'),
    'no_price_found' => t('no_price_found', 'No price found'),
    'half_board' => t('half_board', 'Half Board'),
    'full_board' => t('full_board', 'Full Board'),
    'kids_at_discount' => t('kids_at_discount', 'Kids'),
    'at_discount' => t('at_discount', 'at'),
    'percent' => t('percent', '%'),
    'off' => t('off', 'off'),
    'calculation_formula' => t('calculation_formula', 'Calculation'),
    'divided_by' => t('divided_by', '÷'),
    'per_person' => t('per_person', 'person'),
    'times' => t('times', '×'),
    'kids' => t('kids', 'kids'),
    'minus' => t('minus', '-'),
    'total' => t('total', 'Total'),
    'adults' => t('adults', 'Adults'),
            'royal' => t('royal', 'ROYAL'),
    'normal' => t('normal', 'Normal'),
    'single' => t('single', 'Single'),
    'double' => t('double', 'Double'),
    'triple' => t('triple', 'Triple'),
    'quadruple' => t('quadruple', 'Quadruple'),
    'go_camp_and_return' => t('go_camp_and_return', 'go camp and return'),
    'tourist_tax' => t('tourist_tax', 'Tourist Tax')
];

$price_breakdown = getPriceBreakdown($pdo, $reservation, $guest, $translations);

$total_price = 0;
foreach ($price_breakdown as $data) {
    $total_price += $data['price'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo t('receipt_title', 'Receipt'); ?> - <?php echo t('reservation', 'Reservation'); ?> #<?php echo $reservation['id']; ?> | ABDELMOULA CAMP</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
        body { font-family: 'Arial', sans-serif; color: #111; background: url('https://image.over-blog.com/LMvEGPNfNKC4Pkcb9e6aZ1ma1XE=/filters:no_upscale()/image%2F0630431%2F20241216%2Fob_9c1a6b_capture-d-ecran-2024-12-16-a-15-45.png') no-repeat center center fixed;background-size: cover;; margin: 0; padding: 0; }
        .receipt-container { max-width: 700px; margin: 30px auto; background: #fff; border: 1px solid #222; padding: 24px 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
        .receipt-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .receipt-logo { height: 54px; margin-right: 18px; }
        .receipt-title { font-size: 1.7em; font-weight: bold; letter-spacing: 1.5px; margin-bottom: 0; }
        .receipt-id { font-size: 1em; color: #555; margin-bottom: 14px; text-align: right; }
        .section { margin-bottom: 18px; }
        .section-title { font-size: 1.05em; font-weight: bold; border-bottom: 1px solid #222; margin-bottom: 7px; padding-bottom: 2px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 7px; }
        .info-table td { padding: 3px 0; }
        .breakdown-table { width: 100%; border-collapse: collapse; margin-top: 7px; }
        .breakdown-table th, .breakdown-table td { border: 1px solid #222; padding: 5px 7px; text-align: left; }
        .breakdown-table th { background: #f5f5f5; }
        .total-row td { font-weight: bold; font-size: 1.05em; }
        .print-btn { display: inline-block; margin: 14px auto 0; padding: 8px 22px; background: #222; color: #fff; border: none; font-size: 1em; border-radius: 4px; cursor: pointer; }
        .sections-row { display: flex; gap: 20px; margin-bottom: 18px; }
        .section-half { flex: 1; }
        @media print {
            .print-btn { display: none; }
            body { background: #fff !important; }
            .receipt-container { box-shadow: none; border: none; }
            @page { size: A4; margin: 18mm 12mm 18mm 12mm; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <img src="assets/images/logo.png" alt="Camp Logo" class="receipt-logo">
            <div>
                <div class="receipt-title"><?php echo t('receipt_title', 'Receipt'); ?> / <?php echo t('invoice', 'Facture'); ?></div>
                <div class="receipt-id"><?php echo t('reservation', 'Reservation'); ?> #<?php echo $reservation['id']; ?></div>
            </div>
        </div>
        <div class="sections-row">
            <div class="section-half">
                <div class="section-title"><?php echo t('guest_information', 'Guest Information'); ?></div>
                <table class="info-table">
                    <tr><td><strong><?php echo t('name', 'Name'); ?>:</strong></td><td><?php echo htmlspecialchars($reservation['guest_name']); ?></td></tr>
                    <?php if ($reservation['email']): ?><tr><td><strong><?php echo t('email', 'Email'); ?>:</strong></td><td><?php echo htmlspecialchars($reservation['email']); ?></td></tr><?php endif; ?>
                    <?php if ($reservation['phone']): ?><tr><td><strong><?php echo t('phone', 'Phone'); ?>:</strong></td><td><?php echo htmlspecialchars($reservation['phone']); ?></td></tr><?php endif; ?>
                    <tr><td><strong><?php echo t('reservation_source', 'Reservation Source'); ?>:</strong></td><td><?php echo t($reservation['reservation_source'], ucfirst($reservation['reservation_source'])); ?></td></tr>
                    <?php if ($reservation['reservation_source'] === 'agency' && $reservation['agency_name']): ?><tr><td><strong><?php echo t('agency', 'Agency'); ?>:</strong></td><td><?php echo htmlspecialchars($reservation['agency_name']); ?></td></tr><?php endif; ?>
                    <tr><td><strong><?php echo t('group_size', 'Group Size'); ?>:</strong></td><td><?php echo $reservation['adults']; ?> <?php echo t('adults', 'Adults'); ?>, <?php echo $reservation['kids']; ?> <?php echo t('kids', 'Kids'); ?>, <?php echo $reservation['babies']; ?> <?php echo t('babies', 'Babies'); ?></td></tr>
                </table>
            </div>
            <div class="section-half">
                <div class="section-title"><?php echo t('reservation_details', 'Reservation Details'); ?></div>
                <table class="info-table">
                    <tr><td><strong><?php echo t('check_in', 'Check-in'); ?>:</strong></td><td><?php echo date('d/m/Y', strtotime($reservation['check_in_date'])); ?></td></tr>
                    <tr><td><strong><?php echo t('check_out', 'Check-out'); ?>:</strong></td><td><?php echo date('d/m/Y', strtotime($reservation['check_out_date'])); ?></td></tr>
                    <tr><td><strong><?php echo t('nights', 'Nights'); ?>:</strong></td><td><?php echo $reservation['nights']; ?></td></tr>
                    <tr><td><strong><?php echo t('number_of_tents', 'Number of Tents'); ?>:</strong></td><td><?php echo $reservation['number_of_tents']; ?></td></tr>
                    <tr><td><strong><?php echo t('tent_type', 'Tent Type'); ?>:</strong></td><td><?php echo htmlspecialchars(countTentTypes($reservation['tent_specifications'])); ?></td></tr>
                </table>
            </div>
        </div>
        <div class="section">
            <div class="section-title"><?php echo t('price_breakdown', 'Price Breakdown'); ?></div>
            <table class="breakdown-table">
                <thead><tr><th><?php echo t('category', 'Category'); ?></th><th><?php echo t('details', 'Details'); ?></th><th><?php echo t('amount', 'Amount'); ?> (TND)</th></tr></thead>
                <tbody>
                <?php foreach ($price_breakdown as $category => $data): ?>
                    <tr style="<?php if ($category === 'discount') echo 'color:#b00;font-weight:bold;'; ?>">
                        <td><?php echo t($data['label'], $data['label']); ?></td>
                        <td>
                            <?php if (!empty($data['details'])): ?>
                                <?php if (is_array($data['details'])): ?>
                                    <?php foreach ($data['details'] as $detail): ?>
                                        <div><?php echo htmlspecialchars($detail); ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($data['details']); ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($data['price'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2"><?php echo t('total', 'Total'); ?></td>
                        <td><?php echo number_format($total_price, 2); ?> TND</td>
                    </tr>
                    <?php if ($reservation['payment_cash'] > 0 || $reservation['payment_bank_check'] > 0 || ($reservation['payment_transfer'] ?? 0) > 0): ?>
                    <tr>
                        <td colspan="2"><?php echo t('payment_details', 'Payment Details'); ?></td>
                        <td>
                            <?php if ($reservation['payment_cash'] > 0): ?>
                                <div><?php echo t('cash', 'Cash'); ?>: <?php echo number_format($reservation['payment_cash'], 2); ?> TND</div>
                            <?php endif; ?>
                            <?php if ($reservation['payment_bank_check'] > 0): ?>
                                <div><?php echo t('bank_check', 'Bank Check'); ?>: <?php echo number_format($reservation['payment_bank_check'], 2); ?> TND</div>
                            <?php endif; ?>
                            <?php if (($reservation['payment_transfer'] ?? 0) > 0): ?>
                                <div><?php echo t('transfer_payment', 'Transfer'); ?>: <?php echo number_format($reservation['payment_transfer'], 2); ?> TND</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="2"><?php echo t('remaining', 'Remaining'); ?></td>
                        <td>
                            <?php 
                            $total_paid = ($reservation['payment_cash'] ?? 0) + ($reservation['payment_bank_check'] ?? 0) + ($reservation['payment_transfer'] ?? 0);
                            echo number_format($total_price - $total_paid, 2); 
                            ?> TND
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <button class="print-btn" onclick="window.print()"><?php echo t('print', 'Print'); ?></button>
    </div>
</body>
</html> 