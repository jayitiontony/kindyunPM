<?php
/**
 * AI 助手工具实现 (内部)
 * AI Assistant Tools - Implementation
 *
 * 由 ai_tools.php 引用,所有 _ai_* 函数都在这里实现。
 *
 * 权限自动适配:
 *   - 管理员:全部能力
 *   - 项目经理:自己负责项目的全部能力
 *   - 项目成员:基础操作 (看任务/改状态/评论/记工时)
 *   - 删除类: 管理员 OR 资源创建者
 */

// =============================================================================
// 用户
// =============================================================================
function _ai_get_current_user($user) {
    return _ai_ok([
        'id'        => (int)$user['id'],
        'username'  => $user['username'],
        'name'      => $user['name'] ?: $user['username'],
        'role'      => $user['role_name'] ?? '',
        'expertise' => $user['expertise'] ?? '',
        'is_admin'  => (bool)isAdmin(),
        'is_project_manager' => (bool)isProjectManager(),
        'permissions' => '管理员:全部;项目经理:我负责项目的全部;成员:我参与项目的基础操作;删除:仅创建者+管理员',
    ]);
}

function _ai_list_users($args, $user) {
    $kw    = trim($args['keyword'] ?? '');
    $role  = trim($args['role'] ?? '');
    $limit = max(1, min(200, (int)($args['limit'] ?? 50)));
    $where = []; $params = [];
    if ($kw !== '') {
        $where[] = "(u.username LIKE ? OR u.name LIKE ?)";
        $params[] = "%$kw%"; $params[] = "%$kw%";
    }
    if ($role !== '' && in_array($role, ['admin','project_manager','team_member'], true)) {
        $where[] = "r.name = ?";
        $params[] = $role;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = queryDb(
        "SELECT u.id, u.username, u.name, u.expertise, r.name as role
         FROM users u LEFT JOIN roles r ON u.role_id = r.id
         $whereSql
         ORDER BY u.id
         LIMIT $limit",
        $params
    );
    return _ai_ok($rows);
}

// =============================================================================
// 项目
// =============================================================================
function _ai_list_my_projects($user) {
    $projects = getUserProjects($user['id']);
    $out = [];
    foreach ($projects as $p) {
        $out[] = [
            'id'         => (int)$p['id'],
            'name'       => $p['name'],
            'description'=> $p['description'] ?? '',
            'manager'    => $p['manager_name'] ?? '',
            'start_date' => $p['start_date'],
            'end_date'   => $p['end_date'],
            'status'     => !empty($p['archived_at']) ? 'archived' : 'active',
        ];
    }
    return _ai_ok($out);
}

function _ai_list_all_projects($user) {
    if (!isAdmin()) return _ai_list_my_projects($user);
    $rows = queryDb(
        "SELECT p.id, p.name, p.description, u.username as manager, p.start_date, p.end_date,
                CASE WHEN p.archived_at IS NOT NULL THEN 'archived' ELSE 'active' END as status
         FROM projects p LEFT JOIN users u ON p.manager_id = u.id
         ORDER BY p.id DESC LIMIT 200"
    );
    return _ai_ok($rows);
}

function _ai_get_project($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    if ($pid <= 0) return _ai_err('project_id 必填');
    if (!_ai_can_read_project($pid, $user)) return _ai_err('权限不足:你不是该项目的成员');
    $p = queryOneDb(
        "SELECT p.*, u.username as manager, u.name as manager_real_name
         FROM projects p LEFT JOIN users u ON p.manager_id = u.id WHERE p.id = ?",
        [$pid]
    );
    if (!$p) return _ai_err('项目不存在');
    $stats = getProjectStats($pid);
    $memberCount = (int)queryOneDb("SELECT COUNT(*) AS c FROM project_members WHERE project_id = ? AND status = 'active'", [$pid])['c'];
    $taskCount   = (int)queryOneDb("SELECT COUNT(*) AS c FROM tasks WHERE project_id = ?", [$pid])['c'];
    return _ai_ok([
        'id'          => (int)$p['id'],
        'name'        => $p['name'],
        'description' => $p['description'] ?? '',
        'manager'     => $p['manager'],
        'manager_real_name' => $p['manager_real_name'] ?? '',
        'start_date'  => $p['start_date'],
        'end_date'    => $p['end_date'],
        'status'      => !empty($p['archived_at']) ? 'archived' : 'active',
        'stats'       => $stats,
        'member_count'=> $memberCount,
        'task_count'  => $taskCount,
    ]);
}

function _ai_create_project($args, $user) {
    if (!isProjectManager() && !isAdmin()) return _ai_err('权限不足:只有项目经理或管理员可以创建项目');
    $name = trim($args['name'] ?? '');
    if ($name === '') return _ai_err('项目名称必填');
    $description = trim($args['description'] ?? '');
    $startDate   = !empty($args['start_date']) ? $args['start_date'] : null;
    $endDate     = !empty($args['end_date'])   ? $args['end_date']   : null;
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO projects (name, description, manager_id, created_by, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')")
            ->execute([$name, $description, $user['id'], $user['id'], $startDate, $endDate]);
        $pid = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO project_members (project_id, user_id, custom_role, status) VALUES (?, ?, '项目经理', 'active')")
            ->execute([$pid, $user['id']]);
        logOperation($user['id'], 'create', 'project', $pid, ['name'=>$name, 'via'=>'ai_assistant']);
        $pdo->commit();
        return _ai_ok(['project_id' => (int)$pid, 'name' => $name, 'message' => '项目已创建']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return _ai_err('创建失败: ' . $e->getMessage());
    }
}

function _ai_update_project($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    if ($pid <= 0) return _ai_err('project_id 必填');
    if (!_ai_can_manage_project($pid, $user)) return _ai_err('权限不足:只有项目负责人或管理员可编辑项目');
    $project = queryOneDb("SELECT * FROM projects WHERE id = ?", [$pid]);
    if (!$project) return _ai_err('项目不存在');

    $newName  = array_key_exists('name', $args)        ? trim($args['name'])        : $project['name'];
    $newDesc  = array_key_exists('description', $args) ? trim($args['description']) : $project['description'];
    $newStart = array_key_exists('start_date', $args)  ? ($args['start_date'] ?: null) : $project['start_date'];
    $newEnd   = array_key_exists('end_date', $args)    ? ($args['end_date']   ?: null) : $project['end_date'];
    $newMgr   = (int)$project['manager_id'];
    if (!empty($args['manager_username'])) {
        $u = queryOneDb("SELECT id FROM users WHERE username = ?", [$args['manager_username']]);
        if (!$u) return _ai_err('指定的用户不存在: ' . $args['manager_username']);
        $newMgr = (int)$u['id'];
    }
    if ($newName === '') return _ai_err('项目名不能为空');
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE projects SET name=?, description=?, start_date=?, end_date=?, manager_id=? WHERE id=?")
            ->execute([$newName, $newDesc, $newStart, $newEnd, $newMgr, $pid]);
        if ($newMgr !== (int)$project['manager_id']) {
            $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, custom_role, status) VALUES (?, ?, '项目经理', 'active')")
                ->execute([$pid, $newMgr]);
        }
        logOperation($user['id'], 'update', 'project', $pid, [
            'old_name' => $project['name'], 'new_name' => $newName,
            'old_manager' => (int)$project['manager_id'], 'new_manager' => $newMgr,
            'via' => 'ai_assistant',
        ]);
        $pdo->commit();
        return _ai_ok(['project_id' => $pid, 'name' => $newName, 'manager' => $newMgr, 'message' => '项目已更新']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return _ai_err('更新失败: ' . $e->getMessage());
    }
}

function _ai_archive_project($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    $archive = !empty($args['archive']);
    if ($pid <= 0) return _ai_err('project_id 必填');
    if (!_ai_can_manage_project($pid, $user)) return _ai_err('权限不足');
    try {
        if ($archive) archiveProject($pid);
        else unarchiveProject($pid);
        logOperation($user['id'], $archive ? 'archive' : 'unarchive', 'project', $pid, ['via' => 'ai_assistant']);
        return _ai_ok(['project_id' => $pid, 'archived' => $archive, 'message' => $archive ? '已归档' : '已恢复为活跃']);
    } catch (Exception $e) {
        return _ai_err($e->getMessage());
    }
}

function _ai_delete_project($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    if ($pid <= 0) return _ai_err('project_id 必填');
    $project = queryOneDb("SELECT * FROM projects WHERE id = ?", [$pid]);
    if (!$project) return _ai_err('项目不存在');
    if (!canDeleteProject($project, $user)) return _ai_err('权限不足:只有项目创建者或管理员可删除');
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM task_attachments WHERE task_id IN (SELECT id FROM tasks WHERE project_id = ?)")->execute([$pid]);
        $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$pid]);
        logOperation($user['id'], 'delete', 'project', $pid, ['name' => $project['name'], 'via' => 'ai_assistant']);
        $pdo->commit();
        return _ai_ok(['project_id' => $pid, 'message' => '项目已删除']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return _ai_err('删除失败: ' . $e->getMessage());
    }
}

