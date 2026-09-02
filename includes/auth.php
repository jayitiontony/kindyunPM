<?php
/**
 * 认证与权限校验模块
 * Authentication and Authorization Module
 */

// 启动会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 用户登录
 * User login
 */
function login($username, $password) {
    $user = queryOneDb("SELECT * FROM users WHERE username = ?", [$username]);
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // 登录成功，设置会话
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        
        return true;
    }
    
    return false;
}

/**
 * 用户登出
 * User logout
 */
function logout() {
    // 清除会话
    $_SESSION = array();
    
    // 销毁会话cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // 销毁会话
    session_destroy();
}

/**
 * 需要登录验证的中间件
 * Login required middleware
 */
function requireLogin() {
    if (!isLoggedIn()) {
        redirect('index.php');
    }
}

/**
 * 需要管理员权限的中间件
 * Admin required middleware
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        die("权限不足：需要管理员权限");
    }
}

/**
 * 需要项目经理权限的中间件
 * Project manager required middleware
 */
function requireProjectManager() {
    requireLogin();
    if (!isProjectManager() && !isAdmin()) {
        die("权限不足：需要项目经理或管理员权限");
    }
}
