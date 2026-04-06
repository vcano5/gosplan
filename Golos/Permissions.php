<?php

// Resolución de mask

class Permissions {
    // Bits
    const CREATE = 0b1000;
    const READ   = 0b0100;
    const UPDATE = 0b0010;
    const DELETE = 0b0001;

    private const ACTION_MAP = [
        'list'   => self::READ,
        'show'   => self::READ,
        'create' => self::CREATE,
        'update' => self::UPDATE,
        'delete' => self::DELETE,
    ];

    public static function check(PDO $db, string $userId, string $project, string $table, string $action): void {
        $required = self::ACTION_MAP[$action] ?? 0;
        $effective = self::resolve($db, $userId, $project, $table);

        if (($effective & $required) === 0) {
            throw new PermissionException(
                "No permission to {$action} on {$project}/{$table}"
            );
        }
    }

    /**
     * Resuelve el mask efectivo acumulando wildcards.
     *
     * Orden de evaluación (OR acumulativo):
     *   *, *           → base global
     *   project, *     → override por proyecto
     *   project, table → override específico de tabla
     */
    public static function resolve(PDO $db, string $userId, string $project, string $table): int {
        $rows = $db->prepare("
            SELECT mask FROM gosplan_system_permissions
            WHERE userId = ?
            AND (
                (project = '*' AND `table` = '*')           OR
                (project = ?   AND `table` = '*')           OR
                (project = ?   AND `table` = ?)
            )
            ORDER BY
                CASE
                    WHEN project = '*' AND `table` = '*' THEN 1
                    WHEN project = ?   AND `table` = '*' THEN 2
                    WHEN project = ?   AND `table` = ?   THEN 3
                END ASC
        ");

        $rows->execute([$userId, $project, $project, $table, $project, $project, $table]);
        $results = $rows->fetchAll();

        if (empty($results)) {
            return 0b0000; // sin acceso por defecto
        }

        // OR acumulativo — más específico puede ampliar pero no reducir
        return array_reduce(
            $results,
            fn(int $carry, array $row) => $carry | (int) $row['mask'],
            0b0000
        );
    }
}