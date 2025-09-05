<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';

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

// Check if logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$selected_date = $_GET['date'] ?? date('Y-m-d');
$view_mode = $_GET['view'] ?? 'day'; // 'day', 'week', 'month', 'custom'
$custom_start = $_GET['custom_start'] ?? '';
$custom_end = $_GET['custom_end'] ?? '';

// Get resource details
$resource_type = $_GET['type'] ?? '';
$resource_id = $_GET['id'] ?? '';

if (empty($resource_type) || empty($resource_id)) {
    header('Location: resources.php');
    exit();
}

// Get resource information
$resource_info = null;
switch ($resource_type) {
    case 'car':
        $stmt = $pdo->prepare("SELECT id, registration_number, car_type, owner, number_of_places FROM cars WHERE id = ?");
        $stmt->execute([$resource_id]);
        $resource_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($resource_info) {
            $resource_info['name'] = $resource_info['registration_number'];
            $resource_info['type'] = 'car';
        }
        break;
    case 'tent':
        $stmt = $pdo->prepare("SELECT id, tent_number, tent_type FROM tents WHERE id = ?");
        $stmt->execute([$resource_id]);
        $resource_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($resource_info) {
            $resource_info['name'] = $resource_info['tent_number'];
            $resource_info['type'] = 'tent';
        }
        break;
    case 'human':
        $stmt = $pdo->prepare("SELECT id, full_name, work_position, phone, id_number FROM human_resources WHERE id = ?");
        $stmt->execute([$resource_id]);
        $resource_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($resource_info) {
            $resource_info['name'] = $resource_info['full_name'];
            $resource_info['type'] = 'human';
        }
        break;
}

if (!$resource_info) {
    header('Location: resources.php');
    exit();
}

// Calculate date range based on view mode
function getDateRange($date, $mode, $custom_start = '', $custom_end = '') {
    if ($mode === 'custom' && $custom_start && $custom_end) {
        return [$custom_start, $custom_end];
    }
    
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
        default:
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
}

list($start_date, $end_date) = getDateRange($selected_date, $view_mode, $custom_start, $custom_end);

