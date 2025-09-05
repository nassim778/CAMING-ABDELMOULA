<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';
// Translation loader and function - moved to top
$lang = $_SESSION['lang'] ?? 'en';
$trans = [];
if ($lang === 'fr' && file_exists('languages/fr.php')) {
    $trans = include 'languages/fr.php';
}
function t($key, $default) {
    global $trans;
    return $trans[$key] ?? $default;
}
require_once 'includes/session_check.php';
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !in_array($_SESSION['user_role'], ['Director', 'Accountant'])) {
    header('Location: dashboard.php');
    exit();
}

// Function to create a new tariff version
function createNewTariffVersion($pdo) {
    // Get current tariff version
    $stmt = $pdo->query("SELECT id FROM tariff_versions ORDER BY id DESC LIMIT 1");
    $current_version = $stmt->fetch();
    $new_version_number = ($current_version ? $current_version['id'] : 0) + 1;
    
    // Create new tariff version
    $stmt = $pdo->prepare("INSERT INTO tariff_versions (name, description, is_active, created_at, activated_at) VALUES (?, ?, 0, NOW(), NOW())");
    $version_name = "Tariff Version " . $new_version_number;
    $description = "Auto-generated tariff version " . $new_version_number;
    $stmt->execute([$version_name, $description]);
    
    return $pdo->lastInsertId();
}

