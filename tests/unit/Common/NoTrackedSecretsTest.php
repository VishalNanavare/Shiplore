<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * No credential may be committed to this repository.
 *
 * Audit finding C1: `s3_storage/.env` was tracked and carried the live object-store
 * access key and secret — the sole authentication for the bucket holding vendor and
 * rider KYC scans, GST certificates and invoice PDFs. A second literal copy sat in
 * `s3_storage/public/s3test.php`, which is inside the docroot of s3.shiplore.test.
 *
 * `.gitignore`'s `/.env` is root-anchored, so it only ever covered the project-root
 * env file; the nested application's was never matched.
 *
 * This test walks the git index (not the working tree) so it fails on what would
 * actually be published, and skips cleanly outside a git checkout.
 */
final class NoTrackedSecretsTest extends TestCase
{
    /** @return list<string> repo-relative paths in the git index */
    private function trackedFiles(): array
    {
        $out = @shell_exec('git -C ' . escapeshellarg(rtrim(ROOTPATH, '/\\')) . ' ls-files 2>&1');
        if (! is_string($out) || $out === '' || str_contains($out, 'not a git repository')) {
            $this->markTestSkipped('not a git checkout');
        }

        return array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    /** No .env file may be tracked, at any depth. */
    public function testNoEnvFileIsTracked(): void
    {
        $env = array_values(array_filter(
            $this->trackedFiles(),
            static fn (string $f): bool => basename($f) === '.env',
        ));

        $this->assertSame(
            [],
            $env,
            "env files are tracked and will be published on the next clone/push: " . implode(', ', $env),
        );
    }

    /** .gitignore must exclude env files at every depth, not just the repo root. */
    public function testGitignoreExcludesNestedEnvFiles(): void
    {
        $ignore = (string) @file_get_contents(ROOTPATH . '.gitignore');

        $this->assertMatchesRegularExpression(
            '/^\*\*\/\.env\s*$/m',
            $ignore,
            "'/.env' is root-anchored and does not cover a nested application's env file — "
            . "the s3_storage/ app's env was tracked for exactly this reason",
        );
    }

    /**
     * No tracked PHP/config file may carry a literal AWS-style access key.
     *
     * Scoped to the shapes actually seen in this codebase rather than a generic
     * high-entropy search, so it stays precise and does not fire on hashes or uuids.
     */
    public function testNoTrackedFileContainsALiteralAccessKey(): void
    {
        $offenders = [];

        foreach ($this->trackedFiles() as $rel) {
            if (! preg_match('/\.(php|env|ini|json|ya?ml|sh)$/i', $rel)) {
                continue;
            }
            $path = ROOTPATH . $rel;
            if (! is_file($path) || filesize($path) > 2_000_000) {
                continue;
            }
            $src = (string) file_get_contents($path);

            // A literal AKIA-prefixed key, or accessKey/secretKey assigned a literal.
            if (preg_match('/AKIA[A-Z0-9]{12,}/', $src)
                || preg_match('/\b(?:accessKey|secretKey)\s*=\s*["\'][^"\'$<]{16,}["\']/', $src)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'literal credentials committed in: ' . implode(', ', $offenders),
        );
    }
}
