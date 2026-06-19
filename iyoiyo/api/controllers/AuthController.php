<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/database.php';

class AuthController {
    private function getUsersData(): array {
        return json_decode(file_get_contents(__DIR__ . '/../../users.json'), true);
    }

    private function saveUsersData(array $data): void {
        file_put_contents(__DIR__ . '/../../users.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function login(): array {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        $data = $this->getUsersData();
        foreach ($data['users'] as $user) {
            if ($user['username'] === $username && $user['password'] === $password) {
                $token = JWT::generate([
                    'user_id' => $user['id'],
                    'username' => $user['username']
                ]);
                return [
                    'success' => true,
                    'token' => $token,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'nickname' => $user['nickname'],
                        'avatar' => $user['avatar'] ?? '',
                        'theme' => $user['theme'] ?? 'light'
                    ]
                ];
            }
        }
        http_response_code(401);
        return ['error' => '用户名或密码错误'];
    }

    public function me(): array {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/', $authHeader, $matches)) {
            http_response_code(401);
            return ['error' => '未授权'];
        }
        $token = $matches[1];
        $payload = JWT::verify($token);
        if (!$payload) {
            http_response_code(401);
            return ['error' => 'Token无效或已过期'];
        }
        // 返回用户信息
        $data = $this->getUsersData();
        foreach ($data['users'] as $user) {
            if ($user['id'] == $payload['user_id']) {
                return [
                    'success' => true,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'nickname' => $user['nickname'],
                        'avatar' => $user['avatar'] ?? '',
                        'theme' => $user['theme'] ?? 'light'
                    ]
                ];
            }
        }
        http_response_code(404);
        return ['error' => '用户不存在'];
    }
}
