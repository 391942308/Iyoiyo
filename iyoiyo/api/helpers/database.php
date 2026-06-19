<?php
class Database {
    private static $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dbPath = __DIR__ . '/../../data/iyoiyo.db';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            self::$instance = new PDO('sqlite:' . $dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::initializeTables();
        }
        return self::$instance;
    }

    private static function initializeTables(): void {
        self::$instance->exec("
            CREATE TABLE IF NOT EXISTS bookmarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                category_name TEXT NOT NULL,
                title TEXT NOT NULL,
                url TEXT NOT NULL,
                icon TEXT DEFAULT '',
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                category_name TEXT NOT NULL DEFAULT '未分类',
                title TEXT NOT NULL,
                content TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS finances (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT CHECK(type IN ('income','expense')) NOT NULL,
                category TEXT NOT NULL,
                item TEXT NOT NULL,
                amount REAL NOT NULL,
                date TEXT NOT NULL,
                note TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS reminders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT CHECK(type IN ('once','repeat','permanent')) NOT NULL,
                title TEXT NOT NULL,
                detail TEXT DEFAULT '',
                trigger_time TEXT,
                repeat_rule TEXT DEFAULT '',
                is_done INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS user_settings (
                user_id INTEGER PRIMARY KEY,
                bg_home TEXT DEFAULT '',
                bg_space TEXT DEFAULT '',
                theme TEXT DEFAULT 'light',
                note_categories TEXT DEFAULT '[]',
                bookmark_categories TEXT DEFAULT '[]',
                bookmark_categories_order TEXT DEFAULT '[]',
                finance_categories TEXT DEFAULT '[]'
            );
            CREATE TABLE IF NOT EXISTS knowledge_vectors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                source_type TEXT,
                source_id INTEGER,
                chunk_text TEXT,
                vector_json TEXT
            );
            CREATE TABLE IF NOT EXISTS subscribe_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS subscribe_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS subscribe_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                category_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                url TEXT DEFAULT '',
                progress TEXT DEFAULT '',
                notes TEXT DEFAULT '',
                cover TEXT DEFAULT ''
            );
            CREATE TABLE IF NOT EXISTS subscribe_item_tags (
                item_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (item_id, tag_id)
            );
        ");
        // 并尝试补全字段
        try { self::$instance->exec("ALTER TABLE user_settings ADD COLUMN finance_categories TEXT DEFAULT '[]'"); } catch (Exception $e) {}
    }
}
