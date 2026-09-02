<?php
/**
 * 企业信息设置
 * Company Settings
 *
 * 字段:公司名、简称、Logo、地址、电话、邮箱、官网、标语、版权、ICP备案
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/settings_tabs.php';

requireAdmin();

$user = getCurrentUser();
$error = '';
$success = '';

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'company_name', 'company_short', 'company_logo', 'company_address',
        'company_phone', 'company_email', 'company_website', 'company_slogan',
        'copyright_text', 'icp_beian', 'system_version',
    ];
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        foreach ($fields as $f) {
            $v = $_POST[$f] ?? '';
            setSystemSetting($f, $v, $user['id']);
        }
        logOperation($user['id'], 'update', 'system', null, [
            'action_sub' => 'company_settings',
            'changed_fields' => array_keys($_POST),
        ]);
        $pdo->commit();
        $success = '企业信息已保存';
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = '保存失败: ' . $e->getMessage();
    }
}

$settings = getAllSystemSettings();
$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>企业信息设置 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
$pageTitle = '🏢 企业信息设置';
echo renderHeader($pageTitle, $user, $unreadNotifCount, 'settings', [], false);
?>

<div class="container">
    <form method="GET" action="search.php" class="top-search">
        <input type="text" name="q" placeholder="🔍 搜索任务" required>
        <button type="submit" class="btn btn-primary">搜索</button>
    </form>
</div>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <?php echo renderSettingsTabs('company'); ?>

    <div class="card">
        <h3>📋 当前企业信息(预览)</h3>
        <div class="company-preview">
            <div class="company-logo">
                <?php if (!empty($settings['company_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($settings['company_logo']); ?>" alt="logo" style="max-height:48px; max-width:120px;">
                <?php else: ?>
                    <div class="company-logo-placeholder"><?php echo htmlspecialchars(mb_substr($settings['company_name'] ?? 'K', 0, 1)); ?></div>
                <?php endif; ?>
            </div>
            <div class="company-info-preview">
                <div style="font-size:18px; font-weight:600;"><?php echo htmlspecialchars($settings['company_name'] ?? ''); ?></div>
                <div style="color: var(--color-text-mute); font-size:12px; margin-top:2px;"><?php echo htmlspecialchars($settings['company_slogan'] ?? ''); ?></div>
                <div style="color: var(--color-text-mute); font-size:12px; margin-top:6px;">
                    📞 <?php echo htmlspecialchars($settings['company_phone'] ?: '-'); ?>
                    &nbsp; ✉ <?php echo htmlspecialchars($settings['company_email'] ?: '-'); ?>
                    &nbsp; 🌐 <?php echo htmlspecialchars($settings['company_website'] ?: '-'); ?>
                </div>
                <div style="color: var(--color-text-mute); font-size:12px; margin-top:2px;">📍 <?php echo htmlspecialchars($settings['company_address'] ?: '-'); ?></div>
            </div>
        </div>
    </div>

    <form method="POST">
        <div class="card">
            <h3>📌 基本信息</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>公司全称 <span style="color:var(--color-danger);">*</span></label>
                    <input type="text" name="company_name" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>公司简称 (用于 logo 角标等)</label>
                    <input type="text" name="company_short" value="<?php echo htmlspecialchars($settings['company_short'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-group">
                <label>公司标语 / Slogan</label>
                <input type="text" name="company_slogan" value="<?php echo htmlspecialchars($settings['company_slogan'] ?? ''); ?>" placeholder="例如:让项目管理更高效">
            </div>
            <div class="form-group">
                <label>公司 Logo URL (留空则显示首字母占位)</label>
                <input type="text" name="company_logo" value="<?php echo htmlspecialchars($settings['company_logo'] ?? ''); ?>" placeholder="例如: https://example.com/logo.png 或 /uploads/logo.png">
            </div>
        </div>

        <div class="card">
            <h3>📌 联系信息</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>公司地址</label>
                    <input type="text" name="company_address" value="<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>" placeholder="例如:福建省漳州市...">
                </div>
                <div class="form-group">
                    <label>联系电话</label>
                    <input type="text" name="company_phone" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>" placeholder="例如:0596-1234567">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>邮箱</label>
                    <input type="email" name="company_email" value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>" placeholder="例如:contact@kindyun.com">
                </div>
                <div class="form-group">
                    <label>官网</label>
                    <input type="text" name="company_website" value="<?php echo htmlspecialchars($settings['company_website'] ?? ''); ?>" placeholder="例如:https://www.kindyun.com">
                </div>
            </div>
        </div>

        <div class="card">
            <h3>📌 版权与备案</h3>
            <div class="form-group">
                <label>页脚版权文字 (支持 HTML)</label>
                <input type="text" name="copyright_text" value="<?php echo htmlspecialchars($settings['copyright_text'] ?? ''); ?>" placeholder="例如:© 2024 Kindyun.com · 漳州同舟信息科技有限公司">
                <small style="color:var(--color-text-mute);">显示在所有页面底部。Kindyun.com 会被自动加超链接。</small>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>ICP 备案号</label>
                    <input type="text" name="icp_beian" value="<?php echo htmlspecialchars($settings['icp_beian'] ?? ''); ?>" placeholder="例如:闽ICP备12345678号">
                </div>
                <div class="form-group">
                    <label>系统版本号</label>
                    <input type="text" name="system_version" value="<?php echo htmlspecialchars($settings['system_version'] ?? 'v1.0.0'); ?>">
                </div>
            </div>
        </div>

        <div class="card" style="display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary">💾 保存设置</button>
            <a href="settings.php" class="btn btn-danger">返回</a>
        </div>
    </form>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
