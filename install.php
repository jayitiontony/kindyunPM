<?php
/**
 * 数据库初始化脚本
 * Database Initialization Script
 */

// 引入数据库模块
require_once __DIR__ . '/includes/db.php';

// 初始化数据库
initDatabase();

echo "数据库初始化成功！\n";
echo "默认管理员账号: admin\n";
echo "默认管理员密码: admin123\n";
echo "请登录系统: http://localhost/pm_system/public/index.php\n";
