<?php
/**
 * AI 助手工具函数集合
 * AI Assistant Tools (Function Calling)
 *
 * 每个工具:
 *   - getAiTools() 返回 OpenAI-compatible function definitions
 *   - executeAiTool($name, $args, $user) 真正执行
 *
 * 所有工具自动校验当前用户的权限范围:
 *   - 管理员:全部能力
 *   - 项目经理:自己负责项目的全部能力
 *   - 项目成员:自己参与项目的基础操作
 *   - 删除类: 管理员 OR 资源创建者
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

// 权限辅助
function _ai_can_read_project($projectId, $user) {
    if (isAdmin()) return true;
    $p = queryOneDb("SELECT manager_id FROM projects WHERE id = ?", [$projectId]);
    if (!$p) return false;
    if ((int)$p['manager_id'] === (int)$user['id']) return true;
    return isProjectMember($projectId, $user['id']);
}
function _ai_can_manage_project($projectId, $user) {
    if (isAdmin()) return true;
    $p = queryOneDb("SELECT manager_id FROM projects WHERE id = ?", [$projectId]);
    if (!$p) return false;
    return (int)$p['manager_id'] === (int)$user['id'];
}
function _ai_can_read_task($taskId, $user) {
    if (isAdmin()) return true;
    $t = queryOneDb("SELECT project_id, assignee_id FROM tasks WHERE id = ?", [$taskId]);
    if (!$t) return false;
    if ((int)$t['assignee_id'] === (int)$user['id']) return true;
    return _ai_can_read_project((int)$t['project_id'], $user);
}
function _ai_can_manage_task($taskId, $user) {
    if (isAdmin()) return true;
    $t = queryOneDb("SELECT project_id FROM tasks WHERE id = ?", [$taskId]);
    if (!$t) return false;
    return _ai_can_manage_project((int)$t['project_id'], $user);
}

// ============ Schema ============
function getAiTools() {
    $tools = array (
  0 => 
  array (
    'name' => 'get_current_user',
    'desc' => '获取当前登录用户的基本信息(用户名、姓名、角色、专长、权限范围)',
    'params' => 
    array (
    ),
    'required' => 
    array (
    ),
  ),
  1 => 
  array (
    'name' => 'list_users',
    'desc' => '查询系统中的用户列表(用于指派时获取正确的用户名)。返回 id/username/name/role 字段',
    'params' => 
    array (
      'keyword' => 
      array (
        'type' => 'string',
        'desc' => '搜索用户名/姓名关键词(可选)',
      ),
      'role' => 
      array (
        'type' => 'string',
        'desc' => '按角色过滤: admin / project_manager / team_member (可选)',
      ),
      'limit' => 
      array (
        'type' => 'integer',
        'desc' => '最多返回多少条(默认 50,最大 200)',
      ),
    ),
    'required' => 
    array (
    ),
  ),
  2 => 
  array (
    'name' => 'list_my_projects',
    'desc' => '列出当前用户参与的所有项目(我负责的 + 我是成员的)。非管理员只能看到自己参与的项目',
    'params' => 
    array (
    ),
    'required' => 
    array (
    ),
  ),
  3 => 
  array (
    'name' => 'list_all_projects',
    'desc' => '列出系统所有项目。仅管理员能查看全部,其他用户等同于 list_my_projects',
    'params' => 
    array (
    ),
    'required' => 
    array (
    ),
  ),
  4 => 
  array (
    'name' => 'get_project',
    'desc' => '获取项目详情:基础信息、任务统计、成员数、任务数',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
    ),
  ),
  5 => 
  array (
    'name' => 'create_project',
    'desc' => '创建新项目。需要 project_manager 或 admin 权限。创建者自动成为项目经理和成员',
    'params' => 
    array (
      'name' => 
      array (
        'type' => 'string',
        'desc' => '项目名称(必填)',
      ),
      'description' => 
      array (
        'type' => 'string',
        'desc' => '项目描述',
      ),
      'start_date' => 
      array (
        'type' => 'string',
        'desc' => '开始日期 YYYY-MM-DD',
      ),
      'end_date' => 
      array (
        'type' => 'string',
        'desc' => '结束日期 YYYY-MM-DD',
      ),
    ),
    'required' => 
    array (
      0 => 'name',
    ),
  ),
  6 => 
  array (
    'name' => 'update_project',
    'desc' => '编辑项目基础信息(名称/描述/起止日期/负责人)。需要项目负责人或 admin 权限',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
      'name' => 
      array (
        'type' => 'string',
        'desc' => '新项目名',
      ),
      'description' => 
      array (
        'type' => 'string',
        'desc' => '新描述',
      ),
      'start_date' => 
      array (
        'type' => 'string',
        'desc' => '新开始日期',
      ),
      'end_date' => 
      array (
        'type' => 'string',
        'desc' => '新结束日期',
      ),
      'manager_username' => 
      array (
        'type' => 'string',
        'desc' => '新负责人用户名(留空不改)',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
    ),
  ),
  7 => 
  array (
    'name' => 'archive_project',
    'desc' => '归档/取消归档项目。需要项目负责人或 admin',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
      'archive' => 
      array (
        'type' => 'boolean',
        'desc' => 'true=归档, false=恢复为活跃',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
      1 => 'archive',
    ),
  ),
  8 => 
  array (
    'name' => 'delete_project',
    'desc' => '删除项目(级联删任务/成员/评论/附件/工时等所有数据,不可恢复)。需要管理员 OR 项目创建者',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
    ),
  ),
  9 => 
  array (
    'name' => 'list_project_members',
    'desc' => '列出项目所有成员(含项目角色)',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
    ),
  ),
  10 => 
  array (
    'name' => 'add_project_member',
    'desc' => '添加项目成员。需要项目负责人或 admin 权限。username 必须是系统已有用户',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
      'username' => 
      array (
        'type' => 'string',
        'desc' => '要添加的用户名',
      ),
      'custom_role' => 
      array (
        'type' => 'string',
        'desc' => '此人在项目中的角色(如:算法工程师/测试工程师)',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
      1 => 'username',
      2 => 'custom_role',
    ),
  ),
  11 => 
  array (
    'name' => 'remove_project_member',
    'desc' => '移除项目成员。需要项目负责人或 admin 权限',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
      'username' => 
      array (
        'type' => 'string',
        'desc' => '要移除的用户名',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
      1 => 'username',
    ),
  ),
  12 => 
  array (
    'name' => 'list_my_tasks',
    'desc' => '获取分配给当前用户的任务(分给我的)',
    'params' => 
    array (
      'status' => 
      array (
        'type' => 'string',
        'desc' => 'todo/in_progress/blocked/done 或空=全部',
      ),
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '按项目过滤',
      ),
      'overdue_only' => 
      array (
        'type' => 'boolean',
        'desc' => '只看逾期',
      ),
      'limit' => 
      array (
        'type' => 'integer',
        'desc' => '最多返回多少条(默认 50)',
      ),
    ),
    'required' => 
    array (
    ),
  ),
  13 => 
  array (
    'name' => 'list_project_tasks',
    'desc' => '列出指定项目的所有任务(可按状态过滤)。需要是项目成员',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
      'status' => 
      array (
        'type' => 'string',
        'desc' => '按状态过滤',
      ),
      'include_subtasks' => 
      array (
        'type' => 'boolean',
        'desc' => '是否包含子任务(默认 true)',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
    ),
  ),
  14 => 
  array (
    'name' => 'get_task',
    'desc' => '获取任务完整详情:基础信息 + 负责人 + 依赖关系 + 子任务 + 评论 + checklist + 附件 + 工时 + 状态变更历史',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
    ),
  ),
  15 => 
  array (
    'name' => 'create_task',
    'desc' => '创建任务(在指定项目下)。如要指派负责人,可以填 assign_reason(非必填);不指派就留空 assignee_username。',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID(必填)',
      ),
      'title' => 
      array (
        'type' => 'string',
        'desc' => '任务标题(必填)',
      ),
      'description' => 
      array (
        'type' => 'string',
        'desc' => '任务描述',
      ),
      'assignee_username' => 
      array (
        'type' => 'string',
        'desc' => '指派给的用户名(留空=不指派)',
      ),
      'assign_reason' => 
      array (
        'type' => 'string',
        'desc' => '指派原因(可选)',
      ),
      'priority' => 
      array (
        'type' => 'string',
        'desc' => 'low/medium/high,默认 medium',
      ),
      'due_date' => 
      array (
        'type' => 'string',
        'desc' => '截止日期 YYYY-MM-DD',
      ),
      'start_date' => 
      array (
        'type' => 'string',
        'desc' => '开始日期 YYYY-MM-DD',
      ),
      'estimated_hours' => 
      array (
        'type' => 'number',
        'desc' => '预估工时',
      ),
      'parent_task_id' => 
      array (
        'type' => 'integer',
        'desc' => '父任务 ID(子任务)',
      ),
      'depends_on' => 
      array (
        'type' => 'array',
        'items' => 
        array (
          'type' => 'integer',
        ),
        'desc' => '依赖任务 ID 列表',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
      1 => 'title',
    ),
  ),
  16 => 
  array (
    'name' => 'update_task',
    'desc' => '编辑任务基础信息(标题/描述/优先级/进度/起止日期/父任务)。改负责人请用 reassign_task,改状态请用 update_task_status。',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
      'title' => 
      array (
        'type' => 'string',
        'desc' => '新标题',
      ),
      'description' => 
      array (
        'type' => 'string',
        'desc' => '新描述',
      ),
      'priority' => 
      array (
        'type' => 'string',
        'desc' => 'low/medium/high',
      ),
      'progress' => 
      array (
        'type' => 'integer',
        'desc' => '完成百分比 0-100',
      ),
      'start_date' => 
      array (
        'type' => 'string',
        'desc' => '开始日期 YYYY-MM-DD',
      ),
      'due_date' => 
      array (
        'type' => 'string',
        'desc' => '截止日期 YYYY-MM-DD',
      ),
      'parent_task_id' => 
      array (
        'type' => 'integer',
        'desc' => '新父任务 ID(0=置为顶级)',
      ),
      'edit_note' => 
      array (
        'type' => 'string',
        'desc' => '本次编辑说明(写入操作日志)',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
    ),
  ),
  17 => 
  array (
    'name' => 'update_task_status',
    'desc' => '更新任务状态(可同时改进度)。需要是项目负责人/任务负责人/管理员',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
      'new_status' => 
      array (
        'type' => 'string',
        'desc' => 'todo/in_progress/blocked/done',
      ),
      'progress' => 
      array (
        'type' => 'integer',
        'desc' => '完成百分比 0-100',
      ),
      'note' => 
      array (
        'type' => 'string',
        'desc' => '变更说明',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
      1 => 'new_status',
    ),
  ),
  18 => 
  array (
    'name' => 'reassign_task',
    'desc' => '重新指派任务负责人(reason 必填,会写入操作日志和指派历史)。需要项目负责人或 admin 权限',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
      'new_assignee_username' => 
      array (
        'type' => 'string',
        'desc' => '新负责人用户名(空字符串=取消指派)',
      ),
      'reason' => 
      array (
        'type' => 'string',
        'desc' => '重新指派原因(必填,会写入审计日志)',
      ),
      'reset_status' => 
      array (
        'type' => 'string',
        'desc' => '可选:重置状态 (todo/in_progress/blocked/done/keep)',
      ),
      'reset_progress' => 
      array (
        'type' => 'integer',
        'desc' => '可选:重置进度 0-100',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
      1 => 'reason',
    ),
  ),
  19 => 
  array (
    'name' => 'delete_task',
    'desc' => '删除任务(级联删子任务/评论/附件/工时/依赖等)。需要管理员 OR 任务创建者',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
    ),
  ),
  20 => 
  array (
    'name' => 'add_task_dependency',
    'desc' => '为任务添加依赖(此任务必须等 depends_on_task_id 完成后才能进入 in_progress)',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '被依赖的任务 ID',
      ),
      'depends_on_task_id' => 
      array (
        'type' => 'integer',
        'desc' => '本任务要等它完成',
      ),
      'note' => 
      array (
        'type' => 'string',
        'desc' => '依赖说明(可选)',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
      1 => 'depends_on_task_id',
    ),
  ),
  21 => 
  array (
    'name' => 'remove_task_dependency',
    'desc' => '删除任务的某个依赖关系',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
      'depends_on_task_id' => 
      array (
        'type' => 'integer',
        'desc' => '要移除的依赖任务 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
      1 => 'depends_on_task_id',
    ),
  ),
  22 => 
  array (
    'name' => 'add_task_comment',
    'desc' => '在任务下发表评论/讨论。需要是项目成员',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
      'content' => 
      array (
        'type' => 'string',
        'desc' => '评论内容',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
      1 => 'content',
    ),
  ),
  23 => 
  array (
    'name' => 'list_task_comments',
    'desc' => '列出任务的所有评论',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
    ),
  ),
  24 => 
  array (
    'name' => 'add_checklist_item',
    'desc' => '给任务添加 checklist 子项(如验收标准)',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
      'content' => 
      array (
        'type' => 'string',
        'desc' => '子项内容',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
      1 => 'content',
    ),
  ),
  25 => 
  array (
    'name' => 'update_checklist_item',
    'desc' => '更新 checklist 子项(勾选完成/取消/改内容)',
    'params' => 
    array (
      'item_id' => 
      array (
        'type' => 'integer',
        'desc' => '子项 ID',
      ),
      'content' => 
      array (
        'type' => 'string',
        'desc' => '新内容(可选)',
      ),
      'is_done' => 
      array (
        'type' => 'boolean',
        'desc' => '是否完成',
      ),
    ),
    'required' => 
    array (
      0 => 'item_id',
    ),
  ),
  26 => 
  array (
    'name' => 'log_time',
    'desc' => '记录工时(谁在哪个任务上花了多少小时)',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
      'hours' => 
      array (
        'type' => 'number',
        'desc' => '工时(支持小数)',
      ),
      'work_date' => 
      array (
        'type' => 'string',
        'desc' => '工作日期 YYYY-MM-DD,默认今天',
      ),
      'note' => 
      array (
        'type' => 'string',
        'desc' => '说明',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
      1 => 'hours',
    ),
  ),
  27 => 
  array (
    'name' => 'list_task_attachments',
    'desc' => '列出任务的所有附件(返回 id / filename / size / uploaded_by)',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
    ),
  ),
  28 => 
  array (
    'name' => 'mark_task_blocked',
    'desc' => '将任务标记为阻塞(可指定请求谁协助)',
    'params' => 
    array (
      'task_id' => 
      array (
        'type' => 'integer',
        'desc' => '任务 ID',
      ),
      'block_reason' => 
      array (
        'type' => 'string',
        'desc' => '阻塞原因(必填)',
      ),
      'requested_assist_username' => 
      array (
        'type' => 'string',
        'desc' => '请求协助的用户名(可选)',
      ),
    ),
    'required' => 
    array (
      0 => 'task_id',
      1 => 'block_reason',
    ),
  ),
  29 => 
  array (
    'name' => 'list_milestones',
    'desc' => '列出项目所有里程碑',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
    ),
  ),
  30 => 
  array (
    'name' => 'add_milestone',
    'desc' => '添加里程碑。需要项目负责人或 admin 权限',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
      'name' => 
      array (
        'type' => 'string',
        'desc' => '里程碑名称',
      ),
      'description' => 
      array (
        'type' => 'string',
        'desc' => '描述(可选)',
      ),
      'due_date' => 
      array (
        'type' => 'string',
        'desc' => '截止日期 YYYY-MM-DD',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
      1 => 'name',
    ),
  ),
  31 => 
  array (
    'name' => 'delete_milestone',
    'desc' => '删除里程碑。需要项目负责人或 admin 权限',
    'params' => 
    array (
      'milestone_id' => 
      array (
        'type' => 'integer',
        'desc' => '里程碑 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'milestone_id',
    ),
  ),
  32 => 
  array (
    'name' => 'get_week_summary',
    'desc' => '获取本周任务汇总(本周创建/完成/进行中/阻塞/逾期),可用于生成汇报',
    'params' => 
    array (
    ),
    'required' => 
    array (
    ),
  ),
  33 => 
  array (
    'name' => 'get_project_dashboard',
    'desc' => '获取项目仪表盘完整数据(项目信息+任务统计+成员负载+里程碑+近期活动)',
    'params' => 
    array (
      'project_id' => 
      array (
        'type' => 'integer',
        'desc' => '项目 ID',
      ),
    ),
    'required' => 
    array (
      0 => 'project_id',
    ),
  ),
  34 => 
  array (
    'name' => 'search_tasks',
    'desc' => '按关键词搜索任务(标题/描述/评论)。限定在当前用户有权限看到的项目范围内',
    'params' => 
    array (
      'keyword' => 
      array (
        'type' => 'string',
        'desc' => '搜索关键词',
      ),
      'limit' => 
      array (
        'type' => 'integer',
        'desc' => '最多返回多少条(默认 20)',
      ),
    ),
    'required' => 
    array (
      0 => 'keyword',
    ),
  ),
);

    $out = [];
    foreach ($tools as $t) {
        $params = [];
        foreach ($t['params'] as $pname => $pinfo) {
            $item = ['type' => $pinfo['type'], 'description' => $pinfo['desc']];
            if (isset($pinfo['items'])) $item['items'] = $pinfo['items'];
            $params[$pname] = $item;
        }
        $req = empty($t['required']) ? [] : $t['required'];
        $propObj = empty($params) ? new \stdClass() : $params;
        $out[] = [
            'type' => 'function',
            'function' => [
                'name' => $t['name'],
                'description' => $t['desc'],
                'parameters' => ['type'=>'object', 'properties'=>$propObj, 'required'=>$req],
            ],
        ];
    }
    return $out;
}

function executeAiTool($name, $args, $user) {
    try {
        switch ($name) {
            case 'get_current_user':        return _ai_get_current_user($user);
            case 'list_users':              return _ai_list_users($args, $user);
            case 'list_my_projects':        return _ai_list_my_projects($user);
            case 'list_all_projects':       return _ai_list_all_projects($user);
            case 'get_project':             return _ai_get_project($args, $user);
            case 'create_project':          return _ai_create_project($args, $user);
            case 'update_project':          return _ai_update_project($args, $user);
            case 'archive_project':         return _ai_archive_project($args, $user);
            case 'delete_project':          return _ai_delete_project($args, $user);
            case 'list_project_members':    return _ai_list_project_members($args, $user);
            case 'add_project_member':      return _ai_add_project_member($args, $user);
            case 'remove_project_member':   return _ai_remove_project_member($args, $user);
            case 'list_my_tasks':           return _ai_list_my_tasks($args, $user);
            case 'list_project_tasks':      return _ai_list_project_tasks($args, $user);
            case 'get_task':                return _ai_get_task($args, $user);
            case 'create_task':             return _ai_create_task($args, $user);
            case 'update_task':             return _ai_update_task($args, $user);
            case 'update_task_status':      return _ai_update_task_status($args, $user);
            case 'reassign_task':           return _ai_reassign_task($args, $user);
            case 'delete_task':             return _ai_delete_task($args, $user);
            case 'add_task_dependency':     return _ai_add_task_dependency($args, $user);
            case 'remove_task_dependency':  return _ai_remove_task_dependency($args, $user);
            case 'add_task_comment':        return _ai_add_task_comment($args, $user);
            case 'list_task_comments':      return _ai_list_task_comments($args, $user);
            case 'add_checklist_item':      return _ai_add_checklist_item($args, $user);
            case 'update_checklist_item':   return _ai_update_checklist_item($args, $user);
            case 'log_time':                return _ai_log_time($args, $user);
            case 'list_task_attachments':   return _ai_list_task_attachments($args, $user);
            case 'mark_task_blocked':       return _ai_mark_task_blocked($args, $user);
            case 'list_milestones':         return _ai_list_milestones($args, $user);
            case 'add_milestone':           return _ai_add_milestone($args, $user);
            case 'delete_milestone':        return _ai_delete_milestone($args, $user);
            case 'get_week_summary':        return _ai_get_week_summary($user);
            case 'get_project_dashboard':   return _ai_get_project_dashboard($args, $user);
            case 'search_tasks':            return _ai_search_tasks($args, $user);
            default: return _ai_err('未知工具: ' . $name);
        }
    } catch (Exception $e) {
        return _ai_err('工具执行异常: ' . $e->getMessage());
    }
}

function _ai_ok($data) { return ['ok' => true, 'data' => $data, 'error' => null]; }
function _ai_err($msg) { return ['ok' => false, 'data' => null, 'error' => $msg]; }

require_once __DIR__ . '/ai_tools_impl.php';
