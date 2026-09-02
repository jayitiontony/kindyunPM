<?php
/**
 * 通用函数模块
 * Utility Functions Module
 */

/**
 * 检查用户是否已登录
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * 获取当前登录用户
 * Get current logged in user
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return queryOneDb("SELECT u.*, r.name as role_name, r.description as role_description, r.qualifications, r.permissions FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?", [$_SESSION['user_id']]);
}

/**
 * 检查用户是否为管理员
 * Check if user is admin
 */
function isAdmin() {
    $user = getCurrentUser();
    return $user && $user['role_name'] === 'admin';
}

/**
 * 检查用户是否为项目经理
 * Check if user is project manager
 */
function isProjectManager() {
    $user = getCurrentUser();
    return $user && $user['role_name'] === 'project_manager';
}

/**
 * 检查用户是否为项目组员
 * Check if user is team member
 */
function isTeamMember() {
    $user = getCurrentUser();
    return $user && $user['role_name'] === 'team_member';
}

/**
 * 检查用户是否为项目成员
 * Check if user is a member of a specific project
 */
function isProjectMember($projectId, $userId) {
    $stmt = "SELECT COUNT(*) as count FROM project_members WHERE project_id = ? AND user_id = ? AND status = 'active'";
    $result = queryOneDb($stmt, [$projectId, $userId]);
    return $result && $result['count'] > 0;
}

/**
 * 检查用户能否删除某个项目
 * 规则: 管理员 OR 项目创建者(created_by)
 */
function canDeleteProject($project, $user) {
    if (!$project || !$user) return false;
    if (isAdmin()) return true;
    return isset($project['created_by']) && (int)$project['created_by'] === (int)$user['id'];
}

/**
 * 检查用户能否删除某个任务
 * 规则: 管理员 OR 任务创建者(created_by)
 */
function canDeleteTask($task, $user) {
    if (!$task || !$user) return false;
    if (isAdmin()) return true;
    return isset($task['created_by']) && (int)$task['created_by'] === (int)$user['id'];
}

/**
 * 获取用户参与的项目列表
 * Get projects user is involved in
 */
function getUserProjects($userId) {
    $sql = "SELECT p.*, u.username as manager_name FROM projects p 
            LEFT JOIN users u ON p.manager_id = u.id 
            WHERE p.manager_id = ? 
            UNION 
            SELECT p.*, u.username as manager_name FROM projects p 
            JOIN project_members pm ON p.id = pm.project_id 
            LEFT JOIN users u ON p.manager_id = u.id 
            WHERE pm.user_id = ? AND pm.status = 'active'";
    return queryDb($sql, [$userId, $userId]);
}

/**
 * 重定向到指定页面
 * Redirect to specified page
 */
function redirect($url) {
    header("Location: " . $url);
    exit;
}

/**
 * 显示错误信息
 * Display error message
 */
function showError($message) {
    return "<div class='alert alert-error'>" . htmlspecialchars($message) . "</div>";
}

/**
 * 显示成功信息
 * Display success message
 */
function showSuccess($message) {
    return "<div class='alert alert-success'>" . htmlspecialchars($message) . "</div>";
}

/**
 * 格式化日期
 * Format date
 */
function formatDate($date) {
    if (empty($date)) return '-';
    return date('Y-m-d', strtotime($date));
}

/**
 * 获取任务状态文本
 * Get task status text
 */
function getTaskStatusText($status) {
    $statusMap = [
        'todo' => '待处理',
        'in_progress' => '进行中',
        'blocked' => '阻塞',
        'done' => '已完成'
    ];
    return $statusMap[$status] ?? $status;
}

/**
 * 获取协助申请状态文本
 * Get assistance request status text
 */
function getAssistanceStatusText($status) {
    $statusMap = [
        'pending' => '待处理',
        'resolved' => '已解决',
        'rejected' => '已拒绝'
    ];
    return $statusMap[$status] ?? $status;
}

/**
 * 获取性别文本
 * Get gender text
 */
function getGenderText($gender) {
    $genderMap = [
        'male' => '男',
        'female' => '女',
        'other' => '其他'
    ];
    return $genderMap[$gender] ?? $gender;
}

/**
 * 渲染分页导航条
 * @param int    $currentPage  当前页 (1-based)
 * @param int    $totalPages   总页数
 * @param string $baseUrl      基础 URL,不含 page 参数;若含 query string,需调用方自己拼好后传入
 * @param array  $queryParams  除 page 外的额外 query 参数
 * @return string HTML
 */
function renderPagination($currentPage, $totalPages, $baseUrl, $queryParams = []) {
    if ($totalPages <= 1) return '';
    $currentPage = max(1, min($currentPage, $totalPages));

    $buildUrl = function($page) use ($baseUrl, $queryParams) {
        $params = $queryParams;
        $params['page'] = $page;
        $sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';
        return $baseUrl . $sep . http_build_query($params);
    };

    $html = '<div class="pagination">';
    // 上一页
    if ($currentPage > 1) {
        $html .= '<a href="' . htmlspecialchars($buildUrl($currentPage - 1)) . '" class="page-link">‹ 上一页</a>';
    } else {
        $html .= '<span class="page-link disabled">‹ 上一页</span>';
    }

    // 页码(显示当前页前后 2 个 + 首页 + 末页)
    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= '<a href="' . htmlspecialchars($buildUrl(1)) . '" class="page-link">1</a>';
        if ($start > 2) $html .= '<span class="page-ellipsis">…</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $currentPage) {
            $html .= '<span class="page-link active">' . $i . '</span>';
        } else {
            $html .= '<a href="' . htmlspecialchars($buildUrl($i)) . '" class="page-link">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<span class="page-ellipsis">…</span>';
        $html .= '<a href="' . htmlspecialchars($buildUrl($totalPages)) . '" class="page-link">' . $totalPages . '</a>';
    }

    // 下一页
    if ($currentPage < $totalPages) {
        $html .= '<a href="' . htmlspecialchars($buildUrl($currentPage + 1)) . '" class="page-link">下一页 ›</a>';
    } else {
        $html .= '<span class="page-link disabled">下一页 ›</span>';
    }

    $html .= '</div>';
    return $html;
}
