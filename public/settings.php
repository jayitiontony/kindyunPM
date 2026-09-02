<?php
/**
 * 设置页面 - 角色与用户管理
 * Settings Page - Role and User Management
 */

// 引入必要的文件
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/settings_tabs.php';

// 需要管理员权限
requireAdmin();

$user = getCurrentUser();
$error = '';
$success = '';

// 处理角色管理请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 添加角色
    if (isset($_POST['add_role'])) {
        $name = $_POST['role_name'] ?? '';
        $description = $_POST['role_description'] ?? '';
        $qualifications = $_POST['role_qualifications'] ?? '';
        $permissions = $_POST['role_permissions'] ?? '';

        if (empty($name)) {
            $error = '角色名称不能为空';
        } else {
            try {
                createRole($name, $description, $qualifications, $permissions);
                $newRoleId = getLastInsertId();
                logOperation($user['id'], 'create', 'role', $newRoleId, [
                    'name' => $name, 'description' => $description,
                    'qualifications' => $qualifications, 'permissions' => $permissions,
                ]);
                $success = '角色添加成功';
            } catch (Exception $e) {
                $error = '角色添加失败: ' . $e->getMessage();
            }
        }
    }

    // 更新角色
    if (isset($_POST['update_role'])) {
        $id = $_POST['role_id'] ?? 0;
        $name = $_POST['role_name'] ?? '';
        $description = $_POST['role_description'] ?? '';
        $qualifications = $_POST['role_qualifications'] ?? '';
        $permissions = $_POST['role_permissions'] ?? '';

        if (empty($name)) {
            $error = '角色名称不能为空';
        } else {
            try {
                $old = getRoleById($id);
                updateRole($id, $name, $description, $qualifications, $permissions);
                logOperation($user['id'], 'update', 'role', $id, [
                    'old' => $old,
                    'new' => ['name' => $name, 'description' => $description, 'qualifications' => $qualifications, 'permissions' => $permissions],
                ]);
                $success = '角色更新成功';
            } catch (Exception $e) {
                $error = '角色更新失败: ' . $e->getMessage();
            }
        }
    }

    // 删除角色
    if (isset($_POST['delete_role'])) {
        $id = $_POST['role_id'] ?? 0;
        try {
            $old = getRoleById($id);
            deleteRole($id);
            logOperation($user['id'], 'delete', 'role', $id, ['role' => $old]);
            $success = '角色删除成功';
        } catch (Exception $e) {
            $error = '角色删除失败: ' . $e->getMessage();
        }
    }

    // 添加用户
    if (isset($_POST['add_user'])) {
        $username = $_POST['user_username'] ?? '';
        $password = $_POST['user_password'] ?? '';
        $name = $_POST['user_name'] ?? '';
        $gender = $_POST['user_gender'] ?? 'male';
        $phone = $_POST['user_phone'] ?? '';
        $email = $_POST['user_email'] ?? '';
        $role_id = $_POST['user_role_id'] ?? 1;
        $expertise = $_POST['user_expertise'] ?? '';

        if (empty($username) || empty($password)) {
            $error = '用户名和密码不能为空';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                createUser($username, $password_hash, $name, $gender, $phone, $email, $role_id, $expertise);
                $newUid = getLastInsertId();
                logOperation($user['id'], 'create', 'user', $newUid, [
                    'username' => $username, 'name' => $name, 'role_id' => $role_id,
                ]);
                $success = '用户添加成功';
            } catch (Exception $e) {
                $error = '用户添加失败: ' . $e->getMessage();
            }
        }
    }

    // 更新用户
    if (isset($_POST['update_user'])) {
        $id = $_POST['user_id'] ?? 0;
        $username = $_POST['user_username'] ?? '';
        $name = $_POST['user_name'] ?? '';
        $gender = $_POST['user_gender'] ?? 'male';
        $phone = $_POST['user_phone'] ?? '';
        $email = $_POST['user_email'] ?? '';
        $role_id = $_POST['user_role_id'] ?? 1;
        $expertise = $_POST['user_expertise'] ?? '';
        $newPassword = $_POST['user_password'] ?? '';  // 可选,留空 = 不改密码

        if (empty($username)) {
            $error = '用户名不能为空';
        } else {
            try {
                $old = getUserById($id);
                // 密码处理:留空不改,非空则 hash 后更新
                $passwordHash = null;
                $passwordChanged = false;
                if (!empty($newPassword)) {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $passwordChanged = true;
                }
                updateUser($id, $username, $name, $gender, $phone, $email, $role_id, $expertise, $passwordHash);

                $logDetail = [
                    'old' => $old,
                    'new' => ['username' => $username, 'name' => $name, 'gender' => $gender, 'phone' => $phone, 'email' => $email, 'role_id' => $role_id, 'expertise' => $expertise],
                    'password_changed' => $passwordChanged,
                ];
                logOperation($user['id'], 'update', 'user', $id, $logDetail);
                $success = $passwordChanged ? '用户已更新,密码已重置' : '用户更新成功';
            } catch (Exception $e) {
                $error = '用户更新失败: ' . $e->getMessage();
            }
        }
    }

    // 删除用户
    if (isset($_POST['delete_user'])) {
        $id = $_POST['user_id'] ?? 0;
        try {
            $old = getUserById($id);
            deleteUser($id);
            logOperation($user['id'], 'delete', 'user', $id, ['user' => $old]);
            $success = '用户删除成功';
        } catch (Exception $e) {
            $error = '用户删除失败: ' . $e->getMessage();
        }
    }
}

