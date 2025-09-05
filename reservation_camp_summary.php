<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';

$id = $_GET['id'] ?? 0;
if (!$id) {
    echo '<div style="padding:2rem;color:red;">Reservation ID not specified.</div>';
    exit();
}
$stmt = $pdo->prepare("SELECT r.*, g.name as guest_name, g.email, g.phone, g.adults, g.kids, g.babies FROM reservations r JOIN guests g ON r.guest_id = g.id WHERE r.id = ?");
$stmt->execute([$id]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reservation) {
    echo '<div style="padding:2rem;color:red;">Reservation not found.</div>';
    exit();
}
// Parse tent specifications for detailed tent info
function parseTentSpecs($tent_specifications, $boarding_information = null)
{
    $specs = [];
    if (empty($tent_specifications))
        return $specs;
    
    // Parse boarding information
    $boarding_data = [];
    if (!empty($boarding_information)) {
        $boarding_json = json_decode($boarding_information, true);
        if (isset($boarding_json['tents']) && is_array($boarding_json['tents'])) {
            foreach ($boarding_json['tents'] as $boarding) {
                $boarding_data[$boarding['tent_number']] = $boarding;
            }
        }
    }
    
    $parts = explode(', ', $tent_specifications);
    foreach ($parts as $spec) {
        if (preg_match('/Tent (\d+): (ROYAL|NORMAL) - (single|double|triple|quadruple) ?(?:\(Tent #(\d+)\))?/i', $spec, $matches)) {
            $tent_number = $matches[1];
            $boarding_info = $boarding_data[$tent_number] ?? null;
            
            $specs[] = [
                'tent_number' => $tent_number,
                'tent_type' => $matches[2],
                'beds' => $matches[3],
                'tent_id' => isset($matches[4]) ? $matches[4] : null,
                'boarding' => $boarding_info
            ];
        }
    }
    return $specs;
}
$tents = parseTentSpecs($reservation['tent_specifications'], $reservation['boarding_information']);
// Fetch assigned cars with period
$cars = $pdo->prepare("SELECT c.registration_number, rc.start_date, rc.end_date FROM reservation_cars rc JOIN cars c ON rc.car_id = c.id WHERE rc.reservation_id = ?");
$cars->execute([$id]);
$cars = $cars->fetchAll(PDO::FETCH_ASSOC);
// Fetch assigned drivers with period
$drivers = $pdo->prepare("SELECT h.full_name, rh.start_date, rh.end_date FROM reservation_humans rh JOIN human_resources h ON rh.human_id = h.id WHERE rh.reservation_id = ? AND rh.role = 'driver'");
$drivers->execute([$id]);
$drivers = $drivers->fetchAll(PDO::FETCH_ASSOC);
// Fetch assigned guides with period
$guides = $pdo->prepare("SELECT h.full_name, rh.start_date, rh.end_date FROM reservation_humans rh JOIN human_resources h ON rh.human_id = h.id WHERE rh.reservation_id = ? AND rh.role = 'guide'");
$guides->execute([$id]);
$guides = $guides->fetchAll(PDO::FETCH_ASSOC);
// Fetch services
$services = [];
if (!empty($reservation['services_data'])) {
    $services_data = json_decode($reservation['services_data'], true);
    if (is_array($services_data)) {
        $service_ids = array_keys($services_data);
        if ($service_ids) {
            $in = str_repeat('?,', count($service_ids) - 1) . '?';
            $svc_stmt = $pdo->prepare("SELECT id, name FROM services WHERE id IN ($in)");
            $svc_stmt->execute($service_ids);
            $svc_map = $svc_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($service_ids as $sid) {
                if (!empty($services_data[$sid]['selected']) && isset($svc_map[$sid])) {
                    $services[] = $svc_map[$sid];
                }
            }
        }
    }
}
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?php echo t('camp_summary_title', 'Camp Summary'); ?> - <?php echo t('reservation', 'Reservation'); ?> #<?php echo $reservation['id']; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: url('https://image.over-blog.com/LMvEGPNfNKC4Pkcb9e6aZ1ma1XE=/filters:no_upscale()/image%2F0630431%2F20241216%2Fob_9c1a6b_capture-d-ecran-2024-12-16-a-15-45.png') no-repeat center center fixed;
            background-size: cover;
            margin: 0;
            padding: 0;
        }

        .summary-container {
            max-width: 700px;
            margin: 2rem auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.07);
            padding: 2rem;
        }

        h2 {
            margin-top: 0;
            color: #2d3a4a;
        }

        .section {
            margin-bottom: 1.5rem;
        }

        .sections-row {
            display: flex;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        .section-half {
            flex: 1;
        }

        .section h3 {
            margin-bottom: 0.5rem;
            color: #4a5a6a;
            font-size: 1.1rem;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 4px;
        }

        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .info-list li {
            margin-bottom: 0.3rem;
        }

        .back-btn {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 0.5rem 1.2rem;
            background: #eee;
            color: #333;
            border-radius: 5px;
            text-decoration: none;
            border: 1px solid #bbb;
        }

        .back-btn:hover {
            background: #e0e0e0;
        }

        .tents-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
            font-size: 14px;
        }

        .tents-table th,
        .tents-table td {
            border: 1px solid #e0e0e0;
            padding: 6px 10px;
            text-align: left;
        }

        .tents-table th {
            background: #f5f5f5;
            color: #333;
            font-weight: 600;
        }

        .tents-table td {
            background: #fafbfc;
        }

        @media print {
            .back-btn {
                display: none;
            }

            .print-btn {
                display: none;
            }

            .summary-container {
                box-shadow: none;
                border: 1px solid #bbb;
            }
        }
    </style>
