<?php
/**
 * Walle\Modules\Database\PDOMysql
 *
 * @author     <dendi875@163.com>
 * @createDate 2019-12-29 18:12:56
 * @copyright  Copyright (c) 2019 https://github.com/dendi875
 */

namespace Walle\Modules\Database;

use PDO;

class PDOMysql
{
    protected static $instance;
    protected $pdo;

    private function __construct()
    {
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // 错误的模式
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ, // 提取模式为对象
        ];

        $dsn = 'mysql:host='.DB_HOSTNAME.';dbname='.DB_DATABASE.';charset='.DB_CHARSET;
        $this->pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $opt);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 一个 POD 的代理方法
     *
     * @param $method
     * @param $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->pdo, $method], $args);
    }

    /**
     * 一个帮助方法，能够方便的运行准备好的语句
     *
     * @param $sql
     * @param array $args
     * @return bool|\PDOStatement
     */
    public function run($sql, $args = [])
    {
        if (!$args) {
            return $this->pdo->query($sql);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);

        return $stmt;
    }
}