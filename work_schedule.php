<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';
require_once 'includes/translation.php';

$selected_date = $_GET['date'] ?? date('Y-m-d');
$view_mode = $_GET['view'] ?? 'day'; // 'day', 'week', 'month', 'custom'
$custom_start = $_GET['custom_start'] ?? '';
$custom_end = $_GET['custom_end'] ?? '';
$search_term = $_GET['search'] ?? '';
$resource_filter = $_GET['filter'] ?? 'all'; // 'all', 'car', 'tent', 'human'
$status_filter = $_GET['status_filter'] ?? 'all'; // 'all', 'available', 'partial', 'busy'
$sub_filter = $_GET['sub_filter'] ?? ''; // For sub-filters like owner, tent_type, work_position

// Check if a specific resource is requested (from resources.php)
$specific_resource_type = $_GET['type'] ?? '';
$specific_resource_ids = isset($_GET['id']) ? (is_array($_GET['id']) ? $_GET['id'] : [$_GET['id']]) : [];
$show_specific_resource = !empty($specific_resource_type) && !empty($specific_resource_ids);

function h($s) { return htmlspecialchars($s); }

// Fetch all resources
$cars = $pdo->query("SELECT id, registration_number FROM cars ORDER BY registration_number")->fetchAll(PDO::FETCH_ASSOC);
$tents = $pdo->query("SELECT id, tent_number, tent_type FROM tents ORDER BY tent_type, tent_number")->fetchAll(PDO::FETCH_ASSOC);
$humans = $pdo->query("SELECT id, full_name, work_position FROM human_resources ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate date range based on view mode
function getDateRange($date, $mode, $custom_start = '', $custom_end = '') {
    $start = new DateTime($date);
    $end = new DateTime($date);
    
    switch($mode) {
        case 'day':
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        case 'week':
            // Start from selected day, end 6 days later
            $end->modify('+6 days');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        case 'month':
            $start->modify('first day of this month');
            $end->modify('last day of this month');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        case 'custom':
            if ($custom_start && $custom_end) {
                return [date('Y-m-d', strtotime($custom_start)), date('Y-m-d', strtotime($custom_end))];
            } else {
                return [$start->format('Y-m-d'), $end->format('Y-m-d')];
            }
    }
}

list($start_date, $end_date) = getDateRange($selected_date, $view_mode, $custom_start, $custom_end);

// Get availability for a resource
function getResourceAvailability($pdo, $type, $resource_id, $start_date, $end_date) {
    $table_map = [
        'car' => 'reservation_cars',
        'tent' => 'reservation_tents', 
        'human' => 'reservation_humans'
    ];
    
    $table = $table_map[$type];
    $id_field = $type . '_id';
    
    $stmt = $pdo->prepare("
        SELECT rc.start_date, rc.end_date, r.id as reservation_id, g.name as guest_name
        FROM $table rc 
        JOIN reservations r ON rc.reservation_id = r.id 
        JOIN guests g ON r.guest_id = g.id 
        WHERE rc.$id_field = ? 
        AND ((rc.start_date <= ? AND rc.end_date >= ?) 
             OR (rc.start_date <= ? AND rc.end_date >= ?) 
             OR (rc.start_date >= ? AND rc.end_date <= ?))
        ORDER BY rc.start_date
    ");
    
    $stmt->execute([$resource_id, $end_date, $start_date, $end_date, $end_date, $start_date, $end_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate resource availability status considering partial days
// This function properly handles reservations that end at midday on the last day.
// When a reservation ends on a specific day, that day is marked as 'partial' (morning only) 
// rather than 'busy' (full day), allowing resources to be available for new reservations 
// starting from the afternoon of the last day.
function calculateResourceStatus($bookings, $start_date, $end_date) {
    $total_days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
    $busy_days = 0;
    $partial_days = 0;
    
    foreach ($bookings as $booking) {
        $overlap_start = max(strtotime($start_date), strtotime($booking['start_date']));
        $overlap_end = min(strtotime($end_date), strtotime($booking['end_date']));
        
        if ($overlap_start <= $overlap_end) {
            $overlap_days = ($overlap_end - $overlap_start) / (60 * 60 * 24) + 1;
            
            // Check if this booking ends on the overlap end date (last day of overlap)
            $booking_end_date = date('Y-m-d', strtotime($booking['end_date']));
            $overlap_end_date = date('Y-m-d', $overlap_end);
            
            // A day is partial if:
            // 1. The booking ends on that day (morning only, available afternoon)
            // 2. AND it's not the first day of the overlap (first day is fully busy)
            // 3. OR if we're viewing a single day and it's the end date of a booking
            if ($booking_end_date === $overlap_end_date && 
                ($overlap_end_date !== date('Y-m-d', $overlap_start) || 
                 ($start_date === $end_date && $booking_end_date === $start_date))) {
                // This booking ends on the overlap end date, so it's only partially busy for that day
                $full_days = max(0, $overlap_days - 1);
                $busy_days += $full_days;
                $partial_days += 1;
            } else {
                // This booking covers the full day(s)
                $busy_days += $overlap_days;
            }
        }
    }
    
    // Determine status considering partial days
    if ($busy_days >= $total_days) {
        return 'busy';
    } elseif ($busy_days > 0 || $partial_days > 0) {
        return 'partial';
    } else {
        return 'available';
    }
}

// Filter resources based on search term, type filter, and sub-filter
function filterResources($resources, $search_term, $resource_filter, $status_filter = 'all', $sub_filter = '') {
    $filtered = [];
    $search_term = trim(mb_strtolower($search_term));
    
    foreach ($resources as $resource) {
        // Apply type filter
        if ($resource_filter !== 'all' && $resource['type'] !== $resource_filter) {
            continue;
        }
        
        // Apply sub-filter
        if ($sub_filter && $resource_filter !== 'all') {
            $sub_filter_passed = false;
            
            if ($resource['type'] === 'car') {
                global $cars;
                foreach ($cars as $car) {
                    if ($car['id'] == $resource['id']) {
                        $owner = mb_strtolower($car['owner'] ?? '');
                        if ($sub_filter === 'abdelmoula_camp' && strpos($owner, 'abdelmoula') !== false) {
                            $sub_filter_passed = true;
                        } elseif ($sub_filter === 'other' && strpos($owner, 'abdelmoula') === false) {
                            $sub_filter_passed = true;
                        }
                        break;
                    }
                }
            } elseif ($resource['type'] === 'tent') {
                global $tents;
                foreach ($tents as $tent) {
                    if ($tent['id'] == $resource['id']) {
                        $tent_type = mb_strtolower($tent['tent_type'] ?? '');
                        if ($sub_filter === $tent_type) {
                            $sub_filter_passed = true;
                        }
                        break;
                    }
                }
            } elseif ($resource['type'] === 'human') {
                global $humans;
                foreach ($humans as $human) {
                    if ($human['id'] == $resource['id']) {
                        $work_position = mb_strtolower($human['work_position'] ?? '');
                        if ($sub_filter === $work_position) {
                            $sub_filter_passed = true;
                        }
                        break;
                    }
                }
            }
            
            if (!$sub_filter_passed) {
                continue;
            }
        }
        
        // Apply status filter
        if ($status_filter !== 'all' && mb_strtolower($resource['status']) !== $status_filter) {
            continue;
        }
        
        // Build a searchable string for each resource
        $search_blob = mb_strtolower($resource['name']);
        if ($resource['type'] === 'car') {
            // Try to get more car details from the DB if needed
            global $cars;
            foreach ($cars as $car) {
                if ($car['id'] == $resource['id']) {
                    $search_blob .= ' ' . mb_strtolower($car['car_type'] ?? '');
                    $search_blob .= ' ' . mb_strtolower($car['owner'] ?? '');
                    $search_blob .= ' ' . mb_strtolower($car['number_of_places'] ?? '');
                }
            }
        } elseif ($resource['type'] === 'tent') {
            global $tents;
            foreach ($tents as $tent) {
                if ($tent['id'] == $resource['id']) {
                    $search_blob .= ' ' . mb_strtolower($tent['tent_type'] ?? '');
                    $search_blob .= ' ' . mb_strtolower($tent['tent_number'] ?? '');
                }
            }
        } elseif ($resource['type'] === 'human') {
            global $humans;
            foreach ($humans as $human) {
                if ($human['id'] == $resource['id']) {
                    $search_blob .= ' ' . mb_strtolower($human['full_name'] ?? '');
                    $search_blob .= ' ' . mb_strtolower($human['work_position'] ?? '');
                    $search_blob .= ' ' . mb_strtolower($human['phone'] ?? '');
                    $search_blob .= ' ' . mb_strtolower($human['id_number'] ?? '');
                }
            }
        }
        // Add status and type keywords
        $search_blob .= ' ' . mb_strtolower($resource['status']);
        $search_blob .= ' ' . mb_strtolower($resource['type']);
        if ($resource['type'] === 'human' && strpos($search_blob, 'driver') !== false) {
            $search_blob .= ' staff';
        }
        // Apply search filter
        if ($search_term && strpos($search_blob, $search_term) === false) {
            continue;
        }
        $filtered[] = $resource;
    }
    return $filtered;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo t('resource_availability_schedule', 'Resource Availability Schedule'); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #333;
            line-height: 1.6;
            background: url("https://cdn.pixabay.com/photo/2016/09/08/13/58/desert-1654439_1280.jpg") no-repeat center center fixed;
            background-size: cover;
        }
        
        .schedule-header {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .schedule-header h1 {
            margin: 0 0 8px 0;
            font-size: 1.75rem;
            color: #212529;
            font-weight: 600;
        }
        
        .schedule-header p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95rem;
        }
        
        .schedule-controls {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .controls-row {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        
        .date-navigation {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        
        .date-navigation button {
            background: #b8860b;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.85rem;
            transition: background-color 0.2s;
        }
        
        .date-navigation button:hover {
            background: #d4af37;
        }
        
        .date-navigation input {
            padding: 6px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        .view-toggle {
            display: flex;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .view-toggle button {
            padding: 8px 16px;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .view-toggle button.active {
            background: #b8860b;
            color: white;
        }
        
        .view-toggle button:hover:not(.active) {
            background: #e9ecef;
        }
        
        .selected-period {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .selected-period h3 {
            margin: 0 0 8px 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #212529;
        }
        
        .selected-period .period-details {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .search-filters {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .search-filters h3 {
            margin: 0 0 16px 0;
            color: #212529;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .filters-row {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-box {
            flex: 1;
            min-width: 250px;
        }
        
        .search-box input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #b8860b;
            box-shadow: 0 0 0 2px rgba(184,134,11,0.25);
        }
        
        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 6px 12px;
            border: 1px solid #dee2e6;
            background: white;
            color: #495057;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .filter-btn:hover {
            border-color: #b8860b;
            color: #b8860b;
        }
        
        .filter-btn.active {
            background: #b8860b;
            color: white;
            border-color: #b8860b;
        }
        
        .filter-btn .count {
            background: rgba(255,255,255,0.2);
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        
        /* Sub-filter button styling */
        .sub-filter-btn {
            padding: 6px 12px;
            border: 1px solid #dee2e6;
            background: white;
            color: #495057;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .sub-filter-btn:hover {
            border-color: #b8860b;
            color: #b8860b;
        }
        
        .sub-filter-btn.active {
            background: #b8860b;
            color: white;
            border-color: #b8860b;
        }
        
        .resources-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .resource-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow: hidden;
            transition: box-shadow 0.2s;
            position: relative;
            min-height: 180px;
            display: flex;
            flex-direction: column;
        }
        
        .resource-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .resource-accent {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
        }
        
        .accent-car { background: #b8860b; }
        .accent-tent { background: #d4af37; }
        .accent-human { background: #daa520; }
        
        .resource-header {
            padding: 16px 20px 12px 24px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-start;
            align-items: center;
            background: #f8f9fa;
            gap: 12px;
        }
        
        .resource-icon {
            font-size: 1.5rem;
            margin-right: 8px;
            opacity: 0.8;
        }
        
        .resource-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #212529;
        }
        
        .resource-type {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: auto;
        }
        
        .type-car { background: #fff3cd; color: #856404; }
        .type-tent { background: #d1ecf1; color: #0c5460; }
        .type-human { background: #d4edda; color: #155724; }
        
        .availability-status {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
            margin: 12px 20px 0 28px;
            width: fit-content;
        }
        
        .status-available { background: #d4edda; color: #155724; }
        .status-busy { background: #f8d7da; color: #721c24; }
        .status-partial { background: #fff3cd; color: #856404; }
        
        .resource-schedule {
            padding: 0 20px 16px 28px;
        }
        
        .schedule-item {
            background: #f8f9fa;
            padding: 8px 12px;
            margin: 6px 0;
            border-radius: 4px;
            border-left: 3px solid #b8860b;
        }
        
        .schedule-item .guest {
            font-weight: 600;
            color: #212529;
            font-size: 0.9rem;
        }
        
        .schedule-item .period {
            color: #6c757d;
            font-size: 0.8rem;
            margin-top: 2px;
        }
        
        .no-bookings {
            text-align: center;
            color: #6c757d;
            font-style: italic;
            padding: 16px;
            font-size: 0.9rem;
        }
        
        .date-display {
            font-size: 1.1rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 16px;
        }
        
        .stats-summary {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .stats-summary h3 {
            margin: 0 0 16px 0;
            color: #212529;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #b8860b;
            display: block;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .no-results {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .no-results h3 {
            margin-bottom: 8px;
            color: #495057;
            font-size: 1.2rem;
        }
        
        .no-results p {
            font-size: 0.9rem;
        }
        
        /* Ensure container properly contains all content including AJAX-loaded resources */
        .container {
            background: rgba(255, 255, 255, 0.93) !important;
            border-radius: 12px !important;
            padding: 2rem 2.5rem !important;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1) !important;
            margin: 0 auto !important;
            max-width: 1800px !important;
        }
        
        /* Ensure resources grid is properly contained */
        .resources-grid {
            background: transparent !important;
        }
        
        @media (max-width: 768px) {
            .controls-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .date-navigation {
                justify-content: center;
            }
            
            .view-toggle {
                justify-content: center;
            }
            
            .filters-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                min-width: auto;
            }
            
            .resources-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script>
    function changeView(view) {
        const currentDate = document.getElementById('selected_date').value;
        let url = new URL(window.location.href);
        url.searchParams.set('view', view);
        url.searchParams.set('date', currentDate);
        if(view !== 'custom') {
            url.searchParams.delete('custom_start');
            url.searchParams.delete('custom_end');
        }
        window.location.href = url.toString();
    }
    
    function changeDate(direction) {
        const currentDate = new Date(document.getElementById('selected_date').value);
        const view = '<?php echo $view_mode; ?>';
        const searchTerm = document.getElementById('search_input').value;
        const filter = document.querySelector('.filter-btn.active').dataset.filter;
        
        switch(view) {
            case 'day':
                currentDate.setDate(currentDate.getDate() + direction);
                break;
            case 'week':
                currentDate.setDate(currentDate.getDate() + (direction * 7));
                break;
            case 'month':
                currentDate.setMonth(currentDate.getMonth() + direction);
                break;
        }
        
        const newDate = currentDate.toISOString().split('T')[0];
        window.location.href = `work_schedule.php?view=${view}&date=${newDate}&search=${encodeURIComponent(searchTerm)}&filter=${filter}`;
    }
    
    function goToToday() {
        const today = new Date().toISOString().split('T')[0];
        const view = '<?php echo $view_mode; ?>';
        const searchTerm = document.getElementById('search_input').value;
        const filter = document.querySelector('.filter-btn.active').dataset.filter;
        window.location.href = `work_schedule.php?view=${view}&date=${today}&search=${encodeURIComponent(searchTerm)}&filter=${filter}`;
    }
    
    function setFilter(filter) {
        // Update the active resource type filter button
        document.querySelectorAll('.filter-btn[data-filter]').forEach(btn => btn.classList.remove('active'));
        document.querySelector(`[data-filter="${filter}"]`).classList.add('active');
        
        // Load content with current parameters (which will include custom period if set)
        loadScheduleContent(getCurrentParams());
    }
    
    function performSearch() {
        // Load content with current parameters (which will include custom period if set)
        loadScheduleContent(getCurrentParams());
    }
    
    function setStatusFilter(status) {
        // Update the active status filter button
        document.querySelectorAll('.filter-btn[data-status]').forEach(btn => btn.classList.remove('active'));
        document.querySelector(`[data-status="${status}"]`).classList.add('active');
        
        // Load content with current parameters (which will include custom period if set)
        loadScheduleContent(getCurrentParams());
    }
    
    function setSubFilter(subFilter) {
        // Update the active sub-filter button
        document.querySelectorAll('.sub-filter-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector(`[data-sub-filter="${subFilter}"]`).classList.add('active');
        
        // Load content with current parameters
        loadScheduleContent(getCurrentParams());
    }
    
    // Handle Enter key in search input
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('search_input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
    });
    </script>
</head>
<body class="dashboard-page">
<nav class="navbar">
    <div class="nav-container">
        <div class="nav-brand">
            <img src="assets/images/logo.png" alt="ABDELMOULA CAMP" class="nav-logo">
            <h1><?php echo t('ABDELMOULA CAMP', 'ABDELMOULA CAMP'); ?></h1>
        </div>
        <div class="nav-links">
            <a href="dashboard.php"><?php echo t('dashboard', 'Dashboard'); ?></a>
            <a href="add_reservation.php"><?php echo t('add_reservation', 'Add Reservation'); ?></a>
            <a href="guests.php"><?php echo t('guests', 'Guests'); ?></a>
            <a href="services.php"><?php echo t('services', 'Services'); ?></a>
            <a href="resources.php" class="active"><?php echo t('resources', 'Resources'); ?></a>
            <a href="users.php"><?php echo t('users', 'Users'); ?></a>
            <a href="TARIF.php"><?php echo t('tarif', 'TARIF'); ?></a>
            <a href="logout.php"><?php echo t('logout', 'Logout'); ?></a>
        </div>
    </div>
</nav>

<div class="container">
    <a href="resources.php" class="btn btn-secondary" style="margin-bottom:20px;">&larr; <?php echo t('back_to_resources', 'Back to Resources'); ?></a>
    
    <div id="schedule-overview"></div>
    
    <div class="selected-period-row schedule-period-card">
        <div class="schedule-controls-horizontal">
            <div class="date-and-view-row">
                <div class="date-navigation">
                    <button class="btn btn-small" onclick="changeDate(-1)" title="<?php echo t('previous', 'Previous'); ?>"><span>&larr;</span></button>
                    <input type="date" id="selected_date" value="<?php echo h($selected_date); ?>">
                    <button class="btn btn-small" onclick="changeDate(1)" title="<?php echo t('next', 'Next'); ?>"><span>&rarr;</span></button>
                    <button class="btn btn-small" onclick="goToToday()"><?php echo t('today', 'Today'); ?></button>
                </div>
                <div class="view-toggle">
                    <button class="<?php echo $view_mode === 'day' ? 'active' : ''; ?>" onclick="changeView('day')"><?php echo t('day', 'Day'); ?></button>
                    <button class="<?php echo $view_mode === 'week' ? 'active' : ''; ?>" onclick="changeView('week')"><?php echo t('week', 'Week'); ?></button>
                    <button class="<?php echo $view_mode === 'month' ? 'active' : ''; ?>" onclick="changeView('month')"><?php echo t('month', 'Month'); ?></button>
                    <button class="<?php echo $view_mode === 'custom' ? 'active' : ''; ?>" onclick="changeView('custom')"><?php echo t('custom', 'Custom'); ?></button>
                </div>
                <div class="period-details-inline" style="margin-left:24px; font-size:1rem; color:#6c757d; font-weight:500;">
                    <?php 
                    if ($view_mode === 'day') {
                        echo '<strong>' . t('single_day', 'Single Day') . ':</strong> ' . date('l, F j, Y', strtotime($selected_date));
                    } elseif ($view_mode === 'week') {
                        echo '<strong>' . t('week', 'Week') . ':</strong> ' . date('F j', strtotime($start_date)) . ' ' . t('to', 'to') . ' ' . date('F j, Y', strtotime($end_date));
                        echo ' (7 ' . t('days', 'days') . ')';
                    } elseif ($view_mode === 'month') {
                        echo '<strong>' . t('month', 'Month') . ':</strong> ' . date('F Y', strtotime($selected_date));
                        $days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
                        echo ' (' . $days . ' ' . t('days', 'days') . ')';
                    } elseif ($view_mode === 'custom') {
                        echo '<strong>' . t('custom_range', 'Custom Range') . ':</strong> ' . date('F j, Y', strtotime($start_date)) . ' ' . t('to', 'to') . ' ' . date('F j, Y', strtotime($end_date));
                        $days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
                        echo ' (' . $days . ' ' . t('days', 'days') . ')';
                    }
                    ?>
                </div>
            </div>
            <div id="custom-period-picker" style="margin-top:8px; <?php echo $view_mode === 'custom' ? '' : 'display:none;'; ?>">
                <form id="customPeriodForm" style="display:flex; gap:8px; align-items:center;">
                    <label for="custom_start" style="font-size:0.95rem;"><?php echo t('from', 'From'); ?></label>
                    <input type="date" id="custom_start" name="custom_start" value="<?php echo h($custom_start ?: $selected_date); ?>">
                    <label for="custom_end" style="font-size:0.95rem;"><?php echo t('to', 'To'); ?></label>
                    <input type="date" id="custom_end" name="custom_end" value="<?php echo h($custom_end ?: $selected_date); ?>">
                    <button type="submit" class="btn btn-small"><?php echo t('apply', 'Apply'); ?></button>
                </form>
            </div>
        </div>
    </div>
    
    <style>
    /* Ensure container properly contains all content including AJAX-loaded resources */
    .container {
        background: rgba(255, 255, 255, 0.93) !important;
        border-radius: 12px !important;
        padding: 2rem 2.5rem !important;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1) !important;
        margin: 20px auto !important;
        max-width: 1800px !important;
        position: relative !important;
        z-index: 1 !important;
        min-height: auto !important;
        height: auto !important;
        overflow: visible !important;
    }
    
    /* Ensure all content inside container is properly styled */
    .container > * {
        background: transparent !important;
    }
    
    /* Override any conflicting styles from AJAX content */
    .schedule-content-wrapper {
        background: transparent !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    /* Ensure schedule-content is properly contained */
    #schedule-content {
        background: transparent !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        position: relative !important;
        z-index: 1 !important;
    }
    
    /* Ensure all content inside schedule-content is properly contained */
    #schedule-content > * {
        background: transparent !important;
        position: relative !important;
        z-index: 1 !important;
    }
    
    /* Force container to be visible and properly styled */
    .container {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    
    /* Ensure resources grid is properly contained */
    .resources-grid {
        background: transparent !important;
        position: relative !important;
        z-index: 1 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    /* Ensure resource cards are properly contained */
    .resource-card {
        background: white !important;
        position: relative !important;
        z-index: 1 !important;
    }
    
    /* Ensure body has proper background */
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        color: #333;
        line-height: 1.6;
        background: url("https://cdn.pixabay.com/photo/2016/09/08/13/58/desert-1654439_1280.jpg") no-repeat center center fixed;
        background-size: cover;
    }
    
    .selected-period-row.schedule-period-card {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        padding: 24px 32px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 0;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    .schedule-controls-horizontal {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0;
        min-width: 320px;
        width: 100%;
    }
    .date-and-view-row {
        display: flex;
        align-items: center;
        gap: 16px;
        width: 100%;
    }
    @media (max-width: 900px) {
        .selected-period-row.schedule-period-card {
            flex-direction: column;
            align-items: stretch;
            padding: 16px 8px;
            gap: 16px;
        }
        .schedule-controls-horizontal {
            align-items: stretch;
            min-width: 0;
        }
        .date-and-view-row {
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
        }
    }
    </style>
    
    <?php if (!$show_specific_resource): ?>
    <div class="search-filters">
        <h3><?php echo t('search_and_filter_resources', 'Search & Filter Resources'); ?></h3>
        <div class="filters-row">
            <div class="search-box">
                <input type="text" id="search_input" placeholder="<?php echo t('search_resources_placeholder', 'Search resources by name, number, or type...'); ?>" value="<?php echo h($search_term); ?>">
            </div>
            <div class="filter-buttons">
                <?php
                $car_count = count($cars);
                $tent_count = count($tents);
                $human_count = count($humans);
                $total_count = $car_count + $tent_count + $human_count;
                ?>
                <button class="filter-btn <?php echo $resource_filter === 'all' ? 'active' : ''; ?>" data-filter="all" onclick="setFilter('all')">
                    <?php echo t('all', 'All'); ?> <span class="count"><?php echo $total_count; ?></span>
                </button>
                <button class="filter-btn <?php echo $resource_filter === 'car' ? 'active' : ''; ?>" data-filter="car" onclick="setFilter('car')">
                    <?php echo t('cars', 'Cars'); ?> <span class="count"><?php echo $car_count; ?></span>
                </button>
                <button class="filter-btn <?php echo $resource_filter === 'tent' ? 'active' : ''; ?>" data-filter="tent" onclick="setFilter('tent')">
                    <?php echo t('tents', 'Tents'); ?> <span class="count"><?php echo $tent_count; ?></span>
                </button>
                <button class="filter-btn <?php echo $resource_filter === 'human' ? 'active' : ''; ?>" data-filter="human" onclick="setFilter('human')">
                    <?php echo t('staff', 'Staff'); ?> <span class="count"><?php echo $human_count; ?></span>
                </button>
            </div>
            <div class="filter-buttons" style="margin-left:16px;">
                <button class="filter-btn <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all" onclick="setStatusFilter('all')">
                    <?php echo t('all_status', 'All Status'); ?>
                </button>
                <button class="filter-btn <?php echo $status_filter === 'available' ? 'active' : ''; ?>" data-status="available" onclick="setStatusFilter('available')">
                    <?php echo t('available', 'Available'); ?>
                </button>
                <button class="filter-btn <?php echo $status_filter === 'partial' ? 'active' : ''; ?>" data-status="partial" onclick="setStatusFilter('partial')">
                    <?php echo t('partial', 'Partial'); ?>
                </button>
                <button class="filter-btn <?php echo $status_filter === 'busy' ? 'active' : ''; ?>" data-status="busy" onclick="setStatusFilter('busy')">
                    <?php echo t('busy', 'Busy'); ?>
                </button>
            </div>
        </div>
        
        <!-- Sub-filter section -->
        <div id="sub-filter-section" style="margin-top: 16px; <?php echo $resource_filter === 'all' ? 'display: none;' : ''; ?>">
            <div class="filters-row">
                <div style="font-weight: 600; color: #495057; margin-right: 16px;">
                    <?php echo t('sub_filter', 'Sub-filter'); ?>:
                </div>
                
                <!-- Car sub-filters -->
                <div id="car-sub-filters" class="filter-buttons" style="<?php echo $resource_filter === 'car' ? '' : 'display: none;'; ?>">
                    <button class="sub-filter-btn <?php echo $sub_filter === '' ? 'active' : ''; ?>" data-sub-filter="" onclick="setSubFilter('')">
                        <?php echo t('all_cars', 'All Cars'); ?>
                    </button>
                    <button class="sub-filter-btn <?php echo $sub_filter === 'abdelmoula_camp' ? 'active' : ''; ?>" data-sub-filter="abdelmoula_camp" onclick="setSubFilter('abdelmoula_camp')">
                        <?php echo t('abdelmoula_camp', 'Abdelmoula Camp'); ?>
                    </button>
                    <button class="sub-filter-btn <?php echo $sub_filter === 'other' ? 'active' : ''; ?>" data-sub-filter="other" onclick="setSubFilter('other')">
                        <?php echo t('other', 'Other'); ?>
                    </button>
                </div>
                
                <!-- Tent sub-filters -->
                <div id="tent-sub-filters" class="filter-buttons" style="<?php echo $resource_filter === 'tent' ? '' : 'display: none;'; ?>">
                    <button class="sub-filter-btn <?php echo $sub_filter === '' ? 'active' : ''; ?>" data-sub-filter="" onclick="setSubFilter('')">
                        <?php echo t('all_tents', 'All Tents'); ?>
                    </button>
                    <button class="sub-filter-btn <?php echo $sub_filter === 'normal' ? 'active' : ''; ?>" data-sub-filter="normal" onclick="setSubFilter('normal')">
                        <?php echo t('normal', 'Normal'); ?>
                    </button>
                                    <button class="sub-filter-btn <?php echo $sub_filter === 'royal' ? 'active' : ''; ?>" data-sub-filter="royal" onclick="setSubFilter('royal')">
                    <?php echo t('royal', 'ROYAL'); ?>
                </button>
                </div>
                
                <!-- Staff sub-filters -->
                <div id="staff-sub-filters" class="filter-buttons" style="<?php echo $resource_filter === 'human' ? '' : 'display: none;'; ?>">
                    <button class="sub-filter-btn <?php echo $sub_filter === '' ? 'active' : ''; ?>" data-sub-filter="" onclick="setSubFilter('')">
                        <?php echo t('all_staff', 'All Staff'); ?>
                    </button>
                    <button class="sub-filter-btn <?php echo $sub_filter === 'guide' ? 'active' : ''; ?>" data-sub-filter="guide" onclick="setSubFilter('guide')">
                        <?php echo t('guide', 'Guide'); ?>
                    </button>
                    <button class="sub-filter-btn <?php echo $sub_filter === 'driver' ? 'active' : ''; ?>" data-sub-filter="driver" onclick="setSubFilter('driver')">
                        <?php echo t('driver', 'Driver'); ?>
                    </button>
                </div>
            </div>
        </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div id="schedule-content">
        <!-- AJAX-loaded content will appear here -->
    </div>
</div>

<script>
function loadScheduleContent(params) {
    const container = document.getElementById('schedule-content');
    const overview = document.getElementById('schedule-overview');
    container.innerHTML = '<div style="text-align:center;padding:40px;">Loading...</div>';
    fetch('work_schedule_data.php?' + params)
        .then(res => res.text())
        .then(html => {
            // Split out the overview if present
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const stats = tempDiv.querySelector('.stats-summary');
            if (stats) {
                overview.innerHTML = '';
                overview.appendChild(stats);
                stats.scrollIntoView({behavior:'smooth',block:'nearest'});
            } else {
                overview.innerHTML = '';
            }
            // Remove the overview from the main content
            const statsInContent = tempDiv.querySelector('.stats-summary');
            if (statsInContent) statsInContent.remove();
            
            // Load content directly without additional wrapper
            container.innerHTML = tempDiv.innerHTML;
        });
}

function getCurrentParams() {
    const date = document.getElementById('selected_date').value;
    const view = document.querySelector('.view-toggle button.active').getAttribute('onclick').match(/'(.*?)'/)[1];
    const search = document.getElementById('search_input') ? document.getElementById('search_input').value : '';
    
    // Get resource type filter (cars, tents, staff)
    const resourceFilterBtn = document.querySelector('.filter-btn.active[data-filter]');
    const resourceFilter = resourceFilterBtn ? resourceFilterBtn.dataset.filter : 'all';
    
    // Get status filter (available, partial, busy)
    const statusFilterBtn = document.querySelector('.filter-btn.active[data-status]');
    const statusFilter = statusFilterBtn ? statusFilterBtn.dataset.status : 'all';
    
    // Get sub-filter
    const subFilterBtn = document.querySelector('.sub-filter-btn.active');
    const subFilter = subFilterBtn ? subFilterBtn.dataset.subFilter : '';
    
    const url = new URL(window.location.href);
    let params = `date=${encodeURIComponent(date)}&view=${encodeURIComponent(view)}&search=${encodeURIComponent(search)}&filter=${encodeURIComponent(resourceFilter)}&status_filter=${encodeURIComponent(statusFilter)}&sub_filter=${encodeURIComponent(subFilter)}`;
    
    // If specific resource, preserve type/id
    if (url.searchParams.get('type')) {
        params += `&type=${encodeURIComponent(url.searchParams.get('type'))}`;
        const ids = url.searchParams.getAll('id[]');
        if (ids.length > 0) {
            ids.forEach(id => {
                params += `&id[]=${encodeURIComponent(id)}`;
            });
        }
    }
    
    // Always preserve custom_start and custom_end if they exist in URL, regardless of current view
    const customStart = url.searchParams.get('custom_start');
    const customEnd = url.searchParams.get('custom_end');
    if (customStart && customEnd) {
        params += `&custom_start=${encodeURIComponent(customStart)}&custom_end=${encodeURIComponent(customEnd)}`;
    }
    
    return params;
}

document.addEventListener('DOMContentLoaded', function() {
    // Initial load
    loadScheduleContent(getCurrentParams());
    // Date change
    document.getElementById('selected_date').addEventListener('change', function() {
        loadScheduleContent(getCurrentParams());
    });
    // View change
    document.querySelectorAll('.view-toggle button').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.view-toggle button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            loadScheduleContent(getCurrentParams());
        });
    });
    // Search
    const searchInput = document.getElementById('search_input');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            loadScheduleContent(getCurrentParams());
        });
    }
    // Resource type filter (cars, tents, staff)
    document.querySelectorAll('.filter-btn[data-filter]').forEach(btn => {
        btn.addEventListener('click', function() {
            // Only remove active class from resource type filter buttons
            document.querySelectorAll('.filter-btn[data-filter]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            // Show/hide sub-filter section based on selected resource type
            const subFilterSection = document.getElementById('sub-filter-section');
            const carSubFilters = document.getElementById('car-sub-filters');
            const tentSubFilters = document.getElementById('tent-sub-filters');
            const staffSubFilters = document.getElementById('staff-sub-filters');
            
            if (btn.dataset.filter === 'all') {
                subFilterSection.style.display = 'none';
            } else {
                subFilterSection.style.display = 'block';
                
                // Hide all sub-filter groups
                carSubFilters.style.display = 'none';
                tentSubFilters.style.display = 'none';
                staffSubFilters.style.display = 'none';
                
                // Show the appropriate sub-filter group
                if (btn.dataset.filter === 'car') {
                    carSubFilters.style.display = 'flex';
                } else if (btn.dataset.filter === 'tent') {
                    tentSubFilters.style.display = 'flex';
                } else if (btn.dataset.filter === 'human') {
                    staffSubFilters.style.display = 'flex';
                }
            }
            
            // Reset sub-filter to "all" when changing resource type
            document.querySelectorAll('.sub-filter-btn').forEach(subBtn => subBtn.classList.remove('active'));
            document.querySelector('.sub-filter-btn[data-sub-filter=""]').classList.add('active');
            
            loadScheduleContent(getCurrentParams());
        });
    });
    
    // Status filter (available, partial, busy)
    document.querySelectorAll('.filter-btn[data-status]').forEach(btn => {
        btn.addEventListener('click', function() {
            // Only remove active class from status filter buttons
            document.querySelectorAll('.filter-btn[data-status]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            loadScheduleContent(getCurrentParams());
        });
    });

    const customPeriodForm = document.getElementById('customPeriodForm');
    if(customPeriodForm) {
        customPeriodForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const customStart = document.getElementById('custom_start').value;
            const customEnd = document.getElementById('custom_end').value;
            let url = new URL(window.location.href);
            url.searchParams.set('view', 'custom');
            url.searchParams.set('custom_start', customStart);
            url.searchParams.set('custom_end', customEnd);
            url.searchParams.set('date', customStart);
            window.location.href = url.toString();
        });
    }
    // Show/hide custom picker
    document.querySelectorAll('.view-toggle button').forEach(btn => {
        btn.addEventListener('click', function() {
            setTimeout(function() {
                document.getElementById('custom-period-picker').style.display = btn.textContent === '<?php echo t('custom', 'Custom'); ?>' ? '' : 'none';
            }, 10);
        });
    });
});
</script>
</body>
</html> 