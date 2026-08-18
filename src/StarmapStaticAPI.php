<?php
/**
 * StarmapStaticAPI.php — Класс, отвечающий JSON из статических файлов (зеркало web/api/starmap/).
 *
 * Формат ответа идентичен StarmapAPI — оба класса совместимы с оригинальным движком.
 *
 * Использование:
 *   $api = new StarmapStaticAPI(__DIR__ . '/web');
 *   echo json_encode($api->bootup());
 */

declare(strict_types=1);

class StarmapStaticAPI
{
    private string $webRoot;

    public function __construct(string $webRoot)
    {
        $this->webRoot = rtrim($webRoot, '/');
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
        // Полный перебор по _index.json (как делал StarmapLocalSearch)
        $index = $this->readJson('api/starmap/_index.json');
        if ($index['success'] !== 1) {
            return $index;
        }

        $q = mb_strtolower($query);
        $results = [];
        foreach ($index['data']['resultset'] as $obj) {
            $haystack = mb_strtolower(
                ($obj['code'] ?? '') . ' ' . ($obj['name'] ?? '') . ' ' . ($obj['designation'] ?? '')
            );
            if (str_contains($haystack, $q)) {
                $results[] = $obj;
            }
        }

        return [
            'success' => 1,
            'code'    => 'OK',
            'msg'     => 'OK',
            'data'    => [
                'rowcount'      => count($results),
                'pagecount'     => null,
                'startrow'      => 0,
                'resultset'     => $results,
                'totalrows'     => count($results),
                'pagesize'      => 0,
                'page'          => 1,
                'offset'        => 0,
                'estimatedrows' => false,
            ],
        ];
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
