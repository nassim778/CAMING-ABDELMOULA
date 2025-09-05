<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';

// Check if logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !in_array($_SESSION['user_role'], ['Director', 'Accountant',  'Admin'])) {
    header('Location: dashboard.php');
    exit();
}

$message = '';
$error = '';

// Handle add car
if (isset($_POST['add_car'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO cars (registration_number, number_of_places, owner, car_type) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_POST['registration_number'],
            $_POST['number_of_places'],
            $_POST['owner'],
            $_POST['car_type']
        ]);
        $message = 'Car added successfully!';
    } catch (Exception $e) {
        $error = 'Error adding car: ' . $e->getMessage();
    }
}
// Handle add tent
if (isset($_POST['add_tent'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO tents (tent_number, tent_type) VALUES (?, ?)");
        $stmt->execute([
            $_POST['tent_number'],
            $_POST['tent_type']
        ]);
        $message = 'Tent added successfully!';
    } catch (Exception $e) {
        $error = 'Error adding tent: ' . $e->getMessage();
    }
}
// Handle add human resource
if (isset($_POST['add_human'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO human_resources (full_name, phone, id_number, work_position) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_POST['full_name'],
            $_POST['phone'],
            $_POST['id_number'],
            $_POST['work_position']
        ]);
        $message = 'Human resource added successfully!';
    } catch (Exception $e) {
        $error = 'Error adding human resource: ' . $e->getMessage();
    }
}
// Handle delete car
if (isset($_POST['delete_car'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM cars WHERE id = ?");
        $stmt->execute([$_POST['car_id']]);
        $message = 'Car deleted successfully!';
    } catch (Exception $e) {
        $error = 'Error deleting car: ' . $e->getMessage();
    }
}
// Handle delete tent
if (isset($_POST['delete_tent'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM tents WHERE id = ?");
        $stmt->execute([$_POST['tent_id']]);
        $message = 'Tent deleted successfully!';
    } catch (Exception $e) {
        $error = 'Error deleting tent: ' . $e->getMessage();
    }
}
// Handle delete human resource
if (isset($_POST['delete_human'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM human_resources WHERE id = ?");
        $stmt->execute([$_POST['human_id']]);
        $message = 'Human resource deleted successfully!';
    } catch (Exception $e) {
        $error = 'Error deleting human resource: ' . $e->getMessage();
    }
}
// Handle edit car
if (isset($_POST['edit_car'])) {
    try {
        $stmt = $pdo->prepare("UPDATE cars SET registration_number=?, number_of_places=?, owner=?, car_type=? WHERE id=?");
        $stmt->execute([
            $_POST['edit_registration_number'],
            $_POST['edit_number_of_places'],
            $_POST['edit_owner'],
            $_POST['edit_car_type'],
            $_POST['car_id']
        ]);
        $message = 'Car updated successfully!';
    } catch (Exception $e) {
        $error = 'Error updating car: ' . $e->getMessage();
    }
}
// Handle edit tent
if (isset($_POST['edit_tent'])) {
    try {
        $stmt = $pdo->prepare("UPDATE tents SET tent_number=?, tent_type=? WHERE id=?");
        $stmt->execute([
            $_POST['edit_tent_number'],
            $_POST['edit_tent_type'],
            $_POST['tent_id']
        ]);
        $message = 'Tent updated successfully!';
    } catch (Exception $e) {
        $error = 'Error updating tent: ' . $e->getMessage();
    }
}
// Handle edit human resource
if (isset($_POST['edit_human'])) {
    try {
        $stmt = $pdo->prepare("UPDATE human_resources SET full_name=?, phone=?, id_number=?, work_position=? WHERE id=?");
        $stmt->execute([
            $_POST['edit_full_name'],
            $_POST['edit_phone'],
            $_POST['edit_id_number'],
            $_POST['edit_work_position'],
            $_POST['human_id']
        ]);
        $message = 'Human resource updated successfully!';
    } catch (Exception $e) {
        $error = 'Error updating human resource: ' . $e->getMessage();
    }
}
// Handle toggle car active status
if (isset($_POST['toggle_car_active'])) {
    try {
        $stmt = $pdo->prepare("UPDATE cars SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['car_id']]);
        $message = 'Car status updated!';
    } catch (Exception $e) {
        $error = 'Error updating car status: ' . $e->getMessage();
    }
}
// Handle toggle tent active status
if (isset($_POST['toggle_tent_active'])) {
    try {
        $stmt = $pdo->prepare("UPDATE tents SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['tent_id']]);
        $message = 'Tent status updated!';
    } catch (Exception $e) {
        $error = 'Error updating tent status: ' . $e->getMessage();
    }
}
// Handle toggle human resource active status
if (isset($_POST['toggle_human_active'])) {
    try {
        $stmt = $pdo->prepare("UPDATE human_resources SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['human_id']]);
        $message = 'Human resource status updated!';
    } catch (Exception $e) {
        $error = 'Error updating human resource status: ' . $e->getMessage();
    }
}

// Get all cars, tents, and human resources
$cars = $pdo->query("SELECT * FROM cars ORDER BY owner, registration_number")->fetchAll();
$tents = $pdo->query("SELECT * FROM tents ORDER BY tent_type, tent_number")->fetchAll();
$humans = $pdo->query("SELECT * FROM human_resources ORDER BY full_name")->fetchAll();

// Count cars by owner
$car_owner_counts = [];
foreach ($cars as $car) {
    $owner = $car['owner'];
    if (!isset($car_owner_counts[$owner])) {
        $car_owner_counts[$owner] = 0;
    }
    $car_owner_counts[$owner]++;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resources Management - ABDELMOULA CAMP</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>
    <div class="container">
        <div class="table-header">
            <h2><?php echo t('material_human_resources', 'Material & Human Resources'); ?></h2>
            <div style="margin-top: 10px;">
                <a href="work_schedule.php" class="btn btn-success"><?php echo t('general_work_schedule', '📅 General Work Schedule'); ?></a>
            </div>
        </div>
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <!-- Cars Section -->
        <div class="form-section">
            <h3><?php echo t('material_resources_cars', 'Material Resources - Cars'); ?></h3>
            <form method="POST" class="form-row" style="margin-bottom: 1rem;">
                <input type="hidden" name="add_car" value="1">
                <div class="form-group">
                    <label for="registration_number"><?php echo t('registration_number', 'Registration Number'); ?></label>
                    <input type="text" id="registration_number" name="registration_number" required>
                </div>
                <div class="form-group">
                    <label for="number_of_places"><?php echo t('number_of_places', 'Number of Places'); ?></label>
                    <input type="number" id="number_of_places" name="number_of_places" min="1" required>
                </div>
                <div class="form-group">
                    <label for="owner"><?php echo t('owner', 'Owner'); ?></label>
                    <input type="text" id="owner" name="owner" required>
                </div>
                <div class="form-group">
                    <label for="car_type"><?php echo t('car_type', 'Car Type'); ?></label>
                    <input type="text" id="car_type" name="car_type" required>
                </div>
                <button type="submit" class="sahara-btn"><?php echo t('add_car', 'Add Car'); ?></button>
            </form>
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('registration_number', 'Registration Number'); ?></th>
                        <th class="owner-col"><?php echo t('number_of_places', 'Number of Places'); ?></th>
                        <th class="owner-col"><?php echo t('owner', 'Owner'); ?></th>
                        <th class="car-type-col"><?php echo t('car_type', 'Car Type'); ?></th>
                        <th><?php echo t('status', 'Status'); ?></th>
                        <th><?php echo t('created_at', 'Created At'); ?></th>
                        <th class="actions-col"><?php echo t('actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cars as $car): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($car['registration_number']); ?></td>
                        <td class="owner-col"><?php echo $car['number_of_places']; ?></td>
                        <td class="owner-col"><?php echo htmlspecialchars($car['owner']); ?></td>
                        <td class="car-type-col"><?php echo htmlspecialchars($car['car_type']); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="toggle_car_active" value="1">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <button type="submit" class="btn btn-status" style="background:<?php echo $car['is_active'] ? '#28a745' : '#dc3545'; ?>;color:#fff;">
                                    <?php echo $car['is_active'] ? t('active', 'Active') : t('unactive', 'Unactive'); ?>
                                </button>
                            </form>
                        </td>
                        <td><?php echo isset($car['created_at']) ? date('Y-m-d H:i', strtotime($car['created_at'])) : '-'; ?></td>
                        <td class="actions-col">
                            <form method="POST" class="form-row" style="display: inline;">
                                <input type="hidden" name="delete_car" value="1">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <button type="submit" class="btn btn-danger"><?php echo t('delete', 'Delete'); ?></button>
                            </form>
                            <form method="POST" class="form-row" style="display: inline;" onsubmit="return false;">
                                <button type="button" class="btn btn-primary" onclick="openEditCarModal(<?php echo htmlspecialchars(json_encode($car)); ?>)"><?php echo t('edit', 'Edit'); ?></button>
                            </form>
                            <a href="resource_schedule.php?type=car&id=<?php echo $car['id']; ?>" class="btn btn-info" style="margin-left:4px;"><?php echo t('work_schedule', 'Work Schedule'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="service-total" style="margin-top: 0.5rem;">
                <strong><?php echo t('total_cars', 'Total Cars:'); ?></strong> <?php echo count($cars); ?>
                <?php if (count($car_owner_counts) > 0): ?>
                    <span style="margin-left: 10px; color: #555; font-size: 13px;">
                        <?php foreach ($car_owner_counts as $owner => $count): ?>
                            <?php echo htmlspecialchars($owner) . ': ' . $count; ?>&nbsp;
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <!-- Tents Section -->
        <div class="form-section">
            <h3><?php echo t('material_resources_tents', 'Material Resources - Tents'); ?></h3>
            <form method="POST" class="form-row" style="margin-bottom: 1rem;">
                <input type="hidden" name="add_tent" value="1">
                <div class="form-group">
                    <label for="tent_number"><?php echo t('tent_number', 'Tent Number'); ?></label>
                    <input type="text" id="tent_number" name="tent_number" required>
                </div>
                <div class="form-group">
                    <label for="tent_type"><?php echo t('tent_type', 'Tent Type'); ?></label>
                    <input type="text" id="tent_type" name="tent_type" required>
                </div>
                <button type="submit" class="sahara-btn"><?php echo t('add_tent', 'Add Tent'); ?></button>
            </form>
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('tent_number', 'Tent Number'); ?></th>
                        <th class="tent-type-col"><?php echo t('tent_type', 'Tent Type'); ?></th>
                        <th><?php echo t('status', 'Status'); ?></th>
                        <th><?php echo t('created_at', 'Created At'); ?></th>
                        <th class="actions-col"><?php echo t('actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tents as $tent): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tent['tent_number']); ?></td>
                        <td class="tent-type-col"><?php echo htmlspecialchars($tent['tent_type']); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="toggle_tent_active" value="1">
                                <input type="hidden" name="tent_id" value="<?php echo $tent['id']; ?>">
                                <button type="submit" class="btn btn-status" style="background:<?php echo $tent['is_active'] ? '#28a745' : '#dc3545'; ?>;color:#fff;">
                                    <?php echo $tent['is_active'] ? t('active', 'Active') : t('unactive', 'Unactive'); ?>
                                </button>
                            </form>
                        </td>
                        <td><?php echo isset($tent['created_at']) ? date('Y-m-d H:i', strtotime($tent['created_at'])) : '-'; ?></td>
                        <td class="actions-col">
                            <form method="POST" class="form-row" style="display: inline;">
                                <input type="hidden" name="delete_tent" value="1">
                                <input type="hidden" name="tent_id" value="<?php echo $tent['id']; ?>">
                                <button type="submit" class="btn btn-danger"><?php echo t('delete', 'Delete'); ?></button>
                            </form>
                            <form method="POST" class="form-row" style="display: inline;" onsubmit="return false;">
                                <button type="button" class="btn btn-primary" onclick="openEditTentModal(<?php echo htmlspecialchars(json_encode($tent)); ?>)"><?php echo t('edit', 'Edit'); ?></button>
                            </form>
                            <a href="resource_schedule.php?type=tent&id=<?php echo $tent['id']; ?>" class="btn btn-info" style="margin-left:4px;"><?php echo t('work_schedule', 'Work Schedule'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            // Count tents by tent_type
            $tent_type_counts = [];
            foreach ($tents as $tent) {
                $type = $tent['tent_type'];
                if (!isset($tent_type_counts[$type])) {
                    $tent_type_counts[$type] = 0;
                }
                $tent_type_counts[$type]++;
            }
            ?>
            <div class="service-total" style="margin-top: 0.5rem;">
                <strong><?php echo t('total_tents', 'Total Tents:'); ?></strong> <?php echo count($tents); ?>
                <?php if (count($tent_type_counts) > 0): ?>
                    <span style="margin-left: 10px; color: #555; font-size: 13px;">
                        <?php foreach ($tent_type_counts as $type => $count): ?>
                            <?php echo htmlspecialchars($type) . ': ' . $count; ?>&nbsp;
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <!-- Human Resources Section -->
        <div class="form-section">
            <h3><?php echo t('human_resources', 'Human Resources'); ?></h3>
            <form method="POST" class="form-row" style="margin-bottom: 1rem;">
                <input type="hidden" name="add_human" value="1">
                <div class="form-group">
                    <label for="full_name"><?php echo t('full_name', 'Full Name'); ?></label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>
                <div class="form-group">
                    <label for="phone"><?php echo t('phone', 'Phone'); ?></label>
                    <input type="text" id="phone" name="phone">
                </div>
                <div class="form-group">
                    <label for="id_number"><?php echo t('id_number', 'ID Number'); ?></label>
                    <input type="text" id="id_number" name="id_number" required>
                </div>
                <div class="form-group">
                    <label for="work_position"><?php echo t('work_position', 'Work Position'); ?></label>
                    <input type="text" id="work_position" name="work_position" required>
                </div>
                <button type="submit" class="sahara-btn"><?php echo t('add_human_resource', 'Add Human Resource'); ?></button>
            </form>
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('full_name', 'Full Name'); ?></th>
                        <th><?php echo t('phone', 'Phone'); ?></th>
                        <th><?php echo t('id_number', 'ID Number'); ?></th>
                        <th class="work-position-col"><?php echo t('work_position', 'Work Position'); ?></th>
                        <th><?php echo t('status', 'Status'); ?></th>
                        <th><?php echo t('created_at', 'Created At'); ?></th>
                        <th class="actions-col"><?php echo t('actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($humans as $human): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($human['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($human['phone']); ?></td>
                        <td><?php echo htmlspecialchars($human['id_number']); ?></td>
                        <td class="work-position-col"><?php echo htmlspecialchars($human['work_position']); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="toggle_human_active" value="1">
                                <input type="hidden" name="human_id" value="<?php echo $human['id']; ?>">
                                <button type="submit" class="btn btn-status" style="background:<?php echo $human['is_active'] ? '#28a745' : '#dc3545'; ?>;color:#fff;">
                                    <?php echo $human['is_active'] ? t('active', 'Active') : t('unactive', 'Unactive'); ?>
                                </button>
                            </form>
                        </td>
                        <td><?php echo isset($human['created_at']) ? date('Y-m-d H:i', strtotime($human['created_at'])) : '-'; ?></td>
                        <td class="actions-col">
                            <form method="POST" class="form-row" style="display: inline;">
                                <input type="hidden" name="delete_human" value="1">
                                <input type="hidden" name="human_id" value="<?php echo $human['id']; ?>">
                                <button type="submit" class="btn btn-danger"><?php echo t('delete', 'Delete'); ?></button>
                            </form>
                            <form method="POST" class="form-row" style="display: inline;" onsubmit="return false;">
                                <button type="button" class="btn btn-primary" onclick="openEditHumanModal(<?php echo htmlspecialchars(json_encode($human)); ?>)"><?php echo t('edit', 'Edit'); ?></button>
                            </form>
                            <a href="resource_schedule.php?type=human&id=<?php echo $human['id']; ?>" class="btn btn-info" style="margin-left:4px;"><?php echo t('work_schedule', 'Work Schedule'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="service-total" style="margin-top: 0.5rem;">
                <strong><?php echo t('total_human_resources', 'Total Human Resources:'); ?></strong> <?php echo count($humans); ?>
            </div>
        </div>
    </div>
    <!-- Car Edit Modal -->
    <div id="editCarModal" class="modal" style="display:none;">
      <div class="modal-content">
        <span class="close" onclick="closeEditCarModal()">&times;</span>
        <h3><?php echo t('edit_car', 'Edit Car'); ?></h3>
        <form method="POST" id="editCarForm">
          <input type="hidden" name="edit_car" value="1">
          <input type="hidden" name="car_id" id="edit_car_id">
          <div class="form-group">
            <label for="edit_registration_number"><?php echo t('registration_number', 'Registration Number'); ?></label>
            <input type="text" id="edit_registration_number" name="edit_registration_number" required>
          </div>
          <div class="form-group">
            <label for="edit_number_of_places"><?php echo t('number_of_places', 'Number of Places'); ?></label>
            <input type="number" id="edit_number_of_places" name="edit_number_of_places" min="1" required>
          </div>
          <div class="form-group">
            <label for="edit_owner"><?php echo t('owner', 'Owner'); ?></label>
            <input type="text" id="edit_owner" name="edit_owner" required>
          </div>
          <div class="form-group">
            <label for="edit_car_type"><?php echo t('car_type', 'Car Type'); ?></label>
            <input type="text" id="edit_car_type" name="edit_car_type" required>
          </div>
          <button type="submit" class="btn btn-primary"><?php echo t('update_car', 'Update Car'); ?></button>
          <button type="button" onclick="closeEditCarModal()" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></button>
        </form>
      </div>
    </div>
    <!-- Tent Edit Modal -->
    <div id="editTentModal" class="modal" style="display:none;">
      <div class="modal-content">
        <span class="close" onclick="closeEditTentModal()">&times;</span>
        <h3><?php echo t('edit_tent', 'Edit Tent'); ?></h3>
        <form method="POST" id="editTentForm">
          <input type="hidden" name="edit_tent" value="1">
          <input type="hidden" name="tent_id" id="edit_tent_id">
          <div class="form-group">
            <label for="edit_tent_number"><?php echo t('tent_number', 'Tent Number'); ?></label>
            <input type="text" id="edit_tent_number" name="edit_tent_number" required>
          </div>
          <div class="form-group">
            <label for="edit_tent_type"><?php echo t('tent_type', 'Tent Type'); ?></label>
            <input type="text" id="edit_tent_type" name="edit_tent_type" required>
          </div>
          <button type="submit" class="btn btn-primary"><?php echo t('update_tent', 'Update Tent'); ?></button>
          <button type="button" onclick="closeEditTentModal()" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></button>
        </form>
      </div>
    </div>
    <!-- Human Edit Modal -->
    <div id="editHumanModal" class="modal" style="display:none;">
      <div class="modal-content">
        <span class="close" onclick="closeEditHumanModal()">&times;</span>
        <h3><?php echo t('edit_human_resource', 'Edit Human Resource'); ?></h3>
        <form method="POST" id="editHumanForm">
          <input type="hidden" name="edit_human" value="1">
          <input type="hidden" name="human_id" id="edit_human_id">
          <div class="form-group">
            <label for="edit_full_name"><?php echo t('full_name', 'Full Name'); ?></label>
            <input type="text" id="edit_full_name" name="edit_full_name" required>
          </div>
          <div class="form-group">
            <label for="edit_phone"><?php echo t('phone', 'Phone'); ?></label>
            <input type="text" id="edit_phone" name="edit_phone">
          </div>
          <div class="form-group">
            <label for="edit_id_number"><?php echo t('id_number', 'ID Number'); ?></label>
            <input type="text" id="edit_id_number" name="edit_id_number" required>
          </div>
          <div class="form-group">
            <label for="edit_work_position"><?php echo t('work_position', 'Work Position'); ?></label>
            <input type="text" id="edit_work_position" name="edit_work_position" required>
          </div>
          <button type="submit" class="btn btn-primary"><?php echo t('update_human_resource', 'Update Human Resource'); ?></button>
          <button type="button" onclick="closeEditHumanModal()" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></button>
        </form>
      </div>
    </div>
    <script>
    // Add confirmation for all delete forms
    document.addEventListener('DOMContentLoaded', function() {
        // Select all delete buttons in forms
        var deleteButtons = document.querySelectorAll('form input[type="hidden"][name^="delete_"] + input[type="hidden"] + button.btn-danger');
        if (deleteButtons.length === 0) {
            // Fallback: select all delete buttons by class
            deleteButtons = document.querySelectorAll('button.btn-danger');
        }
        deleteButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                var confirmed = confirm('<?php echo t('confirm_delete', 'Are you sure you want to delete this item? This action cannot be undone.'); ?>');
                if (!confirmed) {
                    e.preventDefault();
                }
            });
        });
    });
    // Modal logic for Car
    function openEditCarModal(car) {
      document.getElementById('edit_car_id').value = car.id;
      document.getElementById('edit_registration_number').value = car.registration_number;
      document.getElementById('edit_number_of_places').value = car.number_of_places;
      document.getElementById('edit_owner').value = car.owner;
      document.getElementById('edit_car_type').value = car.car_type;
      document.getElementById('editCarModal').style.display = 'block';
    }
    function closeEditCarModal() {
      document.getElementById('editCarModal').style.display = 'none';
    }
    // Modal logic for Tent
    function openEditTentModal(tent) {
      document.getElementById('edit_tent_id').value = tent.id;
      document.getElementById('edit_tent_number').value = tent.tent_number;
      document.getElementById('edit_tent_type').value = tent.tent_type;
      document.getElementById('editTentModal').style.display = 'block';
    }
    function closeEditTentModal() {
      document.getElementById('editTentModal').style.display = 'none';
    }
    // Modal logic for Human Resource
    function openEditHumanModal(human) {
      document.getElementById('edit_human_id').value = human.id;
      document.getElementById('edit_full_name').value = human.full_name;
      document.getElementById('edit_phone').value = human.phone;
      document.getElementById('edit_id_number').value = human.id_number;
      document.getElementById('edit_work_position').value = human.work_position;
      document.getElementById('editHumanModal').style.display = 'block';
    }
    function closeEditHumanModal() {
      document.getElementById('editHumanModal').style.display = 'none';
    }
    // Close modals on outside click
    window.onclick = function(event) {
      if (event.target == document.getElementById('editCarModal')) closeEditCarModal();
      if (event.target == document.getElementById('editTentModal')) closeEditTentModal();
      if (event.target == document.getElementById('editHumanModal')) closeEditHumanModal();
    }
    </script>
</body>
</html> 