function _ai_list_project_members($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    if ($pid <= 0) return _ai_err('project_id 必填');
    if (!_ai_can_read_project($pid, $user)) return _ai_err('权限不足');
    $rows = queryDb(
        "SELECT u.id, u.username, u.name, u.expertise, pm.custom_role, pm.status
         FROM project_members pm JOIN users u ON pm.user_id = u.id
         WHERE pm.project_id = ? AND pm.status = 'active'
         ORDER BY pm.id",
        [$pid]
    );
    return _ai_ok($rows);
}

function _ai_add_project_member($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    $username = trim($args['username'] ?? '');
    $customRole = trim($args['custom_role'] ?? '');
    if ($pid <= 0 || $username === '' || $customRole === '') return _ai_err('project_id/username/custom_role 必填');
    if (!_ai_can_manage_project($pid, $user)) return _ai_err('权限不足');
    $u = queryOneDb("SELECT id FROM users WHERE username = ?", [$username]);
    if (!$u) return _ai_err('用户不存在: ' . $username);
    try {
        executeDb(
            "INSERT INTO project_members (project_id, user_id, custom_role, status) VALUES (?, ?, ?, 'active')",
            [$pid, (int)$u['id'], $customRole]
        );
        logOperation($user['id'], 'add_member', 'project', $pid, ['new_member' => $username, 'role' => $customRole, 'via' => 'ai_assistant']);
        return _ai_ok(['project_id' => $pid, 'username' => $username, 'custom_role' => $customRole, 'message' => '成员已添加']);
    } catch (Exception $e) {
        return _ai_err('添加失败(可能已是成员): ' . $e->getMessage());
    }
}

