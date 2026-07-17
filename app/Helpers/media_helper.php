<?php

declare(strict_types=1);

/**
 * media_helper — small view helpers for the media library (icon, size, name).
 */
if (! function_exists('media_icon')) {
    /** @return array{0:string,1:string} [bootstrap-icon, contextual-colour] */
    function media_icon(string $mime): array
    {
        $mime = strtolower($mime);

        return match (true) {
            str_contains($mime, 'pdf')                                                      => ['file-earmark-pdf', 'danger'],
            str_starts_with($mime, 'image/')                                                => ['file-earmark-image', 'info'],
            str_starts_with($mime, 'video/')                                                => ['file-earmark-play', 'primary'],
            str_starts_with($mime, 'audio/')                                                => ['file-earmark-music', 'secondary'],
            str_contains($mime, 'word')                                                     => ['file-earmark-word', 'primary'],
            str_contains($mime, 'sheet') || str_contains($mime, 'excel') || str_contains($mime, 'csv') => ['file-earmark-excel', 'success'],
            str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')        => ['file-earmark-slides', 'warning'],
            str_contains($mime, 'zip') || str_contains($mime, 'compressed')                 => ['file-earmark-zip', 'secondary'],
            str_starts_with($mime, 'text/')                                                 => ['file-earmark-text', 'secondary'],
            default                                                                         => ['file-earmark', 'secondary'],
        };
    }
}

if (! function_exists('media_size')) {
    function media_size(?int $bytes): string
    {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        $n     = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $n : number_format($n, 1)) . ' ' . $units[$i];
    }
}

if (! function_exists('media_name')) {
    /** @param array<string,mixed> $f */
    function media_name(array $f): string
    {
        $name = trim((string) ($f['original_name'] ?? ''));

        return $name !== '' ? $name : basename((string) ($f['object_key'] ?? 'file'));
    }
}
