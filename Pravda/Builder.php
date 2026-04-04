<?php

declare(strict_types=1);

namespace Pravda;

// Convierte el estado acumulado de Fluent en una Query.

class Builder {

    // private const NATIVE_FUNCTIONS = [
    //     'password_hash' => fn($v) => password_hash((string) $v, PASSWORD_BCRYPT),
    //     'floor' => fn($v) => (int) floor((float) $v),
    //     'ceil' => fn($v) => (int) ceil((float) $v),
    // ];

    // private static function resolveFunction(string $funcName, mixed $value) {
    //     if(isset(self::NATIVE_FUNCTIONS[$funcName])) {
    //         return (self::NATIVE_FUNCTIONS[$funcName])($value);
    //     }
    //     throw new \RuntimeException(
    //         "Función '{$funcName}' no reconocida"
    //     );
    // }

    private static ?array $nativeFunctions = null;

    private static array $customFunctions = [];

    public static function registerFunction(string $name, callable $fn): void {
        self::$customFunctions[$name] = $fn;
    }

    private static function getNativeFunctions(): array {
        if (self::$nativeFunctions === null) {
            self::$nativeFunctions = [
                'password_hash' => fn($v) => password_hash((string) $v, PASSWORD_BCRYPT),
                'floor' => fn($v) => (int) floor((float) $v),
                'ceil' => fn($v) => (int) ceil((float) $v),
            ];
        }
        return self::$nativeFunctions;
    }

    public static function resolveFunction(string $funcName, mixed $value) {
        if (isset(self::$customFunctions[$funcName])) {
            return (self::$customFunctions[$funcName])($value);
        }
        $functions = self::getNativeFunctions();
        if(isset($functions[$funcName])) {
            return ($functions[$funcName])($value);
        }
        throw new \RuntimeException(
            "Función '{$funcName}' no reconocida"
        );
    }

    public static function compile(Fluent $fluent): Query {
        return match($fluent->getOperation()) {
            "SELECT" => self::compileSelect($fluent),
            "INSERT" => self::compileInsert($fluent),
            "UPDATE" => self::compileUpdate($fluent),
            "DELETE" => self::compileDelete($fluent),
            default => throw new \RuntimeException("Operación desconocida.")
        };
    }

    private static function compileSelect(Fluent $fluent): Query {
        $values = [];
        $parts = [];

        $distinct = $fluent->isDistinct() ? "DISTINCT " : "";
        $fields = implode(", ", $fluent->getFields());
        $parts[] = "SELECT {$distinct}{$fields}";

        $parts[] = "FROM `{$fluent->getTable()}`";

        foreach($fluent->getJoins() as $join) {
            $parts[] = self::compileJoin($join);
        }


        // WHERE
        if(!empty($fluent->getConditions())) {
            [$whereSql, $whereValues] = self::compileConditions($fluent->getConditions());
            $parts[] = "WHERE {$whereSql}";
            $values = array_merge($values, $whereValues);
        }

        // GROUP BY
        if(!empty($fluent->getGrouping())) {
            $cols = implode(", ", array_map(fn($c) => "`{$c}`", $fluent->getGrouping()));
            $parts[] = "GROUP BY {$cols}";
        }

        // HAVING
        if(!empty($fluent->getHaving())) {
            [$havingSql, $havingValues] = self::compileHaving($fluent->getHaving());
            $parts[] = "HAVING {$havingSql}";
            $values = array_merge($values, $havingValues);
        }

        // ORDER BY
        if(!empty($fluent->getOrdering())) {
            $parts[] = self::compileOrderBy($fluent->getOrdering());
        }

        // LIMIT
        if($fluent->getLimit() !== null) {
            $parts[] = "LIMIT ?";
            $values[] = $fluent->getLimit();
        }

        // OFFSET
        if($fluent->getOffset() !== null) {
            $parts[] = "OFFSET ?";
            $values[] = $fluent->getOffset();
        }

        return new Query(implode(" ", $parts), $values, "SELECT");
    }

    private static function compileInsert(Fluent $fluent): Query {
        $data = $fluent->getData();
        if(empty($data)) {
            throw new \RuntimeException("INSERT requiere datos");
        }

        $columns = array_keys($data);
        $resolvedData = self::resolveData($data);
        $values = array_values($resolvedData);

        $colsSql  = implode(", ", array_map(fn($c) => "`{$c}`", $columns));
        $holdSql  = implode(", ", array_fill(0, count($values), "?"));

        $statement = "INSERT INTO `{$fluent->getTable()}` ({$colsSql}) VALUES ({$holdSql})";

        return new Query($statement, $values, "INSERT");
    }

