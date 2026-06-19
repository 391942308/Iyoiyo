<?php
class JWT {
    private static $secret = 'IYOIYO-JWT-SECRET-CHANGE-ME';
    private static $expire = 604800; // 7 days

    public static function generate(array $payload): string {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload['iat'] = time();
        $payload['exp'] = time() + self::$expire;

        $headerEnc = self::base64UrlEncode(json_encode($header));
        $payloadEnc = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$headerEnc.$payloadEnc", self::$secret, true)
        );
        return "$headerEnc.$payloadEnc.$signature";
    }

    public static function verify(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$headerEnc, $payloadEnc, $signature] = $parts;

        $validSig = self::base64UrlEncode(
            hash_hmac('sha256', "$headerEnc.$payloadEnc", self::$secret, true)
        );
        if (!hash_equals($validSig, $signature)) return null;

        $payload = json_decode(self::base64UrlDecode($payloadEnc), true);
        if (!$payload) return null;
        if (isset($payload['exp']) && $payload['exp'] < time()) return null;
        return $payload;
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
