<?php
/**
 * 根目录智能入口
 * Root Smart Entry
 *
 * 逻辑:
 *   - 系统已初始化 → 直接跳转到 public/
 *   - 系统未初始化 → 自动跑 initDatabase() → 再跳转到 public/
 *
 * 用法: 用户直接访问 http://localhost/pm_system/ 即可
 *       不用再手动访问 install.php
 */

require_once __DIR__ . '/includes/db.php';

// 关闭浏览器缓存(防止 302 被缓存)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$installed = isSystemInstalled();
$action = $_GET['action'] ?? 'auto';

if ($action === 'force_install') {
    // 强制重跑安装(用户手动访问 ?action=force_install)
    initDatabase();
    header('Location: public/index.php?just_installed=1');
    exit;
}

if (!$installed) {
    // 自动跑一次安装
    initDatabase();
    header('Location: public/index.php?just_installed=1');
    exit;
}

// 已安装 → 跳 public
header('Location: public/index.php');
exit;
