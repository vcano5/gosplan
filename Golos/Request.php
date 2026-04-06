<?php

declare(strict_types=1);

// Query params -> Fluent

class Request {
    private string $table;
    private array  $schema;
    private array  $fields   = [];
    private array  $with     = [];
    private array  $filters  = [];
    private array  $sorting  = [];
    private ?int   $page     = null;
    private int    $limit    = 15;
    private ?string $id      = null;
    private string $project;

    // Operadores permitidos desde URL
    private const OPERATORS = [
        'eq'   => '=',
        'neq'  => '!=',
        'gt'   => '>',
        'gte'  => '>=',
        'lt'   => '<',
        'lte'  => '<=',
        'like' => 'LIKE',
        'in'   => 'IN',
        'null' => 'IS NULL',
    ];

    public static function fromGlobals(array $schema, array $route, string $project): static {
        $instance          = new static();
        $instance->table   = $route['table'];
        $instance->id      = $route['id'];
        $instance->schema  = $schema;
        $instance->project = $project;

        // Verificar que la tabla existe en el schema
        if (!isset($schema['schema'][$route['table']])) {
            Response::error("Table '{$route['table']}' not found", 404);
            exit;
        }

        $instance->parseFields();
        $instance->parseWith();
        $instance->parseFilters();
        $instance->parseSorting();
        $instance->parsePagination();

        return $instance;
    }

    /**
     * ?fields=title,completed,userId
     * Valida cada campo contra el schema.
     * Excluye automáticamente campos @hidden.
     */
    private function parseFields(): void {
        $tableSchema = $this->schema['schema'][$this->table];
        $requested   = $_GET['fields'] ?? null;

        if ($requested) {
            $fields = array_map('trim', explode(',', $requested));

            foreach ($fields as $field) {
                if (!isset($tableSchema[$field])) {
                    Response::error("Field '{$field}' does not exist in '{$this->table}'", 400);
                    exit;
                }
                if ($tableSchema[$field]['hidden'] ?? false) {
                    Response::error("Field '{$field}' is not accessible", 403);
                    exit;
                }
            }

            $this->fields = $fields;
        } 
        else {
            // Sin ?fields= → todos excepto @hidden
            $this->fields = array_keys(array_filter(
                $tableSchema,
                fn($f) => !($f['hidden'] ?? false)
            ));
        }
    }

    /**
     * ?with=user,comments
     * Valida contra relaciones del schema.
     */
    private function parseWith(): void {
        $with = $_GET['with'] ?? null;
        if (!$with) return;

        $relations  = array_map('trim', explode(',', $with));
        $tableRels  = $this->schema['schema'][$this->table]['__relations'] ?? [];
        $validNames = [];

        foreach (['belongs_to', 'has_many', 'has_one'] as $type) {
            foreach ($tableRels[$type] ?? [] as $rel) {
                $validNames[$rel['name']] = $rel;
            }
        }

        foreach ($relations as $relation) {
            if (!isset($validNames[$relation])) {
                Response::error(
                    "Relation '{$relation}' does not exist. Valid: " . implode(', ', array_keys($validNames)),
                    400
                );
                exit;
            }
            $this->with[] = $validNames[$relation];
        }
    }

