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

// Handle search and filters
$search = $_GET['search'] ?? '';
$date_filter = $_GET['date_filter'] ?? '';
$tent_filter = $_GET['tent_filter'] ?? '';
$payment_filter = $_GET['payment_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$month_filter = $_GET['month_filter'] ?? '';
$source_filter = $_GET['source_filter'] ?? '';
$confirmation_filter = $_GET['confirmation_filter'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

// For statistics query, build a separate where clause and params (no guest filters)
$stats_where_conditions = [];
$stats_params = [];

if (!empty($search)) {
    $search = trim($search);
    $searchLike = "%$search%";
    $searchDigits = preg_replace('/\D+/', '', $search);
    $searchClauses = [
        "g.name LIKE ?",
        "g.email LIKE ?",
        "g.phone LIKE ?",
        "r.agency_name LIKE ?"
    ];
    $searchParams = [$searchLike, $searchLike, $searchLike, $searchLike];

    if ($searchDigits !== '') {
        // Phone numeric match and reservation ID match
        $searchClauses[] = "REPLACE(REPLACE(REPLACE(g.phone, ' ', ''), '-', ''), '+', '') LIKE ?";
        $searchParams[] = "%$searchDigits%";
        $searchClauses[] = "r.id = ?";
        $searchParams[] = (int)$searchDigits;
    }

    // Date search (dd/mm/yyyy or yyyy-mm-dd)
    $dateYmd = null;
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $search)) {
        $parts = explode('/', $search);
        $dateYmd = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search)) {
        $dateYmd = $search;
    }
    if ($dateYmd) {
        $searchClauses[] = "r.check_in_date = ?";
        $searchParams[] = $dateYmd;
    }

    $where_conditions[] = '(' . implode(' OR ', $searchClauses) . ')';
    $params = array_merge($params, $searchParams);
}

if (!empty($date_filter)) {
    $where_conditions[] = "r.check_in_date = ?";
    $params[] = $date_filter;
    $stats_where_conditions[] = "r.check_in_date = ?";
    $stats_params[] = $date_filter;
}

if (!empty($month_filter)) {
    if ($month_filter === 'current') {
        $where_conditions[] = "DATE_FORMAT(r.check_in_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')";
        $stats_where_conditions[] = "DATE_FORMAT(r.check_in_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')";
    } else {
        $where_conditions[] = "DATE_FORMAT(r.check_in_date, '%Y-%m') = ?";
        $params[] = $month_filter;
        $stats_where_conditions[] = "DATE_FORMAT(r.check_in_date, '%Y-%m') = ?";
        $stats_params[] = $month_filter;
    }
}

if (!empty($tent_filter)) {
    $where_conditions[] = "r.tent_type LIKE ?";
    $params[] = "%$tent_filter%";
    $stats_where_conditions[] = "r.tent_type LIKE ?";
    $stats_params[] = "%$tent_filter%";
}

if (!empty($payment_filter)) {
    $where_conditions[] = "r.payment_status = ?";
    $params[] = $payment_filter;
    $stats_where_conditions[] = "r.payment_status = ?";
    $stats_params[] = $payment_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "r.reservation_status = ?";
    $params[] = $status_filter;
    $stats_where_conditions[] = "r.reservation_status = ?";
    $stats_params[] = $status_filter;
}

if (!empty($source_filter)) {
    $where_conditions[] = "r.reservation_source = ?";
    $params[] = $source_filter;
    $stats_where_conditions[] = "r.reservation_source = ?";
    $stats_params[] = $source_filter;
}