function _ai_remove_project_member($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    $username = trim($args['username'] ?? '');
    if ($pid <= 0 || $username === '') return _ai_err('project_id/username 必填');
    if (!_ai_can_manage_project($pid, $user)) return _ai_err('权限不足');
    $u = queryOneDb("SELECT id FROM users WHERE username = ?", [$username]);
    if (!$u) return _ai_err('用户不存在');
    executeDb("DELETE FROM project_members WHERE project_id = ? AND user_id = ?", [$pid, (int)$u['id']]);
    logOperation($user['id'], 'remove_member', 'project', $pid, ['removed' => $username, 'via' => 'ai_assistant']);
    return _ai_ok(['project_id' => $pid, 'username' => $username, 'message' => '成员已移除']);
}

// =============================================================================
// 任务
// =============================================================================
function _ai_list_my_tasks($args, $user) {
    $status    = $args['status'] ?? '';
    $projectId = (int)($args['project_id'] ?? 0);
    $overdue   = !empty($args['overdue_only']);
    $limit     = max(1, min(200, (int)($args['limit'] ?? 50)));
    $where = ["t.assignee_id = ?"];
    $params = [$user['id']];
    if ($status)    { $where[] = "t.status = ?";    $params[] = $status; }
    if ($projectId) { $where[] = "t.project_id = ?";$params[] = $projectId; }
    $rows = queryDb(
        "SELECT t.id, t.title, t.status, t.priority, t.progress, t.due_date, t.start_date,
                p.name as project_name, p.id as project_id, p.archived_at
         FROM tasks t JOIN projects p ON t.project_id = p.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC
         LIMIT $limit",
        $params
    );
    $out = [];
    foreach ($rows as $r) {
        $r['overdue'] = isTaskOverdue($r);
        if (!empty($r['archived_at'])) continue;
        if ($overdue && !$r['overdue']) continue;
        unset($r['archived_at']);
        $out[] = $r;
    }
    return _ai_ok($out);
}

function _ai_list_project_tasks($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    if ($pid <= 0) return _ai_err('project_id 必填');
    if (!_ai_can_read_project($pid, $user)) return _ai_err('权限不足');
    $status = $args['status'] ?? '';
    $includeSub = !array_key_exists('include_subtasks', $args) || !empty($args['include_subtasks']);
    $where = ["t.project_id = ?"];
    $params = [$pid];
    if ($status) { $where[] = "t.status = ?"; $params[] = $status; }
    if (!$includeSub) $where[] = "(t.parent_task_id = 0 OR t.parent_task_id IS NULL)";
    $rows = queryDb(
        "SELECT t.id, t.title, t.status, t.priority, t.progress, t.due_date, t.start_date,
                t.parent_task_id, t.assignee_id, u.username as assignee_name
         FROM tasks t LEFT JOIN users u ON t.assignee_id = u.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY t.parent_task_id, t.id DESC
         LIMIT 200",
        $params
    );
    return _ai_ok($rows);
}

function _ai_get_task($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    if ($tid <= 0) return _ai_err('task_id 必填');
    if (!_ai_can_read_task($tid, $user)) return _ai_err('权限不足');
    $t = queryOneDb(
        "SELECT t.*, p.name as project_name, p.id as project_id,
                u.username as assignee_name, u.name as assignee_real_name,
                cu.username as creator_name, cu.name as creator_real_name
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         LEFT JOIN users u  ON t.assignee_id = u.id
         LEFT JOIN users cu ON t.created_by  = cu.id
         WHERE t.id = ?",
        [$tid]
    );
    if (!$t) return _ai_err('任务不存在');
    $out = [
        'id'         => (int)$t['id'],
        'title'      => $t['title'],
        'description'=> $t['description'] ?? '',
        'status'     => $t['status'],
        'priority'   => $t['priority'],
        'progress'   => (int)$t['progress'],
        'start_date' => $t['start_date'],
        'due_date'   => $t['due_date'],
        'parent_task_id' => $t['parent_task_id'] ? (int)$t['parent_task_id'] : null,
        'project_id' => (int)$t['project_id'],
        'project_name' => $t['project_name'],
        'assignee'   => $t['assignee_name'] ? ['id'=>(int)$t['assignee_id'], 'username'=>$t['assignee_name'], 'name'=>$t['assignee_real_name']] : null,
        'creator'    => $t['creator_name'] ? ['username'=>$t['creator_name'], 'name'=>$t['creator_real_name']] : null,
        'created_at' => $t['created_at'],
        'updated_at' => $t['updated_at'],
        'overdue'    => isTaskOverdue($t),
    ];
    $out['dependencies']   = getTaskDependencies($tid);
    $out['dependents']     = getTaskDependents($tid);
    $out['comments']       = queryDb("SELECT c.*, u.username, u.name as user_real_name FROM task_comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.task_id = ? ORDER BY c.created_at DESC LIMIT 50", [$tid]);
    $out['checklist']      = queryDb("SELECT * FROM task_checklist_items WHERE task_id = ? ORDER BY sort_order, id", [$tid]);
    $out['attachments']    = queryDb("SELECT id, filename, original_name, file_size, note, user_id, created_at FROM task_attachments WHERE task_id = ? ORDER BY id DESC", [$tid]);
    $out['time_logs']      = queryDb("SELECT tl.*, u.username FROM time_logs tl LEFT JOIN users u ON tl.user_id = u.id WHERE tl.task_id = ? ORDER BY work_date DESC, id DESC LIMIT 50", [$tid]);
    $out['status_history'] = queryDb("SELECT tsc.*, u.username FROM task_status_changes tsc LEFT JOIN users u ON tsc.user_id = u.id WHERE tsc.task_id = ? ORDER BY tsc.id DESC LIMIT 30", [$tid]);
    $out['assignments']    = queryDb("SELECT ta.*, ru.username as from_name, tu.username as to_name FROM task_assignments ta LEFT JOIN users ru ON ta.from_user_id = ru.id LEFT JOIN users tu ON ta.to_user_id = tu.id WHERE ta.task_id = ? ORDER BY ta.id DESC LIMIT 20", [$tid]);
    $out['subtasks']       = queryDb("SELECT id, title, status, progress, assignee_id FROM tasks WHERE parent_task_id = ? ORDER BY id", [$tid]);
    return _ai_ok($out);
}

