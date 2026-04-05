<?php

declare(strict_types=1);
namespace Pravda;

class SchemaCommand {

    /**
     * Genera el SQL CREATE TABLE para una tabla.
     * 
     * @param  string $tableName Nombre de la tabla
     * @param  array  $fields    Campos parseados por Parser::parseTable
     * @return string SQL CREATE TABLE completo
     */
    public static function generateCreateTableSQL(string $tableName, array $fields): string {
        $tableName = trim($tableName);
        $columnLines = [];
        $constraints = []; // Para FK que van al final

        foreach ($fields as $columnName => $fieldSpec) {
            $columnName = trim($columnName);
            $columnDef = self::buildColumnDefinition($columnName, $fieldSpec);
            $columnLines[] = $columnDef;
            if ($fieldSpec['ref'] ?? null) {
                $constraints[] = self::buildForeignKeyConstraint($columnName, $fieldSpec['ref']);
            }
        }

        $allLines = array_merge($columnLines, $constraints);
        $sqlBody = implode(",\n  ", $allLines);

        return "CREATE TABLE `{$tableName}` (\n  {$sqlBody}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    /**
     * Genera el SQL DROP TABLE para una tabla.
     * 
     * @param  string $tableName Nombre de la tabla
     * @return string SQL DROP TABLE
     */
    public static function generateDropTableSQL(string $tableName): string {
        $tableName = trim($tableName);
        return "DROP TABLE IF EXISTS `{$tableName}`;";
    }

    /**
     * Genera migraciones reversibles (UP + DOWN) para todas las tablas.
     * 
     * @param  array $schema Schema parseado con tablas
     * @return array ["up" => [...SQL CREATE], "down" => [...SQL DROP]]
     */
    public static function generateMigration(array $schema): array {
        $up = [];
        $down = [];

        // UP: crear tablas en orden (sin resolver FK dependencies por ahora)
        foreach ($schema as $tableName => $fields) {
            $up[] = self::generateCreateTableSQL($tableName, $fields);
        }

        // DOWN: dropar tablas en orden reverso (para respetar FK constraints)
        $tableNames = array_reverse(array_keys($schema));
        foreach ($tableNames as $tableName) {
            $down[] = self::generateDropTableSQL($tableName);
        }

        return [
            'up' => $up,
            'down' => $down
        ];
    }

    /**
     * Construye la definición de una columna SQL.
     * Ej: "`title` VARCHAR(255) NOT NULL"
     */
    private static function buildColumnDefinition(string $columnName, array $fieldSpec): string {
        $columnName = "`{$columnName}`";
        $type = $fieldSpec['type'];
        $parts = [$columnName, $type];

        // PRIMARY KEY y AUTO_INCREMENT
        if ($fieldSpec['primary'] ?? false) {
            $parts[] = 'PRIMARY KEY';
            if ($fieldSpec['autoIncrement'] ?? false) {
                $parts[] = 'AUTO_INCREMENT';
            }
        }

        // UNSIGNED
        if ($fieldSpec['unsigned'] ?? false) {
            $parts[] = 'UNSIGNED';
        }

        // NOT NULL / NULL
        if ($fieldSpec['required'] ?? false) {
            $parts[] = 'NOT NULL';
        } elseif ($fieldSpec['nullable'] ?? false) {
            $parts[] = 'NULL';
        }

        // UNIQUE (solo si no es PRIMARY KEY)
        if (($fieldSpec['unique'] ?? false) && !($fieldSpec['primary'] ?? false)) {
            $parts[] = 'UNIQUE';
        }

        // INDEX (solo si no es PRIMARY KEY)
        if (($fieldSpec['index'] ?? false) && !($fieldSpec['primary'] ?? false)) {
            $parts[] = 'KEY';
        }

        // DEFAULT
        $default = $fieldSpec['default'];
        if ($default !== null) {
            if (is_bool($default)) {
                $parts[] = 'DEFAULT ' . ($default ? '1' : '0');
            } elseif (is_numeric($default)) {
                $parts[] = "DEFAULT {$default}";
            } else {
                // String — envolver en comillas
                $escaped = str_replace("'", "''", $default);
                $parts[] = "DEFAULT {$escaped}";
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Construye una definición de FOREIGN KEY para una columna.
     * Ej: "CONSTRAINT fk_tasks_userId FOREIGN KEY (`userId`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE"
     * 
     * Soporta parámetros en $ref:
     * - $ref['delete'] = 'CASCADE' | 'RESTRICT' | 'SET NULL' | 'NO ACTION' | 'SET DEFAULT'
     * - $ref['update'] = 'CASCADE' | 'RESTRICT' | 'SET NULL' | 'NO ACTION' | 'SET DEFAULT'
     */
    private static function buildForeignKeyConstraint(string $columnName, array $ref): string {
        $refTable = $ref['table'];
        $refColumn = $ref['column'];
        
        $colName = "`{$columnName}`";
        $refTable = "`{$refTable}`";
        $refColumn = "`{$refColumn}`";

        // Configuración de acciones (con defaults)
        $onDelete = $ref['delete'] ?? 'CASCADE';
        $onUpdate = $ref['update'] ?? 'CASCADE';

        $constraintName = "fk_" . strtolower(str_replace('-', '_', uniqid()));
        
        return "CONSTRAINT {$constraintName} FOREIGN KEY ({$colName}) REFERENCES {$refTable}({$refColumn}) ON DELETE {$onDelete} ON UPDATE {$onUpdate}";
    }

    /**
     * Genera un archivo de migración PHP (opcional, para integración futura con frameworks).
     * 
     * @param  array  $migrations ["up" => [...], "down" => [...]]
     * @param  string $className  Nombre de la clase (ej: "CreateInitialSchema")
     * @return string PHP code
     */
    public static function generateMigrationFile(array $migrations, string $className = 'Migration'): string {
        $up = array_map(fn($sql) => "            '{$sql}'", $migrations['up']);
        $down = array_map(fn($sql) => "            '{$sql}'", $migrations['down']);

        $upSql = implode(",\n", $up);
        $downSql = implode(",\n", $down);

        return <<<PHP
<?php

declare(strict_types=1);

class {$className} {
    
    /**
     * Ejecuta la migración (UP).
     */
    public function up(): void {
        \$queries = [
{$upSql}
        ];

        foreach (\$queries as \$query) {
            // Ejecutar con tu PDO/conexión
            // \$pdo->exec(\$query);
        }
    }

    /**
     * Revierte la migración (DOWN).
     */
    public function down(): void {
        \$queries = [
{$downSql}
        ];

        foreach (\$queries as \$query) {
            // \$pdo->exec(\$query);
        }
    }
}
PHP;
    }
}