if (!empty($confirmation_filter)) {
    if ($confirmation_filter === 'confirmed') {
        $where_conditions[] = "r.confirmation = 1";
        $stats_where_conditions[] = "r.confirmation = 1";
    } elseif ($confirmation_filter === 'not_confirmed') {
        $where_conditions[] = "(r.confirmation = 0 OR r.confirmation IS NULL)";
        $stats_where_conditions[] = "(r.confirmation = 0 OR r.confirmation IS NULL)";
    }
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

$stats_where_clause = '';
if (!empty($stats_where_conditions)) {
    $stats_where_clause = 'WHERE ' . implode(' AND ', $stats_where_conditions);
}

// Pagination setup
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = 35;
$offset = ($page - 1) * $page_size;

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM reservations r JOIN guests g ON r.guest_id = g.id $where_clause";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_reservations = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_reservations / $page_size));

// Get reservations with guest details and tariff version info (with LIMIT/OFFSET)
$query = "SELECT r.*, g.name as guest_name, g.email, g.phone, g.adults, g.kids, g.babies, tv.name as tariff_version_name
          FROM reservations r 
          JOIN guests g ON r.guest_id = g.id 
          LEFT JOIN tariff_versions tv ON r.tariff_version_id = tv.id
          $where_clause 
          ORDER BY " . ((!empty($search) && preg_match('/\d+/', $search)) ? "CASE WHEN r.id = ? THEN 0 ELSE 1 END, " : "") . "r.check_in_date ASC
          LIMIT $page_size OFFSET $offset";

$stmt = $pdo->prepare($query);
if (!empty($search) && preg_match('/\d+/', $search, $m)) {
    $stmt->execute(array_merge($params, [(int)$m[0]]));
} else {
    $stmt->execute($params);
}
$reservations = $stmt->fetchAll();

// Get statistics with the same filters (but no guest filters)
$stats_query = "SELECT 
    COUNT(*) as total_reservations,
    COUNT(CASE WHEN r.payment_status = 'paid' THEN 1 END) as paid_reservations,
    COUNT(CASE WHEN r.payment_status = 'pending' THEN 1 END) as pending_reservations,
    COUNT(CASE WHEN r.reservation_status = 'canceled' THEN 1 END) as canceled_reservations,
    COUNT(CASE WHEN r.reservation_status = 'done' THEN 1 END) as done_reservations,
    COUNT(CASE WHEN r.reservation_status = 'active' THEN 1 END) as active_reservations
    FROM reservations r 
    $stats_where_clause";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch();

// Calculate total revenue accurately by fetching all active/done reservations and calculating their totals
$revenue_query = "SELECT r.*, g.adults, g.kids, g.babies 
                  FROM reservations r 
                  JOIN guests g ON r.guest_id = g.id 
                  WHERE r.reservation_status IN ('active','done')";
if (!empty($stats_where_conditions)) {
    $revenue_query .= " AND " . implode(' AND ', $stats_where_conditions);
}
$revenue_stmt = $pdo->prepare($revenue_query);
$revenue_stmt->execute($stats_params);
$revenue_reservations = $revenue_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_revenue = 0;
foreach ($revenue_reservations as $reservation) {
    // Calculate car days total
    $stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_cars WHERE reservation_id = ?");
    $stmt->execute([$reservation['id']]);
    $car_days_total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $car) {
        $car_days_total += (new DateTime($car['end_date']))->diff(new DateTime($car['start_date']))->days;
    }
    $reservation['car_days_total'] = $car_days_total;

    // Calculate driver days total
    $stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'driver'");
    $stmt->execute([$reservation['id']]);
    $driver_days_total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $driver) {
        $driver_days_total += (new DateTime($driver['end_date']))->diff(new DateTime($driver['start_date']))->days + 1;
    }
    $reservation['driver_days_total'] = $driver_days_total;

    // Calculate guide days total
    $stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'guide'");
    $stmt->execute([$reservation['id']]);
    $guide_days_total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $guide) {
        $guide_days_total += (new DateTime($guide['end_date']))->diff(new DateTime($guide['start_date']))->days + 1;
    }
    $reservation['guide_days_total'] = $guide_days_total;

    // Build guest array for calculation
    $guest = [
        'adults' => $reservation['adults'] ?? 0,
        'kids' => $reservation['kids'] ?? 0,
        'babies' => $reservation['babies'] ?? 0,
    ];
    
    // Calculate total price using the same function as individual reservations
    $total_revenue += calculateTotalReservationPrice($pdo, $reservation, $guest);
}