</head>

<body>
    <div class="summary-container" style="position:relative;">
        <img src="assets/images/logo.png" alt="ABDELMOULA CAMP" style="position:absolute;top:18px;right:18px;height:54px;width:auto;border-radius:8px;box-shadow:0 2px 8px rgba(184,134,11,0.10);background:#fff;padding:4px;z-index:10;">
        <h2><?php echo t('camp_summary_title', 'Camp Summary'); ?> - <?php echo t('reservation', 'Reservation'); ?> #<?php echo $reservation['id']; ?></h2>
        <div class="sections-row">
            <div class="section section-half">
            <h3><?php echo t('guest_information', 'Guest Information'); ?></h3>
            <ul class="info-list">
                <li><strong><?php echo t('name', 'Name'); ?>:</strong>
                    <?php echo htmlspecialchars($reservation['guest_name']); ?>
                </li>
                <?php if ($reservation['phone']): ?>
                    <li><strong><?php echo t('phone', 'Phone'); ?>:</strong>
                        <?php echo htmlspecialchars($reservation['phone']); ?>
                    </li>
                <?php endif; ?>
                <?php if ($reservation['email']): ?>
                    <li><strong><?php echo t('email', 'Email'); ?>:</strong>
                        <?php echo htmlspecialchars($reservation['email']); ?>
                    </li>
                <?php endif; ?>
                <li><strong><?php echo t('adults', 'Adults'); ?>:</strong>
                    <?php echo (int) $reservation['adults']; ?>, <strong><?php echo t('kids', 'Kids'); ?>:</strong>
                    <?php echo (int) $reservation['kids']; ?>, <strong><?php echo t('babies', 'Babies'); ?>:</strong>
                    <?php echo (int) $reservation['babies']; ?>
                </li>
            </ul>
        </div>
            <div class="section section-half">
            <h3><?php echo t('reservation_details', 'Reservation Details'); ?></h3>
            <ul class="info-list">
                <li><strong><?php echo t('check_in', 'Check-in'); ?>:</strong>
                    <?php echo htmlspecialchars($reservation['check_in_date']); ?>
                </li>
                <li><strong><?php echo t('check_out', 'Check-out'); ?>:</strong>
                    <?php echo htmlspecialchars($reservation['check_out_date']); ?>
                </li>
                <li><strong><?php echo t('nights', 'Nights'); ?>:</strong>
                    <?php echo (int) $reservation['nights']; ?>
                </li>
                <li><strong><?php echo t('reservation_source', 'Source'); ?>:</strong>
                    <?php echo htmlspecialchars(ucfirst($reservation['reservation_source'])); ?>
                    <?php if ($reservation['reservation_source'] === 'agency' && $reservation['agency_name']): ?> (
                        <?php echo htmlspecialchars($reservation['agency_name']); ?>)
                    <?php endif; ?>
                </li>
            </ul>
            </div>
        </div>
        <div class="section">
            <h3><?php echo t('tents', 'Tents'); ?></h3>
            <?php if ($tents): ?>
                <table class="tents-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo t('tent_type', 'Type'); ?></th>
                            <th><?php echo t('beds', 'Beds'); ?></th>
                            <th><?php echo t('assigned_tent', 'Assigned Tent'); ?></th>
                            <th><?php echo t('boarding', 'Boarding'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tents as $tent): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($tent['tent_number']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($tent['tent_type']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($tent['beds']); ?>
                                </td>
                                <td>
                                    <?php echo $tent['tent_id'] ? htmlspecialchars($tent['tent_id']) : ''; ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($tent['boarding']) {
                                        $boarding_text = [];
                                        if ($tent['boarding']['half_board_days'] > 0) {
                                            $boarding_text[] = t('half_board', 'Half Board') . ': ' . $tent['boarding']['half_board_days'] . ' ' . t('nights', 'nights');
                                        }
                                        if ($tent['boarding']['full_board_days'] > 0) {
                                            $boarding_text[] = t('full_board', 'Full Board') . ': ' . $tent['boarding']['full_board_days'] . ' ' . t('nights', 'nights');
                                        }
                                        echo implode(', ', $boarding_text);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Summary Row -->
                        <?php
                        $total_half_board = 0;
                        $total_full_board = 0;
                        $tents_with_boarding = 0;
                        
                        foreach ($tents as $tent) {
                            if ($tent['boarding']) {
                                $tents_with_boarding++;
                                $total_half_board += $tent['boarding']['half_board_days'];
                                $total_full_board += $tent['boarding']['full_board_days'];
                            }
                        }
                        
                        if ($tents_with_boarding > 0):
                        ?>
                            <tr style="background-color: #f8f9fa; font-weight: bold; border-top: 2px solid #dee2e6;">
                                <td colspan="4" style="text-align: right;">
                                    <strong><?php echo t('total', 'Total'); ?>:</strong>
                                </td>
                                <td>
                                    <?php 
                                    $summary_text = [];
                                    if ($total_half_board > 0) {
                                        $summary_text[] = t('half_board', 'Half Board') . ': ' . $total_half_board . ' ' . t('nights', 'nights');
                                    }
                                    if ($total_full_board > 0) {
                                        $summary_text[] = t('full_board', 'Full Board') . ': ' . $total_full_board . ' ' . t('nights', 'nights');
                                    }
                                    echo implode(', ', $summary_text);
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <em><?php echo t('no_tents_assigned', 'No tents assigned.'); ?></em>
            <?php endif; ?>
        </div>
        <div class="section">
            <h3><?php echo t('cars', 'Cars'); ?></h3>
            <?php if ($cars): ?>
                <table class="tents-table">
                    <thead>
                        <tr>
                            <th><?php echo t('registration', 'Registration'); ?></th>
                            <th><?php echo t('period', 'Period'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cars as $car): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($car['registration_number']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($car['start_date']); ?> <?php echo t('to', 'to'); ?>
                                    <?php echo htmlspecialchars($car['end_date']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <em><?php echo t('no_cars_assigned', 'No cars assigned.'); ?></em>
            <?php endif; ?>
        </div>
        <div class="section">
            <h3><?php echo t('drivers', 'Drivers'); ?></h3>
            <?php if ($drivers): ?>
                <table class="tents-table">
                    <thead>
                        <tr>
                            <th><?php echo t('name', 'Name'); ?></th>
                            <th><?php echo t('period', 'Period'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($drivers as $driver): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($driver['full_name']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($driver['start_date']); ?> <?php echo t('to', 'to'); ?>
                                    <?php echo htmlspecialchars($driver['end_date']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <em><?php echo t('no_drivers_assigned', 'No drivers assigned.'); ?></em>
            <?php endif; ?>
        </div>
        <div class="section">
            <h3><?php echo t('guides', 'Guides'); ?></h3>
            <?php if ($guides): ?>
                <table class="tents-table">
                    <thead>
                        <tr>
                            <th><?php echo t('name', 'Name'); ?></th>
                            <th><?php echo t('period', 'Period'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($guides as $guide): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($guide['full_name']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($guide['start_date']); ?> <?php echo t('to', 'to'); ?>
                                    <?php echo htmlspecialchars($guide['end_date']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <em><?php echo t('no_guides_assigned', 'No guides assigned.'); ?></em>
            <?php endif; ?>
        </div>
        <div class="section">
            <h3><?php echo t('services', 'Services'); ?></h3>
            <?php if ($services): ?>
                <ul class="info-list">
                    <?php foreach ($services as $service): ?>
                        <li>
                            <?php echo htmlspecialchars($service); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <em><?php echo t('no_services_selected', 'No services selected.'); ?></em>
            <?php endif; ?>
        </div>
        <?php if ($reservation['notes']): ?>
            <div class="section">
                <h3><?php echo t('notes', 'Notes'); ?></h3>
                <div>
                    <?php echo nl2br(htmlspecialchars($reservation['notes'])); ?>
                </div>
            </div>
        <?php endif; ?>
        <a href="view_reservation_details.php?id=<?php echo $reservation['id']; ?>" class="back-btn"><?php echo t('back_to_details', 'Back to Details'); ?> &larr;</a>
        <button onclick="window.print()" class="print-btn" style="margin-left:18px;padding:8px 18px;background:#b8860b;color:#fff;border:none;border-radius:8px;font-size:1em;font-weight:600;box-shadow:0 2px 8px rgba(184,134,11,0.10);cursor:pointer;transition:background 0.18s;z-index:10;display:inline-block;vertical-align:middle;">
            🖨️ <?php echo t('print_summary', 'Print Summary'); ?>
        </button>
    </div>

</html>