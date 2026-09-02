<?php
/**
 * 数据库连接与初始化模块
 * Database Connection and Initialization Module
 */

// 数据库文件路径
define('DB_PATH', __DIR__ . '/../database/pm_system.db');

/**
 * 获取数据库连接
 * Get database connection
 */
function getDbConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            // 确保数据库目录存在
            $dbDir = dirname(DB_PATH);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            // 连接 SQLite 数据库
            $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
        // 每次新连接后跑一遍自愈迁移(增量 ALTER + 回填,幂等)
        migrateDatabase($pdo);
    }

    return $pdo;
}

/**
 * 自愈迁移: 在每个新连接后跑一次,确保老库也能跟最新 schema 兼容
 * 每次只跑幂等的 ALTER + UPDATE,不会破坏已有数据
 */
function migrateDatabase($pdo) {
    try {
        // projects / tasks 加 created_by
        $pdo->exec("ALTER TABLE projects ADD COLUMN created_by INTEGER");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN created_by INTEGER");
    } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_projects_created_by ON projects(created_by)");
    } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_created_by ON tasks(created_by)");
    } catch (Exception $e) {}

    // ai_chat_history 加 attachments
    try {
        $pdo->exec("ALTER TABLE ai_chat_history ADD COLUMN attachments TEXT");
    } catch (Exception $e) {}

    // 回填: projects.created_by 默认为 manager_id
    try {
        $pdo->exec("UPDATE projects SET created_by = manager_id WHERE created_by IS NULL AND manager_id IS NOT NULL");
    } catch (Exception $e) {}
    // 回填: tasks.created_by 用 project.manager_id
    try {
        $pdo->exec("UPDATE tasks SET created_by = (SELECT manager_id FROM projects WHERE projects.id = tasks.project_id) WHERE created_by IS NULL");
    } catch (Exception $e) {}
}

/**
 * 初始化数据库表
 * Initialize database tables
 */