$stats['total_revenue'] = $total_revenue;

// Get success message
$message = $_GET['message'] ?? '';

// Get list of months for the filter
$months_query = "SELECT DISTINCT DATE_FORMAT(check_in_date, '%Y-%m') as month 
                FROM reservations 
                ORDER BY month DESC";
$months = $pdo->query($months_query)->fetchAll(PDO::FETCH_COLUMN);

// Handle reservation status change
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $action = $_GET['action'];
    
    if ($action === 'cancel' || $action === 'activate' || $action === 'done') {
        try {
            $new_status = $action === 'cancel' ? 'canceled' : ($action === 'done' ? 'done' : 'active');
            $update_stmt = $pdo->prepare("UPDATE reservations SET reservation_status = ? WHERE id = ?");
            $update_stmt->execute([$new_status, $id]);
            
            $message = "Reservation has been " . ($action === 'cancel' ? 'canceled' : ($action === 'done' ? 'marked as done' : 'reactivated')) . " successfully!";
            header("Location: dashboard.php?message=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = "Error updating reservation status: " . $e->getMessage();
        }
    }
}

// Remove the old calculate_reservation_total function and replace it with a wrapper for calculateTotalReservationPrice
function calculate_reservation_total($reservation) {
    global $pdo;
    // Build guest array from reservation row (fields: adults, kids, babies, etc.)
    $guest = [
        'adults' => $reservation['adults'] ?? 0,
        'kids' => $reservation['kids'] ?? 0,
        'babies' => $reservation['babies'] ?? 0,
        // Add more fields if needed
    ];
    return calculateTotalReservationPrice($pdo, $reservation, $guest);
}

