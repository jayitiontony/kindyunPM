<?php
/**
 * 登出页面
 * Logout Page
 */

// 启动会话
session_start();

// 引入必要的文件
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$uid = $_SESSION['user_id'] ?? null;

// 销毁会话
session_unset();
session_destroy();

// 记录登出日志
if ($uid) {
    logOperation($uid, 'logout', 'system', null, null);
}

// 重定向到登录页面
redirect('index.php');
?>
