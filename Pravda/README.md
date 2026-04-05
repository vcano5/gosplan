Este proyecto genera statements para ejecutar en una base de datos.

El archivo gosplan.json es la base de todo
```json
{
  "users": {
    "name": "string(255)|@required",
    "role": "enum(admin,editor,user)|@default(user)",
    "balance": "decimal(10,2)",
    "email": "string(255)|@required|@unique"
  }
}
```

## Agregar en index.php

```php
require_once __DIR__ . '/Pravda/Query.php';
require_once __DIR__ . '/Pravda/Builder.php';
require_once __DIR__ . '/Pravda/Fluent.php';
require_once __DIR__ . '/Pravda/Parser.php';
require_once __DIR__ . '/Pravda/TypeParser.php';
require_once __DIR__ . '/Pravda/TypeMapper.php';
require_once __DIR__ . '/Pravda/SchemaCommand.php';

use Pravda\{Fluent, Builder, Parser, TypeParser, TypeMapper, SchemaCommand};
```

# Ejemplo de uso
```php
<?php

declare(strict_types=1);



// ─── Registrar función custom ─────────────────────────────────────────────────
Builder::registerFunction('slug', function(string $v): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($v)));
});

// 1. Parser

$parsed = Parser::parseFile("gosplan.json");

echo "\nTabla 'users' → campo 'role' (enum):\n";
print_r($parsed['schema']['users']['role']);

echo "\nTabla 'users' → campo 'email' (string 255):\n";
print_r($parsed['schema']['users']['email']);

echo "\nTabla 'users' → campo 'balance' (decimal 10,2):\n";
print_r($parsed['schema']['users']['balance']);

// 1b. Verificar @ref con parámetros
echo "\nTabla 'tasks' → campo 'userId' (@ref:users.id con CASCADE):\n";
print_r($parsed['schema']['tasks']['userId']['ref']);

echo "\nTabla 'posts' → campo 'authorId' (@ref:users.id con RESTRICT/NO-ACTION):\n";
print_r($parsed['schema']['posts']['authorId']['ref']);

// 1c. SchemaCommand - Generar DDL 

// Generar CREATE TABLE para 'users'
$createUsers = SchemaCommand::generateCreateTableSQL('users', $parsed['schema']['users']);
echo "\nCREATE TABLE users:\n{$createUsers}\n";

// Generar CREATE TABLE para 'tasks' (con CASCADE)
$createTasks = SchemaCommand::generateCreateTableSQL('tasks', $parsed['schema']['tasks']);
echo "\nCREATE TABLE tasks (con FK CASCADE):\n{$createTasks}\n";

// Generar CREATE TABLE para 'posts' (con RESTRICT/NO-ACTION)
$createPosts = SchemaCommand::generateCreateTableSQL('posts', $parsed['schema']['posts']);

// Generar migraciones completas (UP + DOWN)
$migration = SchemaCommand::generateMigration($parsed['schema']);

// 2. SELECT simple 

$q = Fluent::table('tasks')
    ->select(['title', 'completed', 'userId'])
    ->where('completed', '=', false)
    ->where('userId', 'abc-123')
    ->orderBy('title')
    ->limit(10)
    ->offset(0)
    ->build();

// 3. WHERE con grupo anidado (AND/OR) 

$q = Fluent::table('tasks')
    ->where('completed', false)
    ->where(function($sub) {
        $sub->where('userId', 'abc-123')
            ->orWhere('userId', 'xyz-456');
    })
    ->build();

// 4. JOIN
$q = Fluent::table('tasks')
    ->select(['tasks.title', 'users.name AS author'])
    ->leftJoin('users', 'tasks.userId', 'users.id')
    ->where('tasks.completed', false)
    ->orderBy('tasks.title')
    ->build();

// 5. WHERE IN / BETWEEN / NULL

$q = Fluent::table('tasks')
    ->whereIn('userId', ['id-1', 'id-2', 'id-3'])
    ->whereNotNull('parentId')
    ->whereBetween('created_at', '2024-01-01', '2024-12-31')
    ->build();

// 6. INSERT con función PHP

$rawPassword = 'vivaperu';
$resolvedPass = Builder::resolveFunction('password_hash', $rawPassword);

$q = Fluent::insert('users', [
    'name'     => 'Juan',
    'password' => $resolvedPass,
])->build();

// 7. INSERT múltiple
$q = Fluent::insert('posts', [
    'title' => 'Mi Primer Post',
    'slug' => Builder::resolveFunction('slug', 'Mi Primer Post'),
    'content' => 'Lorem ipsum...',
    'authorId' => 'user-123',
    'views' => 0
])->build();

// 8. UPDATE
$q = Fluent::table('tasks')
    ->update('tasks', ['completed' => true])
    ->where('userId', 'user-123')
    ->build();

// 9. DELETE
$q = Fluent::table('tasks')
    ->delete('tasks')
    ->where('completed', true)
    ->where('userId', 'user-123')
    ->build();

```