function initDatabase() {
    $pdo = getDbConnection();
    
    // 创建角色表 (新增 qualifications 和 permissions 字段)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(50) NOT NULL UNIQUE,
            description TEXT,
            qualifications TEXT,
            permissions TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 创建用户表 (新增 name, gender, phone, expertise 字段)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(100),
            gender VARCHAR(10),
            phone VARCHAR(20),
            email VARCHAR(100),
            role_id INTEGER NOT NULL,
            expertise TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (role_id) REFERENCES roles(id)
        )
    ");
    
    // 兼容旧数据库：添加缺失的字段
    try { $pdo->query("ALTER TABLE roles ADD COLUMN qualifications TEXT"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE roles ADD COLUMN permissions TEXT"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE users ADD COLUMN name VARCHAR(100)"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE users ADD COLUMN gender VARCHAR(10)"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE users ADD COLUMN phone VARCHAR(20)"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE users ADD COLUMN expertise TEXT"); } catch (Exception $e) {}
    
    // 创建项目表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            manager_id INTEGER NOT NULL,
            start_date DATE,
            end_date DATE,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (manager_id) REFERENCES users(id)
        )
    ");
    
    // 创建项目成员表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            custom_role VARCHAR(50),
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id),
            FOREIGN KEY (user_id) REFERENCES users(id),
            UNIQUE(project_id, user_id)
        )
    ");
    
    // 创建任务表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            parent_task_id INTEGER DEFAULT 0,
            title VARCHAR(200) NOT NULL,
            description TEXT,
            assignee_id INTEGER,
            status VARCHAR(20) DEFAULT 'todo',
            priority VARCHAR(20) DEFAULT 'medium',
            start_date DATE,
            due_date DATE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id),
            FOREIGN KEY (parent_task_id) REFERENCES tasks(id),
            FOREIGN KEY (assignee_id) REFERENCES users(id)
        )
    ");
    
    // 创建任务状态日志表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            old_status VARCHAR(20),
            new_status VARCHAR(20),
            note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    
    // 创建协助申请表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS assistance_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            requester_id INTEGER NOT NULL,
            description TEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME,
            resolver_id INTEGER,
            FOREIGN KEY (task_id) REFERENCES tasks(id),
            FOREIGN KEY (requester_id) REFERENCES users(id),
            FOREIGN KEY (resolver_id) REFERENCES users(id)
        )
    ");

    // ============================================================
    // 2026-08-30 扩展:依赖、状态变更清单、阻塞、指派历史、统一操作日志
    // ============================================================

    // 任务依赖关系(多对多): task A 依赖 task B,则 B 完成前 A 不能进入 in_progress
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_dependencies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            depends_on_task_id INTEGER NOT NULL,
            note TEXT,
            created_by INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(task_id, depends_on_task_id),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (depends_on_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");

    // 任务状态变更详细记录(老/新状态、百分比、备注、操作人)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_status_changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            old_status VARCHAR(20),
            new_status VARCHAR(20) NOT NULL,
            old_progress INTEGER,
            new_progress INTEGER,
            note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");

    // 任务阻塞信息(每次阻塞/解除一条,原因 + 请求谁协助 + 是否解决)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_blocked_info (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            block_reason TEXT NOT NULL,
            requested_assist_user_id INTEGER,
            status VARCHAR(20) DEFAULT 'open',
            resolve_note TEXT,
            resolved_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (requested_assist_user_id) REFERENCES users(id),
            FOREIGN KEY (resolved_by) REFERENCES users(id)
        )
    ");

    // 任务指派历史(原指派人 → 新指派人 + 原因)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            operator_id INTEGER NOT NULL,
            from_user_id INTEGER,
            to_user_id INTEGER,
            reason TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (operator_id) REFERENCES users(id),
            FOREIGN KEY (from_user_id) REFERENCES users(id),
            FOREIGN KEY (to_user_id) REFERENCES users(id)
        )
    ");

    // 统一操作日志(所有用户操作都通过此表记录,支持按任务/按人查询)
    // target_type: task / project / user / role / system
    // action:     create / update / delete / status_change / assign / block / unblock / request_assist / resolve_assist / login / logout ...
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action VARCHAR(50) NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id INTEGER,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oplog_target ON operation_logs(target_type, target_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oplog_user   ON operation_logs(user_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oplog_action ON operation_logs(action, created_at)");

    // ============================================================
    // 2026-08-30 第二轮扩展: 评论/标签/checklist/工时/通知/里程碑/附件
    // ============================================================

    // 任务评论 / 讨论
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");

    // 任务标签字典
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER,
            name VARCHAR(50) NOT NULL,
            color VARCHAR(20) DEFAULT '#3498db',
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            UNIQUE(project_id, name)
        )
    ");

    // 任务 - 标签 关联
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_tag_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            UNIQUE(task_id, tag_id),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES task_tags(id) ON DELETE CASCADE
        )
    ");

    // 任务子清单(checklist) - 比如验收标准 1/2/3,完成情况独立勾选
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_checklist_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            content VARCHAR(500) NOT NULL,
            is_done INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            created_by INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");

    // 工时记录
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS time_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hours REAL NOT NULL,
            work_date DATE NOT NULL,
            note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");

    // 站内通知
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(200) NOT NULL,
            body TEXT,
            target_type VARCHAR(50),
            target_id INTEGER,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notif_user_unread ON notifications(user_id, is_read, created_at)");

    // 里程碑
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS milestones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            due_date DATE,
            status VARCHAR(20) DEFAULT 'pending',
            created_by INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");

    // 任务附件(只存元数据,实际文件存 uploads/)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            filename VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_size INTEGER DEFAULT 0,
            file_path VARCHAR(500) NOT NULL,
            note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");

    // 兼容旧库: 给 tasks 加 estimated_hours / actual_hours
    try { $pdo->query("ALTER TABLE tasks ADD COLUMN progress INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE tasks ADD COLUMN start_date DATE"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE tasks ADD COLUMN estimated_hours REAL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE tasks ADD COLUMN actual_hours REAL DEFAULT 0"); } catch (Exception $e) {}
    // projects 加归档
    try { $pdo->query("ALTER TABLE projects ADD COLUMN archived_at DATETIME"); } catch (Exception $e) {}

    // 2026-08-31: projects / tasks 加 created_by 字段,用于"创建者才能删除"权限
    try { $pdo->query("ALTER TABLE projects ADD COLUMN created_by INTEGER"); } catch (Exception $e) {}
    try { $pdo->query("ALTER TABLE tasks ADD COLUMN created_by INTEGER"); } catch (Exception $e) {}
    try { $pdo->query("CREATE INDEX IF NOT EXISTS idx_projects_created_by ON projects(created_by)"); } catch (Exception $e) {}
    try { $pdo->query("CREATE INDEX IF NOT EXISTS idx_tasks_created_by ON tasks(created_by)"); } catch (Exception $e) {}

    // 回填: projects.created_by 默认为 manager_id(创建项目的人就是项目经理)
    try {
        $pdo->exec("UPDATE projects SET created_by = manager_id WHERE created_by IS NULL");
    } catch (Exception $e) {}
    // 回填: tasks.created_by 用 project_members + operation_logs (最早的 create 记录) 推断
    // 简化方案:用 project.manager_id(最常见的创建人模式);无法识别的保持 NULL(无删除权限)
    try {
        $pdo->exec("UPDATE tasks SET created_by = (SELECT manager_id FROM projects WHERE projects.id = tasks.project_id) WHERE created_by IS NULL");
    } catch (Exception $e) {}

    // ============================================================
    // 2026-08-30 AI 助手:用户级 API 设置 + 聊天记录
    // ============================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_user_settings (
            user_id INTEGER PRIMARY KEY,
            api_base VARCHAR(255) DEFAULT 'http://localhost:11434/v1',
            api_key VARCHAR(255) DEFAULT '',
            model VARCHAR(100) DEFAULT 'qwen2.5:7b',
            temperature REAL DEFAULT 0.7,
            max_tokens INTEGER DEFAULT 2000,
            system_prompt TEXT,
            enabled INTEGER DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_chat_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            role VARCHAR(20) NOT NULL,
            content TEXT,
            tool_calls TEXT,
            tool_call_id VARCHAR(64),
            name VARCHAR(64),
            attachments TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_aichat_user_time ON ai_chat_history(user_id, created_at)");
    // 兼容老库: 加 attachments 字段
    try { $pdo->exec("ALTER TABLE ai_chat_history ADD COLUMN attachments TEXT"); } catch (Exception $e) {}

    // ============================================================
    // 系统级设置(key-value 存储:企业信息、版权等)
    // ============================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            key_name VARCHAR(50) PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by INTEGER,
            FOREIGN KEY (updated_by) REFERENCES users(id)
        )
    ");

    // 插入默认企业信息(仅首次)
    $defaultSettings = [
        'company_name'    => 'Kindyun PM',
        'company_short'   => 'Kindyun',
        'company_logo'    => '',                       // logo 文本/URL
        'company_address' => '',
        'company_phone'   => '',
        'company_email'   => '',
        'company_website' => 'https://www.kindyun.com',
        'company_slogan'  => '让项目管理更高效',
        'copyright_text'  => '© ' . date('Y') . ' Kindyun.com · 漳州同舟信息科技有限公司',
        'icp_beian'       => '',
        'system_version'  => 'v1.0.0',
    ];
    foreach ($defaultSettings as $k => $v) {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO system_settings (key_name, value) VALUES (?, ?)");
        $stmt->execute([$k, $v]);
    }
    
    // 插入默认角色
    $roles = [
        ['admin', '系统管理员', '负责公司系统管理与维护', '全部权限'],
        ['project_manager', '项目经理', '负责项目规划、任务分配与进度跟踪', '项目管理、任务管理、成员管理'],
        ['team_member', '项目组员', '负责具体任务执行与反馈', '个人任务管理、协助申请']
    ];
    
    foreach ($roles as $role) {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO roles (name, description, qualifications, permissions) VALUES (?, ?, ?, ?)");
        $stmt->execute([$role[0], $role[1], $role[2], $role[3]]);
    }
    
    // 插入默认管理员用户 (用户名: admin, 密码: admin123)
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (username, password_hash, name, gender, phone, email, role_id, expertise) VALUES (?, ?, ?, ?, ?, ?, (SELECT id FROM roles WHERE name = 'admin'), ?)");
    $stmt->execute(['admin', $adminPassword, '管理员', '男', '13800000000', 'admin@pm_system.com', '系统管理']);
}

