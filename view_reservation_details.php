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

$id = $_GET['id'] ?? 0;

if (!$id) {
    header('Location: dashboard.php?error=No reservation ID provided');
    exit();
}

// Handle status change actions
$action = $_GET['action'] ?? '';
if ($action && in_array($action, ['cancel', 'done', 'activate'])) {
    try {
        $new_status = '';
        switch ($action) {
            case 'cancel':
                $new_status = 'canceled';
                break;
            case 'done':
                $new_status = 'done';
                break;
            case 'activate':
                $new_status = 'active';
                break;
        }
        
        if ($new_status) {
            $update_stmt = $pdo->prepare("UPDATE reservations SET reservation_status = ?, updated_at = NOW() WHERE id = ?");
            $update_stmt->execute([$new_status, $id]);
            
            // Redirect back to the same page with success message
            header("Location: view_reservation_details.php?id=$id&success=status_updated");
            exit();
        }
    } catch (Exception $e) {
        // Redirect back to the same page with error message
        header("Location: view_reservation_details.php?id=$id&error=status_update_failed");
        exit();
    }
}

try {
    // Get reservation with guest details and tariff version
    $stmt = $pdo->prepare("SELECT r.*, g.name as guest_name, g.email, g.phone, g.nationality, g.adults, g.kids, g.babies, tv.name as tariff_version_name
                           FROM reservations r 
                           JOIN guests g ON r.guest_id = g.id 
                           LEFT JOIN tariff_versions tv ON r.tariff_version_id = tv.id
                           WHERE r.id = ?");
    $stmt->execute([$id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        header('Location: dashboard.php?error=Reservation not found');
        exit();
    }

    // Decode services data if exists
    $services_data = [];
    if (!empty($reservation['services_data'])) {
        $services_data = json_decode($reservation['services_data'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $services_data = [];
        }
    }

    // Create guest array from reservation data
    $guest = [
        'name' => $reservation['guest_name'],
        'email' => $reservation['email'],
        'phone' => $reservation['phone'],
        'adults' => $reservation['adults'],
        'kids' => $reservation['kids'],
        'babies' => $reservation['babies']
    ];

    // Fetch assigned cars
    $assigned_cars = [];
    $car_period_min = null;
    $car_period_max = null;
    $car_days_total = 0;
    $stmt = $pdo->prepare("SELECT c.registration_number, rc.start_date, rc.end_date FROM reservation_cars rc JOIN cars c ON rc.car_id = c.id WHERE rc.reservation_id = ?");
    $stmt->execute([$id]);
    $assigned_cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assigned_cars as $car) {
        if (!$car_period_min || $car['start_date'] < $car_period_min) $car_period_min = $car['start_date'];
        if (!$car_period_max || $car['end_date'] > $car_period_max) $car_period_max = $car['end_date'];
        $car_days_total += (new DateTime($car['end_date']))->diff(new DateTime($car['start_date']))->days;
    }

    // Fetch assigned drivers
    $assigned_drivers = [];
    $driver_period_min = null;
    $driver_period_max = null;
    $driver_days_total = 0;
    $stmt = $pdo->prepare("SELECT h.full_name, rh.start_date, rh.end_date FROM reservation_humans rh JOIN human_resources h ON rh.human_id = h.id WHERE rh.reservation_id = ? AND rh.role = 'driver'");
    $stmt->execute([$id]);
    $assigned_drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assigned_drivers as $driver) {
        if (!$driver_period_min || $driver['start_date'] < $driver_period_min) $driver_period_min = $driver['start_date'];
        if (!$driver_period_max || $driver['end_date'] > $driver_period_max) $driver_period_max = $driver['end_date'];
        $driver_days_total += (new DateTime($driver['end_date']))->diff(new DateTime($driver['start_date']))->days + 1;
    }

    // Fetch assigned guides
    $assigned_guides = [];
    $guide_period_min = null;
    $guide_period_max = null;
    $guide_days_total = 0;
    $stmt = $pdo->prepare("SELECT h.full_name, rh.start_date, rh.end_date FROM reservation_humans rh JOIN human_resources h ON rh.human_id = h.id WHERE rh.reservation_id = ? AND rh.role = 'guide'");
    $stmt->execute([$id]);
    $assigned_guides = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assigned_guides as $guide) {
        if (!$guide_period_min || $guide['start_date'] < $guide_period_min) $guide_period_min = $guide['start_date'];
        if (!$guide_period_max || $guide['end_date'] > $guide_period_max) $guide_period_max = $guide['end_date'];
        $guide_days_total += (new DateTime($guide['end_date']))->diff(new DateTime($guide['start_date']))->days + 1;
    }

    // Fetch assigned tents
    $assigned_tents = [];
    $stmt = $pdo->prepare("SELECT t.tent_number, rt.start_date, rt.end_date, t.tent_type FROM reservation_tents rt JOIN tents t ON rt.tent_id = t.id WHERE rt.reservation_id = ?");
    $stmt->execute([$id]);
    $assigned_tents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log the assigned tents and also check raw data
    error_log("View reservation $id - Assigned tents: " . print_r($assigned_tents, true));
    
    // Also check raw reservation_tents data
    $raw_stmt = $pdo->prepare("SELECT * FROM reservation_tents WHERE reservation_id = ?");
    $raw_stmt->execute([$id]);
    $raw_tents = $raw_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("View reservation $id - Raw reservation_tents: " . print_r($raw_tents, true));

    // Before price breakdown, add calculated resource days to reservation array
    $reservation['car_days_total'] = $car_days_total;
    $reservation['driver_days_total'] = $driver_days_total;
    $reservation['guide_days_total'] = $guide_days_total;

} catch (Exception $e) {
    header('Location: dashboard.php?error=Database error: ' . urlencode($e->getMessage()));
    exit();
}
?>