// Unify the total price calculation with view_reservation_details.php: For each reservation, fetch assigned cars, drivers, and guides, calculate their total days, and add car_days_total, driver_days_total, and guide_days_total to the reservation array before calling calculate_reservation_total. This ensures the dashboard total price matches the view reservation details.
foreach ($reservations as &$reservation) {
    // Fetch assigned cars
    $stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_cars WHERE reservation_id = ?");
    $stmt->execute([$reservation['id']]);
    $car_days_total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $car) {
        $car_days_total += (new DateTime($car['end_date']))->diff(new DateTime($car['start_date']))->days;
    }
    $reservation['car_days_total'] = $car_days_total;

    // Fetch assigned drivers
    $stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'driver'");
    $stmt->execute([$reservation['id']]);
    $driver_days_total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $driver) {
        $driver_days_total += (new DateTime($driver['end_date']))->diff(new DateTime($driver['start_date']))->days + 1;
    }
    $reservation['driver_days_total'] = $driver_days_total;

    // Fetch assigned guides
    $stmt = $pdo->prepare("SELECT start_date, end_date FROM reservation_humans WHERE reservation_id = ? AND role = 'guide'");
    $stmt->execute([$reservation['id']]);
    $guide_days_total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $guide) {
        $guide_days_total += (new DateTime($guide['end_date']))->diff(new DateTime($guide['start_date']))->days + 1;
    }
    $reservation['guide_days_total'] = $guide_days_total;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ABDELMOULA CAMP - Admin Dashboard</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .reservation-source {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            display: inline-block;
        }
        .reservation-source.individual {
            background: #d4edda;
            color: #155724;
        }
        .reservation-source.agency {
            background: #fff3cd;
            color: #856404;
        }
        .agency-name {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        
        .header-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .header-buttons .btn {
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .header-buttons .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .confirmation-badge {
            display: inline-block;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            text-align: center;
            line-height: 24px;
            font-weight: bold;
            font-size: 14px;
        }
        
        .confirmation-badge.confirmed {
            background: #28a745;
            color: white;
        }
        
        .confirmation-badge.not-confirmed {
            background: #dc3545;
            color: white;
        }
        
        .confirmation-way {
            font-size: 11px;
            color: #666;
            margin-top: 4px;
            font-style: italic;
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <!-- Welcome Message -->
        <div class="welcome-section">
            <h2><?php echo t('welcome', 'Welcome'); ?>, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h2>
            <p><?php echo t('logged_in_as', 'You are logged in as:'); ?> <strong><?php echo htmlspecialchars($_SESSION['user_role']); ?></strong></p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($_SESSION['user_role'] !== 'Admin'): ?>
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo t('total_reservations', 'Total Reservations'); ?></h3>
                <p class="stat-number"><?php echo $stats['total_reservations']; ?></p>
            </div>
            <div class="stat-card">
                <h3><?php echo t('total_revenue', 'Total Revenue'); ?></h3>
                <p class="stat-number"><?php echo number_format($stats['total_revenue'], 2); ?> TND</p>
            </div>
            <div class="stat-card">
                    <h3><?php echo t('active_reservations', 'Active Reserv'); ?></h3>
                    <p class="stat-number"><?php echo $stats['active_reservations']; ?></p>
                </div>
                <div class="stat-card">
                    <h3><?php echo t('paid_reservations', 'Paid Reserv'); ?></h3>
                <p class="stat-number"><?php echo $stats['paid_reservations']; ?></p>
            </div>
            <div class="stat-card">
                    <h3><?php echo t('pending_reservations', 'Pending Reserv'); ?></h3>
                <p class="stat-number"><?php echo $stats['pending_reservations']; ?></p>
            </div>
            <div class="stat-card">
                    <h3><?php echo t('canceled_reservations', 'Canceled Reserv'); ?></h3>
                <p class="stat-number"><?php echo $stats['canceled_reservations']; ?></p>
            </div>
            <div class="stat-card">
                    <h3><?php echo t('done_reservations', 'Done Reserv'); ?></h3>
                <p class="stat-number"><?php echo $stats['done_reservations']; ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Search and Filters -->
        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <input type="text" name="search" placeholder="<?php echo t('search_placeholder', 'Search by guest name, email, phone, agency name, or reservation ID'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <input type="date" name="date_filter" value="<?php echo htmlspecialchars($date_filter); ?>">
                </div>
                <div class="filter-group">
                    <select name="month_filter">
                        <option value=""><?php echo t('all_time', 'All Time'); ?></option>
                        <option value="current" <?php echo $month_filter === 'current' ? 'selected' : ''; ?>>
                            <?php echo t('current_month', 'Current Month'); ?>
                        </option>
                        <?php foreach ($months as $month): ?>
                            <option value="<?php echo $month; ?>" <?php echo $month_filter === $month ? 'selected' : ''; ?>>
                                <?php echo date('F Y', strtotime($month . '-01')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <select name="tent_filter">
                        <option value=""><?php echo t('all_tent_types', 'All Tent Types'); ?></option>
                        <option value="NORMAL" <?php echo $tent_filter === 'NORMAL' ? 'selected' : ''; ?>>NORMAL</option>
                        <option value="ROYAL" <?php echo $tent_filter === 'ROYAL' ? 'selected' : ''; ?>>ROYAL</option>
                        <option value="MIXED" <?php echo $tent_filter === 'MIXED' ? 'selected' : ''; ?>>MIXED</option>
                    </select>
                </div>
                <div class="filter-group">
                    <select name="payment_filter">
                        <option value=""><?php echo t('all_payment_status', 'All Payment Status'); ?></option>
                        <option value="pending" <?php echo $payment_filter === 'pending' ? 'selected' : ''; ?>>
                            <?php echo t('pending', 'Pending'); ?>
                        </option>
                        <option value="partial" <?php echo $payment_filter === 'partial' ? 'selected' : ''; ?>>
                            <?php echo t('partial', 'Partial'); ?>
                        </option>
                        <option value="paid" <?php echo $payment_filter === 'paid' ? 'selected' : ''; ?>>
                            <?php echo t('paid', 'Paid'); ?>
                        </option>
                    </select>
                </div>
                <div class="filter-group">
                    <select name="status_filter">
                        <option value=""><?php echo t('all_reservation_status', 'All Reservation Status'); ?></option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>
                            <?php echo t('active', 'Active'); ?>
                        </option>
                        <option value="done" <?php echo $status_filter === 'done' ? 'selected' : ''; ?>>
                            <?php echo t('done', 'Done'); ?>
                        </option>
                        <option value="canceled" <?php echo $status_filter === 'canceled' ? 'selected' : ''; ?>>
                            <?php echo t('canceled', 'Canceled'); ?>
                        </option>
                    </select>
                </div>
                <div class="filter-group">
                    <select name="source_filter">
                        <option value=""><?php echo t('all_sources', 'All Sources'); ?></option>
                        <option value="individual" <?php echo $source_filter === 'individual' ? 'selected' : ''; ?>>
                            <?php echo t('individual', 'Individual'); ?>
                        </option>
                        <option value="agency" <?php echo $source_filter === 'agency' ? 'selected' : ''; ?>>
                            <?php echo t('agency', 'Agency'); ?>
                        </option>
                    </select>
                </div>
                <div class="filter-group">
                    <select name="confirmation_filter">
                        <option value=""><?php echo t('all_confirmations', 'All Confirmations'); ?></option>
                        <option value="confirmed" <?php echo $confirmation_filter === 'confirmed' ? 'selected' : ''; ?>>
                            <?php echo t('confirmed', 'Confirmed'); ?>
                        </option>
                        <option value="not_confirmed" <?php echo $confirmation_filter === 'not_confirmed' ? 'selected' : ''; ?>>
                            <?php echo t('not_confirmed', 'Not Confirmed'); ?>
                        </option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo t('filter', 'Filter'); ?></button>
                <a href="dashboard.php" class="btn btn-secondary"><?php echo t('clear', 'Clear'); ?></a>
            </form>
        </div>

        <!-- Reservations Table -->
        <div class="table-container">
            <div class="table-header">
                <h2><?php echo t('guest_reservations', 'Guest Reservations'); ?></h2>
                <div class="header-buttons">
                    <a href="add_reservation.php" class="btn btn-primary"><?php echo t('add_new_reservation', 'Add New Reservation'); ?></a>
                    <a href="reservation_summary.php" class="btn btn-secondary">📊 <?php echo t('reservation_summary', 'Reservation Summary'); ?></a>
                </div>
            </div>
            
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?php echo t('guest_name', 'Guest Name'); ?></th>
                            <th><?php echo t('contact', 'Contact'); ?></th>
                            <th><?php echo t('people_count', 'People (A/K/B)'); ?></th>
                            <th><?php echo t('source', 'Source'); ?></th>
                            <th><?php echo t('check_in', 'Check-in'); ?></th>
                            <th><?php echo t('check_out', 'Check-out'); ?></th>
                            <th><?php echo t('payment', 'Payment'); ?></th>
                            <th><?php echo t('conf', 'Conf'); ?></th>
                            <th><?php echo t('total_price', 'Total Price'); ?></th>
                            <th><?php echo t('tariff_version', 'Tariff Version'); ?></th>
                            <th><?php echo t('status', 'Status'); ?></th>
                            <th><?php echo t('actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as &$reservation): ?>
                        <tr class="<?php echo $reservation['reservation_status'] === 'canceled' ? 'canceled-row' : ($reservation['reservation_status'] === 'done' ? 'done-row' : ''); ?>">
                            <td class="guest-name"><?php echo htmlspecialchars($reservation['guest_name']); ?></td>
                            <td class="contact-info">
                                <?php if ($reservation['email']): ?>
                                    <div class="email"><?php echo htmlspecialchars($reservation['email']); ?></div>
                                <?php endif; ?>
                                <?php if ($reservation['phone']): ?>
                                    <div class="phone"><?php echo htmlspecialchars($reservation['phone']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="people-count">
                                <span class="adults"><?php echo $reservation['adults']; ?>A</span>
                                <span class="kids"><?php echo $reservation['kids']; ?>K</span>
                                <span class="babies"><?php echo $reservation['babies']; ?>B</span>
                            </td>
                            <td class="source">
                                <span class="reservation-source <?php echo $reservation['reservation_source']; ?>">
                                    <?php echo t($reservation['reservation_source'], ucfirst($reservation['reservation_source'])); ?>
                                </span>
                                <?php if ($reservation['reservation_source'] === 'agency' && $reservation['agency_name']): ?>
                                    <div class="agency-name"><?php echo htmlspecialchars($reservation['agency_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($reservation['check_in_date'])); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($reservation['check_out_date'])); ?></td>
                            <td class="payment-info">
                                <span class="payment-status <?php echo $reservation['payment_status']; ?>">
                                    <?php echo t($reservation['payment_status'], ucfirst($reservation['payment_status'])); ?>
                                </span>
                            </td>
                            <td class="confirmation-status">
                                <?php if ($reservation['confirmation'] ?? 0): ?>
                                    <span class="confirmation-badge confirmed">✓</span>
                                    <?php if (!empty($reservation['confirmation_way'])): ?>
                                        <div class="confirmation-way"><?php echo htmlspecialchars($reservation['confirmation_way']); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="confirmation-badge not-confirmed">✗</span>
                                <?php endif; ?>
                            </td>
                            <td class="total-price"><?php echo number_format(calculate_reservation_total($reservation), 2); ?> TND</td>
                            <td class="tariff-version">
                                <span class="tariff-badge"><?php echo htmlspecialchars($reservation['tariff_version_name'] ?? 'Default'); ?></span>
                            </td>
                            <td class="reservation-status">
                                <span class="status-badge <?php echo $reservation['reservation_status']; ?>">
                                    <?php echo t($reservation['reservation_status'], ucfirst($reservation['reservation_status'])); ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="view_reservation_details.php?id=<?php echo $reservation['id']; ?>" class="btn btn-small btn-info"><?php echo t('view_details', 'View Details'); ?></a>
                                <a href="edit_reservation.php?id=<?php echo $reservation['id']; ?>" class="btn btn-small btn-primary"><?php echo t('edit', 'Edit'); ?></a>
                                <?php if ($reservation['reservation_status'] === 'active'): ?>
                                    <a href="dashboard.php?action=cancel&id=<?php echo $reservation['id']; ?>" class="btn btn-danger btn-small" onclick="return confirm('<?php echo t('confirm_cancel', 'Are you sure you want to cancel this reservation?'); ?>');"><?php echo t('cancel', 'Cancel'); ?></a>
                                    <a href="dashboard.php?action=done&id=<?php echo $reservation['id']; ?>" class="btn btn-success btn-small" onclick="return confirm('<?php echo t('confirm_done', 'Mark this reservation as done?'); ?>');"><?php echo t('done', 'Done'); ?></a>
                                <?php elseif ($reservation['reservation_status'] === 'canceled'): ?>
                                    <a href="dashboard.php?action=activate&id=<?php echo $reservation['id']; ?>" class="btn btn-info btn-small"><?php echo t('reactivate', 'Reactivate'); ?></a>
                                <?php elseif ($reservation['reservation_status'] === 'done'): ?>
                                    <a href="dashboard.php?action=activate&id=<?php echo $reservation['id']; ?>" class="btn btn-info btn-small"><?php echo t('undo_done', 'Undo Done'); ?></a>
                                <?php endif; ?>
                                <a href="delete_reservation.php?id=<?php echo $reservation['id']; ?>" 
                                   class="btn btn-small btn-danger" 
                                   onclick="return confirm('<?php echo t('confirm_delete', 'Are you sure you want to delete this reservation?'); ?>')"><?php echo t('delete', 'Delete'); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php unset($reservation); // break the reference } ?>
                    </tbody>
                </table>
            </div>
            <!-- Pagination Controls -->
            <div class="pagination" style="margin:18px 0;display:flex;justify-content:center;gap:8px;">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-small">&laquo; <?php echo t('prev', 'Prev'); ?></a>
                <?php endif; ?>
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>" class="btn btn-small<?php if ($p == $page) echo ' btn-primary'; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-small"><?php echo t('next', 'Next'); ?> &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html> 