/**
 * 执行查询并返回结果
 * Execute query and return results
 */
function queryDb($sql, $params = []) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 执行单条查询并返回结果
 * Execute single query and return result
 */
function queryOneDb($sql, $params = []) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

/**
 * 执行插入/更新/删除操作
 * Execute insert/update/delete operation
 */
function executeDb($sql, $params = []) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

/**
 * 获取最后插入的ID
 * Get last inserted ID
 */
function getLastInsertId() {
    $pdo = getDbConnection();
    return $pdo->lastInsertId();
}

// 角色管理函数
function getAllRoles() {
    return queryDb("SELECT * FROM roles ORDER BY id ASC");
}

function getRoleById($id) {
    return queryOneDb("SELECT * FROM roles WHERE id = ?", [$id]);
}

function createRole($name, $description, $qualifications, $permissions) {
    return executeDb("INSERT INTO roles (name, description, qualifications, permissions) VALUES (?, ?, ?, ?)", 
        [$name, $description, $qualifications, $permissions]);
}

function updateRole($id, $name, $description, $qualifications, $permissions) {
    return executeDb("UPDATE roles SET name=?, description=?, qualifications=?, permissions=? WHERE id=?", 
        [$name, $description, $qualifications, $permissions, $id]);
}

function deleteRole($id) {
    // 检查是否有用户关联此角色
    $userCount = queryOneDb("SELECT COUNT(*) as count FROM users WHERE role_id = ?", [$id]);
    if ($userCount['count'] > 0) {
        throw new Exception("该角色下有用户，无法删除");
    }
    return executeDb("DELETE FROM roles WHERE id=?", [$id]);
}

// 用户管理函数
function getAllUsers() {
    return queryDb("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id ORDER BY u.id DESC");
}

function getUserById($id) {
    return queryOneDb("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?", [$id]);
}

function createUser($username, $password_hash, $name, $gender, $phone, $email, $role_id, $expertise) {
    return executeDb("INSERT INTO users (username, password_hash, name, gender, phone, email, role_id, expertise) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 
        [$username, $password_hash, $name, $gender, $phone, $email, $role_id, $expertise]);
}

function updateUser($id, $username, $name, $gender, $phone, $email, $role_id, $expertise, $passwordHash = null) {
    if ($passwordHash !== null && $passwordHash !== '') {
        return executeDb(
            "UPDATE users SET username=?, name=?, gender=?, phone=?, email=?, role_id=?, expertise=?, password_hash=? WHERE id=?",
            [$username, $name, $gender, $phone, $email, $role_id, $expertise, $passwordHash, $id]
        );
    }
    return executeDb("UPDATE users SET username=?, name=?, gender=?, phone=?, email=?, role_id=?, expertise=? WHERE id=?",
        [$username, $name, $gender, $phone, $email, $role_id, $expertise, $id]);
}

function deleteUser($id) {
    $user = getUserById($id);
    if ($user && $user['role_name'] === 'admin') {
        throw new Exception("不能删除管理员账号");
    }
    return executeDb("DELETE FROM users WHERE id=?", [$id]);
}

// =========================================================================
// 任务依赖关系
// =========================================================================
function addTaskDependency($taskId, $dependsOnTaskId, $note, $createdBy) {
    if ($taskId == $dependsOnTaskId) {
        throw new Exception("任务不能依赖自身");
    }
    // 检查循环依赖
    if (hasDependencyCycle($dependsOnTaskId, $taskId)) {
        throw new Exception("存在循环依赖,无法添加");
    }
    return executeDb(
        "INSERT OR IGNORE INTO task_dependencies (task_id, depends_on_task_id, note, created_by) VALUES (?, ?, ?, ?)",
        [$taskId, $dependsOnTaskId, $note, $createdBy]
    );
}

function removeTaskDependency($taskId, $dependsOnTaskId) {
    return executeDb("DELETE FROM task_dependencies WHERE task_id = ? AND depends_on_task_id = ?",
        [$taskId, $dependsOnTaskId]);
}

function getTaskDependencies($taskId) {
    return queryDb(
        "SELECT td.*, t.title as dep_title, t.status as dep_status, t.progress as dep_progress, u.username as dep_assignee
         FROM task_dependencies td
         JOIN tasks t ON td.depends_on_task_id = t.id
         LEFT JOIN users u ON t.assignee_id = u.id
         WHERE td.task_id = ?
         ORDER BY td.created_at ASC",
        [$taskId]
    );
}

function getTaskDependents($taskId) {
    // 谁依赖了我(反向查询)
    return queryDb(
        "SELECT td.*, t.id as task_id, t.title as task_title, t.status as task_status
         FROM task_dependencies td
         JOIN tasks t ON td.task_id = t.id
         WHERE td.depends_on_task_id = ?
         ORDER BY td.created_at ASC",
        [$taskId]
    );
}

/**
 * 简易循环依赖检测:从 start 出发,沿着 depends_on 边走,如果能到达 target 就是环
 */
function hasDependencyCycle($start, $target) {
    $visited = [];
    $stack = [$start];
    while (!empty($stack)) {
        $node = array_pop($stack);
        if ($node == $target) return true;
        if (in_array($node, $visited)) continue;
        $visited[] = $node;
        $deps = queryDb("SELECT depends_on_task_id FROM task_dependencies WHERE task_id = ?", [$node]);
        foreach ($deps as $d) {
            $stack[] = $d['depends_on_task_id'];
        }
    }
    return false;
}

/**
 * 检查任务的依赖是否都已完成(全部 done 才算通过)
 * 返回 ['ready' => bool, 'pending' => [依赖任务信息]]
 */
function checkTaskReady($taskId) {
    $deps = getTaskDependencies($taskId);
    $pending = [];
    foreach ($deps as $d) {
        if ($d['dep_status'] !== 'done') {
            $pending[] = $d;
        }
    }
    return ['ready' => empty($pending), 'pending' => $pending];
}

// =========================================================================
// 任务状态变更记录 (变更清单)
// =========================================================================
function addTaskStatusChange($taskId, $userId, $oldStatus, $newStatus, $oldProgress, $newProgress, $note) {
    return executeDb(
        "INSERT INTO task_status_changes (task_id, user_id, old_status, new_status, old_progress, new_progress, note) VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$taskId, $userId, $oldStatus, $newStatus, $oldProgress, $newProgress, $note]
    );
}

