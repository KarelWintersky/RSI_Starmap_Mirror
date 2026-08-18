<?php

declare(strict_types=1);

namespace Starmap;

/**
 * StarmapAPI.php — Класс, отвечающий JSON из MariaDB в формате оригинального движка.
 *
 * Endpoints:
 *   bootup()                     → POST /api/starmap/bootup
 *   starSystem(string $code)     → GET  /api/starmap/star-systems/{CODE}
 *   celestialObject(string $code)→ GET  /api/starmap/celestial-objects/{CODE}
 *
 * Использование:
 *   $api = new StarmapAPI($pdo);
 *   echo json_encode($api->bootup());
 */

class StarmapAPI
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    // ─── bootup ──────────────────────────────────────────────────

    public function bootup(): array
    {
        $config = $this->fetchConfig();
        $systems = $this->fetchSystems();
        $tunnels = $this->fetchTunnels();
        $affiliations = $this->fetchAffiliations();
        $species = $this->fetchSpecies();

        return [
            'success' => 1,
            'code'    => 'OK',
            'msg'     => 'OK',
            'data'    => [
                'config'       => $config,
                'systems'      => $this->wrap($systems),
                'tunnels'      => $this->wrap($tunnels),
                'affiliations' => $this->wrap($affiliations),
                'species'      => $this->wrap($species),
            ],
        ];
    }

    // ─── star-system ─────────────────────────────────────────────

    public function starSystem(string $code): array
    {
        $sql = "SELECT * FROM star_systems WHERE code = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$code]);
        $sys = $stmt->fetch();

        if (!$sys) {
            return $this->error('ErrSystemNotFound', "System '$code' not found");
        }

        $objects = $this->fetchObjectsForSystem((int)$sys['id']);

        // Формируем affiliation
        $sys['affiliation'] = $this->fetchSystemAffiliations((int)$sys['id']);

        // thumbnail
        $sys['thumbnail'] = $this->buildThumbnail(
            $sys['thumbnail_slug'] ?? null,
            $sys['thumbnail_source'] ?? null
        );

        // shader_data: декодируем JSON
        $sys['shader_data'] = $this->decodeJson($sys['shader_data']);

        // Убираем служебные поля, которых нет в оригинальном формате
        unset($sys['thumbnail_slug'], $sys['thumbnail_source']);
        unset($sys['oort_radius']);

        $sys['celestial_objects'] = $objects;

        return [
            'success' => 1,
            'code'    => 'OK',
            'msg'     => 'OK',
            'data'    => $this->wrap([$sys]),
        ];
    }

    // ─── celestial-object ────────────────────────────────────────

    public function celestialObject(string $code): array
    {
        $sql = "SELECT * FROM celestial_objects WHERE code = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$code]);
        $obj = $stmt->fetch();

        if (!$obj) {
            return $this->error('ErrObjectNotFound', "Object '$code' not found");
        }

        $this->formatObject($obj);

        // Дочерние объекты (детальный файл содержит children)
        $obj['children'] = $this->fetchChildren((int)$obj['id']);

        return [
            'success' => 1,
            'code'    => 'OK',
            'msg'     => 'OK',
            'data'    => $this->wrap([$obj]),
        ];
    }

    // ─── search ──────────────────────────────────────────────────

    public function search(string $query): array
    {
        $q = '%' . $query . '%';
        $sql = "SELECT code, name, designation, type
                FROM celestial_objects
                WHERE code LIKE ? OR name LIKE ? OR designation LIKE ?
                ORDER BY
                  CASE WHEN code LIKE ? THEN 0
                       WHEN name LIKE ? THEN 1
                       ELSE 2 END,
                  code
                LIMIT 50";
        $like = [$q, $q, $q, $q, $q];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($like);
        $results = $stmt->fetchAll();

        return [
            'success' => 1,
            'code'    => 'OK',
            'msg'     => 'OK',
            'data'    => $this->wrap($results),
        ];
    }

    // ─── Внутренние методы ───────────────────────────────────────

    private function fetchConfig(): array
    {
        $stmt = $this->pdo->query("SELECT config FROM engine_config WHERE id = 1");
        $row = $stmt->fetch();
        return $this->decodeJson($row['config'] ?? '{}');
    }

    private function fetchSystems(): array
    {
        $sql = "SELECT s.*,
                       (SELECT JSON_ARRAYAGG(
                         JSON_OBJECT('id', a.id, 'code', a.code, 'name', a.name, 'color', a.color)
                       ) FROM system_affiliations sa
                       JOIN affiliations a ON a.id = sa.affiliation_id
                       WHERE sa.system_id = s.id) as affiliation_json
                FROM star_systems s
                ORDER BY s.id";
        $rows = $this->pdo->query($sql)->fetchAll();

        return array_map(function ($r) {
            $r['affiliation'] = $this->decodeJson($r['affiliation_json'] ?? '[]') ?? [];
            unset($r['affiliation_json']);

            $r['thumbnail'] = $this->buildThumbnail(
                $r['thumbnail_slug'] ?? null,
                $r['thumbnail_source'] ?? null
            );
            unset($r['thumbnail_slug'], $r['thumbnail_source']);

            // Убираем поля, которых нет в bootup
            unset($r['habitable_zone_inner'], $r['habitable_zone_outer'],
                  $r['frost_line'], $r['oort_radius'],
                  $r['shader_data'], $r['aggregated_size'],
                  $r['aggregated_population'], $r['aggregated_economy'],
                  $r['aggregated_danger']);

            return $r;
        }, $rows);
    }

    private function fetchObjectsForSystem(int $sysId): array
    {
        $sql = "SELECT * FROM celestial_objects WHERE star_system_id = ? ORDER BY id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$sysId]);
        $rows = $stmt->fetchAll();

        return array_map(function ($r) {
            $this->formatObject($r);
            return $r;
        }, $rows);
    }

    private function fetchChildren(int $parentId): array
    {
        $sql = "SELECT * FROM celestial_objects WHERE parent_id = ? ORDER BY id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$parentId]);
        $rows = $stmt->fetchAll();

        return array_map(function ($r) {
            $this->formatObject($r);
            // Рекурсивные вложенные children (для detail файлов)
            $r['children'] = $this->fetchChildren((int)$r['id']);
            return $r;
        }, $rows);
    }

    private function formatObject(array &$obj): void
    {
        // Булевы поля: TINYINT → bool/null
        $obj['habitable']      = $this->toBool($obj['habitable']);
        $obj['fairchanceact']  = $this->toBool($obj['fairchanceact']);
        $obj['show_label']     = $this->toBool($obj['show_label']);
        $obj['show_orbitlines']= $this->toBool($obj['show_orbitlines']);

        // shader_data: JSON-строка → объект
        $obj['shader_data'] = $this->decodeJson($obj['shader_data']);

        // subtype → объект
        $obj['subtype'] = $obj['subtype_id'] !== null
            ? ['id' => (int)$obj['subtype_id'], 'name' => $obj['subtype_name'] ?? '']
            : null;

        // texture
        $obj['texture'] = $this->buildThumbnail(
            $obj['texture_slug'] ?? null,
            $obj['texture_source'] ?? null
        );

        // model (MANMADE)
        if ($obj['model_slug'] !== null) {
            $obj['model'] = [
                'slug'   => $obj['model_slug'],
                'source' => $obj['model_source'],
                'images' => [],
            ];
        } else {
            $obj['model'] = null;
        }

        // affiliation
        $obj['affiliation'] = $this->fetchObjectAffiliations((int)$obj['id']);

        // population: всегда пустой массив (dead field в оригинале)
        $obj['population'] = [];

        // Убираем служебные поля
        unset($obj['subtype_id'], $obj['subtype_name'],
              $obj['texture_slug'], $obj['texture_source'],
              $obj['model_slug'], $obj['model_source']);
    }

    private function fetchObjectAffiliations(int $objId): array
    {
        $sql = "SELECT a.id, a.code, a.name, a.color, oa.membership_id
                FROM object_affiliations oa
                JOIN affiliations a ON a.id = oa.affiliation_id
                WHERE oa.object_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$objId]);
        $rows = $stmt->fetchAll();

        return array_map(function ($r) {
            $r['id']   = (int)$r['id'];
            $r['membership.id'] = $r['membership_id'];
            unset($r['membership_id']);
            return $r;
        }, $rows);
    }

    private function fetchSystemAffiliations(int $sysId): array
    {
        $sql = "SELECT a.id, a.code, a.name, a.color, sa.membership_id
                FROM system_affiliations sa
                JOIN affiliations a ON a.id = sa.affiliation_id
                WHERE sa.system_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$sysId]);
        $rows = $stmt->fetchAll();

        return array_map(function ($r) {
            $r['id'] = (int)$r['id'];
            $r['membership.id'] = $r['membership_id'];
            unset($r['membership_id']);
            return $r;
        }, $rows);
    }

    private function fetchTunnels(): array
    {
        $sql = "SELECT t.*,
                       ej.code        as entry_code,
                       ej.designation as entry_designation,
                       ej.distance    as entry_distance,
                       ej.latitude    as entry_latitude,
                       ej.longitude   as entry_longitude,
                       ej.star_system_id as entry_star_system_id,
                       ex.code        as exit_code,
                       ex.designation as exit_designation,
                       ex.distance    as exit_distance,
                       ex.latitude    as exit_latitude,
                       ex.longitude   as exit_longitude,
                       ex.star_system_id as exit_star_system_id
                FROM tunnels t
                JOIN celestial_objects ej ON ej.id = t.entry_id
                JOIN celestial_objects ex ON ex.id = t.exit_id
                ORDER BY t.id";
        $rows = $this->pdo->query($sql)->fetchAll();

        return array_map(function ($r) {
            return [
                'id'        => (int)$r['id'],
                'direction' => $r['direction'],
                'entry_id'  => (int)$r['entry_id'],
                'exit_id'   => (int)$r['exit_id'],
                'name'      => $r['name'],
                'size'      => $r['size'],
                'entry'     => [
                    'id'             => (int)$r['entry_id'],
                    'code'           => $r['entry_code'],
                    'designation'    => $r['entry_designation'],
                    'distance'       => (float)$r['entry_distance'],
                    'latitude'       => $r['entry_latitude'] !== null ? (float)$r['entry_latitude'] : null,
                    'longitude'      => $r['entry_longitude'] !== null ? (float)$r['entry_longitude'] : null,
                    'name'           => null,
                    'star_system_id' => (int)$r['entry_star_system_id'],
                    'status'         => 'P',
                    'type'           => 'JUMPPOINT',
                ],
                'exit'      => [
                    'id'             => (int)$r['exit_id'],
                    'code'           => $r['exit_code'],
                    'designation'    => $r['exit_designation'],
                    'distance'       => (float)$r['exit_distance'],
                    'latitude'       => $r['exit_latitude'] !== null ? (float)$r['exit_latitude'] : null,
                    'longitude'      => $r['exit_longitude'] !== null ? (float)$r['exit_longitude'] : null,
                    'name'           => null,
                    'star_system_id' => (int)$r['exit_star_system_id'],
                    'status'         => 'P',
                    'type'           => 'JUMPPOINT',
                ],
            ];
        }, $rows);
    }

    private function fetchAffiliations(): array
    {
        $sql = "SELECT id, code, name, color FROM affiliations ORDER BY id";
        return $this->pdo->query($sql)->fetchAll();
    }

    private function fetchSpecies(): array
    {
        $sql = "SELECT id, code, name FROM species ORDER BY id";
        return $this->pdo->query($sql)->fetchAll();
    }

    // ─── Утилиты ─────────────────────────────────────────────────

    private function wrap(array $resultset): array
    {
        return [
            'rowcount'     => count($resultset),
            'pagecount'    => null,
            'startrow'     => 0,
            'resultset'    => $resultset,
            'totalrows'    => count($resultset),
            'pagesize'     => 0,
            'page'         => 1,
            'offset'       => 0,
            'estimatedrows' => false,
        ];
    }

    private function error(string $code, string $msg): array
    {
        return [
            'success' => 0,
            'code'    => $code,
            'msg'     => $msg,
            'data'    => null,
        ];
    }

    private function buildThumbnail(?string $slug, ?string $source): ?array
    {
        if (!$slug) return null;
        return [
            'slug'   => $slug,
            'source' => $source,
            'images' => [
                'post' => $source,
                'product_thumb_large' => $source,
                'subscribers_vault_thumbnail' => $source,
            ],
        ];
    }

    private function decodeJson(?string $json): mixed
    {
        if ($json === null || $json === '' || $json === 'null') return null;
        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function toBool(mixed $val): ?bool
    {
        if ($val === null) return null;
        return (bool)$val;
    }
}
