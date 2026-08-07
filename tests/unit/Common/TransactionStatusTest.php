<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit B, P2a — six transStart()/transComplete() blocks never consulted
 * transStatus(), so a failed transaction was reported as a success.
 *
 * CodeIgniter's transComplete() rolls back automatically when any query in the
 * block failed, so the DATA stayed consistent. What did not was the report: the
 * admin saw "Category mappings saved." over an unchanged table. Two of the six
 * were worse than cosmetic —
 *
 *   - Admin\OrderController::forceClaim() wrote a 'force_claimed' entry to the
 *     claim audit log after a rolled-back UPDATE, so the audit trail asserted an
 *     admin held an order they did not.
 *   - Admin\VendorTypeMismatchController counts $moved in PHP as its loop runs, so
 *     it reported "14 product(s) moved" for work that was rolled back.
 *
 * The sweep below is the real guard: it finds every transStart() in app/ rather
 * than a fixed list, so a new one added later has to check its status too.
 */
final class TransactionStatusTest extends CIUnitTestCase
{
    /** Source with comments stripped, so prose can never satisfy an assertion. */
    private function code(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    /** @return list<string> every .php file under app/ */
    private function appFiles(): array
    {
        $files = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Every transStart() must be followed by a transStatus() check.
     *
     * Scanned per enclosing function rather than by a fixed line window, so moving
     * code around cannot accidentally satisfy it.
     */
    public function testEveryTransStartChecksItsStatus(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $code = $this->code($path);
            if (! str_contains($code, 'transStart()')) {
                continue;
            }

            foreach ($this->functionBodiesContaining($code, 'transStart()') as $name => $body) {
                if (! str_contains($body, 'transStatus()')) {
                    $offenders[] = str_replace(APPPATH, '', $path) . '::' . $name . '()';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these transactions report success without asking whether they committed:\n  " . implode("\n  ", $offenders),
        );
    }

    /**
     * Split source into function bodies, keeping only those containing $needle.
     *
     * @return array<string, string>
     */
    private function functionBodiesContaining(string $code, string $needle): array
    {
        $found = [];
        $count = preg_match_all('/function\s+(\w+)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE);

        for ($i = 0; $i < $count; $i++) {
            $name  = $m[1][$i][0];
            $brace = strpos($code, '{', (int) $m[0][$i][1]);
            if ($brace === false) {
                continue;
            }

            $depth = 0;
            $body  = '';

            for ($j = $brace, $len = strlen($code); $j < $len; $j++) {
                if ($code[$j] === '{') {
                    $depth++;
                } elseif ($code[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $body = substr($code, $brace, $j - $brace + 1);
                        break;
                    }
                }
            }

            if ($body !== '' && str_contains($body, $needle)) {
                $found[$name] = $body;
            }
        }

        return $found;
    }

    /** The claim audit log must not record a claim that rolled back. */
    public function testForceClaimDoesNotLogARolledBackClaim(): void
    {
        $code = $this->code(APPPATH . 'Controllers/Admin/OrderController.php');

        $status = strpos($code, 'transStatus()');
        $log    = strpos($code, "'force_claimed'");

        $this->assertNotFalse($status, 'forceClaim() must check transStatus()');
        $this->assertNotFalse($log, "the 'force_claimed' audit entry is missing");
        $this->assertLessThan(
            $log,
            $status,
            'the status check must run BEFORE the audit log write, or a rolled-back claim is still recorded as having happened',
        );
    }

    /** A repository that can fail must be able to say so. */
    public function testSyncCategoriesReportsFailureToItsCaller(): void
    {
        $repo = $this->code(APPPATH . 'Models/BusinessTypeRepository.php');
        $ctrl = $this->code(APPPATH . 'Controllers/Admin/BusinessTypeController.php');

        $this->assertMatchesRegularExpression(
            '/public function syncCategories\(int \$businessTypeId, array \$categoryIds\): bool/',
            $repo,
            'syncCategories() returned void, so the caller could not know it had failed',
        );
        $this->assertStringContainsString('return $db->transStatus();', $repo);

        $this->assertMatchesRegularExpression(
            '/if \(! service\(\'businessTypeRepository\'\)->syncCategories\(\$id, \$ids\)\) \{/',
            $ctrl,
            'the controller must act on the return value, not discard it',
        );
    }

    /**
     * Raw exception text must not reach a flash message.
     *
     * PayoutController and RiderFinanceController flash `reason` straight to the
     * admin, and a CodeIgniter DatabaseException carries the failing SQL in its
     * message — schema and column names, handed to whoever triggered the error.
     */
    public function testBatchFailuresDoNotLeakExceptionText(): void
    {
        foreach (['Models/PayoutRepository.php', 'Models/RiderSettlementRepository.php'] as $rel) {
            $code = $this->code(APPPATH . $rel);

            $this->assertDoesNotMatchRegularExpression(
                "/'reason' => \\\$e->getMessage\(\)/",
                $code,
                $rel . ' still flashes raw exception text to the admin',
            );
            $this->assertMatchesRegularExpression(
                "/log_message\('error', '[^']*failed: ' \. \\\$e->getMessage\(\)\)/",
                $code,
                $rel . ' must still LOG the real error — hiding it from the user must not mean losing it',
            );
        }
    }
}
