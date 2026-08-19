<?php

declare(strict_types=1);

use App\Libraries\Notify\Mailer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Test doubles for the mailer must answer everything the real Mailer does.
 *
 * This has now broken the suite twice, the same way both times. A feature test injects an
 * anonymous class as the 'mailer' service, declaring only the handful of methods the
 * controller happened to call that day. Later a controller starts calling one more —
 * hasCryptoMismatch(), then diagnose() — and the double fails with "Call to undefined
 * method" from inside the controller, pointing at production code that is perfectly
 * correct. The double had silently drifted behind the class it stands in for.
 *
 * The failure is also badly placed: it surfaces in whichever test happens to exercise that
 * controller, not in the file that owns the stale double, and the message names the
 * controller line rather than the mock. So it reads as a regression in code that did not
 * change.
 *
 * This asserts the property directly. It is a source scan because the doubles are
 * anonymous classes created at runtime inside test methods — there is no type to reflect
 * on until the test that owns it runs, by which point the assertion is too late to be
 * useful anywhere else.
 *
 * The durable alternative is a MailerInterface that both the real class and every double
 * implement, letting PHP refuse to declare an incomplete one. That is the better fix and
 * this test does not pretend otherwise; it is the cheap version that stops the recurrence
 * today without changing the service contract.
 */
final class MailerMockDriftTest extends CIUnitTestCase
{
    /** Public methods a double has to provide, excluding the constructor. */
    private function mailerApi(): array
    {
        $methods = [];

        foreach ((new ReflectionClass(Mailer::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if (! $m->isConstructor() && ! $m->isStatic()) {
                $methods[] = $m->getName();
            }
        }
        sort($methods);

        return $methods;
    }

    /** @return list<string> every test file that injects a mailer double */
    private function filesInjectingAMailer(): array
    {
        $found = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOTPATH . 'tests'));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // Skip this file: it contains the search string in its own scanner, so it
            // matches itself and then fails for not declaring an API it never mocks.
            if (realpath($file->getPathname()) === realpath(__FILE__)) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (str_contains($src, "injectMock('mailer'")) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    public function testEveryInjectedMailerDoubleImplementsTheWholeApi(): void
    {
        $api   = $this->mailerApi();
        $files = $this->filesInjectingAMailer();

        // If this trips, the scan has stopped finding the doubles it is supposed to guard
        // — a green result would then mean nothing at all.
        $this->assertNotEmpty($files, 'no test injects a mailer double — has the service name changed?');
        $this->assertContains('diagnose', $api, 'sanity: the API list is really being read from Mailer');

        foreach ($files as $path) {
            $src = (string) file_get_contents($path);

            foreach ($api as $method) {
                $this->assertMatchesRegularExpression(
                    '/function\s+' . preg_quote($method, '/') . '\s*\(/',
                    $src,
                    basename($path) . ' injects a mailer double that does not declare ' . $method . '(). '
                    . 'A controller calling it fails with "Call to undefined method" inside production code '
                    . 'that is correct — add the method to the double.',
                );
            }
        }
    }
}
