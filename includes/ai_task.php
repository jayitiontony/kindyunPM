<?php
/**
 * AI 任务状态(用于支持中止会话)
 * AI Task State - Cancel Support
 *
 * 用户点"发送" → 后端分配 task_id,同步执行 LLM 调用循环
 * 用户点"中止"  → 标记 task 为 cancelled,LLM 循环检查后提前退出
 *
 * 用文件存状态(无需引入新依赖,跨请求可用)
 */

if (!defined('AI_TASK_DIR'))    define('AI_TASK_DIR', __DIR__ . '/../database/ai_tasks');
if (!defined('AI_TASK_TTL'))    define('AI_TASK_TTL', 300);  // 任务状态文件 5 分钟后清理

/**
 * 创建任务
 * @return string task_id
 */
function aiTaskCreate($userId) {
    if (!is_dir(AI_TASK_DIR)) mkdir(AI_TASK_DIR, 0755, true);
    $taskId = 't_' . bin2hex(random_bytes(8));
    $state = [
        'task_id'   => $taskId,
        'user_id'   => (int)$userId,
        'status'    => 'running',  // running / done / cancelled / error
        'created_at'=> time(),
        'updated_at'=> time(),
        'cancelled' => false,
    ];
    aiTaskWrite($taskId, $state);
    return $taskId;
}

function aiTaskWrite($taskId, $state) {
    $file = AI_TASK_DIR . '/' . basename($taskId) . '.json';
    $state['updated_at'] = time();
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function aiTaskRead($taskId) {
    $file = AI_TASK_DIR . '/' . basename($taskId) . '.json';
    if (!file_exists($file)) return null;
    $raw = @file_get_contents($file);
    return $raw ? json_decode($raw, true) : null;
}

/**
 * 标记取消(返回是否成功标记)
 */
function aiTaskCancel($taskId, $userId) {
    $state = aiTaskRead($taskId);
    if (!$state || $state['user_id'] != $userId) return false;
    $state['cancelled'] = true;
    $state['status'] = 'cancelled';
    aiTaskWrite($taskId, $state);
    return true;
}

/**
 * 检查是否被取消(LLM 循环里每步调用)
 */
function aiTaskIsCancelled($taskId) {
    $state = aiTaskRead($taskId);
    return $state && !empty($state['cancelled']);
}

/**
 * 标记完成
 */
function aiTaskMarkDone($taskId, $extra = []) {
    $state = aiTaskRead($taskId) ?: ['task_id' => $taskId];
    $state['status'] = 'done';
    $state['cancelled'] = false;
    $state = array_merge($state, $extra);
    aiTaskWrite($taskId, $state);
}

/**
 * 标记错误
 */
function aiTaskMarkError($taskId, $error) {
    $state = aiTaskRead($taskId) ?: ['task_id' => $taskId];
    $state['status'] = 'error';
    $state['error'] = $error;
    aiTaskWrite($taskId, $state);
}

/**
 * 清理过期任务文件(简单 GC,随每次创建/查询顺带做)
 */
function aiTaskGc() {
    if (!is_dir(AI_TASK_DIR)) return;
    $files = glob(AI_TASK_DIR . '/t_*.json');
    if (!$files) return;
    $now = time();
    foreach ($files as $f) {
        $st = @json_decode(@file_get_contents($f), true);
        if (!$st || (isset($st['updated_at']) && $now - $st['updated_at'] > AI_TASK_TTL)) {
            @unlink($f);
        }
    }
}
