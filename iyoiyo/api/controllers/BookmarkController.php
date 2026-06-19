<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/database.php';

class BookmarkController {
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    private function getUserFromToken(): array {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/', $auth, $m)) {
            http_response_code(401); die(json_encode(['error' => '未授权']));
        }
        $payload = JWT::verify($m[1]);
        if (!$payload) { http_response_code(401); die(json_encode(['error' => 'Token无效'])); }
        return $payload;
    }

    // 获取存储的分类（用于排序和持久化）
    private function getStoredCategories(int $userId): array {
        $stmt = $this->db->prepare('SELECT bookmark_categories, bookmark_categories_order FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cats = ($row && $row['bookmark_categories']) ? json_decode($row['bookmark_categories'], true) : [];
        $order = ($row && $row['bookmark_categories_order']) ? json_decode($row['bookmark_categories_order'], true) : [];
        // 如果 order 为空，则使用 cats 本身作为顺序（并更新 order）
        if (empty($order) && !empty($cats)) {
            $order = $cats;
            $this->saveStoredCategories($userId, $cats, $order);
        }
        return ['cats' => $cats, 'order' => $order];
    }

    private function saveStoredCategories(int $userId, array $cats, array $order): void {
        $jsonCats = json_encode($cats, JSON_UNESCAPED_UNICODE);
        $jsonOrder = json_encode($order, JSON_UNESCAPED_UNICODE);
        $this->db->prepare('INSERT INTO user_settings (user_id, bookmark_categories, bookmark_categories_order) VALUES (?, ?, ?) ON CONFLICT(user_id) DO UPDATE SET bookmark_categories = ?, bookmark_categories_order = ?')
                  ->execute([$userId, $jsonCats, $jsonOrder, $jsonCats, $jsonOrder]);
    }

    // 获取分类列表（按 order 排序）
    public function categories(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $stored = $this->getStoredCategories($userId);
        $order = $stored['order'];
        $cats = $stored['cats'];
        // 合并书签中存在的分类（避免丢失）
        $stmt = $this->db->prepare('SELECT DISTINCT category_name FROM bookmarks WHERE user_id = ?');
        $stmt->execute([$userId]);
        $used = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $allCats = array_unique(array_merge($cats, $used));
        // 排序：先按 order 中的顺序，未在 order 中的放最后按字母排序
        $ordered = [];
        foreach ($order as $cat) {
            if (in_array($cat, $allCats)) {
                $ordered[] = $cat;
            }
        }
        $remaining = array_diff($allCats, $ordered);
        sort($remaining);
        $ordered = array_merge($ordered, $remaining);
        if (empty($ordered)) $ordered = ['未分类'];
        return ['success' => true, 'data' => $ordered];
    }

    // 保存分类排序
    public function saveOrder(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $order = $input['order'] ?? [];
        $stored = $this->getStoredCategories($userId);
        $cats = $stored['cats'];
        $this->saveStoredCategories($userId, $cats, $order);
        return ['success' => true];
    }

    // 获取书签列表
    public function list(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $stmt = $this->db->prepare('SELECT * FROM bookmarks WHERE user_id = ? ORDER BY sort_order, id');
        $stmt->execute([$userId]);
        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $categories = [];
        foreach ($bookmarks as $b) {
            $cat = $b['category_name'];
            if (!isset($categories[$cat])) $categories[$cat] = [];
            $categories[$cat][] = $b;
        }
        return ['success' => true, 'data' => $categories];
    }

    // 分类管理（新建、重命名、删除）
    public function addCategory(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        if ($name === '') { http_response_code(400); return ['error' => '分类名不能为空']; }
        $stored = $this->getStoredCategories($userId);
        $cats = $stored['cats'];
        $order = $stored['order'];
        if (!in_array($name, $cats)) {
            $cats[] = $name;
            $order[] = $name;
            $this->saveStoredCategories($userId, $cats, $order);
        }
        return $this->categories();
    }

    public function updateCategory(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $old = trim($input['old_name'] ?? '');
        $new = trim($input['new_name'] ?? '');
        if ($old === '' || $new === '') return ['error' => '参数错误'];
        $this->db->prepare('UPDATE bookmarks SET category_name = ? WHERE category_name = ? AND user_id = ?')->execute([$new, $old, $userId]);
        $stored = $this->getStoredCategories($userId);
        $cats = $stored['cats'];
        $order = $stored['order'];
        $idx = array_search($old, $cats);
        if ($idx !== false) $cats[$idx] = $new;
        $idxO = array_search($old, $order);
        if ($idxO !== false) $order[$idxO] = $new;
        $this->saveStoredCategories($userId, $cats, $order);
        return $this->categories();
    }

    public function deleteCategory(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $this->db->prepare('DELETE FROM bookmarks WHERE category_name = ? AND user_id = ?')->execute([$name, $userId]);
        $stored = $this->getStoredCategories($userId);
        $cats = array_values(array_diff($stored['cats'], [$name]));
        $order = array_values(array_diff($stored['order'], [$name]));
        $this->saveStoredCategories($userId, $cats, $order);
        return $this->categories();
    }

    // ========== 添加书签 ==========
    public function add(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $category = trim($input['category_name'] ?? '未分类');
        $title = trim($input['title'] ?? '');
        $url = trim($input['url'] ?? '');
        if ($title === '' || $url === '') {
            http_response_code(400);
            return ['error' => '标题和URL不能为空'];
        }
        $icon = $input['icon'] ?? '';
        $sort = $input['sort_order'] ?? 0;
        $stmt = $this->db->prepare('INSERT INTO bookmarks (user_id, category_name, title, url, icon, sort_order) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$userId, $category, $title, $url, $icon, $sort]);
        return ['success' => true, 'id' => $this->db->lastInsertId()];
    }

    // ========== 更新书签 ==========
    public function update(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $check = $this->db->prepare('SELECT id FROM bookmarks WHERE id = ? AND user_id = ?');
        $check->execute([$id, $userId]);
        if (!$check->fetch()) {
            http_response_code(404);
            return ['error' => '记录不存在'];
        }
        $fields = [];
        $params = [];
        foreach (['category_name','title','url','icon','sort_order'] as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = $input[$f];
            }
        }
        if (empty($fields)) return ['error' => '无更新字段'];
        $params[] = $id;
        $this->db->prepare('UPDATE bookmarks SET '.implode(',', $fields).' WHERE id = ?')->execute($params);
        return ['success' => true];
    }

    // ========== 删除书签 ==========
    public function delete(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $this->db->prepare('DELETE FROM bookmarks WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        return ['success' => true];
    }
}
