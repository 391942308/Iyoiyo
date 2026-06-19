<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/database.php';

class ReminderController {
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
        $stmt = $this->db->prepare('SELECT * FROM reminders WHERE user_id = ? ORDER BY is_done ASC, trigger_time ASC');
        $stmt->execute([$user['user_id']]);
        return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function add(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? 'once';
        $title = trim($input['title'] ?? '');
        $detail = trim($input['detail'] ?? '');
        $trigger_time = $input['trigger_time'] ?? null;
        $repeat_rule = $input['repeat_rule'] ?? '';

        if ($title === '') {
            http_response_code(400);
            return ['error' => '标题不能为空'];
        }
        $stmt = $this->db->prepare('INSERT INTO reminders (user_id, type, title, detail, trigger_time, repeat_rule, is_done) VALUES (?,?,?,?,?,?,0)');
        $stmt->execute([$user['user_id'], $type, $title, $detail, $trigger_time, $repeat_rule]);
        return ['success' => true, 'id' => $this->db->lastInsertId()];
    }

    public function update(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $check = $this->db->prepare('SELECT id FROM reminders WHERE id = ? AND user_id = ?');
        $check->execute([$id, $user['user_id']]);
        if (!$check->fetch()) {
            http_response_code(404);
            return ['error' => '提醒不存在'];
        }
        $fields = [];
        $params = [];
        foreach (['type','title','detail','trigger_time','repeat_rule','is_done'] as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = $input[$f];
            }
        }
        if (empty($fields)) return ['error' => '无更新字段'];
        $params[] = $id;
        $this->db->prepare('UPDATE reminders SET '.implode(',', $fields).' WHERE id = ?')->execute($params);
        return ['success' => true];
    }

    public function delete(): array {
        $user = $this->getUserFromToken();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $this->db->prepare('DELETE FROM reminders WHERE id = ? AND user_id = ?')->execute([$id, $user['user_id']]);
        return ['success' => true];
    }

    public function check(): array {
        $user = $this->getUserFromToken();
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT * FROM reminders
            WHERE user_id = ? AND is_done = 0
            AND (
                (type IN ('once','repeat') AND trigger_time <= ?) OR
                type = 'permanent'
            )
            ORDER BY trigger_time ASC
        ");
        $stmt->execute([$user['user_id'], $now]);
        $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['success' => true, 'count' => count($reminders), 'data' => $reminders];
    }
}
