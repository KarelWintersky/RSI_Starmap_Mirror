<?php

declare(strict_types=1);

namespace Starmap;

/**
 * StarmapStaticAPI.php — Класс, отвечающий JSON из статических файлов (зеркало web/api/starmap/).
 *
 * Формат ответа идентичен StarmapAPI — оба класса совместимы с оригинальным движком.
 *
 * Использование:
 *   $api = new StarmapStaticAPI(__DIR__ . '/web');
 *   echo json_encode($api->bootup());
 */

class StarmapStaticAPI
{
    private string $webRoot;
    private StarmapLocalSearch $searcher;

    public function __construct(string $webRoot)
    {
        $this->webRoot = rtrim($webRoot, '/');
        $this->searcher = new StarmapLocalSearch($webRoot);
    }

    public function bootup(): array
    {
        return $this->readJson('api/starmap/bootup.json');
    }

    public function starSystem(string $code): array
    {
        return $this->readJson("api/starmap/star-systems/{$code}.json");
    }

    public function celestialObject(string $code): array
    {
        return $this->readJson("api/starmap/celestial-objects/{$code}.json");
    }

    public function search(string $query): array
    {
        return $this->searcher->search($query);
    }

    private function readJson(string $relativePath): array
    {
        $file = $this->webRoot . '/' . $relativePath;
        if (!is_file($file)) {
            return [
                'success' => 0,
                'code'    => 'ErrNotFound',
                'msg'     => "File not found: {$relativePath}",
                'data'    => null,
            ];
        }

        $raw = file_get_contents($file);
        $json = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => 0,
                'code'    => 'ErrJsonParse',
                'msg'     => 'JSON parse error: ' . json_last_error_msg(),
                'data'    => null,
            ];
        }

        return $json;
    }
}
