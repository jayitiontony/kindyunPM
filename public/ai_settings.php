<?php
/**
 * AI 助手参数设置
 * AI Assistant Settings
 *
 * 改进: 用预设清单替代手填
 *   - 服务提供方下拉(Ollama / llama.cpp / LM Studio / vLLM / Xinference / OpenAI / 自定义)
 *   - 推荐模型下拉(根据 provider 动态切换)
 *   - 选了之后自动填 API Base / Model,但允许覆盖
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/settings_tabs.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/ai_providers.php';

requireLogin();

$user = getCurrentUser();
$error = '';
$success = '';
$providers = getAiProviders();

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
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
            $success = 'AI 助手参数已保存';
        }
        elseif ($action === 'test') {
            // 用当前 POST 数据测试,无需先保存
            $settings = [
                'api_base'    => trim($_POST['api_base'] ?? ''),
                'api_key'     => trim($_POST['api_key'] ?? ''),
                'model'       => trim($_POST['model'] ?? ''),
                'temperature' => (float)($_POST['temperature'] ?? 0.7),
                'max_tokens'  => (int)($_POST['max_tokens'] ?? 2000),
            ];
            if (empty($settings['api_base']) || empty($settings['model'])) {
                $error = '请先填写 API Base 和模型名';
            } else {
                $resp = callLlm($settings, [
                    ['role' => 'system', 'content' => '你是一个测试助手。'],
                    ['role' => 'user', 'content' => '请用一句话回复"连接成功"'],
                ], null);
                if ($resp['ok']) {
                    $reply = $resp['data']['choices'][0]['message']['content'] ?? '(空)';
                    $success = '✅ 连接成功!模型回复: ' . trim($reply);
                } else {
                    $error = '❌ 连接失败: ' . $resp['error'];
                }
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$settings = getAiSettings($user['id']);
$currentProvider = detectProviderFromApiBase($settings['api_base']);
$unreadNotifCount = getUnreadNotificationCount($user['id']);

// 把当前 model 在所属 provider 的清单中是否存在标记一下
$modelInList = false;
if (isset($providers[$currentProvider]['models'][$settings['model']])) {
    $modelInList = true;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI 助手设置 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <style>
        .provider-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }
        .provider-card {
            border: 2px solid var(--color-border);
            border-radius: var(--radius);
            padding: 12px 14px;
            cursor: pointer;
            background: #fff;
            transition: all 0.15s;
        }
        .provider-card:hover { border-color: var(--color-primary); background: #f9fafb; }
        .provider-card.active { border-color: var(--color-primary); background: var(--color-primary-l); }
        .provider-card .name { font-weight: 600; }
        .provider-card .url { font-size: 11px; color: var(--color-text-mute); font-family: monospace; margin-top: 2px; word-break: break-all; }
        .provider-card .note { font-size: 11px; color: var(--color-text-soft); margin-top: 6px; }
        .model-hint {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #075985;
            padding: 8px 12px;
            border-radius: var(--radius);
            font-size: 12px;
            margin-top: 6px;
        }
    </style>
</head>
<body>
<?php echo renderHeader('🤖 AI 助手设置', $user, $unreadNotifCount, 'settings', [], false); ?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <?php echo renderSettingsTabs('ai'); ?>

    <div class="card">
        <h3>🤖 AI 助手</h3>
        <p>配置你本地部署的大模型服务(支持 Ollama / llama.cpp / LM Studio / vLLM / Xinference / OpenAI 等)。配置完成后到 <a href="ai_assistant.php">AI 助手</a> 跟它对话。</p>
    </div>

    <form method="POST" id="aiSettingsForm">
        <input type="hidden" name="action" value="save">

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
            <button type="submit" class="btn btn-primary">💾 保存设置</button>
            <button type="submit" name="action" value="test" class="btn btn-success" formnovalidate>🧪 测试连接</button>
            <a href="ai_assistant.php" class="btn btn-warning">💬 去跟 AI 对话</a>
        </div>
    </form>
</div>

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
