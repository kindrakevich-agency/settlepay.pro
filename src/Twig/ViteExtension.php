<?php

namespace App\Twig;

use Symfony\Component\HttpKernel\Config\FileLocator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Resolves logical asset paths (e.g. "assets/styles/app.css") to the
 * hashed filename Vite emits in public/build/.vite/manifest.json.
 *
 * Usage in Twig:
 *   {{ vite_asset('assets/styles/app.css') }}      → /build/assets/app-BG-e5e94.css
 *   {{ vite_entry_link('assets/styles/app.css') }} → <link rel="stylesheet" href="...">
 *   {{ vite_entry_script('assets/dashboard/main.tsx') }} → <script type="module" src="...">
 *
 * In dev (when public/build/ doesn't exist yet) we fall back to the
 * Vite dev server at http://localhost:5173 with HMR.
 */
final class ViteExtension extends AbstractExtension
{
    private ?array $manifest = null;
    private string $manifestPath;
    private string $publicPath;
    private bool $devServer;

    public function __construct(string $projectDir, string $appEnv)
    {
        $this->manifestPath = $projectDir . '/public/build/.vite/manifest.json';
        $this->publicPath   = '/build';
        $this->devServer    = $appEnv === 'dev' && !is_file($this->manifestPath);
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vite_asset',         [$this, 'asset'],       ['is_safe' => ['html']]),
            new TwigFunction('vite_entry_link',    [$this, 'entryLink'],   ['is_safe' => ['html']]),
            new TwigFunction('vite_entry_script',  [$this, 'entryScript'], ['is_safe' => ['html']]),
        ];
    }

    public function asset(string $entry): string
    {
        if ($this->devServer) {
            return 'http://localhost:5173/' . ltrim($entry, '/');
        }

        $m = $this->getManifest();
        if (!isset($m[$entry])) {
            return $this->publicPath . '/' . ltrim($entry, '/');
        }
        return $this->publicPath . '/' . $m[$entry]['file'];
    }

    public function entryLink(string $entry): string
    {
        $m = $this->getManifest();
        if (!isset($m[$entry])) {
            return '';
        }
        $tags = [];
        // The CSS for a JS/TS entry is in `css: [...]`; for a CSS entry it's in `file`.
        if (str_ends_with($entry, '.css')) {
            $tags[] = sprintf('<link rel="stylesheet" href="%s/%s">', $this->publicPath, $m[$entry]['file']);
        }
        foreach ($m[$entry]['css'] ?? [] as $css) {
            $tags[] = sprintf('<link rel="stylesheet" href="%s/%s">', $this->publicPath, $css);
        }
        return implode("\n", $tags);
    }

    public function entryScript(string $entry): string
    {
        if ($this->devServer) {
            return sprintf(
                '<script type="module" src="http://localhost:5173/@vite/client"></script>' . "\n"
                . '<script type="module" src="http://localhost:5173/%s"></script>',
                ltrim($entry, '/')
            );
        }

        $m = $this->getManifest();
        if (!isset($m[$entry])) {
            return '';
        }
        return sprintf('<script type="module" src="%s/%s"></script>', $this->publicPath, $m[$entry]['file']);
    }

    private function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        if (!is_file($this->manifestPath)) {
            return $this->manifest = [];
        }
        $raw = file_get_contents($this->manifestPath);
        return $this->manifest = json_decode($raw, true) ?: [];
    }
}
