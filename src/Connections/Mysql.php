<?php

namespace TinyOrm\Connections;

class Mysql extends \TinyOrm\Connection {

    private $readPDO;
    private $writePDO;
    private $readHosts = [];
    private $writeHosts = [];
    private $options;
    private $tryToAvoidBindings = true;

    public function __construct($options) {

        // Validate options
        $required = ['database', 'username', 'password'];
        foreach ($required as $req) {
            if (!isset($options[$req])) {
                throw new \Exception('Missing option: ' . $req);
            }
        }
        if (!isset($options['charset'])) {
            $options['charset'] = 'utf8';
        }

        $readHosts = [];
        foreach (['read', 'write'] as $type) {
            if (!isset($options[$type]['host'])) {
                throw new \Exception('Missing option: $options["' . $type . '"]["host"]');
            }
            if (!is_array($options[$type]['host'])) {
                throw new \Exception('Option must be of type array: $options["' . $type . '"]["host"]');
            }
        }
        $this->readHosts = $options['read']['host'];
        $this->writeHosts = $options['write']['host'];

        $this->options = $options;

        return $this;
    }

    public function getPdo($type) {
        $host = null;
        if ($type === \TinyOrm\Connection::READ) {
            if (isset($this->readPDO)) {
                return $this->readPDO;
            }
            $rand = array_rand($this->readHosts);
            $host = $this->readHosts[$rand];
        }
        if ($type === \TinyOrm\Connection::WRITE) {
            if (isset($this->writePDO)) {
                return $this->writePDO;
            }
            $rand = array_rand($this->writeHosts);
            $host = $this->writeHosts[$rand];
        }
        if (!$host) {
            throw new \Exception('Cannot find a valid host in Mysql::getPdo()');
        }

        $options = $this->options;
        try {
            $pdo = new \PDO("mysql:host=" . $host . ";dbname=" . $options['database'] . ";charset=" . $options['charset'], $options['username'], $options['password'], [
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // set the PDO error mode to exception
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new \Exception($e->getMessage());
        }

        if ($type === \TinyOrm\Connection::READ) {
            $this->readPDO = $pdo;
        }
        if ($type === \TinyOrm\Connection::WRITE) {
            $this->writePDO = $pdo;
        }

        return $pdo;
    }

    public function rawQuery(int $type, string $queryStr, array $bindings = []) {

        $pdo = $this->getPdo($type);

        $query = $this->replaceQueryBindings($queryStr, $bindings);

        if (count($bindings) > 0) {
            $sth = $pdo->prepare($query->sql);
            $sth->execute($query->bindings);
        } else {
            $sth = $pdo->query($query->sql);
        }

        if ($type == \TinyOrm\Connection::READ) {
            return $sth->fetchAll(\PDO::FETCH_ASSOC);
        }

        return true;
    }

    public function runQuery(\TinyOrm\Query $query, $options = []) {
        $sql = '';
        $bindings = [];

        $modelClass = $query->modelClass;
        if (!isset($modelClass::$_table)) {
            throw new \Exception($modelClass . '::$_table is not set');
        }
        $table = $modelClass::$_table;

        $pdoType = null;
        $queryType = $query->getType();

        /////////////
        // SELECT
        /////////////
        $isCount = $options['isCount'] ?? false;
        if ($queryType == \TinyOrm\Query::TYPE_SELECT) {
            $pdoType = \TinyOrm\Connection::READ;
            $selects = $query->selects;
            if ($isCount) {
                $selects = 'COUNT(*)';
            }
            if (!$selects) {
                throw new \Exception('select() is missing the first parameter');
            }
            if (!is_string($selects)) {
                throw new \Exception('select() first parameter must be string');
            }
            $sql .= 'SELECT ' . $selects;
            $sql .= ' FROM ' . $table;
        }

        /////////////
        // INSERT
        /////////////
        if ($queryType == \TinyOrm\Query::TYPE_INSERT) {
            $pdoType = \TinyOrm\Connection::WRITE;
            $sql .= 'INSERT INTO ' . $table . ' (';
            $first = true;
            foreach ($query->insertData as $key => $value) {
                if (!$first) {
                    $sql .= ', ';
                } else {
                    $first = false;
                }
                $sql .= '`' . $key . '`';
            }
            $sql .= ') VALUES (';
            $first = true;
            foreach ($query->insertData as $key => $value) {
                if (!$first) {
                    $sql .= ', ';
                } else {
                    $first = false;
                }
                $check = $this->analyzeValue($value);
                if ($check->safe) {
                    $sql .= $check->binding;
                } else {
                    $sql .= '?';
                    $bindings[] = $check->binding;
                }
            }
            $sql .= ')';
        }

        /////////////
        // UPDATE
        /////////////
        if ($queryType == \TinyOrm\Query::TYPE_UPDATE) {
            $pdoType = \TinyOrm\Connection::WRITE;
            $sql .= 'UPDATE ' . $table . ' SET';
            $first = true;
            foreach ($query->updateData as $key => $value) {
                if (!$first) {
                    $sql .= ', ';
                } else {
                    $first = false;
                }
                $sql .= ' ' . $key . ' = ';
                $check = $this->analyzeValue($value);
                if ($check->safe) {
                    $sql .= $check->binding;
                } else {
                    $sql .= '?';
                    $bindings[] = $check->binding;
                }
            }
        }

        /////////////
        // DELETE
        /////////////
        if ($queryType == \TinyOrm\Query::TYPE_DELETE) {
            $pdoType = \TinyOrm\Connection::WRITE;
            $sql .= 'DELETE FROM ' . $table;
        }

        /////////////
        // WHERE
        /////////////
        if ($query->whereRelationGroup || $query->whereGroup) {
            $sql .= ' WHERE ';
        }
        if ($query->whereRelationGroup) {
            $tmp = $this->handleWhereGroup($query->whereRelationGroup);
            $sql .= '(' . $tmp->sql . ')';
            foreach ($tmp->bindings as $binding) {
                $bindings[] = $binding;
            }
        }
        if ($query->whereGroup) {
            if ($query->whereRelationGroup) {
                $sql .= ' AND ';
            }
            $tmp = $this->handleWhereGroup($query->whereGroup);
            $sql .= '(' . $tmp->sql . ')';
            foreach ($tmp->bindings as $binding) {
                $bindings[] = $binding;
            }
        }

        if ($query->orderBy) {
            $sql .= ' ORDER BY ' . $query->orderBy;
        }
        if ($query->limit) {
            $sql .= ' LIMIT ' . $query->limit;
        }
        if ($query->skip) {
            $sql .= ' OFFSET ' . $query->skip;
        }

        // var_dump($sql);
        $pdo = $this->getPdo($pdoType);
        $sth = $pdo->prepare($sql);
        $sth->execute($bindings);
        if ($queryType == \TinyOrm\Query::TYPE_SELECT) {
            $result = $sth->fetchAll(\PDO::FETCH_ASSOC);
            if ($isCount) {
                $counts = $result[0] ?? [];
                foreach ($counts as $k => $v) {
                    return $v;
                }
                return 0;
            }
            return $result;
        }
        if ($queryType == \TinyOrm\Query::TYPE_INSERT) {
            return $pdo->lastInsertId();
        }

        return true;
    }

    private function analyzeValue($value) {
        if (!$this->tryToAvoidBindings) {
            return (object) [
                'safe' => false,
                'binding' => $value,
            ];
        }
        if (is_string($value)) {
            $safe = strlen($value) < 50 && preg_match('/^[a-zA-Z0-9 .-:+-_%]*$/', $value);
            if ($safe) {
                $value = '"' . $value . '"';
            }
        } elseif (is_int($value)) {
            $str = $value . '';
            $safe = preg_match('/^[0-9]+$/', $str);
            if ($safe) {
                $value = $str;
            }
        } elseif (is_float($value)) {
            $str = str_replace(',', '.', $value . '');
            $safe = preg_match('/^[0-9]+\.?[0-9]*$/', $str);
            if ($safe) {
                $value = $str;
            }
        } elseif (is_bool($value)) {
            $safe = true;
            $value = $value ? '1' : '0';
        }
        if (!isset($safe)) {
            throw new \Exception('Unsupported data type: ' . gettype($value));
        }
        return (object) [
            'safe' => $safe,
            'binding' => $value,
        ];
    }

    public function replaceQueryBindings($query, $bindings) {

        $newBindings = [];
        foreach ($bindings as $key => $value) {
            $check = $this->analyzeValue($value);
            if ($check->safe) {
                $query = preg_replace('/:' . preg_quote($key, '/') . '([ $])/', $check->binding . '$1', $query);
            } else {
                $query = preg_replace('/:' . preg_quote($key, '/') . '([ $])/', '?$1', $query);
                $newBindings[] = $check->binding;
            }
        }

        return (object) [
            'sql' => $query,
            'bindings' => $newBindings,
        ];
    }

    private function handleWhereGroup($group) {
        $sql = '';
        $bindings = [];
        foreach ($group->wheres as $i => $where) {
            // Add AND | OR
            if ($i > 0) {
                if (is_object($where)) {
                    $type = $where->type;
                }
                if (is_array($where) && isset($where[0])) {
                    $type = $where[0];
                }
                if ($type == 'AND') {
                    $sql .= ' AND ';
                } elseif ($type == 'OR') {
                    $sql .= ' OR ';
                } else {
                    throw new \Exception('Invalid whereGroup type: ' . $type);
                }
            }
            // Check values
            if (is_object($where)) {
                $subGroup = $where;
                $tmp = $this->handleWhereGroup($subGroup);
                $sql .= '(' . ($tmp->sql) . ')';
                foreach ($tmp->bindings as $bind) {
                    $bindings[] = $bind;
                }
                continue;
            }
            $col = $where[1];
            $sign = $where[2];
            $value = $where[3];
            if (is_null($value)) {
                $sql .= '`' . $col . '` IS NULL';
                continue;
            }
            $sql .= '`' . $col . '` ' . $sign . ' ';

            if ($sign === 'IN') {
                $sql .= '(';
                $sqlParams = [];
                foreach ($value as $subValue) {
                    $check = $this->analyzeValue($subValue);
                    if ($check->safe) {
                        $sqlParams[] = $check->binding;
                    } else {
                        $sqlParams[] = '?';
                        $bindings[] = $check->binding;
                    }
                }
                $sql .= implode(',', $sqlParams);
                $sql .= ')';
                continue;
            }

            $check = $this->analyzeValue($value);
            if ($check->safe) {
                $sql .= $check->binding;
            } else {
                $sql .= '?';
                $bindings[] = $check->binding;
            }
        }
        return (object) [
            'sql' => $sql,
            'bindings' => $bindings,
        ];
    }
}