function _ai_create_task($args, $user) {
    $projectId = (int)($args['project_id'] ?? 0);
    $title     = trim($args['title'] ?? '');
    if ($projectId <= 0 || $title === '') return _ai_err('project_id 和 title 必填');
    $project = queryOneDb("SELECT * FROM projects WHERE id = ?", [$projectId]);
    if (!$project) return _ai_err('项目不存在');
    if (!_ai_can_read_project($projectId, $user)) return _ai_err('权限不足:您不是该项目的成员');

    $assigneeId   = null;
    $assignReason = trim($args['assign_reason'] ?? '');
    if (!empty($args['assignee_username'])) {
        $au = queryOneDb("SELECT id FROM users WHERE username = ?", [$args['assignee_username']]);
        if (!$au) return _ai_err('指派的用户不存在: ' . $args['assignee_username']);
        $mem = queryOneDb("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? AND status = 'active'", [$projectId, $au['id']]);
        if (!$mem) return _ai_err('该用户不是项目成员,无法指派');
        $assigneeId = (int)$au['id'];
    }

    $priority      = in_array($args['priority'] ?? '', ['low','medium','high'], true) ? $args['priority'] : 'medium';
    $dueDate       = !empty($args['due_date'])     ? $args['due_date']     : null;
    $startDate     = !empty($args['start_date'])   ? $args['start_date']   : null;
    $estHours      = isset($args['estimated_hours']) ? (float)$args['estimated_hours'] : 0;
    $parentId      = !empty($args['parent_task_id']) ? (int)$args['parent_task_id'] : 0;
    $description   = $args['description'] ?? '';
    $depIds        = isset($args['depends_on']) && is_array($args['depends_on']) ? $args['depends_on'] : [];

    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO tasks (project_id, parent_task_id, title, description, assignee_id, priority, start_date, due_date, progress, status, estimated_hours, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'todo', ?, ?)"
        )->execute([$projectId, $parentId ?: null, $title, $description, $assigneeId, $priority, $startDate, $dueDate, $estHours, $user['id']]);
        $newId = (int)$pdo->lastInsertId();

        foreach ($depIds as $did) {
            $did = (int)$did;
            if ($did > 0 && $did !== $newId) {
                try { addTaskDependency($newId, $did, '', $user['id']); } catch (Exception $e) {}
            }
        }

        if ($assigneeId) {
            addTaskAssignment($newId, $user['id'], null, $assigneeId, $assignReason);
            if ($assigneeId !== (int)$user['id']) {
                addNotification($assigneeId, 'task_assign',
                    "AI 助手为你创建了任务: " . $title,
                    $user['name'] . " 通过 AI 助手创建并指派给你" . ($assignReason ? "。\n原因: " . $assignReason : ""),
                    'task', $newId);
            }
        }

        logOperation($user['id'], 'create', 'task', $newId, [
            'title' => $title, 'project_id' => $projectId,
            'assignee_id' => $assigneeId, 'via' => 'ai_assistant',
        ]);
        $pdo->commit();
        return _ai_ok(['task_id' => $newId, 'title' => $title, 'assignee' => $assigneeId, 'message' => '任务已创建']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return _ai_err('创建失败: ' . $e->getMessage());
    }
}

