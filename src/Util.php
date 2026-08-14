<?php

declare(strict_types=1);

namespace Starmap;

/**
 * Служебные функции граббера.
 */
final class Util
{
    public const DATA_DIR = __DIR__ . '/../data';
    public const WEB_DIR = __DIR__ . '/../web';
    public const SRC_DIR = __DIR__;
    public const TMP_DIR = __DIR__ . '/../tmp';

    public const API_BASE = 'https://robertsspaceindustries.com/api/starmap';
    public const SITE_BASE = 'https://robertsspaceindustries.com';
    public const CDN_STARMAP = 'https://cdn.robertsspaceindustries.com/static/starmap';
    public const CDN_STATIC = 'https://cdn.robertsspaceindustries.com/static';

    public static function dataPath(string ...$parts): string
    {
        return implode('/', array_merge([self::DATA_DIR], $parts));
    }

    public static function webPath(string ...$parts): string
    {
        return implode('/', array_merge([self::WEB_DIR], $parts));
    }

    /**
     * @return resource
     */
    public static function openOut(string $path): mixed
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return fopen($path, 'ab');
    }

    public static function saveJson(string $path, mixed $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        file_put_contents($path, json_encode($data, $flags) . "\n");
    }

    public static function readJson(string $path): mixed
    {
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }

    /**
     * Валидирует ответ API: success=1 и наличие data.
     */
    public static function apiData(?array $json, string $what): ?array
    {
        if (!is_array($json) || !isset($json['success'])) {
            return null;
        }
        if ((int) $json['success'] !== 1) {
            return null;
        }
        $data = $json['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    public static function out(string $msg): void
    {
        fwrite(STDOUT, $msg . "\n");
    }

    public static function err(string $msg): void
    {
        fwrite(STDERR, $msg . "\n");
    }

    public static function bytes(int $n): string
    {
        if ($n >= 1073741824) {
            return round($n / 1073741824, 2) . ' GiB';
        }
        if ($n >= 1048576) {
            return round($n / 1048576, 1) . ' MiB';
        }
        if ($n >= 1024) {
            return round($n / 1024, 1) . ' KiB';
        }
        return $n . ' B';
    }

    /**
     * Собирает все уникальные media-URL из JSON-данных.
     *
     * Категории: texture (source), model (source), thumbnail (source/images),
     * media_post (images.post), media_source (images.source), media_any (всё остальное).
     *
     * @return array<string, list<string>> категория => список URL
     */
    public static function collectMediaUrls(string $dataDir): array
    {
        $byCat = ['texture' => [], 'model' => [], 'thumbnail' => [], 'media_post' => [], 'media_source' => [], 'media_any' => []];

        $jsonFiles = self::globRecursive($dataDir, ['*.json']);
        foreach ($jsonFiles as $file) {
            $data = self::readJson($file);
            self::walkMedia($data, $byCat);
        }

        foreach ($byCat as $cat => $urls) {
            $byCat[$cat] = array_values(array_unique($urls));
        }
        return $byCat;
    }

    /**
     * Обходит структуру с учётом имени ключа.
     *
     * @param array<string, list<string>> $byCat
     */
    private static function walkMedia(mixed $node, array &$byCat, string $key = ''): void
    {
        if (is_array($node)) {
            if (isset($node['source']) && is_string($node['source'])) {
                $src = self::absMediaUrl($node['source']);
                if ($src !== null) {
                    if ($key === 'texture') {
                        $byCat['texture'][] = $src;
                    } elseif ($key === 'model') {
                        $byCat['model'][] = $src;
                    } elseif ($key === 'thumbnail') {
                        $byCat['thumbnail'][] = $src;
                    }
                }
            }
            if ($key === 'thumbnail' && isset($node['images']) && is_array($node['images'])) {
                foreach ($node['images'] as $url) {
                    if (is_string($url) && ($abs = self::absMediaUrl($url)) !== null) {
                        $byCat['thumbnail'][] = $abs;
                    }
                }
            }
            foreach ($node as $k => $value) {
                if ($k === 'images' && is_array($value)) {
                    foreach ($value as $size => $url) {
                        if (!is_string($url) || ($abs = self::absMediaUrl($url)) === null) {
                            continue;
                        }
                        if ($size === 'source') {
                            $byCat['media_source'][] = $abs;
                        } elseif ($size === 'post') {
                            $byCat['media_post'][] = $abs;
                        } elseif ($size === 'texture') {
                            $byCat['texture'][] = $abs;
                        } elseif ($size === 'model') {
                            $byCat['model'][] = $abs;
                        } else {
                            $byCat['media_any'][] = $abs;
                        }
                    }
                    continue;
                }
                self::walkMedia($value, $byCat, is_string($k) ? $k : '');
            }
        }
    }

    /**
     * Приводит media-ссылку к абсолютному виду (для скачивания).
     * Относительные "/media/..." получают хост.
     */
    public static function absMediaUrl(string $url): ?string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '/media/')) {
            return self::SITE_BASE . $url;
        }
        return null;
    }

    /** @return list<string> */
    public static function globRecursive(string $dir, array $patterns): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $file->getFilename())) {
                    $out[] = $file->getPathname();
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Переписывает удалённые URL на локальные пути в тексте JSON.
     */
    public static function rewriteUrls(string $text): string
    {
        $text = str_replace(
            'https://cdn.robertsspaceindustries.com/static/starmap/',
            '/static/starmap/',
            $text
        );
        $text = str_replace(
            'https://robertsspaceindustries.com/media/',
            '/media/',
            $text
        );
        return $text;
    }
}
