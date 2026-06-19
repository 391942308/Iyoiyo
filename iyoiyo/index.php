<?php
// index.php - IYOIYO 入口 & 简易路由
date_default_timezone_set('Asia/Shanghai');

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// API 路由
if (strpos($path, '/api/') === 0) {
    require __DIR__ . '/api/router.php';
    exit;
}

// 独立管理页面路由（由 config.json 中的 admin_page 指定）
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$adminPage = $config['admin_page'] ?? '';
if ($adminPage && $path === '/' . ltrim($adminPage, '/')) {
    if (empty($config['allow_admin_access'])) {
        http_response_code(403);
        echo '403 Forbidden - Admin access disabled';
        exit;
    }
    $adminFilePath = __DIR__ . '/' . $adminPage;
    if (file_exists($adminFilePath)) {
        require $adminFilePath;
        exit;
    }
}

// 静态文件服务（public 目录）
$publicDir = __DIR__ . '/public';
$requestedFile = $publicDir . $path;
if ($path === '/' || $path === '') {
    $requestedFile = $publicDir . '/index.html';
}

if (file_exists($requestedFile) && is_file($requestedFile)) {
    $ext = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
    $mimeTypes = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff2'=> 'font/woff2',
        'woff' => 'font/woff',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    readfile($requestedFile);
    exit;
}

// 404
http_response_code(404);
echo '404 Not Found';
