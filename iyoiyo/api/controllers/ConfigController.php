<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/database.php';

class ConfigController {
    // ====== 管理员开关 ======
    public function toggleAdminAccess(): array {
        $configFile = __DIR__ . '/../../config.json';
        $config = json_decode(file_get_contents($configFile), true);
        $config['allow_admin_access'] = !($config['allow_admin_access'] ?? true);
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return ['success' => true, 'allow_admin_access' => $config['allow_admin_access']];
    }

    // ====== 通用鉴权 ======
    private function getUserFromToken(): array {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/', $auth, $m)) {
            http_response_code(401);
            die(json_encode(['error' => '未授权']));
        }
        $payload = JWT::verify($m[1]);
        if (!$payload) {
            http_response_code(401);
            die(json_encode(['error' => 'Token无效']));
        }
        return $payload;
    }

    // ====== 上传辅助 ======
    private function handleUpload(string $field, string $subdir = ''): string {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            die(json_encode(['error' => '文件上传失败']));
        }
        $file = $_FILES[$field];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            http_response_code(400);
            die(json_encode(['error' => '不支持的图片格式']));
        }
        $dir = __DIR__ . '/../../public/uploads/' . $subdir;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = uniqid() . '.' . $ext;
        $dest = $dir . '/' . $filename;
        move_uploaded_file($file['tmp_name'], $dest);
        return '/uploads/' . ($subdir ? $subdir . '/' : '') . $filename;
    }

    private function updateUserField(int $userId, string $field, $value): void {
        $usersFile = __DIR__ . '/../../users.json';
        $data = json_decode(file_get_contents($usersFile), true);
        foreach ($data['users'] as &$u) {
            if ($u['id'] == $userId) {
                $u[$field] = $value;
                break;
            }
        }
        file_put_contents($usersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // ====== 头像上传 ======
    public function uploadAvatar(): array {
        $user = $this->getUserFromToken();
        $url = $this->handleUpload('avatar', 'avatars');
        $this->updateUserField($user['user_id'], 'avatar', $url);
        return ['success' => true, 'url' => $url];
    }

    // ====== 背景上传 ======
    public function uploadBgHome(): array {
        $user = $this->getUserFromToken();
        $url = $this->handleUpload('bg', 'backgrounds');
        // 存入 user_settings 表
        $db = Database::getInstance();
        $db->prepare('INSERT INTO user_settings (user_id, bg_home) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET bg_home = ?')
           ->execute([$user['user_id'], $url, $url]);
        return ['success' => true, 'url' => $url];
    }

    public function uploadBgSpace(): array {
        $user = $this->getUserFromToken();
        $url = $this->handleUpload('bg', 'backgrounds');
        $db = Database::getInstance();
        $db->prepare('INSERT INTO user_settings (user_id, bg_space) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET bg_space = ?')
           ->execute([$user['user_id'], $url, $url]);
        return ['success' => true, 'url' => $url];
    }

    // ====== 修改密码 ======
    public function changePassword(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $oldPassword = $input['old_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';

        $usersFile = __DIR__ . '/../../users.json';
        $data = json_decode(file_get_contents($usersFile), true);
        foreach ($data['users'] as &$u) {
            if ($u['id'] == $user['user_id']) {
                if ($u['password'] !== $oldPassword) {
                    http_response_code(400);
                    return ['error' => '原密码错误'];
                }
                $u['password'] = $newPassword;
                file_put_contents($usersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return ['success' => true];
            }
        }
        http_response_code(404);
        return ['error' => '用户不存在'];
    }

    // ====== 用户设置获取与保存 ======
    public function getSettings(): array {
        $user = $this->getUserFromToken();
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT bg_home, bg_space, theme FROM user_settings WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        // 同时返回用户表中的昵称和头像
        $usersFile = __DIR__ . '/../../users.json';
        $usersData = json_decode(file_get_contents($usersFile), true);
        $currentUser = null;
        foreach ($usersData['users'] as $u) {
            if ($u['id'] == $user['user_id']) {
                $currentUser = $u;
                break;
            }
        }
        return [
            'success' => true,
            'settings' => $settings ?: [],
            'user' => [
                'nickname' => $currentUser['nickname'] ?? '',
                'avatar' => $currentUser['avatar'] ?? '',
                'theme' => $currentUser['theme'] ?? 'light'
            ]
        ];
    }

    public function saveSettings(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $db = Database::getInstance();

        if (isset($input['theme'])) {
            // 更新 users.json 中的 theme
            $this->updateUserField($user['user_id'], 'theme', $input['theme']);
        }
        // user_settings 表中也可存 theme
        $db->prepare('INSERT INTO user_settings (user_id, theme) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET theme = ?')
           ->execute([$user['user_id'], $input['theme'] ?? 'light', $input['theme'] ?? 'light']);

        return ['success' => true];
    }

    public function aiStatus(): array {
        $configFile = __DIR__ . '/../../config.json';
        $config = json_decode(file_get_contents($configFile), true);
        return ['success' => true, 'enabled' => $config['ai']['enabled'] ?? false];
    }
}
