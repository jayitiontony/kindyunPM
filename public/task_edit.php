<?php
/**
 * 任务编辑页
 * Task Edit Page
 *
 * 可编辑: 标题、描述、优先级、开始日期、截止日期、完成百分比、父任务、依赖关系
 * 不可在此页改: 负责人(走 task_reassign.php 必填原因,因为必填指派原因)
 * 每次保存都对比字段并写入 operation_logs.update
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user   = getCurrentUser();
$taskId = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
$error  = '';
$success = '';

// 取任务
$task = queryOneDb("SELECT t.*, p.manager_id as project_manager_id, p.name as project_name FROM tasks t JOIN projects p ON t.project_id = p.id WHERE t.id = ?", [$taskId]);
if (!$task) die('任务不存在');

// 权限
$isProjectManager = ($task['project_manager_id'] == $user['id']) || isAdmin();
if (!$isProjectManager && !isAdmin()) {
    die('权限不足:只有项目经理或管理员可编辑任务基础信息');
}

// ========================================================================
// 保存
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_task'])) {
    $newTitle       = trim($_POST['title'] ?? '');
    $newDescription = trim($_POST['description'] ?? '');
    $newPriority    = $_POST['priority'] ?? 'medium';
    $newStartDate   = $_POST['start_date'] ?: null;
    $newDueDate     = $_POST['due_date'] ?: null;
    $newProgress    = max(0, min(100, (int)($_POST['progress'] ?? 0)));
    $newParentId    = (int)($_POST['parent_task_id'] ?? 0);
    $editNote       = trim($_POST['edit_note'] ?? '');
    $newDeps        = $_POST['depends_on'] ?? [];
    $newDepNote     = trim($_POST['depends_note'] ?? '');

    if (empty($newTitle)) {
        $error = '任务标题不能为空';
    } else {
        $validP = ['low', 'medium', 'high'];
        if (!in_array($newPriority, $validP, true)) $newPriority = 'medium';

        // 父任务不能是自己/自己的子任务(防环)
        if ($newParentId === $taskId) {
            $error = '父任务不能是任务自身';
        } elseif ($newParentId > 0) {
            // 简易防环:父任务的父链路不能包含本任务
            $cursor = $newParentId;
            $visited = [];
            while ($cursor && !in_array($cursor, $visited)) {
                $visited[] = $cursor;
                if ($cursor == $taskId) { $error = '父任务形成了循环'; break; }
                $row = queryOneDb("SELECT parent_task_id FROM tasks WHERE id = ?", [$cursor]);
                $cursor = $row ? (int)$row['parent_task_id'] : 0;
            }
        }

        if (empty($error)) {
            // 对比差异
            $changes = [];
            if ($task['title']       !== $newTitle)       $changes['title']       = ['old' => $task['title'],       'new' => $newTitle];
            if ($task['description'] !== $newDescription) $changes['description'] = ['old' => $task['description'], 'new' => $newDescription];
            if ($task['priority']    !== $newPriority)    $changes['priority']    = ['old' => $task['priority'],    'new' => $newPriority];
            if (($task['start_date'] ?: null) !== $newStartDate) $changes['start_date'] = ['old' => $task['start_date'], 'new' => $newStartDate];
            if (($task['due_date']   ?: null) !== $newDueDate)   $changes['due_date']   = ['old' => $task['due_date'],   'new' => $newDueDate];
            if ((int)$task['progress'] !== $newProgress)         $changes['progress']   = ['old' => (int)$task['progress'], 'new' => $newProgress];
            if ((int)$task['parent_task_id'] !== $newParentId)   $changes['parent_task_id'] = ['old' => (int)$task['parent_task_id'], 'new' => $newParentId];

            // 依赖关系对比
            $oldDeps = queryDb("SELECT depends_on_task_id FROM task_dependencies WHERE task_id = ? ORDER BY depends_on_task_id", [$taskId]);
            $oldDepIds = array_map(function($r) { return (int)$r['depends_on_task_id']; }, $oldDeps);
            $newDepIds = array_values(array_filter(array_map('intval', (array)$newDeps), function($v) use ($taskId) { return $v > 0 && $v !== $taskId; }));
            $addedDeps   = array_diff($newDepIds, $oldDepIds);
            $removedDeps = array_diff($oldDepIds, $newDepIds);
            $depChanged  = !empty($addedDeps) || !empty($removedDeps);
            if ($depChanged) {
                $changes['dependencies'] = [
                    'old'    => $oldDepIds,
                    'new'    => $newDepIds,
                    'added'  => array_values($addedDeps),
                    'removed' => array_values($removedDeps),
                ];
            }

            $hasFieldChange = !empty($changes) && (count($changes) > ($depChanged ? 0 : 1) || !$depChanged);
            // 简化判断: 任意字段或依赖变化都需要保存
            $needSave = !empty($changes);

            if (!$needSave) {
                $success = '未检测到任何变化';
            } else {
                try {
                    $pdo = getDbConnection();
                    $pdo->beginTransaction();
                    executeDb(
                        "UPDATE tasks SET title=?, description=?, priority=?, start_date=?, due_date=?, progress=?, parent_task_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                        [$newTitle, $newDescription, $newPriority, $newStartDate, $newDueDate, $newProgress, $newParentId ?: null, $taskId]
                    );

                    // 进度变化: 也写一条状态变更日志(因为进度独立于状态)
                    if (isset($changes['progress']) && (int)$task['status'] !== 'done') {
                        addTaskStatusChange(
                            $taskId, $user['id'],
                            $task['status'], $task['status'],
                            (int)$task['progress'], $newProgress,
                            $editNote ?: '编辑任务时更新进度'
                        );
                    }

                    // 依赖变化: 删旧 + 加新
                    if ($depChanged) {
                        foreach ($removedDeps as $delId) {
                            removeTaskDependency($taskId, (int)$delId);
                            logOperation($user['id'], 'delete', 'task_dependency', $taskId, [
                                'depends_on_task_id' => (int)$delId,
                            ]);
                        }
                        foreach ($addedDeps as $addId) {
                            addTaskDependency($taskId, (int)$addId, $newDepNote, $user['id']);
                            logOperation($user['id'], 'create', 'task_dependency', $taskId, [
                                'depends_on_task_id' => (int)$addId,
                                'note'               => $newDepNote,
                            ]);
                        }
                    }

                    logOperation($user['id'], 'update', 'task', $taskId, [
                        'changes' => $changes,
                        'note'    => $editNote,
                    ]);

                    $pdo->commit();
                    $success = '任务已更新';
                    // 重读
                    $task = queryOneDb("SELECT t.*, p.manager_id as project_manager_id, p.name as project_name FROM tasks t JOIN projects p ON t.project_id = p.id WHERE t.id = ?", [$taskId]);
                } catch (Exception $e) {
                    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                    $error = '更新失败: ' . $e->getMessage();
                }
            }
        }
    }
}

// 候选父任务(同项目、不是自己、不是自己的子任务)
$candidateParents = queryDb(
    "SELECT id, title FROM tasks WHERE project_id = ? AND id != ? AND (parent_task_id = 0 OR parent_task_id IS NULL) ORDER BY id DESC",
    [$task['project_id'], $taskId]
);

// 当前依赖(用于勾选回显 + 下方列表展示)
$currentDeps = queryDb(
    "SELECT td.depends_on_task_id, td.note, t.title, t.status, t.progress
     FROM task_dependencies td JOIN tasks t ON t.id = td.depends_on_task_id
     WHERE td.task_id = ? ORDER BY td.created_at ASC",
    [$taskId]
);
$currentDepIds = array_map(function($r) { return (int)$r['depends_on_task_id']; }, $currentDeps);

// 候选可作为依赖的任务:同项目、非自己、非自己的子任务(防自环),且不重复
// 简易: 取同项目下除自己外的全部任务
$candidateDeps = queryDb(
    "SELECT id, title, status, progress FROM tasks
     WHERE project_id = ? AND id != ? ORDER BY id DESC",
    [$task['project_id'], $taskId]
);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑任务 - <?php echo htmlspecialchars($task['title']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
echo renderHeader('✏️ 编辑任务', $user, $unreadNotifCount, null, [
    'project_id' => (int)$task['project_id'],
    'task_id'    => (int)$taskId,
    'project_name' => $task['project_name'] ?? '',
    'sub_active' => 'task_edit',
], false);
?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <div class="card">
        <h3>✏️ 编辑任务基础信息</h3>
        <p style="color:#999; font-size:12px;">
            负责人变更请用 <a href="task_reassign.php?task_id=<?php echo (int)$taskId; ?>">"重新指派"</a>(必填指派原因);<b>依赖关系</b>请在本页底部"🔗 依赖关系"区块管理(添加 / 删除 / 备注)。
        </p>
        <form method="POST">
            <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
            <div class="form-group">
                <label>任务标题 <span style="color:#e74c3c;">*</span></label>
                <input type="text" name="title" required value="<?php echo htmlspecialchars($task['title']); ?>">
            </div>
            <div class="form-group">
                <label>任务描述</label>
                <textarea name="description" rows="4"><?php echo htmlspecialchars($task['description']); ?></textarea>
            </div>
            <div class="form-row" style="display:flex; gap:15px;">
                <div class="form-group" style="flex:1;">
                    <label>优先级</label>
                    <select name="priority">
                        <option value="low"    <?php echo $task['priority']==='low'?'selected':''; ?>>低</option>
                        <option value="medium" <?php echo $task['priority']==='medium'?'selected':''; ?>>中</option>
                        <option value="high"   <?php echo $task['priority']==='high'?'selected':''; ?>>高</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>完成百分比</label>
                    <input type="number" name="progress" min="0" max="100" step="5" value="<?php echo (int)$task['progress']; ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>父任务</label>
                    <select name="parent_task_id">
                        <option value="0">无 (顶级任务)</option>
                        <?php foreach ($candidateParents as $cp): ?>
                            <option value="<?php echo (int)$cp['id']; ?>" <?php echo (int)$task['parent_task_id']===(int)$cp['id']?'selected':''; ?>>[#<?php echo (int)$cp['id']; ?>] <?php echo htmlspecialchars($cp['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row" style="display:flex; gap:15px;">
                <div class="form-group" style="flex:1;">
                    <label>开始日期</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($task['start_date'] ?: ''); ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>截止日期</label>
                    <input type="date" name="due_date" value="<?php echo htmlspecialchars($task['due_date'] ?: ''); ?>">
                </div>
            </div>
            <div class="form-group">
                <label>本次编辑说明 (会写入操作日志)</label>
                <input type="text" name="edit_note" placeholder="例如:补充了验收标准 / 调整优先级 / 推迟截止日期">
            </div>

            <!-- 依赖关系管理 -->
            <div class="form-group" style="border-top: 1px dashed #e5e7eb; padding-top: 14px; margin-top: 14px;">
                <label>🔗 依赖关系 <small style="color:#999; font-weight:normal;">(勾选 = 当前依赖;取消勾选 = 删除;多选新任务 = 添加)</small></label>

                <?php if (empty($currentDeps)): ?>
                    <p style="color:#999; font-size:12px; margin: 4px 0 10px;">📭 当前没有依赖任务</p>
                <?php else: ?>
                    <ul class="dep-list" style="margin: 4px 0 10px;">
                        <?php foreach ($currentDeps as $d): ?>
                            <li style="font-size:12px;">
                                <a href="task_detail.php?task_id=<?php echo (int)$d['depends_on_task_id']; ?>" target="_blank">
                                    [#<?php echo (int)$d['depends_on_task_id']; ?>] <?php echo htmlspecialchars($d['title']); ?>
                                </a>
                                <span class="status-badge status-<?php echo htmlspecialchars($d['status']); ?>"><?php echo getTaskStatusText($d['status']); ?></span>
                                <?php if ($d['status'] !== 'done'): ?>
                                    <span class="badge-warn" style="font-size:11px;">未完成 (<?php echo (int)$d['progress']; ?>%)</span>
                                <?php else: ?>
                                    <span style="color:#16a34a; font-size:11px;">✓ 已完成</span>
                                <?php endif; ?>
                                <?php if (!empty($d['note'])): ?>
                                    <small style="color:#888;">— <?php echo htmlspecialchars($d['note']); ?></small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="form-group" style="margin-bottom: 8px;">
                    <label style="font-weight:normal; font-size:12px;">📤 多选下面列表 = 添加新依赖(已勾选的不会重复添加;不勾选 = 删除)</label>
                    <select name="depends_on[]" multiple size="8" style="height:auto;">
                        <?php foreach ($candidateDeps as $cd): ?>
                            <option value="<?php echo (int)$cd['id']; ?>"
                                <?php echo in_array((int)$cd['id'], $currentDepIds, true) ? 'selected' : ''; ?>>
                                [#<?php echo (int)$cd['id']; ?>] <?php echo htmlspecialchars($cd['title']); ?>
                                — <?php echo getTaskStatusText($cd['status']); ?> (<?php echo (int)$cd['progress']; ?>%)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#999;">按住 Ctrl / Cmd 多选。所选任务未完成前,本任务不能进入"进行中"。</small>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label style="font-weight:normal; font-size:12px;">📝 本次依赖说明(仅用于本次新增的依赖)</label>
                    <input type="text" name="depends_note" placeholder="例如:依赖 API 接口文档 / 依赖某同事提供测试用例">
                </div>
            </div>

            <button type="submit" name="save_task" class="btn btn-primary">💾 保存修改</button>
            <a href="task_detail.php?task_id=<?php echo (int)$taskId; ?>" class="btn btn-danger">取消</a>
        </form>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
