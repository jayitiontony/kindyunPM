<?php
/**
 * 公共 UI 模板
 * Common UI Templates
 *
 * 提供:
 *   - renderHeader($pageTitle, $user, $unreadNotifCount, $activeNav)
 *   - renderFooter()
 *   - renderCompanyBar() - 顶栏下方的企业信息条(可选)
 *   - renderBreadcrumb() - 面包屑(可选)
 *
 * 所有页面统一调用这两个函数,保证视觉一致
 */

require_once __DIR__ . '/functions.php';

/**
 * 一级菜单(主导航,所有页面一致)
 * 通过 getMainNav() 集中维护,任何页面要改导航只动这里
 */
function getMainNav() {
    return [
        'my_tasks'      => ['label' => '我的任务', 'url' => 'my_tasks.php',    'icon' => '📋'],
        'dashboard'     => ['label' => '看板',     'url' => 'dashboard.php',   'icon' => '📊'],
        'projects'      => ['label' => '项目',     'url' => 'projects.php',    'icon' => '📂'],
        'calendar'      => ['label' => '日历',     'url' => 'calendar.php',    'icon' => '📅'],
        'gantt'         => ['label' => '甘特图',   'url' => 'gantt.php',       'icon' => '📈'],
        'ai'            => ['label' => 'AI 助手',  'url' => 'ai_assistant.php','icon' => '🤖'],
        'notifications' => ['label' => '通知',     'url' => 'notifications.php','icon' => '🔔'],
        'profile'       => ['label' => '个人中心', 'url' => 'profile.php',     'icon' => '👤'],
    ];
}

/**
 * 管理员专用一级菜单项
 */
function getAdminNav() {
    return [
        'settings'   => ['label' => '设置',     'url' => 'settings.php',     'icon' => '⚙️'],
        'system_log' => ['label' => '系统日志', 'url' => 'operation_log.php','icon' => '📋'],
    ];
}

/**
 * 根据 context 计算二级菜单(项目级 / 任务级)
 * 保证所有项目/任务内页面二级菜单一致
 *
 * @param array $context 支持的 key:
 *   - 'project_id'  : 进入项目级二级菜单
 *   - - 'project_name': 项目名(显示在面包屑用)
 *   - 'task_id'     : 再叠加任务级二级菜单
 * @return array [['label'=>..., 'url'=>..., 'key'=>..., 'icon'=>...], ...]
 */
function getContextNav($context) {
    $items = [];
    $projectId = isset($context['project_id']) ? (int)$context['project_id'] : 0;
    $taskId    = isset($context['task_id'])    ? (int)$context['task_id']    : 0;
    $projectName = isset($context['project_name']) ? $context['project_name'] : '';

    if ($projectId > 0) {
        $items[] = ['key' => 'project_dashboard', 'label' => '📊 项目仪表盘', 'url' => 'project_dashboard.php?project_id=' . $projectId];
        $items[] = ['key' => 'tasks',             'label' => '📋 任务管理',   'url' => 'tasks.php?project_id=' . $projectId];
        $items[] = ['key' => 'gantt',             'label' => '📈 甘特图',     'url' => 'gantt.php?project_id=' . $projectId];
    }
    if ($taskId > 0) {
        $items[] = ['key' => 'task_detail',   'label' => '📄 任务详情',   'url' => 'task_detail.php?task_id=' . $taskId];
        $items[] = ['key' => 'task_edit',     'label' => '✏️ 编辑任务',   'url' => 'task_edit.php?task_id=' . $taskId];
        $items[] = ['key' => 'task_reassign', 'label' => '📤 重新指派',   'url' => 'task_reassign.php?task_id=' . $taskId];
    }

    // (历史: settings 类曾用 subnav 跑设置内导航,但因为在蓝 header 里显示效果差(白字无背景),
    //   已废弃。设置页全部改为页面内 .tab-nav 形式)
    return $items;
}

/**
 * 渲染二级菜单的 HTML(项目级 / 任务级 / 设置内)
 */