function _ai_update_task($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    if ($tid <= 0) return _ai_err('task_id 必填');
    if (!_ai_can_manage_task($tid, $user)) return _ai_err('权限不足:只有项目负责人或管理员可编辑任务');
    $task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$tid]);
    if (!$task) return _ai_err('任务不存在');

    $newTitle    = array_key_exists('title', $args)         ? trim($args['title'])        : $task['title'];
    $newDesc     = array_key_exists('description', $args)  ? trim($args['description']) : $task['description'];
    $newPriority = array_key_exists('priority', $args)      ? $args['priority']           : $task['priority'];
    $newStart    = array_key_exists('start_date', $args)    ? ($args['start_date'] ?: null) : $task['start_date'];
    $newDue      = array_key_exists('due_date', $args)      ? ($args['due_date']   ?: null) : $task['due_date'];
    $newProgress = array_key_exists('progress', $args)      ? max(0, min(100, (int)$args['progress'])) : (int)$task['progress'];
    $newParent   = array_key_exists('parent_task_id', $args) ? (int)$args['parent_task_id'] : (int)$task['parent_task_id'];
    $editNote    = trim($args['edit_note'] ?? '');

    if (!in_array($newPriority, ['low','medium','high'], true)) $newPriority = 'medium';
    if ($newTitle === '') return _ai_err('标题不能为空');
    if ($newParent === $tid) return _ai_err('父任务不能是任务自身');

    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE tasks SET title=?, description=?, priority=?, start_date=?, due_date=?, progress=?, parent_task_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$newTitle, $newDesc, $newPriority, $newStart, $newDue, $newProgress, $newParent ?: null, $tid]);

        $changes = [];
        if ($task['title'] !== $newTitle) $changes['title'] = ['old' => $task['title'], 'new' => $newTitle];
        if (($task['description'] ?: '') !== $newDesc) $changes['description'] = ['old' => $task['description'], 'new' => $newDesc];
        if ($task['priority'] !== $newPriority) $changes['priority'] = ['old' => $task['priority'], 'new' => $newPriority];
        if (($task['start_date'] ?: null) !== $newStart) $changes['start_date'] = ['old' => $task['start_date'], 'new' => $newStart];
        if (($task['due_date'] ?: null) !== $newDue) $changes['due_date'] = ['old' => $task['due_date'], 'new' => $newDue];
        if ((int)$task['progress'] !== $newProgress) $changes['progress'] = ['old' => (int)$task['progress'], 'new' => $newProgress];
        if ((int)$task['parent_task_id'] !== $newParent) $changes['parent_task_id'] = ['old' => (int)$task['parent_task_id'], 'new' => $newParent];

        if (!empty($changes)) {
            logOperation($user['id'], 'update', 'task', $tid, ['changes' => $changes, 'note' => $editNote, 'via' => 'ai_assistant']);
        }
        $pdo->commit();
        return _ai_ok(['task_id' => $tid, 'changes' => array_keys($changes), 'message' => '任务已更新']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return _ai_err('更新失败: ' . $e->getMessage());
    }
}

function _ai_update_task_status($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    $newStatus = $args['new_status'] ?? '';
    if ($tid <= 0) return _ai_err('task_id 必填');
    if (!in_array($newStatus, ['todo','in_progress','blocked','done'], true)) {
        return _ai_err('new_status 必须是 todo/in_progress/blocked/done');
    }
    $task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$tid]);
    if (!$task) return _ai_err('任务不存在');

    $isAssignee = ((int)$task['assignee_id'] === (int)$user['id']);
    $canManage  = _ai_can_manage_task($tid, $user);
    if (!$isAssignee && !$canManage) return _ai_err('权限不足');

    $newProgress = max(0, min(100, (int)($args['progress'] ?? $task['progress'])));
    if ($newStatus === 'done') $newProgress = 100;
    $note = $args['note'] ?? 'AI 助手更新';

    try {
        $oldStatus = $task['status'];
        $oldProgress = (int)$task['progress'];
        executeDb("UPDATE tasks SET status=?, progress=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$newStatus, $newProgress, $tid]);
        addTaskStatusChange($tid, $user['id'], $oldStatus, $newStatus, $oldProgress, $newProgress, $note);
        logOperation($user['id'], 'status_change', 'task', $tid, [
            'old_status' => $oldStatus, 'new_status' => $newStatus,
            'note' => $note, 'via' => 'ai_assistant',
        ]);
        if ($task['assignee_id'] && (int)$task['assignee_id'] !== (int)$user['id']) {
            addNotification((int)$task['assignee_id'], 'task_status',
                "AI 助手更新了任务状态: " . $task['title'],
                $user['name'] . " 通过 AI 助手把状态从 " . getTaskStatusText($oldStatus) . " 改为 " . getTaskStatusText($newStatus),
                'task', $tid);
        }
        return _ai_ok(['task_id' => $tid, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'progress' => $newProgress]);
    } catch (Exception $e) {
        return _ai_err('更新失败: ' . $e->getMessage());
    }
}

function _ai_reassign_task($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    $reason = trim($args['reason'] ?? '');
    if ($tid <= 0) return _ai_err('task_id 必填');
    if ($reason === '') return _ai_err('重新指派必须填写原因(reason 必填)');
    $task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$tid]);
    if (!$task) return _ai_err('任务不存在');
    if (!_ai_can_manage_task($tid, $user)) return _ai_err('权限不足:只有项目负责人或管理员可重新指派');

    $newAssigneeId = null;
    if (isset($args['new_assignee_username']) && $args['new_assignee_username'] !== '') {
        $au = queryOneDb("SELECT id FROM users WHERE username = ?", [$args['new_assignee_username']]);
        if (!$au) return _ai_err('用户不存在: ' . $args['new_assignee_username']);
        $newAssigneeId = (int)$au['id'];
    }

    $resetStatus = $args['reset_status'] ?? 'keep';
    $resetProgress = isset($args['reset_progress']) ? (int)$args['reset_progress'] : null;

    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        $fromUserId = $task['assignee_id'] ? (int)$task['assignee_id'] : null;
        addTaskAssignment($tid, $user['id'], $fromUserId, $newAssigneeId, $reason);
        executeDb("UPDATE tasks SET assignee_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$newAssigneeId, $tid]);

        $newStatus = $task['status']; $newProg = (int)$task['progress'];
        if ($resetStatus !== 'keep' && in_array($resetStatus, ['todo','in_progress','blocked','done'], true)) $newStatus = $resetStatus;
        if ($resetProgress !== null) $newProg = max(0, min(100, $resetProgress));
        if ($newStatus !== $task['status'] || $newProg !== (int)$task['progress']) {
            executeDb("UPDATE tasks SET status=?, progress=? WHERE id=?", [$newStatus, $newProg, $tid]);
            addTaskStatusChange($tid, $user['id'], $task['status'], $newStatus, (int)$task['progress'], $newProg, '重新指派: ' . $reason);
        }

        if ($newAssigneeId && $newAssigneeId !== (int)$user['id']) {
            addNotification($newAssigneeId, 'task_reassign',
                "AI 助手将任务指派给你: " . $task['title'],
                $user['name'] . " 通过 AI 助手重新指派。\n原因: " . $reason,
                'task', $tid);
        }
        logOperation($user['id'], 'reassign', 'task', $tid, [
            'from' => $fromUserId, 'to' => $newAssigneeId, 'reason' => $reason, 'via' => 'ai_assistant',
        ]);
        $pdo->commit();
        return _ai_ok(['task_id' => $tid, 'new_assignee' => $newAssigneeId, 'message' => '已重新指派']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return _ai_err('指派失败: ' . $e->getMessage());
    }
}

