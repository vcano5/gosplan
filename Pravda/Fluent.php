<?php

declare(strict_types=1);
namespace Pravda;

// Borrador mutable que se arma antes de llamar a build

class Fluent {

    private const OPERATORS = [
        "=", "!=", "<>", "<", ">", "<=", ">=", "LIKE", "NOT LIKE", "IN", "NOT IN", "IS NULL", "IS NOT NULL", "BETWEEN", "NOT BETWEEN"
    ];

    private const JOIN_TYPES = ["INNER", "LEFT", "RIGHT", "CROSS"];

    private string $table;
    private string $operation = "SELECT";
    private array $fields = ["*"];
    private array $data = [];
    private array $conditions = [];
    private array $joins = [];
    private array $ordering = [];
    private array $grouping = [];
    private array $havingClauses = [];
    private ?int $limitVal = null;
    private ?int $offsetVal = null;
    private bool $distinctVal = false;

    public function __construct(string $table) {
    $this->table = $table;
    }

    public static function table(string $table): static {
        return new static($table);
    }

    public static function insert(string $table, array $data): static {
        $instance = new static($table);
        $instance->operation = "INSERT";
        $instance->data = $data;
        return $instance;
    }

    public static function update(string $table, array $data): static {
        $instance = new static($table);
        $instance->operation = "UPDATE";
        $instance->data = $data;
        return $instance;
    }

    public static function delete(string $table): static {
        $instance = new static($table);
        $instance->operation = "DELETE";
        return $instance;
    }

    public function select(array $fields): static {
        $this->fields = $fields;
        return $this;
    }

    public function distinct(): static {
        $this->distinctVal = true;
        return $this;
    }

    public function where(string|callable $column, mixed $operatorOrValue = null, mixed $value = null): static {
        return $this->addCondition("AND", $column, $operatorOrValue, $value);
    }

    public function orWhere(string|callable $column, mixed $operatorOrValue = null, mixed $value = null): static {
        return $this->addCondition("OR", $column, $operatorOrValue, $value);
    }
    
    public function whereNull(string $column): static {
        $this->conditions[] = [
            "type" => "null",
            "column" => $column,
            "not" => false,
            "boolean" => "AND"
        ];
        return $this;
    }

    public function whereNotNull(string $column): static {
        $this->conditions[] = [
            "type" => "null",
            "column" => $column,
            "not" => true,
            "boolean" => "AND",
        ];
        return $this;
    }

    public function whereIn(string $column, array $values): static {
        $this->conditions[] = [
            "type" => "in",
            "column" => $column,
            "values" => $values,
            "not" => false,
            "boolean" => "AND"
        ];
        return $this;
    }

