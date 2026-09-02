# PM System - 智能项目管理与AI助手平台

> 一个基于 PHP 开发的轻量级项目管理与任务协作系统，内置强大的 AI 助手功能，支持本地大模型（Ollama、llama.cpp、LM Studio 等）及云端 API（OpenAI 等）。

## 🌟 项目简介

PM System 是一款专为团队设计的项目与任务管理平台。它不仅提供了完整的项目生命周期管理、任务分解与协作功能，还深度集成了 AI 助手能力，帮助团队自动处理任务分配、进度跟踪、代码/文档辅助生成等工作。

无论您是小型创业团队还是中大型研发组织，PM System 都能提供灵活、高效、智能的项目管理体验。

## ✨ 核心功能

### 📊 项目与任务管理
- **项目全生命周期管理**：项目创建、编辑、归档、成员邀请与角色分配、里程碑设置。
- **任务看板与状态跟踪**：待处理、进行中、阻塞、已完成四种状态，支持拖拽与快速状态变更。
- **任务分解与依赖关系**：支持父子任务（任务分解），设置任务依赖（前置任务未完成则无法进入“进行中”）。
- **任务重新指派与原因记录**：负责人变更需填写指派原因，系统自动记录历史指派日志。
- **协助申请机制**：任务负责人可发起协助申请，项目成员或管理员可处理（解决/拒绝）。

### 🤖 智能 AI 助手集成
- **多服务提供方支持**：内置预设清单，支持 Ollama、llama.cpp、LM Studio、vLLM、Xinference、OpenAI 及自定义 API。
- **模型与参数灵活配置**：支持选择推荐模型或自定义模型名称，可调节 Temperature（0~2）、Max Tokens（100~32000）。
- **自定义 System Prompt**：可为 AI 设定专属人设、口头禅、行为准则及工具调用规则。
- **个人 AI 助手设置**：每个用户可在“个人中心”独立配置自己的 AI 助手参数。

### 👥 用户与角色权限
- **角色体系**：管理员（admin）、项目经理（project_manager）、团队成员（team_member）。
- **细粒度权限控制**：管理员可管理全局任务与系统设置；项目经理可管理项目基础信息与任务创建/指派；普通成员查看与更新分配给自己的任务。
- **个人信息管理**：用户可在“个人中心”修改姓名、性别、电话、邮箱、专长及登录密码（用户名不可修改）。

### 📈 数据可视化与报表
- **项目仪表盘**：总任务数、完成率、进行中/阻塞/逾期任务统计、状态分布条形图、成员负载表。
- **日历与甘特图视图**：任务时间线可视化，支持按项目查看甘特图。
- **操作日志与通知**：所有关键操作（创建、更新、指派、状态变更）均记录在操作日志中，并支持系统通知推送。

## 🛠️ 技术栈

- **后端**：PHP 7.4+ (PDO 数据库操作)
- **数据库**：SQLite / MySQL (根据 `includes/db.php` 配置)
- **前端**：HTML5, CSS3, Vanilla JavaScript (无重型前端框架，轻量快速)
- **AI 集成**：支持 OpenAI Compatible API 格式的本地/云端大模型服务

## 📦 安装与部署

### 环境要求
- Web 服务器：Nginx / Apache / phpstudy 等
- PHP 版本：7.4 或更高
- 扩展：`pdo`, `pdo_sqlite` 或 `pdo_mysql`, `json`, `mbstring`

### 安装步骤

1. **克隆或下载项目**
   将项目文件部署到您的 Web 根目录下（例如：`D:\phpstudy_pro\WWW\` 或 `/var/www/html/`）。

2. **配置数据库**
   编辑 `includes/db.php` 文件，配置您的数据库连接信息（SQLite 或 MySQL）。

3. **初始化系统**
   通过浏览器访问 `http://your-domain/install.php` 完成系统初始化与默认数据导入。
   - 默认管理员账号：`admin`
   - 默认管理员密码：`admin123`（首次登录后建议修改密码）

4. **访问系统**
   访问 `http://your-domain/` 或 `http://your-domain/public/` 登录系统。

## 🤖 AI 助手配置指南

系统支持用户在“个人中心 -> AI 助手设置”或“设置 -> AI 助手”中配置大模型服务：

1. **选择服务提供方**：从预设列表中选择 Ollama、LM Studio、vLLM、OpenAI 等。
2. **选择模型**：从推荐模型清单中选择，或手动输入模型名称（如 `qwen2.5:7b`、`gpt-3.5-turbo`）。
3. **填写 API 详情**：API Base URL（如 `http://localhost:11434/v1`）和 API Key（本地服务可留空）。
4. **高级参数**：调整 Temperature 和 Max Tokens，启用/禁用 AI 助手，自定义 System Prompt。
5. **测试连接**：点击“测试连接”按钮验证大模型服务是否正常响应。

## 📁 项目结构

```
├── includes/                 # 核心功能与公共库
│   ├── ai.php               # AI 助手核心逻辑
│   ├── ai_providers.php     # AI 服务提供方清单
│   ├── ai_tools.php         # AI 工具调用接口
│   ├── auth.php             # 认证与权限控制
│   ├── db.php               # 数据库连接与操作
│   ├── functions.php        # 通用函数库
│   ├── settings_tabs.php    # 设置页面 Tab 导航
│   └── ui.php               # UI 模板与渲染函数
├── public/                  # 前端页面入口
│   ├── ai_assistant.php     # AI 助手对话页面
│   ├── ai_settings.php      # AI 助手设置页（管理员）
│   ├── dashboard.php        # 任务看板/仪表盘
│   ├── profile.php          # 个人中心设置（个人信息 + AI 设置）
│   ├── projects.php         # 项目列表
│   ├── project_*.php        # 项目创建/编辑/仪表盘等
│   ├── task_*.php           # 任务创建/编辑/详情/重新指派等
│   ├── settings.php         # 系统设置（角色与用户管理）
│   ├── settings_company.php # 企业信息设置
│   └── settings_data.php    # 数据管理设置
├── assets/                  # 静态资源（CSS、JS、图片）
├── index.php                # 首页入口
├── install.php              # 安装脚本
└── README.md                # 项目说明文档
```

## 🤝 贡献指南

欢迎为本项目提交 Issue 和 Pull Request！如果您有功能建议或发现了 Bug，请按照以下步骤贡献：

1. Fork 本仓库
2. 创建您的特性分支 (`git checkout -b feature/AmazingFeature`)
3. 提交您的更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 开启一个 Pull Request

## 📄 开源协议

本项目采用 [MIT License](LICENSE) 开源协议。您可以自由使用、修改和分发本代码，但请保留原始版权声明和许可证声明。

## 🙏 致谢

- 感谢所有为开源大模型社区做出贡献的开发者（Ollama、llama.cpp、Qwen、Llama 等）。
- 感谢所有为 PHP 和 Web 前端技术贡献力量的社区成员。

---

**© {year} Kindyun.com - 智能项目管理与 AI 助手平台**
