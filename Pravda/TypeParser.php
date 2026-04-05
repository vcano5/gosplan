<?php

declare(strict_types=1);
namespace Pravda;

class TypeParser {

    /**
     * Parsea un tipo con parámetros opcionales.
     * 
     * Ejemplos:
     * - "string(1024)" → ["type" => "string", "params" => [1024]]
     * - "decimal(10,2)" → ["type" => "decimal", "params" => [10, 2]]
     * - "enum(user,admin,guest)" → ["type" => "enum", "params" => ["user", "admin", "guest"]]
     * - "uuid" → ["type" => "uuid", "params" => []]
     * 
     * @param  string $typeDefinition El tipo a parsear (ej: "string(1024)")
     * @return array Parsed type info
     * @throws \RuntimeException Si el tipo es inválido
     */
    public static function parse(string $typeDefinition): array {
        $typeDefinition = trim($typeDefinition);
        
        if (empty($typeDefinition)) {
            throw new \RuntimeException("Type definition cannot be empty");
        }

        // Detectar parámetros: tipo(params)
        if (preg_match('/^([a-zA-Z_]\w*)\((.*)\)$/', $typeDefinition, $matches)) {
            $baseType = strtolower($matches[1]);
            $paramsRaw = $matches[2];
            
            // Parsear parámetros
            $params = self::parseParams($baseType, $paramsRaw);
            
            // Validar parámetros según el tipo
            self::validateTypeParams($baseType, $params);
            
            return [
                'type' => $baseType,
                'params' => $params,
                'hasParams' => true
            ];
        }
        
        // Solo tipo sin parámetros
        $baseType = strtolower($typeDefinition);
        if (!preg_match('/^[a-zA-Z_]\w*$/', $baseType)) {
            throw new \RuntimeException("Invalid type format: {$typeDefinition}");
        }
        
        return [
            'type' => $baseType,
            'params' => [],
            'hasParams' => false
        ];
    }

    /**
     * Parsea los parámetros dentro de los paréntesis.
     * 
     * - Para enum: "user,admin,guest" → ["user", "admin", "guest"]
     * - Para decimal/numeric: "10,2" → [10, 2]
     * - Para string/char: "1024" → [1024]
     */
    private static function parseParams(string $type, string $paramsRaw): array {
        if (empty($paramsRaw)) {
            return [];
        }

        // Enum: parámetros son strings separados por comas (sin conversión numérica)
        if ($type === 'enum') {
            return array_map('trim', explode(',', $paramsRaw));
        }

        // Otros tipos numéricos: parámetros son números
        $parts = array_map('trim', explode(',', $paramsRaw));
        
        foreach ($parts as &$part) {
            if (!is_numeric($part)) {
                throw new \RuntimeException(
                    "Non-numeric parameter for type '{$type}': {$part}"
                );
            }
            $part = (int) $part;
        }

        return $parts;
    }

    /**
     * Valida que los parámetros sean correctos para el tipo especificado.
     */
    private static function validateTypeParams(string $type, array $params): void {
        switch ($type) {
            case 'enum':
                if (empty($params)) {
                    throw new \RuntimeException("enum() requires at least one value");
                }
                if (count($params) < 2) {
                    throw new \RuntimeException("enum() requires at least 2 values, got " . count($params));
                }
                break;

            case 'decimal':
            case 'numeric':
                if (count($params) !== 2) {
                    throw new \RuntimeException(
                        "decimal() requires exactly 2 parameters (precision, scale), got " . count($params)
                    );
                }
                if ($params[0] < 1 || $params[1] < 0) {
                    throw new \RuntimeException(
                        "decimal() precision must be >= 1 and scale >= 0"
                    );
                }
                break;

            case 'string':
            case 'varchar':
                if (count($params) !== 1) {
                    throw new \RuntimeException(
                        "string() requires exactly 1 parameter (length), got " . count($params)
                    );
                }
                if ($params[0] < 1 || $params[0] > 65535) {
                    throw new \RuntimeException(
                        "string() length must be between 1 and 65535, got {$params[0]}"
                    );
                }
                break;

            case 'char':
            case 'character':
                if (count($params) !== 1) {
                    throw new \RuntimeException(
                        "char() requires exactly 1 parameter (length), got " . count($params)
                    );
                }
                if ($params[0] < 1 || $params[0] > 255) {
                    throw new \RuntimeException(
                        "char() length must be between 1 and 255, got {$params[0]}"
                    );
                }
                break;

            case 'int':
            case 'integer':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                if (count($params) > 0) {
                    throw new \RuntimeException(
                        "{$type}() does not accept parameters"
                    );
                }
                break;

            case 'float':
            case 'double':
                if (count($params) > 0) {
                    throw new \RuntimeException(
                        "{$type}() does not accept parameters"
                    );
                }
                break;

            case 'date':
            case 'datetime':
            case 'timestamp':
            case 'time':
            case 'year':
            case 'text':
            case 'blob':
            case 'json':
            case 'uuid':
            case 'boolean':
            case 'bool':
                if (count($params) > 0) {
                    throw new \RuntimeException(
                        "{$type}() does not accept parameters"
                    );
                }
                break;

            default:
                // Tipos desconocidos sin parámetros → permitidos
                if (count($params) > 0) {
                    throw new \RuntimeException(
                        "Unknown type '{$type}' with parameters"
                    );
                }
        }
    }

    /**
     * Obtiene el tipo base (sin parámetros) de una definición.
     * Útil para verificación rápida.
     */
    public static function getBase(string $typeDefinition): string {
        if (preg_match('/^([a-zA-Z_]\w*)/', $typeDefinition, $matches)) {
            return strtolower($matches[1]);
        }
        return '';
    }
}
