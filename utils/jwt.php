<?php
// Simple JWT implementation (HS256) - no external dependencies needed

define('JWT_SECRET', getenv('JWT_SECRET') ?: '09dfe0e12600e806512b42e7a7026a25062dbfd1bc884736d83eb857a9f80498');

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwtEncode(array $payload): string
{
    $header = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload['iat'] = time();
    $payload['exp'] = time() + (7 * 24 * 60 * 60); // 7 days
    $payloadEncoded = base64UrlEncode(json_encode($payload));
    $signature = hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true);
    $signatureEncoded = base64UrlEncode($signature);
    return "$header.$payloadEncoded.$signatureEncoded";
}

function jwtDecode(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;

    $validSignature = base64UrlEncode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($validSignature, $signature)) return null;

    $data = json_decode(base64UrlDecode($payload), true);
    if (!$data) return null;

    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

function getBearerToken(): ?string
{
    $headers = [];
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    } else {
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerKey = str_replace('_', '-', substr($key, 5));
                $headers[$headerKey] = $value;
            }
        }
    }

    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return $matches[1];
    }
    return null;
}
