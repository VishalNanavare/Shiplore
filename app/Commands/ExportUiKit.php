<?php

declare(strict_types=1);

namespace App\Commands;

use App\Controllers\UiKitController;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * ExportUiKit — renders the CodeIgniter UI Kit into a fully standalone set of
 * static HTML files under /self_ui_kit, with the header (topbar + sidebar nav)
 * and footer baked into every page, then copies all assets alongside it.
 *
 * The result is portable: drop the self_ui_kit/ folder into any project (or open
 * index.html directly) — no PHP, no server required. The PHP views remain the
 * single source of truth; re-run `php spark uikit:export` after changing them.
 */
final class ExportUiKit extends BaseCommand
{
    protected $group       = 'UI Kit';
    protected $name        = 'uikit:export';
    protected $description = 'Render the UI Kit to standalone static HTML in self_ui_kit/ (header/footer baked into every page) and copy all assets.';
    protected $usage       = 'uikit:export';

    /** Absolute path to the output folder (project-root/self_ui_kit). */
    private string $outDir = '';

    public function run(array $params): void
    {
        $this->outDir = rtrim(ROOTPATH, '\\/') . DIRECTORY_SEPARATOR . 'self_ui_kit';

        CLI::write('Exporting UI Kit → ' . $this->outDir, 'yellow');
        CLI::newLine();

        // 1. Start from a clean output folder so removed pages don't linger.
        $this->cleanOutput();
        $this->ensureDir($this->outDir);

        // 2. Build the page list: Overview (home) + every menu slug.
        $menu  = UiKitController::menu();
        $pages = [['slug' => 'home', 'active' => 'home', 'title' => 'Overview']];
        foreach ($menu as $group) {
            foreach ($group['items'] as $item) {
                $pages[] = ['slug' => $item['slug'], 'active' => $item['slug'], 'title' => $item['title']];
            }
        }

        // 3. Render → rewrite URLs → write each page.
        $base    = rtrim(base_url(), '/');
        $written = 0;
        foreach ($pages as $page) {
            $viewName = 'uikit/pages/' . $page['slug'];

            // A fresh renderer per page keeps section state (content/head/scripts)
            // from bleeding between pages rendered in the same process.
            $renderer = service('renderer', null, null, false);
            $html     = $renderer->setData([
                'menu'   => $menu,
                'active' => $page['active'],
                'title'  => $page['title'],
            ], 'raw')->render($viewName);

            $html = $this->rewriteUrls($html, $base);

            $file = ($page['slug'] === 'home' ? 'index' : $page['slug']) . '.html';
            file_put_contents($this->outDir . DIRECTORY_SEPARATOR . $file, $html);
            $written++;
            CLI::write('  ' . CLI::color('✓', 'green') . ' ' . $file);
        }

        // 4. Copy all assets (public/assets → self_ui_kit/assets).
        CLI::newLine();
        CLI::write('Copying assets…', 'yellow');
        $assetCount = $this->copyDir(
            rtrim(FCPATH, '\\/') . DIRECTORY_SEPARATOR . 'assets',
            $this->outDir . DIRECTORY_SEPARATOR . 'assets'
        );

        // 5. README for whoever opens the folder later.
        $this->writeReadme($written);

        // 6. Sanity check: no absolute base URL should survive in the output.
        $leftover = $this->countLeftoverAbsoluteUrls($base);

        CLI::newLine();
        CLI::write(sprintf('Done — %d pages, %d asset files copied.', $written, $assetCount), 'green');
        if ($leftover > 0) {
            CLI::error(sprintf('Warning: %d absolute base URL(s) still present in output — please review.', $leftover));
        } else {
            CLI::write('All links are relative. ✓', 'green');
        }
    }

