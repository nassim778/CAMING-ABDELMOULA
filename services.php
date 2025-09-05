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

$message = '';
$error = '';

// Handle service operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_service'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO services (name, description, price, is_active) VALUES (?, ?, ?, ?)");
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $stmt->execute([
                $_POST['name'],
                $_POST['description'],
                $_POST['price'],
                $is_active
            ]);
            $message = 'Service added successfully!';
        } catch (Exception $e) {
            $error = 'Error adding service: ' . $e->getMessage();
        }
    } elseif (isset($_POST['update_service'])) {
        try {
            $stmt = $pdo->prepare("UPDATE services SET name = ?, description = ?, price = ?, is_active = ? WHERE id = ?");
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $stmt->execute([
                $_POST['name'],
                $_POST['description'],
                $_POST['price'],
                $is_active,
                $_POST['service_id']
            ]);
            $message = 'Service updated successfully!';
        } catch (Exception $e) {
            $error = 'Error updating service: ' . $e->getMessage();
        }
    } elseif (isset($_POST['delete_service'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
            $stmt->execute([$_POST['service_id']]);
            $message = 'Service deleted successfully!';
        } catch (Exception $e) {
            $error = 'Error deleting service: ' . $e->getMessage();
        }
    } elseif (isset($_POST['update_tariff'])) {
        try {
            $stmt = $pdo->prepare("UPDATE tariff_prices SET price_per_night = ? WHERE id = ?");
            $stmt->execute([
                $_POST['price_per_night'],
                $_POST['tariff_id']
            ]);
            $message = 'Tariff price updated successfully!';
        } catch (Exception $e) {
            $error = 'Error updating tariff: ' . $e->getMessage();
        }
    }
}

// Get all services
$stmt = $pdo->query("SELECT * FROM services ORDER BY name");
$services = $stmt->fetchAll();

