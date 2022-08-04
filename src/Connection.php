<?php namespace Molecule;

use PDO;

class Connection
{
    /** @var string */
    private $user;

    /** @var string */
    private $pass;

    /** @var string */
    private $query;

    /** @var array */
    private $options;

    const type = 'mysql';

    /** @var PDO */
    private $connection;

    public function __construct(string $host, string $user, string $pass, ?string $db = null, ?string $charset = null)
    {
        $this->user = $user;
        $this->pass = $pass;

        $this->query = http_build_query(array_filter(['host' => $host, 'dbname' => $db, 'charset' => $charset]), '', ';');

        $this->options = [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE  => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_EMULATE_PREPARES => false
        ];

        if($charset) $this->options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES '.$charset;
    }

    public function query(array $query): self
    {
        $this->query = http_build_query($query, '', ';'); return $this;
    }

    public function options(array $options): self
    {
        $this->options = $options; return $this;
    }

    public function connection(): PDO
    {
        if(!$this->connection instanceof PDO) $this->connection = new PDO(static::type.':'.$this->query, $this->user, $this->pass, $this->options);

        return $this->connection;
    }

    public function abort(): self
    {
        $this->connection = null; return $this;
    }
}