function getTaskStatusChanges($taskId) {
    return queryDb(
        "SELECT tsc.*, u.username, u.name as user_real_name
         FROM task_status_changes tsc
         LEFT JOIN users u ON tsc.user_id = u.id
         WHERE tsc.task_id = ?
         ORDER BY tsc.created_at DESC, tsc.id DESC",
        [$taskId]
    );
}

// =========================================================================
// 任务阻塞信息
// =========================================================================
function addTaskBlockedInfo($taskId, $userId, $blockReason, $requestedAssistUserId) {
    return executeDb(
        "INSERT INTO task_blocked_info (task_id, user_id, block_reason, requested_assist_user_id, status) VALUES (?, ?, ?, ?, 'open')",
        [$taskId, $userId, $blockReason, $requestedAssistUserId ?: null]
    );
}

function resolveTaskBlockedInfo($blockedId, $resolvedBy, $resolveNote) {
    return executeDb(
        "UPDATE task_blocked_info SET status = 'resolved', resolve_note = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$resolveNote, $resolvedBy, $blockedId]
    );
}

function getTaskBlockedInfo($taskId, $onlyOpen = false) {
    $sql = "SELECT tbi.*, u.username as requester_name, u.name as requester_real_name,
                   au.username as assist_name, au.name as assist_real_name,
                   ru.username as resolver_username, ru.name as resolver_real_name
            FROM task_blocked_info tbi
            LEFT JOIN users u  ON tbi.user_id = u.id
            LEFT JOIN users au ON tbi.requested_assist_user_id = au.id
            LEFT JOIN users ru ON tbi.resolved_by = ru.id
            WHERE tbi.task_id = ?";
    if ($onlyOpen) $sql .= " AND tbi.status = 'open'";
    $sql .= " ORDER BY tbi.created_at DESC";
    return queryDb($sql, [$taskId]);
}

// =========================================================================
// 任务指派历史
// =========================================================================
function addTaskAssignment($taskId, $operatorId, $fromUserId, $toUserId, $reason) {
    return executeDb(
        "INSERT INTO task_assignments (task_id, operator_id, from_user_id, to_user_id, reason) VALUES (?, ?, ?, ?, ?)",
        [$taskId, $operatorId, $fromUserId, $toUserId, $reason]
    );
}

function getTaskAssignmentHistory($taskId) {
    return queryDb(
        "SELECT ta.*,
                ou.username as operator_name, ou.name as operator_real_name,
                fu.username as from_name, fu.name as from_real_name,
                tu.username as to_name, tu.name as to_real_name
         FROM task_assignments ta
         LEFT JOIN users ou ON ta.operator_id = ou.id
         LEFT JOIN users fu ON ta.from_user_id = fu.id
         LEFT JOIN users tu ON ta.to_user_id = tu.id
         WHERE ta.task_id = ?
         ORDER BY ta.created_at DESC",
        [$taskId]
    );
}

// =========================================================================
// 统一操作日志 (所有用户操作都通过 logOperation 记录)
// =========================================================================

/**
 * 记录一次用户操作
 * @param int|null $userId   操作人(未登录时可传 null)
 * @param string   $action   操作类型 (create / update / delete / status_change / assign / reassign / block / unblock / request_assist / resolve_assist / login / logout / ... )
 * @param string   $targetType  操作对象类型 (task / project / user / role / system)
 * @param int|null $targetId    操作对象 ID
 * @param mixed    $details     详细信息 (string 或 array,array 会被 json_encode)
 */
