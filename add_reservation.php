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

$message = '';
$error = '';

$prefill_guest_name = $_GET['guest_name'] ?? '';
$prefill_email = $_GET['email'] ?? '';
$prefill_phone = $_GET['phone'] ?? '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check for existing guest
        $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE name = ? AND phone = ? LIMIT 1");
        $guest_stmt->execute([$_POST['guest_name'], $_POST['phone']]);
        $existing_guest = $guest_stmt->fetchColumn();

        if ($existing_guest) {
            $guest_id = $existing_guest;
            // Optionally, update email if it's new
            if (!empty($_POST['email'])) {
                $update_email = $pdo->prepare("UPDATE guests SET email = ? WHERE id = ?");
                $update_email->execute([$_POST['email'], $guest_id]);
            }
        } else {
            // Insert new guest
            $guest_stmt = $pdo->prepare("INSERT INTO guests (name, email, phone, nationality, adults, kids, babies, total_people) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $total_people = $_POST['adults'] + $_POST['kids'] + $_POST['babies'];
            $guest_stmt->execute([
                $_POST['guest_name'],
                $_POST['email'],
                $_POST['phone'],
                $_POST['nationality'] ?? null,
                $_POST['adults'],
                $_POST['kids'],
                $_POST['babies'],
                $total_people
            ]);
            $guest_id = $pdo->lastInsertId();
        }
        
        // Calculate nights
        $check_in = new DateTime($_POST['check_in_date']);
        $check_out = new DateTime($_POST['check_out_date']);
        
        // Validate that check-in date is not after check-out date (allows same day)
        if ($check_in > $check_out) {
            throw new Exception('Check-in date cannot be after check-out date. Please correct the dates and try again.');
        }
        
        $nights = $check_out->diff($check_in)->days;
        
        // Process tent specifications and determine tent type
        $number_of_tents = intval($_POST['number_of_tents']);
        $tent_specifications = $_POST['tent_specifications'];
        
        // Validate tent specifications
        $validation = validateTentSpecifications($tent_specifications);
        if (!$validation['valid']) {
            throw new Exception('Invalid tent specifications: ' . implode(', ', $validation['invalid_specs']));
        }
        
        // Determine tent type based on selections
        $royal_tents = 0;
        $normal_tents = 0;
        
        foreach ($validation['valid_specs'] as $spec) {
            if ($spec['tent_type'] === 'ROYAL') {
                $royal_tents++;
            } elseif ($spec['tent_type'] === 'NORMAL') {
                $normal_tents++;
            }
        }
        
        if ($royal_tents > 0 && $normal_tents > 0) {
            $tent_type = 'MIXED';
        } elseif ($royal_tents > 0) {
            $tent_type = 'ROYAL';
        } elseif ($normal_tents > 0) {
            $tent_type = 'NORMAL';
        } else {
            $tent_type = 'NORMAL'; // Default
        }
        
        // Calculate accommodation price using new tariff system with exceptions
        $accommodation_price = calculateAccommodationPriceWithExceptions($pdo, $tent_specifications, $_POST['reservation_source'], $nights, $_POST['exceptions'] ?? []);
        
        // Add 4x4 car costs
        $car_price_per_day = getCar4x4Price($pdo, $_POST['car_days']);
        $cars_total = $_POST['cars_4x4'] * $car_price_per_day * $_POST['car_days'];
        
        // Add staff costs
        $staff_drivers = intval($_POST['staff_drivers'] ?? 0);
        $driver_days = intval($_POST['driver_days'] ?? 0);
        $staff_guides = intval($_POST['staff_guides'] ?? 0);
        $guide_days = intval($_POST['guide_days'] ?? 0);
        $driver_price = getDriverPrice($pdo);
        $guide_price = getGuidePrice($pdo);
        $staff_total = ($staff_drivers * $driver_price * $driver_days) + ($staff_guides * $guide_price * $guide_days);
        
        // Add services costs
        $services_total = 0;
        if (isset($_POST['services']) && is_array($_POST['services'])) {
            foreach ($_POST['services'] as $service_id => $service_data) {
                if (isset($service_data['selected']) && $service_data['selected'] == '1') {
                    $service_price = floatval($service_data['price']);
                    $services_total += $service_price;
                }
            }
        }
        
        // Calculate total price
        $total_price = $accommodation_price + $cars_total + $staff_total + $services_total;
        
        // Handle discount fields
        $discount_type = $_POST['discount_type'] ?? 'none';
        if ($discount_type === 'percent') {
            $discount_value = floatval($_POST['discount_percent'] ?? 0);
        } elseif ($discount_type === 'amount') {
            $discount_value = floatval($_POST['discount_amount'] ?? 0);
        } else {
            $discount_value = 0;
        }
        
        // Get current tariff version
        $current_tariff_version = getCurrentTariffVersion($pdo);
        
        // VALIDATE ALL RESOURCE AVAILABILITY BEFORE CREATING RESERVATION
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
                    $stmt = $pdo->prepare("SELECT 1 FROM reservation_cars WHERE car_id = ? AND NOT (end_date <= ? OR start_date >= ?)");
                    $stmt->execute([$car_id, $car_start, $car_end]);
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
                    $stmt = $pdo->prepare("SELECT 1 FROM reservation_humans WHERE human_id = ? AND role = 'driver' AND NOT (end_date <= ? OR start_date >= ?)");
                    $stmt->execute([$driver_id, $driver_start, $driver_end]);
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
                    $stmt = $pdo->prepare("SELECT 1 FROM reservation_humans WHERE human_id = ? AND role = 'guide' AND NOT (end_date <= ? OR start_date >= ?)");
                    $stmt->execute([$guide_id, $guide_start, $guide_end]);
                    if ($stmt->rowCount() > 0) {
                        $validation_errors[] = str_replace('{guide_id}', $guide_id, t('guide_not_available', 'Guide #{guide_id} is not available for the chosen period.'));
                    }
                }
            }
        }
        
        // Validate tent availability (external conflicts + internal conflicts)
        if (!empty($_POST['tent_numbers'])) {
            $tent_ids = $_POST['tent_numbers'];
            $tent_start = $_POST['check_in_date'];
            $tent_end = $_POST['check_out_date'];
            
            // Check for internal conflicts (same tent used multiple times)
            $tent_assignments = [];
            foreach ($tent_ids as $tent_id) {
                if (!empty($tent_id)) {
                    // Check if this tent is already assigned in this reservation
                    if (isset($tent_assignments[$tent_id])) {
                        $validation_errors[] = str_replace('{tent_id}', $tent_id, t('tent_assigned_multiple_times', 'Tent #{tent_id} is assigned multiple times.'));
                        break; // Exit loop
                    }
                    
                    // Store this assignment for future checks
                    $tent_assignments[$tent_id] = true;
                    
                    // Validate external conflicts (tent already booked by other reservations)
                    $stmt = $pdo->prepare("SELECT 1 FROM reservation_tents WHERE tent_id = ? AND NOT (end_date <= ? OR start_date >= ?)");
                    $stmt->execute([$tent_id, $tent_start, $tent_end]);
                    if ($stmt->rowCount() > 0) {
                        $validation_errors[] = str_replace('{tent_id}', $tent_id, t('tent_not_available', 'Tent #{tent_id} is not available for the chosen period.'));
                    }
                }
            }
        }
        
        // If any validation errors, throw exception before creating reservation
        if (!empty($validation_errors)) {
            throw new Exception(t('resource_validation_failed', 'Resource availability validation failed: ') . implode(' ', $validation_errors));
        }
        
        // Prepare mixed tent allocation data
        $royal_adults = intval($_POST['royal_adults'] ?? 0);
        $royal_kids = intval($_POST['royal_kids'] ?? 0);
        $normal_adults = intval($_POST['normal_adults'] ?? 0);
        $normal_kids = intval($_POST['normal_kids'] ?? 0);
        
        // Insert reservation
        $reservation_stmt = $pdo->prepare("INSERT INTO reservations (
            guest_id, tariff_version_id, reservation_source, agency_name, check_in_date, check_out_date, nights, 
            cars_4x4, car_days, car_with_driver, staff_drivers, driver_days, staff_guides, guide_days,
            tent_type, tent_specifications, number_of_tents,
            royal_adults_kids, normal_adults_kids,
            payment_cash, payment_bank_check, payment_transfer, total_price, 
            discount_type, discount_value, payment_status, confirmation, confirmation_way, notes, services_data,
            boarding_information, tourist_tax,
            created_by, created_by_role, updated_by, updated_by_role
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $reservation_stmt->execute([
            $guest_id,
            $current_tariff_version,
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
            $number_of_tents,
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
            $_SESSION['user_id'],
            $_SESSION['user_role']
        ]);
        $reservation_id = $pdo->lastInsertId();
        
        // Save exceptions
        $exceptions_count = 0;
        if (!empty($_POST['exceptions'])) {
            $exceptions_stmt = $pdo->prepare("INSERT INTO reservation_exceptions (reservation_id, guest_name, exception_type, assigned_tent_id, price_per_night, is_free, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($_POST['exceptions'] as $exception) {
                if (!empty($exception['guest_name']) && !empty($exception['exception_type']) && !empty($exception['assigned_tent_id'])) {
                    $exceptions_stmt->execute([
                        $reservation_id,
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
        if ($exceptions_count > 0) {
            $update_exceptions_stmt = $pdo->prepare("UPDATE reservations SET exceptions_count = ? WHERE id = ?");
            $update_exceptions_stmt->execute([$exceptions_count, $reservation_id]);
        }
        
        // ASSIGN RESOURCES AFTER RESERVATION IS CREATED (validation already passed)
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
                $stmt->execute([$reservation_id, $car_id, $car_start, $car_end]);
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
                $stmt->execute([$reservation_id, $driver_id, $driver_start, $driver_end]);
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
                $stmt->execute([$reservation_id, $guide_id, $guide_start, $guide_end]);
            }
        }
        }
        
        // Save assigned tents
        if (!empty($_POST['tent_numbers'])) {
            $tent_ids = $_POST['tent_numbers'];
            $tent_start = $_POST['check_in_date'];
            $tent_end = $_POST['check_out_date'];
            foreach ($tent_ids as $tent_id) {
                if (!empty($tent_id)) {
                $stmt = $pdo->prepare("INSERT INTO reservation_tents (reservation_id, tent_id, start_date, end_date) VALUES (?, ?, ?, ?)");
                $stmt->execute([$reservation_id, $tent_id, $tent_start, $tent_end]);
                }
            }
        }
        
        $message = t('reservation_added_successfully', 'Reservation added successfully!');
        header('Location: view_reservation_details.php?id=' . $reservation_id);
        exit();
        
    } catch (Exception $e) {
        $error = t('error_adding_reservation', 'Error adding reservation: ') . $e->getMessage();
    }
}

// Get active services
$services_stmt = $pdo->query("SELECT * FROM services WHERE is_active = 1 ORDER BY name");
$services = $services_stmt->fetchAll();

// AJAX endpoint for fetching available resources or tents
if (isset($_GET['fetch_resources']) || isset($_GET['fetch_tents'])) {
    header('Content-Type: application/json');
    $pdo = $pdo ?? require 'config/database.php';
    $response = ['cars' => [], 'drivers' => [], 'guides' => [], 'tents' => []];

    // Get period
    $car_start = $_GET['car_start_date'] ?? $_GET['check_in_date'] ?? null;
    $car_end = $_GET['car_end_date'] ?? $_GET['check_out_date'] ?? null;
    $driver_start = $_GET['driver_start_date'] ?? $_GET['check_in_date'] ?? null;
    $driver_end = $_GET['driver_end_date'] ?? $_GET['check_out_date'] ?? null;
    $guide_start = $_GET['guide_start_date'] ?? $_GET['check_in_date'] ?? null;
    $guide_end = $_GET['guide_end_date'] ?? $_GET['check_out_date'] ?? null;

    // If editing, get assigned resource IDs
    $edit_reservation_id = isset($_GET['edit_reservation_id']) ? intval($_GET['edit_reservation_id']) : 0;
    $assigned_car_ids = [];
    $assigned_driver_ids = [];
    $assigned_guide_ids = [];
    if ($edit_reservation_id) {
        $assigned_car_ids = $pdo->query("SELECT car_id FROM reservation_cars WHERE reservation_id = $edit_reservation_id")->fetchAll(PDO::FETCH_COLUMN);
        $assigned_driver_ids = $pdo->query("SELECT human_id FROM reservation_humans WHERE reservation_id = $edit_reservation_id AND role = 'driver'")->fetchAll(PDO::FETCH_COLUMN);
        $assigned_guide_ids = $pdo->query("SELECT human_id FROM reservation_humans WHERE reservation_id = $edit_reservation_id AND role = 'guide'")->fetchAll(PDO::FETCH_COLUMN);
    }

    // --- Cars ---
    $cars = $pdo->query("SELECT * FROM cars WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $available_cars = [];
    foreach ($cars as $car) {
        $stmt = $pdo->prepare("SELECT 1 FROM reservation_cars WHERE car_id = ? AND NOT (end_date <= ? OR start_date >= ?)");
        $stmt->execute([$car['id'], $car_start, $car_end]);
        if ($stmt->rowCount() == 0 || in_array($car['id'], $assigned_car_ids)) {
            $available_cars[] = ['id' => $car['id'], 'registration_number' => $car['registration_number']];
        }
    }
    // If assigned car is not in available_cars, add it (disabled)
    foreach ($assigned_car_ids as $car_id) {
        if (!in_array($car_id, array_column($available_cars, 'id'))) {
            $car = $pdo->query("SELECT * FROM cars WHERE id = $car_id")->fetch(PDO::FETCH_ASSOC);
            if ($car) $available_cars[] = ['id' => $car['id'], 'registration_number' => $car['registration_number'], 'disabled' => true];
        }
    }
    $response['cars'] = $available_cars;

    // --- Drivers ---
    $drivers = $pdo->query("SELECT * FROM human_resources WHERE is_active = 1 AND work_position LIKE '%driver%'")->fetchAll(PDO::FETCH_ASSOC);
    $available_drivers = [];
    foreach ($drivers as $driver) {
        $stmt = $pdo->prepare("SELECT 1 FROM reservation_humans WHERE human_id = ? AND role = 'driver' AND NOT (end_date <= ? OR start_date >= ?)");
        $stmt->execute([$driver['id'], $driver_start, $driver_end]);
        if ($stmt->rowCount() == 0 || in_array($driver['id'], $assigned_driver_ids)) {
            $available_drivers[] = ['id' => $driver['id'], 'full_name' => $driver['full_name']];
        }
    }
    foreach ($assigned_driver_ids as $driver_id) {
        if (!in_array($driver_id, array_column($available_drivers, 'id'))) {
            $driver = $pdo->query("SELECT * FROM human_resources WHERE id = $driver_id")->fetch(PDO::FETCH_ASSOC);
            if ($driver) $available_drivers[] = ['id' => $driver['id'], 'full_name' => $driver['full_name'], 'disabled' => true];
        }
    }
    $response['drivers'] = $available_drivers;

    // --- Guides ---
    $guides = $pdo->query("SELECT * FROM human_resources WHERE is_active = 1 AND work_position LIKE '%guide%'")->fetchAll(PDO::FETCH_ASSOC);
    $available_guides = [];
    foreach ($guides as $guide) {
        $stmt = $pdo->prepare("SELECT 1 FROM reservation_humans WHERE human_id = ? AND role = 'guide' AND NOT (end_date <= ? OR start_date >= ?)");
        $stmt->execute([$guide['id'], $guide_start, $guide_end]);
        if ($stmt->rowCount() == 0 || in_array($guide['id'], $assigned_guide_ids)) {
            $available_guides[] = ['id' => $guide['id'], 'full_name' => $guide['full_name']];
        }
    }
    foreach ($assigned_guide_ids as $guide_id) {
        if (!in_array($guide_id, array_column($available_guides, 'id'))) {
            $guide = $pdo->query("SELECT * FROM human_resources WHERE id = $guide_id")->fetch(PDO::FETCH_ASSOC);
            if ($guide) $available_guides[] = ['id' => $guide['id'], 'full_name' => $guide['full_name'], 'disabled' => true];
        }
    }
    $response['guides'] = $available_guides;

    // Fetch available tents by type and period
    if (isset($_GET['fetch_tents'])) {
        $tent_type = strtoupper($_GET['tent_type'] ?? '');
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
        if ($tent_type && $start_date && $end_date) {
            $edit_reservation_id = isset($_GET['edit_reservation_id']) ? intval($_GET['edit_reservation_id']) : 0;
            $query = "SELECT t.id, t.tent_number FROM tents t WHERE UPPER(t.tent_type) = ? AND t.is_active = 1 AND NOT EXISTS (
                SELECT 1 FROM reservation_tents rt WHERE rt.tent_id = t.id AND rt.reservation_id != ? AND NOT (rt.end_date <= ? OR rt.start_date >= ?)";
            $params = [$tent_type, $edit_reservation_id, $start_date, $end_date];
            $query .= ") ORDER BY t.tent_number";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $response['tents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode($response);
        exit;
    }

    // Fetch assigned tents for exceptions
    if (isset($_GET['fetch_assigned_tents'])) {
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
        exit;
    }

    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Reservation - ABDELMOULA CAMP</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .resource-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 16px 12px;
            margin: 15px 0 24px 0;
        }
        .resource-section h4 {
            margin: 0 0 12px 0;
            color: #495057;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .resource-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
            margin-top: 8px;
        }
        .resource-item {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 10px 10px 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }
        .resource-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #f0f0f0;
        }
        .resource-badge {
            background: #b8860b;
            color: #fff;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            margin-right: 2px;
        }
        .resource-header h5 {
            margin: 0;
            color: #495057;
            font-size: 1rem;
            font-weight: 600;
        }
        .period-section {
            margin-bottom: 0;
            padding: 0 0 6px 0;
            background: none;
            border: none;
        }
        .period-section label {
            display: block;
            font-weight: 600;
            color: #495057;
            font-size: 0.97rem;
            margin-bottom: 4px;
        }
        .resource-fields-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .date-inputs {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .date-inputs input[type="date"] {
            flex: 1;
            padding: 6px 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.97rem;
        }
        .date-separator {
            color: #6c757d;
            font-weight: 500;
            font-size: 0.97rem;
        }
        .resource-selector {
        
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
            align-items: flex-start;
        }
        
        .form-row-pair .form-section {
            flex: 1;
            min-width: 0;
        }
        

        
        #exceptions_container {
            margin-bottom: 24px !important;
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)) !important;
            gap: 20px !important;
        }
        
        .exceptions-container {
            margin-bottom: 24px;
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)) !important;
            gap: 20px !important;
        }
        
        #exceptions_container .exception-item {
            background: #ffffff !important;
            border: 3px solid #9ca3af !important;
            border-radius: 6px !important;
            padding: 12px !important;
            position: relative !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
            transition: all 0.2s ease !important;
            margin-bottom: 0 !important;
            display: block !important;
        }
        
        .exception-item {
            background: #ffffff;
            border: 3px solid #9ca3af;
            border-radius: 6px;
            padding: 12px;
            position: relative;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
            margin-bottom: 0 !important;
            display: block !important;
        }
        
        .exception-item:hover {
            border-color: #9ca3af;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .exception-item h4 {
            margin: 0 0 8px 0;
            color: #1f2937;
            font-size: 13px;
            font-weight: 600;
            font-family: "Times New Roman", serif;
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
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 3px;
            width: 20px;
            height: 20px;
            cursor: pointer;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-weight: bold;
        }
        
        .exception-item .remove-exception:hover {
            background: #dc2626;
            transform: scale(1.05);
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
        
        .exception-fields .form-group {
            margin-bottom: 0;
        }
        
        .exception-fields .form-group label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 4px;
            font-size: 11px;
            text-transform: none;
            letter-spacing: 0.2px;
            display: block;
        }
        
        .exception-fields .form-group input,
        .exception-fields .form-group select,
        .exception-fields .form-group textarea {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 5px 8px;
            font-size: 12px;
            transition: all 0.2s ease;
            background: #ffffff;
            width: 100%;
            box-sizing: border-box;
        }
        
        .exception-fields .form-group input:focus,
        .exception-fields .form-group select:focus,
        .exception-fields .form-group textarea:focus {
            border-color: #b8860b;
            box-shadow: 0 0 0 2px rgba(184, 134, 11, 0.1);
            outline: none;
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
            margin: 0;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            font-size: 12px;
            line-height: 1;
            display: flex;
            align-items: center;
        }
        
        .exception-fields .checkbox-group:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        
        .exception-item .price-section {
            background: #fefefe;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 6px 8px;
            margin-top: 0;
        }
        
        .exception-item .price-section label {
            color: #6b7280;
            font-size: 11px;
            font-weight: 500;
        }
        
        .exception-item .price-section input {
            font-weight: 500;
            color: #1f2937;
        }
        
        .add-exception-btn {
            background: #b8860b;
            border: 1px solid #a0760b;
            padding: 12px 20px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 14px;
            text-transform: none;
            letter-spacing: 0.3px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            color: white;
        }
        
        .add-exception-btn:hover {
            background: #a0760b;
            border-color: #8b6914;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        }
        
        .exceptions-empty-state {
            text-align: center;
            padding: 32px 20px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            transition: all 0.2s ease;
            grid-column: 1 / -1;
        }
        
        .exceptions-empty-state:hover {
            border-color: #d1d5db;
            background: #f3f4f6;
        }
        
        .empty-state-icon {
            font-size: 32px;
            margin-bottom: 12px;
            opacity: 0.5;
            color: #9ca3af;
        }
        
        .exceptions-empty-state p {
            margin: 0 0 6px 0;
            color: #6b7280;
            font-weight: 500;
            font-size: 15px;
        }
        
        .exceptions-empty-state small {
            color: #9ca3af;
            font-size: 13px;
        }
        
        @media (max-width: 1200px) {
            #exceptions_container {
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)) !important;
                gap: 16px !important;
            }
            .exceptions-container {
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 16px;
            }
            
            .exception-fields .form-group {
                min-width: 100px;
            }
        }
        
        @media (max-width: 768px) {
            #exceptions_container {
                grid-template-columns: 1fr !important;
                gap: 16px !important;
            }
            .exceptions-container {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .exception-fields {
                flex-direction: column;
                gap: 6px;
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
                width: 18px;
                height: 18px;
                font-size: 10px;
            }
            
            .add-exception-btn {
                padding: 10px 18px;
                font-size: 13px;
            }
            
            .exceptions-empty-state {
                padding: 24px 16px;
            }
        }
            margin-top: 0;
            flex: 1;
        }
        .resource-selector label {
            display: block;
            font-weight: 600;
            color: #495057;
            font-size: 0.97rem;
            margin-bottom: 4px;
        }
        .resource-select {
            width: 100%;
            padding: 7px 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.97rem;
            background: white;
        }
        .resource-select:focus {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.1rem rgba(0,123,255,.13);
        }
        .resource-count-row {
            display: flex;
            gap: 24px;
            align-items: flex-end;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .resource-count-group {
            display: flex;
            flex-direction: column;
            min-width: 120px;
        }
        .resource-count-group label {
            font-weight: 600;
            color: #495057;
            font-size: 0.98rem;
            margin-bottom: 2px;
        }
        .resource-count-group input[type="number"] {
            padding: 6px 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.97rem;
            width: 100%;
        }
        @media (max-width: 700px) {
            .resource-grid {
                grid-template-columns: 1fr;
            }
            .resource-fields-row {
                flex-direction: column;
                gap: 4px;
            }
            .resource-count-row {
                flex-direction: column;
                gap: 8px;
            }
            .resource-count-group {
                min-width: 0;
            }
        }
        .resource-section, .resource-item {
  background: #f8f9fa;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 10px 12px;
  margin-bottom: 8px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
  font-size: 12px;
  max-width: 340px;
  min-width: 200px;
  display: inline-block;
  vertical-align: top;
}
.resource-header h5 {
  font-size: 13px;
  margin: 0 0 4px 0;
  color: #495057;
  font-weight: 600;
}
.resource-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
}
.resource-count-row {
  display: flex;
  gap: 10px;
  align-items: flex-end;
  margin-bottom: 4px;
  flex-wrap: wrap;
}
.resource-count-group label {
  font-size: 12px;
  margin-bottom: 1px;
}
.resource-count-group input[type="number"] {
  padding: 3px 5px;
  font-size: 12px;
  width: 60px;
}
.period-section {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.period-section label {
  font-size: 12px;
  margin-bottom: 2px;
}
.period-section input[type="date"] {
  min-width: 110px;
  font-size: 12px;
  padding: 3px 5px;
}
@media (max-width: 700px) {
  .resource-section, .resource-item { max-width: 100%; min-width: 0; font-size: 11px; padding: 6px 4px; }
  .period-section { flex-direction: column; gap: 4px; }
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

        /* add styles */
        .tent-delete { background: transparent; border: none; color: #b91c1c; font-size: 18px; cursor: pointer; }
        .tent-delete:hover { color: #7f1d1d; }

    </style>
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="table-header">
            <h2><?php echo t('add_new_reservation', 'Add New Reservation'); ?></h2>
            <a href="dashboard.php" class="btn btn-secondary"><?php echo t('back_to_dashboard', 'Back to Dashboard'); ?></a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" class="form-container" id="reservationForm" onsubmit="return validateDates()">
            <!-- Guest Information and Exceptions Section -->
            <div class="form-row-pair">
            <!-- Guest Information Section -->
            <div class="form-section">
                <h3><?php echo t('guest_information', 'Guest Information'); ?></h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="reservation_source"><?php echo t('reservation_source', 'Reservation Source'); ?> *</label>
                        <select id="reservation_source" name="reservation_source" required onchange="toggleAgencyField()">
                            <option value="individual"><?php echo t('individual', 'Individual'); ?></option>
                            <option value="agency"><?php echo t('agency', 'Agency'); ?></option>
                        </select>
                    </div>
                    <div class="form-group" id="agency_name_group" style="display: none;">
                        <label for="agency_name"><?php echo t('agency_name', 'Agency Name'); ?> *</label>
                        <input type="text" id="agency_name" name="agency_name" placeholder="<?php echo t('enter_agency_name', 'Enter agency name'); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="guest_name"><?php echo t('guest_name', 'Guest Name'); ?> *</label>
                        <input type="text" id="guest_name" name="guest_name"  placeholder="<?php echo t('enter_guest_name', 'Enter Guest name'); ?>" required value="<?php echo isset($_POST['guest_name']) ? htmlspecialchars($_POST['guest_name']) : htmlspecialchars($prefill_guest_name); ?>">
                    </div>
                    <div class="form-group">
                        <label for="email"><?php echo t('email', 'Email'); ?></label>
                        <input type="email" id="email" name="email"  placeholder="<?php echo t('enter_Email', 'Enter Email'); ?>" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : htmlspecialchars($prefill_email); ?>">
                    </div>
                    <div class="form-group">
                        <label for="phone"><?php echo t('phone', 'Phone'); ?></label>
                        <input type="text" id="phone" name="phone" placeholder="<?php echo t('enter_phone', 'Enter phone'); ?>" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : htmlspecialchars($prefill_phone); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="nationality"><?php echo t('nationality', 'Nationality'); ?></label>
                        <input type="text" id="nationality" name="nationality" value="<?php echo isset($_POST['nationality']) ? htmlspecialchars($_POST['nationality']) : ''; ?>" placeholder="<?php echo t('enter_nationality', 'Enter nationality'); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="adults"><?php echo t('adults', 'Adults'); ?></label>
                        <input type="number" id="adults" name="adults" value="1" min="0">
                    </div>
                    <div class="form-group">
                        <label for="kids"><?php echo t('kids', 'Kids'); ?></label>
                        <input type="number" id="kids" name="kids" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label for="babies"><?php echo t('babies', 'Babies'); ?></label>
                        <input type="number" id="babies" name="babies" value="0" min="0">
                    </div>
                </div>
            </div>
            
            <!-- Exceptions Section -->
            <div class="form-section">
                <h3><?php echo t('exceptions', 'Exceptions'); ?></h3>
                <div class="exceptions-container" id="exceptions_container">
                    <!-- Exceptions will be added here dynamically -->
                    <div class="exceptions-empty-state" id="exceptions_empty_state">
                        <div class="empty-state-icon"></div>
                        
                    </div>
                </div>
                <div class="form-group">
                    <button type="button" class="btn btn-primary add-exception-btn" onclick="addException()">
                        <?php echo t('add_exception', 'Add Exception'); ?>
                    </button>
                </div>
                </div>
            </div>
            <!-- Reservation Details Section (moved directly under Guest Information) -->
            <div class="form-section">
                <h3><?php echo t('reservation_details', 'Reservation Details'); ?></h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="check_in_date"><?php echo t('check_in', 'Check-in Date'); ?> *</label>
                        <input type="date" id="check_in_date" name="check_in_date" required onchange="validateDates()">
                    </div>
                    <div class="form-group">
                        <label for="check_out_date"><?php echo t('check_out', 'Check-out Date'); ?> *</label>
                        <input type="date" id="check_out_date" name="check_out_date" required onchange="validateDates()">
                    </div>
                    <div id="date-error" class="error-message" style="display: none; color: red; margin-top: 5px;"></div>
                </div>
                <!-- Cars Section -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="cars_4x4"><?php echo t('number_of_cars', 'Number of Cars'); ?></label>
                        <input type="number" id="cars_4x4" name="cars_4x4" min="0" value="<?php echo isset($_POST['cars_4x4']) ? htmlspecialchars($_POST['cars_4x4']) : '0'; ?>" onchange="renderCarSelectors()">
                    </div>
                    <div id="car_selectors_group"></div>
                </div>
                <!-- Drivers Section -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="staff_drivers"><?php echo t('number_of_drivers', 'Number of Drivers'); ?></label>
                        <input type="number" id="staff_drivers" name="staff_drivers" min="0" value="<?php echo isset($_POST['staff_drivers']) ? htmlspecialchars($_POST['staff_drivers']) : '0'; ?>" onchange="renderDriverSelectors()">
                    </div>
                    <div id="driver_selectors_group"></div>
                </div>
                <!-- Guides Section -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="staff_guides"><?php echo t('number_of_guides', 'Number of Guides'); ?></label>
                        <input type="number" id="staff_guides" name="staff_guides" min="0" value="<?php echo isset($_POST['staff_guides']) ? htmlspecialchars($_POST['staff_guides']) : '0'; ?>" onchange="renderGuideSelectors()">
                    </div>
                    <div id="guide_selectors_group"></div>
                </div>
                <div id="resourceSelection"></div>
            </div>

            <div class="form-section">
                <h3><?php echo t('services', 'Services'); ?></h3>
                <div class="services-grid">
                    <?php foreach ($services as $service): ?>
                    <div class="service-item">
                        <div class="service-header">
                            <label>
                                <input type="checkbox" name="services[<?php echo $service['id']; ?>][selected]" value="1">
                                <?php echo htmlspecialchars($service['name']); ?>
                            </label>
                        </div>
                        <div class="service-price">
                            <label><?php echo t('price', 'Price'); ?> (TND)</label>
                            <input type="number" name="services[<?php echo $service['id']; ?>][price]" value="<?php echo $service['price']; ?>" step="0.01" min="0">
                        </div>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>

            <div class="form-section">
                <h3><?php echo t('accommodation', 'Accommodation'); ?></h3>
                <div class="form-note" style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 12px; margin-bottom: 16px; border-radius: 4px;">
                    <strong><?php echo t('note', 'Note'); ?>:</strong> <?php echo t('tents_optional_note', 'Tents are optional. Leave blank for day visitors who don\'t need overnight accommodation.'); ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="number_of_tents"><?php echo t('number_of_tents', 'Number of Tents'); ?></label>
                        <input type="number" id="number_of_tents" name="number_of_tents" value="1" min="0" max="100" onchange="updateTentOptions()">
                    </div>
                </div>
                <!-- Dynamic Tent Options -->
                <div id="tent_options_container">
                    <div class="tent-option" data-tent="1">
                        <div class="tent-card">
                            <div class="tent-header">
                                <h4><?php echo t('tent', 'Tent'); ?> 1</h4>
                                <button type="button" class="tent-delete" aria-label="Delete tent" onclick="removeTentOption(this)">✕</button>
                                <div class="tent-status-indicator" id="tent_1_status"></div>
                            </div>
                            
                            <div class="tent-configuration">
                                <div class="config-section">
                                    <h5><?php echo t('configuration', 'Configuration'); ?></h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="tent_1_type"><?php echo t('tent_type', 'Tent Type'); ?></label>
                                            <select id="tent_1_type" name="tent_1_type" onchange="fetchAvailableTentNumbers(1)">
                                                <option value=""><?php echo t('select_type', 'Select Type'); ?></option>
                                                <option value="NORMAL"><?php echo t('normal', 'NORMAL'); ?></option>
                                                <option value="ROYAL"><?php echo t('royal', 'ROYAL'); ?></option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="tent_1_beds"><?php echo t('bed_configuration', 'Bed Configuration'); ?></label>
                                            <select id="tent_1_beds" name="tent_1_beds" onchange="updateTentSpecifications()">
                                                <option value=""><?php echo t('select_beds', 'Select Beds'); ?></option>
                                                <option value="single"><?php echo t('single_bed', 'Single Bed'); ?></option>
                                                <option value="double"><?php echo t('double_bed', 'Double Bed'); ?></option>
                                                <option value="triple"><?php echo t('triple_bed', 'Triple Bed'); ?></option>
                                                <option value="quadruple"><?php echo t('quadruple_bed', 'Quadruple Bed'); ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group" id="tent_1_number_group"></div>
                                </div>
                                
                                <div class="boarding-section">
                                    <h5><?php echo t('boarding_options', 'Boarding Options'); ?></h5>
                                    <div class="boarding-options">
                                        <div class="boarding-option">
                                            <label class="boarding-checkbox">
                                                <input type="checkbox" id="tent_1_half_board" name="tent_1_half_board" onchange="toggleBoardingDays(1, 'half_board')">
                                                <span class="checkmark"></span>
                                                <span class="boarding-label"><?php echo t('half_board', 'Half Board'); ?></span>
                                            </label>
                                            <div id="tent_1_half_board_days_group" class="boarding-days-group" style="display: none;">
                                                <label for="tent_1_half_board_days"><?php echo t('nights', 'Nights'); ?></label>
                                                <input type="number" id="tent_1_half_board_days" name="tent_1_half_board_days" min="0" value="0" onchange="updateTentSpecifications()">
                                            </div>
                                        </div>
                                        <div class="boarding-option">
                                            <label class="boarding-checkbox">
                                                <input type="checkbox" id="tent_1_full_board" name="tent_1_full_board" onchange="toggleBoardingDays(1, 'full_board')">
                                                <span class="checkmark"></span>
                                                <span class="boarding-label"><?php echo t('full_board', 'Full Board'); ?></span>
                                            </label>
                                            <div id="tent_1_full_board_days_group" class="boarding-days-group" style="display: none;">
                                                <label for="tent_1_full_board_days"><?php echo t('nights', 'Nights'); ?></label>
                                                <input type="number" id="tent_1_full_board_days" name="tent_1_full_board_days" min="0" value="0" onchange="updateTentSpecifications()">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="kids-section">
                                    <h5><?php echo t('kids_options', 'Kids Options'); ?></h5>
                                    <div class="kids-options">
                                        <div class="kids-option">
                                            <label class="kids-checkbox">
                                                <input type="checkbox" id="tent_1_has_kids" name="tent_1_has_kids" onchange="toggleKidsNumber(1)">
                                                <span class="checkmark"></span>
                                                <span class="kids-label"><?php echo t('has_kids', 'Has Kids'); ?></span>
                                            </label>
                                            <div id="tent_1_kids_number_group" class="kids-number-group" style="display: none;">
                                                <label for="tent_1_kids_number"><?php echo t('kids_number', 'Number of Kids'); ?></label>
                                                <input type="number" id="tent_1_kids_number" name="tent_1_kids_number" min="1" value="1" onchange="updateTentSpecifications()">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="tent_specifications" name="tent_specifications">
            </div>

            <div class="form-section">
                <h3><?php echo t('payment', 'Payment'); ?></h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="payment_cash"><?php echo t('cash_payment', 'Cash Payment'); ?> (TND)</label>
                        <input type="number" id="payment_cash" name="payment_cash" value="0" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="payment_bank_check"><?php echo t('bank_check', 'Bank Check Payment'); ?> (TND)</label>
                        <input type="number" id="payment_bank_check" name="payment_bank_check" value="0" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="payment_transfer"><?php echo t('transfer_payment', 'Transfer Payment'); ?> (TND)</label>
                        <input type="number" id="payment_transfer" name="payment_transfer" value="0" min="0" step="0.01">
                    </div>
                </div>
                <div class="form-group">
                    <label for="payment_status"><?php echo t('payment_status', 'Payment Status'); ?></label>
                    <select id="payment_status" name="payment_status">
                        <option value="pending"><?php echo t('pending', 'Pending'); ?></option>
                        <option value="partial"><?php echo t('partial', 'Partial'); ?></option>
                        <option value="paid"><?php echo t('paid', 'Paid'); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="confirmation" name="confirmation" value="1" onchange="toggleConfirmationWay()">
                        <span class="checkmark"></span>
                        <?php echo t('confirmation', 'Confirmation'); ?>
                    </label>
                </div>
                <div class="form-group" id="confirmation_way_group" style="display: none;">
                    <label for="confirmation_way"><?php echo t('confirmation_way', 'Confirmation Way'); ?></label>
                    <input type="text" id="confirmation_way" name="confirmation_way" placeholder="<?php echo t('enter_confirmation_way', 'Enter confirmation method'); ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="discount_type"><?php echo t('discount_type', 'Discount Type'); ?></label>
                        <select id="discount_type" name="discount_type" onchange="toggleDiscountFields()">
                            <option value="none"><?php echo t('none', 'None'); ?></option>
                            <option value="percent"><?php echo t('percent', 'Percentage (%)'); ?></option>
                            <option value="amount"><?php echo t('amount', 'Amount (TND)'); ?></option>
                        </select>
                    </div>
                    <div class="form-group" id="discount_percent_group" style="display: none;">
                        <label for="discount_percent"><?php echo t('discount_percent', 'Discount (%)'); ?></label>
                        <input type="number" step="0.01" min="0" max="100" id="discount_percent" name="discount_percent">
                    </div>
                    <div class="form-group" id="discount_amount_group" style="display: none;">
                        <label for="discount_amount"><?php echo t('discount_amount', 'Discount (TND)'); ?></label>
                        <input type="number" step="0.01" min="0" id="discount_amount" name="discount_amount">
                    </div>
                </div>
                <div class="form-group">
                    <label for="tourist_tax"><?php echo t('tourist_tax', 'Tourist Tax'); ?> (TND)</label>
                    <input type="number" id="tourist_tax" name="tourist_tax" value="0" min="0" step="0.01">
                </div>
            </div>
            <div class="form-section">
                <h3><?php echo t('additional_information', 'Additional Information'); ?></h3>
                <div class="form-group">
                    <label for="notes"><?php echo t('notes', 'Notes'); ?></label>
                    <textarea id="notes" name="notes" rows="4"></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?php echo t('add_reservation', 'Add Reservation'); ?></button>
                <a href="dashboard.php" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></a>
            </div>
        </form>
    </div>

    <script>
    window.translations = {
        select_type: "<?php echo t('select_type', 'Select Type'); ?>",
        normal: "<?php echo t('normal', 'NORMAL'); ?>",
        royal: "<?php echo t('royal', 'ROYAL'); ?>",
        select_beds: "<?php echo t('select_beds', 'Select Beds'); ?>",
        single: "<?php echo t('single', 'Single'); ?>",
        double: "<?php echo t('double', 'Double'); ?>",
        triple: "<?php echo t('triple', 'Triple'); ?>",
        quadruple: "<?php echo t('quadruple', 'Quadruple'); ?>",
        tent: "<?php echo t('tent', 'Tent'); ?>",
        tent_number: "<?php echo t('tent_number', 'Tent Number'); ?>",
        select_tent: "<?php echo t('select_tent', 'Select Tent'); ?>",
        no_tents_available: "<?php echo t('no_tents_available', 'No tents available'); ?>",
        select_car: "<?php echo t('select_car', 'Select Car'); ?>",
        select_driver: "<?php echo t('select_driver', 'Select Driver'); ?>",
        select_guide: "<?php echo t('select_guide', 'Select Guide'); ?>",
        car: "<?php echo t('car', 'Car'); ?>",
        driver: "<?php echo t('driver', 'Driver'); ?>",
        guide: "<?php echo t('guide', 'Guide'); ?>",
        usage_period: "<?php echo t('usage_period', 'Usage Period:'); ?>",
        to: "<?php echo t('to', 'to'); ?>",
        unavailable: "<?php echo t('unavailable', 'Unavailable'); ?>",
        half_board: "<?php echo t('half_board', 'Half Board'); ?>",
        full_board: "<?php echo t('full_board', 'Full Board'); ?>",
        days: "<?php echo t('days', 'Days'); ?>"
    };
    window.translations.select_resource_type = function(type) {
        switch(type) {
            case 'car': return window.translations.select_car;
            case 'driver': return window.translations.select_driver;
            case 'guide': return window.translations.select_guide;
            default: return window.translations.select_tent;
        }
    };
    
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
    </script>
    <script>
        function updateTentOptions() {
            const numberOfTents = parseInt(document.getElementById('number_of_tents').value) || 0;
            const container = document.getElementById('tent_options_container');
            // Clear existing options
            container.innerHTML = '';
            
            // If 0 tents, show a message for day visitors
            if (numberOfTents === 0) {
                container.innerHTML = '<div class="form-note" style="background: #e8f5e8; border-left: 4px solid #4caf50; padding: 12px; border-radius: 4px; text-align: center; color: #2e7d32;"><?php echo t("day_visitor_mode", "Day Visitor Mode - No overnight accommodation required"); ?></div>';
                // Clear tent specifications when no tents
                document.getElementById('tent_specifications').value = '';
                updateTentSpecifications();
                return;
            }
            
            // Create tent options based on number
            for (let i = 1; i <= numberOfTents; i++) {
                const tentOption = document.createElement('div');
                tentOption.className = 'tent-option';
                tentOption.setAttribute('data-tent', i);
                tentOption.innerHTML = `
                    <div class="tent-card">
                        <div class="tent-header">
                            <h4>Tent ${i}</h4>
                            <button type="button" class="tent-delete" aria-label="Delete tent" onclick="removeTentOption(this)">✕</button>
                            <div class="tent-status-indicator" id="tent_${i}_status"></div>
                        </div>
                        
                        <div class="tent-configuration">
                            <div class="config-section">
                                <h5><?php echo t('configuration', 'Configuration'); ?></h5>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="tent_${i}_type"><?php echo t('tent_type', 'Tent Type'); ?></label>
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
                                <h5><?php echo t('boarding_options', 'Boarding Options'); ?></h5>
                                <div class="boarding-options">
                                    <div class="boarding-option">
                                        <label class="boarding-checkbox">
                                            <input type="checkbox" id="tent_${i}_half_board" name="tent_${i}_half_board" onchange="toggleBoardingDays(${i}, 'half_board')">
                                            <span class="checkmark"></span>
                                            <span class="boarding-label"><?php echo t('half_board', 'Half Board'); ?></span>
                                        </label>
                                        <div id="tent_${i}_half_board_days_group" class="boarding-days-group" style="display: none;">
                                            <label for="tent_${i}_half_board_days"><?php echo t('nights', 'Nights'); ?></label>
                                            <input type="number" id="tent_${i}_half_board_days" name="tent_${i}_half_board_days" min="0" value="0" onchange="updateTentSpecifications()">
                                        </div>
                                    </div>
                                    <div class="boarding-option">
                                        <label class="boarding-checkbox">
                                            <input type="checkbox" id="tent_${i}_full_board" name="tent_${i}_full_board" onchange="toggleBoardingDays(${i}, 'full_board')">
                                            <span class="checkmark"></span>
                                            <span class="boarding-label"><?php echo t('full_board', 'Full Board'); ?></span>
                                        </label>
                                        <div id="tent_${i}_full_board_days_group" class="boarding-days-group" style="display: none;">
                                            <label for="tent_${i}_full_board_days"><?php echo t('nights', 'Nights'); ?></label>
                                            <input type="number" id="tent_${i}_full_board_days" name="tent_${i}_full_board_days" min="0" value="0" onchange="updateTentSpecifications()">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="kids-section">
                                <h5><?php echo t('kids_options', 'Kids Options'); ?></h5>
                                <div class="kids-options">
                                    <div class="kids-option">
                                        <label class="kids-checkbox">
                                            <input type="checkbox" id="tent_${i}_has_kids" name="tent_${i}_has_kids" onchange="toggleKidsNumber(${i})">
                                            <span class="checkmark"></span>
                                            <span class="kids-label"><?php echo t('has_kids', 'Has Kids'); ?></span>
                                        </label>
                                        <div id="tent_${i}_kids_number_group" class="kids-number-group" style="display: none;">
                                            <label for="tent_${i}_kids_number"><?php echo t('kids_number', 'Number of Kids'); ?></label>
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
            // Populate with existing data if available
            populateTentOptionsFromSpecs();
            updateTentSpecifications();
            
            // Fetch available tent numbers for each tent after creation
            for (let i = 1; i <= numberOfTents; i++) {
                fetchAvailableTentNumbers(i);
            }
        }
        function fetchAvailableTentNumbers(tentIndex) {
            const type = document.getElementById(`tent_${tentIndex}_type`).value;
            const check_in = document.getElementById('check_in_date').value;
            const check_out = document.getElementById('check_out_date').value;
            if (!type || !check_in || !check_out) {
                document.getElementById(`tent_${tentIndex}_number_group`).innerHTML = '';
                updateTentSpecifications();
                return;
            }
            fetch(`add_reservation.php?fetch_tents=1&tent_type=${type}&start_date=${check_in}&end_date=${check_out}`)
                .then(res => res.json())
                .then(data => {
                    let html = '<label for="tent_' + tentIndex + '_number">Tent Number</label>';
                    html += '<select id="tent_' + tentIndex + '_number" name="tent_numbers[]" onchange="updateTentSpecifications()">';
                    html += `<option value="">${window.translations.select_tent}</option>`;
                    if (data.tents && data.tents.length > 0) {
                        data.tents.forEach(tent => {
                            html += `<option value="${tent.id}">${tent.tent_number}</option>`;
                        });
                    } else {
                        html += `<option value="">${window.translations.no_tents_available}</option>`;
                    }
                    html += '</select>';
                    document.getElementById(`tent_${tentIndex}_number_group`).innerHTML = html;
                    updateTentSpecifications();
                });
        }
        function updateTentSpecifications() {
            const numberOfTents = parseInt(document.getElementById('number_of_tents').value) || 0;
            let specs = [];
            
            // If no tents, clear specifications and return
            if (numberOfTents === 0) {
                document.getElementById('tent_specifications').value = '';
                updateMixedTentAllocationVisibility && updateMixedTentAllocationVisibility();
                return;
            }
            
            for (let i = 1; i <= numberOfTents; i++) {
                const type = document.getElementById(`tent_${i}_type`).value;
                const beds = document.getElementById(`tent_${i}_beds`).value;
                const tentNumberSelect = document.getElementById(`tent_${i}_number`);
                let tentNumberText = '';
                if (tentNumberSelect && tentNumberSelect.value) {
                    // Get the tent number from the option text for display
                    let tentNumber = tentNumberSelect.options[tentNumberSelect.selectedIndex].text;
                    // Remove "(Unavailable)" if present
                    tentNumber = tentNumber.replace(/\s*\([^)]*\)$/, '');
                    // Store tent number (not tent ID) in specifications for display purposes
                    tentNumberText = ` (Tent #${tentNumber})`;
                }
                
                // Add boarding information
                let boardingText = '';
                const halfBoardCheckbox = document.getElementById(`tent_${i}_half_board`);
                const fullBoardCheckbox = document.getElementById(`tent_${i}_full_board`);
                
                if (halfBoardCheckbox && halfBoardCheckbox.checked) {
                    const halfBoardDays = document.getElementById(`tent_${i}_half_board_days`).value;
                    boardingText += ` [${window.translations.half_board}: ${halfBoardDays} ${window.translations.days}]`;
                }
                
                if (fullBoardCheckbox && fullBoardCheckbox.checked) {
                    const fullBoardDays = document.getElementById(`tent_${i}_full_board_days`).value;
                    boardingText += ` [${window.translations.full_board}: ${fullBoardDays} ${window.translations.days}]`;
                }
                
                // Add kids information
                let kidsText = '';
                const hasKidsCheckbox = document.getElementById(`tent_${i}_has_kids`);
                
                if (hasKidsCheckbox && hasKidsCheckbox.checked) {
                    const kidsNumber = document.getElementById(`tent_${i}_kids_number`).value;
                    kidsText += ` [Kids: ${kidsNumber}]`;
                }
                
                if (type && beds && tentNumberSelect && tentNumberSelect.value) {
                    specs.push(`Tent ${i}: ${type} - ${beds}${tentNumberText}${boardingText}${kidsText}`);
                }
            }
            document.getElementById('tent_specifications').value = specs.join(', ');
            
            // Update boarding_information column
            updateBoardingInformation();
            
            updateMixedTentAllocationVisibility && updateMixedTentAllocationVisibility();
        }
        
        function updateBoardingInformation() {
            const numberOfTents = parseInt(document.getElementById('number_of_tents').value) || 0;
            
            // If no tents, clear boarding information and return
            if (numberOfTents === 0) {
                document.getElementById('boarding_information').value = '';
                return;
            }
            
            const boardingData = { tents: [] };
            
            // Ensure boarding days inputs have proper values when checkboxes are checked
            for (let i = 1; i <= numberOfTents; i++) {
                const halfBoardCheckbox = document.getElementById(`tent_${i}_half_board`);
                const fullBoardCheckbox = document.getElementById(`tent_${i}_full_board`);
                const halfBoardDaysInput = document.getElementById(`tent_${i}_half_board_days`);
                const fullBoardDaysInput = document.getElementById(`tent_${i}_full_board_days`);
                
                if (halfBoardCheckbox && halfBoardCheckbox.checked && halfBoardDaysInput) {
                    if (!halfBoardDaysInput.value || parseInt(halfBoardDaysInput.value) < 0) {
                        halfBoardDaysInput.value = 0;
                    }
                }
                
                if (fullBoardCheckbox && fullBoardCheckbox.checked && fullBoardDaysInput) {
                    if (!fullBoardDaysInput.value || parseInt(fullBoardDaysInput.value) < 0) {
                        fullBoardDaysInput.value = 0;
                    }
                }
            }
            
            for (let i = 1; i <= numberOfTents; i++) {
                const type = document.getElementById(`tent_${i}_type`).value;
                const beds = document.getElementById(`tent_${i}_beds`).value;
                const halfBoardCheckbox = document.getElementById(`tent_${i}_half_board`);
                const fullBoardCheckbox = document.getElementById(`tent_${i}_full_board`);
                
                let halfBoardDays = 0;
                let fullBoardDays = 0;
                
                if (halfBoardCheckbox && halfBoardCheckbox.checked) {
                    const halfBoardDaysInput = document.getElementById(`tent_${i}_half_board_days`);
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
                    const fullBoardDaysInput = document.getElementById(`tent_${i}_full_board_days`);
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
                        tent_number: i.toString(),
                        tent_type: type,
                        bed_config: beds,
                        half_board_days: halfBoardDays,
                        full_board_days: fullBoardDays
                    });
                }
            }
            
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
        
        function updateMixedTentAllocationVisibility() {
            // Show allocation fields if tent type is mixed
            let tentTypes = [];
            const numberOfTents = parseInt(document.getElementById('number_of_tents').value) || 0;
            
            // If no tents, hide mixed allocation and return
            if (numberOfTents === 0) {
                const mixedAlloc = document.getElementById('mixed-tent-allocation');
                if (mixedAlloc) mixedAlloc.style.display = 'none';
                return;
            }
            
            for (let i = 1; i <= numberOfTents; i++) {
                const tentType = document.getElementById(`tent_${i}_type`);
                if (tentType && tentType.value) tentTypes.push(tentType.value);
            }
            const isMixed = tentTypes.includes('ROYAL') && tentTypes.includes('NORMAL');
            const mixedAlloc = document.getElementById('mixed-tent-allocation');
            if (mixedAlloc) mixedAlloc.style.display = isMixed ? '' : 'none';
        }
        function populateTentOptionsFromSpecs() {
            const tentSpecs = document.getElementById('tent_specifications').value;
            if (!tentSpecs) return;
            const specsArr = tentSpecs.split(', ');
            specsArr.forEach((spec, idx) => {
                // Updated regex to capture boarding information
                const match = spec.match(/Tent (\d+): (\w+) - (\w+)(?:\s*\(Tent #(\d+)\))?(.*)/);
                if (match) {
                    const tentNum = match[1];
                    const type = match[2];
                    const beds = match[3];
                    const tentDisplayNumber = match[4]; // The tent number from the specification
                    const boardingInfo = match[5] || ''; // The boarding information part
                    
                    const typeSelect = document.getElementById(`tent_${tentNum}_type`);
                    const bedsSelect = document.getElementById(`tent_${tentNum}_beds`);
                    if (typeSelect) typeSelect.value = type;
                    if (bedsSelect) bedsSelect.value = beds;
                    
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
                    }
                }
            });
            
            // Fetch available tent numbers for each tent after populating specs
            const numberOfTents = parseInt(document.getElementById('number_of_tents').value) || 0;
            for (let i = 1; i <= numberOfTents; i++) {
                fetchAvailableTentNumbers(i);
            }
        }
        function updateMixedTentAllocationVisibility() {
            // Show allocation fields if tent type is mixed
            let tentTypes = [];
            const numberOfTents = parseInt(document.getElementById('number_of_tents').value) || 1;
            for (let i = 1; i <= numberOfTents; i++) {
                const tentType = document.getElementById(`tent_${i}_type`);
                if (tentType && tentType.value) tentTypes.push(tentType.value);
            }
            const isMixed = tentTypes.includes('ROYAL') && tentTypes.includes('NORMAL');
            document.getElementById('mixed-tent-allocation').style.display = isMixed ? '' : 'none';
        }
        // On page load, initialize tent options
        document.addEventListener('DOMContentLoaded', function() {
            updateTentOptions();
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

        function renderCarSelectors() {
            const num = parseInt(document.getElementById('cars_4x4').value) || 0;
            const group = document.getElementById('car_selectors_group');
            group.innerHTML = '';
            
            if (num > 0) {
                group.innerHTML = `
                    <div class="resource-section">
                        <h4>${window.translations.car}</h4>
                        <div class="resource-grid">
                `;
                
            for (let i = 0; i < num; i++) {
                    const startVal = document.getElementById('check_in_date').value;
                    const endVal = document.getElementById('check_out_date').value;
                    
                    group.innerHTML += `
                        <div class="resource-item">
                            <div class="resource-header">
                                <span class="resource-badge">${i + 1}</span>
                                <h5>${window.translations.car} ${i + 1}</h5>
                            </div>
                            <div class="resource-fields-row">
                                <div class="period-section">
                                    <label>${window.translations.usage_period}:</label>
                                    <div class="date-inputs">
                                        <input type="date" name="car_start_dates[]" class="period-start" data-resource="car" data-index="${i}" value="${startVal}" onchange="updateResourcePeriod('car', ${i})">
                                        <span class="date-separator">${window.translations.to}</span>
                                        <input type="date" name="car_end_dates[]" class="period-end" data-resource="car" data-index="${i}" value="${endVal}" onchange="updateResourcePeriod('car', ${i})">
                                    </div>
                                </div>
                                <div class="resource-selector">
                                    <label>${window.translations.select_car}:</label>
                                    <select name="car_ids[]" class="resource-select" data-resource="car" data-index="${i}" required>
                                        <option value="">${window.translations.select_car}</option>
                                    </select>
                                </div>
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
                select.innerHTML = `<option value="">${window.translations.select_resource_type(resourceType)}</option>`;
            }
        }
        
        function fetchAvailableCars(index, startDate = null, endDate = null) {
            const start = startDate || document.querySelector(`input[data-resource="car"][data-index="${index}"].period-start`).value;
            const end = endDate || document.querySelector(`input[data-resource="car"][data-index="${index}"].period-end`).value;
            
            if (!start || !end) return;
            
                fetch(`add_reservation.php?fetch_resources=1&car_start_date=${start}&car_end_date=${end}`)
                    .then(res => res.json())
                    .then(data => {
                    const select = document.querySelector(`select[data-resource="car"][data-index="${index}"]`);
                    select.innerHTML = `<option value="">${window.translations.select_car}</option>`;
                    
                        if (data.cars) {
                            data.cars.forEach(car => {
                            const option = document.createElement('option');
                            option.value = car.id;
                            option.textContent = car.registration_number;
                            if (car.disabled) {
                                option.disabled = true;
                                option.textContent += ' (${window.translations.unavailable})';
                            }
                            select.appendChild(option);
                            });
                        }
                })
                .catch(error => {
                    console.error('Error fetching cars:', error);
                });
        }
        function renderDriverSelectors() {
            const num = parseInt(document.getElementById('staff_drivers').value) || 0;
            const group = document.getElementById('driver_selectors_group');
            group.innerHTML = '';
            
            if (num > 0) {
                group.innerHTML = `
                    <div class="resource-section">
                        <h4>${window.translations.driver}</h4>
                        <div class="resource-grid">
                `;
                
            for (let i = 0; i < num; i++) {
                    const startVal = document.getElementById('check_in_date').value;
                    const endVal = document.getElementById('check_out_date').value;
                    
                    group.innerHTML += `
                        <div class="resource-item">
                            <div class="resource-header">
                                <span class="resource-badge">${i + 1}</span>
                                <h5>${window.translations.driver} ${i + 1}</h5>
                            </div>
                            <div class="resource-fields-row">
                                <div class="period-section">
                                    <label>${window.translations.usage_period}:</label>
                                    <div class="date-inputs">
                                        <input type="date" name="driver_start_dates[]" class="period-start" data-resource="driver" data-index="${i}" value="${startVal}" onchange="updateResourcePeriod('driver', ${i})">
                                        <span class="date-separator">${window.translations.to}</span>
                                        <input type="date" name="driver_end_dates[]" class="period-end" data-resource="driver" data-index="${i}" value="${endVal}" onchange="updateResourcePeriod('driver', ${i})">
                                    </div>
                                </div>
                                <div class="resource-selector">
                                    <label>${window.translations.select_driver}:</label>
                                    <select name="driver_ids[]" class="resource-select" data-resource="driver" data-index="${i}" required>
                                        <option value="">${window.translations.select_driver}</option>
                                    </select>
                                </div>
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
        function fetchAvailableDrivers(index, startDate = null, endDate = null) {
            const start = startDate || document.querySelector(`input[data-resource="driver"][data-index="${index}"].period-start`).value;
            const end = endDate || document.querySelector(`input[data-resource="driver"][data-index="${index}"].period-end`).value;
            
            if (!start || !end) return;
            
                fetch(`add_reservation.php?fetch_resources=1&driver_start_date=${start}&driver_end_date=${end}`)
                    .then(res => res.json())
                    .then(data => {
                    const select = document.querySelector(`select[data-resource="driver"][data-index="${index}"]`);
                    select.innerHTML = `<option value="">${window.translations.select_driver}</option>`;
                    
                        if (data.drivers) {
                            data.drivers.forEach(driver => {
                            const option = document.createElement('option');
                            option.value = driver.id;
                            option.textContent = driver.full_name;
                            if (driver.disabled) {
                                option.disabled = true;
                                option.textContent += ' (${window.translations.unavailable})';
                            }
                            select.appendChild(option);
                            });
                        }
                })
                .catch(error => {
                    console.error('Error fetching drivers:', error);
                });
        }
        function renderGuideSelectors() {
            const num = parseInt(document.getElementById('staff_guides').value) || 0;
            const group = document.getElementById('guide_selectors_group');
            group.innerHTML = '';
            
            if (num > 0) {
                group.innerHTML = `
                    <div class="resource-section">
                        <h4>${window.translations.guide}</h4>
                        <div class="resource-grid">
                `;
                
            for (let i = 0; i < num; i++) {
                    const startVal = document.getElementById('check_in_date').value;
                    const endVal = document.getElementById('check_out_date').value;
                    
                    group.innerHTML += `
                        <div class="resource-item">
                            <div class="resource-header">
                                <span class="resource-badge">${i + 1}</span>
                                <h5>${window.translations.guide} ${i + 1}</h5>
                            </div>
                            <div class="resource-fields-row">
                                <div class="period-section">
                                    <label>${window.translations.usage_period}:</label>
                                    <div class="date-inputs">
                                        <input type="date" name="guide_start_dates[]" class="period-start" data-resource="guide" data-index="${i}" value="${startVal}" onchange="updateResourcePeriod('guide', ${i})">
                                        <span class="date-separator">${window.translations.to}</span>
                                        <input type="date" name="guide_end_dates[]" class="period-end" data-resource="guide" data-index="${i}" value="${endVal}" onchange="updateResourcePeriod('guide', ${i})">
                                    </div>
                                </div>
                                <div class="resource-selector">
                                    <label>${window.translations.select_guide}:</label>
                                    <select name="guide_ids[]" class="resource-select" data-resource="guide" data-index="${i}" required>
                                        <option value="">${window.translations.select_guide}</option>
                                    </select>
                                </div>
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
        function fetchAvailableGuides(index, startDate = null, endDate = null) {
            const start = startDate || document.querySelector(`input[data-resource="guide"][data-index="${index}"].period-start`).value;
            const end = endDate || document.querySelector(`input[data-resource="guide"][data-index="${index}"].period-end`).value;
            
            if (!start || !end) return;
            
                fetch(`add_reservation.php?fetch_resources=1&guide_start_date=${start}&guide_end_date=${end}`)
                    .then(res => res.json())
                    .then(data => {
                    const select = document.querySelector(`select[data-resource="guide"][data-index="${index}"]`);
                    select.innerHTML = `<option value="">${window.translations.select_guide}</option>`;
                    
                        if (data.guides) {
                            data.guides.forEach(guide => {
                            const option = document.createElement('option');
                            option.value = guide.id;
                            option.textContent = guide.full_name;
                            if (guide.disabled) {
                                option.disabled = true;
                                option.textContent += ' (${window.translations.unavailable})';
                            }
                            select.appendChild(option);
                            });
                        }
                })
                .catch(error => {
                    console.error('Error fetching guides:', error);
                });
        }
        document.addEventListener('DOMContentLoaded', function() {
            renderCarSelectors();
            renderDriverSelectors();
            renderGuideSelectors();
            updateTentOptions(); // Ensure tent options are generated on page load
            toggleAgencyField(); // Initialize agency field visibility
            toggleDiscountFields(); // Initialize discount fields visibility
            updateMixedTentAllocationVisibility(); // Initialize mixed tent allocation visibility
            forceGridLayout(); // Force grid layout for exceptions
        });


        function calcDays(start, end) {
            if (!start || !end) return 0;
            const d1 = new Date(start);
            const d2 = new Date(end);
            return Math.max(0, Math.ceil((d2 - d1) / (1000 * 60 * 60 * 24)) + 1);
        }


        function toggleDiscountFields() {
            var type = document.getElementById('discount_type').value;
            document.getElementById('discount_percent_group').style.display = (type === 'percent') ? '' : 'none';
            document.getElementById('discount_amount_group').style.display = (type === 'amount') ? '' : 'none';
        }
        
        function toggleConfirmationWay() {
            var confirmation = document.getElementById('confirmation').checked;
            document.getElementById('confirmation_way_group').style.display = confirmation ? '' : 'none';
            if (!confirmation) {
                document.getElementById('confirmation_way').value = '';
            }
        }
        
        window.onload = function() {
            toggleDiscountFields();
            toggleConfirmationWay();
        };
        
        let exceptionCounter = 0;
        
        function addException() {
            exceptionCounter++;
            const container = document.getElementById('exceptions_container');
            const exceptionDiv = document.createElement('div');
            exceptionDiv.className = 'exception-item';
            exceptionDiv.id = 'exception_' + exceptionCounter;
            exceptionDiv.style.opacity = '0';
            exceptionDiv.style.transform = 'translateY(20px)';
            
            // Get available tents for this exception
            const availableTents = getAvailableTentsForException();
            
            exceptionDiv.innerHTML = `
                <button type="button" class="remove-exception" onclick="removeException(${exceptionCounter})" title="Remove Exception">×</button>
                <h4><?php echo t('exception_guest', 'Exception Guest'); ?> ${exceptionCounter}</h4>
                <div class="exception-fields">
                    <div class="form-group">
                        <label for="exception_guest_name_${exceptionCounter}"><?php echo t('guest_name', 'Guest Name'); ?></label>
                        <input type="text" id="exception_guest_name_${exceptionCounter}" name="exceptions[${exceptionCounter}][guest_name]" placeholder="Enter guest name" required>
                    </div>
                    <div class="form-group">
                        <label for="exception_type_${exceptionCounter}"><?php echo t('exception_type', 'Exception Type'); ?></label>
                        <select id="exception_type_${exceptionCounter}" name="exceptions[${exceptionCounter}][exception_type]" required>
                            <option value=""><?php echo t('select_type', 'Select Type'); ?></option>
                            <option value="driver"><?php echo t('driver', 'Driver'); ?></option>
                            <option value="guide"><?php echo t('guide', 'Guide'); ?></option>
                            <option value="company"><?php echo t('company', 'Company'); ?></option>
                            <option value="other"><?php echo t('other', 'Other'); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="exception_tent_${exceptionCounter}"><?php echo t('assigned_tent', 'Assigned Tent'); ?></label>
                        <select id="exception_tent_${exceptionCounter}" name="exceptions[${exceptionCounter}][assigned_tent_id]" required>
                            <option value=""><?php echo t('select_tent', 'Select Tent'); ?></option>
                            ${availableTents.map(tent => `<option value="${tent.id}">${tent.display}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group price-section">
                        <label for="exception_price_${exceptionCounter}"><?php echo t('price_per_night', 'Price per Night'); ?> (TND)</label>
                        <input type="number" id="exception_price_${exceptionCounter}" name="exceptions[${exceptionCounter}][price_per_night]" value="0" min="0" step="0.01" onchange="toggleExceptionFree(${exceptionCounter})" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="exception_free_${exceptionCounter}" name="exceptions[${exceptionCounter}][is_free]" value="1" onchange="toggleExceptionPrice(${exceptionCounter})">
                            <label for="exception_free_${exceptionCounter}"><?php echo t('is_free', 'Is Free'); ?></label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="exception_notes_${exceptionCounter}"><?php echo t('exception_notes', 'Notes'); ?></label>
                        <textarea id="exception_notes_${exceptionCounter}" name="exceptions[${exceptionCounter}][notes]" rows="3" placeholder="Additional notes about this exception..."></textarea>
                    </div>
                </div>
            `;
            
            container.appendChild(exceptionDiv);
            
            // Animate the new exception card
            setTimeout(() => {
                exceptionDiv.style.transition = 'all 0.4s ease';
                exceptionDiv.style.opacity = '1';
                exceptionDiv.style.transform = 'translateY(0)';
            }, 10);
            
            // Hide empty state
            const emptyState = document.getElementById('exceptions_empty_state');
            if (emptyState) {
                emptyState.style.display = 'none';
            }
            
            // Force grid layout
            forceGridLayout();
        }
        
        function removeException(id) {
            const exceptionDiv = document.getElementById('exception_' + id);
            if (exceptionDiv) {
                // Animate removal
                exceptionDiv.style.transition = 'all 0.3s ease';
                exceptionDiv.style.opacity = '0';
                exceptionDiv.style.transform = 'translateY(-20px) scale(0.95)';
                
                setTimeout(() => {
                    exceptionDiv.remove();
                    
                    // Show empty state if no exceptions left
                    const remainingExceptions = document.querySelectorAll('.exception-item');
                    if (remainingExceptions.length === 0) {
                        const emptyState = document.getElementById('exceptions_empty_state');
                        if (emptyState) {
                            emptyState.style.display = 'block';
                        }
                    }
                }, 300);
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
        
        function forceGridLayout() {
            const container = document.getElementById('exceptions_container');
            if (container) {
                container.style.display = 'grid';
                container.style.gridTemplateColumns = 'repeat(auto-fit, minmax(400px, 1fr))';
                container.style.gap = '20px';
            }
            
            // Also ensure exception fields are properly laid out
            const exceptionFields = document.querySelectorAll('.exception-fields');
            exceptionFields.forEach(field => {
                field.style.display = 'flex';
                field.style.flexWrap = 'wrap';
                field.style.gap = '8px';
                field.style.alignItems = 'flex-start';
            });
        }
        
        function getAvailableTentsForException() {
            const tentOptions = [];
            const tentContainer = document.getElementById('tent_options_container');
            const tentDivs = tentContainer.querySelectorAll('.tent-option');
            
            tentDivs.forEach((tentDiv, index) => {
                const tentType = tentDiv.querySelector('select[id^="tent_"][id$="_type"]')?.value;
                const tentBeds = tentDiv.querySelector('select[id^="tent_"][id$="_beds"]')?.value;
                const tentNumberSelect = tentDiv.querySelector('select[id^="tent_"][id$="_number"]');
                
                if (tentType && tentBeds && tentNumberSelect && tentNumberSelect.value) {
                    // Get the tent number from the option text (not the value which is tent ID)
                    const tentNumber = tentNumberSelect.options[tentNumberSelect.selectedIndex].text;
                    const tentId = tentNumberSelect.value; // This is the tent ID for database storage
                    
                    // Get boarding information
                    const halfBoardCheckbox = tentDiv.querySelector('input[id^="tent_"][id$="_half_board"]');
                    const fullBoardCheckbox = tentDiv.querySelector('input[id^="tent_"][id$="_full_board"]');
                    const halfBoardDays = tentDiv.querySelector('input[id^="tent_"][id$="_half_board_days"]');
                    const fullBoardDays = tentDiv.querySelector('input[id^="tent_"][id$="_full_board_days"]');
                    
                    tentOptions.push({
                        id: tentId, // Store tent ID for database
                        number: tentNumber, // Store tent number for display
                        type: tentType,
                        beds: tentBeds,
                        display: `Tent #${tentNumber} - ${tentType}`,
                        halfBoard: halfBoardCheckbox?.checked || false,
                        halfBoardDays: halfBoardDays?.value || 0,
                        fullBoard: fullBoardCheckbox?.checked || false,
                        fullBoardDays: fullBoardDays?.value || 0
                    });
                }
            });
            
            return tentOptions;
        }
        
        function populateExceptionTentOptions(exceptionId) {
            const tentSelect = document.getElementById('exception_tent_' + exceptionId);
            const availableTents = getAvailableTentsForException();
            
            tentSelect.innerHTML = '<option value=""><?php echo t('select_tent', 'Select Tent'); ?></option>';
            availableTents.forEach(tent => {
                const option = document.createElement('option');
                option.value = tent.id;
                option.textContent = tent.display;
                tentSelect.appendChild(option);
            });
        }
        
        function toggleBoardingDays(tentIndex, boardingType) {
            const checkbox = document.getElementById(`tent_${tentIndex}_${boardingType}`);
            const daysGroup = document.getElementById(`tent_${tentIndex}_${boardingType}_days_group`);
            const daysInput = document.getElementById(`tent_${tentIndex}_${boardingType}_days`);
            
            if (checkbox.checked) {
                daysGroup.style.display = 'block';
                // Set default days to reservation nights
                const nights = parseInt(document.getElementById('nights').value) || 1;
                daysInput.value = nights;
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

        function removeTentOption(btn) {
          const card = btn.closest('.tent-option');
          if (!card) return;
          card.parentNode.removeChild(card);
          // Re-number remaining tent cards
          const container = document.getElementById('tent_options_container');
          const remaining = container.querySelectorAll('.tent-option');
          remaining.forEach((el, idx) => {
            el.setAttribute('data-tent', idx+1);
            const h4 = el.querySelector('.tent-header h4');
            if (h4) h4.textContent = '<?php echo t('tent','Tent'); ?> ' + (idx+1);
          });
          document.getElementById('number_of_tents').value = remaining.length;
          updateTentSpecifications();
        }
    </script>
</body>
</html>