    public function whereNotIn(string $column, array $values): static {
        $this->conditions[] = [
            "type" => "in",
            "column" => $column,
            "values" => $values,
            "not" => true,
            "boolean" => "AND"
        ];
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static {
        $this->conditions[] = [
            "type" => "between",
            "column" => $column,
            "from" => $from,
            "to" => $to,
            "not" => false,
            "boolean" => "AND"
        ];
        return $this;
    }

    public function whereNotBetween(string $column, mixed $from, mixed $to): static {
        $this->conditions[] = [
            "type" => "between",
            "column" => $column,
            "from" => $from,
            "to" => $to,
            "not" => true,
            "boolean" => "AND"
        ];
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): static {
        $this->conditions[] = [
            "type" => "raw",
            "sql" => $sql,
            "bindings" => $bindings,
            "boolean" => "AND"
        ];
        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): static {
        $this->conditions[] = [
            "type" => "raw",
            "sql" => $sql,
            "bindings" => $bindings,
            "boolean" => "OR"
        ];
        return $this;
    }

    public function join(string $table, string $localKey, string $foreignKey, string $type = "INNER"): static {
        $type = strtoupper($type);

        if(!in_array($type, self::JOIN_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de JOIN inválido: {$type}");
        }

        $this->joins[] = [
            "type" => $type,
            "table" => $table,
            "localKey" => $localKey,
            "foreignKey" => $foreignKey
        ];
        return $this;
    }

    public function leftJoin(string $table, string $localKey, string $foreignKey): static {
        return $this->join($table, $localKey, $foreignKey, "LEFT");
    }

    public function rightJoin(string $table, string $localKey, string $foreingKey): static {
        return $this->join($table, $localKey, $foreignKey, "RIGHT");
    }

    public function crossJoin(string $table): static {
        $this->joins[] = [
            "type" => "CROSS",
            "table" => $table
        ];
        return $this;
    }

    public function groupBy(string|array $columns): static {
        $this->grouping = array_merge($this->grouping, is_array($columns) ? $columns: [$columns]);
        return $this;
    }

    public function having(string $column, mixed $operatorOrValue, mixed $value = null): static {
        [$operator, $resolvedValue] = $this->resolveOperatorAndValue($operatorOrValue, $value);
        $this->havingClauses[] = [
            "column" => $column,
            "operator" => $operator,
            "value" => $resolvedValue,
            "boolean" => "AND"
        ];
        return $this;
    }

    public function orHaving(string $column, mixed $operatorOrValue, mixed $value = null): static {
        [$operator, $resolvedValue] = $this->resolveOperatorAndValue($operatorOrValue, $value);
        $this->havingClauses[] = [
            "column" => $column,
            "operator" => $operator,
            "value" => $resolvedValue,
            "boolean" => "OR"
        ];
        return $this;
    }

    public function orderBy(string|array $column, string $direction = "ASC"): static {
        $direction = strtoupper($direction);
        if(!in_array($direction, ["ASC", "DESC"], true)) {
            throw new \InvalidArgumentException("Dirección invalida: {$direction}");
        }
        if(is_array($column)) {
            foreach($column as $col => $dir) {
                $this->ordering[] = ["column" => $col, "direction" => strtoupper($dir)];
            }
        }
        else {
            $this->ordering[] = ["column" => $column, "direction" => $direction];
        }
        return $this;
    }

    public function orderByDesc(string $column): static {
        return $this->orderBy($column, "DESC");
    }

    public function limit(int $limit): static {
        $this->limitVal = $limit;
        return $this;
    }

    public function offset(int $offset): static {
        $this->offsetVal = $offset;
        return $this;
    }

    public function paginate(int $page, int $perPage = 15): static {
        $this->limitVal = $perPage;
        $this->offsetVal = ($page - 1) * $perPage;
        return $this;
    }

    public function build(): Query {
        return Builder::compile($this);
    }



    public function getTable(): string       { return $this->table; }
    public function getOperation(): string   { return $this->operation; }
    public function getFields(): array       { return $this->fields; }
    public function getData(): array         { return $this->data; }
    public function getConditions(): array   { return $this->conditions; }
    public function getJoins(): array        { return $this->joins; }
    public function getOrdering(): array     { return $this->ordering; }
    public function getGrouping(): array     { return $this->grouping; }
    public function getHaving(): array       { return $this->havingClauses; }
    public function getLimit(): ?int         { return $this->limitVal; }
    public function getOffset(): ?int        { return $this->offsetVal; }
    public function isDistinct(): bool       { return $this->distinctVal; }

    private function addCondition(string $boolean, string|callable $column, mixed $operatorOrValue, mixed $value): static {
        if(is_callable($column)) {
            $nested = new static($this->table);
            $column($nested);

            $this->conditions[] = [
                "type" => "group",
                "boolean" => $boolean,
                "clauses" => $nested->getConditions()
            ];
            return $this;
        }
        [$operator, $resolvedValue] = $this->resolveOperatorAndValue($operatorOrValue, $value);

        $this->conditions[] = [
            "type" => "basic",
            "column" => $column,
            "operator" => $operator,
            "value" => $resolvedValue,
            "boolean" => $boolean
        ];
        return $this;
    }

    private function resolveOperatorAndValue(mixed $operatorOrValue, mixed $value): array {
        if($value === null) {
            return ["=", $operatorOrValue];
        }
        $operator = strtoupper((string) $operatorOrValue);

        if(!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Operador inválido: {$operator}");
        }
        return [$operator, $value];
    }
}