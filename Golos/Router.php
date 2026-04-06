<?php

declare(strict_types=1);

// URL -> Acción

class Router {
    /**
     * Convierte path + método HTTP en una acción.
     *
     * GET    /tasks          → list
     * GET    /tasks/123      → show
     * POST   /tasks          → create
     * PUT    /tasks/123      → update
     * PATCH  /tasks/123      → update
     * DELETE /tasks/123      → delete
     */
    public static function resolve(string $path, string $method): array {
        $segments = explode('/', trim($path, '/'));
        $table    = $segments[0] ?? null;
        $id       = $segments[1] ?? null;

        if (!$table) {
            Response::error('Resource required', 400);
            exit;
        }

        $action = match(true) {
            $method === 'GET'    && !$id => 'list',
            $method === 'GET'    && !!$id => 'show',
            $method === 'POST'   && !$id => 'create',
            $method === 'PUT'    && !!$id => 'update',
            $method === 'PATCH'  && !!$id => 'update',
            $method === 'DELETE' && !!$id => 'delete',
            default => null,
        };

        if (!$action) {
            Response::error('Method not allowed', 405);
            exit;
        }

        return [
            'table'  => $table,
            'id'     => $id,
            'action' => $action,
        ];
    }

    public static function dispatch(string $action, Query $query, PDO $db, array $schema): void {
        $stmt = $db->prepare($query->statement());
        $stmt->execute($query->values());

        $data = match($action) {
            'list'   => $stmt->fetchAll(),
            'show'   => $stmt->fetch() ?: null,
            'create' => ['id' => $db->lastInsertId()],
            'update',
            'delete' => ['affected' => $stmt->rowCount()],
        };

        if ($action === 'show' && $data === null) {
            Response::error('Not found', 404);
            return;
        }

        $status = match($action) {
            'create' => 201,
            'delete' => 200,
            default  => 200,
        };

        Response::json($data, $status);
    }
}