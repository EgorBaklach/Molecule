<?php namespace Molecule;

use Helpers\Corrector;
use LogicException;
use PDO;
use PDOStatement;
use ValueError;

class ORM
{
    protected $table;

    private $receipt;
    private $fields;

    protected $conditions;
    protected $chains;
    protected $mark;

    /** @var Connection */
    private $DB;

    const mark_of_parameter = ':';


    public function __construct($table, Connection $DB)
    {
        $this->table = $table;
        $this->chains = ['references' => [$table]];
        $this->mark = 'a';
        $this->DB = $DB;
    }

    protected function crypt($field, $node, $multi = false, $spec = false): string
    {
        if(is_int($field) && is_string($node))
        {
            return $node;
        }

        switch(gettype($node))
        {
            case 'array':

                $values = [];

                foreach($node as $value)
                {
                    $values[] = $this->crypt($field, $value, true);
                }

                $value = Corrector::RoundFraming(implode(',', $values));

                break;
            default:

                $value = self::mark_of_parameter.$this->mark++;
                $this->convert($value, $node);
        }

        if(!$multi)
        {
            $value = $field.$spec.$value;
        }

        return $value;
    }

    protected function convert($name, $value): self
    {
        switch(gettype($value))
        {
            case 'NULL': return $this->bind($name, null, PDO::PARAM_NULL);
            case 'integer': return $this->bind($name, $value, PDO::PARAM_INT);
        }

        return $this->bind($name, $value, PDO::PARAM_STR);
    }

    public function conditions(array $fields, $multi = false, $spec = false): string
    {
        $this->conditions = [];

        foreach($fields as $field => $value)
        {
            $this->conditions[] = $this->crypt($field, $value, $multi, $spec);
        }

        return implode(',', $this->conditions);
    }

    public function bind($name, ...$binds): self
    {
        if(array_key_exists($name, $this->chains['binds']))
        {
            throw new LogicException("Duplicate bind parameters. Argument: $name", 500);
        }

        $this->chains['binds'][$name] = $binds;

        return $this;
    }

    public function dependence(string $table, string $type, array $reference = []): self
    {
        $references = [];

        foreach($reference as $p => $c)
        {
            $references[] = implode('', [$p, $c]);
        }

        $this->chains['dependencies'][$table] = implode(' ', [$type, 'JOIN', $table, 'ON', implode(' AND ', $references)]);
        $this->chains['references'][] = $table;

        return $this;
    }

    public function where(...$rules): self
    {
        $where = [];

        foreach($rules as $conditions)
        {
            if(!$conditions) continue;

            $this->conditions = [];

            foreach($conditions as $field => $value)
            {
                $this->conditions[] = $this->crypt($field, $value);
            }

            $where[] = implode(' AND ', $this->conditions);
        }

        if(!empty($where))
        {
            $this->chains['where'][] = Corrector::RoundFraming(implode(' OR ', $where));
        }

        return $this;
    }

    public function group(array $fields): self
    {
        $this->chains['group'] = $fields;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->chains['limit'] = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->chains['offset'] = $offset;

        return $this;
    }

    public function order(array $order): self
    {
        foreach($order as $field => $condition)
        {
            if(is_int($field))
            {
                $field = $condition;
                $condition = 'ASC';
            }

            $this->chains['order'][] = implode(' ', [$field, $condition]);
        }

        return $this;
    }

    protected function setAdditional()
    {
        if(!empty($this->chains['where']))
        {
            $this->receipt[] = 'WHERE';
            $this->receipt[] = implode(' AND ', $this->chains['where']);
        }

        if(!empty($this->chains['group']))
        {
            $this->receipt[] = 'GROUP BY';
            $this->receipt[] = implode(', ', $this->chains['group']);
        }

        if(!empty($this->chains['order']))
        {
            $this->receipt[] = 'ORDER BY';
            $this->receipt[] = implode(', ', $this->chains['order']);
        }

        if(!empty($this->chains['limit']))
        {
            $this->receipt[] = 'LIMIT';
            $this->receipt[] = $this->chains['limit'];
        }

        if(!empty($this->chains['offset']))
        {
            $this->receipt[] = 'OFFSET';
            $this->receipt[] = $this->chains['offset'];
        }
    }

    public function onDuplicate($param = false): self
    {
        if(empty($this->receipt) || empty($this->fields))
        {
            return $this;
        }

        $fields = [];

        switch(gettype($param))
        {
            case 'array': $fields = $param; break;
            case 'string': $fields = [$param => $param]; break;
            default:
                foreach($this->fields as $field)
                {
                    $fields[$field] = $param ? "values($field)" : $field;
                }
                break;
        }

        $this->receipt[] = 'ON DUPLICATE KEY UPDATE';
        $this->receipt[] = urldecode(http_build_query($fields, false, ', '));

        $this->fields = null;

        return $this;
    }

    public function select(array $fields = ['*']): self
    {
        $this->receipt = ['SELECT', implode(', ', $fields), 'FROM', $this->table];

        if(!empty($this->chains['dependencies']))
        {
            $this->receipt[] = implode(' ', $this->chains['dependencies']);
        }

        $this->setAdditional();

        return $this;
    }

    public function update(array $update): self
    {
        $this->receipt = ['UPDATE', $this->table];

        if(!empty($this->chains['dependencies']))
        {
            $this->receipt[] = implode(' ', $this->chains['dependencies']);
        }

        $this->receipt[] = 'SET';
        $this->receipt[] = $this->conditions($update, false, '=');

        $this->setAdditional();

        return $this;
    }

    public function insert(array $insert, array $fields = null): self
    {
        $this->fields = $fields ?? array_keys($insert);

        $this->receipt = ['INSERT INTO', $this->table, Corrector::RoundFraming(implode(',', $this->fields))];

        $insert = !empty($fields) ? implode(',', $insert) : Corrector::RoundFraming($this->conditions($insert, true));

        $this->receipt[] = 'VALUES';
        $this->receipt[] = $insert;

        return $this;
    }

    public function merge(array $insert, array $fields): self
    {
        return $this->insert($insert, $fields)->onDuplicate(true);
    }

    public function delete(array $fields = []): self
    {
        $this->receipt = ['DELETE', implode(',', $fields), 'FROM', $this->table];

        if(!empty($this->dependencies))
        {
            $this->receipt[] = implode(' ', $this->dependencies);
        }

        $this->setAdditional();

        return $this;
    }

    private function unifier(): string
    {
        return preg_replace_callback('/(\d):/', function($matches)
        {
            if(!array_key_exists($matches[1], $this->chains['references']))
            {
                throw new ValueError("Reference {$matches[1]}: does not exist", 501);
            }

            return $this->chains['references'][$matches[1]].'.';

        }, implode(' ', $this->receipt));
    }

    public function exec(): PDOStatement
    {
        $stmt = $this->DB->connection()->prepare($this->unifier());

        foreach($this->chains['binds'] as $value => $bind)
        {
            $stmt->bindValue($value, ...$bind);
        }

        $stmt->execute();

        $this->chains = ['references' => [$this->table]];
        $this->mark = 'a';

        return $stmt;
    }

    public function getSql(): string
    {
        return $this->unifier();
    }

    public function disconnect()
    {
        $this->DB->abortConnection();
    }
}