// Get availability for the specific resource
function getResourceAvailability($pdo, $type, $resource_id, $start_date, $end_date) {
    $table_map = [
        'car' => 'reservation_cars',
        'tent' => 'reservation_tents', 
        'human' => 'reservation_humans'
    ];
    
    $table = $table_map[$type];
    $id_field = $type . '_id';
    
    $stmt = $pdo->prepare("
        SELECT rc.start_date, rc.end_date, r.id as reservation_id, g.name as guest_name,
               r.check_in_date, r.check_out_date, r.reservation_status, r.payment_status
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

$availability = getResourceAvailability($pdo, $resource_type, $resource_id, $start_date, $end_date);

function h($s) { return htmlspecialchars($s); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($resource_info['name']); ?> - <?php echo t('schedule', 'Schedule'); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            min-height: 100vh;
            background: url("https://cdn.pixabay.com/photo/2016/09/08/13/58/desert-1654439_1280.jpg")
                no-repeat center center fixed;
            background-size: cover;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .resource-header {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .resource-title {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .resource-icon {
            width: 48px;
            height: 48px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            font-weight: 500;
        }
        
        .icon-car { background: #b8860b; }
        .icon-tent { background: #d4af37; }
        .icon-human { background: #daa520; }
        
        .resource-details h1 {
            margin: 0;
            font-size: 1.75rem;
            color: #212529;
            font-weight: 600;
        }
        
        .resource-meta {
            color: #6c757d;
            font-size: 0.95rem;
            margin-top: 4px;
        }
        
        .back-btn {
            background: #6c757d;
            color: white;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            font-size: 0.9rem;
            transition: background-color 0.2s;
            border: none;
        }
        
        .back-btn:hover {
            background: #5a6268;
            color: white;
            text-decoration: none;
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
        
        .period-selector {
            display: flex;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .period-btn {
            padding: 8px 16px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 500;
            color: #495057;
            transition: all 0.2s;
            font-size: 0.9rem;
        }
        
        .period-btn.active {
            background: #b8860b;
            color: white;
        }
        
        .period-btn:hover:not(.active) {
            background: #e9ecef;
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
        
        .nav-btn {
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
        
        .nav-btn:hover {
            background: #d4af37;
        }
        
        .date-display {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            min-width: 180px;
            text-align: center;
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
        
        .custom-date-inputs {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        
        .custom-date-inputs input {
            padding: 6px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }
        
        .custom-date-inputs input:focus {
            outline: none;
            border-color: #b8860b;
            box-shadow: 0 0 0 2px rgba(184,134,11,0.25);
        }
        
        .schedule-content {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .schedule-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .schedule-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #212529;
        }
        
        .availability-summary {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .summary-item {
            text-align: center;
            padding: 12px 20px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            min-width: 100px;
        }
        
        .summary-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #b8860b;
            display: block;
        }
        
        .summary-label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .schedule-timeline {
            position: relative;
        }
        
        .timeline-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 16px;
            border-left: 4px solid #b8860b;
        }
        
        .timeline-item:hover {
            background: #f1f3f4;
        }
        
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .guest-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #212529;
        }
        
        .reservation-id {
            background: #6c757d;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .timeline-dates {
            display: flex;
            gap: 16px;
            margin-bottom: 12px;
        }
        
        .date-item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        
        .date-label {
            font-size: 0.75rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        
        .date-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #495057;
        }
        
        .timeline-status {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-done { background: #d1ecf1; color: #0c5460; }
        .status-canceled { background: #f8d7da; color: #721c24; }
        .status-paid { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-partial { background: #e2e3e5; color: #383d41; }
        
        .no-bookings {
            text-align: center;
            padding: 48px 20px;
            color: #6c757d;
        }
        
        .no-bookings h3 {
            font-size: 1.25rem;
            margin-bottom: 8px;
            color: #495057;
        }
        
        .no-bookings p {
            font-size: 0.95rem;
        }
        
        @media (max-width: 768px) {
            .controls-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .period-selector {
                justify-content: center;
            }
            
            .date-navigation {
                flex-direction: column;
                gap: 8px;
            }
            
            .custom-date-inputs {
                flex-direction: column;
            }
            
            .schedule-header {
                flex-direction: column;
                gap: 16px;
                text-align: center;
            }
            
            .availability-summary {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Resource Header -->
        <div class="resource-header">
            <div class="resource-title">
                <div class="resource-icon icon-<?php echo $resource_type; ?>">
                    <?php 
                    switch($resource_type) {
                        case 'car': echo '🚗'; break;
                        case 'tent': echo '⛺'; break;
                        case 'human': echo '👤'; break;
                    }
                    ?>
                </div>
                <div class="resource-details">
                    <h1><?php echo h($resource_info['name']); ?></h1>
                    <div class="resource-meta">
                        <?php 
                        switch($resource_type) {
                            case 'car':
                                echo h($resource_info['car_type'] ?? 'Car') . ' • ' . h($resource_info['owner'] ?? 'Owner') . ' • ' . h($resource_info['number_of_places'] ?? '0') . ' places';
                                break;
                            case 'tent':
                                echo h($resource_info['tent_type'] ?? 'Tent') . ' • ' . h($resource_info['tent_specifications'] ?? 'Standard');
                                break;
                            case 'human':
                                echo h($resource_info['work_position'] ?? 'Staff') . ' • ' . h($resource_info['phone'] ?? 'No phone');
                                break;
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <a href="resources.php" class="btn btn-secondary back-btn"><?php echo t('back_to_resources', 'Back to Resources'); ?></a>
                <a href="work_schedule.php" class="btn btn-secondary back-btn"><?php echo t('back_to_general_schedule', 'Back to General Schedule'); ?></a>
            </div>
        </div>

        <!-- Schedule Controls -->
        <div class="schedule-controls">
            <div class="controls-row">
                <div class="period-selector">
                    <button class="period-btn <?php echo $view_mode === 'day' ? 'active' : ''; ?>" onclick="changeView('day')"><?php echo t('day', 'Day'); ?></button>
                    <button class="period-btn <?php echo $view_mode === 'week' ? 'active' : ''; ?>" onclick="changeView('week')"><?php echo t('week', 'Week'); ?></button>
                    <button class="period-btn <?php echo $view_mode === 'month' ? 'active' : ''; ?>" onclick="changeView('month')"><?php echo t('month', 'Month'); ?></button>
                    <button class="period-btn <?php echo $view_mode === 'custom' ? 'active' : ''; ?>" onclick="toggleCustomDates()"><?php echo t('custom', 'Custom'); ?></button>
                </div>
                
                <div class="date-navigation">
                    <button class="nav-btn" onclick="changeDate(-1)">&larr; <?php echo t('previous', 'Previous'); ?></button>
                    <div class="date-display">
                        <?php 
                        if ($view_mode === 'custom') {
                            echo date('M d, Y', strtotime($custom_start)) . ' - ' . date('M d, Y', strtotime($custom_end));
                        } else {
                            echo date('F Y', strtotime($selected_date));
                        }
                        ?>
                    </div>
                    <button class="nav-btn" onclick="changeDate(1)"><?php echo t('next', 'Next'); ?> &rarr;</button>
                    <button class="nav-btn" onclick="goToToday()"><?php echo t('today', 'Today'); ?></button>
                </div>
            </div>
            
            <div id="custom-dates" class="custom-date-inputs" style="display: <?php echo $view_mode === 'custom' ? 'flex' : 'none'; ?>;">
                <input type="date" id="custom_start" name="custom_start" value="<?php echo $custom_start; ?>" placeholder="<?php echo t('start_date', 'Start Date'); ?>">
                <input type="date" id="custom_end" name="custom_end" value="<?php echo $custom_end; ?>" placeholder="<?php echo t('end_date', 'End Date'); ?>">
                <button class="nav-btn" onclick="applyCustomDates()"><?php echo t('apply', 'Apply'); ?></button>
            </div>
        </div>

        <!-- Selected Period Display -->
        <div class="selected-period">
            <h3><?php echo t('selected_period', 'Selected Period'); ?></h3>
            <div class="period-details">
                <?php 
                if ($view_mode === 'custom') {
                    echo '<strong>' . t('custom_range', 'Custom Range') . ':</strong> ' . date('F j, Y', strtotime($custom_start)) . ' ' . t('to', 'to') . ' ' . date('F j, Y', strtotime($custom_end));
                    $days = (strtotime($custom_end) - strtotime($custom_start)) / (60 * 60 * 24) + 1;
                    echo ' (' . $days . ' ' . t('day', 'day') . ($days != 1 ? 's' : '') . ')';
                } elseif ($view_mode === 'day') {
                    echo '<strong>' . t('single_day', 'Single Day') . ':</strong> ' . date('l, F j, Y', strtotime($selected_date));
                } elseif ($view_mode === 'week') {
                    echo '<strong>' . t('week', 'Week') . ':</strong> ' . date('F j', strtotime($start_date)) . ' ' . t('to', 'to') . ' ' . date('F j, Y', strtotime($end_date));
                    echo ' (7 ' . t('days', 'days') . ')';
                } elseif ($view_mode === 'month') {
                    echo '<strong>' . t('month', 'Month') . ':</strong> ' . date('F Y', strtotime($selected_date));
                    $days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
                    echo ' (' . $days . ' ' . t('days', 'days') . ')';
                }
                ?>
            </div>
        </div>

        <!-- Schedule Content -->
        <div class="schedule-content">
            <div class="schedule-header">
                <h2 class="schedule-title"><?php echo t('schedule_timeline', 'Schedule Timeline'); ?></h2>
                <div class="availability-summary">
                    <div class="summary-item">
                        <span class="summary-number"><?php echo count($availability); ?></span>
                        <span class="summary-label"><?php echo t('bookings', 'Bookings'); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-number"><?php echo count(array_filter($availability, function($a) { return $a['reservation_status'] === 'active'; })); ?></span>
                        <span class="summary-label"><?php echo t('active', 'Active'); ?></span>
                    </div>
                </div>
            </div>

            <div class="schedule-timeline">
                <?php if (empty($availability)): ?>
                    <div class="no-bookings">
                        <h3><?php echo t('no_bookings_found', 'No Bookings Found'); ?></h3>
                        <p><?php echo t('no_bookings_message', 'This resource has no bookings for the selected period.'); ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($availability as $booking): ?>
                        <div class="timeline-item">
                            <div class="timeline-header">
                                <div class="guest-name"><?php echo h($booking['guest_name']); ?></div>
                                <div class="reservation-id">#<?php echo $booking['reservation_id']; ?></div>
                            </div>
                            
                            <div class="timeline-dates">
                                <div class="date-item">
                                    <div class="date-label"><?php echo t('resource_period', 'Resource Period'); ?></div>
                                    <div class="date-value"><?php echo date('M d', strtotime($booking['start_date'])); ?> - <?php echo date('M d', strtotime($booking['end_date'])); ?></div>
                                </div>
                                <div class="date-item">
                                    <div class="date-label"><?php echo t('reservation_period', 'Reservation Period'); ?></div>
                                    <div class="date-value"><?php echo date('M d', strtotime($booking['check_in_date'])); ?> - <?php echo date('M d', strtotime($booking['check_out_date'])); ?></div>
                                </div>
                            </div>
                            
                            <div class="timeline-status">
                                <span class="status-badge status-<?php echo $booking['reservation_status']; ?>">
                                    <?php echo ucfirst($booking['reservation_status']); ?>
                                </span>
                                <a href="view_reservation_details.php?id=<?php echo $booking['reservation_id']; ?>" class="btn btn-small btn-info" style="margin-left: 8px;">
                                    <?php echo t('view_details', 'View Details'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function changeView(view) {
            const currentDate = '<?php echo $selected_date; ?>';
            const customStart = '<?php echo $custom_start; ?>';
            const customEnd = '<?php echo $custom_end; ?>';
            
            let url = `resource_schedule.php?type=<?php echo $resource_type; ?>&id=<?php echo $resource_id; ?>&view=${view}&date=${currentDate}`;
            
            if (view === 'custom') {
                url += `&custom_start=${customStart}&custom_end=${customEnd}`;
            }
            
            window.location.href = url;
        }
        
        function changeDate(direction) {
            const currentDate = new Date('<?php echo $selected_date; ?>');
            const view = '<?php echo $view_mode; ?>';
            
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
            window.location.href = `resource_schedule.php?type=<?php echo $resource_type; ?>&id=<?php echo $resource_id; ?>&view=${view}&date=${newDate}`;
        }
        
        function goToToday() {
            const today = new Date().toISOString().split('T')[0];
            const view = '<?php echo $view_mode; ?>';
            window.location.href = `resource_schedule.php?type=<?php echo $resource_type; ?>&id=<?php echo $resource_id; ?>&view=${view}&date=${today}`;
        }
        
        function toggleCustomDates() {
            const customDates = document.getElementById('custom-dates');
            if (customDates.style.display === 'none') {
                customDates.style.display = 'flex';
            } else {
                customDates.style.display = 'none';
            }
        }
        
        function applyCustomDates() {
            const startDate = document.getElementById('custom_start').value;
            const endDate = document.getElementById('custom_end').value;
            
            if (startDate && endDate) {
                window.location.href = `resource_schedule.php?type=<?php echo $resource_type; ?>&id=<?php echo $resource_id; ?>&view=custom&custom_start=${startDate}&custom_end=${endDate}`;
            }
        }
    </script>
</body>
</html> 