// Get all tariff prices
$tariff_stmt = $pdo->query("SELECT * FROM tariff_prices ORDER BY tent_type, bed_configuration, reservation_source");
$tariff_prices = $tariff_stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - ABDELMOULA CAMP</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .section-description {
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        
        .tariff-section {
            margin-top: 20px;
        }
        
        .tariff-category {
            margin-bottom: 30px;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
        }
        
        .tariff-category h3 {
            margin-bottom: 15px;
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 5px;
        }
        
        .tariff-grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
        }
        
        .tariff-header {
            display: table-row;
            background: #e9ecef;
            font-weight: bold;
        }
        
        .tariff-row {
            display: table-row;
            border-bottom: 1px solid #dee2e6;
        }
        
        .tariff-row:hover {
            background: #f8f9fa;
        }
        
        .tariff-cell {
            display: table-cell;
            padding: 12px 8px;
            vertical-align: middle;
            border-right: 1px solid #dee2e6;
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
            width: 100px;
            padding: 6px 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .price-difference {
            font-size: 12px;
            color: #666;
            font-style: italic;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .services-overview-section {
            margin-top: 30px;
            background: #fffbe6;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(184,134,11,0.07);
            padding: 28px 24px 18px 24px;
        }
        .services-overview-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            overflow-x: unset;
        }
        .service-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 6px rgba(184,134,11,0.06);
            border: 1.5px solid #f3e6b3;
            padding: 18px 16px 14px 16px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            transition: box-shadow 0.18s, border-color 0.18s, transform 0.18s;
            position: relative;
        }
        .service-card .service-header {
            font-size: 1.08rem;
            font-weight: 600;
            color: #b8860b;
            margin-bottom: 8px;
        }
        .service-card .service-price {
            font-size: 1.02rem;
            color: #333;
            margin-bottom: 10px;
        }
        .service-card .service-status {
            position: absolute;
            top: 10px;
            right: 14px;
            font-size: 0.92rem;
            color: #fff;
            background: #b8860b;
            border-radius: 12px;
            padding: 2px 10px;
            font-weight: 500;
            letter-spacing: 0.01em;
        }
        .service-card.inactive {
            opacity: 0.6;
            background: #f8f9fa;
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="table-header">
            <h2><?php echo t('services_management', 'Services Management'); ?></h2>
            <button onclick="showAddService()" class="btn btn-primary"><?php echo t('add_new_service', 'Add New Service'); ?></button>
            <a href="dashboard.php" class="btn btn-secondary"><?php echo t('back_to_dashboard', 'Back to Dashboard'); ?></a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Services Table -->
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('service_name', 'Service Name'); ?></th>
                        <th><?php echo t('description', 'Description'); ?></th>
                        <th><?php echo t('price', 'Price (TND)'); ?></th>
                        <th><?php echo t('status', 'Status'); ?></th>
                        <th><?php echo t('actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $service): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($service['name']); ?></td>
                        <td><?php echo htmlspecialchars($service['description']); ?></td>
                        <td><?php echo number_format($service['price'], 2); ?> TND</td>
                        <td>
                            <span class="payment-status <?php echo $service['is_active'] ? 'paid' : 'pending'; ?>">
                                <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <button onclick="editService(<?php echo $service['id']; ?>)" class="btn btn-small btn-primary"><?php echo t('edit', 'Edit'); ?></button>
                            <button onclick="deleteService(<?php echo $service['id']; ?>, '<?php echo htmlspecialchars($service['name']); ?>')" class="btn btn-small btn-danger"><?php echo t('delete', 'Delete'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Services Overview for Frontend -->
        <div class="services-overview-section">
            <div class="services-overview-header">
                <h2><?php echo t('services_overview', 'Services Overview'); ?> <span style="font-size:1rem;font-weight:400;color:#b8860b;"><?php echo t('active_services', '(ACTIVE)'); ?></span></h2>
            </div>
            <div class="services-grid">
                <?php foreach ($services as $service): ?>
                    <?php if ($service['is_active']): ?>
                    <div class="service-card">
                        <div class="service-header"><?php echo htmlspecialchars($service['name']); ?></div>
                        <div class="service-price">Price: <strong><?php echo number_format($service['price'], 2); ?> TND</strong></div>
                        <?php if (!empty($service['description'])): ?>
                        <div class="service-desc" style="font-size:0.97rem;color:#666;margin-bottom:8px;"><?php echo htmlspecialchars($service['description']); ?></div>
                        <?php endif; ?>
                        <span class="service-status"><?php echo t('active', 'Active'); ?></span>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div id="addModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeAddModal()">&times;</span>
            <h3><?php echo t('add_new_service', 'Add New Service'); ?></h3>
            <form method="POST">
                <input type="hidden" name="add_service" value="1">
                
                <div class="form-group">
                    <label for="add_name"><?php echo t('service_name', 'Service Name'); ?> *</label>
                    <input type="text" id="add_name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="add_description"><?php echo t('description', 'Description'); ?></label>
                    <textarea id="add_description" name="description" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="add_price"><?php echo t('price', 'Price (TND)'); ?> *</label>
                    <input type="number" id="add_price" name="price" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="add_is_active" name="is_active" checked>
                        <?php echo t('active', 'Active'); ?>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary"><?php echo t('add_service', 'Add Service'); ?></button>
                <button type="button" onclick="closeAddModal()" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></button>
            </form>
        </div>
    </div>

    <!-- Edit Service Modal -->
    <div id="editModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h3><?php echo t('edit_service', 'Edit Service'); ?></h3>
            <form method="POST">
                <input type="hidden" name="service_id" id="edit_service_id">
                <input type="hidden" name="update_service" value="1">
                
                <div class="form-group">
                    <label for="edit_name"><?php echo t('service_name', 'Service Name'); ?> *</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_description"><?php echo t('description', 'Description'); ?></label>
                    <textarea id="edit_description" name="description" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="edit_price"><?php echo t('price', 'Price (TND)'); ?> *</label>
                    <input type="number" id="edit_price" name="price" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="edit_is_active" name="is_active">
                        <?php echo t('active', 'Active'); ?>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary"><?php echo t('update_service', 'Update Service'); ?></button>
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></button>
            </form>
        </div>
    </div>

    <!-- Delete Service Form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="delete_service" value="1">
        <input type="hidden" name="service_id" id="delete_service_id">
    </form>

    <script>
        function showAddService() {
            document.getElementById('addModal').style.display = 'block';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function editService(serviceId) {
            // Get service data and populate modal
            const services = <?php echo json_encode($services); ?>;
            const service = services.find(s => s.id == serviceId);
            
            if (service) {
                document.getElementById('edit_service_id').value = service.id;
                document.getElementById('edit_name').value = service.name;
                document.getElementById('edit_description').value = service.description;
                document.getElementById('edit_price').value = service.price;
                document.getElementById('edit_is_active').checked = service.is_active == 1;
                
                document.getElementById('editModal').style.display = 'block';
            }
        }

        function deleteService(serviceId, serviceName) {
            if (confirm('<?php echo t('confirm_delete_service', 'Are you sure you want to delete the service "'); ?>' + serviceName + '<?php echo t('confirm_delete_service_end', '"?'); ?>')) {
                document.getElementById('delete_service_id').value = serviceId;
                document.getElementById('deleteForm').submit();
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            if (event.target == addModal) {
                addModal.style.display = 'none';
            }
            if (event.target == editModal) {
                editModal.style.display = 'none';
            }
        }
    </script>
</body>
</html> 