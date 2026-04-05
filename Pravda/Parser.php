<?php

declare(strict_types=1);
namespace Pravda;

class Parser {

    private const TYPE_MAP = [
        "string" => "VARCHAR(255)",
        "text" => "TEXT",
        "int" => "INT",
        "number" => "FLOAT",
        "decimal" => "DECIMAL(10,2)",
        "boolean" => "TINYINT(1)",
        "bool" => "TINYINT(1)",
        "uuid" => "CHAR(36)",
        "date" => "DATE",
        "datetime" => "DATETIME",
        "timestamp" => "TIMESTAMP",
        "enum" => "ENUM" 
    ];

    private const DIRECTIVES = [
        "@primary",
        "@required",
        "@unique",
        "@index",
        "@nullable",
        "@unsigned",
        "@ref:",
        "@default(",
        "@values(",
    ];

    public static function parseFile(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Archivo no encontrado en: {$filePath}");
        }
 
        $raw = file_get_contents($filePath);
        $decoded = json_decode($raw, true);
 
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Archivo inválido: ' . json_last_error_msg());
        }
 
        return self::parse($decoded);
    }

    public static function parse(array $gosplan): array {
        $result = [
            "projectName" => $gosplan["projectName"] ?? "unnamed",
            "schema" => []
        ];

        foreach($gosplan["schema"] ?? [] as $table => $fields) {
            $result["schema"][$table] = self::parseTable($table, $fields);
        }
        return $result;
    }

    public static function parseTable(string $table, array $fields): array {
        $parsed = [];

        if(!isset($fields["id"])) {
            $parsed["id"] = [
                "type" => "CHAR(36)",
                "required" => true,
                "primary" => true,
                "autoIncrement" => false,
                "unique" => true,
                "nullable" => false,
                "index" => true,
                "default" => null,
                "function" => null,
                "ref" => null,
                "enum" => null
            ];
        }

        foreach($fields as $column => $definition) {
            $parsed[$column] = self::parseField($definition);
        }

        if(!isset($fields["fechaCreacion"])) {
            $parsed["fechaCreacion"] = self::makeTimestamp("CURRENT_TIMESTAMP");
        }
        if(!isset($fields["fechaActualizacion"])) {
            $parsed["fechaActualizacion"] = self::makeTimestamp("CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        return $parsed;
    }

    public static function parseField(string $definition): array {
        $tokens = explode("|", $definition);
        $rawType = trim(array_shift($tokens));

        $parsedType = TypeParser::parse($rawType);
        $sqlType = TypeMapper::toSQL($parsedType);
        
        $field = [
            "type" => $sqlType,
            "required" => false,
            "primary" => false,
            "unsigned" => false,
            "autoIncrement" => false,
            "unique" => false,
            "nullable" => false,
            "index" => false,
            "default" => null,
            "function" => null,
            "ref" => null,
            "enum" => null
        ];

        foreach($tokens as $token) {
            $token = trim($token);
            self::applyDirective($field, $token, $rawType);
        }
        return $field;
    }

    /**
     * @deprecated Use TypeParser y TypeMapper en su lugar
     */
    public static function resolveType(string $rawType): string {
        try {
            $parsed = TypeParser::parse($rawType);
            return TypeMapper::toSQL($parsed);
        } catch (\Exception $e) {
            // Fallback al TYPE_MAP legacy
            if(preg_match('/^(\w+)\((.+)\)$/', $rawType, $matches)) {
                $base = strtolower($matches[1]);
                $params = $matches[2];
                $mapped = self::TYPE_MAP[$base] ?? strtoupper($base);
                return preg_replace('/\(.*\)/', "({$params})", $mapped) ?: $mapped;
            }
            return self::TYPE_MAP[strtolower($rawType)] ?? strtoupper($rawType);
        }
    }

    private static function applyDirective(array &$field, string $token, string $rawType): void
    {
        match(true) {
 
            // @primary
            $token === '@primary' => (function() use (&$field) {
                $field['primary']       = true;
                $field['required']      = true;
                $field['autoIncrement'] = true;
                $field['unsigned']      = true;
            })(),
 
            // @required
            $token === '@required' => ($field['required'] = true),
 
            // @unique
            $token === '@unique' => ($field['unique'] = true),
 
            // @index
            $token === '@index' => ($field['index'] = true),
 
            // @nullable
            $token === '@nullable' => ($field['nullable'] = true),
 
            // @unsigned
            $token === '@unsigned' => ($field['unsigned'] = true),
 
            // @ref:tabla.columna o @ref:tabla.columna(delete:CASCADE,update:CASCADE)
            str_starts_with($token, '@ref:') => (function() use (&$field, $token) {
                $refContent = substr($token, 5); 
                if (preg_match('/^([^(]+)\((.+)\)$/', $refContent, $matches)) {
                    $tableColumn = $matches[1];
                    $paramsStr = $matches[2];
                } else {
                    $tableColumn = $refContent;
                    $paramsStr = null;
                }
                
                [$refTable, $refColumn] = explode('.', $tableColumn, 2);
                
                $ref = [
                    'table'  => trim($refTable),
                    'column' => trim($refColumn),
                ];
                
                if ($paramsStr) {
                    $params = array_map('trim', explode(',', $paramsStr));
                    foreach ($params as $param) {
                        if (preg_match('/^(\w+):([a-zA-Z-]+)$/', $param, $pm)) {
                            $key = strtolower($pm[1]);
                            $rawVal = strtoupper(str_replace('-', ' ', $pm[2]));
                            if (in_array($key, ['delete', 'update'])) {
                                if (in_array($rawVal, ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION', 'SET DEFAULT'])) {
                                    $ref[$key] = $rawVal;
                                }
                            }
                        }
                    }
                }
                
                $field['ref'] = $ref;
            })(),
 
            // @default(valor) o @default(funcion())
            str_starts_with($token, '@default(') => (function() use (&$field, $token) {
                // extrae lo que está dentro de @default(...)
                preg_match('/@default\((.+)\)/', $token, $m);
                $inner = trim($m[1] ?? '');
 
                if (preg_match('/^([a-zA-Z_]\w*)\(\)$/', $inner, $fn)) {
                    $field['function'] = $fn[1]; // "password_hash"
                    $field['default']  = null;
                } 
                else {
                    $field['default'] = match(strtolower($inner)) {
                        'true'  => true,
                        'false' => false,
                        'null'  => null,
                        default => $inner,
                    };
                }
            })(),
 
            // @values(draft,published,archived) para ENUM
            str_starts_with($token, '@values(') => (function() use (&$field, $token) {
                preg_match('/@values\((.+)\)/', $token, $m);
                $values       = array_map('trim', explode(',', $m[1] ?? ''));
                $field['enum'] = $values;
                $field['type'] = "ENUM('" . implode("','", $values) . "')";
            })(),
 
            // @length(500) — sobrescribe largo de VARCHAR
            str_starts_with($token, '@length(') => (function() use (&$field, $token, $rawType) {
                preg_match('/@length\((\d+)\)/', $token, $m);
                $len          = (int) ($m[1] ?? 255);
                $field['type'] = "VARCHAR({$len})";
            })(),
 
            // @precision(8,4) — para DECIMAL
            str_starts_with($token, "@precision(") => (function() use (&$field, $token) {
                preg_match('/@precision\((\d+),(\d+)\)/', $token, $m);
                $field["type"] = "DECIMAL({$m[1]},{$m[2]})";
            })(),
 
            // Directiva desconocida — la ignoramos pero la logueamos
            default => error_log("Pravda\\Parser: directiva desconocida '{$token}'"),
        };
    }

    private static function makeTimestamp(string $default): array {
        return [
            "type" => "TIMESTAMP",
            "required" => false,
            "primary" => false,
            "unsigned" => false,
            "autoIncrement" => false,
            "unique" => false,
            "nullable" => false,
            "index" => false,
            "default" => $default,
            "function" => null,
            "ref" => null,
            "enum" => null,
        ];
    }
    
}