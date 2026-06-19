<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/database.php';

class FinanceController {
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
        $userId = $user['user_id'];
        $start = $_GET['start'] ?? '';
        $end = $_GET['end'] ?? '';

        $sql = 'SELECT * FROM finances WHERE user_id = ?';
        $params = [$userId];
        if ($start) {
            $sql .= ' AND date >= ?';
            $params[] = $start;
        }
        if ($end) {
            $sql .= ' AND date <= ?';
            $params[] = $end;
        }
        $sql .= ' ORDER BY date DESC, id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function add(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? 'expense';
        $category = trim($input['category'] ?? '其他');
        $item = trim($input['item'] ?? '');
        $amount = floatval($input['amount'] ?? 0);
        $date = $input['date'] ?? date('Y-m-d');
        $note = trim($input['note'] ?? '');

        if ($item === '' || $amount <= 0) {
            http_response_code(400);
            return ['error' => '项目和金额不能为空'];
        }
        $stmt = $this->db->prepare('INSERT INTO finances (user_id, type, category, item, amount, date, note) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$user['user_id'], $type, $category, $item, $amount, $date, $note]);
        return ['success' => true, 'id' => $this->db->lastInsertId()];
    }

    public function update(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $check = $this->db->prepare('SELECT id FROM finances WHERE id = ? AND user_id = ?');
        $check->execute([$id, $user['user_id']]);
        if (!$check->fetch()) {
            http_response_code(404);
            return ['error' => '记录不存在'];
        }
        $fields = [];
        $params = [];
        foreach (['type','category','item','amount','date','note'] as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = $input[$f];
            }
        }
        if (empty($fields)) return ['error' => '无更新字段'];
        $params[] = $id;
        $this->db->prepare('UPDATE finances SET '.implode(',', $fields).' WHERE id = ?')->execute($params);
        return ['success' => true];
    }

    public function delete(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $this->db->prepare('DELETE FROM finances WHERE id = ? AND user_id = ?')->execute([$id, $user['user_id']]);
        return ['success' => true];
    }

    // ========== 分类管理 ==========
    public function categories(): array {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];

        // 已使用分类
        $stmt = $this->db->prepare('SELECT DISTINCT category FROM finances WHERE user_id = ?');
        $stmt->execute([$userId]);
        $used = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 自定义分类
        $stmt = $this->db->prepare('SELECT finance_categories FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $custom = ($row && $row['finance_categories']) ? json_decode($row['finance_categories'], true) : [];

        $all = array_unique(array_merge($custom, $used));
        sort($all);
        return ['success' => true, 'data' => $all];
    }

    public function saveCategories(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $cats = $input['categories'] ?? [];
        $json = json_encode($cats, JSON_UNESCAPED_UNICODE);
        $this->db->prepare('INSERT INTO user_settings (user_id, finance_categories) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET finance_categories = ?')
                  ->execute([$user['user_id'], $json, $json]);
        return ['success' => true];
    }

    public function updateCategory(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $old = trim($input['old_name'] ?? '');
        $new = trim($input['new_name'] ?? '');
        if ($old === '' || $new === '') return ['error' => '参数错误'];
        $userId = $user['user_id'];

        // 更新 finances 表
        $this->db->prepare('UPDATE finances SET category = ? WHERE category = ? AND user_id = ?')
                 ->execute([$new, $old, $userId]);

        // 更新自定义分类列表
        $stmt = $this->db->prepare('SELECT finance_categories FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cats = ($row && $row['finance_categories']) ? json_decode($row['finance_categories'], true) : [];
        $idx = array_search($old, $cats);
        if ($idx !== false) $cats[$idx] = $new;
        $json = json_encode($cats, JSON_UNESCAPED_UNICODE);
        $this->db->prepare('INSERT INTO user_settings (user_id, finance_categories) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET finance_categories = ?')
                  ->execute([$userId, $json, $json]);

        return ['success' => true];
    }

    public function deleteCategory(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $deleteRecords = $input['delete_records'] ?? false;
        $userId = $user['user_id'];

        if ($deleteRecords) {
            $this->db->prepare('DELETE FROM finances WHERE category = ? AND user_id = ?')->execute([$name, $userId]);
        }

        // 从自定义分类列表移除
        $stmt = $this->db->prepare('SELECT finance_categories FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cats = ($row && $row['finance_categories']) ? json_decode($row['finance_categories'], true) : [];
        $cats = array_values(array_diff($cats, [$name]));
        $json = json_encode($cats, JSON_UNESCAPED_UNICODE);
        $this->db->prepare('INSERT INTO user_settings (user_id, finance_categories) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET finance_categories = ?')
                  ->execute([$userId, $json, $json]);

        return ['success' => true];
    }

    public function stats(): array {
        $user = $this->getUserFromToken();
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-t');
        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
            FROM finances
            WHERE user_id = ? AND date >= ? AND date <= ?
        ");
        $stmt->execute([$user['user_id'], $start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $income = $row['income'] ?? 0;
        $expense = $row['expense'] ?? 0;
        return [
            'success' => true,
            'income' => $income,
            'expense' => $expense,
            'balance' => $income - $expense
        ];
    }
}
