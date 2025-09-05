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

// AJAX endpoint for getting tent ID from tent number
if (isset($_GET['get_tent_id'])) {
    header('Content-Type: application/json');
    $tent_number = $_GET['tent_number'] ?? '';
    
    if ($tent_number) {
        $stmt = $pdo->prepare("SELECT id FROM tents WHERE tent_number = ? ORDER BY id LIMIT 1");
        $stmt->execute([$tent_number]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            echo json_encode(['tent_id' => $result['id']]);
        } else {
            echo json_encode(['tent_id' => null]);
        }
    } else {
        echo json_encode(['tent_id' => null]);
    }
    exit();
}

// AJAX endpoint for fetching assigned tents for exceptions
if (isset($_GET['fetch_assigned_tents'])) {
    header('Content-Type: application/json');
    $reservation_id = intval($_GET['reservation_id'] ?? 0);
    $assigned_tents = [];
    
    if ($reservation_id) {
        // Fetch tents assigned to this reservation from reservation_tents table
        $query = "SELECT t.id, t.tent_number, t.tent_type 
                 FROM tents t 
                 JOIN reservation_tents rt ON t.id = rt.tent_id 
                 WHERE rt.reservation_id = ? 
                 ORDER BY t.tent_number";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$reservation_id]);
        $assigned_tents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode(['tents' => $assigned_tents]);
    exit();
}

// AJAX endpoint for fetching assigned tents for accommodation section
if (isset($_GET['fetch_assigned_tents_for_accommodation'])) {
    header('Content-Type: application/json');
    $reservation_id = intval($_GET['reservation_id'] ?? 0);
    $assigned_tents = [];
    
    if ($reservation_id) {
        // Fetch tents assigned to this reservation from reservation_tents table with bed configuration
        $query = "SELECT t.id, t.tent_number, t.tent_type, rt.start_date, rt.end_date 
                 FROM tents t 
                 JOIN reservation_tents rt ON t.id = rt.tent_id 
                 WHERE rt.reservation_id = ? 
                 ORDER BY t.tent_number";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$reservation_id]);
        $assigned_tents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get bed configurations from tent_specifications
        $reservation_stmt = $pdo->prepare("SELECT tent_specifications FROM reservations WHERE id = ?");
        $reservation_stmt->execute([$reservation_id]);
        $reservation = $reservation_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create a mapping from tent spec numbers to bed configurations
        $bed_configs = [];
        if (!empty($reservation['tent_specifications'])) {
            $specs = explode(', ', $reservation['tent_specifications']);
            foreach ($specs as $spec) {
                if (preg_match('/Tent\s*(\d+):\s*(\w+)\s*-\s*(\w+)\s*\(Tent\s*#(\d+)\)/', $spec, $matches)) {
                    $tent_spec_number = $matches[1];
                    $tent_type = $matches[2];
                    $bed_config = $matches[3];
                    $tent_display_number = $matches[4];
                    
                    $bed_configs[$tent_spec_number] = [
                        'bed_config' => $bed_config,
                        'tent_type' => $tent_type,
                        'tent_display_number' => $tent_display_number
                    ];
                }
            }
        }
        
        // Add bed configuration to each tent based on their position in the assigned tents array
        foreach ($assigned_tents as $index => &$tent) {
            $tent_spec_number = $index + 1; // Tent 1, Tent 2, etc.
            $bed_config = $bed_configs[$tent_spec_number] ?? null;
            
            if ($bed_config) {
                $tent['bed_config'] = $bed_config['bed_config'];
                $tent['tent_spec_number'] = $tent_spec_number;
                $tent['tent_spec_type'] = $bed_config['tent_type'];
            } else {
                $tent['bed_config'] = 'double'; // Default
                $tent['tent_spec_number'] = $tent_spec_number;
                $tent['tent_spec_type'] = $tent['tent_type'];
            }
        }
    }
    
    echo json_encode(['tents' => $assigned_tents]);
    exit();
}

// AJAX endpoint for fetching available tents based on type and period
if (isset($_GET['fetch_available_tents_by_type'])) {
    header('Content-Type: application/json');
    $tent_type = strtoupper($_GET['tent_type'] ?? '');
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    $edit_reservation_id = intval($_GET['edit_reservation_id'] ?? 0);
    
    $available_tents = [];
    
    if ($tent_type && $start_date && $end_date) {
        // Fetch available tents by type and period, excluding inactive tents and tents assigned to other reservations
        $query = "SELECT t.id, t.tent_number, t.tent_type 
                 FROM tents t 
                 WHERE UPPER(t.tent_type) = ? 
                 AND t.is_active = 1 
                 AND NOT EXISTS (
                     SELECT 1 FROM reservation_tents rt 
                     WHERE rt.tent_id = t.id 
                     AND rt.reservation_id != ? 
                     AND NOT (rt.end_date <= ? OR rt.start_date >= ?)
                 )
                 ORDER BY t.tent_number";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$tent_type, $edit_reservation_id, $start_date, $end_date]);
        $available_tents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode(['tents' => $available_tents]);
    exit();
}

$id = $_GET['id'] ?? 0;
$message = '';
$error = '';

// Fetch reservation and guest data
$stmt = $pdo->prepare("SELECT r.*, g.name as guest_name, g.email, g.phone, g.nationality, g.adults, g.kids, g.babies FROM reservations r JOIN guests g ON r.guest_id = g.id WHERE r.id = ?");
$stmt->execute([$id]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch existing exceptions
$exceptions_stmt = $pdo->prepare("SELECT * FROM reservation_exceptions WHERE reservation_id = ? ORDER BY id");
$exceptions_stmt->execute([$id]);
$existing_exceptions = $exceptions_stmt->fetchAll();
if (!$reservation) {
    header('Location: dashboard.php');
    exit();
}

// Fetch assigned resources
function getAssignedIds($pdo, $table, $reservation_id, $role = null) {
    if ($table === 'reservation_tents') {
        $stmt = $pdo->prepare("SELECT tent_id FROM reservation_tents WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($table === 'reservation_cars') {
        $stmt = $pdo->prepare("SELECT car_id FROM reservation_cars WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($table === 'reservation_humans') {
        $stmt = $pdo->prepare("SELECT human_id FROM reservation_humans WHERE reservation_id = ? AND role = ?");
        $stmt->execute([$reservation_id, $role]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return [];
}

$assigned_tent_ids = getAssignedIds($pdo, 'reservation_tents', $id);
$assigned_car_ids = getAssignedIds($pdo, 'reservation_cars', $id);
$assigned_driver_ids = getAssignedIds($pdo, 'reservation_humans', $id, 'driver');
$assigned_guide_ids = getAssignedIds($pdo, 'reservation_humans', $id, 'guide');

// Create a mapping of tent specifications to tent IDs
$tent_spec_to_id_mapping = [];
if (!empty($reservation['tent_specifications'])) {
    $specs = explode(', ', $reservation['tent_specifications']);
    foreach ($specs as $spec) {
        if (preg_match('/Tent\s*(\d+):\s*(\w+)\s*-\s*(\w+)\s*\(Tent\s*#(\d+)\)/', $spec, $matches)) {
            $tent_num = $matches[1];
            $tent_display_number = $matches[4];
            
            // Find the tent ID that corresponds to this tent number
            $tent_stmt = $pdo->prepare("SELECT id FROM tents WHERE tent_number = ? ORDER BY id LIMIT 1");
            $tent_stmt->execute([$tent_display_number]);
            $tent_result = $tent_stmt->fetch();
            if ($tent_result) {
                $tent_spec_to_id_mapping[$tent_num] = $tent_result['id'];
            }
        }
    }
}

// Fetch assigned resource periods for pre-filling
$car_period = $pdo->prepare("SELECT start_date, end_date FROM reservation_cars WHERE reservation_id = ? LIMIT 1");
$car_period->execute([$id]);
$car_period_row = $car_period->fetch(PDO::FETCH_ASSOC);
$car_start_date = $car_period_row['start_date'] ?? $reservation['car_start_date'] ?? $reservation['check_in_date'];
$car_end_date = $car_period_row['end_date'] ?? $reservation['car_end_date'] ?? $reservation['check_out_date'];
$driver_period = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'driver' LIMIT 1");
$driver_period->execute([$id]);
$driver_period_row = $driver_period->fetch(PDO::FETCH_ASSOC);
$driver_start_date = $driver_period_row['start_date'] ?? $reservation['driver_start_date'] ?? $reservation['check_in_date'];
$driver_end_date = $driver_period_row['end_date'] ?? $reservation['driver_end_date'] ?? $reservation['check_out_date'];
$guide_period = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'guide' LIMIT 1");
$guide_period->execute([$id]);
$guide_period_row = $guide_period->fetch(PDO::FETCH_ASSOC);
$guide_start_date = $guide_period_row['start_date'] ?? $reservation['guide_start_date'] ?? $reservation['check_in_date'];
$guide_end_date = $guide_period_row['end_date'] ?? $reservation['guide_end_date'] ?? $reservation['check_out_date'];

// Get active services
$services_stmt = $pdo->query("SELECT * FROM services WHERE is_active = 1 ORDER BY name");
$services = $services_stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Update guest info
        $total_people = $_POST['adults'] + $_POST['kids'] + $_POST['babies'];
        $guest_stmt = $pdo->prepare("UPDATE guests SET name = ?, email = ?, phone = ?, nationality = ?, adults = ?, kids = ?, babies = ?, total_people = ? WHERE id = ?");
        $guest_stmt->execute([
            $_POST['guest_name'],
            $_POST['email'],
            $_POST['phone'],
            $_POST['nationality'] ?? null,
            $_POST['adults'],
            $_POST['kids'],
            $_POST['babies'],
            $total_people,
            $reservation['guest_id']
        ]);
        // Calculate nights
        $check_in = new DateTime($_POST['check_in_date']);
        $check_out = new DateTime($_POST['check_out_date']);
        
        // Validate that check-in date is not after check-out date (allows same day)
        if ($check_in > $check_out) {
            throw new Exception('Check-in date cannot be after check-out date. Please correct the dates and try again.');
        }
        
        $nights = $check_out->diff($check_in)->days;
        // Validate tent specifications
        $tent_specifications = $_POST['tent_specifications'];
        $validation = validateTentSpecifications($tent_specifications);
        if (!$validation['valid']) {
            throw new Exception('Invalid tent specifications: ' . implode(', ', $validation['invalid_specs']));
        }
        // Determine tent type
        $royal_tents = 0; $normal_tents = 0;
        foreach ($validation['valid_specs'] as $spec) {
            if ($spec['tent_type'] === 'ROYAL') $royal_tents++;
            elseif ($spec['tent_type'] === 'NORMAL') $normal_tents++;
        }
        if ($royal_tents > 0 && $normal_tents > 0) $tent_type = 'MIXED';
        elseif ($royal_tents > 0) $tent_type = 'ROYAL';
        else $tent_type = 'NORMAL';
        // Calculate prices with exceptions
        $accommodation_price = calculateAccommodationPriceWithExceptions($pdo, $tent_specifications, $_POST['reservation_source'], $nights, $_POST['exceptions'] ?? []);
        $car_price_per_day = getCar4x4Price($pdo, $_POST['car_days']);
        $cars_total = $_POST['cars_4x4'] * $car_price_per_day * $_POST['car_days'];
        $staff_drivers = intval($_POST['staff_drivers'] ?? 0);
        $driver_days = intval($_POST['driver_days'] ?? 0);
        $staff_guides = intval($_POST['staff_guides'] ?? 0);
        $guide_days = intval($_POST['guide_days'] ?? 0);
        $driver_price = getDriverPrice($pdo);
        $guide_price = getGuidePrice($pdo);
        $staff_total = ($staff_drivers * $driver_price * $driver_days) + ($staff_guides * $guide_price * $guide_days);
        $services_total = 0;
        if (isset($_POST['services']) && is_array($_POST['services'])) {
            foreach ($_POST['services'] as $service_id => $service_data) {
                if (isset($service_data['selected']) && $service_data['selected'] == '1') {
                    $service_price = floatval($service_data['price']);
                    $services_total += $service_price;
                }
            }
        }
        $total_price = $accommodation_price + $cars_total + $staff_total + $services_total;
        $discount_type = $_POST['discount_type'] ?? 'none';
        if ($discount_type === 'percent') {
            $discount_value = floatval($_POST['discount_percent'] ?? 0);
        } elseif ($discount_type === 'amount') {
            $discount_value = floatval($_POST['discount_amount'] ?? 0);
        } else {
            $discount_value = 0;
        }
        // Prepare mixed tent allocation data
        $royal_adults = intval($_POST['royal_adults'] ?? 0);
        $royal_kids = intval($_POST['royal_kids'] ?? 0);
        $normal_adults = intval($_POST['normal_adults'] ?? 0);
        $normal_kids = intval($_POST['normal_kids'] ?? 0);
        
        // VALIDATE ALL RESOURCE AVAILABILITY BEFORE UPDATING RESERVATION
        $validation_errors = [];
        
        // Validate car availability (external conflicts + internal conflicts)
        if (!empty($_POST['car_ids'])) {
            $car_ids = $_POST['car_ids'];
            $car_start_dates = $_POST['car_start_dates'];
            $car_end_dates = $_POST['car_end_dates'];
            
            // Check for internal conflicts (same car used multiple times with overlapping periods)
            $car_assignments = [];
            foreach ($car_ids as $i => $car_id) {
                if (!empty($car_id)) {
                    $car_start = $car_start_dates[$i];
                    $car_end = $car_end_dates[$i];
                    
                    // Check if this car is already assigned in this reservation
                    if (isset($car_assignments[$car_id])) {
                        foreach ($car_assignments[$car_id] as $existing_period) {
                            // Check for overlap: if periods overlap, there's a conflict
                            if (!($car_end <= $existing_period['start'] || $car_start >= $existing_period['end'])) {
                                $validation_errors[] = str_replace('{car_id}', $car_id, t('car_assigned_multiple_times', 'Car #{car_id} is assigned multiple times with overlapping periods.'));
                                break 2; // Exit both loops
                            }
                        }
                    }
                    
                    // Store this assignment for future checks
                    if (!isset($car_assignments[$car_id])) {
                        $car_assignments[$car_id] = [];
                    }
                    $car_assignments[$car_id][] = ['start' => $car_start, 'end' => $car_end];
                    
                    // Validate external conflicts (car already booked by other reservations)
                    $stmt = $pdo->prepare("SELECT 1 FROM reservation_cars WHERE car_id = ? AND reservation_id != ? AND NOT (end_date <= ? OR start_date >= ?)");
                    $stmt->execute([$car_id, $id, $car_start, $car_end]);
                    if ($stmt->rowCount() > 0) {
                        $validation_errors[] = str_replace('{car_id}', $car_id, t('car_not_available', 'Car #{car_id} is not available for the chosen period.'));
                    }
                }
            }
        }
        
        // Validate driver availability (external conflicts + internal conflicts)
        if (!empty($_POST['driver_ids'])) {
            $driver_ids = $_POST['driver_ids'];
            $driver_start_dates = $_POST['driver_start_dates'];
            $driver_end_dates = $_POST['driver_end_dates'];
            
            // Check for internal conflicts (same driver used multiple times with overlapping periods)
            $driver_assignments = [];
            foreach ($driver_ids as $i => $driver_id) {
                if (!empty($driver_id)) {
                    $driver_start = $driver_start_dates[$i];
                    $driver_end = $driver_end_dates[$i];
                    
                    // Check if this driver is already assigned in this reservation
                    if (isset($driver_assignments[$driver_id])) {
                        foreach ($driver_assignments[$driver_id] as $existing_period) {
                            // Check for overlap: if periods overlap, there's a conflict
                            if (!($driver_end <= $existing_period['start'] || $driver_start >= $existing_period['end'])) {
                                $validation_errors[] = str_replace('{driver_id}', $driver_id, t('driver_assigned_multiple_times', 'Driver #{driver_id} is assigned multiple times with overlapping periods.'));
                                break 2; // Exit both loops
                            }
                        }
                    }
                    
                    // Store this assignment for future checks
                    if (!isset($driver_assignments[$driver_id])) {
                        $driver_assignments[$driver_id] = [];
                    }
                    $driver_assignments[$driver_id][] = ['start' => $driver_start, 'end' => $driver_end];
                    
                    // Validate external conflicts (driver already booked by other reservations)
                    $stmt = $pdo->prepare("SELECT 1 FROM reservation_humans WHERE human_id = ? AND role = 'driver' AND reservation_id != ? AND NOT (end_date <= ? OR start_date >= ?)");
                    $stmt->execute([$driver_id, $id, $driver_start, $driver_end]);
                    if ($stmt->rowCount() > 0) {
                        $validation_errors[] = str_replace('{driver_id}', $driver_id, t('driver_not_available', 'Driver #{driver_id} is not available for the chosen period.'));
                    }
                }
            }
        }
        
        // Validate guide availability (external conflicts + internal conflicts)
        if (!empty($_POST['guide_ids'])) {
            $guide_ids = $_POST['guide_ids'];
            $guide_start_dates = $_POST['guide_start_dates'];
            $guide_end_dates = $_POST['guide_end_dates'];
            
            // Check for internal conflicts (same guide used multiple times with overlapping periods)
            $guide_assignments = [];
            foreach ($guide_ids as $i => $guide_id) {
                if (!empty($guide_id)) {
                    $guide_start = $guide_start_dates[$i];
                    $guide_end = $guide_end_dates[$i];
                    
                    // Check if this guide is already assigned in this reservation
                    if (isset($guide_assignments[$guide_id])) {
                        foreach ($guide_assignments[$guide_id] as $existing_period) {
                            // Check for overlap: if periods overlap, there's a conflict
                            if (!($guide_end <= $existing_period['start'] || $guide_start >= $existing_period['end'])) {
                                $validation_errors[] = str_replace('{guide_id}', $guide_id, t('guide_assigned_multiple_times', 'Guide #{guide_id} is assigned multiple times with overlapping periods.'));
                                break 2; // Exit both loops
                            }
                        }
                    }
                    
                    // Store this assignment for future checks
                    if (!isset($guide_assignments[$guide_id])) {
                        $guide_assignments[$guide_id] = [];
                    }
                    $guide_assignments[$guide_id][] = ['start' => $guide_start, 'end' => $guide_end];
                    
                    // Validate external conflicts (guide already booked by other reservations)
                    $stmt = $pdo->prepare("SELECT 1 FROM reservation_humans WHERE human_id = ? AND role = 'guide' AND reservation_id != ? AND NOT (end_date <= ? OR start_date >= ?)");
                    $stmt->execute([$guide_id, $id, $guide_start, $guide_end]);
                    if ($stmt->rowCount() > 0) {
                        $validation_errors[] = str_replace('{guide_id}', $guide_id, t('guide_not_available', 'Guide #{guide_id} is not available for the chosen period.'));
                    }
                }
            }
        }
        
        // Validate tent availability (external conflicts + internal conflicts)
        if (!empty($_POST['tent_numbers']) && !empty($_POST['tent_types'])) {
            $tent_ids = $_POST['tent_numbers'];
            $tent_types = $_POST['tent_types'];
            $tent_start = $_POST['check_in_date'];
            $tent_end = $_POST['check_out_date'];
            // Check for internal conflicts (same tent id and type used multiple times)
            $tent_assignments = [];
            foreach ($tent_ids as $idx => $tent_id) {
                $tent_type = $tent_types[$idx] ?? '';
                if (!empty($tent_id) && !empty($tent_type)) {
                    $key = $tent_id . '_' . $tent_type;
                    if (isset($tent_assignments[$key])) {
                        $validation_errors[] = 'Tent #' . $tent_id . ' (' . $tent_type . ') is assigned multiple times.';
                        break;
                    }
                    $tent_assignments[$key] = true;
                    // Validate external conflicts (tent already booked by other reservations)
                    $stmt = $pdo->prepare("SELECT 1 FROM reservation_tents rt JOIN tents t ON rt.tent_id = t.id WHERE rt.tent_id = ? AND t.tent_type = ? AND rt.reservation_id != ? AND NOT (rt.end_date <= ? OR rt.start_date >= ?)");
                    $stmt->execute([$tent_id, $tent_type, $id, $tent_start, $tent_end]);
                    if ($stmt->rowCount() > 0) {
                        $validation_errors[] = 'Tent #' . $tent_id . ' (' . $tent_type . ') is not available for the chosen period.';
                    }
                }
            }
        }
        
        // If any validation errors, throw exception before updating reservation
        if (!empty($validation_errors)) {
            throw new Exception(t('resource_validation_failed', 'Resource availability validation failed: ') . implode(' ', $validation_errors));
        }
        
        // Update reservation (with tent-related fields)
       $update_stmt = $pdo->prepare("UPDATE reservations SET reservation_source=?, agency_name=?, check_in_date=?, check_out_date=?, nights=?, cars_4x4=?, car_days=?, car_with_driver=?, staff_drivers=?, driver_days=?, staff_guides=?, guide_days=?, tent_type=?, tent_specifications=?, number_of_tents=?, royal_adults_kids=?, normal_adults_kids=?, payment_cash=?, payment_bank_check=?, payment_transfer=?, total_price=?, discount_type=?, discount_value=?, payment_status=?, confirmation=?, confirmation_way=?, notes=?, services_data=?, boarding_information=?, tourist_tax=?, updated_by=?, updated_by_role=?, updated_at=NOW() WHERE id=?");
        $update_stmt->execute([
            $_POST['reservation_source'],
            $_POST['reservation_source'] === 'agency' ? $_POST['agency_name'] : null,
            $_POST['check_in_date'],
            $_POST['check_out_date'],
            $nights,
            $_POST['cars_4x4'],
            $_POST['car_days'],
            $_POST['car_with_driver'],
            $staff_drivers,
            $driver_days,
            $staff_guides,
            $guide_days,
            $tent_type,
            $tent_specifications,
            $_POST['number_of_tents'],
            $tent_type === 'MIXED' ? ($royal_adults . ':' . $royal_kids) : null,
            $tent_type === 'MIXED' ? ($normal_adults . ':' . $normal_kids) : null,
            $_POST['payment_cash'],
            $_POST['payment_bank_check'],
            $_POST['payment_transfer'],
            $total_price,
            $discount_type,
            $discount_value,
            $_POST['payment_status'],
            isset($_POST['confirmation']) ? 1 : 0,
            isset($_POST['confirmation']) && !empty($_POST['confirmation_way']) ? $_POST['confirmation_way'] : null,
            $_POST['notes'],
            isset($_POST['services']) ? json_encode($_POST['services']) : null,
            $_POST['boarding_information'] ?? null,
            $_POST['tourist_tax'] ?? 0,
            $_SESSION['user_id'],
            $_SESSION['user_role'],
            $id
        ]);
        
        // Handle exceptions
        // First, delete existing exceptions
        $pdo->prepare("DELETE FROM reservation_exceptions WHERE reservation_id = ?")->execute([$id]);
        
        // Then insert new exceptions
        $exceptions_count = 0;
        if (!empty($_POST['exceptions'])) {
            $exceptions_stmt = $pdo->prepare("INSERT INTO reservation_exceptions (reservation_id, guest_name, exception_type, assigned_tent_id, price_per_night, is_free, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($_POST['exceptions'] as $exception) {
                if (!empty($exception['guest_name']) && !empty($exception['exception_type']) && !empty($exception['assigned_tent_id'])) {
                    $exceptions_stmt->execute([
                        $id,
                        $exception['guest_name'],
                        $exception['exception_type'],
                        $exception['assigned_tent_id'],
                        $exception['price_per_night'] ?? 0,
                        isset($exception['is_free']) ? 1 : 0,
                        $exception['notes'] ?? null
                    ]);
                    $exceptions_count++;
                }
            }
        }
        
        // Update reservation with exceptions count
        $update_exceptions_stmt = $pdo->prepare("UPDATE reservations SET exceptions_count = ? WHERE id = ?");
        $update_exceptions_stmt->execute([$exceptions_count, $id]);

        // ASSIGN RESOURCES AFTER RESERVATION IS UPDATED (validation already passed)
        // Remove old tent assignments
        $pdo->prepare("DELETE FROM reservation_tents WHERE reservation_id = ?")->execute([$id]);
        
        // Clean up boarding information for deleted tents
        $boarding_information = $_POST['boarding_information'] ?? null;
        if ($boarding_information) {
            try {
                $boarding_data = json_decode($boarding_information, true);
                if (is_array($boarding_data) && isset($boarding_data['tents'])) {
                    // Only keep boarding data for tents that are still in the specifications
                    $valid_tent_numbers = [];
                    if (!empty($tent_specifications)) {
                        preg_match_all('/Tent (\d+):/', $tent_specifications, $matches);
                        $valid_tent_numbers = $matches[1] ?? [];
                    }
                    
                    // Filter out boarding data for deleted tents
                    $boarding_data['tents'] = array_filter($boarding_data['tents'], function($tent) use ($valid_tent_numbers) {
                        return in_array($tent['tent_number'], $valid_tent_numbers);
                    });
                    
                    // Re-index the array
                    $boarding_data['tents'] = array_values($boarding_data['tents']);
                    
                    // Update the boarding_information with cleaned data
                    $_POST['boarding_information'] = json_encode($boarding_data);
                }
            } catch (Exception $e) {
                // If there's an error parsing boarding information, set it to null
                $_POST['boarding_information'] = null;
            }
        }
        
        // Parse tent numbers and types from tent_specifications and save assigned tents
        if (!empty($tent_specifications)) {
                    // Example: Tent 1: ROYAL - double (Tent #25), Tent 2: NORMAL - triple (Tent #12)
        preg_match_all('/Tent \d+: (ROYAL|NORMAL) - \w+ \(Tent #(\d+)\)/i', $tent_specifications, $matches, PREG_SET_ORDER);
            $tent_start = $_POST['check_in_date'];
            $tent_end = $_POST['check_out_date'];
            foreach ($matches as $match) {
                $tent_type = strtoupper($match[1]); // ROYAL or NORMAL
                $tent_number = $match[2];
                // Look up tent ID by tent_number and tent_type
                $stmt = $pdo->prepare("SELECT id FROM tents WHERE tent_number = ? AND UPPER(tent_type) = ? LIMIT 1");
                $stmt->execute([$tent_number, $tent_type]);
                $tent_id = $stmt->fetchColumn();
                if ($tent_id) {
                    $stmt = $pdo->prepare("INSERT INTO reservation_tents (reservation_id, tent_id, start_date, end_date) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$id, $tent_id, $tent_start, $tent_end]);
                }
            }
        }
        
        // Remove old car/driver/guide assignments
        $pdo->prepare("DELETE FROM reservation_cars WHERE reservation_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM reservation_humans WHERE reservation_id = ? AND role = 'driver'")->execute([$id]);
        $pdo->prepare("DELETE FROM reservation_humans WHERE reservation_id = ? AND role = 'guide'")->execute([$id]);
        
        // Save assigned cars (per-resource period)
        if (!empty($_POST['car_ids'])) {
            $car_ids = $_POST['car_ids'];
            $car_start_dates = $_POST['car_start_dates'];
            $car_end_dates = $_POST['car_end_dates'];
            foreach ($car_ids as $i => $car_id) {
                if (!empty($car_id)) {
                    $car_start = $car_start_dates[$i];
                    $car_end = $car_end_dates[$i];
                    $stmt = $pdo->prepare("INSERT INTO reservation_cars (reservation_id, car_id, start_date, end_date) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$id, $car_id, $car_start, $car_end]);
                }
            }
        }
        
        // Save assigned drivers (per-resource period)
        if (!empty($_POST['driver_ids'])) {
            $driver_ids = $_POST['driver_ids'];
            $driver_start_dates = $_POST['driver_start_dates'];
            $driver_end_dates = $_POST['driver_end_dates'];
            foreach ($driver_ids as $i => $driver_id) {
                if (!empty($driver_id)) {
                    $driver_start = $driver_start_dates[$i];
                    $driver_end = $driver_end_dates[$i];
                    $stmt = $pdo->prepare("INSERT INTO reservation_humans (reservation_id, human_id, start_date, end_date, role) VALUES (?, ?, ?, ?, 'driver')");
                    $stmt->execute([$id, $driver_id, $driver_start, $driver_end]);
                }
            }
        }
        
        // Save assigned guides (per-resource period)
        if (!empty($_POST['guide_ids'])) {
            $guide_ids = $_POST['guide_ids'];
            $guide_start_dates = $_POST['guide_start_dates'];
            $guide_end_dates = $_POST['guide_end_dates'];
            foreach ($guide_ids as $i => $guide_id) {
                if (!empty($guide_id)) {
                    $guide_start = $guide_start_dates[$i];
                    $guide_end = $guide_end_dates[$i];
                    $stmt = $pdo->prepare("INSERT INTO reservation_humans (reservation_id, human_id, start_date, end_date, role) VALUES (?, ?, ?, ?, 'guide')");
                    $stmt->execute([$id, $guide_id, $guide_start, $guide_end]);
                }
            }
        }
        
        $message = t('reservation_updated_successfully', 'Reservation updated successfully!');
        header('Location: view_reservation_details.php?id=' . $id);
        exit();
    } catch (Exception $e) {
        $error = t('error_updating_reservation', 'Error updating reservation: ') . $e->getMessage();
    }
}

// Before rendering the form, decode services_data for pre-filling
$services_data = [];
if (!empty($reservation['services_data'])) {
    $services_data = json_decode($reservation['services_data'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $services_data = [];
    }
}

// The rest of the file should mirror add_reservation.php, but pre-fill all fields with $reservation and assigned resource IDs.
// The AJAX endpoints and JS for tent/car/driver/guide selection should be reused from add_reservation.php.
// ... (UI code omitted for brevity, but would be a copy of add_reservation.php with pre-filled values) ...
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Reservation - ABDELMOULA CAMP</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .resource-section, .resource-item {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            font-size: 13px;
            max-width: 320px;
            min-width: 180px;
            display: inline-block;
            vertical-align: top;
        }
        .resource-header h5 {
            font-size: 14px;
            margin: 0 0 6px 0;
            color: #495057;
            font-weight: 600;
        }
        .resource-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 8px;
        }
        .resource-count-row {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .resource-count-group label {
            font-size: 13px;
            margin-bottom: 2px;
        }
        .resource-count-group input[type="number"] {
            padding: 4px 6px;
            font-size: 13px;
            width: 80px;
        }
        @media (max-width: 700px) {
            .resource-section, .resource-item { max-width: 100%; min-width: 0; font-size: 12px; padding: 8px 6px; }
            .resource-grid { flex-direction: column; gap: 8px; }
            .resource-count-row { flex-direction: column; gap: 8px; }
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 14px;
            color: #495057;
            margin-bottom: 10px;
        }
        
        .checkbox-label input[type="checkbox"] {
            margin-right: 8px;
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        .checkmark {
            position: relative;
            display: inline-block;
            width: 16px;
            height: 16px;
            background: #fff;
            border: 2px solid #b8860b;
            border-radius: 3px;
            margin-right: 8px;
        }
        
        .checkbox-label input[type="checkbox"]:checked + .checkmark:after {
            content: '✓';
            position: absolute;
            top: -2px;
            left: 1px;
            color: #b8860b;
            font-weight: bold;
            font-size: 12px;
        }
        
        .form-row-pair {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row-pair .form-section {
            flex: 1;
        }
        
        .exceptions-container {
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 12px;
        }
        
        .exception-item {
            background: #ffffff;
            border: 3px solid #9ca3af;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 0;
            position: relative;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
        }
        

        
        .exception-item h4 {
            margin: 0 0 8px 0;
            color: #495057;
            font-size: 13px;
            font-weight: 600;
            text-transform: none;
            letter-spacing: 0.2px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 6px;
            position: relative;
        }
        
        .exception-item h4::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 40px;
            height: 1px;
            background: #b8860b;
        }
        
        .exception-item .remove-exception {
            position: absolute;
            top: 8px;
            right: 8px;
            background:rgb(167, 117, 11);
            color: white;
            border: none;
            border-radius: 4px;
            width: 26px;
            height: 26px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-weight: bold;
            z-index: 10;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            pointer-events: auto;
            padding: 0;
            margin: 0;
            min-width: 26px;
            min-height: 26px;
            box-sizing: border-box;
            outline: none;
            text-decoration: none;
        }
        
        .exception-item .remove-exception:focus {
            outline: 2px solid #b8860b;
            outline-offset: 2px;
        }
        

        
        .exception-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-start;
        }
        
        .exception-fields .form-group {
            flex: 1;
            min-width: 140px;
            margin-bottom: 0;
        }
        
        .exception-fields .form-group:last-child {
            flex-basis: 100%;
            min-width: 100%;
        }
        
        .exception-fields .form-group label {
            font-size: 11px;
            font-weight: 500;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .exception-fields .form-group input,
        .exception-fields .form-group select,
        .exception-fields .form-group textarea {
            font-size: 12px;
            padding: 5px 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }
        
        @media (max-width: 1200px) {
            .exceptions-container {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 10px;
            }
            
            .exception-fields .form-group {
                min-width: 100px;
            }
        }
        
        @media (max-width: 768px) {
            .exceptions-container {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .exception-fields {
                flex-direction: column;
                gap: 4px;
            }
            
            .exception-fields .form-group {
                flex: none;
                min-width: 100%;
            }
            
            .exception-item {
                padding: 10px;
            }
            
            .exception-item h4 {
                font-size: 12px;
            }
            
            .exception-item .remove-exception {
                width: 24px;
                height: 24px;
                font-size: 12px;
            }
        }
        
        .exception-fields .checkbox-group {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            height: 32px;
        }
        
        .exception-fields .checkbox-group input[type="checkbox"] {
            margin: 0;
            width: 14px;
            height: 14px;
            accent-color: #b8860b;
            flex-shrink: 0;
        }
        
        .exception-fields .checkbox-group label {
            font-size: 12px;
            margin: 0;
            line-height: 1;
            display: flex;
            align-items: center;
        }

        /* Improved Tent Cards */
        .tent-card {
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .tent-card:hover {
            border-color: #b8860b;
            box-shadow: 0 6px 20px rgba(184, 134, 11, 0.15);
        }

        .tent-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f3f4f6;
        }

        .tent-header h4 {
            margin: 0;
            color: #1f2937;
            font-size: 18px;
            font-weight: 600;
        }

        .tent-status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #d1d5db;
            transition: background-color 0.3s ease;
        }

        .tent-status-indicator.complete {
            background: #10b981;
        }

        .tent-status-indicator.incomplete {
            background: #f59e0b;
        }

        .tent-delete { 
            background: transparent; 
            border: none; 
            color: #b91c1c; 
            font-size: 18px; 
            cursor: pointer; 
        }
        .tent-delete:hover { 
            color: #7f1d1d; 
        }

        .tent-configuration {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .config-section, .boarding-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
        }

        .config-section h5, .boarding-section h5 {
            margin: 0 0 12px 0;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .boarding-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .boarding-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
        }

        .boarding-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }

        .boarding-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #b8860b;
            cursor: pointer;
        }

        .boarding-label {
            font-weight: 500;
            color: #374151;
        }

        .boarding-days-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .boarding-days-group label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }

        .boarding-days-group input[type="number"] {
            width: 80px;
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 12px;
        }

        .boarding-days-group input[type="number"]:focus {
            border-color: #b8860b;
            outline: none;
            box-shadow: 0 0 0 3px rgba(184, 134, 11, 0.1);
        }

        /* Kids Section Styles */
        .kids-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
        }

        .kids-section h5 {
            margin: 0 0 12px 0;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kids-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .kids-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
        }

        .kids-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }

        .kids-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #b8860b;
            cursor: pointer;
        }

        .kids-label {
            font-weight: 500;
            color: #374151;
        }

        .kids-number-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .kids-number-group label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }

        .kids-number-group input[type="number"] {
            width: 80px;
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 12px;
        }

        .kids-number-group input[type="number"]:focus {
            border-color: #b8860b;
            outline: none;
            box-shadow: 0 0 0 3px rgba(184, 134, 11, 0.1);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .tent-card {
                padding: 16px;
                margin-bottom: 16px;
            }

            .tent-header h4 {
                font-size: 16px;
            }

            .config-section, .boarding-section {
                padding: 12px;
            }

            .boarding-option {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .boarding-days-group {
                margin-left: 0;
                width: 100%;
            }

            .boarding-days-group input[type="number"] {
                width: 100%;
            }
        }

    </style>
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>
    <div class="container">
        <div class="table-header">
            <h2><?= t('Edit Reservation', 'Edit Reservation') ?></h2>
            <a href="dashboard.php" class="btn btn-secondary"><?= t('Back to Dashboard', 'Back to Dashboard') ?></a>
        </div>
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" class="form-container" id="reservationForm" onsubmit="return validateDates()">
            <!-- Guest Information -->
            <div class="form-row-pair">
                <div class="form-section">
                    <h3><?= t('Guest Information', 'Guest Information') ?></h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label><?= t('Reservation Source *', 'Reservation Source *') ?></label>
                            <select id="reservation_source" name="reservation_source" required onchange="toggleAgencyField()">
                                <option value="individual" <?php if ($reservation['reservation_source'] === 'individual') echo 'selected'; ?>><?= t('Individual', 'Individual') ?></option>
                                <option value="agency" <?php if ($reservation['reservation_source'] === 'agency') echo 'selected'; ?>><?= t('Agency', 'Agency') ?></option>
                            </select>
                        </div>
                        <div class="form-group" id="agency_name_group" style="<?php echo $reservation['reservation_source'] === 'agency' ? '' : 'display:none;'; ?>">
                            <label><?= t('Agency Name *', 'Agency Name *') ?></label>
                            <input type="text" id="agency_name" name="agency_name" placeholder="<?= t('Enter agency name', 'Enter agency name') ?>" value="<?php echo htmlspecialchars($reservation['agency_name'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><?= t('Guest Name *', 'Guest Name *') ?></label>
                            <input type="text" id="guest_name" name="guest_name" required value="<?php echo htmlspecialchars($reservation['guest_name']); ?>">
                        </div>
                        <div class="form-group">
                            <label><?= t('Email', 'Email') ?></label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($reservation['email']); ?>">
                        </div>
                        <div class="form-group">
                            <label><?= t('Phone', 'Phone') ?></label>
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($reservation['phone']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><?= t('nationality', 'Nationality') ?></label>
                            <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($reservation['nationality'] ?? ''); ?>" placeholder="<?= t('enter_nationality', 'Enter nationality') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><?= t('Adults', 'Adults') ?></label>
                            <input type="number" id="adults" name="adults" value="<?php echo $reservation['adults']; ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label><?= t('Kids', 'Kids') ?></label>
                            <input type="number" id="kids" name="kids" value="<?php echo $reservation['kids']; ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label><?= t('Babies', 'Babies') ?></label>
                            <input type="number" id="babies" name="babies" value="<?php echo $reservation['babies']; ?>" min="0">
                        </div>
                    </div>
                </div>
                
                <!-- Exceptions Section -->
                <div class="form-section">
                    <h3><?= t('exceptions', 'Exceptions') ?></h3>
                    <div class="exceptions-container" id="exceptions_container">
                        <?php foreach ($existing_exceptions as $index => $exception): ?>
                        <div class="exception-item" id="exception_<?php echo $index + 1; ?>">
                            <button type="button" class="remove-exception" onclick="removeException(<?php echo $index + 1; ?>)">×</button>
                            <h4><?= t('exception_guest', 'Exception Guest') ?> <?php echo $index + 1; ?></h4>
                            <div class="exception-fields">
                                <div class="form-group">
                                    <label for="exception_guest_name_<?php echo $index + 1; ?>"><?= t('guest_name', 'Guest Name') ?></label>
                                    <input type="text" id="exception_guest_name_<?php echo $index + 1; ?>" name="exceptions[<?php echo $index + 1; ?>][guest_name]" value="<?php echo htmlspecialchars($exception['guest_name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="exception_type_<?php echo $index + 1; ?>"><?= t('exception_type', 'Exception Type') ?></label>
                                    <select id="exception_type_<?php echo $index + 1; ?>" name="exceptions[<?php echo $index + 1; ?>][exception_type]" required>
                                        <option value=""><?= t('select_type', 'Select Type') ?></option>
                                        <option value="driver" <?php if ($exception['exception_type'] === 'driver') echo 'selected'; ?>><?= t('driver', 'Driver') ?></option>
                                        <option value="guide" <?php if ($exception['exception_type'] === 'guide') echo 'selected'; ?>><?= t('guide', 'Guide') ?></option>
                                        <option value="company" <?php if ($exception['exception_type'] === 'company') echo 'selected'; ?>><?= t('company', 'Company') ?></option>
                                        <option value="other" <?php if ($exception['exception_type'] === 'other') echo 'selected'; ?>><?= t('other', 'Other') ?></option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="exception_tent_<?php echo $index + 1; ?>"><?= t('assigned_tent', 'Assigned Tent') ?></label>
                                    <select id="exception_tent_<?php echo $index + 1; ?>" name="exceptions[<?php echo $index + 1; ?>][assigned_tent_id]" required>
                                        <option value=""><?= t('select_tent', 'Select Tent') ?></option>
                                        <?php
                                        // Get assigned tents for this exception from reservation_tents table
                                        $assigned_tents_query = "SELECT t.id, t.tent_number, t.tent_type 
                                                               FROM tents t 
                                                               JOIN reservation_tents rt ON t.id = rt.tent_id 
                                                               WHERE rt.reservation_id = ? 
                                                               ORDER BY t.tent_number";
                                        $assigned_tents_stmt = $pdo->prepare($assigned_tents_query);
                                        $assigned_tents_stmt->execute([$id]);
                                        $assigned_tents = $assigned_tents_stmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach ($assigned_tents as $tent) {
                                            $selected = ($exception['assigned_tent_id'] == $tent['id']) ? 'selected' : '';
                                            echo "<option value=\"{$tent['id']}\" $selected>Tent #{$tent['tent_number']} - {$tent['tent_type']}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="exception_price_<?php echo $index + 1; ?>"><?= t('price_per_night', 'Price per Night') ?> (TND)</label>
                                    <input type="number" id="exception_price_<?php echo $index + 1; ?>" name="exceptions[<?php echo $index + 1; ?>][price_per_night]" value="<?php echo $exception['price_per_night']; ?>" min="0" step="0.01" onchange="toggleExceptionFree(<?php echo $index + 1; ?>)" <?php if ($exception['is_free']) echo 'disabled'; ?>>
                                </div>
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="exception_free_<?php echo $index + 1; ?>" name="exceptions[<?php echo $index + 1; ?>][is_free]" value="1" <?php if ($exception['is_free']) echo 'checked'; ?> onchange="toggleExceptionPrice(<?php echo $index + 1; ?>)">
                                        <label for="exception_free_<?php echo $index + 1; ?>"><?= t('is_free', 'Is Free') ?></label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="exception_notes_<?php echo $index + 1; ?>"><?= t('exception_notes', 'Notes') ?></label>
                                    <textarea id="exception_notes_<?php echo $index + 1; ?>" name="exceptions[<?php echo $index + 1; ?>][notes]" rows="2"><?php echo htmlspecialchars($exception['notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-group">
                        <button type="button" class="btn btn-secondary" onclick="addException()">
                            <?= t('add_exception', 'Add Exception') ?>
                        </button>
                    </div>
                </div>
            </div>
            <div class="form-section">
                <h3><?= t('Reservation Details', 'Reservation Details') ?></h3>
                <div class="form-row">
                    <div class="form-group">
                        <label><?= t('Check-in Date *', 'Check-in Date *') ?></label>
                        <input type="date" id="check_in_date" name="check_in_date" required value="<?php echo htmlspecialchars($reservation['check_in_date'] ?? ''); ?>" onchange="validateDates()">
                    </div>
                    <div class="form-group">
                        <label><?= t('Check-out Date *', 'Check-out Date *') ?></label>
                        <input type="date" id="check_out_date" name="check_out_date" required value="<?php echo htmlspecialchars($reservation['check_out_date'] ?? ''); ?>" onchange="validateDates()">
                    </div>
                    <div id="date-error" class="error-message" style="display: none; color: red; margin-top: 5px;"></div>
                </div>
                <!-- Cars Section -->
                <div class="form-row">
                    <div class="form-group">
                        <label><?= t('Number of Cars', 'Number of Cars') ?></label>
                        <input type="number" id="cars_4x4" name="cars_4x4" value="<?php echo htmlspecialchars($reservation['cars_4x4'] ?? 0); ?>" min="0" onchange="renderCarSelectors();">
                    </div>
                    <div id="car_selectors_group"></div>
                </div>
                <!-- Drivers Section -->
                <div class="form-row">
                    <div class="form-group">
                        <label><?= t('Number of Drivers', 'Number of Drivers') ?></label>
                        <input type="number" id="staff_drivers" name="staff_drivers" value="<?php echo htmlspecialchars($reservation['staff_drivers'] ?? 0); ?>" min="0" onchange="renderDriverSelectors();">
                    </div>
                    <div id="driver_selectors_group"></div>
                </div>
                <!-- Guides Section -->
                <div class="form-row">
                    <div class="form-group">
                        <label><?= t('Number of Guides', 'Number of Guides') ?></label>
                        <input type="number" id="staff_guides" name="staff_guides" value="<?php echo htmlspecialchars($reservation['staff_guides'] ?? 0); ?>" min="0" onchange="renderGuideSelectors();">
                    </div>
                    <div id="guide_selectors_group"></div>
                </div>
                <div id="resourceSelection"></div>
                
                <!-- Hidden fields for processing -->
                <input type="hidden" id="car_days" name="car_days" value="<?php echo htmlspecialchars($reservation['car_days'] ?? 0); ?>">
                <input type="hidden" id="driver_days" name="driver_days" value="<?php echo htmlspecialchars($reservation['driver_days'] ?? 0); ?>">
                <input type="hidden" id="guide_days" name="guide_days" value="<?php echo htmlspecialchars($reservation['guide_days'] ?? 0); ?>">
                <input type="hidden" id="car_with_driver" name="car_with_driver" value="<?php echo htmlspecialchars($reservation['car_with_driver'] ?? 0); ?>">
            </div>

            <div class="form-section">
                <h3><?= t('Accommodation', 'Accommodation') ?></h3>
                <div class="form-note" style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 12px; margin-bottom: 16px; border-radius: 4px;">
                    <strong><?= t('note', 'Note') ?>:</strong> <?= t('tents_optional_note', 'Tents are optional. Leave blank for day visitors who don\'t need overnight accommodation.') ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label><?= t('Number of Tents', 'Number of Tents') ?></label>
                        <input type="number" id="number_of_tents" name="number_of_tents" value="<?php echo htmlspecialchars($reservation['number_of_tents']); ?>" min="0" max="100" onchange="updateTentOptions()">
                    </div>
                </div>
                <!-- Dynamic Tent Options -->
                <div id="tent_options_container" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;"><!-- Tent options will be generated dynamically --></div>
                <input type="hidden" id="tent_specifications" name="tent_specifications" value="<?php echo htmlspecialchars($reservation['tent_specifications']); ?>">
                
                <!-- Mixed Tent Allocation (shown only when tent type is mixed) -->
                <div id="mixed-tent-allocation" style="display: none;">
                    <h4><?= t('Mixed Tent Allocation', 'Mixed Tent Allocation') ?></h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label><?= t('ROYAL Adults', 'ROYAL Adults') ?></label>
                            <input type="number" id="royal_adults" name="royal_adults" value="<?php 
                                if ($reservation['tent_type'] === 'MIXED' && $reservation['royal_adults_kids']) {
                                    echo explode(':', $reservation['royal_adults_kids'])[0];
                                } else {
                                    echo '0';
                                }
                            ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label><?= t('ROYAL Kids', 'ROYAL Kids') ?></label>
                            <input type="number" id="royal_kids" name="royal_kids" value="<?php 
                                if ($reservation['tent_type'] === 'MIXED' && $reservation['royal_adults_kids']) {
                                    echo explode(':', $reservation['royal_adults_kids'])[1];
                                } else {
                                    echo '0';
                                }
                            ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label><?= t('Normal Adults', 'Normal Adults') ?></label>
                            <input type="number" id="normal_adults" name="normal_adults" value="<?php 
                                if ($reservation['tent_type'] === 'MIXED' && $reservation['normal_adults_kids']) {
                                    echo explode(':', $reservation['normal_adults_kids'])[0];
                                } else {
                                    echo '0';
                                }
                            ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label><?= t('Normal Kids', 'Normal Kids') ?></label>
                            <input type="number" id="normal_kids" name="normal_kids" value="<?php 
                                if ($reservation['tent_type'] === 'MIXED' && $reservation['normal_adults_kids']) {
                                    echo explode(':', $reservation['normal_adults_kids'])[1];
                                } else {
                                    echo '0';
                                }
                            ?>" min="0">
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3><?= t('Services', 'Services') ?></h3>
                <div class="services-grid">
                    <?php foreach ($services as $service): ?>
                    <div class="service-item">
                        <div class="service-header">
                            <label>
                                <input type="checkbox" name="services[<?php echo $service['id']; ?>][selected]" value="1" <?php if (!empty($services_data[$service['id']]['selected'])) echo 'checked'; ?>>
                                <?php echo htmlspecialchars($service['name']); ?>
                            </label>
                        </div>
                        <div class="service-price">
                            <label><?= t('Price (TND)', 'Price (TND)') ?></label>
                            <input type="number" name="services[<?php echo $service['id']; ?>][price]" value="<?php echo isset($services_data[$service['id']]['price']) ? htmlspecialchars($services_data[$service['id']]['price']) : $service['price']; ?>" step="0.01" min="0">
                        </div>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>

            <div class="form-row-pair">

                <div class="form-section">
                    <h3><?= t('Payment', 'Payment') ?></h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label><?= t('Cash Payment (TND)', 'Cash Payment (TND)') ?></label>
                            <input type="number" id="payment_cash" name="payment_cash" value="<?php echo htmlspecialchars($reservation['payment_cash']); ?>" min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label><?= t('Bank Check Payment (TND)', 'Bank Check Payment (TND)') ?></label>
                            <input type="number" id="payment_bank_check" name="payment_bank_check" value="<?php echo htmlspecialchars($reservation['payment_bank_check']); ?>" min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label><?= t('Transfer Payment (TND)', 'Transfer Payment (TND)') ?></label>
                            <input type="number" id="payment_transfer" name="payment_transfer" value="<?php echo htmlspecialchars($reservation['payment_transfer'] ?? '0'); ?>" min="0" step="0.01">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><?= t('Payment Status', 'Payment Status') ?></label>
                        <select id="payment_status" name="payment_status">
                            <option value="pending" <?php if ($reservation['payment_status'] === 'pending') echo 'selected'; ?>><?= t('Pending', 'Pending') ?></option>
                            <option value="partial" <?php if ($reservation['payment_status'] === 'partial') echo 'selected'; ?>><?= t('Partial', 'Partial') ?></option>
                            <option value="paid" <?php if ($reservation['payment_status'] === 'paid') echo 'selected'; ?>><?= t('Paid', 'Paid') ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="confirmation" name="confirmation" value="1" <?php if ($reservation['confirmation'] ?? 0) echo 'checked'; ?> onchange="toggleConfirmationWay()">
                            <span class="checkmark"></span>
                            <?= t('confirmation', 'Confirmation') ?>
                        </label>
                    </div>
                    <div class="form-group" id="confirmation_way_group" style="display: <?php echo ($reservation['confirmation'] ?? 0) ? '' : 'none'; ?>;">
                        <label for="confirmation_way"><?= t('confirmation_way', 'Confirmation Way') ?></label>
                        <input type="text" id="confirmation_way" name="confirmation_way" value="<?php echo htmlspecialchars($reservation['confirmation_way'] ?? ''); ?>" placeholder="<?= t('enter_confirmation_way', 'Enter confirmation method (e.g., Phone, Email, WhatsApp)') ?>">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><?= t('Discount Type', 'Discount Type') ?></label>
                            <select id="discount_type" name="discount_type" onchange="toggleDiscountFields()">
                                <option value="none" <?php if ($reservation['discount_type'] === 'none') echo 'selected'; ?>><?= t('None', 'None') ?></option>
                                <option value="percent" <?php if ($reservation['discount_type'] === 'percent') echo 'selected'; ?>><?= t('Percentage (%)', 'Percentage (%)') ?></option>
                                <option value="amount" <?php if ($reservation['discount_type'] === 'amount') echo 'selected'; ?>><?= t('Amount (TND)', 'Amount (TND)') ?></option>
                            </select>
                        </div>
                        <div class="form-group" id="discount_percent_group" style="display: <?php echo $reservation['discount_type'] === 'percent' ? '' : 'none'; ?>;">
                            <label><?= t('Discount (%)', 'Discount (%)') ?></label>
                            <input type="number" step="0.01" min="0" max="100" id="discount_percent" name="discount_percent" value="<?php echo htmlspecialchars($reservation['discount_type'] === 'percent' ? $reservation['discount_value'] : ''); ?>">
                        </div>
                        <div class="form-group" id="discount_amount_group" style="display: <?php echo $reservation['discount_type'] === 'amount' ? '' : 'none'; ?>;">
                            <label><?= t('Discount (TND)', 'Discount (TND)') ?></label>
                            <input type="number" step="0.01" min="0" id="discount_amount" name="discount_amount" value="<?php echo htmlspecialchars($reservation['discount_type'] === 'amount' ? $reservation['discount_value'] : ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="tourist_tax"><?= t('tourist_tax', 'Tourist Tax') ?> (TND)</label>
                        <input type="number" id="tourist_tax" name="tourist_tax" value="<?php echo htmlspecialchars($reservation['tourist_tax'] ?? '0'); ?>" min="0" step="0.01">
                    </div>
                </div>
                <div class="form-section">
                    <h3><?= t('Additional Information', 'Additional Information') ?></h3>
                    <div class="form-group">
                        <label><?= t('Notes', 'Notes') ?></label>
                        <textarea id="notes" name="notes" rows="4"><?php echo htmlspecialchars($reservation['notes']); ?></textarea>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= t('Update Reservation', 'Update Reservation') ?></button>
                <a href="view_reservation_details.php?id=<?php echo $id; ?>" class="btn btn-secondary"><?= t('Cancel', 'Cancel') ?></a>
            </div>
        </form>
    </div>
    <script>
        // Date validation function
        function validateDates() {
            const checkInDate = document.getElementById('check_in_date').value;
            const checkOutDate = document.getElementById('check_out_date').value;
            const errorDiv = document.getElementById('date-error');
            
            if (checkInDate && checkOutDate) {
                if (checkInDate > checkOutDate) {
                    errorDiv.textContent = 'Check-in date cannot be after check-out date. Please correct the dates.';
                    errorDiv.style.display = 'block';
                    return false;
                } else {
                    errorDiv.style.display = 'none';
                    return true;
                }
            }
            return true;
        }
        
        // Translations for JavaScript
        const translations = {
            half_board: "<?= t('half_board', 'Half Board') ?>",
            full_board: "<?= t('full_board', 'Full Board') ?>",
            days: "<?= t('days', 'Days') ?>"
        };
        
        // --- JS for edit reservation mode ---
        
        // Tent-related functions
        function updateTentOptions() {
            const numberOfTents = parseInt(document.getElementById('number_of_tents').value) || 0;
            const container = document.getElementById('tent_options_container');
            container.innerHTML = '';
            
            // If 0 tents, show a message for day visitors
            if (numberOfTents === 0) {
                container.innerHTML = '<div class="form-note" style="background: #e8f5e8; border-left: 4px solid #4caf50; padding: 12px; border-radius: 4px; text-align: center; color: #2e7d32;"><?= t("day_visitor_mode", "Day Visitor Mode - No overnight accommodation required") ?></div>';
                // Clear tent specifications when no tents
                document.getElementById('tent_specifications').value = '';
                updateTentSpecifications();
                updateMixedTentAllocationVisibility();
                return;
            }
            
            for (let i = 1; i <= numberOfTents; i++) {
                const tentOption = document.createElement('div');
                tentOption.className = 'tent-option';
                tentOption.setAttribute('data-tent', i);
                tentOption.innerHTML = `
                    <div class="tent-card">
                        <div class="tent-header">
                            <h4><?= t('tent', 'Tent') ?> ${i}</h4>
                            <button type="button" class="tent-delete" aria-label="Delete tent" onclick="removeTentOption(this)">✕</button>
                            <div class="tent-status-indicator" id="tent_${i}_status"></div>
                        </div>
                        
                        <div class="tent-configuration">
                            <div class="config-section">
                                <h5><?= t('configuration', 'Configuration') ?></h5>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="tent_${i}_type"><?= t('tent_type', 'Tent Type') ?></label>
                                        <select id="tent_${i}_type" name="tent_${i}_type" onchange="fetchAvailableTentNumbers(${i})">
                                            <option value=""><?php echo t('select_type', 'Select Type'); ?></option>
                                            <option value="NORMAL"><?php echo t('normal', 'NORMAL'); ?></option>
                                            <option value="ROYAL"><?php echo t('royal', 'ROYAL'); ?></option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="tent_${i}_beds"><?php echo t('bed_configuration', 'Bed Configuration'); ?></label>
                                        <select id="tent_${i}_beds" name="tent_${i}_beds" onchange="updateTentSpecifications()">
                                            <option value=""><?php echo t('select_beds', 'Select Beds'); ?></option>
                                            <option value="single"><?php echo t('single_bed', 'Single Bed'); ?></option>
                                            <option value="double"><?php echo t('double_bed', 'Double Bed'); ?></option>
                                            <option value="triple"><?php echo t('triple_bed', 'Triple Bed'); ?></option>
                                            <option value="quadruple"><?php echo t('quadruple_bed', 'Quadruple Bed'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group" id="tent_${i}_number_group"></div>
                            </div>
                            
                            <div class="boarding-section">
                                <h5><?= t('boarding_options', 'Boarding Options') ?></h5>
                                <div class="boarding-options">
                                    <div class="boarding-option">
                                        <label class="boarding-checkbox">
                                            <input type="checkbox" id="tent_${i}_half_board" name="tent_${i}_half_board" onchange="toggleBoardingDays(${i}, 'half_board')">
                                            <span class="checkmark"></span>
                                            <span class="boarding-label"><?= t('half_board', 'Half Board') ?></span>
                                        </label>
                                        <div id="tent_${i}_half_board_days_group" class="boarding-days-group" style="display: none;">
                                            <label for="tent_${i}_half_board_days"><?= t('nights', 'Nights') ?></label>
                                            <input type="number" id="tent_${i}_half_board_days" name="tent_${i}_half_board_days" min="0" value="0" onchange="updateTentSpecifications()">
                                        </div>
                                    </div>
                                    <div class="boarding-option">
                                        <label class="boarding-checkbox">
                                            <input type="checkbox" id="tent_${i}_full_board" name="tent_${i}_full_board" onchange="toggleBoardingDays(${i}, 'full_board')">
                                            <span class="checkmark"></span>
                                            <span class="boarding-label"><?= t('full_board', 'Full Board') ?></span>
                                        </label>
                                        <div id="tent_${i}_full_board_days_group" class="boarding-days-group" style="display: none;">
                                            <label for="tent_${i}_full_board_days"><?= t('nights', 'Nights') ?></label>
                                            <input type="number" id="tent_${i}_full_board_days" name="tent_${i}_full_board_days" min="0" value="0" onchange="updateTentSpecifications()">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="kids-section">
                                <h5><?= t('kids_options', 'Kids Options') ?></h5>
                                <div class="kids-options">
                                    <div class="kids-option">
                                        <label class="kids-checkbox">
                                            <input type="checkbox" id="tent_${i}_has_kids" name="tent_${i}_has_kids" onchange="toggleKidsNumber(${i})">
                                            <span class="checkmark"></span>
                                            <span class="kids-label"><?= t('has_kids', 'Has Kids') ?></span>
                                        </label>
                                        <div id="tent_${i}_kids_number_group" class="kids-number-group" style="display: none;">
                                            <label for="tent_${i}_kids_number"><?= t('kids_number', 'Number of Kids') ?></label>
                                            <input type="number" id="tent_${i}_kids_number" name="tent_${i}_kids_number" min="1" value="1" onchange="updateTentSpecifications()">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(tentOption);
            }
            populateTentOptionsFromSpecs();
            updateTentSpecifications();
            updateMixedTentAllocationVisibility();
            
            // Note: fetchAvailableTentNumbers will be called when user selects a tent type
            // or when populateTentOptionsFromSpecs populates existing data
        }
        
        function fetchAvailableTentNumbers(tentIndex, assignedTentId = null) {
            console.log(`fetchAvailableTentNumbers called for tent ${tentIndex}`);
            
            const type = document.getElementById(`tent_${tentIndex}_type`);
            const check_in = document.getElementById('check_in_date');
            const check_out = document.getElementById('check_out_date');
            
            if (!type || !check_in || !check_out) {
                console.log('Missing required elements:', { type: !!type, check_in: !!check_in, check_out: !!check_out });
                document.getElementById(`tent_${tentIndex}_number_group`).innerHTML = '';
                updateTentSpecifications();
                return;
            }
            
            const typeValue = type.value;
            const checkInValue = check_in.value;
            const checkOutValue = check_out.value;
            
            if (!typeValue || !checkInValue || !checkOutValue) {
                console.log('Missing required values:', { type: typeValue, check_in: checkInValue, check_out: checkOutValue });
                document.getElementById(`tent_${tentIndex}_number_group`).innerHTML = '';
                updateTentSpecifications();
                return;
            }
            
            // Get the currently assigned tent number from tent specifications
            const tentSpecs = document.getElementById('tent_specifications').value;
            let currentlyAssignedTentNumber = null;
            if (tentSpecs) {
                const specsArr = tentSpecs.split(', ');
                const currentTentSpec = specsArr.find(spec => spec.startsWith(`Tent ${tentIndex}:`));
                if (currentTentSpec) {
                    const tentNumberMatch = currentTentSpec.match(/Tent #(\d+)/);
                    if (tentNumberMatch) {
                        currentlyAssignedTentNumber = tentNumberMatch[1];
                        console.log(`Tent ${tentIndex} is currently assigned to tent number: ${currentlyAssignedTentNumber}`);
                    }
                }
            }
            
            console.log(`Fetching tents for type: ${typeValue}, dates: ${checkInValue} to ${checkOutValue}`);
            console.log(`Currently assigned tent number: ${currentlyAssignedTentNumber}`);
            
            // Fetch available tents based on tent type and period
            fetch(`add_reservation.php?fetch_tents=1&tent_type=${typeValue}&start_date=${checkInValue}&end_date=${checkOutValue}&edit_reservation_id=<?php echo $id; ?>`)
                .then(res => {
                    console.log('Response received:', res);
                    return res.json();
                })
                .then(data => {
                    console.log('Tent data received:', data);
                    
                    let html = '<label><?= t('Tent Number', 'Tent Number') ?></label>';
                    html += '<select id="tent_' + tentIndex + '_number" name="tent_numbers[]" onchange="updateTentSpecifications()">';
                    html += '<option value=""><?= t('Select Tent', 'Select Tent') ?></option>';
                    
                    let foundAssignedTent = false;
                    
                    if (data.tents && data.tents.length > 0) {
                        console.log(`Found ${data.tents.length} available tents`);
                        console.log(`Tent data sample:`, data.tents.slice(0, 3)); // Show first 3 tents
                        data.tents.forEach((tent, index) => {
                            let selected = '';
                            // Check if this tent matches the currently assigned tent number
                            if (currentlyAssignedTentNumber && tent.tent_number == currentlyAssignedTentNumber) {
                                selected = ' selected';
                                foundAssignedTent = true;
                                console.log(`Marking tent ${tent.tent_number} as selected (currently assigned)`);
                            }
                            html += `<option value="${tent.id}"${selected}>${tent.tent_number}</option>`;
                            if (index < 3) console.log(`Generated option ${index}:`, `<option value="${tent.id}"${selected}>${tent.tent_number}</option>`);
                        });
                    } else {
                        console.log('No tents available');
                        html += '<option value=""><?= t('No tents available', 'No tents available') ?></option>';
                    }
                    
                    // If the currently assigned tent is not in the available list, add it as selected but disabled
                    if (currentlyAssignedTentNumber && !foundAssignedTent) {
                        console.log(`Currently assigned tent ${currentlyAssignedTentNumber} not in available list, adding as disabled option`);
                        html += `<option value="" selected disabled>${currentlyAssignedTentNumber} (Currently Assigned - Not Available)</option>`;
                    }
                    
                    html += '</select>';
                    
                    const tentNumberGroup = document.getElementById(`tent_${tentIndex}_number_group`);
                    if (tentNumberGroup) {
                        tentNumberGroup.innerHTML = html;
                        // Remove temporary debugging styles
                        tentNumberGroup.style.border = '';
                        tentNumberGroup.style.backgroundColor = '';
                        tentNumberGroup.style.padding = '';
                        tentNumberGroup.style.margin = '';
                        tentNumberGroup.style.position = '';
                        tentNumberGroup.style.zIndex = '';
                        
                        console.log(`Tent number group updated for tent ${tentIndex}`);
                        console.log(`HTML generated:`, html);
                        console.log(`Element exists:`, !!tentNumberGroup);
                        console.log(`Element visible:`, tentNumberGroup.offsetHeight > 0);
                        console.log(`Element content:`, tentNumberGroup.innerHTML);
                    } else {
                        console.error(`Tent number group not found for tent ${tentIndex}`);
                    }
                    
                    updateTentSpecifications();
                })
                .catch(error => {
                    console.error('Error fetching available tents:', error);
                    document.getElementById(`tent_${tentIndex}_number_group`).innerHTML = '<div style="color: red;">Error loading tents</div>';
                });
        }
        
        function updateTentSpecifications() {
            const container = document.getElementById('tent_options_container');
            const tentOptions = container.querySelectorAll('.tent-option');
            let specs = [];
            
            tentOptions.forEach((tentOption, index) => {
                const tentNumber = index + 1;
                const type = tentOption.querySelector(`#tent_${tentNumber}_type`)?.value;
                const beds = tentOption.querySelector(`#tent_${tentNumber}_beds`)?.value;
                const tentNumberSelect = tentOption.querySelector(`#tent_${tentNumber}_number`);
                let tentNumberText = '';
                if (tentNumberSelect && tentNumberSelect.value) {
                    // Get the tent number from the option text, handling "Unavailable" cases
                    let tentNumberDisplay = tentNumberSelect.options[tentNumberSelect.selectedIndex].text;
                    // Remove "(Unavailable)" if present
                    tentNumberDisplay = tentNumberDisplay.replace(/\s*\([^)]*\)$/, '');
                    tentNumberText = ` (Tent #${tentNumberDisplay})`;
                }
                
                // Add boarding information
                let boardingText = '';
                const halfBoardCheckbox = tentOption.querySelector(`#tent_${tentNumber}_half_board`);
                const fullBoardCheckbox = tentOption.querySelector(`#tent_${tentNumber}_full_board`);
                
                if (halfBoardCheckbox && halfBoardCheckbox.checked) {
                    const halfBoardDaysInput = tentOption.querySelector(`#tent_${tentNumber}_half_board_days`);
                    let halfBoardDays = halfBoardDaysInput ? parseInt(halfBoardDaysInput.value) || 0 : 0;
                    // Ensure minimum value of 1 if checkbox is checked
                    if (halfBoardDays <= 0) {
                        halfBoardDays = 1;
                        if (halfBoardDaysInput) {
                            halfBoardDaysInput.value = 1;
                        }
                    }
                    // Ensure the value is properly set in the input
                    if (halfBoardDaysInput) {
                        halfBoardDaysInput.value = halfBoardDays;
                    }
                    boardingText += ` [<?= t('half_board', 'Half Board') ?>: ${halfBoardDays} <?= t('days', 'days') ?>]`;
                }
                
                if (fullBoardCheckbox && fullBoardCheckbox.checked) {
                    const fullBoardDaysInput = tentOption.querySelector(`#tent_${tentNumber}_full_board_days`);
                    let fullBoardDays = fullBoardDaysInput ? parseInt(fullBoardDaysInput.value) || 0 : 0;
                    // Ensure minimum value of 1 if checkbox is checked
                    if (fullBoardDays <= 0) {
                        fullBoardDays = 1;
                        if (fullBoardDaysInput) {
                            fullBoardDaysInput.value = 1;
                        }
                    }
                    // Ensure the value is properly set in the input
                    if (fullBoardDaysInput) {
                        fullBoardDaysInput.value = fullBoardDays;
                    }
                    boardingText += ` [<?= t('full_board', 'Full Board') ?>: ${fullBoardDays} <?= t('days', 'days') ?>]`;
                }
                
                // Add kids information
                let kidsText = '';
                const hasKidsCheckbox = tentOption.querySelector(`#tent_${tentNumber}_has_kids`);
                
                if (hasKidsCheckbox && hasKidsCheckbox.checked) {
                    const kidsNumber = tentOption.querySelector(`#tent_${tentNumber}_kids_number`).value;
                    kidsText += ` [Kids: ${kidsNumber}]`;
                }
                
                if (type && beds) {
                    specs.push(`Tent ${tentNumber}: ${type} - ${beds}${tentNumberText}${boardingText}${kidsText}`);
                }
            });
            document.getElementById('tent_specifications').value = specs.join(', ');
            
            // Update boarding_information column
            updateBoardingInformation();
            
            updateMixedTentAllocationVisibility();
        }
        
        function populateTentOptionsFromSpecs() {
            console.log('populateTentOptionsFromSpecs called');
            const tentSpecs = document.getElementById('tent_specifications').value;
            console.log('Tent specs found:', tentSpecs);
            if (!tentSpecs) {
                console.log('No tent specs, returning early');
                return;
            }
            const specsArr = tentSpecs.split(', ');
            console.log('Parsed specs array:', specsArr);
            
            specsArr.forEach((spec, idx) => {
                console.log(`Processing spec ${idx}:`, spec);
                // Updated regex to capture boarding information
                const match = spec.match(/Tent (\d+): (\w+) - (\w+)(?:\s*\(Tent #(\d+)\))?(.*)/);
                if (match) {
                    console.log('Spec matched:', match);
                    const tentNum = parseInt(match[1]);
                    const type = match[2];
                    const beds = match[3];
                    const tentDisplayNumber = match[4]; // The tent number from the specification
                    const boardingInfo = match[5] || ''; // The boarding information part
                    
                    console.log(`Setting tent ${tentNum}: type=${type}, beds=${beds}`);
                    
                    // Set the type and beds
                    const typeSelect = document.getElementById(`tent_${tentNum}_type`);
                    const bedsSelect = document.getElementById(`tent_${tentNum}_beds`);
                    if (typeSelect) {
                        typeSelect.value = type;
                        console.log(`Set tent ${tentNum} type to ${type}`);
                    } else {
                        console.error(`Type select not found for tent ${tentNum}`);
                    }
                    if (bedsSelect) {
                        bedsSelect.value = beds;
                        console.log(`Set tent ${tentNum} beds to ${beds}`);
                    } else {
                        console.error(`Beds select not found for tent ${tentNum}`);
                    }
                    
                    // Parse and populate boarding options
                    if (boardingInfo) {
                        // Parse Half Board - try both English and French
                        const halfBoardMatch = boardingInfo.match(/\[(?:Half Board|Demi-pension): (\d+) (?:days|jours)\]/);
                        if (halfBoardMatch) {
                            const halfBoardCheckbox = document.getElementById(`tent_${tentNum}_half_board`);
                            const halfBoardDaysInput = document.getElementById(`tent_${tentNum}_half_board_days`);
                            const halfBoardDaysGroup = document.getElementById(`tent_${tentNum}_half_board_days_group`);
                            
                            if (halfBoardCheckbox && halfBoardDaysInput) {
                                halfBoardCheckbox.checked = true;
                                halfBoardDaysInput.value = halfBoardMatch[1];
                                if (halfBoardDaysGroup) {
                                    halfBoardDaysGroup.style.display = 'block';
                                }
                            }
                        }
                        
                        // Parse Full Board - try both English and French
                        const fullBoardMatch = boardingInfo.match(/\[(?:Full Board|Pension complète): (\d+) (?:days|jours)\]/);
                        if (fullBoardMatch) {
                            const fullBoardCheckbox = document.getElementById(`tent_${tentNum}_full_board`);
                            const fullBoardDaysInput = document.getElementById(`tent_${tentNum}_full_board_days`);
                            const fullBoardDaysGroup = document.getElementById(`tent_${tentNum}_full_board_days_group`);
                            
                            if (fullBoardCheckbox && fullBoardDaysInput) {
                                fullBoardCheckbox.checked = true;
                                fullBoardDaysInput.value = fullBoardMatch[1];
                                if (fullBoardDaysGroup) {
                                    fullBoardDaysGroup.style.display = 'block';
                                }
                            }
                        }
                        
                        // Parse Kids information
                        const kidsMatch = boardingInfo.match(/\[Kids: (\d+)\]/);
                        if (kidsMatch) {
                            const hasKidsCheckbox = document.getElementById(`tent_${tentNum}_has_kids`);
                            const kidsNumberInput = document.getElementById(`tent_${tentNum}_kids_number`);
                            const kidsNumberGroup = document.getElementById(`tent_${tentNum}_kids_number_group`);
                            
                            if (hasKidsCheckbox && kidsNumberInput) {
                                hasKidsCheckbox.checked = true;
                                kidsNumberInput.value = kidsMatch[1];
                                if (kidsNumberGroup) {
                                    kidsNumberGroup.style.display = 'block';
                                }
                            }
                        }
                    }
                    
                    // Fetch available tent numbers for this tent
                    console.log(`Calling fetchAvailableTentNumbers for tent ${tentNum} from populateTentOptionsFromSpecs`);
                    fetchAvailableTentNumbers(tentNum);
                }
            });
            
            // Also populate boarding information from database if available
            populateBoardingFromDatabase();
            
            // Add event listeners to boarding days inputs to ensure they update specifications
            const container = document.getElementById('tent_options_container');
            const tentOptions = container.querySelectorAll('.tent-option');
            
            tentOptions.forEach((tentOption, index) => {
                const tentNumber = index + 1;
                const halfBoardDaysInput = tentOption.querySelector(`#tent_${tentNumber}_half_board_days`);
                const fullBoardDaysInput = tentOption.querySelector(`#tent_${tentNumber}_full_board_days`);
                
                if (halfBoardDaysInput) {
                    halfBoardDaysInput.addEventListener('change', updateTentSpecifications);
                }
                if (fullBoardDaysInput) {
                    fullBoardDaysInput.addEventListener('change', updateTentSpecifications);
                }
            });
        }
        
        function populateBoardingFromDatabase() {
            // Get boarding information from PHP variable
            const boardingInfo = <?php echo json_encode($reservation['boarding_information'] ?? null); ?>;
            if (!boardingInfo) return;
            
            try {
                const boardingData = JSON.parse(boardingInfo);
                if (boardingData.tents && Array.isArray(boardingData.tents)) {
                    boardingData.tents.forEach(boarding => {
                        const tentNum = boarding.tent_number;
                        
                        // Set Half Board
                        if (boarding.half_board_days > 0) {
                            const halfBoardCheckbox = document.getElementById(`tent_${tentNum}_half_board`);
                            const halfBoardDaysInput = document.getElementById(`tent_${tentNum}_half_board_days`);
                            const halfBoardDaysGroup = document.getElementById(`tent_${tentNum}_half_board_days_group`);
                            
                            if (halfBoardCheckbox && halfBoardDaysInput) {
                                halfBoardCheckbox.checked = true;
                                halfBoardDaysInput.value = boarding.half_board_days;
                                if (halfBoardDaysGroup) {
                                    halfBoardDaysGroup.style.display = 'block';
                                }
                                // Add event listener to ensure updates
                                halfBoardDaysInput.addEventListener('change', updateTentSpecifications);
                            }
                        }
                        
                        // Set Full Board
                        if (boarding.full_board_days > 0) {
                            const fullBoardCheckbox = document.getElementById(`tent_${tentNum}_full_board`);
                            const fullBoardDaysInput = document.getElementById(`tent_${tentNum}_full_board_days`);
                            const fullBoardDaysGroup = document.getElementById(`tent_${tentNum}_full_board_days_group`);
                            
                            if (fullBoardCheckbox && fullBoardDaysInput) {
                                fullBoardCheckbox.checked = true;
                                fullBoardDaysInput.value = boarding.full_board_days;
                                if (fullBoardDaysGroup) {
                                    fullBoardDaysGroup.style.display = 'block';
                                }
                                // Add event listener to ensure updates
                                fullBoardDaysInput.addEventListener('change', updateTentSpecifications);
                            }
                        }
                    });
                }
            } catch (e) {
                console.error('Error parsing boarding information:', e);
            }
        }
        
        function updateMixedTentAllocationVisibility() {
            let tentTypes = [];
            const container = document.getElementById('tent_options_container');
            const tentOptions = container.querySelectorAll('.tent-option');
            
            tentOptions.forEach((tentOption, index) => {
                const tentNumber = index + 1;
                const tentType = tentOption.querySelector(`#tent_${tentNumber}_type`);
                if (tentType && tentType.value) tentTypes.push(tentType.value);
            });
            
            const isMixed = tentTypes.includes('ROYAL') && tentTypes.includes('NORMAL');
            const mixedAlloc = document.getElementById('mixed-tent-allocation');
            if (mixedAlloc) mixedAlloc.style.display = isMixed ? '' : 'none';
        }
        
        function toggleBoardingDays(tentIndex, boardingType) {
            const checkbox = document.getElementById(`tent_${tentIndex}_${boardingType}`);
            const daysGroup = document.getElementById(`tent_${tentIndex}_${boardingType}_days_group`);
            const daysInput = document.getElementById(`tent_${tentIndex}_${boardingType}_days`);
            
            if (checkbox.checked) {
                daysGroup.style.display = 'block';
                // Set default days to reservation nights, but ensure minimum of 1
                const nights = parseInt(document.getElementById('nights').value) || 1;
                const defaultValue = Math.max(1, nights);
                daysInput.value = defaultValue;
                
                // Ensure the input has a valid value and trigger change event
                if (!daysInput.value || parseInt(daysInput.value) <= 0) {
                    daysInput.value = 1;
                }
                
                // Trigger change event to ensure the value is properly registered
                daysInput.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
                daysGroup.style.display = 'none';
                daysInput.value = 1;
            }
            updateTentSpecifications();
        }
        
        function toggleKidsNumber(tentIndex) {
            const checkbox = document.getElementById(`tent_${tentIndex}_has_kids`);
            const kidsGroup = document.getElementById(`tent_${tentIndex}_kids_number_group`);
            const kidsInput = document.getElementById(`tent_${tentIndex}_kids_number`);
            
            if (checkbox.checked) {
                kidsGroup.style.display = 'block';
                // Set default kids number to 1
                kidsInput.value = 1;
            } else {
                kidsGroup.style.display = 'none';
                kidsInput.value = 1;
            }
            updateTentSpecifications();
        }
        
        function fetchAvailableResources() {
            // Get period and numbers
            const check_in = document.getElementById('check_in_date').value;
            const check_out = document.getElementById('check_out_date').value;
            const car_start = document.getElementById('car_start_date').value || check_in;
            const car_end = document.getElementById('car_end_date').value || check_out;
            const driver_start = document.getElementById('driver_start_date').value || check_in;
            const driver_end = document.getElementById('driver_end_date').value || check_out;
            const guide_start = document.getElementById('guide_start_date').value || check_in;
            const guide_end = document.getElementById('guide_end_date').value || check_out;
            const num_cars = parseInt(document.getElementById('cars_4x4').value) || 0;
            const num_drivers = parseInt(document.getElementById('staff_drivers').value) || 0;
            const num_guides = parseInt(document.getElementById('staff_guides').value) || 0;

            if (!car_start || !car_end || !driver_start || !driver_end || !guide_start || !guide_end) {
                document.getElementById('resourceSelection').innerHTML = '<em>Please select all periods to see available resources.</em>';
                return;
            }

            // Pass edit_reservation_id to always include assigned resources
            fetch(`add_reservation.php?fetch_resources=1&car_start_date=${car_start}&car_end_date=${car_end}&driver_start_date=${driver_start}&driver_end_date=${driver_end}&guide_start_date=${guide_start}&guide_end_date=${guide_end}&check_in_date=${check_in}&check_out_date=${check_out}&edit_reservation_id=<?php echo $id; ?>`)
                .then(res => res.json())
                .then(data => {
                    let html = '';
                    // Cars
                    if (num_cars > 0) {
                        html += '<div class="form-group"><label><?= t('Select Cars', 'Select Cars') ?></label>';
                        for (let i = 0; i < num_cars; i++) {
                            html += `<select name="car_ids[]" required><option value=""><?= t('Select Car', 'Select Car') ?></option>`;
                            data.cars.forEach(car => {
                                html += `<option value="${car.id}"${car.disabled ? ' disabled' : ''}>${car.registration_number}${car.disabled ? ' (<?= t('Unavailable', 'Unavailable') ?>)' : ''}</option>`;
                            });
                            html += '</select> ';
                        }
                        html += '</div>';
                    }
                    // Drivers
                    if (num_drivers > 0) {
                        html += '<div class="form-group"><label><?= t('Select Drivers', 'Select Drivers') ?></label>';
                        for (let i = 0; i < num_drivers; i++) {
                            html += `<select name="driver_ids[]" required><option value=""><?= t('Select Driver', 'Select Driver') ?></option>`;
                            data.drivers.forEach(driver => {
                                html += `<option value="${driver.id}"${driver.disabled ? ' disabled' : ''}>${driver.full_name}${driver.disabled ? ' (<?= t('Unavailable', 'Unavailable') ?>)' : ''}</option>`;
                            });
                            html += '</select> ';
                        }
                        html += '</div>';
                    }
                    // Guides
                    if (num_guides > 0) {
                        html += '<div class="form-group"><label><?= t('Select Guides', 'Select Guides') ?></label>';
                        for (let i = 0; i < num_guides; i++) {
                            html += `<select name="guide_ids[]" required><option value=""><?= t('Select Guide', 'Select Guide') ?></option>`;
                            data.guides.forEach(guide => {
                                html += `<option value="${guide.id}"${guide.disabled ? ' disabled' : ''}>${guide.full_name}${guide.disabled ? ' (<?= t('Unavailable', 'Unavailable') ?>)' : ''}</option>`;
                            });
                            html += '</select> ';
                        }
                        html += '</div>';
                    }
                    if (!html) html = '<em>No resources needed or available for selection.</em>';
                    document.getElementById('resourceSelection').innerHTML = html;
                    // Pre-select assigned cars, drivers, guides after dropdowns are rendered
                    <?php if (!empty($assigned_car_ids)): ?>
                    let carSelects = document.querySelectorAll('select[name="car_ids[]"]');
                    let carIds = <?php echo json_encode(array_values($assigned_car_ids)); ?>;
                    carSelects.forEach(function(sel, idx) { if (carIds[idx]) sel.value = carIds[idx]; });
                    <?php endif; ?>
                    <?php if (!empty($assigned_driver_ids)): ?>
                    let driverSelects = document.querySelectorAll('select[name="driver_ids[]"]');
                    let driverIds = <?php echo json_encode(array_values($assigned_driver_ids)); ?>;
                    driverSelects.forEach(function(sel, idx) { if (driverIds[idx]) sel.value = driverIds[idx]; });
                    <?php endif; ?>
                    <?php if (!empty($assigned_guide_ids)): ?>
                    let guideSelects = document.querySelectorAll('select[name="guide_ids[]"]');
                    let guideIds = <?php echo json_encode(array_values($assigned_guide_ids)); ?>;
                    guideSelects.forEach(function(sel, idx) { if (guideIds[idx]) sel.value = guideIds[idx]; });
                    <?php endif; ?>
                });
        }
        function toggleCarFields() {
            const num = parseInt(document.getElementById('cars_4x4').value) || 0;
            document.getElementById('car_period_group').style.display = num > 0 ? '' : 'none';
            document.getElementById('car_days_group').style.display = num > 0 ? '' : 'none';
            updateCarDays();
        }
        function toggleDriverFields() {
            const num = parseInt(document.getElementById('staff_drivers').value) || 0;
            document.getElementById('driver_period_group').style.display = num > 0 ? '' : 'none';
            document.getElementById('driver_days_group').style.display = num > 0 ? '' : 'none';
            updateDriverDays();
        }
        function toggleGuideFields() {
            const num = parseInt(document.getElementById('staff_guides').value) || 0;
            document.getElementById('guide_period_group').style.display = num > 0 ? '' : 'none';
            document.getElementById('guide_days_group').style.display = num > 0 ? '' : 'none';
            updateGuideDays();
        }
        function calcDays(start, end) {
            if (!start || !end) return 0;
            const d1 = new Date(start);
            const d2 = new Date(end);
            return Math.max(0, Math.ceil((d2 - d1) / (1000 * 60 * 60 * 24)) + 1);
        }
        function updateCarDays() {
            const start = document.getElementById('check_in_date').value;
            const end = document.getElementById('check_out_date').value;
            document.getElementById('car_days').value = calcDays(start, end);
        }
        function updateDriverDays() {
            const start = document.getElementById('check_in_date').value;
            const end = document.getElementById('check_out_date').value;
            document.getElementById('driver_days').value = calcDays(start, end);
        }
        function updateGuideDays() {
            const start = document.getElementById('check_in_date').value;
            const end = document.getElementById('check_out_date').value;
            document.getElementById('guide_days').value = calcDays(start, end);
        }
        function renderCarSelectors() {
            const num = parseInt(document.getElementById('cars_4x4').value) || 0;
            const group = document.getElementById('car_selectors_group');
            group.innerHTML = '';
            
            if (num > 0) {
                group.innerHTML = `
                    <div class="resource-section">
                        <h4><?= t('🚗 Car Selection', '🚗 Car Selection') ?></h4>
                        <div class="resource-grid">
                `;
                
                for (let i = 0; i < num; i++) {
                    const assignedCars = window.assignedCarData || [];
                    const startVal = assignedCars[i] ? assignedCars[i].start_date : document.getElementById('check_in_date').value;
                    const endVal = assignedCars[i] ? assignedCars[i].end_date : document.getElementById('check_out_date').value;
                    
                    group.innerHTML += `
                        <div class="resource-item">
                            <div class="resource-header">
                                <h5><?= t('Car', 'Car') ?> ${i + 1}</h5>
                            </div>
                            <div class="period-section">
                                <label><?= t('Usage Period:', 'Usage Period:') ?></label>
                                <div class="date-inputs">
                                    <input type="date" name="car_start_dates[]" class="period-start" data-resource="car" data-index="${i}" value="${startVal}" onchange="updateResourcePeriod('car', ${i})">
                                    <span><?= t('to', 'to') ?></span>
                                    <input type="date" name="car_end_dates[]" class="period-end" data-resource="car" data-index="${i}" value="${endVal}" onchange="updateResourcePeriod('car', ${i})">
                                </div>
                            </div>
                            <div class="resource-selector">
                                <label><?= t('Select Car:', 'Select Car:') ?></label>
                                <select name="car_ids[]" class="resource-select" data-resource="car" data-index="${i}" required>
                                    <option value=""><?= t('Select Car', 'Select Car') ?></option>
                                </select>
                            </div>
                        </div>
                    `;
                }
                
                group.innerHTML += `
                        </div>
                    </div>
                `;
                
                // Fetch and populate car options for each car
                for (let i = 0; i < num; i++) {
                    fetchAvailableCars(i);
                }
            }
        }
        
        function renderDriverSelectors() {
            const num = parseInt(document.getElementById('staff_drivers').value) || 0;
            const group = document.getElementById('driver_selectors_group');
            group.innerHTML = '';
            
            if (num > 0) {
                group.innerHTML = `
                    <div class="resource-section">
                        <h4><?= t('👨‍💼 Driver Selection', '👨‍💼 Driver Selection') ?></h4>
                        <div class="resource-grid">
                `;
                
                for (let i = 0; i < num; i++) {
                    const assignedDrivers = window.assignedDriverData || [];
                    const startVal = assignedDrivers[i] ? assignedDrivers[i].start_date : document.getElementById('check_in_date').value;
                    const endVal = assignedDrivers[i] ? assignedDrivers[i].end_date : document.getElementById('check_out_date').value;
                    
                    group.innerHTML += `
                        <div class="resource-item">
                            <div class="resource-header">
                                <h5><?= t('Driver', 'Driver') ?> ${i + 1}</h5>
                            </div>
                            <div class="period-section">
                                <label><?= t('Usage Period:', 'Usage Period:') ?></label>
                                <div class="date-inputs">
                                    <input type="date" name="driver_start_dates[]" class="period-start" data-resource="driver" data-index="${i}" value="${startVal}" onchange="updateResourcePeriod('driver', ${i})">
                                    <span><?= t('to', 'to') ?></span>
                                    <input type="date" name="driver_end_dates[]" class="period-end" data-resource="driver" data-index="${i}" value="${endVal}" onchange="updateResourcePeriod('driver', ${i})">
                                </div>
                            </div>
                            
                            <div class="resource-selector">
                                <label><?= t('Select Driver:', 'Select Driver:') ?></label>
                                <select name="driver_ids[]" class="resource-select" data-resource="driver" data-index="${i}" required>
                                    <option value=""><?= t('Select Driver', 'Select Driver') ?></option>
                                </select>
                            </div>
                        </div>
                    `;
                }
                
                group.innerHTML += `
                        </div>
                    </div>
                `;
                
                // Fetch and populate driver options for each driver
                for (let i = 0; i < num; i++) {
                    fetchAvailableDrivers(i);
                }
            }
        }
        
        function renderGuideSelectors() {
            const num = parseInt(document.getElementById('staff_guides').value) || 0;
            const group = document.getElementById('guide_selectors_group');
            group.innerHTML = '';
            
            if (num > 0) {
                group.innerHTML = `
                    <div class="resource-section">
                        <h4><?= t('🗺️ Guide Selection', '🗺️ Guide Selection') ?></h4>
                        <div class="resource-grid">
                `;
                
                for (let i = 0; i < num; i++) {
                    const assignedGuides = window.assignedGuideData || [];
                    const startVal = assignedGuides[i] ? assignedGuides[i].start_date : document.getElementById('check_in_date').value;
                    const endVal = assignedGuides[i] ? assignedGuides[i].end_date : document.getElementById('check_out_date').value;
                    
                    group.innerHTML += `
                        <div class="resource-item">
                            <div class="resource-header">
                                <h5><?= t('Guide', 'Guide') ?> ${i + 1}</h5>
                            </div>
                            <div class="period-section">
                                <label><?= t('Usage Period:', 'Usage Period:') ?></label>
                                <div class="date-inputs">
                                    <input type="date" name="guide_start_dates[]" class="period-start" data-resource="guide" data-index="${i}" value="${startVal}" onchange="updateResourcePeriod('guide', ${i})">
                                    <span><?= t('to', 'to') ?></span>
                                    <input type="date" name="guide_end_dates[]" class="period-end" data-resource="guide" data-index="${i}" value="${endVal}" onchange="updateResourcePeriod('guide', ${i})">
                                </div>
                            </div>
                            <div class="resource-selector">
                                <label><?= t('Select Guide:', 'Select Guide:') ?></label>
                                <select name="guide_ids[]" class="resource-select" data-resource="guide" data-index="${i}" required>
                                    <option value=""><?= t('Select Guide', 'Select Guide') ?></option>
                                </select>
                            </div>
                        </div>
                    `;
                }
                
                group.innerHTML += `
                        </div>
                    </div>
                `;
                
                // Fetch and populate guide options for each guide
                for (let i = 0; i < num; i++) {
                    fetchAvailableGuides(i);
                }
            }
        }
        
        function updateResourcePeriod(resourceType, index) {
            const startInput = document.querySelector(`input[data-resource="${resourceType}"][data-index="${index}"].period-start`);
            const endInput = document.querySelector(`input[data-resource="${resourceType}"][data-index="${index}"].period-end`);
            const select = document.querySelector(`select[data-resource="${resourceType}"][data-index="${index}"]`);
            
            const startDate = startInput.value;
            const endDate = endInput.value;
            
            if (startDate && endDate) {
                // Fetch available resources for this specific period
                if (resourceType === 'car') {
                    fetchAvailableCars(index, startDate, endDate);
                } else if (resourceType === 'driver') {
                    fetchAvailableDrivers(index, startDate, endDate);
                } else if (resourceType === 'guide') {
                    fetchAvailableGuides(index, startDate, endDate);
                }
            } else {
                // Clear the select if dates are not complete
                select.innerHTML = '<option value="">Select ' + resourceType.charAt(0).toUpperCase() + resourceType.slice(1) + '</option>';
            }
        }
        
        function fetchAvailableCars(index, startDate = null, endDate = null) {
            const start = startDate || document.querySelector(`input[data-resource="car"][data-index="${index}"].period-start`).value;
            const end = endDate || document.querySelector(`input[data-resource="car"][data-index="${index}"].period-end`).value;
            
            if (!start || !end) return;
            
            fetch(`add_reservation.php?fetch_resources=1&car_start_date=${start}&car_end_date=${end}&edit_reservation_id=<?php echo $id; ?>`)
                .then(res => res.json())
                .then(data => {
                    const select = document.querySelector(`select[data-resource="car"][data-index="${index}"]`);
                    select.innerHTML = '<option value=""><?= t('Select Car', 'Select Car') ?></option>';
                    
                    if (data.cars) {
                        data.cars.forEach(car => {
                            const option = document.createElement('option');
                            option.value = car.id;
                            option.textContent = car.registration_number;
                            if (car.disabled) {
                                option.disabled = true;
                                option.textContent += ' (<?= t('Unavailable', 'Unavailable') ?>)';
                            }
                            select.appendChild(option);
                        });
                    }
                    
                    // Pre-select if assigned
                    <?php if (!empty($assigned_car_ids)): ?>
                    const assignedCarIds = <?php echo json_encode(array_values($assigned_car_ids)); ?>;
                    if (assignedCarIds[index]) {
                        select.value = assignedCarIds[index];
                    }
                    <?php endif; ?>
                })
                .catch(error => {
                    console.error('Error fetching cars:', error);
                });
        }
        
        function fetchAvailableDrivers(index, startDate = null, endDate = null) {
            const start = startDate || document.querySelector(`input[data-resource="driver"][data-index="${index}"].period-start`).value;
            const end = endDate || document.querySelector(`input[data-resource="driver"][data-index="${index}"].period-end`).value;
            
            if (!start || !end) return;
            
            fetch(`add_reservation.php?fetch_resources=1&driver_start_date=${start}&driver_end_date=${end}&edit_reservation_id=<?php echo $id; ?>`)
                .then(res => res.json())
                .then(data => {
                    const select = document.querySelector(`select[data-resource="driver"][data-index="${index}"]`);
                    select.innerHTML = '<option value=""><?= t('Select Driver', 'Select Driver') ?></option>';
                    
                    if (data.drivers) {
                        data.drivers.forEach(driver => {
                            const option = document.createElement('option');
                            option.value = driver.id;
                            option.textContent = driver.full_name;
                            if (driver.disabled) {
                                option.disabled = true;
                                option.textContent += ' (<?= t('Unavailable', 'Unavailable') ?>)';
                            }
                            select.appendChild(option);
                        });
                    }
                    
                    // Pre-select if assigned
                    <?php if (!empty($assigned_driver_ids)): ?>
                    const assignedDriverIds = <?php echo json_encode(array_values($assigned_driver_ids)); ?>;
                    if (assignedDriverIds[index]) {
                        select.value = assignedDriverIds[index];
                    }
                    <?php endif; ?>
                })
                .catch(error => {
                    console.error('Error fetching drivers:', error);
                });
        }
        
        function fetchAvailableGuides(index, startDate = null, endDate = null) {
            const start = startDate || document.querySelector(`input[data-resource="guide"][data-index="${index}"].period-start`).value;
            const end = endDate || document.querySelector(`input[data-resource="guide"][data-index="${index}"].period-end`).value;
            
            if (!start || !end) return;
            
            fetch(`add_reservation.php?fetch_resources=1&guide_start_date=${start}&guide_end_date=${end}&edit_reservation_id=<?php echo $id; ?>`)
                .then(res => res.json())
                .then(data => {
                    const select = document.querySelector(`select[data-resource="guide"][data-index="${index}"]`);
                    select.innerHTML = '<option value=""><?= t('Select Guide', 'Select Guide') ?></option>';
                    
                    if (data.guides) {
                        data.guides.forEach(guide => {
                            const option = document.createElement('option');
                            option.value = guide.id;
                            option.textContent = guide.full_name;
                            if (guide.disabled) {
                                option.disabled = true;
                                option.textContent += ' (<?= t('Unavailable', 'Unavailable') ?>)';
                            }
                            select.appendChild(option);
                        });
                    }
                    
                    // Pre-select if assigned
                    <?php if (!empty($assigned_guide_ids)): ?>
                    const assignedGuideIds = <?php echo json_encode(array_values($assigned_guide_ids)); ?>;
                    if (assignedGuideIds[index]) {
                        select.value = assignedGuideIds[index];
                    }
                    <?php endif; ?>
                })
                .catch(error => {
                    console.error('Error fetching guides:', error);
                });
        }
        document.addEventListener('DOMContentLoaded', function() {
            // Prepare assigned resource data for pre-fill
            window.assignedCarData = <?php echo json_encode($pdo->query("SELECT car_id, start_date, end_date FROM reservation_cars WHERE reservation_id = $id")->fetchAll(PDO::FETCH_ASSOC)); ?>;
            window.assignedDriverData = <?php echo json_encode($pdo->query("SELECT human_id, start_date, end_date FROM reservation_humans WHERE reservation_id = $id AND role = 'driver'")->fetchAll(PDO::FETCH_ASSOC)); ?>;
            window.assignedGuideData = <?php echo json_encode($pdo->query("SELECT human_id, start_date, end_date FROM reservation_humans WHERE reservation_id = $id AND role = 'guide'")->fetchAll(PDO::FETCH_ASSOC)); ?>;
            renderCarSelectors();
            renderDriverSelectors();
            renderGuideSelectors();
            updateTentOptions(); // Initialize tent options
            toggleAgencyField(); // Initialize agency field visibility
            toggleDiscountFields(); // Initialize discount fields visibility
            updateMixedTentAllocationVisibility(); // Initialize mixed tent allocation visibility
            
            // Force period fields to appear by triggering change events
            document.getElementById('cars_4x4').dispatchEvent(new Event('change'));
            document.getElementById('staff_drivers').dispatchEvent(new Event('change'));
            document.getElementById('staff_guides').dispatchEvent(new Event('change'));
            setTimeout(function() {
                // Pre-select assigned cars, drivers, guides, tents
                <?php if (!empty($assigned_car_ids)): ?>
                let carSelects = document.querySelectorAll('select[name="car_ids[]"]');
                let carIds = <?php echo json_encode(array_values($assigned_car_ids)); ?>;
                carSelects.forEach(function(sel, idx) { if (carIds[idx]) sel.value = carIds[idx]; });
                <?php endif; ?>
                <?php if (!empty($assigned_driver_ids)): ?>
                let driverSelects = document.querySelectorAll('select[name="driver_ids[]"]');
                let driverIds = <?php echo json_encode(array_values($assigned_driver_ids)); ?>;
                driverSelects.forEach(function(sel, idx) { if (driverIds[idx]) sel.value = driverIds[idx]; });
                <?php endif; ?>
                <?php if (!empty($assigned_guide_ids)): ?>
                let guideSelects = document.querySelectorAll('select[name="guide_ids[]"]');
                let guideIds = <?php echo json_encode(array_values($assigned_guide_ids)); ?>;
                guideSelects.forEach(function(sel, idx) { if (guideIds[idx]) sel.value = guideIds[idx]; });
                <?php endif; ?>
                // Tent selection is handled by populateTentOptionsFromSpecs() function
                // which is called after updateTentOptions()
            }, 1200);
        });
        
        function toggleAgencyField() {
            const reservationSource = document.getElementById('reservation_source').value;
            const agencyNameGroup = document.getElementById('agency_name_group');
            const agencyNameInput = document.getElementById('agency_name');
            
            if (reservationSource === 'agency') {
                agencyNameGroup.style.display = 'block';
                agencyNameInput.required = true;
            } else {
                agencyNameGroup.style.display = 'none';
                agencyNameInput.required = false;
                agencyNameInput.value = '';
            }
        }

        function toggleDiscountFields() {
            const discountType = document.getElementById('discount_type').value;
            const percentGroup = document.getElementById('discount_percent_group');
            const amountGroup = document.getElementById('discount_amount_group');
            
            if (discountType === 'percent') {
                percentGroup.style.display = 'block';
                amountGroup.style.display = 'none';
            } else if (discountType === 'amount') {
                percentGroup.style.display = 'none';
                amountGroup.style.display = 'block';
            } else {
                percentGroup.style.display = 'none';
                amountGroup.style.display = 'none';
            }
        }
        
        function toggleConfirmationWay() {
            const confirmation = document.getElementById('confirmation').checked;
            const confirmationWayGroup = document.getElementById('confirmation_way_group');
            const confirmationWayInput = document.getElementById('confirmation_way');
            
            if (confirmation) {
                confirmationWayGroup.style.display = 'block';
            } else {
                confirmationWayGroup.style.display = 'none';
                confirmationWayInput.value = '';
            }
        }
        
        let exceptionCounter = <?php echo count($existing_exceptions); ?>;
        
        function addException() {
            exceptionCounter++;
            const container = document.getElementById('exceptions_container');
            const exceptionDiv = document.createElement('div');
            exceptionDiv.className = 'exception-item';
            exceptionDiv.id = 'exception_' + exceptionCounter;
            
            exceptionDiv.innerHTML = `
                <button type="button" class="remove-exception" onclick="removeException(${exceptionCounter})">×</button>
                <h4><?= t('exception_guest', 'Exception Guest') ?> ${exceptionCounter}</h4>
                <div class="exception-fields">
                    <div class="form-group">
                        <label for="exception_guest_name_${exceptionCounter}"><?= t('guest_name', 'Guest Name') ?></label>
                        <input type="text" id="exception_guest_name_${exceptionCounter}" name="exceptions[${exceptionCounter}][guest_name]" required>
                    </div>
                    <div class="form-group">
                        <label for="exception_type_${exceptionCounter}"><?= t('exception_type', 'Exception Type') ?></label>
                        <select id="exception_type_${exceptionCounter}" name="exceptions[${exceptionCounter}][exception_type]" required>
                            <option value=""><?= t('select_type', 'Select Type') ?></option>
                            <option value="driver"><?= t('driver', 'Driver') ?></option>
                            <option value="guide"><?= t('guide', 'Guide') ?></option>
                            <option value="company"><?= t('company', 'Company') ?></option>
                            <option value="other"><?= t('other', 'Other') ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="exception_tent_${exceptionCounter}"><?= t('assigned_tent', 'Assigned Tent') ?></label>
                        <select id="exception_tent_${exceptionCounter}" name="exceptions[${exceptionCounter}][assigned_tent_id]" required>
                            <option value=""><?= t('select_tent', 'Select Tent') ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="exception_price_${exceptionCounter}"><?= t('price_per_night', 'Price per Night') ?> (TND)</label>
                        <input type="number" id="exception_price_${exceptionCounter}" name="exceptions[${exceptionCounter}][price_per_night]" value="0" min="0" step="0.01" onchange="toggleExceptionFree(${exceptionCounter})">
                    </div>
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="exception_free_${exceptionCounter}" name="exceptions[${exceptionCounter}][is_free]" value="1" onchange="toggleExceptionPrice(${exceptionCounter})">
                            <label for="exception_free_${exceptionCounter}"><?= t('is_free', 'Is Free') ?></label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="exception_notes_${exceptionCounter}"><?= t('exception_notes', 'Notes') ?></label>
                        <textarea id="exception_notes_${exceptionCounter}" name="exceptions[${exceptionCounter}][notes]" rows="2"></textarea>
                    </div>
                </div>
            `;
            
            container.appendChild(exceptionDiv);
            
            // Populate tent options for the new exception
            populateTentOptionsForException(exceptionCounter);
        }
        
        function populateTentOptionsForException(exceptionId) {
            const tentSelect = document.getElementById('exception_tent_' + exceptionId);
            if (!tentSelect) return;
            
            // Clear existing options except the first one
            tentSelect.innerHTML = '<option value=""><?= t('select_tent', 'Select Tent') ?></option>';
            
            // Fetch assigned tents from reservation_tents table
            fetch(`edit_reservation.php?fetch_assigned_tents=1&reservation_id=<?php echo $id; ?>`)
                .then(res => res.json())
                .then(data => {
                    if (data.tents && data.tents.length > 0) {
                        data.tents.forEach(tent => {
                            const option = document.createElement('option');
                            option.value = tent.id;
                            option.textContent = `Tent #${tent.tent_number} - ${tent.tent_type}`;
                            tentSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching assigned tents:', error);
                });
        }
        
        function removeException(id) {
            const exceptionDiv = document.getElementById('exception_' + id);
            if (exceptionDiv) {
                exceptionDiv.remove();
            }
        }
        
        function toggleExceptionFree(id) {
            const priceInput = document.getElementById('exception_price_' + id);
            const freeCheckbox = document.getElementById('exception_free_' + id);
            
            if (parseFloat(priceInput.value) === 0) {
                freeCheckbox.checked = true;
            } else {
                freeCheckbox.checked = false;
            }
        }
        
        function toggleExceptionPrice(id) {
            const priceInput = document.getElementById('exception_price_' + id);
            const freeCheckbox = document.getElementById('exception_free_' + id);
            
            if (freeCheckbox.checked) {
                priceInput.value = '0';
                priceInput.disabled = true;
            } else {
                priceInput.disabled = false;
            }
        }
        
        function removeTentOption(btn) {
            const card = btn.closest('.tent-option');
            if (!card) return;
            card.parentNode.removeChild(card);
            // Re-number remaining tent cards
            const container = document.getElementById('tent_options_container');
            const remaining = container.querySelectorAll('.tent-option');
            remaining.forEach((el, idx) => {
                const newTentNumber = idx + 1;
                el.setAttribute('data-tent', newTentNumber);
                
                // Update the tent header
                const h4 = el.querySelector('.tent-header h4');
                if (h4) h4.textContent = '<?= t('tent','Tent') ?> ' + newTentNumber;
                
                // Update all form element IDs and names
                const formElements = el.querySelectorAll('[id^="tent_"]');
                formElements.forEach(element => {
                    const oldId = element.id;
                    const newId = oldId.replace(/^tent_\d+/, `tent_${newTentNumber}`);
                    element.id = newId;
                    
                    // Update name attribute if it exists
                    if (element.name) {
                        element.name = element.name.replace(/^tent_\d+/, `tent_${newTentNumber}`);
                    }
                    
                    // Update for attribute in labels
                    if (element.tagName === 'SELECT' || element.tagName === 'INPUT') {
                        const label = el.querySelector(`label[for="${oldId}"]`);
                        if (label) {
                            label.setAttribute('for', newId);
                        }
                    }
                });
                
                // Update group IDs
                const groups = el.querySelectorAll('[id$="_group"]');
                groups.forEach(group => {
                    const oldGroupId = group.id;
                    const newGroupId = oldGroupId.replace(/^tent_\d+/, `tent_${newTentNumber}`);
                    group.id = newGroupId;
                });
            });
            
            document.getElementById('number_of_tents').value = remaining.length || 1;
            updateTentSpecifications();
            
            // Force update boarding information to remove data for deleted tent
            updateBoardingInformation();
        }

        // --- End JS ---

        function updateBoardingInformation() {
            const container = document.getElementById('tent_options_container');
            const tentOptions = container.querySelectorAll('.tent-option');
            const boardingData = { tents: [] };
            
            tentOptions.forEach((tentOption, index) => {
                const tentNumber = index + 1;
                const type = tentOption.querySelector(`#tent_${tentNumber}_type`)?.value;
                const beds = tentOption.querySelector(`#tent_${tentNumber}_beds`)?.value;
                const halfBoardCheckbox = tentOption.querySelector(`#tent_${tentNumber}_half_board`);
                const fullBoardCheckbox = tentOption.querySelector(`#tent_${tentNumber}_full_board`);
                
                let halfBoardDays = 0;
                let fullBoardDays = 0;
                
                if (halfBoardCheckbox && halfBoardCheckbox.checked) {
                    const halfBoardDaysInput = tentOption.querySelector(`#tent_${tentNumber}_half_board_days`);
                    halfBoardDays = halfBoardDaysInput ? parseInt(halfBoardDaysInput.value) || 0 : 0;
                    // Allow 0 values for boarding days
                    if (halfBoardDays < 0) {
                        halfBoardDays = 0;
                        if (halfBoardDaysInput) {
                            halfBoardDaysInput.value = 0;
                        }
                    }
                }
                
                if (fullBoardCheckbox && fullBoardCheckbox.checked) {
                    const fullBoardDaysInput = tentOption.querySelector(`#tent_${tentNumber}_full_board_days`);
                    fullBoardDays = fullBoardDaysInput ? parseInt(fullBoardDaysInput.value) || 0 : 0;
                    // Allow 0 values for boarding days
                    if (fullBoardDays < 0) {
                        fullBoardDays = 0;
                        if (fullBoardDaysInput) {
                            fullBoardDaysInput.value = 0;
                        }
                    }
                }
                
                // Add boarding data if either checkbox is checked or if there are days specified
                if ((halfBoardCheckbox && halfBoardCheckbox.checked) || (fullBoardCheckbox && fullBoardCheckbox.checked) || halfBoardDays > 0 || fullBoardDays > 0) {
                    boardingData.tents.push({
                        tent_number: tentNumber.toString(),
                        tent_type: type,
                        bed_config: beds,
                        half_board_days: halfBoardDays,
                        full_board_days: fullBoardDays
                    });
                }
            });
            
            // Create a hidden input for boarding_information if it doesn't exist
            let boardingInput = document.getElementById('boarding_information');
            if (!boardingInput) {
                boardingInput = document.createElement('input');
                boardingInput.type = 'hidden';
                boardingInput.id = 'boarding_information';
                boardingInput.name = 'boarding_information';
                document.querySelector('form').appendChild(boardingInput);
            }
            
            boardingInput.value = JSON.stringify(boardingData);
            console.log('Boarding data updated:', boardingData);
        }
    </script>
</body>
</html> 