function _ai_delete_task($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    if ($tid <= 0) return _ai_err('task_id 必填');
    $task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$tid]);
    if (!$task) return _ai_err('任务不存在');
    if (!canDeleteTask($task, $user)) return _ai_err('权限不足:只有任务创建者或管理员可删除');
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM task_attachments WHERE task_id = ?")->execute([$tid]);
        $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$tid]);
        logOperation($user['id'], 'delete', 'task', $tid, ['title' => $task['title'], 'via' => 'ai_assistant']);
        $pdo->commit();
        return _ai_ok(['task_id' => $tid, 'message' => '任务已删除']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return _ai_err('删除失败: ' . $e->getMessage());
    }
}

// =============================================================================
// 任务细节
// =============================================================================
function _ai_add_task_dependency($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    $did = (int)($args['depends_on_task_id'] ?? 0);
    $note = trim($args['note'] ?? '');
    if ($tid <= 0 || $did <= 0) return _ai_err('task_id 和 depends_on_task_id 必填');
    if (!_ai_can_manage_task($tid, $user)) return _ai_err('权限不足');
    try {
        addTaskDependency($tid, $did, $note, $user['id']);
        logOperation($user['id'], 'create', 'task_dependency', $tid, ['depends_on' => $did, 'note' => $note, 'via' => 'ai_assistant']);
        return _ai_ok(['task_id' => $tid, 'depends_on_task_id' => $did, 'message' => '依赖已添加']);
    } catch (Exception $e) {
        return _ai_err($e->getMessage());
    }
}

function _ai_remove_task_dependency($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    $did = (int)($args['depends_on_task_id'] ?? 0);
    if ($tid <= 0 || $did <= 0) return _ai_err('task_id 和 depends_on_task_id 必填');
    if (!_ai_can_manage_task($tid, $user)) return _ai_err('权限不足');
    removeTaskDependency($tid, $did);
    logOperation($user['id'], 'delete', 'task_dependency', $tid, ['depends_on' => $did, 'via' => 'ai_assistant']);
    return _ai_ok(['task_id' => $tid, 'depends_on_task_id' => $did, 'message' => '依赖已移除']);
}

function _ai_add_task_comment($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    $content = trim($args['content'] ?? '');
    if ($tid <= 0 || $content === '') return _ai_err('task_id 和 content 必填');
    if (!_ai_can_read_task($tid, $user)) return _ai_err('权限不足');
    $task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$tid]);
    addTaskComment($tid, $user['id'], $content);
    logOperation($user['id'], 'comment', 'task', $tid, ['via' => 'ai_assistant']);
    if ($task['assignee_id'] && (int)$task['assignee_id'] !== (int)$user['id']) {
        addNotification((int)$task['assignee_id'], 'task_comment',
            "AI 助手在任务中发表了评论: " . $task['title'],
            $user['name'] . ": " . mb_substr($content, 0, 50),
            'task', $tid);
    }
    return _ai_ok(['task_id' => $tid, 'message' => '评论已发布']);
}

function _ai_list_task_comments($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    if ($tid <= 0) return _ai_err('task_id 必填');
    if (!_ai_can_read_task($tid, $user)) return _ai_err('权限不足');
    $rows = queryDb(
        "SELECT c.id, c.content, c.created_at, c.updated_at, u.username, u.name as user_real_name
         FROM task_comments c LEFT JOIN users u ON c.user_id = u.id
         WHERE c.task_id = ? ORDER BY c.created_at DESC LIMIT 100",
        [$tid]
    );
    return _ai_ok($rows);
}

function _ai_add_checklist_item($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    $content = trim($args['content'] ?? '');
    if ($tid <= 0 || $content === '') return _ai_err('task_id 和 content 必填');
    if (!_ai_can_read_task($tid, $user)) return _ai_err('权限不足');
    $maxOrder = (int)queryOneDb("SELECT IFNULL(MAX(sort_order),0) AS m FROM task_checklist_items WHERE task_id = ?", [$tid])['m'];
    executeDb("INSERT INTO task_checklist_items (task_id, content, sort_order, created_by) VALUES (?, ?, ?, ?)",
        [$tid, $content, $maxOrder + 10, $user['id']]);
    $id = getLastInsertId();
    logOperation($user['id'], 'create', 'task_checklist', $id, ['task_id' => $tid, 'via' => 'ai_assistant']);
    return _ai_ok(['item_id' => (int)$id, 'message' => 'checklist 项已添加']);
}

