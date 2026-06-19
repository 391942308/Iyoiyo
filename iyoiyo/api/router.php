<?php
// 简易 API 路由
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 路由映射表
$routes = [
    'POST /api/auth/login' => ['AuthController', 'login'],
    'POST /api/auth/register' => ['AuthController', 'register'], // 预留
    'GET /api/auth/me' => ['AuthController', 'me'],
    // 配置
    'POST /api/config/toggle-admin' => ['ConfigController', 'toggleAdminAccess'],
    'GET /api/config/ai-status' => ['ConfigController', 'aiStatus'],
    // 书签
    'GET /api/bookmarks' => ['BookmarkController', 'list'],
    'POST /api/bookmarks' => ['BookmarkController', 'add'],
    'PUT /api/bookmarks/update' => ['BookmarkController', 'update'],
    'DELETE /api/bookmarks/delete' => ['BookmarkController', 'delete'],
    'GET /api/bookmarks/categories' => ['BookmarkController', 'categories'],
    'POST /api/bookmarks/category' => ['BookmarkController', 'addCategory'],
    'PUT /api/bookmarks/category' => ['BookmarkController', 'updateCategory'],
    'DELETE /api/bookmarks/category' => ['BookmarkController', 'deleteCategory'],
    'POST /api/bookmarks/categories/order' => ['BookmarkController', 'saveOrder'],
    // 个人配置
    'POST /api/config/avatar' => ['ConfigController', 'uploadAvatar'],
    'POST /api/config/bg-home' => ['ConfigController', 'uploadBgHome'],
    'POST /api/config/bg-space' => ['ConfigController', 'uploadBgSpace'],
    'POST /api/config/password' => ['ConfigController', 'changePassword'],
    'GET /api/config/settings' => ['ConfigController', 'getSettings'],
    'POST /api/config/settings' => ['ConfigController', 'saveSettings'],
    // 笔记
    'GET /api/notes' => ['NoteController', 'list'],
    'POST /api/notes' => ['NoteController', 'add'],
    'PUT /api/notes/update' => ['NoteController', 'update'],
    'DELETE /api/notes/delete' => ['NoteController', 'delete'],
    'GET /api/notes/categories' => ['NoteController', 'getCategories'],
    'POST /api/notes/categories' => ['NoteController', 'saveCategories'],
    // 记账
    'GET /api/finances' => ['FinanceController', 'list'],
    'POST /api/finances' => ['FinanceController', 'add'],
    'PUT /api/finances/update' => ['FinanceController', 'update'],
    'DELETE /api/finances/delete' => ['FinanceController', 'delete'],
    'GET /api/finances/categories' => ['FinanceController', 'categories'],
    'POST /api/finances/categories' => ['FinanceController', 'saveCategories'],
    'PUT /api/finances/category' => ['FinanceController', 'updateCategory'],
    'DELETE /api/finances/category' => ['FinanceController', 'deleteCategory'],
    'GET /api/finances/stats' => ['FinanceController', 'stats'],
    // 提醒
    'GET /api/reminders' => ['ReminderController', 'list'],
    'POST /api/reminders' => ['ReminderController', 'add'],
    'PUT /api/reminders/update' => ['ReminderController', 'update'],
    'DELETE /api/reminders/delete' => ['ReminderController', 'delete'],
    'GET /api/reminders/check' => ['ReminderController', 'check'],
    // 订阅
    // 订阅分类
    'GET /api/subscribe/categories' => ['SubscribeController', 'getCategories'],
    'POST /api/subscribe/categories' => ['SubscribeController', 'addCategory'],
    'PUT /api/subscribe/categories' => ['SubscribeController', 'updateCategory'],
    'DELETE /api/subscribe/categories' => ['SubscribeController', 'deleteCategory'],
    // 订阅标签
    'GET /api/subscribe/tags' => ['SubscribeController', 'getTags'],
    'POST /api/subscribe/tags' => ['SubscribeController', 'addTag'],
    'PUT /api/subscribe/tags' => ['SubscribeController', 'updateTag'],
    'DELETE /api/subscribe/tags' => ['SubscribeController', 'deleteTag'],
    // 订阅作品
    'GET /api/subscribe/items' => ['SubscribeController', 'getItems'],
    'GET /api/subscribe/items/detail' => ['SubscribeController', 'getItemDetail'],
    'POST /api/subscribe/items' => ['SubscribeController', 'addItem'],
    'PUT /api/subscribe/items/update' => ['SubscribeController', 'updateItem'],
    'DELETE /api/subscribe/items/delete' => ['SubscribeController', 'deleteItem'],
    // 上传封面
    'POST /api/subscribe/upload-cover' => ['SubscribeController', 'uploadCover'],

    // ai
    'POST /api/ai/chat' => ['AIController', 'chat'],
];

// 解析当前请求
$currentRoute = $method . ' ' . $uri;
if (!isset($routes[$currentRoute])) {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found']);
    exit;
}

// 加载控制器
$handler = $routes[$currentRoute];
$controllerName = $handler[0];
$action = $handler[1];

require_once __DIR__ . '/controllers/' . $controllerName . '.php';
$controller = new $controllerName();
$result = $controller->$action();
echo json_encode($result);
