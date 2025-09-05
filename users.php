<?php
require_once 'config/session_config.php';
require_once 'includes/session_check.php';
require_once 'config/database.php';
require_once 'includes/translation.php';

// Check if logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || $_SESSION['user_role'] !== 'Director') {
    header('Location: dashboard.php');
    exit();
}

$message = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (name, id_number, phone, role, username, password) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['id_number'],
                        $_POST['phone'],
                        $_POST['role'],
                        $_POST['username'],
                        $_POST['password']
                    ]);
                    $message = t('user_added_success', 'User added successfully!');
                } catch (Exception $e) {
                    $error = t('error_adding_user', 'Error adding user: ') . $e->getMessage();
                }
                break;
                
            case 'edit':
                try {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, id_number = ?, phone = ?, role = ?, username = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['id_number'],
                        $_POST['phone'],
                        $_POST['role'],
                        $_POST['username'],
                        $_POST['user_id']
                    ]);
                    
                    // Update password if provided
                    if (!empty($_POST['password'])) {
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$_POST['password'], $_POST['user_id']]);
                    }
                    
                    $message = t('user_updated_success', 'User updated successfully!');
                } catch (Exception $e) {
                    $error = t('error_updating_user', 'Error updating user: ') . $e->getMessage();
                }
                break;
                
            case 'delete':
                try {
                    // Don't allow deleting the current user
                    if ($_POST['user_id'] == $_SESSION['user_id']) {
                        $error = t('cannot_delete_self', 'You cannot delete your own account!');
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$_POST['user_id']]);
                        $message = t('user_deleted_success', 'User deleted successfully!');
                    }
                } catch (Exception $e) {
                    $error = t('error_deleting_user', 'Error deleting user: ') . $e->getMessage();
                }
                break;
                
            case 'toggle_status':
                try {
                    // Don't allow deactivating the current user
                    if ($_POST['user_id'] == $_SESSION['user_id']) {
                        $error = t('cannot_deactivate_self', 'You cannot deactivate your own account!');
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
                        $stmt->execute([$_POST['user_id']]);
                        $message = t('user_status_updated', 'User status updated successfully!');
                    }
                } catch (Exception $e) {
                    $error = t('error_updating_status', 'Error updating user status: ') . $e->getMessage();
                }
                break;
        }
    }
}

// Get all users
$users_stmt = $pdo->query("SELECT * FROM users ORDER BY member_since DESC");
$users = $users_stmt->fetchAll();