function _ai_update_checklist_item($args, $user) {
    $iid = (int)($args['item_id'] ?? 0);
    if ($iid <= 0) return _ai_err('item_id 必填');
    $item = queryOneDb("SELECT * FROM task_checklist_items WHERE id = ?", [$iid]);
    if (!$item) return _ai_err('checklist 项不存在');
    if (!_ai_can_read_task((int)$item['task_id'], $user)) return _ai_err('权限不足');
    $newContent = array_key_exists('content', $args) ? trim($args['content']) : $item['content'];
    $newDone    = array_key_exists('is_done', $args) ? (!empty($args['is_done']) ? 1 : 0) : (int)$item['is_done'];
    executeDb("UPDATE task_checklist_items SET content=?, is_done=? WHERE id=?", [$newContent, $newDone, $iid]);
    logOperation($user['id'], 'update', 'task_checklist', $iid, ['task_id' => (int)$item['task_id'], 'is_done' => $newDone, 'via' => 'ai_assistant']);
    return _ai_ok(['item_id' => $iid, 'is_done' => (bool)$newDone, 'message' => '已更新']);
}

function _ai_log_time($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    $hours = (float)($args['hours'] ?? 0);
    $workDate = !empty($args['work_date']) ? $args['work_date'] : date('Y-m-d');
    $note = trim($args['note'] ?? '');
    if ($tid <= 0 || $hours <= 0) return _ai_err('task_id 和 hours (>0) 必填');
    if (!_ai_can_read_task($tid, $user)) return _ai_err('权限不足');
    executeDb("INSERT INTO time_logs (task_id, user_id, hours, work_date, note) VALUES (?, ?, ?, ?, ?)",
        [$tid, $user['id'], $hours, $workDate, $note]);
    $id = getLastInsertId();
    logOperation($user['id'], 'log_time', 'task', $tid, ['hours' => $hours, 'work_date' => $workDate, 'via' => 'ai_assistant']);
    return _ai_ok(['time_log_id' => (int)$id, 'task_id' => $tid, 'hours' => $hours, 'message' => '工时已记录']);
}

function _ai_list_task_attachments($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    if ($tid <= 0) return _ai_err('task_id 必填');
    if (!_ai_can_read_task($tid, $user)) return _ai_err('权限不足');
    $rows = queryDb(
        "SELECT a.id, a.filename, a.original_name, a.file_size, a.note, a.created_at, u.username as uploader
         FROM task_attachments a LEFT JOIN users u ON a.user_id = u.id
         WHERE a.task_id = ? ORDER BY a.id DESC",
        [$tid]
    );
    return _ai_ok($rows);
}

function _ai_mark_task_blocked($args, $user) {
    $tid = (int)($args['task_id'] ?? 0);
    $reason = trim($args['block_reason'] ?? '');
    if ($tid <= 0 || $reason === '') return _ai_err('task_id 和 block_reason 必填');
    $task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$tid]);
    if (!$task) return _ai_err('任务不存在');
    $isAssignee = ((int)$task['assignee_id'] === (int)$user['id']);
    if (!$isAssignee && !_ai_can_manage_task($tid, $user)) return _ai_err('权限不足');

    $assistId = null;
    if (!empty($args['requested_assist_username'])) {
        $au = queryOneDb("SELECT id FROM users WHERE username = ?", [$args['requested_assist_username']]);
        if ($au) $assistId = (int)$au['id'];
    }
    addTaskBlockedInfo($tid, $user['id'], $reason, $assistId);
    executeDb("UPDATE tasks SET status='blocked', updated_at=CURRENT_TIMESTAMP WHERE id=?", [$tid]);
    addTaskStatusChange($tid, $user['id'], $task['status'], 'blocked', (int)$task['progress'], (int)$task['progress'], '阻塞: ' . $reason);
    logOperation($user['id'], 'block', 'task', $tid, ['reason' => $reason, 'via' => 'ai_assistant']);
    if ($assistId) {
        addNotification($assistId, 'request_assist',
            "AI 助手请求你协助: " . $task['title'],
            $user['name'] . " 通过 AI 助手请求协助。\n原因: " . $reason,
            'task', $tid);
    }
    return _ai_ok(['task_id' => $tid, 'message' => '任务已标记为阻塞']);
}

// =============================================================================
// 里程碑
// =============================================================================
function _ai_list_milestones($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    if ($pid <= 0) return _ai_err('project_id 必填');
    if (!_ai_can_read_project($pid, $user)) return _ai_err('权限不足');
    $rows = queryDb("SELECT * FROM milestones WHERE project_id = ? ORDER BY due_date IS NULL, due_date", [$pid]);
    return _ai_ok($rows);
}

function _ai_add_milestone($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    $name = trim($args['name'] ?? '');
    if ($pid <= 0 || $name === '') return _ai_err('project_id 和 name 必填');
    if (!_ai_can_manage_project($pid, $user)) return _ai_err('权限不足');
    $desc = trim($args['description'] ?? '');
    $due = !empty($args['due_date']) ? $args['due_date'] : null;
    addMilestone($pid, $name, $desc, $due, $user['id']);
    logOperation($user['id'], 'create', 'milestone', null, ['project_id' => $pid, 'name' => $name, 'via' => 'ai_assistant']);
    return _ai_ok(['message' => '里程碑已添加', 'name' => $name]);
}

function _ai_delete_milestone($args, $user) {
    $mid = (int)($args['milestone_id'] ?? 0);
    if ($mid <= 0) return _ai_err('milestone_id 必填');
    $m = queryOneDb("SELECT * FROM milestones WHERE id = ?", [$mid]);
    if (!$m) return _ai_err('里程碑不存在');
    if (!_ai_can_manage_project((int)$m['project_id'], $user)) return _ai_err('权限不足');
    deleteMilestone($mid);
    logOperation($user['id'], 'delete', 'milestone', $mid, ['via' => 'ai_assistant']);
    return _ai_ok(['message' => '里程碑已删除']);
}

