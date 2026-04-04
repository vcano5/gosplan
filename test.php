<?php

declare(strict_types=1);

require_once __DIR__ . '/Pravda/Query.php';
require_once __DIR__ . '/Pravda/Builder.php';
require_once __DIR__ . '/Pravda/Fluent.php';
require_once __DIR__ . '/Pravda/Parser.php';

use Pravda\{Fluent, Builder, Parser};

// ─── Registrar función custom ─────────────────────────────────────────────────
Builder::registerFunction('slug', function(string $v): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($v)));
});

echo "=== PRAVDA TEST SUITE ===\n\n";

// ─── 1. Parser ────────────────────────── ──────────────────────────────────────
echo "── Parser ──────────────────────────────────────────────\n";


$parsed = Parser::parseFile("gosplan.json");

echo "Tabla 'tasks' → campo 'title':\n";
print_r($parsed['schema']['tasks']['title']);

echo "\nTabla 'users' → campo 'password' (tiene @default con función):\n";
print_r($parsed['schema']['users']['password']);

// ─── 2. SELECT simple ─────────────────────────────────────────────────────────
echo "\n── SELECT simple ───────────────────────────────────────\n";

$q = Fluent::table('tasks')
    ->select(['title', 'completed', 'userId'])
    ->where('completed', '=', false)
    ->where('userId', 'abc-123')
    ->orderBy('title')
    ->limit(10)
    ->offset(0)
    ->build();

echo $q->toDebugString() . "\n";
echo "Values: " . json_encode($q->values()) . "\n";

// ─── 3. WHERE con grupo anidado (AND/OR) ─────────────────────────────────────
echo "\n── WHERE con grupo ─────────────────────────────────────\n";

$q = Fluent::table('tasks')
    ->where('completed', false)
    ->where(function($sub) {
        $sub->where('userId', 'abc-123')
            ->orWhere('userId', 'xyz-456');
    })
    ->build();

echo $q->toDebugString() . "\n";

// ─── 4. JOIN ─────────────────────────────────────────────────────────────────
echo "\n── JOIN ────────────────────────────────────────────────\n";

$q = Fluent::table('tasks')
    ->select(['tasks.title', 'users.name AS author'])
    ->leftJoin('users', 'tasks.userId', 'users.id')
    ->where('tasks.completed', false)
    ->orderBy('tasks.title')
    ->build();

echo $q->toDebugString() . "\n";

// ─── 5. WHERE IN / BETWEEN / NULL ────────────────────────────────────────────
echo "\n── WHERE IN / BETWEEN / NULL ───────────────────────────\n";

$q = Fluent::table('tasks')
    ->whereIn('userId', ['id-1', 'id-2', 'id-3'])
    ->whereNotNull('parentId')
    ->whereBetween('created_at', '2024-01-01', '2024-12-31')
    ->build();

echo $q->toDebugString() . "\n";

// ─── 6. INSERT con función PHP ────────────────────────────────────────────────
echo "\n── INSERT con password_hash ─────────────────────────────\n";

// Así es como el dev usaría Builder::resolveFunction para aplicar
// la función definida en el gosplan antes de insertar
$rawPassword = 'vivaperu';
$resolvedPass = Builder::resolveFunction('password_hash', $rawPassword);

$q = Fluent::insert('users', [
    'name'     => 'Juan',
    'password' => $resolvedPass,
])->build();

echo $q->toDebugString() . "\n";
echo "¿Password hash? " . (str_starts_with($resolvedPass, '$2y$') ? 'SÍ' : 'NO') . "\n";

// ─── 7. INSERT con Closure ────────────────────────────────────────────────────
echo "\n── INSERT con Closure inline ────────────────────────────\n";

$q = Fluent::insert('tasks', [
    'title'     => 'Mi tarea',
    'completed' => false,
    'userId'    => 'abc-123',
    'slug'      => fn() => Builder::resolveFunction('slug', 'Mi tarea'),
])->build();

echo $q->toDebugString() . "\n";

// ─── 8. UPDATE ───────────────────────────────────────────────────────────────
echo "\n── UPDATE ──────────────────────────────────────────────\n";

$q = Fluent::update('tasks', ['completed' => true])
    ->where('id', 42)
    ->build();

echo $q->toDebugString() . "\n";

// ─── 9. DELETE ───────────────────────────────────────────────────────────────
echo "\n── DELETE ──────────────────────────────────────────────\n";

$q = Fluent::delete('tasks')
    ->where('userId', 'abc-123')
    ->where('completed', true)
    ->build();

echo $q->toDebugString() . "\n";

// ─── 10. GROUP BY / HAVING / DISTINCT ────────────────────────────────────────
echo "\n── GROUP BY / HAVING / DISTINCT ────────────────────────\n";

$q = Fluent::table('tasks')
    ->select(['userId', 'COUNT(*) AS total'])
    ->groupBy('userId')
    ->having('total', '>', 5)
    ->orderByDesc('total')
    ->build();

echo $q->toDebugString() . "\n";

// ─── 11. Paginación ──────────────────────────────────────────────────────────
echo "\n── Paginación ──────────────────────────────────────────\n";

$q = Fluent::table('tasks')
    ->where('completed', false)
    ->orderBy('created_at', 'DESC')
    ->paginate(page: 3, perPage: 15)
    ->build();

echo $q->toDebugString() . "\n";
echo "Values (limit/offset): " . json_encode($q->values()) . "\n";

// ─── 12. UPDATE sin WHERE = excepción ────────────────────────────────────────
echo "\n── Seguridad: UPDATE sin WHERE ─────────────────────────\n";

try {
    $q = Fluent::update('tasks', ['completed' => true])->build();
} catch (\RuntimeException $e) {
    echo "Bloqueado correctamente: " . $e->getMessage() . "\n";
}

echo "\n=== TODO OK ===\n";