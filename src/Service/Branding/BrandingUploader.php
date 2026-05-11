<?php

namespace App\Service\Branding;

use App\Entity\User;
use App\Entity\Workspace;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Validates + stores Pro user brand logos under
 * `public/uploads/branding/<uuid>.<ext>`.
 *
 * Sanity rails:
 *   - Max 1 MB.
 *   - Allowlist of image types: PNG, JPEG, SVG, WebP.
 *   - File extension reset by us — uploaded filename is never trusted.
 *   - SVG is allowed but its contents are NOT executed; payment pages
 *     render via <img src> so embedded JS is inert there.
 */
class BrandingUploader
{
    private const MAX_BYTES = 1_048_576; // 1 MB
    private const ALLOWED_MIMES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $projectDir) {}

    /**
     * @throws \InvalidArgumentException on validation failure
     * @return string Relative path stored on user.brand_logo_path
     */
    public function store(User $user, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('branding.logo_too_large');
        }
        $mime = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_MIMES[$mime])) {
            throw new \InvalidArgumentException('branding.logo_unsupported_type');
        }

        $ext = self::ALLOWED_MIMES[$mime];
        $dir = $this->projectDir . '/public/uploads/branding';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Filename = user UUID — overwrites any previous logo, no orphan files.
        $name = $user->getUuid() . '.' . $ext;
        $file->move($dir, $name);

        return 'uploads/branding/' . $name;
    }

    public function delete(User $user): void
    {
        $path = $user->getBrandLogoPath();
        if (!$path) return;
        $abs = $this->projectDir . '/public/' . ltrim($path, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    /**
     * Phase 2 helpers: workspace-scoped storage. Path now uses the
     * workspace UUID rather than the user UUID so a logo follows the
     * business, not a single teammate.
     *
     * @return string Relative path stored on workspace.brand_logo_path
     */
    public function storeForWorkspace(Workspace $workspace, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('branding.logo_too_large');
        }
        $mime = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_MIMES[$mime])) {
            throw new \InvalidArgumentException('branding.logo_unsupported_type');
        }
        $ext = self::ALLOWED_MIMES[$mime];
        $dir = $this->projectDir . '/public/uploads/branding';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = 'ws-' . $workspace->getUuid() . '.' . $ext;
        $file->move($dir, $name);
        return 'uploads/branding/' . $name;
    }

    public function deleteWorkspaceLogo(Workspace $workspace): void
    {
        $path = $workspace->getBrandLogoPath();
        if (!$path) return;
        $abs = $this->projectDir . '/public/' . ltrim($path, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}
