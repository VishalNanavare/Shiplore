<?php

declare(strict_types=1);

use App\Libraries\Firebase\FirebaseIdTokenVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Firebase/FirebaseIdTokenVerifier.php';

/**
 * Server-side verification of a Firebase Phone-Auth ID token: a token signed by
 * the expected key with the right claims is accepted; wrong audience / issuer /
 * expiry / signature / key id are all rejected. A fixed RSA key + self-signed
 * cert are embedded so signing/verifying needs no openssl config (portable).
 */
final class FirebaseIdTokenVerifierTest extends TestCase
{
    private const PRIV = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC4AfIQxrMJ154f
HdJEHgf2Fxp4hSaBaEfU7qzjUPP3qUMgbNKKcxNBstQ9+mgWp5p3oTndT9YpsmvI
v+jf9w9KmweiJd8mJbPz4Eb2FKED5iOmgWhckuiEHmy+bZd5VYxxQnfk7w7gdbt/
lioV8wfMg49ccrzf4K+T5E8yrvLWJeayFrjfT79SGqT/Ptszd3jv/Bbhx+Hgp+Gf
o4x3j0qxpH27F1X6fEhljjlwbqfgFLt4dJBz8ltxRXd05GV/8slSGLrvutrE2cX2
xG/MmYyGhOSbIKy8qlBOomKTvXJEdeaHXL2CKzBmGcdru7t27sWOBUfBbNUzjiB4
m9z7HGZ9AgMBAAECggEAFGOyzQUXhbmsvcnyKLYjL7OzrTMj5ycE/qVL5wxrXEAX
PhqQ4eKKebc1KYD707bSuPrWjJ1yH8CzjNUnGtoniZ7QI7mKlpGK9TUe59m1VddE
kAi65bcpqKouZpOCO2GtZEd3PZj3zwt8sVCUbUY20QSq+BWGtFATZJHh7L3SDsSI
M1yXl9BWlsCMuoRgmopb8DBkWmoujq1j+KGITu7eT1sGGP0KdxT1/9AIjoPDz3ju
0UMQYiqcrr6tGCw5m//gOP0e7OUUxdU0kykMmmvwOHlUkuO2WgLNKRwBkZ86RGew
pwp5ccCHOEFPHMbR0ww/GKAJZ+4DSyuNh0XurnEcwQKBgQDmAASMU3uebNLI8A0w
WhDpbprk6NR87HpGCyZjogRYqJtPz6cgLqn14eLJinTXEzfLMZs0GaOBvm1f/4g3
JwV2/U9vOlQvnzMt9BOdSwoqrsAAkPa6Y9+D5LpBie+kQwtjVBM3qX+uNGvQgP9V
krz1v7q871u3rCXvv1K/XDXeFQKBgQDMzvMeXE+1MpVcnvuxO5Qo5xr0QMaFALrN
60v8ME93fdBfV7E77PqKv871RceI6b5UYrPLbHaDosqzmEiWr3QeDqhxo6JBkgQj
Yk6wZpvErMcKGqbWA5UslZP+bxcP8JEukd0kHq5ve56d8Bi8r7yzFHkk8Yg5VhhI
4KVWJtboyQKBgHHXFGk4cPlrN7GJT53dFn3T5wriSzpB+gttPWUXLjuLyMPqLfh5
4Fn5ojzLMSW7N2R1ezKAdjOjw5M+cXeK8uOAYa7WGhEwJS7bnlG+cJvLvvEIz3ZW
NK2dqqsB0QFmxd42IQTt+mqJO8wJ7Ve3t5uTeKRHfQgeRvCxrA1XYLo5AoGAS1x/
IxXOkpsZUKqDbLTCkMZxKZ1ILxqUoj7Jh/Ny2kImUV7gLW/GxRVNHv1dLajsyvpC
tECl30wgkDMhyqim7oRwQNh4VO5YrXh4AfrPqG/3EWW7LBbHZk9n1ICmGMxpb5xm
perQPt9a/zygrZVwtuh6pzhk6sweRXq7+9Zwz9kCgYAjegcFRsxbi8gP80TKh9Bl
kmQYjayk/JSekct3fbUlCiOkzVjQzt/hgfcpp5gjDou7ZZqb8QM0Dz8uyPPHAOMr
Is8IE27Za1lMdwbJoubZYrfloaOwejuwuzh3aeOq6JQ3OqH+f5zTuGyKu6IvyMX6
ml/v/t+9FXUoyx1aAjOerA==
-----END PRIVATE KEY-----
PEM;

