<?php
/**
 * 数据管理:备份 / 恢复 / 清空 / 重置
 * Data Management
 *
 * ⚠️ 危险操作区,所有破坏性动作:
 *   1. 先自动备份当前 db(留后悔药)
 *   2. 二次确认(JS confirm + 二次输入关键字)
 *   3. 操作日志完整记录
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/settings_tabs.php';

requireAdmin();

// GET 下载
if (!empty($_GET['download'])) {
    downloadBackup($_GET['download']);
}

$user  = getCurrentUser();
$error = '';
$success = '';

// 防止缓存(POST 后看到旧数据)
header('Cache-Control: no-store');

// ========================================================================
// POST 处理
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ----- 1. 立即备份 -----
        if ($action === 'backup_now') {
            $dest = createBackup('manual');
            $success = '已创建备份: ' . basename($dest);
            logOperation($user['id'], 'create', 'backup', null, [
                'file' => basename($dest), 'reason' => 'manual',
            ]);
        }

        // ----- 2. 从历史备份恢复 -----
        elseif ($action === 'restore_from_history') {
            $name = $_POST['backup_name'] ?? '';
            $confirm = trim($_POST['confirm_text'] ?? '');
            if ($confirm !== '恢复') throw new Exception('请输入"恢复"以确认操作');
            restoreBackup($name);
            logOperation($user['id'], 'restore', 'database', null, [
                'from_backup' => $name, 'auto_backup' => 'before_restore',
            ]);
            // 强制踢出,重登录
            session_destroy();
            header('Location: ../index.php?restored=1');
            exit;
        }

        // ----- 3. 上传 .db 恢复 -----
        elseif ($action === 'restore_from_upload') {
            $confirm = trim($_POST['confirm_text'] ?? '');
            if ($confirm !== '恢复') throw new Exception('请输入"恢复"以确认操作');
            if (empty($_FILES['db_file']['tmp_name'])) throw new Exception('请选择 .db 文件');
            $original = $_FILES['db_file']['name'] ?? '';
            restoreFromUpload($_FILES['db_file']['tmp_name'], $original);
            logOperation($user['id'], 'restore', 'database', null, [
                'from_upload' => $original, 'auto_backup' => 'before_restore',
            ]);
            session_destroy();
            header('Location: ../index.php?restored=1');
            exit;
        }

        // ----- 4. 清空业务数据(保留用户/角色/企业设置) -----
        elseif ($action === 'truncate_business') {
            $confirm = trim($_POST['confirm_text'] ?? '');
            if ($confirm !== '清空业务数据') throw new Exception('请输入"清空业务数据"以确认');
            $autoBackup = createBackup('before_truncate');
            truncateBusinessData();
            logOperation($user['id'], 'truncate', 'database', null, [
                'scope' => 'business', 'auto_backup' => basename($autoBackup),
            ]);
            $success = '已清空所有业务数据(用户/角色/企业设置保留)。自动备份已保存: ' . basename($autoBackup);
        }

        // ----- 5. 完全重置(回到出厂) -----
        elseif ($action === 'reset_everything') {
            $confirm = trim($_POST['confirm_text'] ?? '');
            if ($confirm !== '完全重置') throw new Exception('请输入"完全重置"以确认');
            $autoBackup = createBackup('before_reset');
            resetEverything();
            logOperation($user['id'], 'reset', 'database', null, [
                'scope' => 'full', 'auto_backup' => basename($autoBackup),
            ]);
            $success = '已完全重置(回到出厂状态,仅保留 admin/admin123)。自动备份已保存: ' . basename($autoBackup);
        }

        // ----- 6. 删除备份 -----
        elseif ($action === 'delete_backup') {
            $name = basename($_POST['backup_name'] ?? '');
            $file = BACKUP_DIR . '/' . $name;
            if (!preg_match('/^' . BACKUP_PREFIX . '.*\.db$/', $name) || !file_exists($file)) {
                throw new Exception('备份文件不存在或非法');
            }
            @unlink($file);
            logOperation($user['id'], 'delete', 'backup', null, ['file' => $name]);
            $success = '已删除备份: ' . $name;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 数据
$backups = listBackups();
$dbFile = DB_PATH;
$dbSize = file_exists($dbFile) ? filesize($dbFile) : 0;
$unreadNotifCount = getUnreadNotificationCount($user['id']);

// 业务数据统计(展示给管理员看)
$pdo = getDbConnection();
$tableStats = [];
$tablesToCount = ['users', 'projects', 'project_members', 'tasks', 'task_comments', 'task_dependencies', 'time_logs', 'notifications', 'operation_logs', 'milestones', 'task_attachments', 'task_checklist_items'];
foreach ($tablesToCount as $t) {
    try {
        $row = $pdo->query("SELECT COUNT(*) as c FROM $t")->fetch();
        $tableStats[$t] = (int)$row['c'];
    } catch (Exception $e) {
        $tableStats[$t] = '?';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据管理 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <style>
        .data-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            font-size: 13px;
        }
        .data-warning strong { color: #d35400; }
        .data-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            font-size: 13px;
        }
        .data-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }
        .data-stat {
            background: #f9fafb;
            border: 1px solid var(--color-border);
            padding: 10px 14px;
            border-radius: var(--radius);
        }
        .data-stat .label { font-size: 12px; color: var(--color-text-mute); }
        .data-stat .num { font-size: 22px; font-weight: 600; color: var(--color-text); }
        .data-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .confirm-block {
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 12px;
            border-radius: var(--radius);
            margin-top: 10px;
        }
        .confirm-block label {
            display: block;
            font-size: 12px;
            color: #991b1b;
            margin-bottom: 4px;
        }
        .confirm-block input {
            font-family: monospace;
            font-weight: 600;
        }
    </style>
</head>
<body>
<?php echo renderHeader('💾 数据管理', $user, $unreadNotifCount, 'settings', [], false); ?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <?php echo renderSettingsTabs('data'); ?>

    <div class="data-warning">
        ⚠️ <strong>危险操作区</strong> — 所有破坏性操作(清空/重置)都会自动备份当前数据库到 <code>database/backups/</code> 目录,失败可一键恢复。最近 30 份自动备份会被保留,更老的会自动清理。
    </div>

    <!-- 当前数据库概况 -->
    <div class="card">
        <h3>📌 当前数据库概况</h3>
        <div class="data-stat-grid">
            <div class="data-stat">
                <div class="label">数据库文件大小</div>
                <div class="num"><?php echo formatFileSize($dbSize); ?></div>
            </div>
            <?php foreach ($tableStats as $t => $c): ?>
                <div class="data-stat">
                    <div class="label"><?php echo htmlspecialchars($t); ?></div>
                    <div class="num"><?php echo htmlspecialchars($c); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 立即备份 -->
    <div class="card">
        <h3>📌 立即备份</h3>
        <p>创建一个数据库快照,保存到 <code>database/backups/</code> 目录。</p>
        <form method="POST">
            <input type="hidden" name="action" value="backup_now">
            <button type="submit" class="btn btn-success">💾 立即备份</button>
        </form>
    </div>

    <!-- 备份列表 -->
    <div class="card">
        <h3>📌 备份历史 (<?php echo count($backups); ?>)</h3>
        <?php if (empty($backups)): ?>
            <p style="color:#999;">还没有任何备份。点击"立即备份"创建第一个。</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>文件名</th>
                        <th>类型</th>
                        <th>大小</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($backups as $b):
                    $reasonLabel = [
                        'manual'         => ['label' => '手动',  'color' => 'status-in_progress'],
                        'before_truncate'=> ['label' => '清空前', 'color' => 'status-blocked'],
                        'before_reset'   => ['label' => '重置前', 'color' => 'status-blocked'],
                        'before_restore' => ['label' => '恢复前', 'color' => 'status-blocked'],
                    ];
                    $r = $reasonLabel[$b['reason']] ?? ['label' => $b['reason'], 'color' => 'status-todo'];
                ?>
                    <tr>
                        <td><code style="font-size:11px;"><?php echo htmlspecialchars($b['name']); ?></code></td>
                        <td><span class="status-badge <?php echo $r['color']; ?>"><?php echo htmlspecialchars($r['label']); ?></span></td>
                        <td><?php echo htmlspecialchars($b['size_human']); ?></td>
                        <td><?php echo htmlspecialchars($b['mtime_human']); ?></td>
                        <td>
                            <a href="?download=<?php echo urlencode($b['name']); ?>" class="btn btn-primary btn-sm">下载</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('确认删除此备份?')">
                                <input type="hidden" name="action" value="delete_backup">
                                <input type="hidden" name="backup_name" value="<?php echo htmlspecialchars($b['name']); ?>">
                                <button type="submit" class="btn btn-danger btn-sm">删</button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ 确认从此备份恢复?\\n\\n恢复前会先自动备份当前数据库,但所有在线用户会被踢出。')">
                                <input type="hidden" name="action" value="restore_from_history">
                                <input type="hidden" name="backup_name" value="<?php echo htmlspecialchars($b['name']); ?>">
                                <input type="hidden" name="confirm_text" value="恢复">
                                <button type="submit" class="btn btn-warning btn-sm">从此恢复</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 上传 .db 恢复 -->
    <div class="card">
        <h3>📌 从 .db 文件恢复</h3>
        <p style="color: var(--color-text-soft); font-size: 13px;">
            上传一个之前导出的 <code>.db</code> 文件,系统会先自动备份当前数据库,然后覆盖。
        </p>
        <div class="data-warning">
            ⚠️ 上传恢复会替换当前所有数据,所有用户会被踢出需重新登录。请先下载一份当前备份。
        </div>
        <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('⚠️ 确认上传并恢复?\\n\\n此操作会替换当前所有数据,所有在线用户会被踢出。')">
            <input type="hidden" name="action" value="restore_from_upload">
            <div class="form-group">
                <label>选择 .db 文件 (最大 100MB)</label>
                <input type="file" name="db_file" accept=".db" required>
            </div>
            <div class="confirm-block">
                <label>二次确认:请输入 <code>恢复</code> 以启用按钮</label>
                <input type="text" name="confirm_text" placeholder='输入"恢复"' required pattern="恢复" title='请输入"恢复"'>
            </div>
            <button type="submit" class="btn btn-warning" style="margin-top:10px;">📤 上传并恢复</button>
        </form>
    </div>

    <!-- 清空业务数据 -->
    <div class="card">
        <h3>📌 清空业务数据</h3>
        <p>删除所有项目、任务、评论、通知、操作日志等业务数据。</p>
        <div class="data-warning">
            <strong>保留:</strong> 用户账号、角色、企业信息(系统设置)<br>
            <strong>删除:</strong> 项目、项目成员、任务、依赖、状态变更、阻塞、指派、评论、标签、checklist、工时、通知、里程碑、附件、协助申请、操作日志
        </div>
        <form method="POST" onsubmit="return confirm('⚠️ 再次确认:你即将清空所有业务数据!\\n\\n系统会先自动备份当前数据库。继续?')">
            <input type="hidden" name="action" value="truncate_business">
            <div class="confirm-block">
                <label>二次确认:请输入 <code>清空业务数据</code> 以启用按钮</label>
                <input type="text" name="confirm_text" placeholder='输入"清空业务数据"' required pattern="清空业务数据" title='请输入"清空业务数据"'>
            </div>
            <button type="submit" class="btn btn-warning" style="margin-top:10px;">🧹 清空业务数据</button>
        </form>
    </div>

    <!-- 完全重置 -->
    <div class="card">
        <h3>📌 完全重置 (回到出厂)</h3>
        <p>删除所有数据并重新初始化,只保留默认管理员 <code>admin / admin123</code>。</p>
        <div class="data-danger">
            <strong>这是一个不可逆的危险操作!</strong><br>
            所有用户、角色、项目、任务、设置将被删除。系统会先自动备份当前数据库,但恢复后所有账号需重新创建。
        </div>
        <form method="POST" onsubmit="return confirm('🔥 最终确认:你即将执行完全重置!\\n\\n所有数据将被清空,系统将自动备份当前数据库,但所有用户需重新登录。\\n\\n你确定?')">
            <input type="hidden" name="action" value="reset_everything">
            <div class="confirm-block">
                <label>二次确认:请输入 <code>完全重置</code> 以启用按钮</label>
                <input type="text" name="confirm_text" placeholder='输入"完全重置"' required pattern="完全重置" title='请输入"完全重置"'>
            </div>
            <button type="submit" class="btn btn-danger" style="margin-top:10px;">🔥 完全重置</button>
        </form>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
