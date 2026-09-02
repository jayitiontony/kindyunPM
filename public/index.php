<?php
/**
 * 登录页面
 * Login Page
 */

// 启动会话
session_start();

// 引入必要的文件
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// 如果已登录，重定向到仪表盘
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = '用户名和密码不能为空';
    } else {
        // 查询用户
        $user = queryOneDb("SELECT * FROM users WHERE username = ?", [$username]);

        if ($user && password_verify($password, $user['password_hash'])) {
            // 登录成功，设置会话
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role_name'] = $user['role_name'];

            logOperation($user['id'], 'login', 'system', null, ['username' => $user['username']]);

            redirect('dashboard.php');
        } else {
            logOperation(null, 'login', 'system', null, ['username' => $username, 'result' => 'failed']);
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>项目管理 - 登录</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body class="login-page">
    <div class="login-container">
        <h2>项目管理平台 - 登录</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="index.php">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn btn-primary">登录</button>
        </form>
        
        <div class="login-hint">
            <p>默认管理员账号：admin / admin123</p>
        </div>
    </div>
</body>
</html>
