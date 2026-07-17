<?php

declare(strict_types=1);

/**
 * Asset helper — resolves a path under public/assets to a base-URL'd link with
 * an mtime cache-buster (?v=) so browsers refresh when a file changes.
 *
 * Usage in views:  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
 *                  <script src="<?= asset('vendor/jquery/jquery.min.js') ?>"></script>
 */
if (! function_exists('asset')) {
    function asset(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $diskPath     = FCPATH . 'assets/' . $relativePath;
        $url          = base_url('assets/' . $relativePath);

        if (is_file($diskPath)) {
            return $url . '?v=' . filemtime($diskPath);
        }

        return $url;
    }
}
