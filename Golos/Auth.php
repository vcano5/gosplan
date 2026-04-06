<?php

// JWT, login, registro

class Auth {
    private const ALGORITHM = 'HS256';
    private const TTL       = 3600;        // 1 hora
    private const REFRESH   = 604800;      // 7 días

    public static function handleLogin(): void {
        $body = json_decode(file_get_contents('php://input'), true);

        $user = self::findUser($body['username'] ?? '');

        if (!$user || !password_verify($body['password'] ?? '', $user['password_hash'])) {
            Response::error('Invalid credentials', 401);
            return;
        }

        // Cargar tenants del usuario desde gosplan_system
        $tenants = self::loadTenants($user['id']);

        $token = self::generate([
            "sub" => $user["username"],
            "userId" =>  $user["id"],
            "tenants" => $tenants,
            // { "gosplan_uuid1": ["todoapp","crm"], "gosplan_uuid2": ["todoapp"] }
        ]);

        // Guardar refresh token hasheado
        self::saveRefreshToken($user['id'], $token['refresh']);

        Response::json([
            "token" => $token["access"],
            "refresh" => $token["refresh"],
            "expires" => time() + self::TTL,
        ]);
    }

    public static function verify(string $token): array {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new AuthException('Invalid token format');
        }

        [$header, $payload, $signature] = $parts;

        // Verificar firma
        $expected = self::sign("{$header}.{$payload}");
        if (!hash_equals($expected, $signature)) {
            throw new AuthException('Invalid token signature');
        }

        $decoded = json_decode(self::base64Decode($payload), true);

        // Verificar expiración
        if (($decoded['exp'] ?? 0) < time()) {
            throw new AuthException('Token expired');
        }

        return $decoded;
    }

    public static function extractToken(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            throw new AuthException('Authorization header required');
        }

        $token = trim(substr($header, 7));

        if (empty($token)) {
            throw new AuthException('Token required');
        }

        return $token;
    }

    private static function generate(array $claims): array {
        $now = time();

        // Access token
        $accessPayload = array_merge($claims, [
            "iat" => $now,
            "exp" => $now + self::TTL,
            "type" => "access",
        ]);

        $access = self::encode($accessPayload);

        // Refresh token (más largo, menos claims)
        $refreshPayload = [
            "sub" => $claims['sub'],
            "userId" => $claims['userId'],
            "iat" => $now,
            "exp" => $now + self::REFRESH,
            "type" =>"refresh",
        ];

        $refresh = self::encode($refreshPayload);

        return ['access' => $access, 'refresh' => $refresh];
    }

    private static function encode(array $payload): string {
        $header    = self::base64Encode(json_encode(['alg' => self::ALGORITHM, 'typ' => 'JWT']));
        $body      = self::base64Encode(json_encode($payload));
        $signature = self::sign("{$header}.{$body}");
        return "{$header}.{$body}.{$signature}";
    }

    private static function sign(string $data): string {
        return self::base64Encode(
            hash_hmac('sha256', $data, $_ENV['JWT_SECRET'], true)
        );
    }

    private static function base64Encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64Decode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private static function findUser(string $username): ?array { /* SELECT desde gosplan_system */ }
    private static function loadTenants(string $userId): array { /* SELECT desde gosplan_system.tenant_databases JOIN permissions */ }
    private static function saveRefreshToken(string $userId, string $token): void { /* INSERT hash */ }
}