    private const CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICkTCCAXmgAwIBAgIBADANBgkqhkiG9w0BAQUFADAMMQowCAYDVQQDDAF0MB4X
DTI2MDYxMzA2Mzc0NVoXDTM2MDYxMDA2Mzc0NVowDDEKMAgGA1UEAwwBdDCCASIw
DQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBALgB8hDGswnXnh8d0kQeB/YXGniF
JoFoR9TurONQ8/epQyBs0opzE0Gy1D36aBanmnehOd1P1imya8i/6N/3D0qbB6Il
3yYls/PgRvYUoQPmI6aBaFyS6IQebL5tl3lVjHFCd+TvDuB1u3+WKhXzB8yDj1xy
vN/gr5PkTzKu8tYl5rIWuN9Pv1IapP8+2zN3eO/8FuHH4eCn4Z+jjHePSrGkfbsX
Vfp8SGWOOXBup+AUu3h0kHPyW3FFd3TkZX/yyVIYuu+62sTZxfbEb8yZjIaE5Jsg
rLyqUE6iYpO9ckR15odcvYIrMGYZx2u7u3buxY4FR8Fs1TOOIHib3PscZn0CAwEA
ATANBgkqhkiG9w0BAQUFAAOCAQEAMsnrHcybpWsPcWjTk1d53V/0B2YICwSaBb22
Ot4ClqPVhGQMAIird5XAzZGSji27bOr57yKaxqsZV78nvlqpeqUeUHnh1G6jj0ML
3TKovHnRxc8RtG6SxQy2JDMzbKdcZirulUt5JOgLNY9pHUsEHRMUVqyuqDWF4wgQ
KvVZPoud2P9xuz2Q9RSGxkJkmCzn4C7nzvIFc8Qm7ZlEkCXevJ+LHK93I7T9gOfy
2z8jTbYq5BT4aWKO9E0VmSkdSvTL8vqcyrLis4szr/mQeViLJ2e2sKJIS88sAYuf
JE+uuho7IK5I3Bbzmo8jgxCRxrh+f1rZzezy1Tpni3dTJbx6VA==
-----END CERTIFICATE-----
PEM;

    private string $kid     = 'testkid1';
    private string $project = 'demo-proj';

    private function verifier(): FirebaseIdTokenVerifier
    {
        $kid  = $this->kid;
        $cert = self::CERT;

        return new FirebaseIdTokenVerifier($this->project, static fn (): array => [$kid => $cert]);
    }

    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** @param array<string,mixed> $override */
    private function token(array $override = [], ?string $kid = null): string
    {
        $header  = ['alg' => 'RS256', 'kid' => $kid ?? $this->kid, 'typ' => 'JWT'];
        $payload = array_merge([
            'aud'          => $this->project,
            'iss'          => 'https://securetoken.google.com/' . $this->project,
            'sub'          => 'uid-123',
            'iat'          => time() - 10,
            'auth_time'    => time() - 10,
            'exp'          => time() + 3600,
            'phone_number' => '+919800000000',
        ], $override);

        $h    = $this->b64((string) json_encode($header));
        $p    = $this->b64((string) json_encode($payload));
        $priv = self::PRIV;
        openssl_sign($h . '.' . $p, $sig, $priv, OPENSSL_ALGO_SHA256);

        return $h . '.' . $p . '.' . $this->b64($sig);
    }

    public function testValidTokenReturnsClaims(): void
    {
        $claims = $this->verifier()->verify($this->token());
        $this->assertIsArray($claims);
        $this->assertSame('+919800000000', $claims['phone_number']);
        $this->assertSame('uid-123', $claims['sub']);
    }

    public function testWrongAudienceRejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token(['aud' => 'someone-else'])));
    }

    public function testWrongIssuerRejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token(['iss' => 'https://evil.example'])));
    }

    public function testExpiredTokenRejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token(['exp' => time() - 5])));
    }

    public function testMissingAuthTimeRejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token(['auth_time' => 0])));
    }

    public function testFutureAuthTimeRejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token(['auth_time' => time() + 3600])));
    }

    public function testUnknownKidRejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token([], 'unknown-kid')));
    }

    public function testTamperedSignatureRejected(): void
    {
        // Flip the FIRST signature char (always a significant bit) so the tamper
        // reliably changes the decoded bytes regardless of the run's timestamp.
        $parts    = explode('.', $this->token());
        $parts[2] = ($parts[2][0] === 'A' ? 'B' : 'A') . substr($parts[2], 1);
        $this->assertNull($this->verifier()->verify(implode('.', $parts)));
    }

    public function testEmptyProjectIdRejects(): void
    {
        $cert = self::CERT;
        $v    = new FirebaseIdTokenVerifier('', static fn (): array => ['testkid1' => $cert]);
        $this->assertNull($v->verify($this->token()));
    }
}
