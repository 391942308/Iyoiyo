<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/database.php';

class NoteController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

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

    public function list(): array {
        $user = $this->getUserFromToken();
        $stmt = $this->db->prepare('SELECT * FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$user['user_id']]);
        return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function add(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $content = $input['content'] ?? '';
        $category = trim($input['category_name'] ?? '未分类');
        if ($title === '') {
            http_response_code(400);
            return ['error' => '标题不能为空'];
        }
        $stmt = $this->db->prepare('INSERT INTO notes (user_id, category_name, title, content, updated_at) VALUES (?,?,?,?, datetime(\'now\'))');
        $stmt->execute([$user['user_id'], $category, $title, $content]);
        return ['success' => true, 'id' => $this->db->lastInsertId()];
    }

    public function update(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $check = $this->db->prepare('SELECT id FROM notes WHERE id = ? AND user_id = ?');
        $check->execute([$id, $user['user_id']]);
        if (!$check->fetch()) {
            http_response_code(404);
            return ['error' => '笔记不存在'];
        }
        $fields = [];
        $params = [];
        foreach (['title', 'content', 'category_name'] as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = $input[$f];
            }
        }
        if (empty($fields)) return ['error' => '无更新字段'];
        $fields[] = "updated_at = datetime('now')";
        $params[] = $id;
        $this->db->prepare('UPDATE notes SET ' . implode(',', $fields) . ' WHERE id = ?')->execute($params);
        return ['success' => true];
    }

    public function delete(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $this->db->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?')->execute([$id, $user['user_id']]);
        return ['success' => true];
    }

    // 获取用户分类列表（合并自定义分类和笔记实际使用的分类）
    public function getCategories(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];

        // 从 user_settings 中读取自定义分类
        $stmt = $this->db->prepare('SELECT note_categories FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $customCats = [];
        if ($row && $row['note_categories']) {
            $customCats = json_decode($row['note_categories'], true) ?: [];
        }

        // 从笔记中提取实际使用的分类
        $stmt2 = $this->db->prepare('SELECT DISTINCT category_name FROM notes WHERE user_id = ?');
        $stmt2->execute([$userId]);
        $usedCats = $stmt2->fetchAll(PDO::FETCH_COLUMN);

        // 合并去重排序
        $allCats = array_unique(array_merge($customCats, $usedCats));
        sort($allCats);
        if (empty($allCats)) $allCats = ['未分类'];

        return ['success' => true, 'data' => $allCats];
    }

    // 保存用户分类列表（全量覆盖）
    public function saveCategories(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $categories = $input['categories'] ?? [];

        $json = json_encode($categories, JSON_UNESCAPED_UNICODE);
        $this->db->prepare('INSERT INTO user_settings (user_id, note_categories) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET note_categories = ?')
                  ->execute([$userId, $json, $json]);

        return ['success' => true];
    }
}
