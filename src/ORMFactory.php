<?php namespace Molecule;

use PDO;

abstract class ORMFactory
{
    /** @var ORM[] */
    protected $tables;

    public function __construct(Connection $connection)
    {
        $rs = $connection->connection()->query("show tables"); while($table = $rs->fetch(PDO::FETCH_COLUMN)) $this->tables[$table] = new ORM($table, $connection);
    }

    public function table(string $name): ORM
    {
        return $this->tables[$name];
    }
}