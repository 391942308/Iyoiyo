<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/database.php';

class AIController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function getUserFromToken(): array
    {
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

    public function chat(): array
    {
        $user = $this->getUserFromToken();
        $userId = $user['user_id'];

        $input = json_decode(file_get_contents('php://input'), true);
        $message = $input['message'] ?? '';
        $history = $input['history'] ?? [];

        if (!$message) return ['error' => '消息不能为空'];

        $config = json_decode(file_get_contents(__DIR__ . '/../../config.json'), true);
        $aiConfig = $config['ai'] ?? [];
        if (empty($aiConfig['enabled'])) return ['success' => true, 'reply' => 'AI 助理未启用。'];

        $provider = $aiConfig['provider'] ?? 'openai';
        $model    = $aiConfig['model'] ?? 'gpt-3.5-turbo';
        $apiKey   = $aiConfig['api_key'] ?? '';
        $baseUrl  = rtrim($aiConfig['base_url'] ?? 'https://api.openai.com/v1', '/');

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $dayOfWeek = date('N');

        // 获取用户已有记账分类
        $categories = $this->getFinanceCategories($userId);
        $catList = !empty($categories) ? implode('、', $categories) : '无';

        // 极其严格的系统提示，包含已有分类
        $systemPrompt = "你是 IYOIYO 个人助理“小凪”。当前日期：{$today}，时间：{$now}，星期{$dayOfWeek}。\n";
        $systemPrompt .= "当前用户的记账分类有：{$catList}。\n";
        $systemPrompt .= "当用户要求执行操作时，你必须**只输出一个 JSON 对象**，没有任何其他字符。格式：\n";
        $systemPrompt .= "{\"action\": \"操作名\", \"parameters\": {参数}}\n";
        $systemPrompt .= "允许的操作及参数：\n";
        $systemPrompt .= "- list_notes: 查看笔记 (无参数)\n";
        $systemPrompt .= "- create_note: 创建笔记 (title必填, content可选, category可选, 默认\"未分类\")\n";
        $systemPrompt .= "- update_note: 更新笔记 (id必填, title, content, category可选)\n";
        $systemPrompt .= "- delete_note: 删除笔记 (id必填)\n";
        $systemPrompt .= "- list_finances: 查看记账 (可选 start, end, 格式YYYY-MM-DD)\n";
        $systemPrompt .= "- add_finance: 添加记账 (type必填(income/expense), item必填, amount必填(数字), category可选(优先使用已有分类{$catList}，若无法匹配则用\"其他\"), date可选(YYYY-MM-DD), note可选)\n";
        $systemPrompt .= "- list_reminders: 查看提醒 (无参数)\n";
        $systemPrompt .= "- add_reminder: 添加提醒 (title必填, type必填(once/repeat/permanent), detail可选, trigger_time可选(YYYY-MM-DD HH:MM), repeat_rule必填当type为repeat时(daily/weekly/monthly))\n";
        $systemPrompt .= "  示例：\n";
        $systemPrompt .= "  用户：明天下午3点开会 → {\"action\":\"add_reminder\",\"parameters\":{\"title\":\"开会\",\"type\":\"once\",\"trigger_time\":\"2026-06-20 15:00\"}}\n";
        $systemPrompt .= "  用户：每周三下午3点提醒我 → {\"action\":\"add_reminder\",\"parameters\":{\"title\":\"每周三提醒\",\"type\":\"repeat\",\"repeat_rule\":\"weekly\",\"trigger_time\":\"2026-06-24 15:00\"}}\n";
        $systemPrompt .= "  用户：永久提醒我多喝水 → {\"action\":\"add_reminder\",\"parameters\":{\"title\":\"多喝水\",\"type\":\"permanent\"}}\n";
        $systemPrompt .= "- list_subscriptions: 查看订阅 (可选category_id)\n";
        $systemPrompt .= "- add_subscription: 添加订阅 (category_id必填, title必填, url可选, progress可选, notes可选, tag_ids可选(整数数组))\n";
        $systemPrompt .= "如果不是操作请求，请正常回复。";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $response = $this->callLLM($provider, $baseUrl, $apiKey, $model, $messages);

        // 调试日志
        file_put_contents(__DIR__ . '/../../ai_debug.log', date('Y-m-d H:i:s') . " User: $message\nAI raw: $response\n\n", FILE_APPEND);

        $json = $this->extractJson($response);

        if ($json && isset($json['action'])) {
            $result = $this->executeTool($json['action'], $json['parameters'] ?? [], $userId);

            $messages[] = ['role' => 'assistant', 'content' => $response];
            $messages[] = ['role' => 'user', 'content' => '工具执行结果：' . json_encode($result, JSON_UNESCAPED_UNICODE) . '。请根据结果用友好的语气回复用户，不要返回 JSON。'];
            $finalReply = $this->callLLM($provider, $baseUrl, $apiKey, $model, $messages);
            return ['success' => true, 'reply' => $finalReply];
        }

        return ['success' => true, 'reply' => $response];
    }

    /**
     * 获取用户已有记账分类
     */
    private function getFinanceCategories(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT category FROM finances WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * 强化 JSON 提取：移除 <think>、markdown 代码块，尝试匹配完整 JSON 对象
     */
    private function extractJson(string $text): ?array
    {
        // 移除思考标签
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
        // 移除 markdown 代码块标记
        $text = preg_replace('/```(?:json)?\s*/', '', $text);
        $text = str_replace('```', '', $text);
        $text = trim($text);

        // 直接尝试解析
        $json = json_decode($text, true);
        if (is_array($json) && isset($json['action'])) return $json;

        // 提取第一个包含 "action" 的 JSON 对象
        if (preg_match('/\{[^{}]*"action"\s*:\s*"[^"]+"[^{}]*\}/s', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (is_array($json) && isset($json['action'])) return $json;
        }

        // 逐个尝试所有可能的 JSON 对象
        if (preg_match_all('/\{[^{}]+\}/s', $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $json = json_decode($candidate, true);
                if (is_array($json) && isset($json['action'])) return $json;
            }
        }

        return null;
    }

    private function callLLM($provider, $baseUrl, $apiKey, $model, $messages): string
    {
        if ($provider === 'ollama') {
            $url = $baseUrl . '/api/chat';
            $postData = json_encode([
                'model'    => $model,
                'messages' => $messages,
                'stream'   => false
            ]);
            $headers = "Content-Type: application/json\r\n";
        } else {
            $url = $baseUrl . '/chat/completions';
            $postData = json_encode([
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => 0.7
            ]);
            $headers = "Content-Type: application/json\r\nAuthorization: Bearer $apiKey\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => $headers,
                'content' => $postData,
                'timeout' => 30,
                'ignore_errors' => true,
            ]
        ]);

        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) return '无法连接到 AI 服务。';

        $data = json_decode($resp, true);
        if (!$data) return 'AI 返回异常。';

        if ($provider === 'ollama') {
            return $data['message']['content'] ?? '...';
        } else {
            return $data['choices'][0]['message']['content'] ?? '...';
        }
    }

    private function executeTool(string $name, array $args, int $userId): array
    {
        try {
            switch ($name) {
                case 'list_notes':
                    $stmt = $this->db->prepare('SELECT * FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
                    $stmt->execute([$userId]);
                    return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];

                case 'create_note':
                    $stmt = $this->db->prepare("INSERT INTO notes (user_id, category_name, title, content, updated_at) VALUES (?,?,?,?, datetime('now'))");
                    $stmt->execute([$userId, $args['category'] ?? '未分类', $args['title'], $args['content'] ?? '']);
                    return ['success' => true, 'id' => $this->db->lastInsertId()];

                case 'update_note':
                    $stmt = $this->db->prepare("UPDATE notes SET title=?, content=?, category_name=?, updated_at=datetime('now') WHERE id=? AND user_id=?");
                    $stmt->execute([$args['title'] ?? '', $args['content'] ?? '', $args['category'] ?? '未分类', $args['id'], $userId]);
                    return ['success' => true];

                case 'delete_note':
                    $this->db->prepare('DELETE FROM notes WHERE id=? AND user_id=?')->execute([$args['id'], $userId]);
                    return ['success' => true];

                case 'list_finances':
                    $sql = 'SELECT * FROM finances WHERE user_id=?';
                    $params = [$userId];
                    if (!empty($args['start'])) { $sql .= ' AND date >= ?'; $params[] = $args['start']; }
                    if (!empty($args['end'])) { $sql .= ' AND date <= ?'; $params[] = $args['end']; }
                    $sql .= ' ORDER BY date DESC';
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($params);
                    return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];

                case 'add_finance':
                    $stmt = $this->db->prepare('INSERT INTO finances (user_id, type, category, item, amount, date, note) VALUES (?,?,?,?,?,?,?)');
                    $stmt->execute([$userId, $args['type'], $args['category'] ?? '其他', $args['item'], $args['amount'], $args['date'] ?? date('Y-m-d'), $args['note'] ?? '']);
                    return ['success' => true, 'id' => $this->db->lastInsertId()];

                case 'list_reminders':
                    $stmt = $this->db->prepare('SELECT * FROM reminders WHERE user_id=? ORDER BY trigger_time');
                    $stmt->execute([$userId]);
                    return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];

                case 'add_reminder':
                    $type = $args['type'] ?? 'once';
                    $triggerTime = $args['trigger_time'] ?? null;
                    if ($triggerTime && strpos($triggerTime, 'T') !== false) {
                        $triggerTime = str_replace('T', ' ', $triggerTime);
                    }
                    $repeatRule = $args['repeat_rule'] ?? '';
                    $stmt = $this->db->prepare('INSERT INTO reminders (user_id, type, title, detail, trigger_time, repeat_rule, is_done) VALUES (?,?,?,?,?,?,0)');
                    $stmt->execute([$userId, $type, $args['title'], $args['detail'] ?? '', $triggerTime, $repeatRule]);
                    return ['success' => true, 'id' => $this->db->lastInsertId()];

                case 'list_subscriptions':
                    $sql = 'SELECT * FROM subscribe_items WHERE user_id=?';
                    $params = [$userId];
                    if (!empty($args['category_id'])) { $sql .= ' AND category_id=?'; $params[] = $args['category_id']; }
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($params);
                    return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];

                case 'add_subscription':
                    $stmt = $this->db->prepare('INSERT INTO subscribe_items (user_id, category_id, title, url, progress, notes) VALUES (?,?,?,?,?,?)');
                    $stmt->execute([$userId, $args['category_id'], $args['title'], $args['url'] ?? '', $args['progress'] ?? '', $args['notes'] ?? '']);
                    $itemId = $this->db->lastInsertId();
                    if (!empty($args['tag_ids']) && is_array($args['tag_ids'])) {
                        $tagStmt = $this->db->prepare('INSERT INTO subscribe_item_tags (item_id, tag_id) VALUES (?,?)');
                        foreach ($args['tag_ids'] as $tid) {
                            $tagStmt->execute([$itemId, $tid]);
                        }
                    }
                    return ['success' => true, 'id' => $itemId];

                default:
                    return ['error' => '未知操作'];
            }
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
