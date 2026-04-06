<?php
declare(strict_types=1);

require_once __DIR__ . '/Golos/Exceptions/AuthException.php';
// ... resto de requires

use Golos\{Connection, Auth, Permissions, Router, Request, Response};
use Golos\Exceptions\{AuthException, PermissionException, TenantException};

header('Content-Type: application/json');
header('X-Powered-By: Gosplan');

$project = strtolower($_GET['project'] ?? '');
$path    = trim($_GET['path'] ?? '', '/');

if (!$project) {
    Response::error('Project required', 400);
    exit;
}
$schemaFile = __DIR__ . "/schemas/gosplan_{$project}.json";
if (!file_exists($schemaFile)) {
    Response::error("Project '{$project}' not found", 404);
    exit;
}

$schema = Parser::parseFile($schemaFile);

if ($path === 'auth/login')    { Auth::handleLogin();    exit; }
if ($path === 'auth/refresh')  { Auth::handleRefresh();  exit; }

try {
    $token   = Auth::extractToken();
    $payload = Auth::verify($token);
} catch (AuthException $e) {
    Response::error($e->getMessage(), 401);
    exit;
}

try {
    $tenantId = Connection::resolveTenant($payload);
    // verifica X-Tenant header contra jwt.tenants
    // verifica que el proyecto esté en los proyectos del tenant
} catch (TenantException $e) {
    Response::error($e->getMessage(), 403);
    exit;
}

try {
    $systemDb = Connection::system();
    $tenantDb = Connection::tenant($tenantId);
} catch (\Exception $e) {
    Response::error('Database unavailable', 503);
    exit;
}

$route = Router::resolve($path, $_SERVER['REQUEST_METHOD']);
// → ['table' => 'tasks', 'id' => '123', 'action' => 'list']

try {
    Permissions::check(
        db:      $systemDb,
        userId:  $payload['userId'],
        project: $project,
        table:   $route['table'],
        action:  $route['action'],
    );
} catch (PermissionException $e) {
    Response::error($e->getMessage(), 403);
    exit;
}

$request = Request::fromGlobals($schema, $route, $project);
$query   = $request->toFluent()->build();

Router::dispatch($route['action'], $query, $tenantDb, $schema);