// =============================================================================
// 跨域查询
// =============================================================================
function _ai_get_week_summary($user) {
    $monday = date('Y-m-d', strtotime('monday this week'));
    $sunday = date('Y-m-d', strtotime('sunday this week'));
    $today  = date('Y-m-d');
    $nextMon = date('Y-m-d', strtotime('monday next week'));
    $nextSun = date('Y-m-d', strtotime('sunday next week'));

    if (isAdmin()) {
        $myTasks = queryDb("SELECT t.*, p.name as project_name FROM tasks t JOIN projects p ON t.project_id = p.id");
    } else {
        $myTasks = queryDb(
            "SELECT t.*, p.name as project_name
             FROM tasks t JOIN projects p ON t.project_id = p.id
             WHERE t.assignee_id = ?
                OR p.manager_id = ?
                OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ? AND status = 'active')",
            [$user['id'], $user['id'], $user['id']]
        );
    }

    $createdThisWeek = 0; $doneThisWeek = 0; $inProgress = 0; $blocked = 0; $todo = 0;
    $overdue = []; $thisWeekDue = []; $nextWeekDue = [];
    foreach ($myTasks as $t) {
        if (substr($t['created_at'], 0, 10) >= $monday) $createdThisWeek++;
        if ($t['status'] === 'done' && substr($t['updated_at'], 0, 10) >= $monday) $doneThisWeek++;
        if ($t['status'] === 'in_progress') $inProgress++;
        if ($t['status'] === 'blocked') $blocked++;
        if ($t['status'] === 'todo') $todo++;
        if (isTaskOverdue($t)) $overdue[] = $t;
        if ($t['due_date'] && $t['due_date'] >= $monday && $t['due_date'] <= $sunday && $t['status'] !== 'done') $thisWeekDue[] = $t;
        if ($t['due_date'] && $t['due_date'] >= $nextMon && $t['due_date'] <= $nextSun && $t['status'] !== 'done') $nextWeekDue[] = $t;
    }
    return _ai_ok([
        'period' => ['monday' => $monday, 'sunday' => $sunday, 'today' => $today],
        'user'   => $user['name'] ?: $user['username'],
        'scope'  => isAdmin() ? 'all' : 'my',
        'stats'  => [
            'total_tasks' => count($myTasks),
            'created_this_week' => $createdThisWeek,
            'done_this_week'    => $doneThisWeek,
            'in_progress'       => $inProgress,
            'blocked'           => $blocked,
            'todo'              => $todo,
            'overdue_count'     => count($overdue),
        ],
        'this_week_due' => $thisWeekDue,
        'next_week_due' => $nextWeekDue,
        'overdue_tasks' => $overdue,
    ]);
}

function _ai_get_project_dashboard($args, $user) {
    $pid = (int)($args['project_id'] ?? 0);
    if ($pid <= 0) return _ai_err('project_id 必填');
    if (!_ai_can_read_project($pid, $user)) return _ai_err('权限不足');
    $p = queryOneDb(
        "SELECT p.*, u.username as manager, u.name as manager_real_name
         FROM projects p LEFT JOIN users u ON p.manager_id = u.id WHERE p.id = ?",
        [$pid]
    );
    if (!$p) return _ai_err('项目不存在');
    $stats = getProjectStats($pid);
    $members = queryDb(
        "SELECT u.id, u.username, u.name, pm.custom_role,
                (SELECT COUNT(*) FROM tasks WHERE project_id = ? AND assignee_id = u.id) AS total_tasks,
                (SELECT COUNT(*) FROM tasks WHERE project_id = ? AND assignee_id = u.id AND status = 'done') AS done_tasks,
                (SELECT COUNT(*) FROM tasks WHERE project_id = ? AND assignee_id = u.id AND status = 'in_progress') AS in_progress_tasks,
                (SELECT COUNT(*) FROM tasks WHERE project_id = ? AND assignee_id = u.id AND status = 'blocked') AS blocked_tasks
         FROM project_members pm JOIN users u ON pm.user_id = u.id
         WHERE pm.project_id = ? AND pm.status = 'active'",
        [$pid, $pid, $pid, $pid, $pid]
    );
    $milestones = getProjectMilestones($pid);
    $recent = queryDb(
        "SELECT ol.*, u.username FROM operation_logs ol LEFT JOIN users u ON ol.user_id = u.id
         WHERE (ol.target_type = 'task' AND ol.target_id IN (SELECT id FROM tasks WHERE project_id = ?))
            OR (ol.target_type = 'project' AND ol.target_id = ?)
         ORDER BY ol.id DESC LIMIT 20",
        [$pid, $pid]
    );
    return _ai_ok([
        'project'    => $p,
        'stats'      => $stats,
        'members'    => $members,
        'milestones' => $milestones,
        'recent_ops' => $recent,
    ]);
}

function _ai_search_tasks($args, $user) {
    $kw = trim($args['keyword'] ?? '');
    if ($kw === '') return _ai_err('keyword 必填');
    $limit = max(1, min(100, (int)($args['limit'] ?? 20)));
    $rows = globalSearchTasks($kw, $user['id'], $limit);
    return _ai_ok($rows);
}
