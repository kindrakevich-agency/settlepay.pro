<?php

namespace App\Service\Auth;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies a Google Identity Services ID token (JWT).
 *
 * Flow:
 *   1. Decode the JWT header to find the `kid` (key id).
 *   2. Fetch Google's JWKS (https://www.googleapis.com/oauth2/v3/certs),
 *      cached for 1 hour. The JWKS is a small list of RSA public keys.
 *   3. Use openssl_verify() to confirm the JWT signature was produced
 *      by Google's matching private key.
 *   4. Validate the payload's iss / aud / exp / iat claims.
 *   5. Return the verified claims array on success, or throw.
 *
 * Done without firebase/php-jwt to keep the dep surface minimal — JWT
 * verification is ~60 lines of openssl + base64url + json.
 */
final class GoogleTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ALLOWED_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const CACHE_KEY = 'google_jwks_v3';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $googleClientId,
    ) {}

    /**
     * @return array{sub: string, email: string, email_verified: bool, name?: string, given_name?: string, family_name?: string, picture?: string, locale?: string}
     * @throws \DomainException on any verification failure
     */
    public function verify(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new \DomainException('Malformed JWT');
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $header  = json_decode($this->b64UrlDecode($headerB64), true);
        $payload = json_decode($this->b64UrlDecode($payloadB64), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new \DomainException('Malformed JWT JSON');
        }
        $alg = $header['alg'] ?? '';
        $kid = $header['kid'] ?? '';
        if ($alg !== 'RS256') {
            throw new \DomainException("Unsupported JWT alg: $alg");
        }
        if (!$kid) {
            throw new \DomainException('JWT missing kid');
        }

        $key = $this->findJwk($kid);
        $pem = $this->jwkToPem($key);
        $signed = "$headerB64.$payloadB64";
        $signature = $this->b64UrlDecode($sigB64);

        $ok = openssl_verify($signed, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new \DomainException('JWT signature invalid');
        }

        // Claims validation
        $now = time();
        $iss = (string) ($payload['iss'] ?? '');
        $aud = (string) ($payload['aud'] ?? '');
        $exp = (int) ($payload['exp'] ?? 0);
        $iat = (int) ($payload['iat'] ?? 0);

        if (!in_array($iss, self::ALLOWED_ISSUERS, true)) {
            throw new \DomainException("Bad issuer: $iss");
        }
        if ($aud !== $this->googleClientId) {
            throw new \DomainException('JWT aud does not match our client id');
        }
        if ($exp < $now) {
            throw new \DomainException('JWT expired');
        }
        if ($iat > $now + 60) {
            throw new \DomainException('JWT issued in the future');
        }
        if (empty($payload['email']) || empty($payload['sub'])) {
            throw new \DomainException('JWT missing email or sub');
        }
        if (empty($payload['email_verified'])) {
            throw new \DomainException('Google says email is not verified');
        }

        return $payload;
    }

    /** @return array{kid: string, n: string, e: string, kty: string, alg: string, use: string} */
    private function findJwk(string $kid): array
    {
        $jwks = $this->fetchJwks();
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }
        // kid not found — JWKS may have rotated. Bust the cache and retry once.
        $this->cache->deleteItem(self::CACHE_KEY);
        $jwks = $this->fetchJwks();
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }
        throw new \DomainException("No JWK with kid=$kid");
    }

    private function fetchJwks(): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            return $item->get();
        }
        $response = $this->http->request('GET', self::JWKS_URL, ['timeout' => 5]);
        $jwks = $response->toArray();
        $item->set($jwks)->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);
        return $jwks;
    }

    /** Convert a JWK (n, e) to a PEM-encoded RSA public key for openssl_verify. */
    private function jwkToPem(array $jwk): string
    {
        $n = $this->b64UrlDecode($jwk['n']);
        $e = $this->b64UrlDecode($jwk['e']);

        // ASN.1 DER encoding of an RSA public key (PKCS#1).
        $modulus  = "\x02" . $this->derLen(strlen($n) + 1) . "\x00" . $n;
        $exponent = "\x02" . $this->derLen(strlen($e)) . $e;
        $rsaSeq   = "\x30" . $this->derLen(strlen($modulus . $exponent)) . $modulus . $exponent;

        $algoOid  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // OID 1.2.840.113549.1.1.1 NULL
        $bitStr   = "\x03" . $this->derLen(strlen($rsaSeq) + 1) . "\x00" . $rsaSeq;
        $spki     = "\x30" . $this->derLen(strlen($algoOid . $bitStr)) . $algoOid . $bitStr;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function derLen(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xff) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function b64UrlDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($s, '-_', '+/'));
    }
}
