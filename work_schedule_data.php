<?php
// Translation loader and function - moved to top
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';
require_once 'includes/translation.php';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$view_mode = $_GET['view'] ?? 'day';
$custom_start = $_GET['custom_start'] ?? '';
$custom_end = $_GET['custom_end'] ?? '';
$search_term = $_GET['search'] ?? '';
$resource_filter = $_GET['filter'] ?? 'all';
$status_filter = $_GET['status_filter'] ?? 'all'; // 'all', 'available', 'partial', 'busy'
$sub_filter = $_GET['sub_filter'] ?? ''; // For sub-filters like owner, tent_type, work_position

// Check if a specific resource is requested (from resources.php)
$specific_resource_type = $_GET['type'] ?? '';
$specific_resource_ids = isset($_GET['id']) ? (is_array($_GET['id']) ? $_GET['id'] : [$_GET['id']]) : [];
$show_specific_resource = !empty($specific_resource_type) && !empty($specific_resource_ids);

function h($s) { return htmlspecialchars($s); }

$cars = $pdo->query("SELECT * FROM cars ORDER BY registration_number")->fetchAll(PDO::FETCH_ASSOC);
$tents = $pdo->query("SELECT * FROM tents ORDER BY tent_type, tent_number")->fetchAll(PDO::FETCH_ASSOC);
$humans = $pdo->query("SELECT * FROM human_resources ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

function getDateRange($date, $mode, $custom_start = '', $custom_end = '') {
    $start = new DateTime($date);
    $end = new DateTime($date);
    switch($mode) {
        case 'day':
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        case 'week':
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

// Ensure $start_date and $end_date are valid dates
if (empty($start_date) || !strtotime($start_date)) {
    $start_date = date('Y-m-d');
}
if (empty($end_date) || !strtotime($end_date)) {
    $end_date = date('Y-m-d');
}

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
        
        // Build searchable string
        $search_blob = mb_strtolower($resource['name']);
        if ($resource['type'] === 'car') {
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

// Build all_resources array
// Note: The availability calculation now properly handles reservations that end at midday on the last day.
// When a reservation ends on a specific day, that day is marked as 'partial' (morning only) rather than 'busy' (full day).
// This allows resources to be available for new reservations starting from the afternoon of the last day.
// The logic also properly handles single-day views where the filtered date is the end date of a reservation.
$all_resources = [];
foreach ($cars as $car) {
    $bookings = getResourceAvailability($pdo, 'car', $car['id'], $start_date, $end_date);
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
        $status = 'busy';
    } elseif ($busy_days > 0 || $partial_days > 0) {
        $status = 'partial';
    } else {
        $status = 'available';
    }
    
    $all_resources[] = [
        'type' => 'car',
        'id' => $car['id'],
        'name' => $car['registration_number'],
        'status' => $status,
        'bookings' => $bookings
    ];
}
foreach ($tents as $tent) {
    $bookings = getResourceAvailability($pdo, 'tent', $tent['id'], $start_date, $end_date);
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
        $status = 'busy';
    } elseif ($busy_days > 0 || $partial_days > 0) {
        $status = 'partial';
    } else {
        $status = 'available';
    }
    
    $all_resources[] = [
        'type' => 'tent',
        'id' => $tent['id'],
        'name' => $tent['tent_number'] . ' (' . $tent['tent_type'] . ')',
        'status' => $status,
        'bookings' => $bookings
    ];
}
foreach ($humans as $human) {
    $bookings = getResourceAvailability($pdo, 'human', $human['id'], $start_date, $end_date);
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
        $status = 'busy';
    } elseif ($busy_days > 0 || $partial_days > 0) {
        $status = 'partial';
    } else {
        $status = 'available';
    }
    
    $all_resources[] = [
        'type' => 'human',
        'id' => $human['id'],
        'name' => $human['full_name'] . ' (' . $human['work_position'] . ')',
        'status' => $status,
        'bookings' => $bookings
    ];
}
if ($show_specific_resource) {
    $filtered_resources = [];
    foreach ($all_resources as $resource) {
        if ($resource['type'] === $specific_resource_type && in_array($resource['id'], $specific_resource_ids)) {
            $filtered_resources[] = $resource;
        }
    }
} else {
    $filtered_resources = filterResources($all_resources, $search_term, $resource_filter, $status_filter, $sub_filter);
}
// Calculate stats for filtered resources only
$filtered_available = 0;
$filtered_partial = 0;
$filtered_busy = 0;

// Sub-filter stats
$sub_filter_stats = [];
if ($resource_filter === 'car' && $sub_filter) {
    $abdelmoula_camp_count = 0;
    $other_count = 0;
    foreach ($filtered_resources as $resource) {
        global $cars;
        foreach ($cars as $car) {
            if ($car['id'] == $resource['id']) {
                $owner = mb_strtolower($car['owner'] ?? '');
                if (strpos($owner, 'abdelmoula') !== false) {
                    $abdelmoula_camp_count++;
                } else {
                    $other_count++;
                }
                break;
            }
        }
    }
    $sub_filter_stats = [
        'abdelmoula_camp' => $abdelmoula_camp_count,
        'other' => $other_count
    ];
} elseif ($resource_filter === 'tent' && $sub_filter) {
    $normal_count = 0;
            $royal_count = 0;
    foreach ($filtered_resources as $resource) {
        global $tents;
        foreach ($tents as $tent) {
            if ($tent['id'] == $resource['id']) {
                $tent_type = mb_strtolower($tent['tent_type'] ?? '');
                if ($tent_type === 'normal') {
                    $normal_count++;
                        } elseif ($tent_type === 'royal') {
            $royal_count++;
                }
                break;
            }
        }
    }
    $sub_filter_stats = [
        'normal' => $normal_count,
                    'royal' => $royal_count
    ];
} elseif ($resource_filter === 'human' && $sub_filter) {
    $guide_count = 0;
    $driver_count = 0;
    foreach ($filtered_resources as $resource) {
        global $humans;
        foreach ($humans as $human) {
            if ($human['id'] == $resource['id']) {
                $work_position = mb_strtolower($human['work_position'] ?? '');
                if ($work_position === 'guide') {
                    $guide_count++;
                } elseif ($work_position === 'driver') {
                    $driver_count++;
                }
                break;
            }
        }
    }
    $sub_filter_stats = [
        'guide' => $guide_count,
        'driver' => $driver_count
    ];
}

foreach ($filtered_resources as $resource) {
    if ($resource['status'] === 'available') $filtered_available++;
    elseif ($resource['status'] === 'partial') $filtered_partial++;
    elseif ($resource['status'] === 'busy') $filtered_busy++;
}
?>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<style>
.stats-summary {
    background: #e0c97f;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 25px;
    text-align: center;
    color: #4a3a00;
}
.stats-summary .stat-number {
    color: #b8860b;
}
.stats-summary .stat-label {
    color: #4a3a00;
}
</style>
<?php
// Translation loader and function
?>
<?php if (!$show_specific_resource): ?>
<div class="stats-summary">
    <h3><?php echo t('resource_overview', 'Resource Overview'); ?></h3>
    <div class="stats-grid">
        <div class="stat-item">
            <div class="stat-number"><?php echo count($filtered_resources); ?></div>
            <div class="stat-label"><?php echo t('filtered_resources', 'Filtered Resources'); ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-number" style="color: #155724;"><?php echo $filtered_available; ?></div>
            <div class="stat-label"><?php echo t('available', 'Available'); ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-number" style="color: #856404;"><?php echo $filtered_partial; ?></div>
            <div class="stat-label"><?php echo t('partially_booked', 'Partially Booked'); ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-number" style="color: #721c24;"><?php echo $filtered_busy; ?></div>
            <div class="stat-label"><?php echo t('fully_booked', 'Fully Booked'); ?></div>
        </div>
    </div>
    
    <?php if (!empty($sub_filter_stats)): ?>
    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(74, 58, 0, 0.2);">
        <h4 style="margin: 0 0 15px 0; color: #4a3a00; font-size: 1rem;"><?php echo t('sub_filter_details', 'Sub-filter Details'); ?></h4>
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));">
            <?php if ($resource_filter === 'car'): ?>
                <div class="stat-item">
                    <div class="stat-number" style="color: #b8860b;"><?php echo $sub_filter_stats['abdelmoula_camp']; ?></div>
                    <div class="stat-label"><?php echo t('abdelmoula_camp', 'Abdelmoula Camp'); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #b8860b;"><?php echo $sub_filter_stats['other']; ?></div>
                    <div class="stat-label"><?php echo t('other', 'Other'); ?></div>
                </div>
            <?php elseif ($resource_filter === 'tent'): ?>
                <div class="stat-item">
                    <div class="stat-number" style="color: #b8860b;"><?php echo $sub_filter_stats['normal']; ?></div>
                    <div class="stat-label"><?php echo t('normal', 'Normal'); ?></div>
                </div>
                <div class="stat-item">
                                    <div class="stat-number" style="color: #b8860b;"><?php echo $sub_filter_stats['royal']; ?></div>
                <div class="stat-label"><?php echo t('royal', 'ROYAL'); ?></div>
                </div>
            <?php elseif ($resource_filter === 'human'): ?>
                <div class="stat-item">
                    <div class="stat-number" style="color: #b8860b;"><?php echo $sub_filter_stats['guide']; ?></div>
                    <div class="stat-label"><?php echo t('guide', 'Guide'); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #b8860b;"><?php echo $sub_filter_stats['driver']; ?></div>
                    <div class="stat-label"><?php echo t('driver', 'Driver'); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if (empty($filtered_resources)): ?>
