<?php

// Manejar PDDs (system + tenant)

class Connection {
    private static ?PDO $system = null;
    private static array $tenants = [];   // cache por request

    public static function system(): PDO {
        if (self::$system === null) {
            self::$system = self::connect(
                db: 'gosplan_system',
                host: $_ENV['DB_HOST'],
                user: $_ENV['DB_USER'],
                pass: $_ENV['DB_PASS'],
            );
        }
        return self::$system;
    }

    public static function tenant(string $tenantId): PDO {
        if (!isset(self::$tenants[$tenantId])) {
            // Validar formato UUID para evitar SQL injection en el nombre de la db
            if (!preg_match('/^gosplan_[a-f0-9\-]{36}$/', $tenantId)) {
                throw new TenantException("Invalid tenant ID format");
            }

            self::$tenants[$tenantId] = self::connect(
                db:   $tenantId,
                host: $_ENV['DB_HOST'],
                user: $_ENV['DB_USER'],
                pass: $_ENV['DB_PASS'],
            );
        }
        return self::$tenants[$tenantId];
    }

    /**
     * Resuelve el tenant desde JWT payload + X-Tenant header.
     *
     * Valida que:
     * 1. X-Tenant header esté presente
     * 2. El tenant esté en jwt.tenants
     * 3. El proyecto esté en los proyectos del tenant
     */
    public static function resolveTenant(array $payload, string $project): string
    {
        $tenantId = $_SERVER['HTTP_X_TENANT'] ?? null;

        if (!$tenantId) {
            throw new TenantException('X-Tenant header required');
        }

        $tenants = $payload['tenants'] ?? [];

        // jwt.tenants = { "gosplan_uuid": ["todoapp", "crm"] }
        if (!isset($tenants[$tenantId])) {
            throw new TenantException('Tenant not authorized for this token');
        }

        if (!in_array($project, $tenants[$tenantId], true)) {
            throw new TenantException("Project '{$project}' not available in this tenant");
        }

        return $tenantId;
    }

    private static function connect(string $db, string $host, string $user, string $pass): PDO {
        $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}