<?php
// Add translation loader and t() function before HTML output
$lang = $_SESSION['lang'] ?? 'en';
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
    <title><?php echo t('reservation_details_title', 'Reservation Details'); ?> - #<?php echo $reservation['id']; ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .reservation-source {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .reservation-source.individual {
            background: #d4edda;
            color: #155724;
        }
        .reservation-source.agency {
            background: #fff3cd;
            color: #856404;
        }
        .price-details {
            margin-left: 20px;
            margin-bottom: 10px;
            color: #666;
            font-size: 12px;
        }
        .reservation-details-grid {
  padding: 18px 12px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  margin-bottom: 18px;
}
.details-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 10px 24px;
  font-size: 14px;
  color: #222;
  align-items: center;
}
        .reservation-details-grid h3 {
          font-size: 1.1rem;
          color: #4a5a6a;
          margin-bottom: 10px;
        }
        
        .exceptions-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .exception-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #28a745;
        }
        
        .exception-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        
        .exception-type {
            background: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: normal;
        }
        
        .exception-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .exception-tent {
            color: #495057;
            font-weight: 500;
        }
        
        .exception-price {
            color: #28a745;
            font-weight: 500;
        }
        
        .exception-price.free {
            color: #dc3545;
        }
        
        .exception-notes {
            width: 100%;
            margin-top: 8px;
            color: #6c757d;
            font-style: italic;
        }
        
        .boarding-details {
            margin-top: 8px;
        }
        
        .boarding-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            padding: 6px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .boarding-tent {
            font-weight: 600;
            color: #495057;
            min-width: 60px;
        }
        
        .boarding-type {
            color: #6c757d;
            font-style: italic;
        }
        
        .boarding-cost {
            margin-left: auto;
            color: #28a745;
            font-weight: 500;
        }
        
        .boarding-total {
            color: #28a745;
            font-weight: 600;
            font-size: 16px;
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="table-header">
            <h2><?php echo t('reservation_details_title', 'Reservation Details'); ?> - #<?php echo $reservation['id']; ?></h2>
            <div>
                <a href="edit_reservation.php?id=<?php echo $reservation['id']; ?>" class="btn btn-primary"><?php echo t('edit_reservation', 'Edit Reservation'); ?></a>
                <a href="reservation_camp_summary.php?id=<?php echo $reservation['id']; ?>" class="btn btn-info" style="background:#f5b041;color:#222;border:1px solid #f5b041;"><?php echo t('camp_summary', 'Camp Summary'); ?></a>
                <a href="dashboard.php" class="btn btn-secondary"><?php echo t('back_to_dashboard', 'Back to Dashboard'); ?></a>
                <a href="reservation_receipt.php?id=<?php echo $reservation['id']; ?>" target="_blank" class="btn btn-success" style="background:#222;color:#fff;border:1px solid #222;"><?php echo t('print_receipt', 'Print Receipt'); ?></a>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success']) && $_GET['success'] === 'status_updated'): ?>
            <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 18px; border: 1px solid #c3e6cb;">
                <?php echo t('status_updated_success', 'Reservation status updated successfully!'); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error']) && $_GET['error'] === 'status_update_failed'): ?>
            <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 18px; border: 1px solid #f5c6cb;">
                <?php echo t('status_update_failed', 'Failed to update reservation status. Please try again.'); ?>
            </div>
        <?php endif; ?>

        <!-- Guest Information (gold cards style) -->
        <div class="form-section" style="background:#fff;border-radius:8px;padding:18px 16px 10px 16px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,0.04);border:1px solid #f5e6c5;">
            <h3 style="color:#b48b0f;font-size:1.15rem;margin-bottom:10px;font-weight:700;"><?php echo t('guest_information', 'Guest Information'); ?></h3>
            <div class="detail-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 18px;font-size:15px;color:#222;align-items:center;">
                <div style="background:#fcfaf6;border-left:4px solid #f5c242;border-radius:6px;padding:10px 14px;min-height:48px;"><strong><?php echo t('name', 'Name'); ?>:</strong><br><?php echo htmlspecialchars($reservation['guest_name']); ?></div>
                <?php if ($reservation['email']): ?><div style="background:#fcfaf6;border-left:4px solid #f5c242;border-radius:6px;padding:10px 14px;min-height:48px;"><strong><?php echo t('email', 'Email'); ?>:</strong><br><?php echo htmlspecialchars($reservation['email']); ?></div><?php endif; ?>
                <?php if ($reservation['phone']): ?><div style="background:#fcfaf6;border-left:4px solid #f5c242;border-radius:6px;padding:10px 14px;min-height:48px;"><strong><?php echo t('phone', 'Phone'); ?>:</strong><br><?php echo htmlspecialchars($reservation['phone']); ?></div><?php endif; ?>
                <?php if ($reservation['nationality']): ?><div style="background:#fcfaf6;border-left:4px solid #f5c242;border-radius:6px;padding:10px 14px;min-height:48px;"><strong><?php echo t('nationality', 'Nationality'); ?>:</strong><br><?php echo htmlspecialchars($reservation['nationality']); ?></div><?php endif; ?>
                <div style="background:#fcfaf6;border-left:4px solid #f5c242;border-radius:6px;padding:10px 14px;min-height:48px;"><strong><?php echo t('reservation_source', 'Reservation Source'); ?>:</strong><br><span class="reservation-source <?php echo $reservation['reservation_source']; ?>" style="background:#ffe9b3;color:#b48b0f;padding:2px 10px;border-radius:12px;font-size:13px;font-weight:600;text-transform:uppercase;vertical-align:middle;display:inline-block;margin-top:2px;">
                    <?php echo strtoupper($reservation['reservation_source']); ?></span></div>
                <?php if ($reservation['reservation_source'] === 'agency' && $reservation['agency_name']): ?>
                <div style="background:#fcfaf6;border-left:4px solid #f5c242;border-radius:6px;padding:10px 14px;min-height:48px;"><strong><?php echo t('agency', 'Agency'); ?>:</strong><br><?php echo htmlspecialchars($reservation['agency_name']); ?></div>
                <?php endif; ?>
                <div style="background:#fcfaf6;border-left:4px solid #f5c242;border-radius:6px;padding:10px 14px;min-height:48px;">
                    <strong><?php echo t('group_size', 'Group Size'); ?>:</strong><br>
                    <span style="display:inline-block;background:#eaf7ea;color:#2e7d32;padding:2px 8px;border-radius:8px;font-size:13px;margin-right:4px;font-weight:600;">
                        <?php echo (int)$reservation['adults']; ?> <?php echo t('adults', 'Adults'); ?>
                    </span>
                    <span style="display:inline-block;background:#fff3e0;color:#e65100;padding:2px 8px;border-radius:8px;font-size:13px;margin-right:4px;font-weight:600;">
                        <?php echo (int)$reservation['kids']; ?> <?php echo t('kids', 'Kids'); ?>
                    </span>
                    <span style="display:inline-block;background:#fce4ec;color:#ad1457;padding:2px 8px;border-radius:8px;font-size:13px;font-weight:600;">
                        <?php echo (int)$reservation['babies']; ?> <?php echo t('babies', 'Babies'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Reservation Details -->
        <div class="form-section">
            <h3><?php echo t('reservation_details', 'Reservation Details'); ?></h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <strong><?php echo t('check_in', 'Check-in'); ?>:</strong> <?php echo date('d/m/Y', strtotime($reservation['check_in_date'])); ?>
                </div>
                <div class="detail-item">
                    <strong><?php echo t('check_out', 'Check-out'); ?>:</strong> <?php echo date('d/m/Y', strtotime($reservation['check_out_date'])); ?>
                </div>
                <div class="detail-item">
                    <strong><?php echo t('nights', 'Nights'); ?>:</strong> <?php echo $reservation['nights']; ?>
                </div>
                <div class="detail-item">
                    <strong><?php echo t('tariff_version', 'Tariff Version'); ?>:</strong> 
                    <span class="tariff-badge"><?php echo htmlspecialchars($reservation['tariff_version_name'] ?? 'Default'); ?></span>
                </div>
            </div>
            <!-- Cars Group -->
        <?php if ($reservation['cars_4x4'] > 0): ?>
            <?php
                // Display days should be nights + 1 when a valid period exists
                $car_days_display = ($car_period_min && $car_period_max) ? ($car_days_total + 1) : $car_days_total;
                $nights_label = ($car_days_total === 1) ? t('night', 'night') : t('nights', 'nights');
                $days_label = ($car_days_display === 1) ? t('day', 'day') : t('days', 'days');
            ?>
            <div class="detail-grid" style="margin-top:10px;">
                <div class="detail-item"><strong><?php echo t('number_of_cars', 'Number of Cars'); ?>:</strong> <?php echo $reservation['cars_4x4']; ?></div>
                <div class="detail-item"><strong><?php echo t('car_usage_period', 'Car Usage Period'); ?>:</strong> <?php echo $car_period_min ? date('d/m/Y', strtotime($car_period_min)) : 'N/A'; ?> <?php echo t('to', 'to'); ?> <?php echo $car_period_max ? date('d/m/Y', strtotime($car_period_max)) : 'N/A'; ?></div>
                <div class="detail-item"><strong><?php echo t('car_days', 'Car Days'); ?>:</strong> <?php echo $car_days_display; ?> <small style="color:#666;">(<?php echo $car_days_total; ?> <?php echo $nights_label; ?> - <?php echo t('go_camp_and_return', 'go camp and return'); ?>)</small></div>
                <div class="detail-item"><strong><?php echo t('car_cost', 'Car Cost'); ?>:</strong> <?php echo number_format(calculateCarPricingCorrectly($pdo, $id), 2); ?> TND</div>
            </div>
            <?php endif; ?>
            <!-- Drivers Group -->
            <?php if ($reservation['staff_drivers'] > 0): ?>
            <div class="detail-grid" style="margin-top:10px;">
                <div class="detail-item"><strong><?php echo t('number_of_drivers', 'Number of Drivers'); ?>:</strong> <?php echo $reservation['staff_drivers']; ?></div>
                <div class="detail-item"><strong><?php echo t('driver_usage_period', 'Driver Usage Period'); ?>:</strong> <?php echo $driver_period_min ? date('d/m/Y', strtotime($driver_period_min)) : 'N/A'; ?> <?php echo t('to', 'to'); ?> <?php echo $driver_period_max ? date('d/m/Y', strtotime($driver_period_max)) : 'N/A'; ?></div>
                <div class="detail-item"><strong><?php echo t('driver_days', 'Driver Days'); ?>:</strong> <?php echo $driver_days_total; ?></div>
                <div class="detail-item"><strong><?php echo t('driver_cost', 'Driver Cost'); ?>:</strong> <?php $driver_price = getDriverPrice($pdo); echo number_format($driver_price * $driver_days_total, 2); ?> TND</div>
        </div>
        <?php endif; ?>
            <!-- Guides Group -->
            <?php if ($reservation['staff_guides'] > 0): ?>
            <div class="detail-grid" style="margin-top:10px;">
                <div class="detail-item"><strong><?php echo t('number_of_guides', 'Number of Guides'); ?>:</strong> <?php echo $reservation['staff_guides']; ?></div>
                <div class="detail-item"><strong><?php echo t('guide_usage_period', 'Guide Usage Period'); ?>:</strong> <?php echo $guide_period_min ? date('d/m/Y', strtotime($guide_period_min)) : 'N/A'; ?> <?php echo t('to', 'to'); ?> <?php echo $guide_period_max ? date('d/m/Y', strtotime($guide_period_max)) : 'N/A'; ?></div>
                <div class="detail-item"><strong><?php echo t('guide_days', 'Guide Days'); ?>:</strong> <?php echo $guide_days_total; ?></div>
                <div class="detail-item"><strong><?php echo t('guide_cost', 'Guide Cost'); ?>:</strong> <?php $guide_price = getGuidePrice($pdo); echo number_format($guide_price * $guide_days_total, 2); ?> TND</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Boarding Information -->
        <?php
        // Parse boarding information from the dedicated column
        $boarding_info = [];
        $boarding_total_cost = 0;
        if (!empty($reservation['boarding_information'])) {
            $boarding_data = json_decode($reservation['boarding_information'], true);
            if (isset($boarding_data['tents']) && is_array($boarding_data['tents'])) {
                foreach ($boarding_data['tents'] as $boarding) {
                    $tent_number = $boarding['tent_number'];
                    $tent_type = $boarding['tent_type'];
                    $bed_config = $boarding['bed_config'];
                    
                    // Process Half Board
                    if (isset($boarding['half_board_days']) && $boarding['half_board_days'] > 0) {
                        $days = intval($boarding['half_board_days']);
                        $price_per_night = getBoardingPrice($pdo, $reservation['reservation_source'], $tent_type, $bed_config, 'half_board', $reservation['tariff_version_id']);
                        $total_cost = $price_per_night * $days;
                        $boarding_total_cost += $total_cost;
                        
                        if (!isset($boarding_info['half_board'])) {
                            $boarding_info['half_board'] = [];
                        }
                        $boarding_info['half_board'][] = [
                            'tent' => $tent_number,
                            'type' => $tent_type,
                            'bed_config' => $bed_config,
                            'days' => $days,
                            'price_per_night' => $price_per_night,
                            'total_cost' => $total_cost
                        ];
                    }
                    
                    // Process Full Board
                    if (isset($boarding['full_board_days']) && $boarding['full_board_days'] > 0) {
                        $days = intval($boarding['full_board_days']);
                        $price_per_night = getBoardingPrice($pdo, $reservation['reservation_source'], $tent_type, $bed_config, 'full_board', $reservation['tariff_version_id']);
                        $total_cost = $price_per_night * $days;
                        $boarding_total_cost += $total_cost;
                        
                        if (!isset($boarding_info['full_board'])) {
                            $boarding_info['full_board'] = [];
                        }
                        $boarding_info['full_board'][] = [
                            'tent' => $tent_number,
                            'type' => $tent_type,
                            'bed_config' => $bed_config,
                            'days' => $days,
                            'price_per_night' => $price_per_night,
                            'total_cost' => $total_cost
                        ];
                    }
                }
            }
        }
        ?>
        <?php if (!empty($boarding_info)): ?>
        <div class="form-section">
            <h3><?php echo t('boarding_information', 'Boarding Information'); ?></h3>
            <div class="detail-grid">
                <?php if (!empty($boarding_info['half_board'])): ?>
                <div class="detail-item full-width">
                    <strong><?php echo t('half_board', 'Half Board'); ?>:</strong>
                    <div class="boarding-details">
                        <?php foreach ($boarding_info['half_board'] as $boarding): ?>
                        <div class="boarding-item">
                            <span class="boarding-tent"><?php echo t('tent', 'Tent'); ?> <?php echo $boarding['tent']; ?></span>
                            <span class="boarding-type">(<?php echo $boarding['type']; ?> - <?php echo $boarding['bed_config']; ?>)</span>
                            <span class="boarding-cost"><?php echo $boarding['price_per_night']; ?> TND/<?php echo t('night', 'night'); ?> × <?php echo $boarding['days']; ?> <?php echo t('nights', 'nights'); ?> = <?php echo number_format($boarding['total_cost'], 2); ?> TND</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($boarding_info['full_board'])): ?>
                <div class="detail-item full-width">
                    <strong><?php echo t('full_board', 'Full Board'); ?>:</strong>
                    <div class="boarding-details">
                        <?php foreach ($boarding_info['full_board'] as $boarding): ?>
                        <div class="boarding-item">
                            <span class="boarding-tent"><?php echo t('tent', 'Tent'); ?> <?php echo $boarding['tent']; ?></span>
                            <span class="boarding-type">(<?php echo $boarding['type']; ?> - <?php echo $boarding['bed_config']; ?>)</span>
                            <span class="boarding-cost"><?php echo $boarding['price_per_night']; ?> TND/<?php echo t('night', 'night'); ?> × <?php echo $boarding['days']; ?> <?php echo t('nights', 'nights'); ?> = <?php echo number_format($boarding['total_cost'], 2); ?> TND</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <strong><?php echo t('boarding_total_cost', 'Boarding Total Cost'); ?>:</strong> 
                    <span class="boarding-total"><?php echo number_format($boarding_total_cost, 2); ?> TND</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Services -->
        <?php if (!empty($services_data)): ?>
        <div class="form-section">
            <h3><?php echo t('services', 'Services'); ?></h3>
            <div class="services-list">
                <?php 
                // Fetch all services for name lookup
                $all_services = $pdo->query("SELECT id, name FROM services")->fetchAll(PDO::FETCH_KEY_PAIR);
                $services_total = 0;
                foreach ($services_data as $service_id => $service_data): 
                    if (isset($service_data['selected']) && $service_data['selected'] == '1'):
                        $service_price = floatval($service_data['price'] ?? 0);
                        $services_total += $service_price;
                        $service_name = $all_services[$service_id] ?? ($service_data['name'] ?? 'Service');
                ?>
                <div class="service-detail">
                    <strong><?php echo htmlspecialchars($service_name); ?>:</strong>
                    <?php echo t('price', 'Price'); ?>: <?php echo number_format($service_price, 2); ?> TND
                </div>
                <?php 
                    endif;
                endforeach; 
                ?>
                <div class="service-total">
                    <strong><?php echo t('services_total', 'Services Total'); ?>:</strong> <?php echo number_format($services_total, 2); ?> TND
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Accommodation Details -->
        <div class="form-section">
            <h3><?php echo t('accommodation_details', 'Accommodation Details'); ?></h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <strong><?php echo t('tent_type', 'Tent Type'); ?>:</strong> <?php echo htmlspecialchars(formatTentSpecifications($reservation['tent_type'], $reservation['tent_specifications'])); ?>
                </div>
                <div class="detail-item">
                    <strong><?php echo t('number_of_tents', 'Number of Tents'); ?>:</strong> <?php echo $reservation['number_of_tents']; ?>
                </div>
                <?php if ($reservation['tent_type'] === 'MIXED'): ?>
                <div class="detail-item">
                                    <strong><?php echo t('royal_tent_guests', 'ROYAL Tent Guests'); ?>:</strong> <?php echo t('adults', 'Adults'); ?>: <?php echo ($reservation['royal_adults_kids'] ? explode(':', $reservation['royal_adults_kids'])[0] : 0); ?>,
                <?php echo t('kids', 'Kids'); ?>: <?php echo ($reservation['royal_adults_kids'] ? explode(':', $reservation['royal_adults_kids'])[1] : 0); ?>
                </div>
                <div class="detail-item">
                    <strong><?php echo t('normal_tent_guests', 'Normal Tent Guests'); ?>:</strong> <?php echo t('adults', 'Adults'); ?>: <?php echo ($reservation['normal_adults_kids'] ? explode(':', $reservation['normal_adults_kids'])[0] : 0); ?>,
                    <?php echo t('kids', 'Kids'); ?>: <?php echo ($reservation['normal_adults_kids'] ? explode(':', $reservation['normal_adults_kids'])[1] : 0); ?>
                </div>
                <?php endif; ?>
                <?php if ($reservation['tent_specifications']): ?>
                <div class="detail-item full-width">
                    <strong><?php echo t('detailed_specifications', 'Detailed Specifications'); ?>:</strong>
                    <div class="tent-specifications">
                        <?php 
                        $tent_specs = explode(', ', $reservation['tent_specifications']);
                        // Build a mapping of assigned tents by type and tent_number for easy lookup
                        $assigned_tent_map = [];
                        foreach ($assigned_tents as $tent) {
                            $assigned_tent_map[strtoupper($tent['tent_type']) . '-' . $tent['tent_number']] = $tent;
                        }
                        foreach ($tent_specs as $spec) {
                            if (preg_match('/Tent (\d+): (\w+) - (\w+)(?:\s*\(Tent #(\d+)\))?/', $spec, $matches)) {
                                $tent_label = $matches[1];
                                $type = strtoupper($matches[2]);
                                $beds = $matches[3];
                                $tent_number = isset($matches[4]) ? $matches[4] : null;
                                $assigned = null;
                                if ($tent_number !== null) {
                                    $key = $type . '-' . $tent_number;
                                    $assigned = isset($assigned_tent_map[$key]) ? $assigned_tent_map[$key] : null;
                                }
                                echo '<div class="tent-item">';
                                echo '<span class="tent-number">Tent ' . htmlspecialchars($tent_label) . '</span> ';
                                echo '<span class="tent-details">' . htmlspecialchars($type) . ' - ' . ucfirst($beds);
                                if ($assigned) {
                                    echo ' (' . t('assigned_tent', 'Assigned Tent') . ': #' . htmlspecialchars($assigned['tent_number']) . ' ' . htmlspecialchars($assigned['tent_type']) . ')';
                                } elseif ($tent_number !== null) {
                                    echo ' (' . t('tent_number', 'Tent Number') . ' ' . htmlspecialchars($tent_number) . ')';
                                }
                                echo '</span>';
                                echo '</div>';
                            } else {
                                echo '<div class="tent-item">';
                                echo '<span class="tent-details">' . htmlspecialchars($spec) . '</span>';
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Assigned Resources -->
        <div class="form-section">
            <h3><?php echo t('assigned_resources', 'Assigned Resources'); ?></h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <strong><?php echo t('assigned_tents', 'Assigned Tents'); ?>:</strong>
                    <?php if (count($assigned_tents) > 0): ?>
                        <ul>
                        <?php foreach ($assigned_tents as $tent): ?>
                            <li><?php echo htmlspecialchars($tent['tent_number']); ?> (<?php echo htmlspecialchars($tent['tent_type']); ?>, <?php echo date('d/m/Y', strtotime($tent['start_date'])); ?> <?php echo t('to', 'to'); ?> <?php echo date('d/m/Y', strtotime($tent['end_date'])); ?>)</li>
                        <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <em><?php echo t('none', 'None'); ?></em>
                    <?php endif; ?>
                </div>
                <div class="detail-item">
                    <strong><?php echo t('assigned_cars', 'Assigned Cars'); ?>:</strong>
                    <?php if (count($assigned_cars) > 0): ?>
                        <ul>
                        <?php foreach ($assigned_cars as $car): ?>
                            <li><?php echo htmlspecialchars($car['registration_number']); ?> (<?php echo date('d/m/Y', strtotime($car['start_date'])); ?> <?php echo t('to', 'to'); ?> <?php echo date('d/m/Y', strtotime($car['end_date'])); ?>)</li>
                        <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <em><?php echo t('none', 'None'); ?></em>
                    <?php endif; ?>
                </div>
                <div class="detail-item">
                    <strong><?php echo t('assigned_drivers', 'Assigned Drivers'); ?>:</strong>
                    <?php if (count($assigned_drivers) > 0): ?>
                        <ul>
                        <?php foreach ($assigned_drivers as $driver): ?>
                            <li><?php echo htmlspecialchars($driver['full_name']); ?> (<?php echo date('d/m/Y', strtotime($driver['start_date'])); ?> <?php echo t('to', 'to'); ?> <?php echo date('d/m/Y', strtotime($driver['end_date'])); ?>)</li>
                        <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <em><?php echo t('none', 'None'); ?></em>
                    <?php endif; ?>
                </div>
                <div class="detail-item">
                    <strong><?php echo t('assigned_guides', 'Assigned Guides'); ?>:</strong>
                    <?php if (count($assigned_guides) > 0): ?>
                        <ul>
                        <?php foreach ($assigned_guides as $guide): ?>
                            <li><?php echo htmlspecialchars($guide['full_name']); ?> (<?php echo date('d/m/Y', strtotime($guide['start_date'])); ?> <?php echo t('to', 'to'); ?> <?php echo date('d/m/Y', strtotime($guide['end_date'])); ?>)</li>
                        <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <em><?php echo t('none', 'None'); ?></em>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Price Breakdown -->
        <div class="form-section">
            <h3><?php echo t('price_breakdown', 'Price Breakdown'); ?></h3>
            <div class="price-breakdown">
                <?php
                // Get price breakdown using new tariff system
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
                    'go_camp_and_return' => t('go_camp_and_return', 'go camp and return'),
                    'tourist_tax' => t('tourist_tax', 'Tourist Tax')
                ];
                $price_breakdown = getPriceBreakdown($pdo, $reservation, $guest, $translations);
                $total_price = 0;
                foreach ($price_breakdown as $category => $data):
                    // Always show discount (even if negative), sum all lines
                    $total_price += $data['price'];
                ?>
                <div class="price-item" style="<?php if ($category === 'discount') echo 'color:#b00;font-weight:bold;'; ?>">
                    <span><?php echo htmlspecialchars($data['label']); ?>:</span>
                    <span><?php echo number_format($data['price'], 2); ?> TND</span>
                </div>
                <?php if (!empty($data['details'])): ?>
                <div class="price-details">
                    <?php if (is_array($data['details'])): ?>
                        <?php foreach ($data['details'] as $detail): ?>
                            <small><?php echo htmlspecialchars($detail); ?></small><br>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <small><?php echo htmlspecialchars($data['details']); ?></small>
                <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
                <div class="price-total">
                    <strong><?php echo t('total_price', 'Total Price'); ?>:</strong>
                    <strong><?php echo number_format($total_price, 2); ?> TND</strong>
                </div>
            </div>
        </div>

        <!-- Payment Information -->
        <div class="form-section">
            <h3><?php echo t('payment_information', 'Payment Information'); ?></h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <strong><?php echo t('payment_status', 'Payment Status'); ?>:</strong> 
                    <span class="payment-status <?php echo $reservation['payment_status']; ?>">
                        <?php echo t($reservation['payment_status'], ucfirst($reservation['payment_status'])); ?>
                    </span>
                </div>
                <?php if ($reservation['payment_cash'] > 0): ?>
                <div class="detail-item">
                    <strong><?php echo t('cash_payment', 'Cash Payment'); ?>:</strong> <?php echo number_format($reservation['payment_cash'], 2); ?> TND
                </div>
                <?php endif; ?>
                <?php if ($reservation['payment_bank_check'] > 0): ?>
                <div class="detail-item">
                    <strong><?php echo t('bank_check', 'Bank Check'); ?>:</strong> <?php echo number_format($reservation['payment_bank_check'], 2); ?> TND
                </div>
                <?php endif; ?>
                <?php if ($reservation['payment_transfer'] > 0): ?>
                <div class="detail-item">
                    <strong><?php echo t('transfer_payment', 'Transfer Payment'); ?>:</strong> <?php echo number_format($reservation['payment_transfer'], 2); ?> TND
                </div>
                <?php endif; ?>
                <?php 
                // Use the new tariff system calculation for total price (with discount)
                $calculated_total = $total_price; // This is already calculated above in the price breakdown
                $total_paid = $reservation['payment_cash'] + $reservation['payment_bank_check'] + ($reservation['payment_transfer'] ?? 0);
                $remaining = $calculated_total - $total_paid;
                ?>
                <div class="detail-item">
                    <strong><?php echo t('total_paid', 'Total Paid'); ?>:</strong> <?php echo number_format($total_paid, 2); ?> TND
                </div>
                <div class="detail-item">
                    <strong><?php echo t('final_price_after_discount', 'Final Price (after discount)'); ?>:</strong> <span><?php echo number_format($calculated_total, 2); ?> TND</span>
                </div>
                <?php if ($remaining > 0): ?>
                <div class="detail-item">
                    <strong><?php echo t('remaining', 'Remaining'); ?>:</strong> <span class="remaining-amount"><?php echo number_format($remaining, 2); ?> TND</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Exceptions -->
        <?php
        // Fetch exceptions for this reservation
        $exceptions_stmt = $pdo->prepare("SELECT * FROM reservation_exceptions WHERE reservation_id = ? ORDER BY assigned_tent_id");
        $exceptions_stmt->execute([$id]);
        $exceptions = $exceptions_stmt->fetchAll();
        ?>
        <?php if (!empty($exceptions)): ?>
        <div class="form-section">
            <h3><?php echo t('exceptions', 'Exceptions'); ?></h3>
            <div class="exceptions-list">
                <?php foreach ($exceptions as $exception): ?>
                <div class="exception-item">
                    <div class="exception-header">
                        <strong><?php echo htmlspecialchars($exception['guest_name']); ?></strong>
                        <span class="exception-type">(<?php echo t($exception['exception_type'], ucfirst($exception['exception_type'])); ?>)</span>
                    </div>
                    <div class="exception-details">
                        <?php
                        // Get tent number and type from tent ID
                        $tent_stmt = $pdo->prepare("SELECT tent_number, tent_type FROM tents WHERE id = ?");
                        $tent_stmt->execute([$exception['assigned_tent_id']]);
                        $tent_result = $tent_stmt->fetch();
                        $tent_number = $tent_result ? $tent_result['tent_number'] : $exception['assigned_tent_id'];
                        $tent_type = $tent_result ? $tent_result['tent_type'] : '';
                        ?>
                        <span class="exception-tent"><?php echo t('assigned_tent', 'Assigned Tent'); ?>: #<?php echo $tent_number; ?><?php echo $tent_type ? ' (' . htmlspecialchars($tent_type) . ')' : ''; ?></span>
                        <?php if (isset($exception['is_free']) && $exception['is_free']): ?>
                            <span class="exception-price free"><?php echo t('is_free', 'Is Free'); ?></span>
                        <?php else: ?>
                            <span class="exception-price"><?php echo t('price_per_night', 'Price per Night'); ?>: <?php echo number_format($exception['price_per_night'], 2); ?> TND</span>
                        <?php endif; ?>
                        <?php if (!empty($exception['notes'])): ?>
                            <div class="exception-notes"><?php echo htmlspecialchars($exception['notes']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if ($reservation['notes']): ?>
        <div class="form-section">
            <h3><?php echo t('notes', 'Notes'); ?></h3>
            <div class="notes-content">
                <?php echo nl2br(htmlspecialchars($reservation['notes'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reservation Actions -->
        <div class="form-actions">
            <a href="edit_reservation.php?id=<?php echo $reservation['id']; ?>" class="btn btn-primary"><?php echo t('edit_reservation', 'Edit Reservation'); ?></a>
            <a href="reservation_camp_summary.php?id=<?php echo $reservation['id']; ?>" class="btn btn-info" style="background:#f5b041;color:#222;border:1px solid #f5b041;"><?php echo t('camp_summary', 'Camp Summary'); ?></a>
            
            <!-- Status Action Buttons -->
            <?php if ($reservation['reservation_status'] === 'active'): ?>
                <a href="view_reservation_details.php?action=cancel&id=<?php echo $reservation['id']; ?>" 
                   class="btn btn-warning" 
                   onclick="return confirm('<?php echo t('confirm_cancel_reservation', 'Are you sure you want to cancel this reservation?'); ?>')"><?php echo t('cancel_reservation', 'Cancel Reservation'); ?></a>
                <a href="view_reservation_details.php?action=done&id=<?php echo $reservation['id']; ?>" 
                   class="btn btn-success" 
                   onclick="return confirm('<?php echo t('confirm_done', 'Mark this reservation as done?'); ?>')"><?php echo t('done', 'Done'); ?></a>
            <?php elseif ($reservation['reservation_status'] === 'canceled'): ?>
                <a href="view_reservation_details.php?action=activate&id=<?php echo $reservation['id']; ?>" 
                   class="btn btn-success" 
                   onclick="return confirm('<?php echo t('confirm_reactivate_reservation', 'Are you sure you want to reactivate this reservation?'); ?>')"><?php echo t('reactivate_reservation', 'Reactivate Reservation'); ?></a>
            <?php elseif ($reservation['reservation_status'] === 'done'): ?>
                <a href="view_reservation_details.php?action=activate&id=<?php echo $reservation['id']; ?>" 
                   class="btn btn-info" 
                   onclick="return confirm('<?php echo t('confirm_undo_done', 'Undo the done status?'); ?>')"><?php echo t('undo_done', 'Undo Done'); ?></a>
            <?php endif; ?>
            
            <a href="delete_reservation.php?id=<?php echo $reservation['id']; ?>" 
               class="btn btn-danger" 
               onclick="return confirm('<?php echo t('confirm_delete_reservation', 'Are you sure you want to delete this reservation?'); ?>')"><?php echo t('delete_reservation', 'Delete Reservation'); ?></a>
            <a href="dashboard.php" class="btn btn-secondary"><?php echo t('back_to_dashboard', 'Back to Dashboard'); ?></a>
        </div>
        <?php
        // Fetch reserved by and last edited by info
        $reserved_by_name = $reserved_by_role = $edited_by_name = $edited_by_role = null;
        if (!empty($reservation['created_by'])) {
            $stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
            $stmt->execute([$reservation['created_by']]);
            $user = $stmt->fetch();
            if ($user) {
                $reserved_by_name = $user['name'];
                $reserved_by_role = $user['role'];
            } else {
                $reserved_by_name = 'Unknown';
                $reserved_by_role = $reservation['created_by_role'] ?? '';
            }
        }
        if (!empty($reservation['updated_by'])) {
            $stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
            $stmt->execute([$reservation['updated_by']]);
            $user = $stmt->fetch();
            if ($user) {
                $edited_by_name = $user['name'];
                $edited_by_role = $user['role'];
            } else {
                $edited_by_name = 'Unknown';
                $edited_by_role = $reservation['updated_by_role'] ?? '';
            }
        }
        ?>
        <!-- Signature Block at the bottom -->
        <div class="signature-block">
            <?php if ($reserved_by_name): ?>
            <div class="signature-item">
                <div class="signature-avatar">
                    <?php echo strtoupper(substr($reserved_by_name, 0, 1)); ?>
                </div>
                <div class="signature-info">
                    <span class="signature-label"><?php echo t('created_by', 'Created by'); ?>:</span>
                    <span class="role-badge <?php echo htmlspecialchars(strtolower($reserved_by_role)); ?>"> <?php echo htmlspecialchars($reserved_by_role); ?> </span>
                    <span class="signature-name"> <?php echo htmlspecialchars($reserved_by_name); ?></span>
                    <?php if (!empty($reservation['created_at'])): ?>
                    <span class="signature-date" style="font-size:0.95em;color:#888;margin-top:2px;display:block;">
                        <?php echo t('created_on', 'Created on'); ?>: <?php echo date('d/m/Y H:i', strtotime($reservation['created_at'])); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($edited_by_name && !empty($reservation['updated_at'])): ?>
            <div class="signature-item">
                <div class="signature-avatar signature-edited">
                    <?php echo strtoupper(substr($edited_by_name, 0, 1)); ?>
                </div>
                <div class="signature-info">
                    <span class="signature-label"><?php echo t('last_edited_by', 'Last edited by'); ?>:</span>
                    <span class="role-badge <?php echo htmlspecialchars(strtolower($edited_by_role)); ?>"> <?php echo htmlspecialchars($edited_by_role); ?> </span>
                    <span class="signature-name"> <?php echo htmlspecialchars($edited_by_name); ?></span>
                    <span class="signature-date" style="font-size:0.95em;color:#888;margin-top:2px;display:block;">
                        <?php echo t('last_edited_on', 'Last edited on'); ?>: <?php echo date('d/m/Y H:i', strtotime($reservation['updated_at'])); ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html> 