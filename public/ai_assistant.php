<?php
/**
 * AI 助手 - 聊天主页
 * AI Assistant Chat - 流式输出 + 文件上传
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/ai_task.php';

requireLogin();

$user = getCurrentUser();
$error = '';
$cancelResult = null;

$reqAction = $_REQUEST['action'] ?? '';

// 文件上传目录
if (!defined('AI_UPLOAD_DIR'))   define('AI_UPLOAD_DIR',   __DIR__ . '/../database/ai_attachments');
if (!defined('AI_UPLOAD_MAXSIZE')) define('AI_UPLOAD_MAXSIZE', 20 * 1024 * 1024); // 20MB

if (!is_dir(AI_UPLOAD_DIR)) @mkdir(AI_UPLOAD_DIR, 0755, true);

// 简易图片/文件提取 (纯文本文件读前面 2000 字节供 LLM 参考)
function _ai_extract_text($filePath) {
    if (!file_exists($filePath)) return '';
    $size = filesize($filePath);
    if ($size > 2 * 1024 * 1024) return '';  // 太大不读
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $textExts = ['txt','md','csv','log','json','xml','yml','yaml','ini','conf','sql','html','htm','css','js','ts','py','php','c','cpp','h','java','go','rs','sh','bat','env'];
    if (!in_array($ext, $textExts, true)) return '';
    $c = @file_get_contents($filePath, false, null, 0, 2000);
    if ($c === false) return '';
    // 去掉控制字符
    $c = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $c);
    return trim($c);
}

// ========================================================================
// action: upload - 上传附件
// ========================================================================
if ($reqAction === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $files = $_FILES['files'] ?? null;
    if (!$files || !isset($files['name']) || !is_array($files['name'])) {
        echo json_encode(['ok' => false, 'error' => '没有收到文件']);
        exit;
    }
    $uploaded = [];
    $errors = [];
    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        $err = $files['error'][$i];
        if ($err !== UPLOAD_ERR_OK) { $errors[] = "文件 #{$i} 上传错误 code={$err}"; continue; }
        if ($files['size'][$i] > AI_UPLOAD_MAXSIZE) { $errors[] = "文件 {$files['name'][$i]} 超过 20MB"; continue; }
        $original = basename($files['name'][$i]);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
        $dest = AI_UPLOAD_DIR . '/' . $stored;
        if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
            $errors[] = "文件 {$original} 移动失败"; continue;
        }
        $extracted = _ai_extract_text($dest);
        $uploaded[] = [
            'original_name' => $original,
            'filename'      => $stored,
            'file_size'     => (int)$files['size'][$i],
            'mime_type'     => $files['type'][$i] ?? '',
            'extracted_text'=> $extracted,
            'url'           => 'download_ai_attachment.php?file=' . urlencode($stored),
        ];
    }
    echo json_encode(['ok' => true, 'files' => $uploaded, 'errors' => $errors]);
    exit;
}

// ========================================================================
// action: stream - SSE 流式 AI 调用
// ========================================================================
if ($reqAction === 'stream' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = trim($_POST['message'] ?? '');
    $attachmentsJson = $_POST['attachments'] ?? '[]';
    $attachments = json_decode($attachmentsJson, true) ?: [];

    // 关闭任何已有缓冲, 启 SSE 头
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    @ob_implicit_flush(true);

    function sse_send($eventType, $data) {
        echo "event: " . $eventType . "\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        @flush();
    }

    sse_send('ready', ['ok' => true]);

    if ($msg === '') {
        sse_send('error', ['message' => '请输入消息']);
        sse_send('done', ['ok' => false]);
        exit;
    }
    if (empty($user)) {
        sse_send('error', ['message' => '未登录']);
        sse_send('done', ['ok' => false]);
        exit;
    }

    // 创建 cancel 任务
    $taskId = aiTaskCreate($user['id']);
    sse_send('task', ['task_id' => $taskId]);

    // 显式设 3 分钟超时(覆盖 php.ini 默认 30s)
    @ini_set('max_execution_time', '180');
    @ini_set('max_input_time', '180');
    @ini_set('default_socket_timeout', '180');
    @ini_set('output_buffering', '0');
    @set_time_limit(180);

    // 用户消息预览立即推送(让前端先显示 user bubble)
    sse_send('user_message', ['message' => $msg, 'attachments' => $attachments]);

    // 跑流式主循环
    $lastEventAt = time();
    $result = runAssistantLoopStream($user, $msg, [
        'task_id'     => $taskId,
        'attachments' => $attachments,
    ], function($eventType, $data) use ($taskId, &$lastEventAt) {
        // 中止检查
        if ($eventType !== 'error' && $eventType !== 'done' && aiTaskIsCancelled($taskId)) {
            sse_send('cancelled', ['reply_partial' => $data['content'] ?? '']);
            throw new Exception('cancelled');   // 抛异常跳出 runAssistantLoopStream
        }
        // Heartbeat: 距上次事件 > 15s,推一个 heartbeat 防前端/代理 idle timeout
        $now = time();
        if ($now - $lastEventAt >= 15) {
            sse_send('heartbeat', ['ts' => $now]);
        }
        $lastEventAt = $now;
        sse_send($eventType, $data);
    });

    if (!empty($result['cancelled'])) {
        sse_send('cancelled', ['task_id' => $taskId, 'reply_partial' => $result['reply'] ?? '']);
    } elseif (!empty($result['error'])) {
        sse_send('error', ['message' => $result['error']]);
    }
    sse_send('done', ['ok' => !empty($result['ok']), 'task_id' => $taskId, 'reply_len' => strlen($result['reply'] ?? '')]);
    exit;
}

// ========================================================================
// action: cancel - 中止
// ========================================================================
if ($reqAction === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tid = $_POST['task_id'] ?? $_GET['task_id'] ?? '';
    if (aiTaskCancel($tid, $user['id'])) {
        $cancelResult = ['ok' => true, 'message' => '已请求中止'];
    } else {
        $cancelResult = ['ok' => false, 'message' => '任务不存在或无权操作'];
    }
}

// ========================================================================
// action: poll
// ========================================================================
if ($reqAction === 'poll') {
    header('Content-Type: application/json');
    $tid = $_GET['task_id'] ?? '';
    $state = aiTaskRead($tid);
    if (!$state) {
        echo json_encode(['ok' => false, 'error' => 'task_not_found']);
    } else {
        echo json_encode(['ok' => true, 'task_id' => $tid, 'status' => $state['status'] ?? 'unknown', 'cancelled' => !empty($state['cancelled'])]);
    }
    exit;
}

// ========================================================================
// action: clear
// ========================================================================
if ($reqAction === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    clearChatHistory($user['id']);
    logOperation($user['id'], 'delete', 'ai_chat_history', null, ['action' => 'clear_all']);
    $_SESSION['success_message'] = '对话已清空';
    redirect('ai_assistant.php');
}

if (isset($_SESSION['success_message'])) {
    $successMsg = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

aiTaskGc();
$history = getChatHistory($user['id'], 100);
$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI 助手 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <style>
        .ai-chat-wrap { background:#fff; border:1px solid var(--color-border); border-radius:var(--radius-lg); box-shadow:var(--shadow); max-width:960px; margin:0 auto; }
        .ai-chat-header { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1px solid var(--color-border); background:linear-gradient(135deg,#f0f9ff 0%,#e0f2fe 100%); border-radius:var(--radius-lg) var(--radius-lg) 0 0; }
        .ai-chat-header h3 { margin:0; border:none; padding:0; color:var(--color-text); }
        .ai-chat-header .model-info { font-size:12px; color:var(--color-text-mute); }
        .ai-chat-body { min-height:400px; max-height:calc(100vh - 400px); overflow-y:auto; padding:20px; background:#f9fafb; }
        .ai-msg { display:flex; gap:10px; margin-bottom:16px; align-items:flex-start; }
        .ai-msg.user { flex-direction:row-reverse; }
        .ai-avatar { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:600; color:#fff; flex-shrink:0; }
        .ai-msg.user .ai-avatar   { background:var(--color-primary); }
        .ai-msg.assistant .ai-avatar { background:#7c3aed; }
        .ai-msg.tool .ai-avatar    { background:#f59e0b; }
        .ai-msg.system .ai-avatar  { background:var(--color-muted); }
        .ai-bubble { max-width:75%; background:#fff; border:1px solid var(--color-border); border-radius:12px; padding:10px 14px; font-size:14px; line-height:1.6; color:var(--color-text); box-shadow:var(--shadow-sm); white-space:pre-wrap; word-wrap:break-word; }
        .ai-msg.user .ai-bubble { background:var(--color-primary); color:#fff; border-color:var(--color-primary-d); }
        .ai-msg.tool .ai-bubble { background:#fffbeb; border-color:#fde68a; font-size:12px; }
        .ai-bubble pre { background:#f3f4f6; padding:8px; border-radius:4px; font-size:12px; overflow-x:auto; white-space:pre-wrap; }
        .ai-bubble code { background:#f3f4f6; padding:1px 5px; border-radius:3px; font-size:12px; }
        .ai-msg.user .ai-bubble code { background:rgba(255,255,255,0.2); }
        .ai-tool-calls { background:#f0f9ff; border:1px solid #bae6fd; border-radius:var(--radius); padding:10px 14px; margin-top:8px; font-size:12px; }
        .ai-tool-calls .label { font-weight:600; color:#0369a1; margin-bottom:4px; }
        .ai-tool-calls .item { color:#075985; margin:2px 0; }
        .ai-tool-calls .item.error { color:#b91c1c; }
        .ai-tool-calls .item .tool-name { font-family:monospace; background:#fff; padding:1px 4px; border-radius:3px; }
        .ai-input-bar { border-top:1px solid var(--color-border); padding:12px 20px; background:#fff; border-radius:0 0 var(--radius-lg) var(--radius-lg); }
        .ai-input-bar form { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
        .ai-input-bar textarea { flex:1; padding:10px 12px; border:1px solid var(--color-border-d); border-radius:var(--radius); font-family:inherit; font-size:14px; resize:vertical; min-height:60px; max-height:200px; }
        .ai-input-bar textarea:focus { outline:none; border-color:var(--color-primary); box-shadow:0 0 0 3px var(--color-primary-l); }
        .ai-input-actions { display:flex; gap:8px; }
        .ai-attachments-preview { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px; }
        .ai-attachments-preview:empty { display:none; }
        .ai-att-chip { background:#f0f9ff; border:1px solid #bae6fd; border-radius:14px; padding:4px 10px; font-size:12px; display:inline-flex; gap:6px; align-items:center; }
        .ai-att-chip .remove { cursor:pointer; color:#999; }
        .ai-att-chip .remove:hover { color:#dc2626; }
        .ai-cancel-btn { background:#dc2626 !important; color:#fff !important; border-color:#dc2626 !important; animation: pulse 1.5s infinite; }
        .ai-cancel-btn:hover { background:#b91c1c !important; }
        @keyframes pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); } 50% { box-shadow: 0 0 0 8px rgba(220, 38, 38, 0); } }
        .ai-cancel-banner { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:var(--radius); margin-bottom:12px; font-size:13px; text-align:center; }
        .quick-prompts { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
        .quick-prompts button { padding:6px 12px; font-size:12px; background:#fff; border:1px solid var(--color-border-d); border-radius:var(--radius); color:var(--color-text-soft); cursor:pointer; }
        .quick-prompts button:hover { background:var(--color-primary-l); color:var(--color-primary); border-color:var(--color-primary); }
        .ai-thinking-dots::after { content:'...'; display:inline-block; width:1em; text-align:left; animation: dots 1.2s steps(3,end) infinite; }
        @keyframes dots { 0%{content:'.';} 33%{content:'..';} 66%{content:'...';} }
        .ai-cursor::after { content:'▌'; animation: blink 1s infinite; opacity:0.5; }
        @keyframes blink { 50% { opacity: 0; } }
        .ai-streaming-bubble .cursor { display:inline-block; }
        .ai-msg-meta { font-size:11px; color:var(--color-text-mute); margin-bottom:4px; }
        .ai-msg.user .ai-msg-meta { color:rgba(255,255,255,0.7); text-align:right; }
        .ai-attach-icon { color:#0ea5e9; }
    </style>
</head>
<body>
<?php
$settings = getAiSettings($user['id']);
echo renderHeader('🤖 AI 助手', $user, $unreadNotifCount, 'ai');
?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($successMsg)) echo showSuccess($successMsg); ?>
    <?php if (!empty($cancelResult['ok'])): ?>
        <div class="ai-cancel-banner">⏹️ <?php echo htmlspecialchars($cancelResult['message']); ?></div>
    <?php endif; ?>

    <?php if (empty($settings['enabled']) || empty($settings['api_base'])): ?>
        <div style="background:#fff3cd; border:1px solid #ffeaa7; color:#856404; padding:14px 18px; border-radius:8px; margin-bottom:16px;">
            ⚠️ AI 助手未启用或未配置。👉 <a href="ai_settings.php">点此配置 API / 模型 / 启用开关</a>
        </div>
    <?php endif; ?>

    <div class="ai-chat-wrap">
        <div class="ai-chat-header">
            <div>
                <h3>🤖 AI 项目管理助手 <small style="font-weight:normal; font-size:12px; color:#16a34a;">流式输出 ✨</small></h3>
                <div class="model-info">
                    模型: <strong><?php echo htmlspecialchars($settings['model'] ?: '(未配置)'); ?></strong> ·
                    状态: <span style="color:<?php echo $settings['enabled'] ? '#16a34a' : '#dc2626'; ?>"><?php echo $settings['enabled'] ? '● 已启用' : '● 未启用'; ?></span>
                </div>
            </div>
            <form method="POST" style="display:inline;" onsubmit="return confirm('清空所有对话?')">
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="btn btn-danger btn-sm">🗑️ 清空对话</button>
            </form>
        </div>

        <div class="ai-chat-body" id="aiChatBody">
            <?php
            $hasHistory = false;
            foreach ($history as $r) {
                if (in_array($r['role'], ['user', 'assistant', 'tool', 'system'])) {
                    $hasHistory = true;
                    break;
                }
            }
            ?>
            <?php if (!$hasHistory): ?>
                <div class="ai-empty" style="text-align:center; padding:60px 20px; color:var(--color-text-mute);">
                    <div style="font-size:48px;">💬</div>
                    <h3>开始和 AI 助手对话</h3>
                    <p>让 AI 帮你规划任务、分解工作、生成汇报。支持流式输出和文件上传 📎</p>
                    <div class="quick-prompts" style="justify-content:center; margin-top:20px;">
                        <button onclick="document.getElementById('aiInput').value='本周我要做哪些任务?给我个清单';">📅 本周任务清单</button>
                        <button onclick="document.getElementById('aiInput').value='帮我看看有没有逾期任务,有的话列出来';">⚠️ 查看逾期</button>
                        <button onclick="document.getElementById('aiInput').value='把"完成用户登录模块"这个任务分解成3-5个子任务,创建在项目#1里';">🧩 分解任务</button>
                        <button onclick="document.getElementById('aiInput').value='生成本周工作汇报,包含完成、进行中、阻塞、逾期';">📝 生成汇报</button>
                    </div>
                </div>
            <?php else: ?>
                <?php
                $currentAssistantToolCalls = [];
                foreach ($history as $r):
                    $role = $r['role'];
                    $content = $r['content'] ?? '';
                    $name = $r['name'] ?? '';
                    $tc = !empty($r['tool_calls']) ? json_decode($r['tool_calls'], true) : null;
                    $attachments = !empty($r['attachments']) ? json_decode($r['attachments'], true) : null;

                    if ($role === 'assistant' && $tc) {
                        $currentAssistantToolCalls = $tc;
                    }
                    if ($role === 'tool') {
                        $data = json_decode($content, true);
                        $isOk = is_array($data) && ($data['ok'] ?? false);
                        $summary = $isOk ? '✅ 调用成功' : '❌ 失败: ' . ($data['error'] ?? '未知错误');
                        ?>
                        <div class="ai-msg tool">
                            <div class="ai-avatar">🔧</div>
                            <div style="max-width:75%;">
                                <div class="ai-msg-meta">工具: <strong><?php echo htmlspecialchars($name); ?></strong></div>
                                <div class="ai-bubble">
                                    <pre><?php echo htmlspecialchars($summary); ?><?php if ($isOk && !empty($data['data'])): ?>&#10;<?php echo htmlspecialchars(mb_substr(json_encode($data['data'], JSON_UNESCAPED_UNICODE), 0, 300)); ?><?php endif; ?></pre>
                                </div>
                            </div>
                        </div>
                        <?php
                    } elseif ($role === 'user' || $role === 'assistant' || $role === 'system') {
                        $showContent = ($content !== '' && $content !== null) ? $content : ($tc ? '(已发起工具调用)' : '');
                        $avatar = $role === 'user' ? htmlspecialchars(mb_substr($user['name'] ?: $user['username'], 0, 1)) : ($role === 'assistant' ? '🤖' : '⚙️');
                        ?>
                        <div class="ai-msg <?php echo htmlspecialchars($role); ?>">
                            <div class="ai-avatar"><?php echo $avatar; ?></div>
                            <div style="max-width:75%;">
                                <div class="ai-msg-meta"><?php echo $role === 'user' ? '我' : ($role === 'assistant' ? 'AI 助手' : '系统'); ?> · <?php echo htmlspecialchars($r['created_at']); ?></div>
                                <?php if ($attachments): ?>
                                    <div style="font-size:11px; color:#888; margin-bottom:4px;">
                                        <?php foreach ($attachments as $a): ?>
                                            <span class="ai-attach-icon">📎</span> <?php echo htmlspecialchars($a['original_name'] ?? ''); ?>&nbsp;
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="ai-bubble"><?php echo nl2br(htmlspecialchars($showContent)); ?></div>
                                <?php if ($role === 'assistant' && $currentAssistantToolCalls): ?>
                                    <div class="ai-tool-calls">
                                        <div class="label">🔧 本轮调用了 <?php echo count($currentAssistantToolCalls); ?> 个工具:</div>
                                        <?php foreach ($currentAssistantToolCalls as $tcc): ?>
                                            <div class="item">
                                                <span class="tool-name"><?php echo htmlspecialchars($tcc['function']['name'] ?? '?'); ?></span>
                                                <?php
                                                $args = $tcc['function']['arguments'] ?? '';
                                                if (is_array($args)) $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                                                $argsStr = mb_substr((string)$args, 0, 100);
                                                ?>
                                                <small style="color:#6b7280;">(<?php echo htmlspecialchars($argsStr); ?><?php echo mb_strlen($argsStr) >= 100 ? '...' : ''; ?>)</small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php $currentAssistantToolCalls = []; endif; ?>
                            </div>
                        </div>
                        <?php
                    }
                endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="ai-input-bar">
            <!-- 附件预览 -->
            <div class="ai-attachments-preview" id="aiAttachPreview"></div>
            <form id="aiForm" enctype="multipart/form-data">
                <textarea name="message" id="aiInput" placeholder="输入消息,Enter 发送 / Shift+Enter 换行&#10;支持上传文件 (📎 图标) ,常见文本格式会被自动读取内容" required></textarea>
                <div class="ai-input-actions">
                    <label class="btn btn-sm" style="background:#fff; border:1px solid var(--color-border-d); cursor:pointer;" title="上传附件">
                        📎
                        <input type="file" id="aiFileInput" multiple style="display:none;">
                    </label>
                    <button type="submit" id="aiSendBtn" class="btn btn-primary">📤 发送</button>
                    <button type="button" id="aiCancelBtn" class="btn ai-cancel-btn" style="display:none;">⏹ 中止</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var form       = document.getElementById('aiForm');
    var input      = document.getElementById('aiInput');
    var fileInput  = document.getElementById('aiFileInput');
    var attachPrev = document.getElementById('aiAttachPreview');
    var sendBtn    = document.getElementById('aiSendBtn');
    var cancelBtn  = document.getElementById('aiCancelBtn');
    var chatBody   = document.getElementById('aiChatBody');
    var currentTaskId = null;
    var attachments = [];   // { original_name, filename, file_size, url, extracted_text }

    function scrollBottom() { if (chatBody) chatBody.scrollTop = chatBody.scrollHeight; }
    scrollBottom();

    // ===== 附件管理 =====
    function renderAttachments() {
        attachPrev.innerHTML = '';
        attachments.forEach(function(a, i) {
            var chip = document.createElement('span');
            chip.className = 'ai-att-chip';
            chip.innerHTML = '📎 ' + escapeHtml(a.original_name) + ' <span class="remove" data-idx="' + i + '">×</span>';
            attachPrev.appendChild(chip);
        });
        attachPrev.querySelectorAll('.remove').forEach(function(el) {
            el.addEventListener('click', function() {
                var idx = parseInt(el.getAttribute('data-idx'));
                attachments.splice(idx, 1);
                renderAttachments();
            });
        });
    }

    fileInput.addEventListener('change', function() {
        if (!fileInput.files.length) return;
        var fd = new FormData();
        for (var i = 0; i < fileInput.files.length; i++) {
            fd.append('files[]', fileInput.files[i]);
        }
        fd.append('action', 'upload');
        sendBtn.disabled = true; sendBtn.textContent = '📤 上传中...';
        fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok && data.files) {
                    data.files.forEach(function(f) { attachments.push(f); });
                    renderAttachments();
                } else {
                    alert('上传失败: ' + (data.error || '未知错误'));
                }
                fileInput.value = '';
            })
            .catch(function(err) { alert('上传出错: ' + err.message); })
            .finally(function() {
                sendBtn.disabled = false; sendBtn.textContent = '📤 发送';
            });
    });

    // ===== 发送(走 SSE) =====
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = input.value.trim();
        if (!msg) return;

        // 1) 立即插入 user 消息(乐观)
        var userBubble = document.createElement('div');
        userBubble.className = 'ai-msg user';
        var attachHtml = '';
        if (attachments.length) {
            attachHtml = '<div style="font-size:11px; color:rgba(255,255,255,0.85); margin-bottom:4px;">';
            attachments.forEach(function(a) { attachHtml += '📎 ' + escapeHtml(a.original_name) + ' &nbsp;'; });
            attachHtml += '</div>';
        }
        userBubble.innerHTML = '<div class="ai-avatar">' + escapeHtml(msg.substr(0,1)) + '</div><div style="max-width:75%;"><div class="ai-msg-meta">我 · 刚刚</div>' + attachHtml + '<div class="ai-bubble">' + escapeHtml(msg).replace(/\n/g, '<br>') + '</div></div>';
        chatBody.appendChild(userBubble);
        scrollBottom();
        var savedMsg = msg;
        var savedAtts = attachments.slice();
        input.value = '';
        attachments = [];
        renderAttachments();

        // 2) 进入 streaming 模式
        enterStreamingMode();

        // 3) 准备 SSE 用的 assistant 消息容器(用于流式追加)
        var asstBubble = null;
        var asstContent = '';
        var asstToolCallsBlock = null;
        var currentToolEls = {};

        var fd = new FormData();
        fd.append('action', 'stream');
        fd.append('message', savedMsg);
        fd.append('attachments', JSON.stringify(savedAtts));

        fetch(window.location.pathname, {
            method: 'POST', body: fd, credentials: 'same-origin',
            // 不支持 abort (EventSource),但用户可以点 cancel
        }).then(function(resp) {
            if (!resp.ok || !resp.body) throw new Error('HTTP ' + resp.status);
            var reader = resp.body.getReader();
            var decoder = new TextDecoder('utf-8');
            var buffer = '';

            function readChunk() {
                return reader.read().then(function(r) {
                    if (r.done) return;
                    buffer += decoder.decode(r.value, { stream: true });
                    // SSE 事件以 \n\n 分隔
                    var idx;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        var raw = buffer.slice(0, idx);
                        buffer = buffer.slice(idx + 2);
                        var lines = raw.split('\n');
                        var event = 'message', data = '';
                        for (var i = 0; i < lines.length; i++) {
                            var l = lines[i];
                            if (l.indexOf('event:') === 0) event = l.slice(6).trim();
                            else if (l.indexOf('data:') === 0) data += l.slice(5).trim();
                        }
                        if (data) handleEvent(event, data);
                    }
                    return readChunk();
                });
            }
            return readChunk();
        }).catch(function(err) {
            if (err.name !== 'AbortError') {
                var banner = document.createElement('div');
                banner.className = 'ai-cancel-banner';
                banner.textContent = '❌ 网络错误: ' + err.message;
                chatBody.appendChild(banner);
                scrollBottom();
            }
            exitStreamingMode();
        });

        function handleEvent(event, dataStr) {
            var data;
            try { data = JSON.parse(dataStr); } catch (e) { return; }
            switch (event) {
                case 'ready':
                    break;
                case 'task':
                    currentTaskId = data.task_id;
                    break;
                case 'user_message':
                    // server already echoed, no-op
                    break;
                case 'start':
                    // 插入 assistant 容器 (还没内容)
                    if (!asstBubble) {
                        asstBubble = document.createElement('div');
                        asstBubble.className = 'ai-msg assistant ai-streaming-bubble';
                        asstBubble.innerHTML = '<div class="ai-avatar">🤖</div><div style="max-width:75%;"><div class="ai-msg-meta">AI 助手 · 思考中...</div><div class="ai-bubble"><span class="cursor">▌</span></div></div>';
                        chatBody.appendChild(asstBubble);
                        scrollBottom();
                    }
                    break;
                case 'content_delta':
                    if (!asstBubble) {
                        asstBubble = document.createElement('div');
                        asstBubble.className = 'ai-msg assistant ai-streaming-bubble';
                        asstBubble.innerHTML = '<div class="ai-avatar">🤖</div><div style="max-width:75%;"><div class="ai-msg-meta">AI 助手</div><div class="ai-bubble"></div></div>';
                        chatBody.appendChild(asstBubble);
                    }
                    asstContent = data.content || (asstContent + (data.delta || ''));
                    var meta = asstBubble.querySelector('.ai-msg-meta');
                    if (meta) meta.textContent = 'AI 助手 · 生成中...';
                    var bub = asstBubble.querySelector('.ai-bubble');
                    if (bub) {
                        bub.innerHTML = escapeHtml(asstContent) + '<span class="cursor">▌</span>';
                    }
                    scrollBottom();
                    break;
                case 'tool_calls':
                    // 在 assistant bubble 后面插入工具调用说明
                    var wrap = document.createElement('div');
                    wrap.className = 'ai-tool-calls';
                    var label = document.createElement('div');
                    label.className = 'label';
                    label.textContent = '🔧 调用了 ' + (data.tool_calls || []).length + ' 个工具';
                    wrap.appendChild(label);
                    (data.tool_calls || []).forEach(function(tc) {
                        var item = document.createElement('div');
                        item.className = 'item';
                        var name = (tc.function && tc.function.name) || '?';
                        var argsStr = (tc.function && tc.function.arguments) || '';
                        if (argsStr.length > 100) argsStr = argsStr.slice(0, 100) + '...';
                        item.innerHTML = '<span class="tool-name">' + escapeHtml(name) + '</span> <small style="color:#6b7280;">' + escapeHtml(argsStr) + '</small>';
                        wrap.appendChild(item);
                        currentToolEls[tc.id || name] = item;
                    });
                    asstBubble.querySelector('div[style*="max-width"]').appendChild(wrap);
                    scrollBottom();
                    break;
                case 'tool_result':
                    if (data.tool && currentToolEls[data.tool] || (data.args && data.args.id && currentToolEls[data.args.id])) {
                        // 标记 ok/err
                    }
                    // 插入 tool result bubble
                    var tb = document.createElement('div');
                    tb.className = 'ai-msg tool';
                    var summary = data.ok ? '✅ 调用成功' : '❌ 失败: ' + (data.error || '未知错误');
                    var dataJson = data.ok && data.data ? '&#10;' + escapeHtml(JSON.stringify(data.data).slice(0, 300)) : '';
                    tb.innerHTML = '<div class="ai-avatar">🔧</div><div style="max-width:75%;"><div class="ai-msg-meta">工具: <strong>' + escapeHtml(data.tool) + '</strong></div><div class="ai-bubble"><pre>' + summary + dataJson + '</pre></div></div>';
                    chatBody.appendChild(tb);
                    scrollBottom();
                    break;
                case 'final':
                    if (asstBubble) {
                        var m = asstBubble.querySelector('.ai-msg-meta');
                        if (m) m.textContent = 'AI 助手 · 已完成';
                        var b = asstBubble.querySelector('.ai-bubble');
                        if (b) b.innerHTML = escapeHtml(asstContent);
                        asstBubble.classList.remove('ai-streaming-bubble');
                    }
                    break;
                case 'cancelled':
                    if (asstBubble) {
                        var m = asstBubble.querySelector('.ai-msg-meta');
                        if (m) m.textContent = 'AI 助手 · 已中止';
                        var b = asstBubble.querySelector('.ai-bubble');
                        if (b && asstContent) b.innerHTML = escapeHtml(asstContent) + '<br><small style="color:#999;">⏹️ 已中止</small>';
                    }
                    var banner = document.createElement('div');
                    banner.className = 'ai-cancel-banner';
                    banner.textContent = '⏹️ 会话已中止';
                    chatBody.appendChild(banner);
                    scrollBottom();
                    break;
                case 'error':
                    var eb = document.createElement('div');
                    eb.className = 'ai-cancel-banner';
                    eb.textContent = '❌ ' + (data.message || '错误');
                    chatBody.appendChild(eb);
                    scrollBottom();
                    break;
                case 'done':
                    exitStreamingMode();
                    currentTaskId = null;
                    scrollBottom();
                    break;
            }
        }
    });

    function enterStreamingMode() {
        sendBtn.disabled = true;
        sendBtn.textContent = '⏳ 思考中...';
        cancelBtn.style.display = 'inline-flex';
    }
    function exitStreamingMode() {
        sendBtn.disabled = false;
        sendBtn.textContent = '📤 发送';
        cancelBtn.style.display = 'none';
    }

    cancelBtn.addEventListener('click', function() {
        if (!currentTaskId) return;
        var fd = new FormData();
        fd.append('action', 'cancel');
        fd.append('task_id', currentTaskId);
        fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .catch(function() {});
    });

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s == null ? '' : s;
        return div.innerHTML;
    }

    // Enter 发送 / Shift+Enter 换行
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    // 拖拽上传
    ['dragover','dragenter'].forEach(function(ev) {
        form.addEventListener(ev, function(e) { e.preventDefault(); });
    });
    form.addEventListener('drop', function(e) {
        e.preventDefault();
        if (!e.dataTransfer.files.length) return;
        fileInput.files = e.dataTransfer.files;
        fileInput.dispatchEvent(new Event('change'));
    });
})();
</script>

<?php echo renderFooter(); ?>
</body>
</html>