// Get user for editing
$edit_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $edit_stmt->execute([$_GET['edit']]);
    $edit_user = $edit_stmt->fetch();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - ABDELMOULA CAMP</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="table-header">
            <h2><?php echo t('users_management_title', 'Users Management'); ?></h2>
            <button class="btn btn-primary" onclick="showAddForm()"><?php echo t('add_new_user_button', 'Add New User'); ?></button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Add/Edit User Form -->
        <div id="user-form" class="form-container" style="display: <?php echo $edit_user ? 'block' : 'none'; ?>;">
            <h3><?php echo $edit_user ? t('edit_user_title', 'Edit User') : t('add_new_user_title', 'Add New User'); ?></h3>
            <form method="POST" class="form-container">
                <input type="hidden" name="action" value="<?php echo $edit_user ? 'edit' : 'add'; ?>">
                <?php if ($edit_user): ?>
                    <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="name"><?php echo t('full_name_label', 'Full Name *'); ?></label>
                        <input type="text" id="name" name="name" value="<?php echo $edit_user ? htmlspecialchars($edit_user['name']) : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="id_number"><?php echo t('id_number_label', 'ID Number *'); ?></label>
                        <input type="text" id="id_number" name="id_number" value="<?php echo $edit_user ? htmlspecialchars($edit_user['id_number']) : ''; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone"><?php echo t('phone_label', 'Phone'); ?></label>
                        <input type="text" id="phone" name="phone" value="<?php echo $edit_user ? htmlspecialchars($edit_user['phone']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="role"><?php echo t('role_label', 'Role *'); ?></label>
                        <select id="role" name="role" required>
                            <option value=""><?php echo t('select_role_option', 'Select Role'); ?></option>
                            <option value="Accountant" <?php echo ($edit_user && $edit_user['role'] === 'Accountant') ? 'selected' : ''; ?>><?php echo t('accountant_role', 'Accountant'); ?></option>
                            <option value="Admin" <?php echo ($edit_user && $edit_user['role'] === 'Admin') ? 'selected' : ''; ?>><?php echo t('admin_role', 'Admin'); ?></option>
                            <option value="Director" <?php echo ($edit_user && $edit_user['role'] === 'Director') ? 'selected' : ''; ?>><?php echo t('director_role', 'Director'); ?></option>
                           
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="username"><?php echo t('username_label', 'Username *'); ?></label>
                        <input type="text" id="username" name="username" value="<?php echo $edit_user ? htmlspecialchars($edit_user['username']) : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="password"><?php echo $edit_user ? t('password_label_keep_current', 'Password (leave blank to keep current)') : t('password_label_required', 'Password *'); ?></label>
                        <input type="password" id="password" name="password" <?php echo $edit_user ? '' : 'required'; ?>>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $edit_user ? t('update_user_button', 'Update User') : t('add_user_button', 'Add User'); ?></button>
                    <a href="users.php" class="btn btn-secondary"><?php echo t('cancel_button', 'Cancel'); ?></a>
                </div>
            </form>
        </div>

        <!-- Users Table -->
        <div class="table-container">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?php echo t('name_column', 'Name'); ?></th>
                            <th><?php echo t('id_number_column', 'ID Number'); ?></th>
                            <th><?php echo t('phone_column', 'Phone'); ?></th>
                            <th><?php echo t('role_column', 'Role'); ?></th>
                            <th><?php echo t('username_column', 'Username'); ?></th>
                            <th><?php echo t('member_since_column', 'Member Since'); ?></th>
                            <th><?php echo t('status_column', 'Status'); ?></th>
                            <th><?php echo t('actions_column', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr class="<?php echo !$user['is_active'] ? 'inactive-row' : ''; ?>">
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                            <td>
                                <span class="role-badge <?php echo strtolower($user['role']); ?>">
                                    <?php echo htmlspecialchars($user['role']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($user['member_since'])); ?></td>
                            <td>
                                <span class="status-badge <?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $user['is_active'] ? t('active_status', 'Active') : t('inactive_status', 'Inactive'); ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="users.php?edit=<?php echo $user['id']; ?>" class="btn btn-small btn-primary"><?php echo t('edit_action', 'Edit'); ?></a>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-small <?php echo $user['is_active'] ? 'btn-warning' : 'btn-success'; ?>" 
                                                onclick="return confirm('<?php echo t('confirm_deactivate_message', 'Are you sure you want to ' . ($user['is_active'] ? 'deactivate' : 'activate') . ' this user?'); ?>');">
                                            <?php echo $user['is_active'] ? t('deactivate_button', 'Deactivate') : t('activate_button', 'Activate'); ?>
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-small btn-danger" 
                                                onclick="return confirm('<?php echo t('confirm_delete_message', 'Are you sure you want to delete this user? This action cannot be undone.'); ?>');">
                                            <?php echo t('delete_button', 'Delete'); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="current-user-badge"><?php echo t('current_user_badge', 'Current User'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function showAddForm() {
            document.getElementById('user-form').style.display = 'block';
            // Clear form
            document.getElementById('name').value = '';
            document.getElementById('id_number').value = '';
            document.getElementById('phone').value = '';
            document.getElementById('role').value = '';
            document.getElementById('username').value = '';
            document.getElementById('password').value = '';
            document.getElementById('password').required = true;
            document.querySelector('input[name="action"]').value = 'add';
            document.querySelector('h3').textContent = '<?php echo t('add_new_user_title', 'Add New User'); ?>';
            document.querySelector('button[type="submit"]').textContent = '<?php echo t('add_user_button', 'Add User'); ?>';
        }
    </script>
</body>
</html> 