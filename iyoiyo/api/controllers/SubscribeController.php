<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/database.php';

class SubscribeController {
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

    // 分类
    public function getCategories(): array {
        $user = $this->getUserFromToken();
        $stmt = $this->db->prepare('SELECT * FROM subscribe_categories WHERE user_id = ? ORDER BY id');
        $stmt->execute([$user['user_id']]);
        return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function addCategory(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        if ($name === '') { http_response_code(400); return ['error' => '分类名不能为空']; }
        $this->db->prepare('INSERT INTO subscribe_categories (user_id, name) VALUES (?,?)')->execute([$user['user_id'], $name]);
        return ['success' => true, 'id' => $this->db->lastInsertId()];
    }

    public function updateCategory(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $this->db->prepare('UPDATE subscribe_categories SET name = ? WHERE id = ? AND user_id = ?')->execute([$input['name'], $input['id'], $user['user_id']]);
        return ['success' => true];
    }

    public function deleteCategory(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $this->db->prepare('DELETE FROM subscribe_items WHERE category_id = ? AND user_id = ?')->execute([$id, $user['user_id']]);
        $this->db->prepare('DELETE FROM subscribe_categories WHERE id = ? AND user_id = ?')->execute([$id, $user['user_id']]);
        return ['success' => true];
    }

    // 标签
    public function getTags(): array {
        $user = $this->getUserFromToken();
        $stmt = $this->db->prepare('SELECT * FROM subscribe_tags WHERE user_id = ? ORDER BY id');
        $stmt->execute([$user['user_id']]);
        return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function addTag(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        if ($name === '') return ['error' => '标签名不能为空'];
        $this->db->prepare('INSERT INTO subscribe_tags (user_id, name) VALUES (?,?)')->execute([$user['user_id'], $name]);
        return ['success' => true, 'id' => $this->db->lastInsertId()];
    }

    public function updateTag(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $this->db->prepare('UPDATE subscribe_tags SET name = ? WHERE id = ? AND user_id = ?')->execute([$input['name'], $input['id'], $user['user_id']]);
        return ['success' => true];
    }

    public function deleteTag(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $this->db->prepare('DELETE FROM subscribe_item_tags WHERE tag_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM subscribe_tags WHERE id = ? AND user_id = ?')->execute([$id, $user['user_id']]);
        return ['success' => true];
    }

    // 作品
    public function getItems(): array {
        $user = $this->getUserFromToken();
        $categoryId = $_GET['category_id'] ?? null;
        $tagId = $_GET['tag_id'] ?? null;
        $sql = 'SELECT i.* FROM subscribe_items i WHERE i.user_id = ?';
        $params = [$user['user_id']];
        if ($categoryId) { $sql .= ' AND i.category_id = ?'; $params[] = $categoryId; }
        if ($tagId) { $sql .= ' AND i.id IN (SELECT item_id FROM subscribe_item_tags WHERE tag_id = ?)'; $params[] = $tagId; }
        $sql .= ' ORDER BY i.id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) { $item['tags'] = $this->getTagsForItem($item['id']); }
        return ['success' => true, 'data' => $items];
    }

    public function getItemDetail(): array {
        $user = $this->getUserFromToken();
        $id = $_GET['id'] ?? 0;
        $stmt = $this->db->prepare('SELECT * FROM subscribe_items WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['user_id']]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) { http_response_code(404); return ['error' => '作品不存在']; }
        $item['tags'] = $this->getTagsForItem($item['id']);
        return ['success' => true, 'data' => $item];
    }

    public function addItem(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $this->db->prepare('INSERT INTO subscribe_items (user_id, category_id, title, url, progress, notes, cover) VALUES (?,?,?,?,?,?,?)')
                 ->execute([$user['user_id'], $input['category_id'], $input['title'], $input['url']??'', $input['progress']??'', $input['notes']??'', $input['cover']??'']);
        $itemId = $this->db->lastInsertId();
        $this->syncTags($itemId, $input['tag_ids'] ?? []);
        return ['success' => true, 'id' => $itemId];
    }

    public function updateItem(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $stmt = $this->db->prepare('UPDATE subscribe_items SET category_id=?, title=?, url=?, progress=?, notes=?, cover=? WHERE id=? AND user_id=?');
        $stmt->execute([$input['category_id'], $input['title'], $input['url']??'', $input['progress']??'', $input['notes']??'', $input['cover']??'', $id, $user['user_id']]);
        if ($stmt->rowCount() === 0) {
            return ['error' => '作品不存在或无权修改'];
        }
        if (isset($input['tag_ids'])) $this->syncTags($id, $input['tag_ids']);
        return ['success' => true];
    }

    public function deleteItem(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $this->db->prepare('DELETE FROM subscribe_item_tags WHERE item_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM subscribe_items WHERE id = ? AND user_id = ?')->execute([$id, $user['user_id']]);
        return ['success' => true];
    }

    private function getTagsForItem($itemId): array {
        $stmt = $this->db->prepare('SELECT t.id, t.name FROM subscribe_tags t JOIN subscribe_item_tags it ON t.id = it.tag_id WHERE it.item_id = ?');
        $stmt->execute([$itemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function syncTags($itemId, array $tagIds) {
        $this->db->prepare('DELETE FROM subscribe_item_tags WHERE item_id = ?')->execute([$itemId]);
        if (!empty($tagIds)) {
            $stmt = $this->db->prepare('INSERT INTO subscribe_item_tags (item_id, tag_id) VALUES (?,?)');
            foreach ($tagIds as $tid) { $stmt->execute([$itemId, $tid]); }
        }
    }

    public function uploadCover(): array {
        $user = $this->getUserFromToken();
        if (!isset($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            return ['error' => '文件上传失败'];
        }
        $file = $_FILES['cover'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            return ['error' => '不支持的图片格式'];
        }
        $dir = __DIR__ . '/../../public/uploads/subscribe/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = uniqid('sub_') . '.' . $ext;
        $dest = $dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['error' => '保存文件失败'];
        }
        $url = '/uploads/subscribe/' . $filename;
        return ['success' => true, 'url' => $url];
    }
}
