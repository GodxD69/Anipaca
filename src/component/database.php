<?php
/**
 * Unified Database Handler for AniPaca
 * Automatically connects to MySQL if available, or falls back to a self-initializing SQLite database.
 */

if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
    define('MYSQLI_NUM', 2);
    define('MYSQLI_BOTH', 3);
}

class SQLiteResultWrapper {
    private $rows;
    private $index = 0;
    public $num_rows = 0;

    public function __construct(array $rows) {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc() {
        if ($this->index < $this->num_rows) {
            $row = $this->rows[$this->index++];
            $assoc = [];
            foreach ($row as $k => $v) {
                if (!is_int($k)) {
                    $assoc[$k] = $v;
                }
            }
            return $assoc;
        }
        return null;
    }

    public function fetch_row() {
        if ($this->index < $this->num_rows) {
            $row = $this->rows[$this->index++];
            $num = [];
            foreach ($row as $k => $v) {
                if (is_int($k)) {
                    $num[$k] = $v;
                }
            }
            return array_values($num);
        }
        return null;
    }

    public function fetch_array($mode = MYSQLI_BOTH) {
        if ($this->index < $this->num_rows) {
            $row = $this->rows[$this->index++];
            if ($mode === MYSQLI_ASSOC) {
                $res = [];
                foreach ($row as $k => $v) {
                    if (!is_int($k)) $res[$k] = $v;
                }
                return $res;
            } elseif ($mode === MYSQLI_NUM) {
                $res = [];
                foreach ($row as $k => $v) {
                    if (is_int($k)) $res[] = $v;
                }
                return $res;
            }
            return $row;
        }
        return null;
    }

    public function fetch_all($mode = MYSQLI_ASSOC) {
        $all = [];
        while ($r = $this->fetch_assoc()) {
            $all[] = $r;
        }
        return $all;
    }

    public function free() {
        $this->rows = [];
        $this->num_rows = 0;
    }
}

class SQLiteStmtWrapper {
    private $pdo;
    private $stmt;
    private $params = [];
    public $insert_id = 0;
    public $affected_rows = 0;
    public $error = '';
    private $executedResult = null;

    public function __construct(PDO $pdo, string $sql) {
        $this->pdo = $pdo;
        // Transform MySQL-specific functions if needed
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now', 'localtime')", $sql);
        try {
            $this->stmt = $this->pdo->prepare($sql);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->stmt = null;
        }
    }

    public function bind_param($types, &...$args) {
        $this->params = [];
        foreach ($args as $k => &$arg) {
            $this->params[] = &$arg;
        }
        return true;
    }

    public function execute() {
        if (!$this->stmt) return false;
        try {
            $boundValues = [];
            foreach ($this->params as $idx => &$val) {
                $boundValues[] = $val;
            }
            $ok = $this->stmt->execute(!empty($boundValues) ? $boundValues : null);
            if ($ok) {
                $this->affected_rows = $this->stmt->rowCount();
                $this->insert_id = (int)$this->pdo->lastInsertId();
                if ($this->stmt->columnCount() > 0) {
                    $this->executedResult = $this->stmt->fetchAll(PDO::FETCH_BOTH);
                }
            }
            return $ok;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function get_result() {
        if ($this->executedResult !== null) {
            return new SQLiteResultWrapper($this->executedResult);
        }
        if ($this->stmt && $this->stmt->columnCount() > 0) {
            try {
                $rows = $this->stmt->fetchAll(PDO::FETCH_BOTH);
                return new SQLiteResultWrapper($rows);
            } catch (PDOException $e) {
                return new SQLiteResultWrapper([]);
            }
        }
        return new SQLiteResultWrapper([]);
    }

    public function close() {
        $this->stmt = null;
        return true;
    }
}

class AnipacaDatabase {
    public $connect_error = null;
    public $error = '';
    public $insert_id = 0;
    public $affected_rows = 0;
    private $pdo = null;
    private $mysqli = null;
    private $isSQLite = false;

    public function __construct($host = "localhost", $user = "root", $pass = "", $db = "anipaca") {
        @mysqli_report(MYSQLI_REPORT_OFF);
        
        // Check for environment variables (Vercel / Cloud MySQL)
        $host = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: $host;
        $user = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: $user;
        $pass = getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: $pass;
        $db   = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: $db;
        $port = (int)(getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: 3306);
        
        // Try MySQL first
        try {
            $m = @new mysqli($host, $user, $pass, null, $port);
            if (!$m->connect_error) {
                @$m->query("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                if ($m->select_db($db)) {
                    $m->set_charset("utf8mb4");
                    $this->mysqli = $m;
                    $this->autoInitMySQL();
                    return;
                }
            }
        } catch (Throwable $e) {
            // MySQL unavailable
        }

        // Fallback to SQLite (with /tmp fallback for Vercel serverless)
        $this->isSQLite = true;
        $dbDir = __DIR__ . '/../../data';
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0777, true);
        }
        $dbPath = $dbDir . '/anipaca.sqlite';
        // If data dir is not writable (e.g. Vercel read-only filesystem), use sys_get_temp_dir()
        if (!is_writable($dbDir) && !@touch($dbPath)) {
            $dbPath = sys_get_temp_dir() . '/anipaca.sqlite';
        }
        try {
            $this->pdo = new PDO('sqlite:' . $dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->sqliteCreateFunction('NOW', function() {
                return date('Y-m-d H:i:s');
            });
            $this->autoInitSQLite();
        } catch (Throwable $e) {
            $this->connect_error = $e->getMessage();
        }
    }

    private function autoInitMySQL() {
        if (!$this->mysqli) return;
        $res = $this->mysqli->query("SHOW TABLES LIKE 'users'");
        if ($res && $res->num_rows === 0) {
            $sqlFile = __DIR__ . '/../../database.sql';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                $this->mysqli->multi_query($sql);
                while ($this->mysqli->more_results() && $this->mysqli->next_result()) {
                    if ($r = $this->mysqli->store_result()) $r->free();
                }
            }
        }
    }

