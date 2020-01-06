<?php
/**
 * Walle\Model\Notify
 *
 * @author     <dendi875@163.com>
 * @createDate 2019-12-29 18:12:01
 * @copyright  Copyright (c) 2019 https://github.com/dendi875
 */

namespace Walle\Model;

use Walle\Modules\Database\PDOMysql;

class Notify
{
    /**
     * @var PDOMysql
     */
    protected $db;

    private static $instance;

    protected static $fieldArr = [
        'caller',
        'url',
        'method',
        'contentType',
        'data',
        'timeDelayedSend',
        'needResponse',
        'expectResponse',
        'actualResponse',
        'runOnce',
        'queueName',
        'fKey'
    ];

    private function __construct()
    {
        $this->db = PDOMysql::getInstance();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function findDelayNotify(array $params)
    {
        $objs = [];

        $stmt = $this->db->run(
            "SELECT * FROM Notify WHERE timeDelayedSend <= :timeDelayedSend ORDER BY id asc",
            [":timeDelayedSend" => $params['timeDelayedSend']]
        );

        while ($obj = $stmt->fetch()) {
            $objs[] = $obj;
        }

        return $objs;
    }

    public function getById($id)
    {
        $obj = $this->db
                    ->run("SELECT * FROM Notify WHERE id = :id", [':id' => $id])
                    ->fetch();

        return $obj;
    }

    public function getByFKey($fKey)
    {
        $obj = $this->db
                    ->run("SELECT * FROM Notify WHERE fKey = :fKey", [':fKey' => $fKey])
                    ->fetch();

        return $obj;
    }

    public function deleteById($id)
    {
        $this->db
                ->run("DELETE FROM Notify WHERE id = :id", [':id' => $id]);

        return $id;
    }

    public function add(array $params)
    {
        $result = $this->buildParams($params);
        $placeholder = array_keys($result['bindParams']);

        $this->db
                ->run("INSERT INTO Notify (".$result['fields'].") VALUES (".implode(',', $placeholder).")", $result['bindParams']);

        return $this->db->lastInsertId();
    }

    private function buildParams(array $params)
    {
        $fields = '';
        $bindParams = [];

        foreach (self::$fieldArr as $field) {
            if (!empty($params[$field])) {
                $fields .= $field.',';
                $bindParams[':'.$field] = $params[$field];
            }
        }

        $fields = rtrim($fields, ',');

        return ['fields' => $fields, 'bindParams' => $bindParams];
    }
}