function renderContextNav($context) {
    $items = getContextNav($context);
    if (empty($items)) return '';
    $html = '<div class="subnav"><div class="container subnav-inner">';
    if (!empty($context['project_name'])) {
        $html .= '<span class="subnav-label">📂 ' . htmlspecialchars($context['project_name']) . '</span>';
    } elseif (!empty($context['project_id'])) {
        $html .= '<span class="subnav-label">项目 #' . (int)$context['project_id'] . '</span>';
    } elseif (!empty($context['task_id'])) {
        $html .= '<span class="subnav-label">任务 #' . (int)$context['task_id'] . '</span>';
    } elseif (!empty($context['settings_pages'])) {
        $html .= '<span class="subnav-label">⚙️ 系统设置</span>';
    }
    foreach ($items as $it) {
        $isActive = false;
        if (!empty($it['_is_current'])) {
            $isActive = true;
        } elseif (isset($context['sub_active']) && $context['sub_active'] === $it['key']) {
            $isActive = true;
        }
        $cls = $isActive ? ' class="active"' : '';
        $html .= '<a href="' . htmlspecialchars($it['url']) . '"' . $cls . '>' . htmlspecialchars($it['label']) . '</a>';
    }
    $html .= '</div></div>';
    return $html;
}

/**
 * 渲染顶栏
 * @param string       $pageTitle        页面 H1 标题(可含 emoji)
 * @param array|null   $user             当前用户数组(为 null 时不显示用户菜单)
 * @param int          $unreadNotifCount 未读通知数
 * @param string       $activeNav        当前激活的一级导航标识 (my_tasks/dashboard/projects/...)
 * @param array        $context          二级菜单 context ['project_id'=>X, 'task_id'=>Y, 'project_name'=>..., 'sub_active'=>'tasks']
 * @param bool         $showSearch       是否在 header 下方显示搜索框
 */
function renderHeader($pageTitle, $user = null, $unreadNotifCount = 0, $activeNav = null, $context = [], $showSearch = true) {
    $settings = getAllSystemSettings();
    $companyName = $settings['company_name'] ?? 'PM System';
    $companyShort = $settings['company_short'] ?? mb_substr($companyName, 0, 1);
    $companyLogo = $settings['company_logo'] ?? '';
    $companySlogan = $settings['company_slogan'] ?? '';

    $mainNav = getMainNav();
    $adminNav = (function_exists('isAdmin') && isAdmin()) ? getAdminNav() : [];

    ob_start();
    ?>
    <div class="header">
        <div class="header-top">
            <div class="container header-top-inner">
                <div class="company-info">
                    <?php if (!empty($companyLogo)): ?>
                        <img src="<?php echo htmlspecialchars($companyLogo); ?>" alt="<?php echo htmlspecialchars($companyName); ?>" class="company-logo-img">
                    <?php else: ?>
                        <div class="company-logo-text"><?php echo htmlspecialchars($companyShort); ?></div>
                    <?php endif; ?>
                    <div class="company-info-text">
                        <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
                        <?php if (!empty($companySlogan)): ?>
                            <div class="company-slogan"><?php echo htmlspecialchars($companySlogan); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($user): ?>
                <div class="user-menu">
                    <span>👤 <?php echo htmlspecialchars($user['name'] ?: $user['username']); ?> <small style="opacity:0.7;">(<?php echo htmlspecialchars($user['role_name']); ?>)</small></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="container header-main">
            <h1><?php echo $pageTitle; ?></h1>
            <div class="nav-links">
                <?php foreach ($mainNav as $key => $item): ?>
                    <?php if ($key === 'notifications'): ?>
                        <a href="<?php echo $item['url']; ?>" class="nav-with-badge <?php echo $activeNav === $key ? 'active' : ''; ?>">
                            <?php echo $item['icon']; ?> <?php echo $item['label']; ?>
                            <?php if ($unreadNotifCount > 0): ?><span class="notif-badge"><?php echo $unreadNotifCount; ?></span><?php endif; ?>
                        </a>
                    <?php else: ?>
                        <a href="<?php echo $item['url']; ?>" class="<?php echo $activeNav === $key ? 'active' : ''; ?>"><?php echo $item['icon']; ?> <?php echo $item['label']; ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($adminNav as $key => $item): ?>
                    <a href="<?php echo $item['url']; ?>" class="<?php echo $activeNav === $key ? 'active' : ''; ?>"><?php echo $item['icon']; ?> <?php echo $item['label']; ?></a>
                <?php endforeach; ?>
                <?php if ($user): ?>
                    <a href="logout.php">登出</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php echo renderContextNav($context); ?>
    <?php if ($showSearch): ?>
    <div class="container">
        <form method="GET" action="search.php" class="top-search">
            <input type="text" name="q" placeholder="🔍 搜索任务标题/描述/编号/评论" required>
            <button type="submit" class="btn btn-primary">搜索</button>
        </form>
    </div>
    <?php endif;
    return ob_get_clean();
}

