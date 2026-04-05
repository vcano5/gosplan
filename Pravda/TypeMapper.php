<?php

declare(strict_types=1);
namespace Pravda;

class TypeMapper {

    /**
     * Mapea un tipo parseado (con parámetros) a SQL MySQL válido.
     * 
     * @param  array $parsed El resultado de TypeParser::parse()
     * @return string SQL type (ej: "VARCHAR(1024)", "DECIMAL(10,2)", "ENUM('user','admin')")
     */
    public static function toSQL(array $parsed): string {
        $type = $parsed['type'];
        $params = $parsed['params'] ?? [];

        return match ($type) {
            'string', 'varchar' => self::mapString($params),
            'char', 'character' => self::mapChar($params),
            'text' => 'TEXT',
            'int', 'integer' => 'INT',
            'bigint' => 'BIGINT',
            'smallint' => 'SMALLINT',
            'tinyint' => 'TINYINT',
            'float', 'double' => 'FLOAT',
            'decimal', 'numeric' => self::mapDecimal($params),
            'enum' => self::mapEnum($params),
            'boolean', 'bool' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'year' => 'YEAR',
            'uuid' => 'CHAR(36)',
            'text' => 'TEXT',
            'blob', 'longblob' => 'LONGBLOB',
            'json' => 'JSON',
            default => throw new \RuntimeException("Unknown type: {$type}")
        };
    }

    /**
     * Mapea string(N) → VARCHAR(N)
     */
    private static function mapString(array $params): string {
        if (empty($params)) {
            return 'VARCHAR(255)'; // default
        }
        $length = $params[0];
        return "VARCHAR({$length})";
    }

    /**
     * Mapea char(N) → CHAR(N)
     */
    private static function mapChar(array $params): string {
        if (empty($params)) {
            return 'CHAR(1)'; // default
        }
        $length = $params[0];
        return "CHAR({$length})";
    }

    /**
     * Mapea decimal(P,S) → DECIMAL(P,S)
     */
    private static function mapDecimal(array $params): string {
        if (count($params) < 2) {
            return 'DECIMAL(10,2)'; // default
        }
        $precision = $params[0];
        $scale = $params[1];
        return "DECIMAL({$precision},{$scale})";
    }

    /**
     * Mapea enum(val1,val2,...) → ENUM('val1','val2',...)
     * Escapa comillas en valores si existen.
     */
    private static function mapEnum(array $params): string {
        if (empty($params)) {
            throw new \RuntimeException("enum() requires at least 2 values");
        }
        
        $escaped = array_map(function($val) {
            // Escapa comillas simples
            return "'" . str_replace("'", "''", $val) . "'";
        }, $params);
        
        $joined = implode(',', $escaped);
        return "ENUM({$joined})";
    }

    /**
     * Obtiene el tipo MySQL por defecto para un tipo base (sin parámetros).
     * Útil para retrocompatibilidad.
     */
    public static function getDefault(string $baseType): string {
        return match (strtolower($baseType)) {
            'string', 'varchar' => 'VARCHAR(255)',
            'char', 'character' => 'CHAR(1)',
            'text' => 'TEXT',
            'int', 'integer' => 'INT',
            'bigint' => 'BIGINT',
            'smallint' => 'SMALLINT',
            'tinyint' => 'TINYINT',
            'float', 'double' => 'FLOAT',
            'decimal', 'numeric' => 'DECIMAL(10,2)',
            'boolean', 'bool' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'year' => 'YEAR',
            'uuid' => 'CHAR(36)',
            'blob', 'longblob' => 'LONGBLOB',
            'json' => 'JSON',
            'enum' => throw new \RuntimeException("enum requires explicit values"),
            default => throw new \RuntimeException("Unknown type: {$baseType}")
        };
    }
}
