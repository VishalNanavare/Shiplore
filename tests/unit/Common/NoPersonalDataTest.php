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

    // ------------------------------------------------------- whole-repository checks

    /**
     * Every TRACKED path, across the whole repository — not just first-party PHP.
     *
     * The checks above read file CONTENTS under app/ and tests/. Two whole classes of
     * exposure are invisible to that, and both were live:
     *
     *  - a path can leak the deployment hostname in its own NAME. A cPanel subdomain
     *    docroot was tracked as `test_shiplore_in/`, i.e. the hostname with dots
     *    replaced by underscores. No content grep finds that, ever.
     *  - directories excluded from the first-party scan (the nested s3_storage/ app,
     *    a committed phpMyAdmin) are still published, and one of them carried the
     *    hosting account name in a config file.
     *
     * Uses git ls-files, so untracked working-tree files are correctly ignored — only
     * what is actually published counts.
     *
     * @return list<string>
     */
    private function trackedPaths(): array
    {
        exec('git -C ' . escapeshellarg(ROOTPATH) . ' ls-files 2>&1', $out, $code);

        return $code === 0 ? array_values(array_filter($out)) : [];
    }

    /**
     * A hostname flattened into a path: `<label>_<label>_<tld>` for a real TLD.
     *
     * Deliberately matches the SHAPE rather than the brand — searching for the brand
     * would mean writing it back into the tree.
     */
    private static function looksLikeHostnamePath(string $path): bool
    {
        return (bool) preg_match('#(^|/)[a-z0-9]+_[a-z0-9]+_(in|com|net|org|co|io)(/|$)#i', $path);
    }

    /**
     * The predicate is asserted against fixed samples, not only against the live tree.
     *
     * Once the offending directory was untracked there was nothing left for the tree
     * scan to catch, so disabling the filter changed nothing and the check passed
     * either way — a mutation run proved it vacuous. These samples keep it honest
     * whether or not the repository currently happens to be clean.
     *
     * @return list<array{0:string,1:bool}>
     */
    public static function hostnamePathProvider(): array
    {
        return [
            'the real leftover: a cPanel subdomain docroot' => ['test_shiplore_in/.htaccess', true],
            'apex form'                                     => ['example_co_in/index.php', true],
            'nested'                                        => ['deploy/staging_example_com/x.txt', true],
            'dotcom'                                        => ['my_site_com/a', true],
            'ordinary snake_case file'                      => ['app/Views/order_summary.php', false],
            'ordinary source path'                          => ['app/Models/UserRepository.php', false],
            'underscored test name'                         => ['tests/unit/Common/NoPersonalDataTest.php', false],
            'two-part snake_case dir'                       => ['app/some_helper/file.php', false],
        ];
    }

    /**
     * @dataProvider hostnamePathProvider
     */
    public function testTheHostnamePathPredicateIsCorrect(string $path, bool $expected): void
    {
        $this->assertSame($expected, self::looksLikeHostnamePath($path));
    }

    public function testNoTrackedPathNamesTheDeploymentHost(): void
    {
        $paths = $this->trackedPaths();
        $this->assertNotEmpty($paths, 'git ls-files returned nothing — this check proved nothing');

        $offenders = array_values(array_filter($paths, static fn (string $p): bool => self::looksLikeHostnamePath($p)));

        $this->assertSame([], $offenders, "a tracked path names the deployment host:\n" . implode("\n", $offenders));
    }

    /**
     * No real uploaded content may be tracked at all.
     *
     * The gap this closes was invisible to every text scan, mine included. A tracked
     * .xlsx under the nested object-store app published seven live email addresses and
     * roughly 250 real people's names — inside xl/sharedStrings.xml, zip-compressed
     * within a binary file, so a grep for '@' finds nothing. A scanned handwritten
     * signature sat beside it, and no regex will ever read a PNG.
     *
     * The rule is therefore structural rather than pattern-based: user-uploaded data
     * has no business being in the repository, whatever it happens to contain. Cheap to
     * enforce, and it cannot be defeated by compression or an image format.
     */
    public function testNoUploadedUserContentIsTracked(): void
    {
        $offenders = array_values(array_filter(
            $this->trackedPaths(),
            static function (string $p): bool {
                if (! preg_match('#(^|/)writable/(data|uploads|\.meta)/#', $p)) {
                    return false;
                }

                // CodeIgniter ships index.html / .htaccess placeholders in these
                // directories to stop directory listing. They are scaffolding, and
                // removing them would REDUCE security.
                return ! preg_match('#/(index\.html|\.htaccess|\.gitkeep)$#', $p);
            },
        ));

        $this->assertSame(
            [],
            $offenders,
            "uploaded user content is published — it can contain names, emails and signatures no text scan would find:\n"
                . implode("\n", $offenders),
        );
    }

    /**
     * Binary documents that ARE tracked must not carry contact details.
     *
     * Belt and braces for the check above: if a spreadsheet or PDF is ever legitimately
     * committed, its text payload is still read. XLSX and DOCX are zip containers, so
     * they are opened and their XML parts scanned rather than sniffed as bytes.
     */
    public function testTrackedDocumentsCarryNoEmailAddresses(): void
    {
        $offenders = [];

        foreach ($this->trackedPaths() as $rel) {
            if (! preg_match('/\.(xlsx|docx|pptx|pdf)$/i', $rel)) {
                continue;
            }
            $abs = ROOTPATH . $rel;
            if (! is_file($abs)) {
                continue;
            }

            $text = '';

            if (preg_match('/\.(xlsx|docx|pptx)$/i', $rel) && class_exists(ZipArchive::class)) {
                $zip = new ZipArchive();
                if ($zip->open($abs) === true) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = (string) $zip->getNameIndex($i);
                        if (str_ends_with($name, '.xml')) {
                            $text .= (string) $zip->getFromIndex($i);
                        }
                    }
                    $zip->close();
                }
            } else {
                $text = (string) file_get_contents($abs);
            }

            if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $m)) {
                $offenders[] = $rel . '  ' . $m[0];
            }
        }

        $this->assertSame([], $offenders, "a tracked document contains email addresses:\n" . implode("\n", $offenders));
    }

    /** No hosting-account path in ANY tracked file, including the nested app's config. */
    public function testNoServerPathAnywhereInTheRepository(): void
    {
        $offenders = [];

        foreach ($this->trackedPaths() as $rel) {
            $abs = ROOTPATH . $rel;
            if (! is_file($abs) || filesize($abs) > 2_000_000) {
                continue;
            }
            // Third-party sources legitimately quote example paths in their own docs.
            if (preg_match('#(^|/)(vendor|system|node_modules)/#', $rel)) {
                continue;
            }
            $body = (string) file_get_contents($abs);
            if (preg_match('#/home/[a-z0-9_]+/#i', $body, $m)) {
                $offenders[] = $rel . '  ' . trim($m[0]);
            }
        }

        $this->assertSame([], $offenders, "a hosting-account path is published:\n" . implode("\n", $offenders));
    }
}