/**
 * 渲染页脚(含 Kindyun.com 版权 + 企业信息)
 */
function renderFooter() {
    $settings = getAllSystemSettings();
    $copyright = $settings['copyright_text'] ?? ('© ' . date('Y') . ' Kindyun.com');
    $icp = $settings['icp_beian'] ?? '';
    $version = $settings['system_version'] ?? 'v1.0.0';
    $companyName = $settings['company_name'] ?? '';
    $companyWebsite = $settings['company_website'] ?? 'https://www.kindyun.com';

    // 自动给 Kindyun.com 加链接
    $copyrightHtml = htmlspecialchars($copyright);
    $copyrightHtml = preg_replace(
        '/(Kindyun\.com)/i',
        '<a href="' . htmlspecialchars($companyWebsite) . '" target="_blank" rel="noopener" style="color:inherit; text-decoration:underline;">$1</a>',
        $copyrightHtml
    );

    ob_start();
    ?>
    <footer class="site-footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-main">
                    <div class="footer-company">
                        <?php if (!empty($settings['company_logo'])): ?>
                            <img src="<?php echo htmlspecialchars($settings['company_logo']); ?>" alt="" class="footer-logo">
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:600; color:#fff; margin-bottom:4px;"><?php echo htmlspecialchars($companyName); ?></div>
                            <div class="footer-links">
                                <?php if (!empty($settings['company_website'])): ?>
                                    <a href="<?php echo htmlspecialchars($settings['company_website']); ?>" target="_blank" rel="noopener">官网</a>
                                <?php endif; ?>
                                <?php if (!empty($settings['company_email'])): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($settings['company_email']); ?>">邮箱</a>
                                <?php endif; ?>
                                <?php if (!empty($settings['company_phone'])): ?>
                                    <span>📞 <?php echo htmlspecialchars($settings['company_phone']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="footer-meta">
                    <div class="footer-copyright"><?php echo $copyrightHtml; ?></div>
                    <?php if (!empty($icp)): ?>
                        <div class="footer-icp">
                            <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener"><?php echo htmlspecialchars($icp); ?></a>
                        </div>
                    <?php endif; ?>
                    <div class="footer-version">系统版本: <?php echo htmlspecialchars($version); ?> · Powered by <a href="https://www.kindyun.com" target="_blank" rel="noopener">Kindyun.com</a></div>
                </div>
            </div>
        </div>
    </footer>
    <?php
    return ob_get_clean();
}

/**
 * 在 body 顶端输出一个小提示条(系统刚安装完成时使用)
 */
function renderJustInstalledBanner() {
    if (!empty($_GET['just_installed'])) {
        return '<div class="container"><div class="alert alert-success" style="margin-top:10px;">✓ 系统已自动初始化完成。默认管理员: <strong>admin</strong> / 密码: <strong>admin123</strong> (首次登录后请到设置里修改)</div></div>';
    }
    return '';
}
