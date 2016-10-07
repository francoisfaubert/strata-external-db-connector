<?php
namespace Francoisfaubert\Service\Db\Connector;

use PDO;
use PDOException;
use Exception;

use Strata\Strata;

abstract class MysqlConnector
{
    protected $servername;
    protected $dbname;
    protected $username;
    protected $password;
    protected $charset;

    private $connector;

    protected $where = array();
    protected $select = array();
    protected $from = array();
    protected $join = array();
    protected $order = array();

    private $preparedData = array();

    abstract public function init();

    public function close()
    {
        $this->connector = null;
    }

    public function connect()
    {
        try {
            if (isset($this->charset)) {
                $connectionStr = sprintf("mysql:host=%s;dbname=%s;charset=%s", $this->servername, $this->dbname, $this->charset);
            } else {
                $connectionStr = sprintf("mysql:host=%s;dbname=%s", $this->servername, $this->dbname);
            }

            $this->connector = new PDO($connectionStr, $this->username, $this->password);
            $this->connector->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            throw new Exception($e->getMessage());
            $this->close();
        }
    }

    public function select($tableVars)
    {
        $this->select[] = $tableVars;
        return $this;
    }

    public function from($tableName, $tableVariable)
    {
        $this->from[] = array($tableName, $tableVariable);
        return $this;
    }

    public function where($querie, $value)
    {
        $this->where[] = array($querie, $value);
        return $this;
    }

    public function join($whichTable, $onWhat)
    {
        $this->join[] = array($whichTable, $onWhat);
        return $this;
    }

    public function orderBy($field, $direction)
    {
        $this->order[] = array($field, $direction);
        return $this;
    }

    public function fetch()
    {
        try {
            $this->connect();
            return $this->makeSelectStatement()->fetchAll();
        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }

        $this->close();
    }

    public function first()
    {
        try {
            $this->connect();
            return $this->makeSelectStatement()->fetch();
        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }

        $this->close();
    }

    public function count()
    {
        $this->select("count(*) as row_count");

        try {
            $this->connect();
            $row = $this->makeSelectStatement()->fetch();
            return (int)$row['row_count'];
        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }

        $this->close();
    }

    public function insert($table, $entity)
    {
        $valuesSet = array();
        $columnsSet = array();
        $placeholdersSet = array();
        $attributeList = array_keys($entity->getAttributes());

        try {
            $this->connect();

            foreach ($attributeList as $attributeKey) {
                if (!is_null($entity->{$attributeKey})) {
                    $columnsSet[] = '`' . $attributeKey . '`';
                    $valuesSet[":" . $attributeKey] = $entity->{$attributeKey};
                    $placeholdersSet[] = ":" . $attributeKey;
                }
            }

            $query = sprintf(
                "INSERT INTO %s (%s) VALUES (%s);",
                $table,
                implode(", ", $columnsSet),
                implode(", ", $placeholdersSet)
            );
            $statement = $this->connector->prepare($query);

            foreach ($valuesSet as $key => $value) {
                $statement->bindValue($key, $value);
            }

            $args = count($valuesSet) ? " using: :" . var_export($valuesSet, true) : "";
            Strata::app()->log($statement->queryString . $args, "<magenta>MysqlConnector</magenta>");

            return (bool)$statement->execute();

        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }

        $this->close();
    }


    public function update($table, $entity)
    {
        $valuesSet = array();
        $columnsSet = array();
        $placeholdersSet = array();
        $attributeList = array_keys($entity->getAttributes());

        try {
            $this->connect();

            foreach ($attributeList as $attributeKey) {
                if (!is_null($entity->{$attributeKey})) {
                    $valuesSet[] = array(
                        "column" => $attributeKey,
                        "bind" => ":" . $attributeKey,
                        "value" => $entity->{$attributeKey}
                    );
                }
            }

            $updates = "";
            foreach ($valuesSet as $idx => $value) {
                $updates .= sprintf(" `%s` = %s ", $value['column'], $value['bind']);
                if ($idx < count($valuesSet) - 1) {
                    $updates .= ",";
                }
            }

            $primaryKey = $entity->getPrimaryKey();
            $query = sprintf("UPDATE %s SET %s WHERE %s = %d LIMIT 1", $table, $updates, $primaryKey, $entity->{$primaryKey});
            $statement = $this->connector->prepare($query);

            foreach ($valuesSet as $value) {
                $statement->bindValue($value['bind'], $value['value']);
            }

            $args = count($valuesSet) ? " using: :" . var_export($valuesSet, true) : "";
            Strata::app()->log($statement->queryString . $args, "<magenta>MysqlConnector</magenta>");

            return $statement->execute() > 0;

        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }

        $this->close();
    }

    private function makeSelectStatement()
    {
        $statement = $this->connector->prepare($this->buildSelectQuery());

        $args = count($this->preparedData) ? " using: " . implode(", ", $this->preparedData) : "";
        Strata::app()->log($statement->queryString . $args, "<magenta>MysqlConnector</magenta>");
        $statement->execute(count($this->preparedData) ? $this->preparedData : null);

        return $statement;
    }

    private function buildSelectQuery()
    {
        $query = "";

        if (count($this->select)) {
            $query .= "SELECT " . implode(", ", $this->select) . " FROM ";
        } else {
            $query .= "SELECT * FROM ";
        }

        foreach ($this->from as $table) {
            $query .= $table[0] . " as " . $table[1] . " ";
        }

        if (count($this->join)) {
            foreach ($this->join as $joins) {
                $query .= " LEFT JOIN " . $joins[0] . " ON " . $joins[1] . " ";
            }
        }

        if (count($this->where)) {
            $query .= " WHERE 1=1 ";
            foreach ($this->where as $conditions) {
                $query .= "AND " . $conditions[0] . " ";
                $this->preparedData[] = $conditions[1];
            }
        }

        if (count($this->order)) {
            $query .= " ORDER BY 1=1 ";
            foreach ($this->order as $order) {
                $query .= ", " . $order[0] . " " . $order[1] . " ";
            }
        }

        return $query;
    }

    protected function getCurrentLocaleCode()
    {
        $code = Strata::i18n()->getCurrentLocale()->getConfig("displayedCode");
        return strtolower($code);
    }
}
