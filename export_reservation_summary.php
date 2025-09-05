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
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Fetch reservations in the selected period
$query = "
    SELECT 
        r.id,
        r.check_in_date,
        r.check_out_date,
        r.nights,
        r.notes,
        r.services_data,
        g.name as guest_name,
        g.adults,
        g.kids,
        g.babies,
        (g.adults + g.kids + g.babies) as total_pax
    FROM reservations r
    JOIN guests g ON r.guest_id = g.id
    WHERE r.check_in_date BETWEEN ? AND ?
    ORDER BY r.check_in_date ASC, g.name ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$start_date, $end_date]);
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

// Set headers for CSV download
$filename = 'reservation_summary_' . date('Y-m-d', strtotime($start_date)) . '_to_' . date('Y-m-d', strtotime($end_date)) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV headers
$headers = [
    t('reservation_id', 'ID'),
    t('guest', 'Guest'),
    t('check_in_date', 'Check-in Date'),
    t('nights', 'Nights'),
    t('pax', 'PAX'),
    t('single', 'Single'),
    t('double', 'Double'),
    t('triple', 'Triple'),
    t('quadruple', 'Quadruple'),
    t('cars', 'Cars'),
    t('drivers', 'Drivers'),
    t('guides', 'Guides'),
    t('services', 'Services'),
    t('notes', 'Notes')
];

fputcsv($output, $headers);

// Write data rows
foreach ($reservations as $reservation) {
    $resources = getAssignedResources($pdo, $reservation['id']);
    $services = getServices($pdo, $reservation['services_data']);
    
            // Format tent data for Excel
        $formatTentData = function($tents) {
            if (empty($tents)) return '';
            $formatted = [];
            foreach ($tents as $tent) {
                if (is_array($tent)) {
                    $formatted[] = "#{$tent['number']} ({$tent['type']})";
                } else {
                    $formatted[] = "#$tent";
                }
            }
            return implode('; ', $formatted);
        };
        
        $row = [
            $reservation['id'],
            $reservation['guest_name'],
            date('M j, Y', strtotime($reservation['check_in_date'])),
            $reservation['nights'],
            $reservation['total_pax'],
            $formatTentData($resources['tents']['single']),
            $formatTentData($resources['tents']['double']),
            $formatTentData($resources['tents']['triple']),
            $formatTentData($resources['tents']['quadruple']),
            !empty($resources['cars']) ? implode('; ', $resources['cars']) : '',
            !empty($resources['drivers']) ? implode('; ', $resources['drivers']) : '',
            !empty($resources['guides']) ? implode('; ', $resources['guides']) : '',
            !empty($services) ? implode('; ', $services) : '',
            $reservation['notes'] ?? ''
        ];
    
    fputcsv($output, $row);
}

fclose($output);
exit();
?>