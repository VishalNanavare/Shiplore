<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * No real personal or deployment data in tracked first-party code — comments included.
 *
 * The repository is public. Two of these were live, not hypothetical:
 *
 *  - a real Indian mobile number (Mumbai prefix) appeared in seven places, one of them
 *    PREFILLED into an input on app/Views/auth/firebase_otp_test.php, and paired in a
 *    test fixture with the owner's first name;
 *  - personal and support email addresses were rendered by app/Views/uikit/pages/*,
 *    which Routes.php serves at `ui-kit` with NO auth filter and NO subdomain pin — a
 *    world-readable page on every host.
 *
 * Scope is first-party code only. composer.lock and vendored libraries legitimately
 * carry their authors' contact details, and framework/plugin files are not ours to
 * rewrite.
 */
final class NoPersonalDataTest extends CIUnitTestCase
{
    /** Directories that are ours to keep clean. */
    private const ROOTS = [APPPATH, SUPPORTPATH . '../'];

    /** Reserved by RFC 2606 / RFC 6761, or obvious sequential placeholders. */
    private const ALLOWED_EMAIL_DOMAINS = [
        'example.com', 'example.org', 'example.net', 'example.co.uk',
        'platform.local', 'precision.example',
        'x.com', 'y.in', 'x.in', 'v.in', 'shop.in', 'vendor.in', 'x.test', 'nowhere.test',
        // third-party service endpoints the app genuinely calls
        'system.gserviceaccount.com',
        // Framework attribution in CodeIgniter's own copyright headers, not our data.
        'codeigniter.com',
    ];

    /**
     * @return list<string> every first-party PHP file
     */
    private function sourceFiles(): array
    {
        $out = [];

        foreach (self::ROOTS as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                $path = str_replace('\\', '/', (string) $file);
                if (! str_ends_with($path, '.php')) {
                    continue;
                }
                // Not ours: framework, dependencies, bundled plugins, the second app.
                if (preg_match('#/(vendor|system|pma|s3_storage|plugins|ThirdParty)/#', $path)) {
                    continue;
                }
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * No real mobile number anywhere.
     *
     * Placeholders must be visibly fake — a repeated-zero block or the sequential
     * 9812345678. Anything else is treated as somebody's actual number.
     */
    public function testNoRealMobileNumbers(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            foreach (file($file) as $i => $line) {
                // The country code must be optional AND stripped. A plain \b[6-9]\d{9}\b
                // misses a +91-prefixed number entirely: twelve digits form one unbroken
                // run, so there is no word boundary after the tenth. That is the exact
                // form the published number took on the OTP page, and a mutation run
                // caught the gap. (Deliberately not quoting the number here — this test
                // scans itself, and an example in the comment would trip it.)
                preg_match_all('/(?<!\d)(?:\+?91[-\s]?)?([6-9]\d{9})(?!\d)/', $line, $m);

                foreach ($m[1] as $number) {
                    $fake = preg_match('/0{5}/', $number)          // 9800000000, 9000000001
                        || $number === '9812345678'                 // the documented sample
                        || $number === '9999999999'
                        || $number === '9876500000'
                        || $number === '9990001112';
                    if (! $fake) {
                        $offenders[] = sprintf('%s:%d  %s', $file, $i + 1, $number);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "a real-looking mobile number is published:\n" . implode("\n", $offenders));
    }

    /** No email address outside the reserved/placeholder set. */
    public function testNoRealEmailAddresses(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            foreach (file($file) as $i => $line) {
                preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $line, $m);

                foreach ($m[1] as $k => $domain) {
                    if (! in_array(strtolower($domain), self::ALLOWED_EMAIL_DOMAINS, true)) {
                        $offenders[] = sprintf('%s:%d  %s', $file, $i + 1, $m[0][$k]);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "a real email address is published:\n" . implode("\n", $offenders));
    }

    /**
     * No server filesystem path.
     *
     * `/home/<account>/...` gives away the hosting account name, which pairs with a
     * password to become cPanel/SSH/FTP access.
     */
    public function testNoServerFilesystemPaths(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            foreach (file($file) as $i => $line) {
                if (preg_match('#/home/[a-z0-9_]+/#i', $line, $m)) {
                    $offenders[] = sprintf('%s:%d  %s', $file, $i + 1, trim($m[0]));
                }
            }
        }

        $this->assertSame([], $offenders, "a server path is published:\n" . implode("\n", $offenders));
    }
}