function logOperation($userId, $action, $targetType, $targetId, $details = null) {
    if (is_array($details) || is_object($details)) {
        $detailsStr = json_encode($details, JSON_UNESCAPED_UNICODE);
    } else {
        $detailsStr = (string)$details;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    return executeDb(
        "INSERT INTO operation_logs (user_id, action, target_type, target_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$userId, $action, $targetType, $targetId, $detailsStr, $ip, $ua]
    );
}

/**
 * 按任务查操作记录
 */
function getOperationsByTarget($targetType, $targetId, $limit = 200) {
    return queryDb(
        "SELECT ol.*, u.username, u.name as user_real_name
         FROM operation_logs ol
         LEFT JOIN users u ON ol.user_id = u.id
         WHERE ol.target_type = ? AND ol.target_id = ?
         ORDER BY ol.created_at DESC, ol.id DESC
         LIMIT ?",
        [$targetType, $targetId, $limit]
    );
}

/**
 * 按操作人查操作记录
 */
function getOperationsByUser($userId, $limit = 200, $actionFilter = null) {
    $sql = "SELECT ol.*, u.username, u.name as user_real_name
            FROM operation_logs ol
            LEFT JOIN users u ON ol.user_id = u.id
            WHERE ol.user_id = ?";
    $params = [$userId];
    if ($actionFilter) {
        $sql .= " AND ol.action = ?";
        $params[] = $actionFilter;
    }
    $sql .= " ORDER BY ol.created_at DESC, ol.id DESC LIMIT ?";
    $params[] = $limit;
    return queryDb($sql, $params);
}

/**
 * 全部操作记录(管理员视角)
 */
function getAllOperations($limit = 200, $filters = []) {
    $sql = "SELECT ol.*, u.username, u.name as user_real_name
            FROM operation_logs ol
            LEFT JOIN users u ON ol.user_id = u.id
            WHERE 1=1";
    $params = [];
    if (!empty($filters['user_id'])) {
        $sql .= " AND ol.user_id = ?";
        $params[] = (int)$filters['user_id'];
    }
    if (!empty($filters['action'])) {
        $sql .= " AND ol.action = ?";
        $params[] = $filters['action'];
    }
    if (!empty($filters['target_type'])) {
        $sql .= " AND ol.target_type = ?";
        $params[] = $filters['target_type'];
    }
    if (!empty($filters['keyword'])) {
        $sql .= " AND (ol.details LIKE ? OR u.username LIKE ? OR u.name LIKE ?)";
        $kw = '%' . $filters['keyword'] . '%';
        $params[] = $kw; $params[] = $kw; $params[] = $kw;
    }
    $sql .= " ORDER BY ol.created_at DESC, ol.id DESC LIMIT ?";
    $params[] = $limit;
    return queryDb($sql, $params);
}

/**
 * 取所有操作类型 (用于过滤下拉)
 */
function getOperationActionList() {
    return [
        'create'        => '创建',
        'update'        => '更新',
        'delete'        => '删除',
        'status_change' => '状态变更',
        'assign'        => '指派',
        'reassign'      => '重新指派',
        'block'         => '标记阻塞',
        'unblock'       => '解除阻塞',
        'request_assist'=> '请求协助',
        'resolve_assist'=> '解决协助',
        'login'         => '登录',
        'logout'        => '登出',
        'add_member'    => '添加成员',
        'remove_member' => '移除成员',
    ];
}

// =========================================================================
// 任务逾期 / 任务统计 helper
// =========================================================================
function isTaskOverdue($task) {
    if (empty($task['due_date'])) return false;
    if ($task['status'] === 'done') return false;
    return strtotime($task['due_date']) < strtotime(date('Y-m-d'));
}

function getOverdueCount($tasks) {
    $c = 0;
    foreach ($tasks as $t) if (isTaskOverdue($t)) $c++;
    return $c;
}

function getProjectStats($projectId) {
    $tasks = queryDb("SELECT status, progress, due_date FROM tasks WHERE project_id = ?", [$projectId]);
    $stats = [
        'total' => count($tasks),
        'todo' => 0, 'in_progress' => 0, 'blocked' => 0, 'done' => 0,
        'overdue' => 0,
        'avg_progress' => 0,
    ];
    if (empty($tasks)) return $stats;
    $sumP = 0;
    foreach ($tasks as $t) {
        if (isset($stats[$t['status']])) $stats[$t['status']]++;
        if (isTaskOverdue($t)) $stats['overdue']++;
        $sumP += (int)$t['progress'];
    }
    $stats['avg_progress'] = (int)($sumP / count($tasks));
    $stats['done_rate'] = $stats['total'] > 0 ? round($stats['done'] * 100 / $stats['total'], 1) : 0;
    return $stats;
}

// =========================================================================
// 任务评论
// =========================================================================
function addTaskComment($taskId, $userId, $content) {
    return executeDb("INSERT INTO task_comments (task_id, user_id, content) VALUES (?, ?, ?)", [$taskId, $userId, $content]);
}

function updateTaskComment($commentId, $content) {
    return executeDb("UPDATE task_comments SET content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$content, $commentId]);
}

function deleteTaskComment($commentId) {
    return executeDb("DELETE FROM task_comments WHERE id = ?", [$commentId]);
}

function getTaskComments($taskId) {
    return queryDb(
        "SELECT tc.*, u.username, u.name as user_real_name
         FROM task_comments tc
         LEFT JOIN users u ON tc.user_id = u.id
         WHERE tc.task_id = ?
         ORDER BY tc.created_at ASC, tc.id ASC",
        [$taskId]
    );
}

// =========================================================================
// 任务标签
// =========================================================================
function getProjectTags($projectId) {
    return queryDb("SELECT * FROM task_tags WHERE project_id IS NULL OR project_id = ? ORDER BY name", [$projectId]);
}

function addTag($name, $color, $projectId, $createdBy) {
    return executeDb(
        "INSERT OR IGNORE INTO task_tags (name, color, project_id, created_by) VALUES (?, ?, ?, ?)",
        [$name, $color, $projectId, $createdBy]
    );
}

function deleteTag($tagId) {
    return executeDb("DELETE FROM task_tags WHERE id = ?", [$tagId]);
}

function setTaskTags($taskId, $tagIds) {
    executeDb("DELETE FROM task_tag_map WHERE task_id = ?", [$taskId]);
    if (is_array($tagIds)) {
        foreach ($tagIds as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) {
                executeDb("INSERT OR IGNORE INTO task_tag_map (task_id, tag_id) VALUES (?, ?)", [$taskId, $tid]);
            }
        }
    }
}

function getTaskTags($taskId) {
    return queryDb(
        "SELECT t.* FROM task_tags t JOIN task_tag_map m ON t.id = m.tag_id WHERE m.task_id = ? ORDER BY t.name",
        [$taskId]
    );
}

// =========================================================================
// 任务子清单 (checklist)
// =========================================================================
function addChecklistItem($taskId, $content, $createdBy, $sortOrder = 0) {
    return executeDb(
        "INSERT INTO task_checklist_items (task_id, content, created_by, sort_order) VALUES (?, ?, ?, ?)",
        [$taskId, $content, $createdBy, $sortOrder]
    );
}

function updateChecklistItem($itemId, $content, $isDone) {
    return executeDb(
        "UPDATE task_checklist_items SET content = ?, is_done = ? WHERE id = ?",
        [$content, $isDone ? 1 : 0, $itemId]
    );
}

function toggleChecklistItem($itemId, $isDone) {
    return executeDb("UPDATE task_checklist_items SET is_done = ? WHERE id = ?", [$isDone ? 1 : 0, $itemId]);
}

function deleteChecklistItem($itemId) {
    return executeDb("DELETE FROM task_checklist_items WHERE id = ?", [$itemId]);
}

function getTaskChecklist($taskId) {
    return queryDb(
        "SELECT * FROM task_checklist_items WHERE task_id = ? ORDER BY sort_order ASC, id ASC",
        [$taskId]
    );
}

function getChecklistProgress($taskId) {
    $items = getTaskChecklist($taskId);
    $total = count($items);
    $done  = 0;
    foreach ($items as $i) if ($i['is_done']) $done++;
    return ['total' => $total, 'done' => $done, 'rate' => $total > 0 ? round($done * 100 / $total, 1) : 0];
}

// =========================================================================
// 工时记录
// =========================================================================
function addTimeLog($taskId, $userId, $hours, $workDate, $note) {
    executeDb(
        "INSERT INTO time_logs (task_id, user_id, hours, work_date, note) VALUES (?, ?, ?, ?, ?)",
        [$taskId, $userId, $hours, $workDate, $note]
    );
    // 同步累加到 tasks.actual_hours
    executeDb("UPDATE tasks SET actual_hours = COALESCE(actual_hours, 0) + ? WHERE id = ?", [$hours, $taskId]);
    return true;
}

function deleteTimeLog($logId) {
    $log = queryOneDb("SELECT * FROM time_logs WHERE id = ?", [$logId]);
    if ($log) {
        executeDb("DELETE FROM time_logs WHERE id = ?", [$logId]);
        executeDb("UPDATE tasks SET actual_hours = MAX(0, COALESCE(actual_hours, 0) - ?) WHERE id = ?", [$log['hours'], $log['task_id']]);
    }
    return true;
}

function getTaskTimeLogs($taskId) {
    return queryDb(
        "SELECT tl.*, u.username, u.name as user_real_name
         FROM time_logs tl LEFT JOIN users u ON tl.user_id = u.id
         WHERE tl.task_id = ?
         ORDER BY tl.work_date DESC, tl.id DESC",
        [$taskId]
    );
}

// =========================================================================
// 通知系统
// =========================================================================
function addNotification($userId, $type, $title, $body, $targetType = null, $targetId = null) {
    return executeDb(
        "INSERT INTO notifications (user_id, type, title, body, target_type, target_id) VALUES (?, ?, ?, ?, ?, ?)",
        [$userId, $type, $title, $body, $targetType, $targetId]
    );
}

function getUserNotifications($userId, $onlyUnread = false, $limit = 100) {
    $sql = "SELECT * FROM notifications WHERE user_id = ?";
    $params = [$userId];
    if ($onlyUnread) $sql .= " AND is_read = 0";
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT ?";
    $params[] = $limit;
    return queryDb($sql, $params);
}

function getUnreadNotificationCount($userId) {
    $row = queryOneDb("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0", [$userId]);
    return $row ? (int)$row['c'] : 0;
}

function markNotificationRead($notifId, $userId) {
    return executeDb("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$notifId, $userId]);
}

function markAllNotificationsRead($userId) {
    return executeDb("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$userId]);
}

// =========================================================================
// 里程碑
// =========================================================================
function addMilestone($projectId, $name, $description, $dueDate, $createdBy) {
    return executeDb(
        "INSERT INTO milestones (project_id, name, description, due_date, created_by) VALUES (?, ?, ?, ?, ?)",
        [$projectId, $name, $description, $dueDate, $createdBy]
    );
}

function updateMilestone($id, $name, $description, $dueDate, $status) {
    return executeDb(
        "UPDATE milestones SET name=?, description=?, due_date=?, status=? WHERE id=?",
        [$name, $description, $dueDate, $status, $id]
    );
}

function deleteMilestone($id) {
    return executeDb("DELETE FROM milestones WHERE id = ?", [$id]);
}

function getMilestoneById($id) {
    return queryOneDb("SELECT * FROM milestones WHERE id = ?", [$id]);
}

function getProjectMilestones($projectId) {
    return queryDb(
        "SELECT m.*, u.username as creator_name, u.name as creator_real_name,
                (SELECT COUNT(*) FROM tasks WHERE project_id = m.project_id) as total_tasks,
                (SELECT COUNT(*) FROM tasks WHERE project_id = m.project_id AND status = 'done') as done_tasks
         FROM milestones m
         LEFT JOIN users u ON m.created_by = u.id
         WHERE m.project_id = ?
         ORDER BY m.due_date ASC, m.id ASC",
        [$projectId]
    );
}

// =========================================================================
// 任务附件 (元数据)
// =========================================================================
function addTaskAttachment($taskId, $userId, $filename, $originalName, $fileSize, $filePath, $note) {
    return executeDb(
        "INSERT INTO task_attachments (task_id, user_id, filename, original_name, file_size, file_path, note) VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$taskId, $userId, $filename, $originalName, $fileSize, $filePath, $note]
    );
}

function getTaskAttachments($taskId) {
    return queryDb(
        "SELECT ta.*, u.username, u.name as user_real_name
         FROM task_attachments ta LEFT JOIN users u ON ta.user_id = u.id
         WHERE ta.task_id = ?
         ORDER BY ta.created_at DESC",
        [$taskId]
    );
}

function deleteTaskAttachment($attachmentId, $userId) {
    // 只允许上传人本人/项目经理/管理员删除
    $att = queryOneDb("SELECT * FROM task_attachments WHERE id = ?", [$attachmentId]);
    if (!$att) return false;
    if ($att['user_id'] != $userId && !isAdmin()) {
        // 简单权限:非上传人需要管理员
        return false;
    }
    // 删文件
    if (file_exists($att['file_path'])) {
        @unlink($att['file_path']);
    }
    return executeDb("DELETE FROM task_attachments WHERE id = ?", [$attachmentId]);
}

// =========================================================================
// 项目归档
// =========================================================================
function archiveProject($projectId) {
    return executeDb("UPDATE projects SET archived_at = CURRENT_TIMESTAMP, status = 'archived' WHERE id = ?", [$projectId]);
}

function unarchiveProject($projectId) {
    return executeDb("UPDATE projects SET archived_at = NULL, status = 'active' WHERE id = ?", [$projectId]);
}

// =========================================================================
// 全局搜索
// =========================================================================
function globalSearchTasks($keyword, $userId, $limit = 50) {
    // 匹配任务标题/描述/编号;并且只能搜用户能看到的(参与的或负责的项目)
    $kw = '%' . $keyword . '%';
    $sql = "SELECT DISTINCT t.id, t.title, t.description, t.status, t.priority, t.progress, t.due_date,
                   p.name as project_name, u.username as assignee_name
            FROM tasks t
            JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assignee_id = u.id
            WHERE (t.title LIKE ? OR t.description LIKE ? OR CAST(t.id AS TEXT) = ?)
              AND (p.manager_id = ?
                   OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active')
                   OR t.assignee_id = ?)
            ORDER BY t.updated_at DESC
            LIMIT ?";
    return queryDb($sql, [$kw, $kw, $keyword, $userId, $userId, $userId, $limit]);
}

// =========================================================================
// 系统级设置 (key-value)
// =========================================================================
function getSystemSetting($key, $default = null) {
    $row = queryOneDb("SELECT value FROM system_settings WHERE key_name = ?", [$key]);
    if ($row === null || $row === false) return $default;
    return $row['value'];
}

function setSystemSetting($key, $value, $updatedBy = null) {
    // 存在则更新,否则插入
    $exists = queryOneDb("SELECT 1 FROM system_settings WHERE key_name = ?", [$key]);
    if ($exists) {
        return executeDb(
            "UPDATE system_settings SET value = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE key_name = ?",
            [$value, $updatedBy, $key]
        );
    }
    return executeDb(
        "INSERT INTO system_settings (key_name, value, updated_by) VALUES (?, ?, ?)",
        [$key, $value, $updatedBy]
    );
}

function getAllSystemSettings() {
    $rows = queryDb("SELECT key_name, value FROM system_settings");
    $out = [];
    foreach ($rows as $r) $out[$r['key_name']] = $r['value'];
    return $out;
}

/**
 * 检测系统是否已初始化(关键表是否齐全)
 * 用于根目录 index.php 决定要不要自动跑 install.php
 */
function isSystemInstalled() {
    $dbFile = DB_PATH;
    if (!file_exists($dbFile)) return false;
    try {
        $pdo = getDbConnection();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $required = ['users', 'roles', 'projects', 'tasks', 'system_settings'];
        foreach ($required as $t) {
            if (!in_array($t, $tables, true)) return false;
        }
        // 至少有一个 admin 用户
        $admin = queryOneDb("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
        return !empty($admin);
    } catch (Exception $e) {
        return false;
    }
}

// =========================================================================
// 数据管理:备份 / 恢复 / 清空 / 重置
// =========================================================================
if (!defined('BACKUP_DIR'))  define('BACKUP_DIR',  __DIR__ . '/../database/backups');
if (!defined('BACKUP_KEEP'))  define('BACKUP_KEEP',  30);  // 自动备份保留份数
if (!defined('BACKUP_PREFIX')) define('BACKUP_PREFIX', 'pm_system_backup_');

/**
 * 创建数据库备份(把 DB 文件复制到 backups/ 带时间戳)
 * @param string $reason 备份原因: 'manual' / 'before_truncate' / 'before_reset' / 'before_restore'
 * @return string 备份文件路径
 */
function createBackup($reason = 'manual') {
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
    $name = BACKUP_PREFIX . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower($reason)) . '.db';
    $dest = BACKUP_DIR . '/' . $name;
    // 用 PHP 自带 copy,失败抛异常
    if (!@copy(DB_PATH, $dest)) {
        throw new Exception('备份失败:无法写入 ' . $dest);
    }
    // 清理过老的备份
    pruneOldBackups();
    return $dest;
}

/**
 * 清理过期备份,只留最新 N 份
 */
function pruneOldBackups() {
    $files = glob(BACKUP_DIR . '/' . BACKUP_PREFIX . '*.db');
    if (!$files || count($files) <= BACKUP_KEEP) return;
    // 按修改时间排序,删掉最老的
    usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
    $toDelete = array_slice($files, 0, count($files) - BACKUP_KEEP);
    foreach ($toDelete as $f) @unlink($f);
}

/**
 * 列出所有备份(按时间倒序)
 * @return array [['name'=>..., 'size'=>..., 'mtime'=>..., 'mtime_human'=>..., 'reason'=>...], ...]
 */
function listBackups() {
    if (!is_dir(BACKUP_DIR)) return [];
    $files = glob(BACKUP_DIR . '/' . BACKUP_PREFIX . '*.db');
    if (!$files) return [];
    usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
    $out = [];
    foreach ($files as $f) {
        $name = basename($f);
        $reason = 'manual';
        if (preg_match('/_(before_truncate|before_reset|before_restore|manual)\.db$/', $name, $m)) {
            $reason = $m[1];
        }
        $out[] = [
            'name'         => $name,
            'path'         => $f,
            'size'         => filesize($f),
            'size_human'   => formatFileSize(filesize($f)),
            'mtime'        => filemtime($f),
            'mtime_human'  => date('Y-m-d H:i:s', filemtime($f)),
            'reason'       => $reason,
        ];
    }
    return $out;
}

/**
 * 格式化文件大小
 */
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 2) . ' MB';
    return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}

/**
 * 备份下载(发文件给浏览器)
 */
function downloadBackup($backupName) {
    $file = BACKUP_DIR . '/' . basename($backupName);
    if (!file_exists($file) || !preg_match('/^' . BACKUP_PREFIX . '.*\.db$/', basename($backupName))) {
        http_response_code(404);
        echo '备份文件不存在';
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

/**
 * 恢复备份(从 backups/ 选一个)
 * 关键:必须先备份当前 db(留后悔药),再复制覆盖,再 force 重新连接
 */
function restoreBackup($backupName) {
    $file = BACKUP_DIR . '/' . basename($backupName);
    if (!file_exists($file) || !preg_match('/^' . BACKUP_PREFIX . '.*\.db$/', basename($backupName))) {
        throw new Exception('备份文件不存在或非法');
    }
    // 1) 备份当前 db
    createBackup('before_restore');
    // 2) 关掉 PDO 静态引用(下次 getDbConnection 会重开)
    restoreResetConnection();
    // 3) 覆盖
    if (!@copy($file, DB_PATH)) {
        throw new Exception('恢复失败:无法写入数据库文件 (检查目录权限)');
    }
    return true;
}

/**
 * 从上传文件恢复
 */
function restoreFromUpload($tmpFile, $originalName) {
    if (!is_uploaded_file($tmpFile)) throw new Exception('非法上传');
    if ($originalName !== '' && !preg_match('/\.db$/i', $originalName)) {
        throw new Exception('只支持 .db 文件');
    }
    if (filesize($tmpFile) > 100 * 1024 * 1024) throw new Exception('文件过大 (>100MB)');
    // 备份当前
    createBackup('before_restore');
    restoreResetConnection();
    if (!@move_uploaded_file($tmpFile, DB_PATH)) {
        throw new Exception('恢复失败:无法写入数据库');
    }
    return true;
}

/**
 * 强制关闭并清空 PDO 静态缓存,使下次 getDbConnection 重新打开
 */
function restoreResetConnection() {
    // 没法直接清 static $pdo 变量,只能依赖 PHP-FPM 进程重启
    // 但 SQLite 文件是覆盖式,旧连接仍持有旧数据(内存里),所以恢复后必须强制退出
    // 做法:返回 true,调用方在恢复后强制 logout / 跳登录页
    return true;
}

/**
 * 清空业务数据(保留 users/roles/system_settings)
 * 删:projects, project_members, tasks, task_dependencies, task_status_changes,
 *     task_blocked_info, task_assignments, operation_logs, task_comments,
 *     task_tags, task_tag_map, task_checklist_items, time_logs,
 *     notifications, milestones, task_attachments, assistance_requests
 */
function truncateBusinessData() {
    $pdo = getDbConnection();
    $pdo->beginTransaction();
    try {
        // 关闭外键(防止依赖报错)
        $pdo->exec("PRAGMA foreign_keys = OFF");
        $tables = [
            'task_attachments',
            'task_checklist_items',
            'task_tag_map',
            'task_tags',
            'time_logs',
            'notifications',
            'milestones',
            'task_assignments',
            'task_blocked_info',
            'task_status_changes',
            'task_dependencies',
            'task_comments',
            'assistance_requests',
            'operation_logs',
            'task_logs',
            'tasks',
            'project_members',
            'projects',
        ];
        foreach ($tables as $t) {
            try { $pdo->exec("DELETE FROM $t"); } catch (Exception $e) { /* 表可能不存在 */ }
            try { $pdo->exec("DELETE FROM sqlite_sequence WHERE name='$t'"); } catch (Exception $e) {}
        }
        $pdo->exec("PRAGMA foreign_keys = ON");
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 完全重置(回到初始状态,只留 admin/admin123)
 * 先清空所有表,再重新执行 initDatabase() 重建
 */
function resetEverything() {
    $pdo = getDbConnection();
    $pdo->beginTransaction();
    try {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec("PRAGMA foreign_keys = OFF");
        foreach ($tables as $t) {
            if ($t === 'sqlite_sequence') continue;
            try { $pdo->exec("DROP TABLE IF EXISTS $t"); } catch (Exception $e) {}
        }
        $pdo->exec("PRAGMA foreign_keys = ON");
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    // 重建
    initDatabase();
}

// =========================================================================
// AI 助手:用户设置 + 聊天记录 CRUD
// =========================================================================

/**
 * 取用户 AI 设置,不存在返回默认值
 */
function getAiSettings($userId) {
    $defaults = [
        'user_id'       => (int)$userId,
        'api_base'      => 'http://localhost:11434/v1',
        'api_key'       => '',
        'model'         => 'qwen2.5:7b',
        'temperature'   => 0.7,
        'max_tokens'    => 2000,
        'system_prompt' => '',
        'enabled'       => 1,
    ];
    $row = queryOneDb("SELECT * FROM ai_user_settings WHERE user_id = ?", [$userId]);
    if (!$row) return $defaults;
    foreach ($defaults as $k => $v) {
        if (!isset($row[$k])) $row[$k] = $v;
    }
    return $row;
}

function saveAiSettings($userId, $data) {
    $cur = getAiSettings($userId);
    $merged = array_merge($cur, $data);
    $exists = queryOneDb("SELECT 1 FROM ai_user_settings WHERE user_id = ?", [$userId]);
    if ($exists) {
        return executeDb(
            "UPDATE ai_user_settings SET api_base=?, api_key=?, model=?, temperature=?, max_tokens=?, system_prompt=?, enabled=?, updated_at=CURRENT_TIMESTAMP WHERE user_id=?",
            [
                $merged['api_base'],
                $merged['api_key'],
                $merged['model'],
                (float)$merged['temperature'],
                (int)$merged['max_tokens'],
                $merged['system_prompt'],
                (int)$merged['enabled'],
                $userId,
            ]
        );
    }
    return executeDb(
        "INSERT INTO ai_user_settings (user_id, api_base, api_key, model, temperature, max_tokens, system_prompt, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $userId,
            $merged['api_base'],
            $merged['api_key'],
            $merged['model'],
            (float)$merged['temperature'],
            (int)$merged['max_tokens'],
            $merged['system_prompt'],
            (int)$merged['enabled'],
        ]
    );
}

/**
 * 添加一条聊天记录
 * @param string $role  user / assistant / tool / system
 * @param string $content
 * @param array|null $toolCalls  assistant 消息的 tool_calls (LLM 决定调用哪些工具)
 * @param string|null $toolCallId tool 消息的 id(对应 assistant 的 tool_calls[i].id)
 * @param string|null $name       tool 消息的函数名
 * @param array|string|null $attachments 用户消息附件清单(JSON 数组)
 */
function addChatMessage($userId, $role, $content, $toolCalls = null, $toolCallId = null, $name = null, $attachments = null) {
    $tc = null;
    if ($toolCalls !== null) {
        $tc = is_string($toolCalls) ? $toolCalls : json_encode($toolCalls, JSON_UNESCAPED_UNICODE);
    }
    $att = null;
    if ($attachments !== null) {
        $att = is_string($attachments) ? $attachments : json_encode($attachments, JSON_UNESCAPED_UNICODE);
    }
    return executeDb(
        "INSERT INTO ai_chat_history (user_id, role, content, tool_calls, tool_call_id, name, attachments) VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$userId, $role, $content, $tc, $toolCallId, $name, $att]
    );
}

function getChatHistory($userId, $limit = 50) {
    return queryDb(
        "SELECT * FROM ai_chat_history WHERE user_id = ? ORDER BY id ASC LIMIT ?",
        [$userId, $limit]
    );
}

function clearChatHistory($userId) {
    return executeDb("DELETE FROM ai_chat_history WHERE user_id = ?", [$userId]);
}

/**
 * 把 DB 聊天记录转成 OpenAI messages 格式
 */
function chatHistoryToMessages($historyRows) {
    $msgs = [];
    foreach ($historyRows as $r) {
        $m = ['role' => $r['role']];
        if ($r['content'] !== null && $r['content'] !== '') {
            $m['content'] = $r['content'];
        }
        if ($r['role'] === 'assistant' && !empty($r['tool_calls'])) {
            $tc = json_decode($r['tool_calls'], true);
            if (is_array($tc)) $m['tool_calls'] = $tc;
        }
        if ($r['role'] === 'tool') {
            if (!empty($r['tool_call_id'])) $m['tool_call_id'] = $r['tool_call_id'];
            if (!empty($r['name'])) $m['name'] = $r['name'];
        }
        $msgs[] = $m;
    }
    return $msgs;
}