// 获取所有角色和用户
$roles = getAllRoles();
$users = getAllUsers();

$tab = $_GET['tab'] ?? 'roles';
$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>项目管理网站 - 系统设置</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <style>
        .tab-nav {
            display: flex;
            border-bottom: 2px solid #ddd;
            margin-bottom: 20px;
        }
        .tab-nav a {
            padding: 10px 20px;
            text-decoration: none;
            color: #555;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        .tab-nav a.active {
            color: #3498db;
            border-bottom-color: #3498db;
            font-weight: bold;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        .role-card, .user-card {
            border: 1px solid #eee;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
            background: #fafafa;
        }
        .role-card h4, .user-card h4 {
            margin-top: 0;
            color: #2c3e50;
        }
        .role-info, .user-info {
            font-size: 14px;
            color: #666;
            margin: 5px 0;
        }
        .action-buttons {
            margin-top: 10px;
        }
        .action-buttons button, .action-buttons a.btn {
            margin-right: 5px;
            padding: 5px 10px;
            font-size: 12px;
        }
    </style>
</head>
<body>
<?php
echo renderHeader('⚙️ 系统设置', $user, $unreadNotifCount, 'settings', [], false);
?>

    <div class="container">
        <?php if ($error): ?>
            <?php echo showError($error); ?>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <?php echo showSuccess($success); ?>
        <?php endif; ?>

        <?php echo renderSettingsTabs($tab); ?>

        <!-- 角色管理 -->
        <div id="roles-tab" class="tab-content <?php echo $tab === 'roles' ? 'active' : ''; ?>">
            <div class="card">
                <h3>添加/编辑角色</h3>
                <form method="POST" action="settings.php?tab=roles">
                    <input type="hidden" name="role_id" id="edit_role_id" value="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role_name">角色名称</label>
                            <input type="text" id="role_name" name="role_name" required placeholder="如：程序员、硬件工程师">
                        </div>
                        <div class="form-group">
                            <label for="role_description">角色描述</label>
                            <input type="text" id="role_description" name="role_description" placeholder="角色简要描述">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role_qualifications">任职资格</label>
                            <input type="text" id="role_qualifications" name="role_qualifications" placeholder="如：本科及以上学历，3年以上经验">
                        </div>
                        <div class="form-group">
                            <label for="role_permissions">角色权限</label>
                            <input type="text" id="role_permissions" name="role_permissions" placeholder="如：代码编写、任务分配">
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="submit" name="add_role" id="add_role_btn" class="btn btn-primary">添加角色</button>
                        <button type="submit" name="update_role" id="update_role_btn" class="btn btn-success" style="display:none;">更新角色</button>
                        <button type="button" class="btn btn-danger" onclick="cancelEditRole()" id="cancel_role_btn" style="display:none;">取消编辑</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3>角色列表</h3>
                <?php foreach ($roles as $role): ?>
                    <div class="role-card">
                        <h4><?php echo htmlspecialchars($role['name']); ?> - <?php echo htmlspecialchars($role['description']); ?></h4>
                        <div class="role-info"><strong>任职资格：</strong><?php echo htmlspecialchars($role['qualifications'] ?: '无'); ?></div>
                        <div class="role-info"><strong>角色权限：</strong><?php echo htmlspecialchars($role['permissions'] ?: '无'); ?></div>
                        <div class="action-buttons">
                            <button class="btn btn-primary" onclick="editRole(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['name']); ?>', '<?php echo htmlspecialchars($role['description']); ?>', '<?php echo htmlspecialchars($role['qualifications'] ?: ''); ?>', '<?php echo htmlspecialchars($role['permissions'] ?: ''); ?>')">编辑</button>
                            <?php if ($role['name'] !== 'admin' && $role['name'] !== 'project_manager' && $role['name'] !== 'team_member'): ?>
                            <form method="POST" action="settings.php?tab=roles" style="display:inline;" onsubmit="return confirm('确定要删除此角色吗？');">
                                <input type="hidden" name="role_id" value="<?php echo $role['id']; ?>">
                                <button type="submit" name="delete_role" class="btn btn-danger">删除</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 用户管理 -->
        <div id="users-tab" class="tab-content <?php echo $tab === 'users' ? 'active' : ''; ?>">
            <div class="card">
                <h3>添加/编辑用户</h3>
                <form method="POST" action="settings.php?tab=users">
                    <input type="hidden" name="user_id" id="edit_user_id" value="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="user_username">用户名</label>
                            <input type="text" id="user_username" name="user_username" required>
                        </div>
                        <div class="form-group">
                             <label for="user_password">
                                 密码 <span style="color:#999;">(新添加用户需填写)</span>
                             </label>
                             <input type="password" id="user_password" name="user_password" placeholder="新添加用户需填写密码" required>
                         </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="user_name">姓名</label>
                            <input type="text" id="user_name" name="user_name">
                        </div>
                        <div class="form-group">
                            <label for="user_gender">性别</label>
                            <select id="user_gender" name="user_gender">
                                <option value="male">男</option>
                                <option value="female">女</option>
                                <option value="other">其他</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="user_phone">电话号码</label>
                            <input type="text" id="user_phone" name="user_phone">
                        </div>
                        <div class="form-group">
                            <label for="user_email">邮箱地址</label>
                            <input type="email" id="user_email" name="user_email">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="user_role_id">角色</label>
                            <select id="user_role_id" name="user_role_id" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="user_expertise">专长</label>
                            <input type="text" id="user_expertise" name="user_expertise" placeholder="如：PHP开发、硬件设计">
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="submit" name="add_user" id="add_user_btn" class="btn btn-primary">添加用户</button>
                        <button type="submit" name="update_user" id="update_user_btn" class="btn btn-success" style="display:none;">更新用户</button>
                        <button type="button" class="btn btn-danger" onclick="cancelEditUser()" id="cancel_user_btn" style="display:none;">取消编辑</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3>用户列表</h3>
                <?php foreach ($users as $userItem): ?>
                    <div class="user-card">
                        <h4><?php echo htmlspecialchars($userItem['name'] ?: $userItem['username']); ?> (<?php echo htmlspecialchars($userItem['username']); ?>)</h4>
                        <div class="user-info"><strong>性别：</strong><?php echo getGenderText($userItem['gender']); ?> | <strong>电话：</strong><?php echo htmlspecialchars($userItem['phone'] ?: '无'); ?> | <strong>邮箱：</strong><?php echo htmlspecialchars($userItem['email'] ?: '无'); ?></div>
                        <div class="user-info"><strong>角色：</strong><?php echo htmlspecialchars($userItem['role_name']); ?> | <strong>专长：</strong><?php echo htmlspecialchars($userItem['expertise'] ?: '无'); ?></div>
                        <div class="action-buttons">
                            <button class="btn btn-primary" onclick="editUser(<?php echo $userItem['id']; ?>, '<?php echo htmlspecialchars($userItem['username']); ?>', '<?php echo htmlspecialchars($userItem['name'] ?: ''); ?>', '<?php echo $userItem['gender'] ?: 'male'; ?>', '<?php echo htmlspecialchars($userItem['phone'] ?: ''); ?>', '<?php echo htmlspecialchars($userItem['email'] ?: ''); ?>', <?php echo $userItem['role_id']; ?>, '<?php echo htmlspecialchars($userItem['expertise'] ?: ''); ?>')">编辑</button>
                            <?php if ($userItem['role_name'] !== 'admin'): ?>
                            <form method="POST" action="settings.php?tab=users" style="display:inline;" onsubmit="return confirm('确定要删除此用户吗？');">
                                <input type="hidden" name="user_id" value="<?php echo $userItem['id']; ?>">
                                <button type="submit" name="delete_user" class="btn btn-danger">删除</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        function editRole(id, name, description, qualifications, permissions) {
            document.getElementById('edit_role_id').value = id;
            document.getElementById('role_name').value = name;
            document.getElementById('role_description').value = description;
            document.getElementById('role_qualifications').value = qualifications;
            document.getElementById('role_permissions').value = permissions;
            
            document.getElementById('add_role_btn').style.display = 'none';
            document.getElementById('update_role_btn').style.display = 'inline-block';
            document.getElementById('cancel_role_btn').style.display = 'inline-block';
        }
        
        function cancelEditRole() {
            document.getElementById('edit_role_id').value = '';
            document.getElementById('role_name').value = '';
            document.getElementById('role_description').value = '';
            document.getElementById('role_qualifications').value = '';
            document.getElementById('role_permissions').value = '';
            
            document.getElementById('add_role_btn').style.display = 'inline-block';
            document.getElementById('update_role_btn').style.display = 'none';
            document.getElementById('cancel_role_btn').style.display = 'none';
        }
        
        function editUser(id, username, name, gender, phone, email, role_id, expertise) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('user_username').value = username;
            document.getElementById('user_name').value = name;
            document.getElementById('user_gender').value = gender;
            document.getElementById('user_phone').value = phone;
            document.getElementById('user_email').value = email;
            document.getElementById('user_role_id').value = role_id;
            document.getElementById('user_expertise').value = expertise;
            
            document.getElementById('user_password').placeholder = '编辑时留空则不修改密码';
            document.getElementById('user_password').required = false;
            
            document.getElementById('add_user_btn').style.display = 'none';
            document.getElementById('update_user_btn').style.display = 'inline-block';
            document.getElementById('cancel_user_btn').style.display = 'inline-block';
        }
        
        function cancelEditUser() {
            document.getElementById('edit_user_id').value = '';
            document.getElementById('user_username').value = '';
            document.getElementById('user_password').value = '';
            document.getElementById('user_name').value = '';
            document.getElementById('user_gender').value = 'male';
            document.getElementById('user_phone').value = '';
            document.getElementById('user_email').value = '';
            document.getElementById('user_expertise').value = '';
            
            document.getElementById('user_password').placeholder = '新添加用户需填写';
            document.getElementById('user_password').required = true;
            
            document.getElementById('add_user_btn').style.display = 'inline-block';
            document.getElementById('update_user_btn').style.display = 'none';
            document.getElementById('cancel_user_btn').style.display = 'none';
        }
    </script>

<?php echo renderFooter(); ?>
</body>
</html>
