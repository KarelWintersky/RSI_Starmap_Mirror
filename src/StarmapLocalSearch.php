<?php

declare(strict_types=1);

namespace Starmap;

/**
 * Локальный поиск (/api/starmap/find) по зеркалу _index.json.
 */
final class StarmapLocalSearch
{
    /** @var array{systems:list<array>, objects:list<array>} */
    private array $index;

    public function __construct(private string $webRoot)
    {
        $this->index = Util::readJson($webRoot . '/api/starmap/_index.json');
        if (!is_array($this->index)) {
            $this->index = ['systems' => [], 'objects' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $query): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return $this->emptyResult();
        }

        $systems = [];
        foreach ($this->index['systems'] as $s) {
            $hay = mb_strtolower(($s['code'] ?? '') . ' ' . ($s['name'] ?? ''));
            if (str_contains($hay, $q)) {
                $systems[] = [
                    'id' => $s['id'],
                    'code' => $s['code'],
                    'name' => $s['name'],
                    'type' => $s['type'],
                ];
            }
        }

        $objects = [];
        foreach ($this->index['objects'] as $o) {
            $hay = mb_strtolower(($o['code'] ?? '') . ' ' . ($o['designation'] ?? '') . ' ' . ($o['name'] ?? ''));
            if (str_contains($hay, $q)) {
                $objects[] = [
                    'id' => $o['id'],
                    'code' => $o['code'],
                    'designation' => $o['designation'],
                    'name' => $o['name'],
                    'star_system_id' => $o['star_system_id'],
                    'status' => 'P',
                    'type' => $o['type'],
                    'star_system' => $o['star_system'],
                ];
            }
        }

        return [
            'success' => 1,
            'code' => 'OK',
            'msg' => 'OK',
            'data' => [
                'systems' => $this->wrap($systems),
                'objects' => $this->wrap($objects),
            ],
        ];
    }

    /** @param list<array> $rows */
    private function wrap(array $rows): array
    {
        return [
            'rowcount' => count($rows),
            'pagecount' => null,
            'startrow' => 0,
            'resultset' => $rows,
            'totalrows' => count($rows),
            'pagesize' => 0,
            'page' => 1,
            'offset' => 0,
            'estimatedrows' => false,
        ];
    }

    private function emptyResult(): array
    {
        return [
            'success' => 1,
            'code' => 'OK',
            'msg' => 'OK',
            'data' => [
                'systems' => $this->wrap([]),
                'objects' => $this->wrap([]),
            ],
        ];
    }
}
