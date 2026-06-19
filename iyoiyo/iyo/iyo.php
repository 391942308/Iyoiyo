<?php
// iyo/iyo.php - IYOIYO 成员管理（含访问开关、AI 配置、密码保护、公网地址显示）
session_start();

// 密码文件路径
$passwordFile = __DIR__ . '/admin_config.txt';
$correctPassword = '';
if (file_exists($passwordFile)) {
    $lines = file($passwordFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($lines)) {
        $correctPassword = trim($lines[0]); // 第一行为密码
    }
}

// 如果设置了密码，进行验证
if ($correctPassword !== '') {
    // 退出登录
    if (isset($_GET['logout'])) {
        unset($_SESSION['admin_authenticated']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    // 处理登录
    if (isset($_POST['admin_password'])) {
        if (trim($_POST['admin_password']) === $correctPassword) {
            $_SESSION['admin_authenticated'] = true;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $loginError = '密码错误';
        }
    }

    // 未验证则显示登录页面
    if (empty($_SESSION['admin_authenticated'])) {
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Iyo管理员登录</title>
            <style>
                body { background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; font-family: 'Microsoft YaHei', sans-serif; }
                .login-box { background: #fff; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); width: 300px; }
                h2 { text-align: center; color: #4a6cf7; }
                input[type="password"] { width: 100%; padding: 0.6rem; margin: 1rem 0; border: 1px solid #ddd; border-radius: 6px; }
                button { width: 100%; padding: 0.6rem; background: #4a6cf7; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
                .error { color: #e53e3e; text-align: center; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2>Iyo管理员登录</h2>
                <?php if (isset($loginError)) echo "<p class='error'>$loginError</p>"; ?>
                <form method="post">
                    <input type="password" name="admin_password" placeholder="请输入管理密码" required>
                    <button type="submit">登录</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// ========== 以下是原有的用户管理页面（已通过密码验证） ==========

$usersFile = __DIR__ . '/../users.json';
$configFile = __DIR__ . '/../config.json';

// 初始化文件
if (!file_exists($usersFile)) {
    file_put_contents($usersFile, json_encode(['users' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
if (!file_exists($configFile)) {
    file_put_contents($configFile, json_encode([
        'allow_admin_access' => true,
        'ai' => [
            'enabled' => false,
            'provider' => 'ollama',
            'api_key' => '',
            'base_url' => 'http://127.0.0.1:11434',
            'model' => 'deepseek-r1:8b'
        ]
    ], JSON_PRETTY_PRINT));
}

$data = json_decode(file_get_contents($usersFile), true);
$users = $data['users'] ?? [];
$config = json_decode(file_get_contents($configFile), true);

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 用户操作
    if ($action === 'add' && !empty($_POST['username']) && !empty($_POST['password'])) {
        $maxId = 0;
        foreach ($users as $u) if ($u['id'] > $maxId) $maxId = $u['id'];
        $users[] = [
            'id' => $maxId + 1,
            'username' => trim($_POST['username']),
            'password' => trim($_POST['password']),
            'nickname' => trim($_POST['nickname'] ?? ''),
            'avatar' => '',
            'theme' => 'light'
        ];
        $data['users'] = array_values($users);
        file_put_contents($usersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } elseif ($action === 'delete' && isset($_POST['id'])) {
        $users = array_values(array_filter($users, fn($u) => $u['id'] != $_POST['id']));
        $data['users'] = $users;
        file_put_contents($usersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } elseif ($action === 'update' && isset($_POST['id'])) {
        foreach ($users as &$u) {
            if ($u['id'] == $_POST['id']) {
                $u['username'] = trim($_POST['username'] ?? $u['username']);
                $u['password'] = trim($_POST['password'] ?? $u['password']);
                $u['nickname'] = trim($_POST['nickname'] ?? $u['nickname']);
                break;
            }
        }
        $data['users'] = array_values($users);
        file_put_contents($usersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // 开关操作
    elseif ($action === 'toggle_admin') {
        $config['allow_admin_access'] = !($config['allow_admin_access'] ?? true);
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }

    // AI 配置保存
    elseif ($action === 'save_ai_config') {
        $config['ai']['enabled'] = isset($_POST['ai_enabled']) && $_POST['ai_enabled'] === '1';
        $config['ai']['provider'] = trim($_POST['ai_provider'] ?? 'ollama');
        $config['ai']['api_key'] = trim($_POST['ai_api_key'] ?? '');
        $config['ai']['base_url'] = trim($_POST['ai_base_url'] ?? 'http://127.0.0.1:11434');
        $config['ai']['model'] = trim($_POST['ai_model'] ?? 'deepseek-r1:8b');
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // 重定向避免重复提交
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// ========== 读取 tunnel.log 获取外网地址 ==========
$tunnelLog = __DIR__ . '/../tunnel.log';
$tunnelUrl = '';
if (file_exists($tunnelLog)) {
    $content = file_get_contents($tunnelLog);
    // 匹配 cloudflared 生成的 trycloudflare.com 地址
    if (preg_match('/(https:\/\/[a-zA-Z0-9-]+\.trycloudflare\.com)/', $content, $matches)) {
        $tunnelUrl = $matches[1];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IYOIYO 成员管理</title>
    <style>
        body { font-family: 'Microsoft YaHei', sans-serif; background: #f0f2f5; padding: 2rem; color: #333; }
        .container { max-width: 850px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #4a6cf7; }
        .logout-link { float: right; color: #e53e3e; text-decoration: none; font-size: 0.9rem; }
        .section { margin: 1.5rem 0; }
        .section h2 { font-size: 1.2rem; margin-bottom: 0.5rem; border-bottom: 1px solid #eee; padding-bottom: 0.3rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
        th, td { padding: 0.6rem; text-align: left; border-bottom: 1px solid #eee; font-size: 0.95rem; }
        th { background: #f9fafb; }
        input[type="text"], input[type="password"], input[type="url"], select { padding: 0.4rem; border: 1px solid #ccc; border-radius: 4px; width: 90%; }
        button { padding: 0.4rem 0.8rem; border: none; background: #4a6cf7; color: #fff; border-radius: 4px; cursor: pointer; margin-right: 0.3rem; font-size: 0.9rem; }
        button.delete { background: #e53e3e; }
        .form-inline { display: flex; gap: 0.5rem; align-items: center; margin: 1rem 0; flex-wrap: wrap; }
        .form-inline input { flex: 1; min-width: 120px; }
        .toggle-section { display: flex; align-items: center; gap: 1rem; background: #f9fafb; padding: 0.8rem; border-radius: 8px; margin-bottom: 1rem; }
        .toggle-section button { background: #e2e8f0; color: #333; }
        .toggle-section button.active { background: #4a6cf7; color: #fff; }
        .status-badge { padding: 0.2rem 0.8rem; border-radius: 20px; font-size: 0.85rem; font-weight: bold; }
        .status-open { background: #c6f6d5; color: #22543d; }
        .status-closed { background: #fed7d7; color: #822727; }
        .hint { color: #666; font-size: 0.85rem; margin-top: 0.3rem; }
        label { display: block; margin-bottom: 0.3rem; font-weight: bold; }
        .form-row { display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .form-row > div { flex: 1; min-width: 200px; }
        .tunnel-url { word-break: break-all; background: #f0f4ff; padding: 0.5rem; border-radius: 6px; }
    </style>
</head>
<body>
<div class="container">
    <h1>👥 IYOIYO 成员管理</h1>
    <a href="?logout=1" class="logout-link">退出管理</a>
    <p class="hint">此页面路径可自行重命名文件夹（如 /mysecretpanel/）以增强安全性。</p>
    <p class="hint" style="color:red;">如果路径名已修改，请到config.json文件中找到对应配置，修改并同步。</p>

    <!-- 外网地址 -->
    <div class="section">
        <h2>🌐 外网访问地址</h2>
        <?php if ($tunnelUrl): ?>
            <p>当前隧道地址：<a href="<?= htmlspecialchars($tunnelUrl) ?>" target="_blank"><?= htmlspecialchars($tunnelUrl) ?></a></p>
            <p class="hint">此地址由 cloudflared 临时生成，重启后可能变化。</p>
        <?php else: ?>
            <p class="hint">未检测到隧道地址，请确保服务已启动且 cloudflared 正在运行。约需 5~10 秒生成，可稍后刷新。</p>
        <?php endif; ?>
    </div>

    <!-- 访问开关 -->
    <div class="section">
        <h2>🔒 管理员页面访问控制</h2>
        <div class="toggle-section">
            <span>当前状态：</span>
            <span class="status-badge <?= $config['allow_admin_access'] ? 'status-open' : 'status-closed' ?>">
                <?= $config['allow_admin_access'] ? '允许访问' : '禁止访问' ?>
            </span>
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="toggle_admin">
                <button type="submit" class="<?= $config['allow_admin_access'] ? '' : 'active' ?>">
                    <?= $config['allow_admin_access'] ? '🔒 立即禁止' : '🔓 立即允许' ?>
                </button>
            </form>
        </div>
        <p class="hint"><?= $config['allow_admin_access']
            ? '⚠️ 当前任何人都可以通过此页面管理账号，建议修改文件夹名并仅在需要时开启。'
            : '✅ 页面已关闭，外网无法访问。需要管理时请将 config.json 中 allow_admin_access 设为 true 或通过其他方式打开。' ?></p>
    </div>

    <!-- AI 功能配置 -->
    <div class="section">
        <h2>🍀 AI 助理 小凪 配置</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_ai_config">
            <div class="toggle-section">
                <span>启用状态：</span>
                <span class="status-badge <?= ($config['ai']['enabled'] ?? false) ? 'status-open' : 'status-closed' ?>">
                    <?= ($config['ai']['enabled'] ?? false) ? '已启用' : '已禁用' ?>
                </span>
                <label>
                    <input type="checkbox" name="ai_enabled" value="1" <?= ($config['ai']['enabled'] ?? false) ? 'checked' : '' ?>>
                    启用 AI 助理
                </label>
            </div>
            <div class="form-row">
                <div>
                    <label>提供商</label>
                    <select name="ai_provider">
                        <option value="ollama" <?= ($config['ai']['provider'] ?? '') === 'ollama' ? 'selected' : '' ?>>Ollama (本地)</option>
                        <option value="openai" <?= ($config['ai']['provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI (云端)</option>
                    </select>
                </div>
                <div>
                    <label>模型名称</label>
                    <input type="text" name="ai_model" value="<?= htmlspecialchars($config['ai']['model'] ?? 'deepseek-r1:8b') ?>">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>API 地址</label>
                    <input type="url" name="ai_base_url" value="<?= htmlspecialchars($config['ai']['base_url'] ?? 'http://127.0.0.1:11434') ?>">
                </div>
                <div>
                    <label>API Key（可选）</label>
                    <input type="text" name="ai_api_key" value="<?= htmlspecialchars($config['ai']['api_key'] ?? '') ?>" placeholder="Ollama 可留空">
                </div>
            </div>
            <button type="submit">💾 保存 AI 配置</button>
        </form>
        <p class="hint">修改后 AI 助理状态将立即生效，导航栏中的 AI 按钮也会随之显示或隐藏。</p>
    </div>

    <!-- 用户列表 -->
    <div class="section">
        <h2>📋 现有用户</h2>
        <table>
            <thead>
                <tr><th>ID</th><th>用户名</th><th>密码</th><th>昵称</th><th>操作</th></tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" style="text-align:center;">暂无用户，请添加</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <form method="post">
                            <td><?= $u['id'] ?><input type="hidden" name="id" value="<?= $u['id'] ?>"></td>
                            <td><input type="text" name="username" value="<?= htmlspecialchars($u['username']) ?>" required></td>
                            <td><input type="text" name="password" value="<?= htmlspecialchars($u['password']) ?>" required></td>
                            <td><input type="text" name="nickname" value="<?= htmlspecialchars($u['nickname'] ?? '') ?>"></td>
                            <td>
                                <button type="submit" name="action" value="update">💾 保存</button>
                                <button type="submit" name="action" value="delete" class="delete" onclick="return confirm('确认删除？')">🗑 删除</button>
                            </td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 添加新用户 -->
    <div class="section">
        <h2>➕ 添加新用户</h2>
        <form method="post" class="form-inline">
            <input type="text" name="username" placeholder="用户名" required>
            <input type="text" name="password" placeholder="密码" required>
            <input type="text" name="nickname" placeholder="昵称（可选）">
            <button type="submit" name="action" value="add">添加</button>
        </form>
        <p class="hint">密码以明文存储，请确保环境安全。</p>
    </div>
</div>
</body>
</html>
