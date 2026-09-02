<?php
/**
 * 创建项目 - 独立页面
 * Create Project Page
 *
 * 权限: 项目经理 (project_manager) 或 管理员 (admin)
 * 创建成功 → 跳转项目列表 projects.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user  = getCurrentUser();
$error = '';

if (!isProjectManager() && !isAdmin()) {
    die('权限不足:只有项目经理或管理员可以创建项目');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    $name        = trim($_POST['project_name'] ?? '');
    $description = trim($_POST['project_description'] ?? '');
    $startDate   = $_POST['start_date'] ?: null;
    $endDate     = $_POST['end_date'] ?: null;

    if ($name === '') {
        $error = '项目名称不能为空';
    } else {
        try {
            $pdo = getDbConnection();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO projects (name, description, manager_id, created_by, start_date, end_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active')"
            );
            $stmt->execute([$name, $description, $user['id'], $user['id'], $startDate, $endDate]);
            $projectId = $pdo->lastInsertId();

            // 创建者自动加入项目成员
            $stmt = $pdo->prepare(
                "INSERT INTO project_members (project_id, user_id, custom_role, status) VALUES (?, ?, '项目经理', 'active')"
            );
            $stmt->execute([$projectId, $user['id']]);

            logOperation($user['id'], 'create', 'project', $projectId, [
                'name'       => $name,
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]);

            $pdo->commit();
            $_SESSION['success_message'] = '项目创建成功,已自动进入项目仪表盘';
            redirect('project_dashboard.php?project_id=' . $projectId);
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = '创建失败: ' . $e->getMessage();
        }
    }
}

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>创建项目 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php echo renderHeader('➕ 创建新项目', $user, $unreadNotifCount, 'projects'); ?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>

    <div class="card" style="max-width: 720px;">
        <h3>📂 新建项目</h3>
        <p style="color:#666; font-size:13px; margin-bottom: 16px;">
            填写项目基本信息。创建后会自动跳转到项目仪表盘,你可以在那里添加成员、创建任务、设置里程碑。
        </p>

        <form method="POST" action="project_create.php">
            <div class="form-group">
                <label for="project_name">项目名称 <span style="color:#e74c3c;">*</span></label>
                <input type="text" id="project_name" name="project_name" required
                       placeholder="例如:64平台软波束定版"
                       value="<?php echo htmlspecialchars($_POST['project_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="project_description">项目描述</label>
                <textarea id="project_description" name="project_description" rows="4"
                          placeholder="简要描述项目目标、范围、关键交付物..."><?php echo htmlspecialchars($_POST['project_description'] ?? ''); ?></textarea>
            </div>

            <div class="form-row" style="display:flex; gap:15px;">
                <div class="form-group" style="flex:1;">
                    <label for="start_date">开始日期</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label for="end_date">结束日期</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group" style="display:flex; gap:10px; align-items:center;">
                <button type="submit" name="create_project" class="btn btn-primary">✅ 创建项目</button>
                <a href="projects.php" class="btn btn-danger">取消</a>
            </div>
        </form>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
