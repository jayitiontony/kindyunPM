<?php
/**
 * 设置内统一 tab 导航
 * Settings Tabs (共享给 4 个设置页)
 *
 * 4 个设置页:
 *   - settings.php (角色管理/用户管理 tab + 业务 tab)
 *   - settings_company.php (企业信息)
 *   - ai_settings.php (AI 助手)
 *   - settings_data.php (数据管理)
 *
 * 调用: <?php echo renderSettingsTabs($currentTab); ?>
 *   $currentTab 形如 'roles' / 'users' / 'company' / 'ai' / 'data'
 *   不传则根据当前 PHP 文件自动判断
 */
function renderSettingsTabs($currentTab = null) {
    if ($currentTab === null) {
        $self = basename($_SERVER['PHP_SELF'] ?? '');
        $map = [
            'settings.php'         => 'roles',
            'settings_company.php' => 'company',
            'ai_settings.php'      => 'ai',
            'settings_data.php'    => 'data',
        ];
        $currentTab = $map[$self] ?? 'roles';
    }
    $tabs = [
        'roles'   => ['label' => '📌 角色管理', 'url' => 'settings.php?tab=roles'],
        'users'   => ['label' => '📌 用户管理', 'url' => 'settings.php?tab=users'],
        'company' => ['label' => '📌 企业信息', 'url' => 'settings_company.php'],
        'ai'      => ['label' => '📌 AI 助手',  'url' => 'ai_settings.php'],
        'data'    => ['label' => '📌 数据管理', 'url' => 'settings_data.php'],
    ];
    $html = '<div class="tab-nav">';
    foreach ($tabs as $key => $t) {
        $cls = ($currentTab === $key) ? ' class="active"' : '';
        $html .= '<a href="' . htmlspecialchars($t['url']) . '"' . $cls . '>' . htmlspecialchars($t['label']) . '</a>';
    }
    $html .= '</div>';
    return $html;
}