// Function to copy all current tariff prices to new version
function copyTariffPricesToNewVersion($pdo, $new_version_id) {
    // Copy accommodation tariffs
    $stmt = $pdo->prepare("
        INSERT INTO tariff_prices (tariff_version_id, tent_type, bed_configuration, reservation_source, price_per_night)
        SELECT ?, tent_type, bed_configuration, reservation_source, price_per_night
        FROM tariff_prices 
        WHERE tariff_version_id = (SELECT id FROM tariff_versions WHERE is_active = 1)
    ");
    $stmt->execute([$new_version_id]);
    
    // Copy service tariffs
    $stmt = $pdo->prepare("
        INSERT INTO service_tariff_prices (tariff_version_id, service_type, price_per_unit)
        SELECT ?, service_type, price_per_unit
        FROM service_tariff_prices 
        WHERE tariff_version_id = (SELECT id FROM tariff_versions WHERE is_active = 1)
    ");
    $stmt->execute([$new_version_id]);
    
    // Copy boarding prices
    $stmt = $pdo->prepare("
        INSERT INTO boarding_prices (tariff_version_id, reservation_source, tent_type, bed_configuration, boarding_type, price_per_night)
        SELECT ?, reservation_source, tent_type, bed_configuration, boarding_type, price_per_night
        FROM boarding_prices 
        WHERE tariff_version_id = (SELECT id FROM tariff_versions WHERE is_active = 1)
    ");
    $stmt->execute([$new_version_id]);
    
    // Copy kids discount percentage
    $stmt = $pdo->prepare("
        INSERT INTO kids_discount_percentage (tariff_version_id, discount_percentage)
        SELECT ?, discount_percentage
        FROM kids_discount_percentage 
        WHERE tariff_version_id = (SELECT id FROM tariff_versions WHERE is_active = 1)
    ");
    $stmt->execute([$new_version_id]);
}

// Function to update current version pointer
function updateCurrentVersion($pdo, $new_version_id) {
    // Set all versions as not active
    $stmt = $pdo->prepare("UPDATE tariff_versions SET is_active = 0");
    $stmt->execute();
    
    // Set new version as active
    $stmt = $pdo->prepare("UPDATE tariff_versions SET is_active = 1 WHERE id = ?");
    $stmt->execute([$new_version_id]);
}

// At the top of the PHP file, before any HTML output, initialize a message variable
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_new_tariff'])) {
    $bed_configs = ['single', 'double', 'triple', 'quadruple'];
    $tent_types = ['ROYAL', 'NORMAL'];
    $sources = ['agency', 'individual'];
    $service_types = ['driver', 'guide', 'car_4x4_1day', 'car_4x4_multiday'];
    $inputs = [];
    $missing = false;
    // Collect and validate tent prices
    foreach ($tent_types as $tent_type) {
        foreach ($bed_configs as $bed_config) {
            foreach ($sources as $source) {
                $key = strtolower($tent_type) . '_' . $source;
                $val = isset($_POST[$key][$bed_config]) ? floatval($_POST[$key][$bed_config]) : null;
                if ($val === null || $val <= 0) {
                    $missing = true;
                }
                $inputs['tent'][$tent_type][$bed_config][$source] = $val;
            }
        }
    }
    // Collect and validate service prices
    foreach ($service_types as $stype) {
        $val = isset($_POST['service'][$stype]) ? floatval($_POST['service'][$stype]) : null;
        if ($val === null || $val < 0) {
            $missing = true;
        }
        $inputs['service'][$stype] = $val;
    }
    
    // Collect and validate boarding prices
    $boarding_types = ['half_board', 'full_board'];
    foreach ($tent_types as $tent_type) {
        foreach ($bed_configs as $bed_config) {
            foreach ($sources as $source) {
                foreach ($boarding_types as $boarding_type) {
                    $val = isset($_POST['boarding'][$source][$tent_type][$bed_config][$boarding_type]) ? floatval($_POST['boarding'][$source][$tent_type][$bed_config][$boarding_type]) : null;
                    if ($val === null || $val < 0) {
                        $missing = true;
                    }
                    $inputs['boarding'][$source][$tent_type][$bed_config][$boarding_type] = $val;
                }
            }
        }
    }
    
    // Collect and validate kids discount percentage
    $kids_discount_percentage = isset($_POST['kids_discount_percentage']) ? intval($_POST['kids_discount_percentage']) : 50;
    if ($kids_discount_percentage < 0 || $kids_discount_percentage > 100) {
        $missing = true;
    }
    $inputs['kids_discount_percentage'] = $kids_discount_percentage;
    if ($missing) {
        $message = '<div style="font-size:1.2em;padding:18px 24px;margin:20px 0 16px 0;border-radius:8px;background:#ffeaea;color:#b71c1c;border:2px solid #f44336;box-shadow:0 2px 8px rgba(244,67,54,0.08);font-weight:bold;">' . t('tariff_all_prices_required', 'All tent prices are required and must be greater than 0! Service prices can be 0.') . '</div>';
    } else {
        // Fetch current version prices for comparison
        $current_version_stmt = $pdo->query("SELECT id FROM tariff_versions WHERE is_active = 1");
        $current_version = $current_version_stmt->fetch();
        $current_prices = [];
        $stmt = $pdo->prepare("SELECT tent_type, bed_configuration, reservation_source, price_per_night FROM tariff_prices WHERE tariff_version_id = ?");
        $stmt->execute([$current_version['id']]);
        foreach ($stmt as $row) {
            $current_prices[$row['tent_type']][$row['bed_configuration']][$row['reservation_source']] = floatval($row['price_per_night']);
        }
        $current_services = [];
        $stmt = $pdo->prepare("SELECT service_type, price_per_unit FROM service_tariff_prices WHERE tariff_version_id = ?");
        $stmt->execute([$current_version['id']]);
        foreach ($stmt as $row) {
            $current_services[$row['service_type']] = floatval($row['price_per_unit']);
        }
        
        // Fetch current boarding prices for comparison
        $current_boarding = [];
        $stmt = $pdo->prepare("SELECT reservation_source, tent_type, bed_configuration, boarding_type, price_per_night FROM boarding_prices WHERE tariff_version_id = ?");
        $stmt->execute([$current_version['id']]);
        foreach ($stmt as $row) {
            $key = $row['reservation_source'] . '_' . $row['tent_type'] . '_' . $row['bed_configuration'] . '_' . $row['boarding_type'];
            $current_boarding[$key] = floatval($row['price_per_night']);
        }
        
        // Fetch current kids discount percentage for comparison
        $current_kids_discount = 50; // Default value
        $stmt = $pdo->prepare("SELECT discount_percentage FROM kids_discount_percentage WHERE tariff_version_id = ?");
        $stmt->execute([$current_version['id']]);
        $kids_discount_result = $stmt->fetch();
        if ($kids_discount_result) {
            $current_kids_discount = intval($kids_discount_result['discount_percentage']);
        }
        
        // Compare all prices (tent + service + boarding)
        $no_change = true;
        foreach ($tent_types as $tent_type) {
            foreach ($bed_configs as $bed_config) {
                foreach ($sources as $source) {
                    $old = isset($current_prices[$tent_type][$bed_config][$source]) ? $current_prices[$tent_type][$bed_config][$source] : null;
                    $new = $inputs['tent'][$tent_type][$bed_config][$source];
                    if ($old === null || abs($old - $new) > 0.0001) {
                        $no_change = false;
                    }
                }
            }
        }
        foreach ($service_types as $stype) {
            $old = isset($current_services[$stype]) ? $current_services[$stype] : null;
            $new = $inputs['service'][$stype];
            if ($old === null || abs($old - $new) > 0.0001) {
                $no_change = false;
            }
        }
        
        // Compare boarding prices
        $boarding_types = ['half_board', 'full_board'];
        foreach ($tent_types as $tent_type) {
            foreach ($bed_configs as $bed_config) {
                foreach ($sources as $source) {
                    foreach ($boarding_types as $boarding_type) {
                        $key = $source . '_' . $tent_type . '_' . $bed_config . '_' . $boarding_type;
                        $old = isset($current_boarding[$key]) ? $current_boarding[$key] : null;
                        $new = $inputs['boarding'][$source][$tent_type][$bed_config][$boarding_type];
                        if ($old === null || abs($old - $new) > 0.0001) {
                            $no_change = false;
                        }
                    }
                }
            }
        }
        
        // Compare kids discount percentage
        $new_kids_discount = $inputs['kids_discount_percentage'];
        if ($current_kids_discount !== $new_kids_discount) {
            $no_change = false;
        }
        
        if ($no_change) {
            $message = '<div style="font-size:1.2em;padding:18px 24px;margin:20px 0 16px 0;border-radius:8px;background:#fff8e1;color:#b8860b;border:2px solid #ff9800;box-shadow:0 2px 8px rgba(255,152,0,0.08);font-weight:bold;">' . t('tariff_no_changes_detected', 'No changes detected. Please change at least one price to create a new tariff version.') . '</div>';
        } else {
    // Create new tariff version
    $new_version_id = createNewTariffVersion($pdo);
    updateCurrentVersion($pdo, $new_version_id);
            // Insert all prices from form into new version
            foreach ($tent_types as $tent_type) {
                foreach ($bed_configs as $bed_config) {
                    foreach ($sources as $source) {
                        $val = $inputs['tent'][$tent_type][$bed_config][$source];
                        $stmt = $pdo->prepare("INSERT INTO tariff_prices (tariff_version_id, tent_type, bed_configuration, reservation_source, price_per_night, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE price_per_night = VALUES(price_per_night), updated_at = NOW(), is_active = 1");
                        $stmt->execute([$new_version_id, $tent_type, $bed_config, $source, $val]);
                    }
                }
            }
            foreach ($service_types as $stype) {
                $val = $inputs['service'][$stype];
                $stmt = $pdo->prepare("INSERT INTO service_tariff_prices (tariff_version_id, service_type, price_per_unit, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE price_per_unit = VALUES(price_per_unit), updated_at = NOW(), is_active = 1");
                $stmt->execute([$new_version_id, $stype, $val]);
            }
            
            // Insert boarding prices from form into new version
            $boarding_types = ['half_board', 'full_board'];
            foreach ($tent_types as $tent_type) {
                foreach ($bed_configs as $bed_config) {
                    foreach ($sources as $source) {
                        foreach ($boarding_types as $boarding_type) {
                            $val = $inputs['boarding'][$source][$tent_type][$bed_config][$boarding_type];
                            $stmt = $pdo->prepare("INSERT INTO boarding_prices (tariff_version_id, reservation_source, tent_type, bed_configuration, boarding_type, price_per_night, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE price_per_night = VALUES(price_per_night), updated_at = NOW(), is_active = 1");
                            $stmt->execute([$new_version_id, $source, $tent_type, $bed_config, $boarding_type, $val]);
                        }
                    }
                }
            }
            
            // Insert kids discount percentage for the new version
            $stmt = $pdo->prepare("INSERT INTO kids_discount_percentage (tariff_version_id, discount_percentage) VALUES (?, ?)");
            $stmt->execute([$new_version_id, $inputs['kids_discount_percentage']]);
            // Show success message with new version number
            $version_stmt = $pdo->prepare("SELECT name FROM tariff_versions WHERE id = ?");
            $version_stmt->execute([$new_version_id]);
            $version = $version_stmt->fetch();
            $message = '<div style="font-size:1.2em;padding:18px 24px;margin:20px 0 16px 0;border-radius:8px;background:#e8f5e9;color:#1b5e20;border:2px solid #4caf50;box-shadow:0 2px 8px rgba(76,175,80,0.08);font-weight:bold;">' . t('tariff_created_success', 'Successfully created new tariff:') . ' ' . htmlspecialchars($version['name']) . '</div>';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_current_tariff'])) {
    $bed_configs = ['single', 'double', 'triple', 'quadruple'];
    $tent_types = ['ROYAL', 'NORMAL'];
    $sources = ['agency', 'individual'];
    $service_types = ['driver', 'guide', 'car_4x4_1day', 'car_4x4_multiday'];
    $missing = false;
    // Get current version
    $current_version_stmt = $pdo->query("SELECT id FROM tariff_versions WHERE is_active = 1");
    $current_version = $current_version_stmt->fetch();
    // Update tent prices
    foreach ($tent_types as $tent_type) {
        foreach ($bed_configs as $bed_config) {
            foreach ($sources as $source) {
                $key = strtolower($tent_type) . '_' . $source;
                $val = isset($_POST[$key][$bed_config]) ? floatval($_POST[$key][$bed_config]) : null;
                if ($val === null || $val <= 0) {
                    $missing = true;
                }
                $stmt = $pdo->prepare("INSERT INTO tariff_prices (tariff_version_id, tent_type, bed_configuration, reservation_source, price_per_night, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE price_per_night = VALUES(price_per_night), updated_at = NOW(), is_active = 1");
                $stmt->execute([$current_version['id'], $tent_type, $bed_config, $source, $val]);
            }
        }
    }
    // Update service prices
    foreach ($service_types as $stype) {
        $val = isset($_POST['service'][$stype]) ? floatval($_POST['service'][$stype]) : null;
        if ($val === null || $val < 0) {
            $missing = true;
        }
        $stmt = $pdo->prepare("INSERT INTO service_tariff_prices (tariff_version_id, service_type, price_per_unit, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE price_per_unit = VALUES(price_per_unit), updated_at = NOW(), is_active = 1");
        $stmt->execute([$current_version['id'], $stype, $val]);
    }
    
    // Update boarding prices
    $boarding_types = ['half_board', 'full_board'];
    foreach ($tent_types as $tent_type) {
        foreach ($bed_configs as $bed_config) {
            foreach ($sources as $source) {
                foreach ($boarding_types as $boarding_type) {
                    $val = isset($_POST['boarding'][$source][$tent_type][$bed_config][$boarding_type]) ? floatval($_POST['boarding'][$source][$tent_type][$bed_config][$boarding_type]) : null;
                    if ($val === null || $val < 0) {
                        $missing = true;
                    }
                    $stmt = $pdo->prepare("INSERT INTO boarding_prices (tariff_version_id, reservation_source, tent_type, bed_configuration, boarding_type, price_per_night, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE price_per_night = VALUES(price_per_night), updated_at = NOW(), is_active = 1");
                    $stmt->execute([$current_version['id'], $source, $tent_type, $bed_config, $boarding_type, $val]);
                }
            }
        }
    }
    
    // Update kids discount percentage
    $kids_discount_percentage = isset($_POST['kids_discount_percentage']) ? intval($_POST['kids_discount_percentage']) : 50;
    
    // Debug: Log the received value
    error_log("Kids discount percentage received: " . $kids_discount_percentage);
    error_log("Current version ID: " . $current_version['id']);
    
    if ($kids_discount_percentage >= 0 && $kids_discount_percentage <= 100) {
        // First check if a record exists for this tariff version
        $check_stmt = $pdo->prepare("SELECT id FROM kids_discount_percentage WHERE tariff_version_id = ?");
        $check_stmt->execute([$current_version['id']]);
        $existing_record = $check_stmt->fetch();
        
        error_log("Existing record found: " . ($existing_record ? "Yes" : "No"));
        
        if ($existing_record) {
            // Update existing record
            $stmt = $pdo->prepare("UPDATE kids_discount_percentage SET discount_percentage = ? WHERE tariff_version_id = ?");
            $result = $stmt->execute([$kids_discount_percentage, $current_version['id']]);
            error_log("Update result: " . ($result ? "Success" : "Failed"));
        } else {
            // Insert new record
            $stmt = $pdo->prepare("INSERT INTO kids_discount_percentage (tariff_version_id, discount_percentage) VALUES (?, ?)");
            $result = $stmt->execute([$current_version['id'], $kids_discount_percentage]);
            error_log("Insert result: " . ($result ? "Success" : "Failed"));
        }
    }

    if ($missing) {
        $message = '<div style="font-size:1.2em;padding:18px 24px;margin:20px 0 16px 0;border-radius:8px;background:#ffeaea;color:#b71c1c;border:2px solid #f44336;box-shadow:0 2px 8px rgba(244,67,54,0.08);font-weight:bold;">' . t('tariff_all_prices_required', 'All tent prices are required and must be greater than 0! Service prices can be 0.') . '</div>';
    } else {
        $message = '<div style="font-size:1.2em;padding:18px 24px;margin:20px 0 16px 0;border-radius:8px;background:#e8f5e9;color:#1b5e20;border:2px solid #4caf50;box-shadow:0 2px 8px rgba(76,175,80,0.08);font-weight:bold;">' . t('tariff_updated_success', 'Tariff updated successfully!') . '</div>';
    }
}

// Get current tariff version info
$current_version_stmt = $pdo->query("SELECT * FROM tariff_versions WHERE is_active = 1");
$current_version = $current_version_stmt->fetch();

// Get all tariff prices for current version
$tariff_stmt = $pdo->prepare("SELECT * FROM tariff_prices WHERE tariff_version_id = ? ORDER BY tent_type, bed_configuration, reservation_source");
$tariff_stmt->execute([$current_version['id']]);
$tariff_prices = $tariff_stmt->fetchAll();

// Fetch service tariff prices for staff and car (current version)
$service_tariff_stmt = $pdo->prepare("SELECT * FROM service_tariff_prices WHERE tariff_version_id = ? ORDER BY service_type");
$service_tariff_stmt->execute([$current_version['id']]);
$service_tariff_prices = [];
foreach ($service_tariff_stmt as $row) {
    $service_tariff_prices[$row['service_type']] = $row;
}

// Fetch boarding prices for current version
$boarding_stmt = $pdo->prepare("SELECT * FROM boarding_prices WHERE tariff_version_id = ? ORDER BY reservation_source, tent_type, bed_configuration, boarding_type");
$boarding_stmt->execute([$current_version['id']]);
$boarding_prices = [];
foreach ($boarding_stmt as $row) {
    $key = $row['reservation_source'] . '_' . $row['tent_type'] . '_' . $row['bed_configuration'] . '_' . $row['boarding_type'];
    $boarding_prices[$key] = $row;
}

// Fetch kids discount percentage for current version
$kids_discount_stmt = $pdo->prepare("SELECT discount_percentage FROM kids_discount_percentage WHERE tariff_version_id = ?");
$kids_discount_stmt->execute([$current_version['id']]);
$kids_discount = $kids_discount_stmt->fetch();
$current_kids_discount = $kids_discount ? $kids_discount['discount_percentage'] : 50;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TARIF - Pricing Management</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .tariff-section {
            margin-top: 30px;
        }
        .tariff-category {
            margin-bottom: 40px;
            background: #fffbe6;
            padding: 30px 24px 24px 24px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(184,134,11,0.07);
        }
        .tariff-category h3 {
            margin-bottom: 18px;
            color: #b8860b;
            border-bottom: 2px solid #b8860b;
            padding-bottom: 7px;
            font-size: 1.3rem;
        }
        .tariff-grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .tariff-header {
            display: table-row;
            background: #f8f9fa;
            font-weight: bold;
            font-size: 1.08rem;
        }
        .tariff-row {
            display: table-row;
            border-bottom: 1px solid #e0c97f;
            background: #fff;
            transition: background 0.2s;
        }
        .tariff-row:hover {
            background: #fffde7;
        }
        .tariff-cell {
            display: table-cell;
            padding: 16px 10px;
            vertical-align: middle;
            border-right: 1px solid #f3e6b3;
            font-size: 1.08rem;
        }
        .tariff-cell:last-child {
            border-right: none;
        }
        .inline-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .price-input {
            width: 110px;
            padding: 7px 10px;
            border: 1px solid #e0c97f;
            border-radius: 5px;
            font-size: 1.08rem;
            background: #fffbe6;
        }
        .btn-small {
            padding: 5px 12px;
            font-size: 1rem;
            border-radius: 5px;
        }
        .price-difference {
            font-size: 13px;
            color: #b8860b;
            font-style: italic;
        }
        .section-description {
            color: #666;
            margin-top: 5px;
            font-style: italic;
            margin-bottom: 10px;
        }
        .version-info {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #2e7d32;
        }
        .version-info strong {
            color: #1b5e20;
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>
    <div class="container">
        <div class="table-header">
            <h2><?php echo t('tariff_management_title', 'TARIF - Pricing Management'); ?></h2>
            <p class="section-description"><?php echo t('tariff_management_description', 'Manage prices based on reservation source (Individual vs Agency) and bed configuration'); ?></p>
        </div>
        
        <?php if (!empty($message)) echo $message; ?>
        <!-- Version Information -->
        <div class="version-info">
            <strong><?php echo t('current_tariff_version', 'Current Tariff Version:'); ?></strong> <?php echo $current_version['name']; ?> 
            (<?php echo t('created_at', 'Created:'); ?> <?php echo date('Y-m-d H:i', strtotime($current_version['created_at'])); ?>)
            <br>
            <small></small>
        </div>
        
        <div class="tariff-section">
            <form method="POST">
                            <!-- ROYAL Tents -->
                <div class="tariff-category">
                    <h3><?php echo t('royal_tents_pricing', 'ROYAL Tents Pricing'); ?></h3>
                <div class="tariff-grid">
                    <div class="tariff-header">
                        <div class="tariff-cell"><?php echo t('bed_configuration', 'Bed Configuration'); ?></div>
                        <div class="tariff-cell"><?php echo t('agency_price', 'Agency Price (TND/night)'); ?></div>
                        <div class="tariff-cell"><?php echo t('individual_price', 'Individual Price (TND/night)'); ?></div>
                            <div class="tariff-cell"><?php echo t('price_difference', 'Diff'); ?></div>
                    </div>
                    <?php
                    $bed_configs = ['single', 'double', 'triple', 'quadruple'];
                    foreach ($bed_configs as $bed_config):
                        $agency_price = null;
                        $individual_price = null;
                        foreach ($tariff_prices as $tariff) {
                            if ($tariff['tent_type'] === 'ROYAL' && $tariff['bed_configuration'] === $bed_config) {
                                if ($tariff['reservation_source'] === 'agency') {
                                    $agency_price = $tariff['price_per_night'];
                                } else {
                                    $individual_price = $tariff['price_per_night'];
                                }
                            }
                        }
                    ?>
                    <div class="tariff-row">
                        <div class="tariff-cell">
                            <strong><?php echo ucfirst($bed_config); ?></strong>
                        </div>
                        <div class="tariff-cell">
                                <input type="number" name="royal_agency[<?php echo $bed_config; ?>]" value="<?php echo $agency_price; ?>" step="0.01" min="0.01" class="price-input" required>
                        </div>
                        <div class="tariff-cell">
                                <input type="number" name="royal_individual[<?php echo $bed_config; ?>]" value="<?php echo $individual_price; ?>" step="0.01" min="0.01" class="price-input" required>
                        </div>
                        <div class="tariff-cell">
                            <span class="price-difference">
                                <?php echo t('price_difference_label', 'Diff:'); ?> <?php echo $individual_price && $agency_price ? number_format($individual_price - $agency_price, 2) : t('na', 'N/A'); ?> TND
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Normal Tents -->
            <div class="tariff-category">
                <h3><?php echo t('normal_tents_pricing', 'Normal Tents Pricing'); ?></h3>
                <div class="tariff-grid">
                    <div class="tariff-header">
                        <div class="tariff-cell"><?php echo t('bed_configuration', 'Bed Configuration'); ?></div>
                        <div class="tariff-cell"><?php echo t('agency_price', 'Agency Price (TND/night)'); ?></div>
                        <div class="tariff-cell"><?php echo t('individual_price', 'Individual Price (TND/night)'); ?></div>
                            <div class="tariff-cell"><?php echo t('price_difference', 'Diff'); ?></div>
                    </div>
                    <?php
                    foreach ($bed_configs as $bed_config):
                        $agency_price = null;
                        $individual_price = null;
                        foreach ($tariff_prices as $tariff) {
                            if ($tariff['tent_type'] === 'NORMAL' && $tariff['bed_configuration'] === $bed_config) {
                                if ($tariff['reservation_source'] === 'agency') {
                                    $agency_price = $tariff['price_per_night'];
                                } else {
                                    $individual_price = $tariff['price_per_night'];
                                }
                            }
                        }
                    ?>
                    <div class="tariff-row">
                        <div class="tariff-cell">
                            <strong><?php echo ucfirst($bed_config); ?></strong>
                        </div>
                        <div class="tariff-cell">
                                <input type="number" name="normal_agency[<?php echo $bed_config; ?>]" value="<?php echo $agency_price; ?>" step="0.01" min="0.01" class="price-input" required>
                        </div>
                        <div class="tariff-cell">
                                <input type="number" name="normal_individual[<?php echo $bed_config; ?>]" value="<?php echo $individual_price; ?>" step="0.01" min="0.01" class="price-input" required>
                        </div>
                        <div class="tariff-cell">
                            <span class="price-difference">
                                <?php echo t('price_difference_label', 'Diff:'); ?> <?php echo $individual_price && $agency_price ? number_format($individual_price - $agency_price, 2) : t('na', 'N/A'); ?> TND
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Staff & 4x4 Pricing -->
            <div class="tariff-category">
                <h3><?php echo t('staff_4x4_pricing', 'Staff & 4x4 Pricing'); ?></h3>
                <div class="tariff-grid">
                    <div class="tariff-header">
                        <div class="tariff-cell"><?php echo t('type', 'Type'); ?></div>
                        <div class="tariff-cell"><?php echo t('price_per_day', 'Price Per Day (TND)'); ?></div>
                    </div>
                    <?php
                    $staff_types = [
                        ['label' => t('driver', 'Driver'), 'service_type' => 'driver'],
                        ['label' => t('guide', 'Guide'), 'service_type' => 'guide'],
                        ['label' => t('4x4_car_1day', '4x4 Car (1 Day)'), 'service_type' => 'car_4x4_1day'],
                        ['label' => t('4x4_car_multiday', '4x4 Car (Multi-Day)'), 'service_type' => 'car_4x4_multiday']
                    ];
                    foreach ($staff_types as $type) {
                        $service = $service_tariff_prices[$type['service_type']] ?? null;
                    ?>
                    <div class="tariff-row">
                        <div class="tariff-cell">
                            <strong><?php echo $type['label']; ?></strong>
                        </div>
                        <div class="tariff-cell">
                                <input type="number" name="service[<?php echo $type['service_type']; ?>]" value="<?php echo $service ? $service['price_per_unit'] : ''; ?>" step="0.10" min="0.00" class="price-input" required>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                <!-- Kids Discount Setting -->
                <div class="tariff-category">
                    <h3><?php echo t('kids_discount_setting', 'Kids Discount Setting'); ?></h3>
                    <div class="tariff-grid">
                        <div class="tariff-header">
                            <div class="tariff-cell"><?php echo t('setting', 'Setting'); ?></div>
                            <div class="tariff-cell"><?php echo t('value', 'Value'); ?></div>
                        </div>
                        <div class="tariff-row">
                            <div class="tariff-cell">
                                <strong><?php echo t('kids_discount_percentage', 'Kids Discount Percentage'); ?></strong>
                            </div>
                            <div class="tariff-cell">
                                <input type="number" name="kids_discount_percentage" value="<?php echo $current_kids_discount; ?>" step="1" min="0" max="100" class="price-input" required>
                                <small><?php echo t('percentage_info', 'Percentage discount for kids (0-100)'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Boarding Prices -->
                <div class="tariff-category">
                    <h3><?php echo t('boarding_prices', 'Boarding Prices'); ?></h3>
                    <div class="tariff-grid">
                        <div class="tariff-header">
                            <div class="tariff-cell"><?php echo t('tent_type', 'Tent Type'); ?></div>
                            <div class="tariff-cell"><?php echo t('bed_configuration', 'Bed Configuration'); ?></div>
                            <div class="tariff-cell"><?php echo t('reservation_source', 'Source'); ?></div>
                            <div class="tariff-cell"><?php echo t('half_board_price', 'Half Board (TND/night)'); ?></div>
                            <div class="tariff-cell"><?php echo t('full_board_price', 'Full Board (TND/night)'); ?></div>
                        </div>
                        <?php
                        $tent_types = ['ROYAL', 'NORMAL'];
                        $sources = ['individual', 'agency'];
                        foreach ($tent_types as $tent_type):
                            foreach ($bed_configs as $bed_config):
                                foreach ($sources as $source):
                                    $half_board_key = $source . '_' . $tent_type . '_' . $bed_config . '_half_board';
                                    $full_board_key = $source . '_' . $tent_type . '_' . $bed_config . '_full_board';
                                    $half_board_price = $boarding_prices[$half_board_key]['price_per_night'] ?? '';
                                    $full_board_price = $boarding_prices[$full_board_key]['price_per_night'] ?? '';
                        ?>
                        <div class="tariff-row">
                            <div class="tariff-cell">
                                <strong><?php echo $tent_type; ?></strong>
                            </div>
                            <div class="tariff-cell">
                                <strong><?php echo ucfirst($bed_config); ?></strong>
                            </div>
                            <div class="tariff-cell">
                                <strong><?php echo ucfirst($source); ?></strong>
                            </div>
                            <div class="tariff-cell">
                                <input type="number" name="boarding[<?php echo $source; ?>][<?php echo $tent_type; ?>][<?php echo $bed_config; ?>][half_board]" value="<?php echo $half_board_price; ?>" step="0.01" min="0" class="price-input" required>
                            </div>
                            <div class="tariff-cell">
                                <input type="number" name="boarding[<?php echo $source; ?>][<?php echo $tent_type; ?>][<?php echo $bed_config; ?>][full_board]" value="<?php echo $full_board_price; ?>" step="0.01" min="0" class="price-input" required>
                            </div>
                        </div>
                        <?php 
                                endforeach;
                            endforeach;
                        endforeach; 
                        ?>
                    </div>
                </div>
                <div style="margin-top:24px;display:flex;gap:16px;">
                    <button type="submit" name="create_new_tariff" class="btn btn-primary"><?php echo t('create_new_tariff_button', 'Create New Tariff Version'); ?></button>
                    <button type="submit" name="update_current_tariff" class="btn btn-success"><?php echo t('update_current_tariff_button', 'Update Current Tariff Version'); ?></button>
            </div>
            </form>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const allInputs = document.querySelectorAll('.price-input');
        function checkIfChanged() {
            let changed = false;
            // Tent/resource prices
            for (const key in originalTariffPrices) {
                const [type, source, bed] = key.split('_');
                const input = document.querySelector(`[name="${type}_${source}[${bed}]"]`);
                if (input) {
                    const inputVal = parseFloat(input.value);
                    const origVal = parseFloat(originalTariffPrices[key]);
                    if (inputVal !== origVal) {
                        changed = true;
                    }
                    console.log(`Comparing ${type}_${source}[${bed}]: input=${inputVal}, original=${origVal}`);
                }
            }
            // Service/resource prices
            for (const key in originalServicePrices) {
                const input = document.querySelector(`[name="service[${key}]"]`);
                if (input) {
                    const inputVal = parseFloat(input.value);
                    const origVal = parseFloat(originalServicePrices[key]);
                    if (inputVal !== origVal) {
                        changed = true;
                    }
                    console.log(`Comparing service[${key}]: input=${inputVal}, original=${origVal}`);
                }
            }
            // The createBtn is removed, so we don't need to check if it's disabled.
            // The title logic is also removed as there's no button.
        }
        allInputs.forEach(input => {
            input.addEventListener('input', checkIfChanged);
        });
        checkIfChanged();
    });
    </script>
</body>
</html> 