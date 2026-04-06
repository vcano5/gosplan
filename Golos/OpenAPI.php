<?php

declare(strict_types=1);

// Traduce el project.json al estándar de Swagger

namespace Golos;

/**
 * OpenAPI.php — Traduce el gosplan.json al estándar OpenAPI 3.0.
 *
 * Uso desde el router:
 *   GET /swagger/todoapp  → sirve Swagger UI embebida
 *   GET /swagger/todoapp/json → devuelve la spec como JSON crudo
 *
 * Lo que infiere automáticamente del schema:
 *   - Tipos de cada campo (string, integer, number, boolean)
 *   - Formatos especiales (uuid, date-time, email, decimal → number)
 *   - Campos required (desde @required)
 *   - Enums (desde enum(a,b,c))
 *   - maxLength (desde string(255))
 *   - Relaciones como parámetro ?with= (desde @ref)
 *   - nullable (desde @nullable)
 *   - Ejemplos de default (desde @default)
 *   - Endpoints CRUD por tabla
 *   - Schemas de request body (sin campos auto: id, created_at, updated_at)
 *   - Schemas de response (con todos los campos)
 *   - Autenticación JWT (bearerAuth)
 *   - Headers requeridos (X-Tenant)
 *   - Responses de error reutilizables (401, 403, 404, 422)
 */
class OpenAPI {
    // ─── Mapa de tipos gosplan → OpenAPI ─────────────────────────────────────
    private const TYPE_MAP = [
        'string'    => ['type' => 'string'],
        'text'      => ['type' => 'string'],
        'longtext'  => ['type' => 'string'],
        'uuid'      => ['type' => 'string', 'format' => 'uuid'],
        'int'       => ['type' => 'integer'],
        'integer'   => ['type' => 'integer'],
        'bigint'    => ['type' => 'integer', 'format' => 'int64'],
        'boolean'   => ['type' => 'boolean'],
        'bool'      => ['type' => 'boolean'],
        'float'     => ['type' => 'number', 'format' => 'float'],
        'double'    => ['type' => 'number', 'format' => 'double'],
        'decimal'   => ['type' => 'number', 'format' => 'decimal'],
        'date'      => ['type' => 'string', 'format' => 'date'],
        'datetime'  => ['type' => 'string', 'format' => 'date-time'],
        'timestamp' => ['type' => 'string', 'format' => 'date-time'],
        'json'      => ['type' => 'object'],
        'enum'      => ['type' => 'string'],
    ];

    // Campos que nunca van en el request body
    private const AUTO_FIELDS = ['id', 'created_at', 'updated_at'];

    // Campos sensibles — se omiten de responses por nombre
    private const SENSITIVE_FIELDS = ['password', 'password_hash', 'token', 'secret'];

    // ─── Entry points ─────────────────────────────────────────────────────────

    /**
     * Sirve la Swagger UI embebida.
     * Llama desde tu router: OpenAPI::serveUI($schema, 'todoapp');
     */
    public static function serveUI(array $schema, string $project): void
    {
        $title   = ucfirst($schema['projectName'] ?? $project) . ' API';
        $jsonUrl = "/swagger/{$project}/json";

        header('Content-Type: text/html; charset=utf-8');
        echo self::renderHTML($title, $jsonUrl);
    }

