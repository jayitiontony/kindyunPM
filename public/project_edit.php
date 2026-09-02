<?php
/**
 * 编辑项目 - 独立页面
 * Edit Project Page
 *
 * 权限: 项目经理(manager) 或 管理员
 * 注: 删除权限另见 project_delete.php (仅创建者 / 管理员)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user     = getCurrentUser();
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$error    = '';
$success  = '';

$project = queryOneDb(
    "SELECT p.*, u.username as manager_name, u.name as manager_real_name
     FROM projects p LEFT JOIN users u ON p.manager_id = u.id WHERE p.id = ?",
    [$projectId]
);
if (!$project) die('项目不存在');

$isManager  = ((int)$project['manager_id'] === (int)$user['id']) || isAdmin();
if (!$isManager) {
    die('权限不足:只有项目负责人或管理员可编辑项目基础信息');
}

// 处理添加项目成员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_member'])) {
    $newUserId = (int)($_POST['new_member_id'] ?? 0);
    $newRole   = trim($_POST['new_member_role'] ?? '成员');

    if ($newUserId <= 0) {
        $error = '请选择要添加的用户';
    } else {
        // 检查是否已经是成员
        $exists = queryOneDb(
            "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND status = 'active'",
            [$projectId, $newUserId]
        );
        if ($exists) {
            $error = '该用户已经是项目成员';
        } else {
            try {
                $pdo = getDbConnection();
                $pdo->beginTransaction();

                executeDb(
                    "INSERT INTO project_members (project_id, user_id, custom_role, status) VALUES (?, ?, ?, 'active')",
                    [$projectId, $newUserId, $newRole]
                );

                logOperation($user['id'], 'add_member', 'project', $projectId, [
                    'user_id' => $newUserId,
                    'role'    => $newRole,
                ]);

                $pdo->commit();
                $success = '项目成员已添加';
            } catch (Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                $error = '添加成员失败: ' . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_project'])) {
    $name        = trim($_POST['project_name'] ?? '');
    $description = trim($_POST['project_description'] ?? '');
    $startDate   = $_POST['start_date'] ?: null;
    $endDate     = $_POST['end_date'] ?: null;
    $newManager  = (int)($_POST['manager_id'] ?? 0);
    $editNote    = trim($_POST['edit_note'] ?? '');

    if ($name === '') {
        $error = '项目名称不能为空';
    } else {
        // 转让项目负责人时,新负责人必须是已有用户;管理员可以直接指定任何用户
        if ($newManager > 0) {
            $exists = queryOneDb("SELECT id FROM users WHERE id = ?", [$newManager]);
            if (!$exists) {
                $error = '指定的项目负责人不存在';
            }
        } else {
            $newManager = (int)$project['manager_id'];
        }

        if (empty($error)) {
            try {
                $pdo = getDbConnection();
                $pdo->beginTransaction();

                $changes = [];
                if ($project['name']        !== $name)        $changes['name']        = ['old' => $project['name'], 'new' => $name];
                if ($project['description'] !== $description) $changes['description'] = ['old' => $project['description'], 'new' => $description];
                if (($project['start_date'] ?: null) !== $startDate) $changes['start_date'] = ['old' => $project['start_date'], 'new' => $startDate];
                if (($project['end_date']   ?: null) !== $endDate)   $changes['end_date']   = ['old' => $project['end_date'], 'new' => $endDate];
                if ((int)$project['manager_id'] !== $newManager)     $changes['manager_id'] = ['old' => (int)$project['manager_id'], 'new' => $newManager];

                executeDb(
                    "UPDATE projects SET name=?, description=?, start_date=?, end_date=?, manager_id=? WHERE id=?",
                    [$name, $description, $startDate, $endDate, $newManager, $projectId]
                );

                // 转让负责人:自动把新负责人加入项目成员
                if ($changes && isset($changes['manager_id'])) {
                    executeDb(
                        "INSERT OR IGNORE INTO project_members (project_id, user_id, custom_role, status) VALUES (?, ?, '项目经理', 'active')",
                        [$projectId, $newManager]
                    );
                }

                logOperation($user['id'], 'update', 'project', $projectId, [
                    'changes' => $changes,
                    'note'    => $editNote,
                ]);

                $pdo->commit();
                $success = '项目已更新';
                // 重读
                $project = queryOneDb(
                    "SELECT p.*, u.username as manager_name, u.name as manager_real_name
                     FROM projects p LEFT JOIN users u ON p.manager_id = u.id WHERE p.id = ?",
                    [$projectId]
                );
            } catch (Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                $error = '更新失败: ' . $e->getMessage();
            }
        }
    }
}

// 候选负责人列表(同角色 = project_manager 或 admin,或当前项目所有成员)
$candidateManagers = queryDb(
    "SELECT DISTINCT u.id, u.username, u.name, r.name as role_name
     FROM users u LEFT JOIN roles r ON u.role_id = r.id
     WHERE r.name IN ('admin', 'project_manager')
        OR u.id IN (SELECT user_id FROM project_members WHERE project_id = ?)
     ORDER BY u.id",
    [$projectId]
);

// 项目成员列表
$projectMembers = queryDb(
    "SELECT u.id, u.username, u.name as real_name, pm.custom_role, pm.status
     FROM project_members pm
     JOIN users u ON pm.user_id = u.id
     WHERE pm.project_id = ? AND pm.status = 'active'",
    [$projectId]
);

// 可用用户列表(未加入项目的用户)
$availableUsers = queryDb(
    "SELECT id, username, name FROM users WHERE id NOT IN (SELECT user_id FROM project_members WHERE project_id = ? AND status = 'active') ORDER BY id",
    [$projectId]
);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑项目 - <?php echo htmlspecialchars($project['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
echo renderHeader('✏️ 编辑项目', $user, $unreadNotifCount, 'projects', [
    'project_id'   => (int)$projectId,
    'project_name' => $project['name'] ?? '',
    'sub_active'   => 'project_dashboard',
], false);
?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <div class="card" style="max-width: 720px;">
        <h3>✏️ 编辑项目基础信息</h3>
        <form method="POST">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">

            <div class="form-group">
                <label>项目名称 <span style="color:#e74c3c;">*</span></label>
                <input type="text" name="project_name" required
                       value="<?php echo htmlspecialchars($project['name']); ?>">
            </div>

            <div class="form-group">
                <label>项目描述</label>
                <textarea name="project_description" rows="4"><?php echo htmlspecialchars($project['description']); ?></textarea>
            </div>

            <div class="form-row" style="display:flex; gap:15px;">
                <div class="form-group" style="flex:1;">
                    <label>开始日期</label>
                    <input type="date" name="start_date"
                           value="<?php echo htmlspecialchars($project['start_date'] ?: ''); ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>结束日期</label>
                    <input type="date" name="end_date"
                           value="<?php echo htmlspecialchars($project['end_date'] ?: ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>项目负责人 (变更后会同步加入项目成员)</label>
                <select name="manager_id">
                    <?php foreach ($candidateManagers as $cm): ?>
                        <option value="<?php echo (int)$cm['id']; ?>"
                            <?php echo (int)$project['manager_id']===(int)$cm['id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($cm['name'] ?: $cm['username']); ?>
                            (<?php echo htmlspecialchars($cm['role_name'] ?: '无角色'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>本次编辑说明 (会写入操作日志)</label>
                <input type="text" name="edit_note" placeholder="例如:补充项目目标 / 调整结束日期 / 转让负责人给某同事">
            </div>

            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" name="save_project" class="btn btn-primary">💾 保存</button>
                <a href="project_dashboard.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-danger">取消</a>
            </div>
        </form>
    </div>

    <!-- 项目成员管理 -->
    <div class="card" style="max-width: 720px; margin-top: 20px;">
        <h3>👥 项目成员管理</h3>

        <!-- 添加成员表单 -->
        <form method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
            <div class="form-row" style="display:flex; gap:15px; align-items:end;">
                <div class="form-group" style="flex:1;">
                    <label>选择用户</label>
                    <select name="new_member_id" required>
                        <option value="">请选择用户...</option>
                        <?php foreach ($availableUsers as $user): ?>
                            <option value="<?php echo (int)$user['id']; ?>">
                                <?php echo htmlspecialchars($user['name'] ?: $user['username']); ?> (<?php echo htmlspecialchars($user['username']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>角色</label>
                    <input type="text" name="new_member_role" value="成员" required>
                </div>
                <div class="form-group" style="flex:0 0 auto;">
                    <button type="submit" name="add_member" class="btn btn-primary">➕ 添加成员</button>
                </div>
            </div>
        </form>

        <!-- 当前成员列表 -->
        <h4>当前成员列表</h4>
        <?php if (empty($projectMembers)): ?>
            <p style="color:#999;">暂无项目成员</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>用户</th>
                        <th>角色</th>
                        <th>状态</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projectMembers as $member): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($member['real_name'] ?: $member['username']); ?> (<?php echo htmlspecialchars($member['username']); ?>)</td>
                            <td><?php echo htmlspecialchars($member['custom_role']); ?></td>
                            <td>
                                <?php if ($member['status'] === 'active'): ?>
                                    <span class="status-badge status-in_progress">活跃</span>
                                <?php else: ?>
                                    <span class="status-badge status-todo">非活跃</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>