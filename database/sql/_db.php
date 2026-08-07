<?php

declare(strict_types=1);

/**
 * Shared PDO connection for the one-off maintenance/seed scripts in this directory.
 *
 * Every one of those scripts previously constructed its own PDO with the database
 * superuser's username and password written inline — fourteen copies of a working
 * credential, in files that live under the DocumentRoot. They are only
 * unreachable because `database/.htaccess` denies the directory, which is an
 * Apache-with-AllowOverride guarantee: it stops applying the moment the site is
 * served by anything else, or that file is lost.
 *
 * Credentials now come from the environment. Resolution order:
 *   1. Real environment variables (works for CI and `VAR=x php script.php`).
 *   2. The project's own .env, parsed directly — these scripts are standalone and
 *      never boot CodeIgniter, so the framework's env() helper is unavailable.
 *
 * Fails loudly with an actionable message rather than falling back to a default,
 * because a silent default is how the literals ended up here in the first place.
 *
 * Usage (replaces the old inline `new PDO(...)`):
 *     require __DIR__ . '/_db.php';
 *     $pdo = shiplore_pdo();
 */

if (! function_exists('shiplore_pdo')) {
    /**
     * Read a value from the real environment, else from the project .env.
     *
     * @param list<string> $keys candidate names, first match wins
     */
    function shiplore_env(array $keys, ?string $default = null): ?string
    {
        static $dotenv = null;

        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                return $v;
            }
        }

        if ($dotenv === null) {
            $dotenv = [];
            $path   = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    // .env values may be quoted and may carry a trailing comment.
                    $dotenv[trim($k)] = trim(trim($v), " \t\"'");
                }
            }
        }

        foreach ($keys as $k) {
            if (isset($dotenv[$k]) && $dotenv[$k] !== '') {
                return $dotenv[$k];
            }
        }

        return $default;
    }

    function shiplore_pdo(): PDO
    {
        $host = shiplore_env(['database.default.hostname', 'DB_HOSTNAME', 'DB_HOST'], '127.0.0.1');
        $name = shiplore_env(['database.default.database', 'DB_DATABASE', 'DB_NAME']);
        $user = shiplore_env(['database.default.username', 'DB_USERNAME', 'DB_USER']);
        // An empty password is legitimate (it is the committed default for this
        // project), so only the username and database name are treated as required.
        $pass = shiplore_env(['database.default.password', 'DB_PASSWORD', 'DB_PASS'], '');

        if ($name === null || $user === null) {
            fwrite(STDERR, <<<'MSG'
            Database credentials are not configured.

            These scripts read them from the environment or from the project .env.
            Set either the CodeIgniter-style keys:

                database.default.hostname = 127.0.0.1
                database.default.database = your_db
                database.default.username = your_user
                database.default.password = your_pass

            or the plain forms DB_HOSTNAME / DB_DATABASE / DB_USERNAME / DB_PASSWORD.

            One-off alternative:
                DB_DATABASE=your_db DB_USERNAME=your_user DB_PASSWORD=your_pass php <script>

            MSG);
            exit(1);
        }

        return new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
            $user,
            (string) $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