    /**
     * Filtros simples:    ?completed=false&userId=abc-123
     * Filtros con op:     ?created_at[gte]=2024-01-01&title[like]=%task%
     * Filtros IN:         ?priority[in]=high,medium
     * Filtros NULL:       ?parentId[null]=true
     *
     * Solo permite campos marcados como @filterable en el schema.
     */
    private function parseFilters(): void {
        $tableSchema = $this->schema['schema'][$this->table];
        $reserved    = ['fields', 'with', 'sort', 'page', 'limit'];

        foreach ($_GET as $key => $value) {
            if (in_array($key, $reserved, true)) continue;

            // Detectar operador: created_at[gte]
            preg_match('/^([^\[]+)(?:\[([^\]]+)\])?$/', $key, $matches);
            $column   = $matches[1];
            $operator = $matches[2] ?? 'eq';

            // Validar que el campo existe
            if (!isset($tableSchema[$column])) continue;

            // Validar que es @filterable
            if (!($tableSchema[$column]['filterable'] ?? false)) {
                Response::error("Field '{$column}' is not filterable", 400);
                exit;
            }

            // Validar operador
            if (!isset(self::OPERATORS[$operator])) {
                Response::error("Invalid operator '{$operator}'", 400);
                exit;
            }

            $sqlOperator = self::OPERATORS[$operator];

            // Casos especiales
            if ($operator === 'in') {
                $this->filters[] = [
                    'type'   => 'in',
                    'column' => $column,
                    'values' => array_map('trim', explode(',', $value)),
                ];
            } 
            elseif ($operator === 'null') {
                $this->filters[] = [
                    'type'   => 'null',
                    'column' => $column,
                    'not'    => $value === 'false',
                ];
            } 
            else {
                $this->filters[] = [
                    'type'     => 'basic',
                    'column'   => $column,
                    'operator' => $sqlOperator,
                    'value'    => $this->castValue($value, $tableSchema[$column]['type_name']),
                ];
            }
        }
    }

    /**
     * ?sort=-created_at,title
     * - prefijo = DESC, sin prefijo = ASC
     */
    private function parseSorting(): void {
        $sort = $_GET['sort'] ?? null;
        if (!$sort) return;

        $tableSchema = $this->schema['schema'][$this->table];

        foreach (array_map('trim', explode(',', $sort)) as $item) {
            $desc   = str_starts_with($item, '-');
            $column = ltrim($item, '-');

            if (!isset($tableSchema[$column])) {
                Response::error("Cannot sort by '{$column}'", 400);
                exit;
            }

            $this->sorting[] = [$column, $desc ? 'DESC' : 'ASC'];
        }
    }

    private function parsePagination(): void {
        $this->page  = isset($_GET['page'])  ? max(1, (int) $_GET['page'])  : null;
        $this->limit = isset($_GET['limit']) ? min(100, (int) $_GET['limit']) : 15;
        // limit máximo de 100 para proteger el servidor
    }

    public function toFluent(): Fluent {
        $fullTable = "gosplan_{$this->project}_{$this->table}";
        $fluent    = Fluent::table($fullTable);

        // SELECT fields
        $fluent = $fluent->select(
            array_map(fn($f) => "{$fullTable}.{$f}", $this->fields)
        );

        // JOINs desde ?with=
        foreach ($this->with as $rel) {
            $relTable = "gosplan_{$this->project}_{$rel['table']}";
            $fluent   = $fluent->leftJoin(
                $relTable,
                "{$fullTable}.{$rel['fk']}",
                "{$relTable}.{$rel['pk']}",
            );
        }

        // WHERE por ID (show/update/delete)
        if ($this->id) {
            $fluent = $fluent->where("{$fullTable}.id", $this->id);
        }

        // Filtros desde query params
        foreach ($this->filters as $filter) {
            $fluent = match($filter['type']) {
                'basic' => $fluent->where($filter['column'], $filter['operator'], $filter['value']),
                'in'    => $fluent->whereIn($filter['column'], $filter['values']),
                'null'  => $filter['not']
                            ? $fluent->whereNotNull($filter['column'])
                            : $fluent->whereNull($filter['column']),
            };
        }

        // ORDER BY
        foreach ($this->sorting as [$column, $direction]) {
            $fluent = $fluent->orderBy($column, $direction);
        }

        // Paginación
        if ($this->page) {
            $fluent = $fluent->paginate($this->page, $this->limit);
        }

        return $fluent;
    }

    private function castValue(string $value, string $typeName): mixed {
        return match($typeName) {
            'boolean', 'bool' => in_array(strtolower($value), ['true', '1', 'yes'], true),
            'int', 'integer'  => (int) $value,
            'decimal', 'float'=> (float) $value,
            default           => $value,
        };
    }
}