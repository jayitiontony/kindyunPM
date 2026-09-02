<?php
/**
 * 个人中心设置
 * Profile Settings
 *
 * 所有用户均可访问，设置个人信息和AI助手设置
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/ai_providers.php';

requireLogin();

$user = getCurrentUser();
$error = '';
$success = '';

$tab = $_GET['tab'] ?? 'info';

// 处理个人信息更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $gender = $_POST['gender'] ?? 'male';
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $expertise = trim($_POST['expertise'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password_confirm = trim($_POST['password_confirm'] ?? '');

    if (!empty($password)) {
        if ($password !== $password_confirm) {
            $error = '两次输入的密码不一致';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
        }
    } else {
        $password_hash = null;
    }

    if (empty($error)) {
        try {
            $currentUser = getUserById($user['id']);
            if (!$currentUser) {
                throw new Exception('用户不存在');
            }

            // updateUser($id, $username, $name, $gender, $phone, $email, $role_id, $expertise, $passwordHash)
            updateUser($user['id'], $currentUser['username'], $name, $gender, $phone, $email, $currentUser['role_id'], $expertise, $password_hash);
            
            logOperation($user['id'], 'update', 'profile', $user['id'], [
                'name' => $name, 'gender' => $gender, 'phone' => $phone, 'email' => $email, 'expertise' => $expertise,
                'password_changed' => !empty($password_hash)
            ]);

            // Update session
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['gender'] = $gender;
            $_SESSION['user']['phone'] = $phone;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['expertise'] = $expertise;

            $success = '个人信息已更新';
            $user = getCurrentUser(); // refresh
        } catch (Exception $e) {
            $error = '更新失败: ' . $e->getMessage();
        }
    }
}

// 处理 AI 设置保存
$aiSettingsError = '';
$aiSettingsSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ai_settings'])) {
    $action = 'save';
    try {
        $data = [
            'api_base'      => trim($_POST['api_base'] ?? ''),
            'api_key'       => trim($_POST['api_key'] ?? ''),
            'model'         => trim($_POST['model'] ?? ''),
            'temperature'   => (float)($_POST['temperature'] ?? 0.7),
            'max_tokens'    => (int)($_POST['max_tokens'] ?? 2000),
            'system_prompt' => trim($_POST['system_prompt'] ?? ''),
            'enabled'       => isset($_POST['enabled']) ? 1 : 0,
        ];
        if ($data['api_base'] === '') throw new Exception('API Base 必填');
        if ($data['model'] === '') throw new Exception('模型名称必填');
        if ($data['temperature'] < 0 || $data['temperature'] > 2) throw new Exception('Temperature 必须在 0~2 之间');
        if ($data['max_tokens'] < 100 || $data['max_tokens'] > 32000) throw new Exception('Max Tokens 必须在 100~32000');
        saveAiSettings($user['id'], $data);
        logOperation($user['id'], 'update', 'ai_settings', null, [
            'model' => $data['model'], 'api_base' => $data['api_base'],
        ]);
        $aiSettingsSuccess = 'AI 助手参数已保存';
    } catch (Exception $e) {
        $aiSettingsError = $e->getMessage();
    }
}

$settings = getAiSettings($user['id']);
$currentProvider = detectProviderFromApiBase($settings['api_base']);
$providers = getAiProviders();

// 把当前 model 在所属 provider 的清单中是否存在标记一下
$modelInList = false;
if (isset($providers[$currentProvider]['models'][$settings['model']])) {
    $modelInList = true;
}

$unreadNotifCount = getUnreadNotificationCount($user['id']);

function renderProfileTabs($currentTab) {
    $tabs = [
        'info' => ['label' => '👤 个人信息', 'url' => 'profile.php?tab=info'],
        'ai'   => ['label' => '🤖 AI 助手设置', 'url' => 'profile.php?tab=ai'],
    ];
    $html = '<div class="tab-nav">';
    foreach ($tabs as $key => $t) {
        $cls = ($currentTab === $key) ? ' class="active"' : '';
        $html .= '<a href="' . htmlspecialchars($t['url']) . '"' . $cls . '>' . htmlspecialchars($t['label']) . '</a>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人中心设置 - PM 系统</title>
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
        .provider-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }
        .provider-card {
            border: 2px solid var(--color-border, #ddd);
            border-radius: var(--radius, 4px);
            padding: 12px 14px;
            cursor: pointer;
            background: #fff;
            transition: all 0.15s;
        }
        .provider-card:hover { border-color: #3498db; background: #f9fafb; }
        .provider-card.active { border-color: #3498db; background: #e7f3ff; }
        .provider-card .name { font-weight: 600; }
        .provider-card .url { font-size: 11px; color: #666; font-family: monospace; margin-top: 2px; word-break: break-all; }
        .provider-card .note { font-size: 11px; color: #888; margin-top: 6px; }
        .model-hint {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #075985;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 12px;
            margin-top: 6px;
        }
    </style>
</head>
<body>
<?php echo renderHeader('👤 个人中心', $user, $unreadNotifCount, 'profile'); ?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>
    <?php if (!empty($aiSettingsError)) echo showError($aiSettingsError); ?>
    <?php if (!empty($aiSettingsSuccess)) echo showSuccess($aiSettingsSuccess); ?>

    <?php echo renderProfileTabs($tab); ?>

    <!-- 个人信息设置 -->
    <div id="info-tab" class="tab-content <?php echo $tab === 'info' ? 'active' : ''; ?>">
        <div class="card">
            <h3>👤 个人信息设置</h3>
            <p style="color:#666; font-size:13px;">您可以修改姓名、性别、电话、邮箱、专长以及登录密码。用户名不可修改。</p>
            <form method="POST">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled style="background:#f5f5f5; color:#888;">
                    <small style="color:#999;">用户名不可修改</small>
                </div>

                <div class="form-group">
                    <label for="name">姓名 <span style="color:#e74c3c;">*</span></label>
                    <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($user['name'] ?: ''); ?>">
                </div>

                <div class="form-row" style="display:flex; gap:15px; margin-bottom:15px;">
                    <div class="form-group" style="flex:1;">
                        <label for="gender">性别</label>
                        <select id="gender" name="gender">
                            <option value="male" <?php echo ($user['gender'] ?? 'male') === 'male' ? 'selected' : ''; ?>>男</option>
                            <option value="female" <?php echo ($user['gender'] ?? 'male') === 'female' ? 'selected' : ''; ?>>女</option>
                            <option value="other" <?php echo ($user['gender'] ?? 'male') === 'other' ? 'selected' : ''; ?>>其他</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="phone">电话号码</label>
                        <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?: ''); ?>">
                    </div>
                </div>

                <div class="form-row" style="display:flex; gap:15px; margin-bottom:15px;">
                    <div class="form-group" style="flex:1;">
                        <label for="email">邮箱地址</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?: ''); ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="expertise">专长</label>
                        <input type="text" id="expertise" name="expertise" value="<?php echo htmlspecialchars($user['expertise'] ?: ''); ?>" placeholder="如：PHP开发、硬件设计">
                    </div>
                </div>

                <hr style="margin:20px 0; border:1px solid #eee;">
                <h4>🔒 修改密码（可选）</h4>
                <p style="color:#666; font-size:13px;">如不需修改密码，请留空。</p>

                <div class="form-row" style="display:flex; gap:15px; margin-bottom:15px;">
                    <div class="form-group" style="flex:1;">
                        <label for="password">新密码</label>
                        <input type="password" id="password" name="password" placeholder="留空则不修改密码">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="password_confirm">确认新密码</label>
                        <input type="password" id="password_confirm" name="password_confirm" placeholder="再次输入新密码">
                    </div>
                </div>

                <div class="form-group" style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-primary">💾 保存个人信息</button>
                </div>
            </form>
        </div>
    </div>

    <!-- AI 助手设置 -->
    <div id="ai-tab" class="tab-content <?php echo $tab === 'ai' ? 'active' : ''; ?>">
        <form method="POST" id="aiSettingsForm">
            <input type="hidden" name="save_ai_settings" value="1">

            <!-- 第 1 步:选服务提供方 -->
            <div class="card">
                <h3>📌 第 1 步:选择服务提供方</h3>
                <p style="color: var(--color-text-soft); font-size: 13px;">点选你要使用的服务,系统会自动填入 API 地址。后续也支持手动调整。</p>
                <div class="provider-grid" id="providerGrid">
                    <?php foreach ($providers as $key => $p): ?>
                        <div class="provider-card <?php echo $key === $currentProvider ? 'active' : ''; ?>" data-key="<?php echo htmlspecialchars($key); ?>" data-api="<?php echo htmlspecialchars($p['api_base']); ?>" data-default-model="<?php echo htmlspecialchars($p['default_model']); ?>">
                            <div class="name"><?php echo htmlspecialchars($p['label']); ?></div>
                            <div class="url"><?php echo htmlspecialchars($p['api_base']); ?></div>
                            <div class="note"><?php echo htmlspecialchars($p['note']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 第 2 步:选推荐模型(或自定义) -->
            <div class="card">
                <h3>📌 第 2 步:选择模型</h3>
                <p style="color: var(--color-text-soft); font-size: 13px;">从清单里选,或选"自定义模型"手填名字。</p>
                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label>推荐模型</label>
                        <select id="modelSelect">
                            <option value="">-- 请选择 --</option>
                            <?php foreach ($providers as $key => $p): ?>
                                <optgroup label="<?php echo htmlspecialchars($p['label']); ?>" data-provider="<?php echo htmlspecialchars($key); ?>">
                                    <?php foreach ($p['models'] as $modelKey => $modelLabel): ?>
                                        <option value="<?php echo htmlspecialchars($modelKey); ?>" data-provider="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($modelLabel); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:3;">
                        <label>模型名称 <span style="color:var(--color-danger);">*</span></label>
                        <input type="text" name="model" id="modelInput" value="<?php echo htmlspecialchars($settings['model']); ?>" required placeholder="例如: qwen2.5:7b">
                        <div class="model-hint" id="modelHint">
                            <?php if ($modelInList): ?>
                                ✅ 当前模型在清单中(<?php echo htmlspecialchars($providers[$currentProvider]['label']); ?>)
                            <?php elseif (!empty($settings['model'])): ?>
                                ℹ️ 当前模型 <code><?php echo htmlspecialchars($settings['model']); ?></code> 不在推荐清单,但可以用。
                            <?php else: ?>
                                请先选一个服务提供方,再选模型。
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- API 配置 -->
            <div class="card">
                <h3>📌 第 3 步:API 详情</h3>
                <div class="form-group">
                    <label>API Base URL <span style="color:var(--color-danger);">*</span></label>
                    <input type="text" name="api_base" id="apiBaseInput" value="<?php echo htmlspecialchars($settings['api_base']); ?>" required placeholder="例如: http://localhost:11434/v1">
                    <small>选服务提供方会自动填,你也可以手动调整(改端口/路径)。</small>
                </div>
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="api_key" value="<?php echo htmlspecialchars($settings['api_key']); ?>" placeholder="本地 Ollama / llama.cpp 留空;LM Studio 填 lm-studio;OpenAI 填 sk-...">
                </div>
            </div>

            <!-- 高级参数 -->
            <div class="card">
                <h3>📌 第 4 步:高级参数(可选)</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Temperature (0~2)</label>
                        <input type="number" name="temperature" min="0" max="2" step="0.1" value="<?php echo htmlspecialchars($settings['temperature']); ?>">
                        <small>越低越稳定,越高越有创造性</small>
                    </div>
                    <div class="form-group">
                        <label>Max Tokens (100~32000)</label>
                        <input type="number" name="max_tokens" min="100" max="32000" step="100" value="<?php echo htmlspecialchars($settings['max_tokens']); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?php echo $settings['enabled'] ? 'checked' : ''; ?>>
                        启用 AI 助手
                    </label>
                </div>
            </div>

            <!-- 自定义 System Prompt -->
            <div class="card">
                <h3>📌 第 5 步:自定义 System Prompt(可选)</h3>
                <p style="color: var(--color-text-soft); font-size: 13px;">留空则使用默认提示词(包含你的姓名/角色/专长,以及工具调用规则)。</p>
                <div class="form-group">
                    <textarea name="system_prompt" rows="6" placeholder="留空使用默认。&#10;你可以在这里给 AI 设定专属的人设、口头禅、行为准则等。"><?php echo htmlspecialchars($settings['system_prompt']); ?></textarea>
                </div>
            </div>

            <div class="card" style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">💾 保存 AI 设置</button>
                <button type="button" class="btn btn-success" onclick="testAiConnection()">🧪 测试连接</button>
            </div>
        </form>
    </div>
</div>

<script>
function testAiConnection() {
    if (!confirm('确定要测试 AI 连接吗？')) return;
    var form = document.getElementById('aiSettingsForm');
    var actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'test';
    form.appendChild(actionInput);
    form.submit();
}
</script>

<script>
(function() {
    var providers = <?php echo json_encode($providers, JSON_UNESCAPED_UNICODE); ?>;
    var providerCards = document.querySelectorAll('.provider-card');
    var modelSelect = document.getElementById('modelSelect');
    var modelInput  = document.getElementById('modelInput');
    var modelHint   = document.getElementById('modelHint');
    var apiBaseInput = document.getElementById('apiBaseInput');

    // 当前选中的 provider
    var currentProvider = '<?php echo $currentProvider; ?>';

    // 切换 provider 卡片
    function selectProvider(key) {
        currentProvider = key;
        providerCards.forEach(function(c) {
            c.classList.toggle('active', c.getAttribute('data-key') === key);
        });
        // 填 API Base
        var p = providers[key];
        if (p && p.api_base) {
            apiBaseInput.value = p.api_base;
        }
        // 如果 modelInput 当前的 model 不在当前 provider 的清单里,且 modelInput 为空,自动填默认模型
        var models = (p && p.models) ? p.models : {};
        if (!modelInput.value || !(modelInput.value in models)) {
            if (p && p.default_model) {
                modelInput.value = p.default_model;
            }
        }
        // 更新 modelHint
        updateModelHint();
    }

    function updateModelHint() {
        if (!modelInput.value) {
            modelHint.innerHTML = '请先选一个服务提供方,再选模型。';
            return;
        }
        var p = providers[currentProvider];
        var models = (p && p.models) ? p.models : {};
        if (modelInput.value in models) {
            modelHint.innerHTML = '✅ 当前模型在 <strong>' + (p.label || '') + '</strong> 推荐清单中';
        } else {
            modelHint.innerHTML = 'ℹ️ 当前模型 <code>' + modelInput.value + '</code> 不在推荐清单,但可以用(只要你的服务支持)。';
        }
    }

    providerCards.forEach(function(card) {
        card.addEventListener('click', function() {
            selectProvider(card.getAttribute('data-key'));
        });
    });

    // modelSelect 选值 → 填 modelInput
    modelSelect.addEventListener('change', function() {
        var v = modelSelect.value;
        if (v && v !== '__custom__') {
            modelInput.value = v;
            // 顺便切换到该模型所属的 provider
            var opt = modelSelect.querySelector('option[value="' + v + '"]');
            if (opt) {
                var prov = opt.getAttribute('data-provider');
                if (prov && prov !== currentProvider) {
                    selectProvider(prov);
                }
            }
            updateModelHint();
        } else if (v === '__custom__') {
            modelInput.value = '';
            modelInput.focus();
        }
    });

    // modelInput 改了 → 检查在不在清单,更新提示
    modelInput.addEventListener('input', updateModelHint);
})();
</script>

<?php echo renderFooter(); ?>
</body>
</html>