    private static function compileUpdate(Fluent $fluent): Query {
        $data = $fluent->getData();
        if(empty($data)) {
            throw new \RuntimeException("UPDATE require datos");
        }

        if(empty($fluent->getConditions())) {
            throw new \RuntimeException("UPDATE no permitido sin condiciones");
        }

        $resolvedData = self::resolveData($data);
        $values = [];

        $setSql = implode(", ", array_map(function ($col) use (&$values, $resolvedData) {
            $values[] = $resolvedData[$col];
            return "`{$col}` = ?";
        }, array_keys($resolvedData)));

        [$whereSql, $whereValues] = self::compileConditions($fluent->getConditions());
        $values = array_merge($values, $whereValues);

        $statement = "UPDATE `{$fluent->getTable()}` SET {$setSql} WHERE {$whereSql}";

        return new Query($statement, $values, "UPDATE");
    }

    private static function compileDelete(Fluent $fluent): Query {
        if(empty($fluent->getConditions())) {
            throw new \RuntimeException("DELETE no permitido sin condiciones");
        }


        $values = [];
        [$whereSql, $whereValues] = self::compileConditions($fluent->getConditions());
        $values = array_merge($values, $whereValues);

        $statement = "DELETE FROM `{$fluent->getTable()}` WHERE {$whereSql}";
        
        return new Query($statement, $values, "DELETE");
    }

    private static function compileConditions(array $conditions): array {
        $parts  = [];
        $values = [];
        $first  = true;

        foreach ($conditions as $cond) {
            $boolean = $first ? "" : " {$cond["boolean"]} ";
            $first   = false;

            switch ($cond["type"]) {
                case "basic":
                    $parts[]  = "{$boolean}`{$cond["column"]}` {$cond["operator"]} ?";
                    $values[] = self::castValue($cond["value"]);
                    break;

                case "null":
                    $op      = $cond["not"] ? "IS NOT NULL" : "IS NULL";
                    $parts[] = "{$boolean}`{$cond["column"]}` {$op}";
                    break;

                case "in":
                    $op          = $cond["not"] ? "NOT IN" : "IN";
                    $holders     = implode(", ", array_fill(0, count($cond["values"]), "?"));
                    $parts[]     = "{$boolean}`{$cond["column"]}` {$op} ({$holders})";
                    $values      = array_merge($values, array_map(fn($v) => self::castValue($v), $cond["values"]));
                    break;

                case "between":
                    $op      = $cond["not"] ? "NOT BETWEEN" : "BETWEEN";
                    $parts[] = "{$boolean}`{$cond["column"]}` {$op} ? AND ?";
                    $values[] = self::castValue($cond["from"]);
                    $values[] = self::castValue($cond["to"]);
                    break;

                case "raw":
                    $parts[]  = "{$boolean}{$cond["sql"]}";
                    $values   = array_merge($values, $cond["bindings"]);
                    break;

                case "group":
                    [$groupSql, $groupValues] = self::compileConditions($cond["clauses"]);
                    $parts[]  = "{$boolean}({$groupSql})";
                    $values   = array_merge($values, $groupValues);
                    break;
            }
        }

        return [implode("", $parts), $values];
    }

    private static function compileJoin(array $join): string {
        if ($join["type"] === "CROSS") {
            return "CROSS JOIN `{$join["table"]}`";
        }
        return "{$join["type"]} JOIN `{$join["table"]}` ON `{$join["localKey"]}` = `{$join["foreignKey"]}`";
    }

    private static function compileOrderBy(array $ordering): string {
        $parts = array_map(fn($o) => "`{$o["column"]}` {$o["direction"]}", $ordering);
        return "ORDER BY " . implode(", ", $parts);
    }

    private static function compileHaving(array $havingClauses): array {
        $parts  = [];
        $values = [];
        $first  = true;
        foreach ($havingClauses as $h) {
            $boolean  = $first ? "" : " {$h["boolean"]} ";
            $first    = false;
            $parts[]  = "{$boolean}`{$h["column"]}` {$h["operator"]} ?";
            $values[] = self::castValue($h["value"]);
        }
        return [implode("", $parts), $values];
    }

    private static function resolveData(array $data): array {
        $resolved = [];
        foreach ($data as $col => $value) {
            $resolved[$col] = ($value instanceof \Closure)? $value() : self::castValue($value);
        }
        return $resolved;
    }

    private static function castValue(mixed $value): mixed {
        return match(true) {
            is_bool($value) => (int) $value,
            is_null($value) => null,
            default         => $value,
        };
    }


}