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

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Last day of current month
$confirmation_filter = $_GET['confirmation_filter'] ?? '';

// Build query with confirmation filter
$where_conditions = ["r.check_in_date BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if (!empty($confirmation_filter)) {
    if ($confirmation_filter === 'confirmed') {
        $where_conditions[] = "r.confirmation = 1";
    } elseif ($confirmation_filter === 'not_confirmed') {
        $where_conditions[] = "(r.confirmation = 0 OR r.confirmation IS NULL)";
    }
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch reservations in the selected period
$query = "
    SELECT 
        r.id,
        r.check_in_date,
        r.check_out_date,
        r.nights,
        r.notes,
        r.services_data,
        r.boarding_information,
        r.confirmation,
        g.name as guest_name,
        g.adults,
        g.kids,
        g.babies,
        (g.adults + g.kids + g.babies) as total_pax
    FROM reservations r
    JOIN guests g ON r.guest_id = g.id
    $where_clause
    ORDER BY r.check_in_date ASC, g.name ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to get assigned resources for a reservation
function getAssignedResources($pdo, $reservation_id) {
    $resources = [
        'cars' => [],
        'drivers' => [],
        'guides' => [],
        'tents' => []
    ];
    
    // Get assigned cars
    $car_stmt = $pdo->prepare("
        SELECT c.registration_number 
        FROM reservation_cars rc 
        JOIN cars c ON rc.car_id = c.id 
        WHERE rc.reservation_id = ?
    ");
    $car_stmt->execute([$reservation_id]);
    $resources['cars'] = $car_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get assigned drivers
    $driver_stmt = $pdo->prepare("
        SELECT h.full_name 
        FROM reservation_humans rh 
        JOIN human_resources h ON rh.human_id = h.id 
        WHERE rh.reservation_id = ? AND rh.role = 'driver'
    ");
    $driver_stmt->execute([$reservation_id]);
    $resources['drivers'] = $driver_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get assigned guides
    $guide_stmt = $pdo->prepare("
        SELECT h.full_name 
        FROM reservation_humans rh 
        JOIN human_resources h ON rh.human_id = h.id 
        WHERE rh.reservation_id = ? AND rh.role = 'guide'
    ");
    $guide_stmt->execute([$reservation_id]);
    $resources['guides'] = $guide_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get assigned tents
    $tent_stmt = $pdo->prepare("
        SELECT t.tent_number, t.tent_type
        FROM reservation_tents rt 
        JOIN tents t ON rt.tent_id = t.id 
        WHERE rt.reservation_id = ?
    ");
    $tent_stmt->execute([$reservation_id]);
    $tents = $tent_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get tent specifications from reservation to determine bed types
    $spec_stmt = $pdo->prepare("SELECT tent_specifications FROM reservations WHERE id = ?");
    $spec_stmt->execute([$reservation_id]);
    $tent_specifications = $spec_stmt->fetchColumn();
    
    // Organize tents by bed type
    $tent_by_type = [
        'single' => [],
        'double' => [],
        'triple' => [],
        'quadruple' => []
    ];
    
    // Parse tent specifications to get bed types
    if (!empty($tent_specifications)) {
        // Example: "Tent 1: ROYAL - single (Tent #2), Tent 2: NORMAL - double (Tent #3)"
        preg_match_all('/Tent \d+: (ROYAL|NORMAL) - (single|double|triple|quadruple) \(Tent #(\d+)\)/i', $tent_specifications, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $bed_type = strtolower($match[2]); // single, double, triple, quadruple
            $tent_type = $match[1]; // ROYAL or NORMAL
            $tent_number = $match[3];
            
            if (isset($tent_by_type[$bed_type])) {
                $tent_by_type[$bed_type][] = [
                    'number' => $tent_number,
                    'type' => $tent_type
                ];
            }
        }
    }
    
    // If no specifications found, just add tent numbers without bed type info
    if (empty($tent_specifications)) {
        foreach ($tents as $tent) {
            // Default to double if no specification available
            $tent_by_type['double'][] = [
                'number' => $tent['tent_number'],
                'type' => $tent['tent_type']
            ];
        }
    }
    
    $resources['tents'] = $tent_by_type;
    
    return $resources;
}

// Function to get services for a reservation
function getServices($pdo, $services_data) {
    if (empty($services_data)) {
        return [];
    }
    
    $services = json_decode($services_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    
    $active_services = [];
    foreach ($services as $service_id => $service_data) {
        if (isset($service_data['selected']) && $service_data['selected'] == '1') {
            // Get service name from database
            $stmt = $pdo->prepare("SELECT name FROM services WHERE id = ?");
            $stmt->execute([$service_id]);
            $service_name = $stmt->fetchColumn();
            
            if ($service_name) {
                $active_services[] = $service_name;
            } else {
                $active_services[] = $service_data['name'] ?? "Service #$service_id";
            }
        }
    }
    
    return $active_services;
}

// Function to get boarding information for a reservation
function getBoardingInformation($pdo, $boarding_information) {
    $boarding_info = [];
    
    if (!empty($boarding_information)) {
        $boarding_data = json_decode($boarding_information, true);
        if (isset($boarding_data['tents']) && is_array($boarding_data['tents'])) {
            foreach ($boarding_data['tents'] as $boarding) {
                $tent_number = $boarding['tent_number'];
                $tent_type = $boarding['tent_type'];
                $bed_config = $boarding['bed_config'];
                
                // Check for half board
                if (isset($boarding['half_board_days']) && $boarding['half_board_days'] > 0) {
                    $days = intval($boarding['half_board_days']);
                    if (!isset($boarding_info['half_board'])) {
                        $boarding_info['half_board'] = [];
                    }
                    $boarding_info['half_board'][] = [
                        'tent' => $tent_number,
                        'type' => $tent_type,
                        'bed_config' => $bed_config,
                        'days' => $days
                    ];
                }
                
                // Check for full board
                if (isset($boarding['full_board_days']) && $boarding['full_board_days'] > 0) {
                    $days = intval($boarding['full_board_days']);
                    if (!isset($boarding_info['full_board'])) {
                        $boarding_info['full_board'] = [];
                    }
                    $boarding_info['full_board'][] = [
                        'tent' => $tent_number,
                        'type' => $tent_type,
                        'bed_config' => $bed_config,
                        'days' => $days
                    ];
                }
            }
        }
    }
    
    return $boarding_info;
}

// Function to calculate aggregate statistics
function calculateAggregateStats($pdo, $reservations) {
    $stats = [
        'total_reservations' => count($reservations),
        'total_nights' => 0,
        'total_pax' => 0,
        'tent_counts' => [
            'single' => 0,
            'double' => 0,
            'triple' => 0,
            'quadruple' => 0
        ],
        'total_cars' => 0,
        'total_drivers' => 0,
        'total_guides' => 0,
        'service_frequency' => [],
        'confirmed_count' => 0,
        'not_confirmed_count' => 0,
        'boarding_totals' => [
            'half_board' => 0,
            'full_board' => 0
        ]
    ];
    
    foreach ($reservations as $reservation) {
        // Basic stats
        $stats['total_nights'] += $reservation['nights'];
        $stats['total_pax'] += $reservation['total_pax'];
        
        // Confirmation stats
        if ($reservation['confirmation']) {
            $stats['confirmed_count']++;
        } else {
            $stats['not_confirmed_count']++;
        }
        
        // Get resources for this reservation
        $resources = getAssignedResources($pdo, $reservation['id']);
        $services = getServices($pdo, $reservation['services_data']);
        $boarding = getBoardingInformation($pdo, $reservation['boarding_information']);
        
        // Count tents by type
        foreach (['single', 'double', 'triple', 'quadruple'] as $bed_type) {
            $stats['tent_counts'][$bed_type] += count($resources['tents'][$bed_type]);
        }
        
        // Count cars, drivers, guides
        $stats['total_cars'] += count($resources['cars']);
        $stats['total_drivers'] += count($resources['drivers']);
        $stats['total_guides'] += count($resources['guides']);
        
        // Count boarding totals
        if (!empty($boarding['half_board'])) {
            $stats['boarding_totals']['half_board'] += count($boarding['half_board']);
        }
        if (!empty($boarding['full_board'])) {
            $stats['boarding_totals']['full_board'] += count($boarding['full_board']);
        }
        
        // Count service frequency
        foreach ($services as $service) {
            if (!isset($stats['service_frequency'][$service])) {
                $stats['service_frequency'][$service] = 0;
            }
            $stats['service_frequency'][$service]++;
        }
    }
    
    return $stats;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('reservation_summary', 'Reservation Summary'); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: url('https://image.over-blog.com/LMvEGPNfNKC4Pkcb9e6aZ1ma1XE=/filters:no_upscale()/image%2F0630431%2F20241216%2Fob_9c1a6b_capture-d-ecran-2024-12-16-a-15-45.png') no-repeat center center fixed;
            background-size: cover;
        }
        
        .summary-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
        }
        
        .summary-container h1 {
            color: #b8860b;
            font-size: 2rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        
        .filter-row {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
        }
        
        .filter-group input, .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .filter-group input:focus, .filter-group select:focus {
            outline: none;
            border-color: #b8860b;
            box-shadow: 0 0 0 2px rgba(184, 134, 11, 0.2);
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #b8860b;
            color: white;
        }
        
        .btn-primary:hover {
            background: #a0760b;
            transform: translateY(-1px);
        }
        
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border: 1px solidrgb(233, 227, 225);
        }
        
        .summary-table th,
        .summary-table td {
            border: 1px solid #e1e5e9;
            padding: 14px 12px;
            text-align: left;
            vertical-align: top;
        }
        
        .summary-table th {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            font-weight: 600;
            text-align: center;
            font-size: 11px;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #1a252f;
        }
        
        .tent-columns th {
            background: linear-gradient(135deg, #34495e, #2c3e50);
            font-size: 10px;
            border-bottom: 1px solid #1a252f;
        }
        
        /* Professional footer row styling */
        .summary-footer {
            background: #f8f9fa !important;
            border-top: 3px solid #dee2e6;
            font-weight: 600;
        }
        
        .footer-cell {
            border: 1px solid #dee2e6 !important;
            padding: 16px 12px !important;
            text-align: center !important;
            vertical-align: middle !important;
            background: #ffffff !important;
        }
        
        .footer-label {
            color: #495057;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            display: block;
        }
        
        .footer-value {
            color: #2c3e50;
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 4px;
            display: block;
        }
        
        .footer-subtext {
            color: #6c757d;
            font-size: 10px;
            font-weight: 500;
            display: block;
        }
        
        .summary-table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .summary-table tbody tr:nth-child(odd) {
            background: #ffffff;
        }
        
        .summary-table tbody tr:hover {
            background: #e8f4fd;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(52, 73, 94, 0.15);
        }
        
        .guest-name {
            font-weight: 600;
            color: #333;
        }
        
        .date-cell {
            white-space: nowrap;
            color: #666;
        }
        
        .resource-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .resource-list li {
            margin-bottom: 3px;
            font-size: 11px;
            color: #555;
        }
        
        .tent-number {
            background: #ecf0f1;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            margin: 2px;
            display: inline-block;
            border: 1px solid #bdc3c7;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .no-data {
            color: #999;
            font-style: italic;
            font-size: 11px;
        }
        
        .boarding-info {
            font-size: 11px;
            color: #555;
        }
        
        .boarding-summary {
            font-size: 11px;
            color: #555;
        }
        
        .boarding-summary div {
            margin-bottom: 2px;
        }
        
        .period-info {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid #c3e6cb;
        }
        
        .period-info h3 {
            margin: 0 0 10px 0;
            color: #155724;
            font-size: 1.2rem;
        }
        
        .period-info p {
            margin: 0;
            color: #155724;
            font-size: 16px;
            font-weight: 500;
        }
        
        .print-section {
            text-align: right;
            margin-bottom: 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
        }
        
        .btn-print {
            background: #b8860b;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-print:hover {
            background: #a0760b;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        @media (max-width: 1200px) {
            .summary-container {
                margin: 10px;
                padding: 15px;
            }
            
            .summary-table {
                font-size: 11px;
            }
            
            .summary-table th,
            .summary-table td {
                padding: 6px 4px;
            }
        }
        
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                margin-bottom: 10px;
            }
            
            .summary-table {
                font-size: 10px;
            }
            
            .tent-number {
                font-size: 9px;
                padding: 2px 4px;
            }
            
            /* Mobile footer adjustments */
            .footer-cell {
                padding: 8px 4px !important;
            }
            
            .footer-label {
                font-size: 9px;
                margin-bottom: 3px;
            }
            
            .footer-value {
                font-size: 12px;
                margin-bottom: 2px;
            }
            
            .footer-subtext {
                font-size: 8px;
            }
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white !important;
                margin: 0 !important;
                padding: 20px !important;
            }
            
            .summary-container {
                box-shadow: none !important;
                border: none !important;
                background: white !important;
            }
            
            .filter-section, .print-section {
                display: none !important;
            }
            
            .summary-table {
                width: 100% !important;
                font-size: 10px !important;
                border-collapse: collapse !important;
                page-break-inside: auto !important;
            }
            
            .summary-table th,
            .summary-table td {
                border: 1px solid #000 !important;
                padding: 6px 4px !important;
                font-size: 9px !important;
                page-break-inside: avoid !important;
            }
            
            .summary-table th {
                background: #f0f0f0 !important;
                color: #000 !important;
                font-weight: bold !important;
            }
            
            .tent-columns th {
                background: #e0e0e0 !important;
            }
            
            .tent-number {
                background: #f5f5f5 !important;
                border: 1px solid #ccc !important;
                color: #000 !important;
                padding: 1px 3px !important;
                font-size: 8px !important;
            }
            
            .resource-list li {
                font-size: 8px !important;
                margin-bottom: 1px !important;
            }
            
            .services-list {
                font-size: 8px !important;
            }
            
            .notes-cell {
                font-size: 8px !important;
                max-width: none !important;
            }
            
            /* Professional footer print styles */
            .summary-footer {
                background: #f8f9fa !important;
                border-top: 2px solid #000 !important;
                font-weight: bold !important;
            }
            
            .footer-cell {
                border: 1px solid #000 !important;
                padding: 6px 4px !important;
                font-size: 8px !important;
                text-align: center !important;
                background: #ffffff !important;
            }
            
            .footer-label {
                color: #000 !important;
                font-size: 7px !important;
                font-weight: bold !important;
                text-transform: uppercase !important;
                margin-bottom: 3px !important;
                display: block !important;
            }
            
            .footer-value {
                color: #000 !important;
                font-size: 10px !important;
                font-weight: bold !important;
                margin-bottom: 2px !important;
                display: block !important;
            }
            
            .footer-subtext {
                color: #666 !important;
                font-size: 6px !important;
                font-weight: normal !important;
                display: block !important;
            }
            
            /* Page header for print */
            .print-header {
                display: block !important;
                text-align: center !important;
                margin-bottom: 20px !important;
                font-size: 16px !important;
                font-weight: bold !important;
            }
            
            .print-period {
                display: block !important;
                text-align: center !important;
                margin-bottom: 15px !important;
                font-size: 14px !important;
            }
        }
        
        /* Hide print header on screen */
        .print-header, .print-period {
            display: none;
        }
    </style>
</head>
<body>
    
    
    <div class="summary-container">
        <!-- Print Headers (hidden on screen, visible when printing) -->
        <div class="print-header">
            <?php echo t('reservation_summary', 'Reservation Summary'); ?>
        </div>
        <div class="print-period">
            <?php echo date('F j, Y', strtotime($start_date)); ?> - <?php echo date('F j, Y', strtotime($end_date)); ?> 
            (<?php echo count($reservations); ?> <?php echo t('reservations_found', 'reservations found'); ?>)
        </div>
        
        <h1><?php echo t('reservation_summary', 'Reservation Summary'); ?></h1>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="start_date"><?php echo t('start_date', 'Start Date'); ?></label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
                    </div>
                    
                    <div class="filter-group">
                        <label for="end_date"><?php echo t('end_date', 'End Date'); ?></label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
                    </div>
                    
                    <div class="filter-group">
                        <label for="confirmation_filter"><?php echo t('confirmation', 'Confirmation'); ?></label>
                        <select id="confirmation_filter" name="confirmation_filter">
                            <option value=""><?php echo t('all_confirmations', 'All Confirmations'); ?></option>
                            <option value="confirmed" <?php echo $confirmation_filter === 'confirmed' ? 'selected' : ''; ?>>
                                <?php echo t('confirmed', 'Confirmed'); ?>
                            </option>
                            <option value="not_confirmed" <?php echo $confirmation_filter === 'not_confirmed' ? 'selected' : ''; ?>>
                                <?php echo t('not_confirmed', 'Not Confirmed'); ?>
                            </option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary"><?php echo t('filter', 'Filter'); ?></button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Period Info -->
        <div class="period-info">
            <h3><?php echo t('reservations_in_period', 'Reservations in Period'); ?></h3>
            <p><?php echo date('F j, Y', strtotime($start_date)); ?> - <?php echo date('F j, Y', strtotime($end_date)); ?></p>
            <p><?php echo count($reservations); ?> <?php echo t('reservations_found', 'reservations found'); ?></p>
        </div>
        
        <!-- Print Section -->
        <div class="print-section">
            <a href="dashboard.php" class="btn btn-secondary" style="margin-right: 10px;">
                ← <?php echo t('back_to_dashboard', 'Back to Dashboard'); ?>
            </a>
            <button onclick="window.print()" class="btn-print">
                🖨️ <?php echo t('print_summary', 'Print Summary'); ?>
            </button>
        </div>
        
        <?php if (empty($reservations)): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <h3><?php echo t('no_reservations_found', 'No reservations found for the selected period'); ?></h3>
            </div>
        <?php else: ?>
            <!-- Summary Table -->
            <div style="overflow-x: auto;">
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th rowspan="2"><?php echo t('reservation_id', 'ID'); ?></th>
                            <th rowspan="2"><?php echo t('guest', 'Guest'); ?></th>
                            <th rowspan="2"><?php echo t('check_in_date', 'Check-in Date'); ?></th>
                            <th rowspan="2"><?php echo t('nights', 'Nights'); ?></th>
                            <th rowspan="2"><?php echo t('pax', 'PAX'); ?></th>
                            <th colspan="4" class="tent-columns"><?php echo t('tents', 'Tents'); ?></th>
                            <th rowspan="2"><?php echo t('boarding', 'Boarding'); ?></th>
                            <th rowspan="2"><?php echo t('cars', 'Cars'); ?></th>
                            <th rowspan="2"><?php echo t('drivers', 'Drivers'); ?></th>
                            <th rowspan="2"><?php echo t('guides', 'Guides'); ?></th>
                            <th rowspan="2"><?php echo t('services', 'Services'); ?></th>
                            <th rowspan="2"><?php echo t('notes', 'Notes'); ?></th>
                        </tr>
                        <tr class="tent-columns">
                            <th><?php echo t('single', 'Single'); ?></th>
                            <th><?php echo t('double', 'Double'); ?></th>
                            <th><?php echo t('triple', 'Triple'); ?></th>
                            <th><?php echo t('quadruple', 'Quadruple'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                            <?php 
                            $resources = getAssignedResources($pdo, $reservation['id']);
                            $services = getServices($pdo, $reservation['services_data']);
                            $boarding = getBoardingInformation($pdo, $reservation['boarding_information']);
                            ?>
                            <tr>
                                <td style="text-align: center; font-weight: bold; color: #b8860b;">#<?php echo $reservation['id']; ?></td>
                                <td class="guest-name"><?php echo htmlspecialchars($reservation['guest_name']); ?></td>
                                <td class="date-cell"><?php echo date('M j, Y', strtotime($reservation['check_in_date'])); ?></td>
                                <td style="text-align: center;"><?php echo $reservation['nights']; ?></td>
                                <td style="text-align: center;"><?php echo $reservation['total_pax']; ?></td>
                                
                                <!-- Tent columns -->
                                <td>
                                    <?php if (!empty($resources['tents']['single'])): ?>
                                        <?php foreach ($resources['tents']['single'] as $tent_info): ?>
                                            <span class="tent-number">#<?php echo $tent_info['number']; ?> (<?php echo $tent_info['type']; ?>)</span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($resources['tents']['double'])): ?>
                                        <?php foreach ($resources['tents']['double'] as $tent_info): ?>
                                            <span class="tent-number">#<?php echo $tent_info['number']; ?> (<?php echo $tent_info['type']; ?>)</span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($resources['tents']['triple'])): ?>
                                        <?php foreach ($resources['tents']['triple'] as $tent_info): ?>
                                            <span class="tent-number">#<?php echo $tent_info['number']; ?> (<?php echo $tent_info['type']; ?>)</span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($resources['tents']['quadruple'])): ?>
                                        <?php foreach ($resources['tents']['quadruple'] as $tent_info): ?>
                                            <span class="tent-number">#<?php echo $tent_info['number']; ?> (<?php echo $tent_info['type']; ?>)</span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Boarding -->
                                <td>
                                    <?php 
                                    $half_board_count = !empty($boarding['half_board']) ? count($boarding['half_board']) : 0;
                                    $full_board_count = !empty($boarding['full_board']) ? count($boarding['full_board']) : 0;
                                    ?>
                                    <?php if ($half_board_count > 0 || $full_board_count > 0): ?>
                                        <div class="boarding-summary">
                                            <?php if ($half_board_count > 0): ?>
                                                <div><?php echo t('hb', 'HB'); ?>: <?php echo $half_board_count; ?></div>
                                            <?php endif; ?>
                                            <?php if ($full_board_count > 0): ?>
                                                <div><?php echo t('fb', 'FB'); ?>: <?php echo $full_board_count; ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="no-data"><?php echo t('no_boarding', 'No boarding'); ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Cars -->
                                <td>
                                    <?php if (!empty($resources['cars'])): ?>
                                        <ul class="resource-list">
                                            <?php foreach ($resources['cars'] as $car): ?>
                                                <li><?php echo htmlspecialchars($car); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Drivers -->
                                <td>
                                    <?php if (!empty($resources['drivers'])): ?>
                                        <ul class="resource-list">
                                            <?php foreach ($resources['drivers'] as $driver): ?>
                                                <li><?php echo htmlspecialchars($driver); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Guides -->
                                <td>
                                    <?php if (!empty($resources['guides'])): ?>
                                        <ul class="resource-list">
                                            <?php foreach ($resources['guides'] as $guide): ?>
                                                <li><?php echo htmlspecialchars($guide); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Services -->
                                <td>
                                    <?php if (!empty($services)): ?>
                                        <ul class="resource-list">
                                            <?php foreach ($services as $service): ?>
                                                <li><?php echo htmlspecialchars($service); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Notes -->
                                <td>
                                    <?php if (!empty($reservation['notes'])): ?>
                                        <?php echo htmlspecialchars(substr($reservation['notes'], 0, 50)); ?>
                                        <?php if (strlen($reservation['notes']) > 50): ?>...<?php endif; ?>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    
                    <!-- Footer Row with Aggregate Statistics -->
                    <?php 
                    $stats = calculateAggregateStats($pdo, $reservations);
                    ?>
                    <tfoot>
                        <tr class="summary-footer">
                            <td colspan="2" class="footer-cell">
                                <div class="footer-label"><?php echo t('totals', 'TOTALS'); ?></div>
                                <div class="footer-value"><?php echo $stats['total_reservations']; ?> <?php echo t('reservations', 'reservations'); ?></div>
                                <div class="footer-subtext">
                                    <?php echo $stats['confirmed_count']; ?> <?php echo t('confirmed', 'confirmed'); ?>, 
                                    <?php echo $stats['not_confirmed_count']; ?> <?php echo t('not_confirmed', 'not confirmed'); ?>
                                </div>
                            </td>
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('check_in_date', 'Check-in Date'); ?></div>
                                <div class="footer-value">-</div>
                            </td>
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('total_nights', 'Total Nights'); ?></div>
                                <div class="footer-value"><?php echo $stats['total_nights']; ?></div>
                            </td>
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('total_pax', 'Total PAX'); ?></div>
                                <div class="footer-value"><?php echo $stats['total_pax']; ?></div>
                            </td>
                            
                            <!-- Tent totals -->
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('single', 'Single'); ?></div>
                                <div class="footer-value"><?php echo $stats['tent_counts']['single']; ?></div>
                            </td>
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('double', 'Double'); ?></div>
                                <div class="footer-value"><?php echo $stats['tent_counts']['double']; ?></div>
                            </td>
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('triple', 'Triple'); ?></div>
                                <div class="footer-value"><?php echo $stats['tent_counts']['triple']; ?></div>
                            </td>
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('quadruple', 'Quadruple'); ?></div>
                                <div class="footer-value"><?php echo $stats['tent_counts']['quadruple']; ?></div>
                            </td>
                            
                            <!-- Boarding totals -->
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('boarding', 'Boarding'); ?></div>
                                <div class="footer-value">
                                    <?php if ($stats['boarding_totals']['half_board'] > 0 || $stats['boarding_totals']['full_board'] > 0): ?>
                                        <?php if ($stats['boarding_totals']['half_board'] > 0): ?>
                                            <?php echo $stats['boarding_totals']['half_board']; ?> <?php echo t('hb', 'HB'); ?><?php echo $stats['boarding_totals']['full_board'] > 0 ? ', ' : ''; ?>
                                        <?php endif; ?>
                                        <?php if ($stats['boarding_totals']['full_board'] > 0): ?>
                                            <?php echo $stats['boarding_totals']['full_board']; ?> <?php echo t('fb', 'FB'); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Resource totals -->
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('cars', 'Cars'); ?></div>
                                <div class="footer-value"><?php echo $stats['total_cars']; ?></div>
                            </td>
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('drivers', 'Drivers'); ?></div>
                                <div class="footer-value"><?php echo $stats['total_drivers']; ?></div>
                            </td>
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('guides', 'Guides'); ?></div>
                                <div class="footer-value"><?php echo $stats['total_guides']; ?></div>
                            </td>
                            
                            <!-- Empty cell for services column -->
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('services', 'Services'); ?></div>
                                <div class="footer-value">-</div>
                            </td>
                            
                            <td class="footer-cell">
                                <div class="footer-label"><?php echo t('summary', 'Summary'); ?></div>
                                <div class="footer-subtext"><?php echo t('filtered_view', 'Filtered View'); ?></div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html> 