    /**
     * Rewrite framework-generated absolute URLs to portable relative paths.
     * Handles an optional "/index.php" segment so it works regardless of whether
     * the app serves URLs with or without the front controller in the path.
     */
    private function rewriteUrls(string $html, string $base): string
    {
        $root = preg_quote($base, '#') . '(?:/index\.php)?';

        // Asset links (drop the ?v= cache-buster):  …/assets/x?v=1  →  assets/x
        $html = preg_replace('#' . $root . '/assets/([^"\'?\s)]+)(?:\?v=\d+)?#', 'assets/$1', $html);

        // UI Kit page links:  …/ui-kit/buttons  →  buttons.html
        $html = preg_replace('#' . $root . '/ui-kit/([a-z0-9-]+)#', '$1.html', $html);

        // Overview link (…/ui-kit not followed by another segment)  →  index.html
        $html = preg_replace('#' . $root . '/ui-kit(?![\w/-])#', 'index.html', $html);

        // Back-to-app link site_url('/')  (base + "/" right before a quote)  →  index.html
        $html = preg_replace('#' . $root . '/(?=["\'])#', 'index.html', $html);

        return $html;
    }

    /** Count any remaining absolute references to the app base URL (should be 0). */
    private function countLeftoverAbsoluteUrls(string $base): int
    {
        $total = 0;
        foreach (glob($this->outDir . DIRECTORY_SEPARATOR . '*.html') ?: [] as $file) {
            $total += substr_count((string) file_get_contents($file), $base);
        }

        return $total;
    }

    /** Recursively copy a directory; returns the number of files copied. */
    private function copyDir(string $src, string $dst): int
    {
        if (! is_dir($src)) {
            CLI::error('Asset source not found: ' . $src);

            return 0;
        }

        $this->ensureDir($dst);
        $count = 0;

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $items */
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $dst . DIRECTORY_SEPARATOR . $items->getSubPathname();
            if ($item->isDir()) {
                $this->ensureDir($target);
            } else {
                copy($item->getPathname(), $target);
                $count++;
            }
        }

        return $count;
    }

    /** Remove generated *.html files and the assets/ folder from a previous run. */
    private function cleanOutput(): void
    {
        if (! is_dir($this->outDir)) {
            return;
        }

        foreach (glob($this->outDir . DIRECTORY_SEPARATOR . '*.html') ?: [] as $file) {
            @unlink($file);
        }

        $assets = $this->outDir . DIRECTORY_SEPARATOR . 'assets';
        if (is_dir($assets)) {
            $this->removeDir($assets);
        }
    }

    /** Recursively delete a directory and its contents. */
    private function removeDir(string $dir): void
    {
        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $items */
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function writeReadme(int $pageCount): void
    {
        $readme = <<<MD
        # Self UI Kit — standalone HTML

        A fully self-contained, static copy of the Shiplore UI Kit: **{$pageCount} pages**,
        each a complete HTML document with the sidebar navigation, top bar, and footer baked in.
        No PHP, framework, or build step is needed to view it.

        ## Use it

        - **Open directly:** double-click `index.html` (works from `file://`).
        - **Or serve the folder** with any static server, e.g. `php -S localhost:8080`.
        - **Reuse in another project:** copy this whole `self_ui_kit/` folder anywhere — every
          page references its assets relatively (`assets/…`), so navigation and styling keep working.

        All third-party libraries (Bootstrap, jQuery, Chart.js, ApexCharts, DataTables, Swiper,
        Select2, SweetAlert2, Toastr, Leaflet, …) are bundled under `assets/` and load offline.
        The only online dependency is the **Leaflet maps** page, which fetches map tiles from the
        network — every other page is fully offline-capable.

        ## Regenerate

        This folder is generated from the CodeIgniter views in `app/Views/uikit/`. After editing
        those views (or the shared header/footer in `app/Views/uikit/_layout.php`), rebuild with:

        ```
        php spark uikit:export
        ```

        Do not hand-edit files in this folder — they are overwritten on every export.
        MD;

        file_put_contents($this->outDir . DIRECTORY_SEPARATOR . 'README.md', $readme . "\n");
    }
}