<div class="no-results">
    <h3><?php echo t('no_resources_found', 'No resources found'); ?></h3>
    <p><?php echo t('try_adjusting_search', 'Try adjusting your search terms or filter criteria.'); ?></p>
</div>
<?php else: ?>
<div class="resources-grid">
    <?php foreach ($filtered_resources as $resource): ?>
    <div class="resource-card">
        <div class="resource-accent accent-<?php echo $resource['type']; ?>"></div>
        <div class="resource-header">
            <span class="resource-icon">
                <?php if ($resource['type'] === 'car'): ?>
                    🚗
                <?php elseif ($resource['type'] === 'tent'): ?>
                    ⛺
                <?php else: ?>
                    👤
                <?php endif; ?>
            </span>
            <div class="resource-title"><?php echo h($resource['name']); ?></div>
            <div class="resource-type type-<?php echo $resource['type']; ?>">
                <?php
                if ($resource['type'] === 'car') {
                    echo t('car', 'Car');
                } elseif ($resource['type'] === 'tent') {
                    echo t('tent', 'Tent');
                } elseif ($resource['type'] === 'human') {
                    // Try to distinguish driver/guide if possible
                    if (stripos($resource['name'], 'driver') !== false || stripos($resource['name'], 'chauffeur') !== false) {
                        echo t('driver', 'Driver');
                    } elseif (stripos($resource['name'], 'guide') !== false) {
                        echo t('guide', 'Guide');
                    } else {
                        echo t('staff', 'Staff');
                    }
                }
                ?>
            </div>
        </div>
        <div class="availability-status status-<?php echo $resource['status']; ?>">
            <?php
            if ($resource['status'] === 'available') {
                echo t('available', 'Available');
            } elseif ($resource['status'] === 'partial') {
                echo t('partial', 'Partial');
            } elseif ($resource['status'] === 'busy') {
                echo t('busy', 'Busy');
            }
            ?>
        </div>
        <div class="resource-schedule">
            <?php if (empty($resource['bookings'])): ?>
                <div class="no-bookings"><?php echo t('no_bookings_in_period', 'No bookings in this period'); ?></div>
            <?php else: ?>
                <?php foreach ($resource['bookings'] as $booking): ?>
                <div class="schedule-item">
                    <div class="guest"><?php echo h($booking['guest_name']); ?></div>
                    <div class="period">
                        <?php echo h($booking['start_date']); ?> <?php echo t('to', 'to'); ?> <?php echo h($booking['end_date']); ?>
                        <br><small><?php echo t('reservation', 'Reservation'); ?> #<?php echo h($booking['reservation_id']); ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div style="margin-top: 15px; text-align: center;">
                <a href="resource_schedule.php?type=<?php echo $resource['type']; ?>&id=<?php echo $resource['id']; ?>" 
                   class="btn btn-small" 
                   style="background: linear-gradient(135deg, #b8860b 0%, #d4af37 100%); color: white; border: none; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 0.9em; font-weight: 600; box-shadow: 0 2px 8px rgba(184, 134, 11, 0.3); transition: all 0.3s ease;">
                    📅 <?php echo t('view_schedule', 'View Schedule'); ?>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?> 