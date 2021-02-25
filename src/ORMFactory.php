<?php namespace Molecule;

use PDO;

class ORMFactory
{
    /** @var Connection */
    protected $database;

    /** @var ORM[] */
    protected $tables;

    public function __construct(array $access)
    {
        $this->database = new Connection($access);
    }

    public function database(): Connection
    {
        return $this->database;
    }

    public function __set($name, ORM $orm)
    {
        $this->tables[$name] = $orm;
    }
}