    private function autoInitSQLite() {
        if (!$this->pdo) return;
        $schema = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            avatar_url TEXT DEFAULT NULL,
            anime_count INTEGER DEFAULT 0,
            anime_episodes INTEGER DEFAULT 0,
            manga_count INTEGER DEFAULT 0,
            manga_chapters INTEGER DEFAULT 0,
            email TEXT NOT NULL,
            password TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            image TEXT DEFAULT NULL,
            custom_avatar INTEGER DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            anime_id TEXT NOT NULL,
            episode_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            is_spoiler INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            username TEXT DEFAULT NULL,
            user_avatar TEXT DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS comment_reactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            comment_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            type INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(comment_id, user_id)
        );

        CREATE TABLE IF NOT EXISTS watched_episode (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            anime_id TEXT NOT NULL,
            anilist_id INTEGER DEFAULT NULL,
            episodes_watched TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, anime_id)
        );

        CREATE TABLE IF NOT EXISTS watchlist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            anime_id TEXT DEFAULT NULL,
            anilist_id INTEGER DEFAULT NULL,
            anime_name TEXT NOT NULL,
            type INTEGER NOT NULL,
            poster TEXT DEFAULT NULL,
            sub_count INTEGER DEFAULT NULL,
            dub_count INTEGER DEFAULT NULL,
            anime_type TEXT DEFAULT NULL,
            duration TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, anime_id)
        );

        CREATE TABLE IF NOT EXISTS watch_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            anime_id TEXT NOT NULL,
            anime_name TEXT NOT NULL,
            poster TEXT DEFAULT NULL,
            sub_count INTEGER DEFAULT NULL,
            dub_count INTEGER DEFAULT NULL,
            anilist_id TEXT DEFAULT NULL,
            episode_number INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            watched_episodes TEXT DEFAULT NULL,
            UNIQUE(user_id, anime_id)
        );

        CREATE TABLE IF NOT EXISTS pageview (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pageID TEXT NOT NULL,
            totalview INTEGER NOT NULL,
            like_count INTEGER NOT NULL,
            dislike_count INTEGER NOT NULL,
            animeID TEXT NOT NULL
        );
        ";
        $this->pdo->exec($schema);
    }

    public function query($sql) {
        if ($this->mysqli) {
            $r = $this->mysqli->query($sql);
            $this->error = $this->mysqli->error;
            $this->insert_id = $this->mysqli->insert_id;
            $this->affected_rows = $this->mysqli->affected_rows;
            return $r;
        }
        if (!$this->pdo) return false;
        try {
            $sql = preg_replace('/\bNOW\(\)/i', "datetime('now', 'localtime')", $sql);
            $stmt = $this->pdo->query($sql);
            if ($stmt) {
                $this->insert_id = (int)$this->pdo->lastInsertId();
                $this->affected_rows = $stmt->rowCount();
                $rows = $stmt->fetchAll(PDO::FETCH_BOTH);
                return new SQLiteResultWrapper($rows);
            }
            return false;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function prepare($sql) {
        if ($this->mysqli) {
            return $this->mysqli->prepare($sql);
        }
        if (!$this->pdo) return false;
        return new SQLiteStmtWrapper($this->pdo, $sql);
    }

    public function real_escape_string($str) {
        if ($this->mysqli) return $this->mysqli->real_escape_string((string)$str);
        return addslashes((string)$str);
    }

    public function escape_string($str) {
        return $this->real_escape_string($str);
    }

    public function begin_transaction() {
        if ($this->mysqli) return $this->mysqli->begin_transaction();
        if ($this->pdo) return $this->pdo->beginTransaction();
        return false;
    }

    public function commit() {
        if ($this->mysqli) return $this->mysqli->commit();
        if ($this->pdo) return $this->pdo->commit();
        return false;
    }

    public function rollback() {
        if ($this->mysqli) return $this->mysqli->rollback();
        if ($this->pdo) return $this->pdo->rollBack();
        return false;
    }

    public function close() {
        if ($this->mysqli) return $this->mysqli->close();
        $this->pdo = null;
        return true;
    }

    public function getMysqli() {
        return $this->mysqli;
    }

    public function isSQLiteMode() {
        return $this->isSQLite;
    }
}