    /**
     * Devuelve la spec como JSON crudo.
     * Llama desde tu router: OpenAPI::serveJson($schema, $baseUrl);
     */
    public static function serveJson(array $schema, string $baseUrl): void
    {
        header('Content-Type: application/json');
        echo json_encode(
            self::generate($schema, $baseUrl),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Genera el array completo de la spec OpenAPI 3.0.
     * Útil para tests o para serializar manualmente.
     */
    public static function generate(array $schema, string $baseUrl = ''): array
    {
        $project = $schema['projectName'] ?? 'api';
        $tables  = $schema['schema'] ?? [];

        return [
            'openapi' => '3.0.3',
            'info'    => self::buildInfo($project),
            'servers' => self::buildServers($baseUrl, $project),
            'security'=> [['bearerAuth' => []]],
            'paths'   => self::buildPaths($tables, $project),
            'components' => [
                'securitySchemes' => self::buildSecuritySchemes(),
                'parameters'      => self::buildSharedParameters(),
                'responses'       => self::buildSharedResponses(),
                'schemas'         => self::buildSchemas($tables),
            ],
        ];
    }

    // ─── Info ─────────────────────────────────────────────────────────────────

    private static function buildInfo(string $project): array
    {
        return [
            'title'       => ucfirst($project) . ' API',
            'description' => "API generada automáticamente desde el schema de **{$project}**.\n\n"
                           . "Todos los endpoints requieren autenticación JWT (`Authorization: Bearer <token>`) "
                           . "y el header `X-Tenant` con el ID del tenant.",
            'version'     => '1.0.0',
            'contact'     => ['name' => 'Gosplan'],
        ];
    }

    // ─── Servers ──────────────────────────────────────────────────────────────

    private static function buildServers(string $baseUrl, string $project): array
    {
        $servers = [];

        if ($baseUrl) {
            $servers[] = [
                'url'         => rtrim($baseUrl, '/') . "/{$project}",
                'description' => 'Base URL del proyecto',
            ];
        }

        $servers[] = [
            'url'         => "http://localhost/{$project}",
            'description' => 'Local',
        ];

        return $servers;
    }

    // ─── Paths ────────────────────────────────────────────────────────────────

    private static function buildPaths(array $tables, string $project): array
    {
        $paths = [];

        // Ruta de autenticación
        $paths['/auth/login'] = self::buildAuthPath();

        foreach ($tables as $table => $fields) {
            $parsedFields = self::parseAllFields($fields);
            $relations    = self::inferRelations($table, $parsedFields, $tables);
            $withOptions  = array_column($relations['belongs_to'], 'name');
            $withOptions  = array_merge($withOptions, array_column($relations['has_many'], 'name'));

            // GET /table + POST /table
            $paths["/{$table}"] = [
                'get'  => self::buildList($table, $parsedFields, $withOptions),
                'post' => self::buildCreate($table, $parsedFields),
            ];

            // GET /table/{id} + PUT /table/{id} + DELETE /table/{id}
            $paths["/{$table}/{id}"] = [
                'get'    => self::buildShow($table, $parsedFields, $withOptions),
                'put'    => self::buildUpdate($table, $parsedFields),
                'delete' => self::buildDelete($table),
            ];
        }

        return $paths;
    }

    // ─── Auth path ────────────────────────────────────────────────────────────

    private static function buildAuthPath(): array
    {
        return [
            'post' => [
                'tags'        => ['Auth'],
                'summary'     => 'Login',
                'description' => 'Obtiene un JWT con los tenants del usuario.',
                'security'    => [],   // sin auth
                'requestBody' => [
                    'required' => true,
                    'content'  => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'required'   => ['username', 'password'],
                                'properties' => [
                                    'username' => ['type' => 'string', 'example' => 'vcano5'],
                                    'password' => ['type' => 'string', 'format' => 'password'],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Login exitoso',
                        'content'     => [
                            'application/json' => [
                                'schema' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'token'   => ['type' => 'string'],
                                        'refresh' => ['type' => 'string'],
                                        'expires' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                ],
            ],
        ];
    }

    // ─── CRUD operations ──────────────────────────────────────────────────────

    private static function buildList(string $table, array $fields, array $withOptions): array
    {
        $filterableFields = array_filter($fields, fn($f) => $f['filterable'] ?? true);
        $parameters       = [
            ['$ref' => '#/components/parameters/XTenant'],
            ['$ref' => '#/components/parameters/Fields'],
            ['$ref' => '#/components/parameters/Sort'],
            ['$ref' => '#/components/parameters/Page'],
            ['$ref' => '#/components/parameters/Limit'],
        ];

        // ?with= solo si hay relaciones
        if (!empty($withOptions)) {
            $parameters[] = [
                'name'        => 'with',
                'in'          => 'query',
                'description' => 'Relaciones a incluir',
                'schema'      => [
                    'type'  => 'string',
                    'enum'  => $withOptions,
                ],
                'example' => $withOptions[0] ?? null,
            ];
        }

        // Parámetros de filtro por campo @filterable
        foreach ($filterableFields as $col => $field) {
            if (in_array($col, self::AUTO_FIELDS, true)) continue;
            if (in_array($col, self::SENSITIVE_FIELDS, true)) continue;

            $parameters[] = [
                'name'        => $col,
                'in'          => 'query',
                'description' => "Filtrar por {$col}",
                'required'    => false,
                'schema'      => self::fieldToOpenAPIType($field),
            ];

            // Operadores extendidos para tipos comparables
            if (in_array($field['type_name'], ['int', 'integer', 'decimal', 'float', 'datetime', 'date'], true)) {
                foreach (['gte', 'lte', 'gt', 'lt'] as $op) {
                    $parameters[] = [
                        'name'        => "{$col}[{$op}]",
                        'in'          => 'query',
                        'description' => "Filtrar {$col} con operador {$op}",
                        'required'    => false,
                        'schema'      => self::fieldToOpenAPIType($field),
                    ];
                }
            }

            if (in_array($field['type_name'], ['string', 'text'], true)) {
                $parameters[] = [
                    'name'        => "{$col}[like]",
                    'in'          => 'query',
                    'description' => "Búsqueda parcial en {$col}",
                    'required'    => false,
                    'schema'      => ['type' => 'string'],
                    'example'     => '%texto%',
                ];
            }
        }

        return [
            'tags'        => [self::tag($table)],
            'summary'     => "Listar " . self::label($table),
            'description' => "Devuelve una lista paginada de {$table}.",
            'parameters'  => $parameters,
            'responses'   => [
                '200' => [
                    'description' => 'Lista paginada',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'data'       => [
                                        'type'  => 'array',
                                        'items' => ['$ref' => "#/components/schemas/{$table}Response"],
                                    ],
                                    'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                                    'success'    => ['type' => 'boolean', 'example' => true],
                                    'status'     => ['type' => 'integer', 'example' => 200],
                                ],
                            ],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
            ],
        ];
    }

    private static function buildShow(string $table, array $fields, array $withOptions): array
    {
        $parameters = [
            ['$ref' => '#/components/parameters/XTenant'],
            ['$ref' => '#/components/parameters/IdPath'],
            ['$ref' => '#/components/parameters/Fields'],
        ];

        if (!empty($withOptions)) {
            $parameters[] = [
                'name'   => 'with',
                'in'     => 'query',
                'schema' => ['type' => 'string', 'enum' => $withOptions],
            ];
        }

        return [
            'tags'        => [self::tag($table)],
            'summary'     => "Obtener " . self::labelSingular($table),
            'parameters'  => $parameters,
            'responses'   => [
                '200' => [
                    'description' => ucfirst($table) . ' encontrado',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'data'    => ['$ref' => "#/components/schemas/{$table}Response"],
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'status'  => ['type' => 'integer', 'example' => 200],
                                ],
                            ],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
            ],
        ];
    }

    private static function buildCreate(string $table, array $fields): array
    {
        return [
            'tags'        => [self::tag($table)],
            'summary'     => "Crear " . self::labelSingular($table),
            'parameters'  => [['$ref' => '#/components/parameters/XTenant']],
            'requestBody' => [
                'required' => true,
                'content'  => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$table}Request"],
                    ],
                ],
            ],
            'responses'   => [
                '201' => [
                    'description' => ucfirst($table) . ' creado',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'data'    => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'status'  => ['type' => 'integer', 'example' => 201],
                                ],
                            ],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
            ],
        ];
    }

    private static function buildUpdate(string $table, array $fields): array
    {
        return [
            'tags'        => [self::tag($table)],
            'summary'     => "Actualizar " . self::labelSingular($table),
            'parameters'  => [
                ['$ref' => '#/components/parameters/XTenant'],
                ['$ref' => '#/components/parameters/IdPath'],
            ],
            'requestBody' => [
                'required' => true,
                'content'  => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$table}Request"],
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => ucfirst($table) . ' actualizado',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'data'    => ['type' => 'object', 'properties' => ['affected' => ['type' => 'integer']]],
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'status'  => ['type' => 'integer', 'example' => 200],
                                ],
                            ],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
            ],
        ];
    }

    private static function buildDelete(string $table): array
    {
        return [
            'tags'       => [self::tag($table)],
            'summary'    => "Eliminar " . self::labelSingular($table),
            'parameters' => [
                ['$ref' => '#/components/parameters/XTenant'],
                ['$ref' => '#/components/parameters/IdPath'],
            ],
            'responses'  => [
                '200' => [
                    'description' => ucfirst($table) . ' eliminado',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'data'    => ['type' => 'object', 'properties' => ['affected' => ['type' => 'integer']]],
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'status'  => ['type' => 'integer', 'example' => 200],
                                ],
                            ],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
            ],
        ];
    }

    // ─── Components: Schemas ──────────────────────────────────────────────────

    private static function buildSchemas(array $tables): array
    {
        $schemas = [
            'Pagination' => [
                'type'       => 'object',
                'properties' => [
                    'total'   => ['type' => 'integer', 'example' => 100],
                    'page'    => ['type' => 'integer', 'example' => 1],
                    'limit'   => ['type' => 'integer', 'example' => 15],
                    'pages'   => ['type' => 'integer', 'example' => 7],
                    'hasMore' => ['type' => 'boolean', 'example' => true],
                ],
            ],
        ];

        foreach ($tables as $table => $fields) {
            $parsedFields = self::parseAllFields($fields);

            // Schema de Response — todos los campos excepto sensibles
            $schemas["{$table}Response"] = self::buildResponseSchema($table, $parsedFields);

            // Schema de Request — solo fillable (sin auto_fields ni sensibles visibles)
            $schemas["{$table}Request"] = self::buildRequestSchema($table, $parsedFields);
        }

        return $schemas;
    }

    private static function buildResponseSchema(string $table, array $fields): array
    {
        $properties = [];

        // id siempre primero
        $properties['id'] = ['type' => 'integer', 'example' => 1, 'readOnly' => true];

        foreach ($fields as $col => $field) {
            // Omitir campos sensibles de la respuesta
            if (in_array($col, self::SENSITIVE_FIELDS, true)) continue;

            $prop = self::fieldToOpenAPIType($field);

            if ($field['nullable'] ?? false) {
                $prop['nullable'] = true;
            }

            if ($field['default'] !== null) {
                $prop['example'] = $field['default'];
            }

            $properties[$col] = $prop;
        }

        // timestamps siempre al final
        $properties['created_at'] = ['type' => 'string', 'format' => 'date-time', 'readOnly' => true];
        $properties['updated_at'] = ['type' => 'string', 'format' => 'date-time', 'readOnly' => true];

        return [
            'type'       => 'object',
            'properties' => $properties,
        ];
    }

    private static function buildRequestSchema(string $table, array $fields): array
    {
        $properties = [];
        $required   = [];

        foreach ($fields as $col => $fields) {
            // Omitir campos automáticos
            if (in_array($col, self::AUTO_FIELDS, true)) continue;

            $prop = self::fieldToOpenAPIType($fields);

            // Campos con default_fn son opcionales en el request
            // (el sistema los resuelve automáticamente)
            $isRequired = ($fields['required'] ?? false)
                       && ($fields['default']    === null)
                       && ($fields['default_fn'] === null);

            if ($isRequired) {
                $required[] = $col;
            }

            if ($fields['default'] !== null) {
                $prop['default'] = $fields['default'];
                $prop['example'] = $fields['default'];
            }

            if ($fields['default_fn'] !== null) {
                $prop['description'] = "Procesado automáticamente por `{$fields['default_fn']}()`";
            }

            if ($fields['nullable'] ?? false) {
                $prop['nullable'] = true;
            }

            // Campos de tipo password — escribible pero no legible
            if (in_array($col, self::SENSITIVE_FIELDS, true)) {
                $prop['format']      = 'password';
                $prop['description'] = 'Solo escritura — no aparece en responses';
                $prop['writeOnly']   = true;
            }

            $properties[$col] = $prop;
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    // ─── Components: Security, Parameters, Responses ─────────────────────────

    private static function buildSecuritySchemes(): array
    {
        return [
            'bearerAuth' => [
                'type'         => 'http',
                'scheme'       => 'bearer',
                'bearerFormat' => 'JWT',
                'description'  => 'JWT obtenido desde POST /auth/login',
            ],
        ];
    }

    private static function buildSharedParameters(): array
    {
        return [
            'XTenant' => [
                'name'        => 'X-Tenant',
                'in'          => 'header',
                'required'    => true,
                'description' => 'ID del tenant (UUID de la base de datos)',
                'schema'      => ['type' => 'string', 'format' => 'uuid'],
                'example'     => 'gosplan_a3f8c2d1-e4b7-...',
            ],
            'IdPath' => [
                'name'        => 'id',
                'in'          => 'path',
                'required'    => true,
                'description' => 'ID del recurso',
                'schema'      => ['type' => 'string'],
            ],
            'Fields' => [
                'name'        => 'fields',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Campos a incluir en la respuesta (separados por coma)',
                'schema'      => ['type' => 'string'],
                'example'     => 'title,completed,userId',
            ],
            'Sort' => [
                'name'        => 'sort',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Ordenar por campo. Prefijo `-` para DESC.',
                'schema'      => ['type' => 'string'],
                'example'     => '-created_at,title',
            ],
            'Page' => [
                'name'    => 'page',
                'in'      => 'query',
                'schema'  => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
            'Limit' => [
                'name'    => 'limit',
                'in'      => 'query',
                'schema'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 15],
            ],
        ];
    }

    private static function buildSharedResponses(): array
    {
        $errorSchema = [
            'type'       => 'object',
            'properties' => [
                'error'   => ['type' => 'string'],
                'status'  => ['type' => 'integer'],
                'success' => ['type' => 'boolean', 'example' => false],
            ],
        ];

        return [
            'Unauthorized' => [
                'description' => 'Token inválido o expirado',
                'content'     => ['application/json' => ['schema' => $errorSchema]],
            ],
            'Forbidden' => [
                'description' => 'Sin permisos para esta acción',
                'content'     => ['application/json' => ['schema' => $errorSchema]],
            ],
            'NotFound' => [
                'description' => 'Recurso no encontrado',
                'content'     => ['application/json' => ['schema' => $errorSchema]],
            ],
            'ValidationError' => [
                'description' => 'Datos inválidos',
                'content'     => [
                    'application/json' => [
                        'schema' => [
                            'type'       => 'object',
                            'properties' => [
                                'error'   => ['type' => 'string'],
                                'errors'  => [
                                    'type'                 => 'object',
                                    'additionalProperties' => ['type' => 'string'],
                                    'example'              => ['title' => "El campo 'title' es requerido"],
                                ],
                                'status'  => ['type' => 'integer', 'example' => 422],
                                'success' => ['type' => 'boolean', 'example' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ─── Helpers: parsing del schema ─────────────────────────────────────────

    /**
     * Parsea todos los campos de una tabla desde el gosplan.json.
     * Entiende: string(255), enum(a,b,c), @required, @nullable,
     *           @default(val), @default(fn()), @ref:tabla.col(opts), @unique
     */
    private static function parseAllFields(array $rawFields): array
    {
        $parsed = [];
        foreach ($rawFields as $col => $definition) {
            $parsed[$col] = self::parseField((string) $definition);
        }
        return $parsed;
    }

    private static function parseField(string $definition): array
    {
        $parts     = explode('|', $definition);
        $typePart  = trim(array_shift($parts));

        // Parsear tipo base y params: string(255), enum(a,b,c), decimal(10,2)
        preg_match('/^([a-zA-Z]+)(?:\(([^)]*)\))?/', $typePart, $typeMatch);
        $typeName   = strtolower($typeMatch[1] ?? 'string');
        $typeParams = isset($typeMatch[2]) ? array_map('trim', explode(',', $typeMatch[2])) : [];

        $field = [
            'type_name'   => $typeName,
            'type_params' => $typeParams,
            'required'    => false,
            'nullable'    => false,
            'unique'      => false,
            'default'     => null,
            'default_fn'  => null,
            'enum_values' => $typeName === 'enum' ? $typeParams : null,
            'ref'         => null,
            'filterable'  => true,   // por defecto filterable hasta implementar @filterable
        ];

        foreach ($parts as $part) {
            $part = trim($part);
            self::applyDirective($field, $part);
        }

        return $field;
    }

    private static function applyDirective(array &$field, string $directive): void
    {
        match(true) {
            $directive === '@required'  => ($field['required']  = true),
            $directive === '@nullable'  => ($field['nullable']  = true),
            $directive === '@unique'    => ($field['unique']    = true),
            $directive === '@hidden'    => ($field['filterable'] = false),
            $directive === '@filterable'=> ($field['filterable'] = true),

            // @default(valor) o @default(fn())
            str_starts_with($directive, '@default(') => (function() use (&$field, $directive) {
                preg_match('/@default\((.+)\)/', $directive, $m);
                $inner = trim($m[1] ?? '');

                if (preg_match('/^([a-zA-Z_]\w*)\(\)$/', $inner, $fn)) {
                    $field['default_fn'] = $fn[1];
                } else {
                    $field['default'] = match(strtolower($inner)) {
                        'true'  => true,
                        'false' => false,
                        'null'  => null,
                        default => is_numeric($inner) ? $inner + 0 : $inner,
                    };
                }
            })(),

            // @ref:tabla.col(delete:cascade,update:cascade)
            str_starts_with($directive, '@ref:') => (function() use (&$field, $directive) {
                preg_match('/@ref:([a-zA-Z_]+)\.([a-zA-Z_]+)(?:\(([^)]*)\))?/', $directive, $m);
                if (!$m) return;

                $opts = [];
                if (!empty($m[3])) {
                    foreach (explode(',', $m[3]) as $opt) {
                        [$k, $v] = array_map('trim', explode(':', $opt, 2));
                        $opts[strtolower($k)] = strtoupper($v);
                    }
                }

                $field['ref'] = [
                    'table'     => $m[1],
                    'column'    => $m[2],
                    'on_delete' => $opts['delete'] ?? 'RESTRICT',
                    'on_update' => $opts['update'] ?? 'RESTRICT',
                ];
            })(),

            default => null,
        };
    }

    /**
     * Infiere relaciones desde los @ref del schema.
     * Igual lógica que resolver.py pero en PHP.
     */
    private static function inferRelations(string $table, array $fields, array $allTables): array
    {
        $relations = ['belongs_to' => [], 'has_many' => [], 'has_one' => []];

        foreach ($fields as $col => $field) {
            if (!$field['ref']) continue;

            $refTable = $field['ref']['table'];
            $isSelf   = $refTable === $table;

            $relations['belongs_to'][] = [
                'name'  => $isSelf ? self::inferBelongsName($col) : self::singular($refTable),
                'table' => $refTable,
                'fk'    => $col,
                'pk'    => $field['ref']['column'],
            ];
        }

        // Inversos — buscar en otras tablas quién apunta a esta
        foreach ($allTables as $otherTable => $otherFields) {
            if ($otherTable === $table) continue;
            $otherParsed = self::parseAllFields($otherFields);

            foreach ($otherParsed as $col => $field) {
                if (!$field['ref']) continue;
                if ($field['ref']['table'] !== $table) continue;

                $name = $field['unique']
                    ? self::singular($otherTable)
                    : self::plural($otherTable);

                $type = $field['unique'] ? 'has_one' : 'has_many';
                $relations[$type][] = [
                    'name'  => $name,
                    'table' => $otherTable,
                    'fk'    => $col,
                    'pk'    => $field['ref']['column'],
                ];
            }
        }

        return $relations;
    }

    // ─── Helpers: tipos OpenAPI ───────────────────────────────────────────────

    private static function fieldToOpenAPIType(array $field): array
    {
        $typeName   = $field['type_name'];
        $typeParams = $field['type_params'] ?? [];
        $base       = self::TYPE_MAP[$typeName] ?? ['type' => 'string'];
        $prop       = $base;

        // String con longitud: string(255) → maxLength: 255
        if (in_array($typeName, ['string'], true) && !empty($typeParams)) {
            $prop['maxLength'] = (int) $typeParams[0];
        }

        // Decimal con precisión: decimal(10,2)
        if ($typeName === 'decimal' && count($typeParams) >= 2) {
            $prop['description'] = "Decimal({$typeParams[0]},{$typeParams[1]})";
            $prop['example']     = 0.00;
        }

        // Enum
        if ($typeName === 'enum' && !empty($typeParams)) {
            $prop['enum']    = $typeParams;
            $prop['example'] = $typeParams[0];
        }

        return $prop;
    }

    // ─── Helpers: nomenclatura ────────────────────────────────────────────────

    private static function singular(string $name): string
    {
        if (str_ends_with($name, 'ies')) return substr($name, 0, -3) . 'y';
        if (str_ends_with($name, 's') && !str_ends_with($name, 'ss')) return substr($name, 0, -1);
        return $name;
    }

    private static function plural(string $name): string
    {
        if (str_ends_with($name, 's')) return $name;
        if (str_ends_with($name, 'y')) return substr($name, 0, -1) . 'ies';
        return $name . 's';
    }

    private static function inferBelongsName(string $fieldName): string
    {
        return strtolower(preg_replace('/[_]?[Ii][Dd]$/', '', $fieldName));
    }

    private static function tag(string $table): string
    {
        return ucfirst($table);
    }

    private static function label(string $table): string
    {
        return ucfirst($table);
    }

    private static function labelSingular(string $table): string
    {
        return ucfirst(self::singular($table));
    }

    // ─── Swagger UI HTML ──────────────────────────────────────────────────────

    private static function renderHTML(string $title, string $jsonUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.11.0/swagger-ui.min.css">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f0f0f; }
    #header {
      background: #1a1a1a;
      border-bottom: 1px solid #2a2a2a;
      padding: 16px 24px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    #header h1 { color: #e8e8e8; font-size: 18px; font-weight: 500; }
    #header .badge {
      background: #00c48c22;
      color: #00c48c;
      border: 1px solid #00c48c44;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
    }
    #swagger-ui { max-width: 1200px; margin: 0 auto; padding: 24px; }
    .swagger-ui .topbar { display: none; }
    .swagger-ui { background: transparent; }
    .swagger-ui .info { margin: 0 0 24px; }
    .swagger-ui .info .title { color: #e8e8e8; }
    .swagger-ui .scheme-container {
      background: #1a1a1a;
      border: 1px solid #2a2a2a;
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 24px;
    }
  </style>
</head>
<body>
  <div id="header">
    <h1>{$title}</h1>
    <span class="badge">OpenAPI 3.0</span>
  </div>
  <div id="swagger-ui"></div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.11.0/swagger-ui-bundle.min.js"></script>
  <script>
    SwaggerUIBundle({
      url:                    '{$jsonUrl}',
      dom_id:                 '#swagger-ui',
      presets:                [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
      layout:                 'BaseLayout',
      deepLinking:            true,
      displayRequestDuration: true,
      filter:                 true,
      persistAuthorization:   true,
      tryItOutEnabled:        true,
    });
  </script>
</body>
</html>
HTML;
    }
}