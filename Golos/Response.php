<?php

declare(strict_types=1);

// JSON + headers

class Response {
    public static function json(mixed $data, int $status = 200): void {
        http_response_code($status);
        echo json_encode([
            'data'    => $data,
            'status'  => $status,
            'success' => $status < 400,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function error(string $message, int $status = 400): void {
        http_response_code($status);
        echo json_encode([
            'error'   => $message,
            'status'  => $status,
            'success' => false,
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function paginated(array $data, int $total, int $page, int $limit): void {
        self::json([
            'data'       => $data,
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'limit'    => $limit,
                'pages'    => (int) ceil($total / $limit),
                'hasMore'  => ($page * $limit) < $total,
            ],
        ]);
    }
}