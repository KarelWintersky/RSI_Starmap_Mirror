<?php

declare(strict_types=1);

/**
 * Генератор данных демо-сеттинга → JSON-схема движка ARK Starmap.
 *
 * Схема: MIN_SCHEME.md. Запуск: php web_demo/make_data.php
 * Перегенерирует web_demo/api/starmap/ (bootup, star-systems, celestial-objects, _index.json).
 *
 * Это прообраз будущего конвертера: данные сеттинга (ниже, секция "сеттинг")
 * раскладываются по файлам, которые потребляет движок.
 */

const DEMO_API = __DIR__ . '/api/starmap';

// --------------------------------------------------------------------- сеттинг
// «Мой сеттинг»: две системы, связанные туннелем. Всё остальное — формат API.

const SYSTEMS = [
    'ALPHA' => [
        'id' => 1,
        'name' => 'Alpha',
        'type' => 'SINGLE_STAR',
        'position' => [50.0, 0.0, 0.0],
        'description' => 'Первая система демо-сеттинга. Голубая планета One у единственной звезды.',
        'habitable_zone_inner' => 0.5,
        'habitable_zone_outer' => 1.2,
        'frost_line' => 2.5,
        'affiliation' => ['myfaction'],
    ],
    'BETA' => [
        'id' => 2,
        'name' => 'Beta',
        'type' => 'SINGLE_STAR',
        'position' => [-50.0, 0.0, 0.0],
        'description' => 'Вторая система демо-сеттинга, соединена с Alpha туннелем размера M.',
        'habitable_zone_inner' => 0.4,
        'habitable_zone_outer' => 1.0,
        'frost_line' => 2.0,
        'affiliation' => ['myfaction'],
    ],
];

// объекты: код => [id, type, parent, distance(АЕ), lat, lon, size(км), appearance, name, designation, texture, habitable, sensors]
const OBJECTS = [
    'ALPHA.STAR.ALPHA'            => ['id' => 100, 'type' => 'STAR',        'parent' => null, 'distance' => 0.0,   'lat' => 0,   'lon' => 0,    'size' => 1.2,     'appearance' => 'DEFAULT',        'name' => 'Alpha',  'designation' => null,      'texture' => null, 'habitable' => false, 'sensors' => [0, 0, 0]],
    'ALPHA.JUMPPOINTS.BETA'       => ['id' => 101, 'type' => 'JUMPPOINT',   'parent' => 100,  'distance' => 1.8,   'lat' => -5,  'lon' => 130,  'size' => 0,        'appearance' => 'DEFAULT',        'name' => null,     'designation' => 'Alpha - Beta', 'texture' => null, 'habitable' => false, 'sensors' => [0, 0, 0]],
    'ALPHA.PLANET.ONE'            => ['id' => 102, 'type' => 'PLANET',      'parent' => 100,  'distance' => 0.8,   'lat' => 0,   'lon' => 0,    'size' => 6000,     'appearance' => 'PLANET_BLUE',    'name' => 'One',    'designation' => 'Alpha I',     'texture' => '/media/planet-one.jpg', 'habitable' => true, 'sensors' => [5, 5, 1]],
    'ALPHA.MOONS.ONEA'            => ['id' => 103, 'type' => 'SATELLITE',   'parent' => 102,  'distance' => 0.005, 'lat' => 0,   'lon' => 45,   'size' => 0.5,      'appearance' => 'DEFAULT',        'name' => 'One A',  'designation' => 'Alpha Ia',    'texture' => null, 'habitable' => false, 'sensors' => [0, 0, 0]],
    'ALPHA.BELTS.ALPHA'           => ['id' => 104, 'type' => 'ASTEROID_BELT', 'parent' => 100, 'distance' => 1.5,  'lat' => 0,   'lon' => 0,    'size' => 1,        'appearance' => 'DEFAULT',        'name' => 'Alpha Belt', 'designation' => null,  'texture' => null, 'habitable' => false, 'sensors' => [0, 0, 0]],
    'BETA.STAR.BETA'              => ['id' => 200, 'type' => 'STAR',        'parent' => null, 'distance' => 0.0,   'lat' => 0,   'lon' => 0,    'size' => 1.2,     'appearance' => 'DEFAULT',        'name' => 'Beta',   'designation' => null,      'texture' => null, 'habitable' => false, 'sensors' => [0, 0, 0]],
    'BETA.JUMPPOINTS.ALPHA'       => ['id' => 201, 'type' => 'JUMPPOINT',   'parent' => 200,  'distance' => 9.0,   'lat' => -1,  'lon' => -5,   'size' => 0,        'appearance' => 'DEFAULT',        'name' => null,     'designation' => 'Beta - Alpha', 'texture' => null, 'habitable' => false, 'sensors' => [0, 0, 0]],
    'BETA.PLANET.TWO'             => ['id' => 202, 'type' => 'PLANET',      'parent' => 200,  'distance' => 0.6,   'lat' => 10,  'lon' => -20,  'size' => 8000,     'appearance' => 'PLANET_GREEN',   'name' => 'Two',    'designation' => 'Beta I',      'texture' => '/media/planet-two.jpg', 'habitable' => false, 'sensors' => [2, 3, 4]],
];

const TUNNELS = [
    ['id' => 10, 'size' => 'M', 'direction' => 'B', 'a' => 'ALPHA.JUMPPOINTS.BETA', 'b' => 'BETA.JUMPPOINTS.ALPHA'],
];

const AFFILIATIONS = [
    ['id' => 1, 'code' => 'myfaction', 'color' => '#48bbd4', 'name' => 'My Faction'],
];

// Иконки фракций живут в web/static/starmap/sourceimages/factions/{code}.png.
// Система без фракции → код "NONE" → спрайт с пустой текстурой = белый квадрат.
// Fallback: round-иконка NONE.png (копия uee.png), чтобы таких квадратов не было.
const FACTION_ICON_FALLBACK = __DIR__ . '/static/starmap/sourceimages/factions/NONE.png';
const FACTION_ICON_SOURCE = __DIR__ . '/../web/static/starmap/sourceimages/factions/uee.png';

// --------------------------------------------------------------------- helpers

function demoOut(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}

function demoJson(mixed $data): string
{
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

function demoWrite(string $path, mixed $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, demoJson($data));
}

function systemRow(string $code, array $cfg): array
{
    [$x, $y, $z] = $cfg['position'];
    return [
        'id' => $cfg['id'],
        'code' => $code,
        'description' => $cfg['description'],
        'info_url' => null,
        'name' => $cfg['name'],
        'position_x' => $x,
        'position_y' => $y,
        'position_z' => $z,
        'status' => 'P',
        'time_modified' => '2026-01-01 00:00:00',
        'type' => $cfg['type'],
        'affiliation' => array_map(
            fn (string $ac): array => AFFILIATIONS[array_search($ac, array_column(AFFILIATIONS, 'code'), true)] ?? [],
            $cfg['affiliation']
        ),
        'aggregated_size' => '4.85',
        'aggregated_population' => 5,
        'aggregated_economy' => 5,
        'aggregated_danger' => 3,
    ];
}

function objectRow(string $code): array
{
    $o = OBJECTS[$code];
    return [
        'id' => $o['id'],
        'age' => 0,
        'appearance' => $o['appearance'],
        'axial_tilt' => 0,
        'code' => $code,
        'description' => null,
        'designation' => $o['designation'],
        'distance' => $o['distance'],
        'fairchanceact' => false,
        'habitable' => $o['habitable'],
        'info_url' => null,
        'latitude' => $o['lat'],
        'longitude' => $o['lon'],
        'name' => $o['name'],
        'orbit_period' => null,
        'parent_id' => $o['parent'],
        'sensor_danger' => (string) $o['sensors'][2],
        'sensor_economy' => (string) $o['sensors'][1],
        'sensor_population' => (string) $o['sensors'][0],
        'shader_data' => null,
        'show_label' => true,
        'show_orbitlines' => in_array($o['type'], ['PLANET', 'SATELLITE'], true),
        'size' => $o['size'],
        'subtype_id' => null,
        'time_modified' => '2026-01-01 00:00:00',
        'type' => $o['type'],
        'subtype' => null,
        'affiliation' => [],
        'population' => [],
    ] + ($o['texture'] ? ['texture' => ['slug' => 'demo', 'source' => $o['texture'], 'images' => []]] : []);
}

function jpRow(string $code): array
{
    $o = OBJECTS[$code];
    return [
        'id' => $o['id'],
        'code' => $code,
        'designation' => $o['designation'],
        'distance' => $o['distance'],
        'latitude' => $o['lat'],
        'longitude' => $o['lon'],
        'name' => null,
        'star_system_id' => SYSTEMS[explode('.', $code)[0]]['id'],
        'status' => 'P',
        'type' => 'JUMPPOINT',
    ];
}

// --------------------------------------------------------------------- генерация

$bootup = [
    'success' => 1,
    'code' => 'OK',
    'msg' => 'OK',
    'data' => [
        'config' => [],
        'systems' => ['resultset' => array_map(fn (string $c): array => systemRow($c, SYSTEMS[$c]), array_keys(SYSTEMS))],
        'tunnels' => ['resultset' => array_map(
            fn (array $t): array => [
                'id' => $t['id'],
                'direction' => $t['direction'],
                'entry_id' => OBJECTS[$t['a']]['id'],
                'exit_id' => OBJECTS[$t['b']]['id'],
                'name' => null,
                'size' => $t['size'],
                'entry' => jpRow($t['a']),
                'exit' => jpRow($t['b']),
            ],
            TUNNELS
        )],
        'species' => ['resultset' => []],
        'affiliations' => ['resultset' => AFFILIATIONS],
    ],
];
demoWrite(DEMO_API . '/bootup.json', $bootup);

foreach (SYSTEMS as $code => $cfg) {
    $objects = array_filter(array_keys(OBJECTS), fn (string $c): bool => explode('.', $c)[0] === $code);
    $rows = array_map(fn (string $c): array => objectRow($c), $objects);
    // порядок: сначала корневые (STAR), чтобы parent_id был определён раньше — движку без разницы,
    // он строит дерево по parent_id (см. MIN_SCHEME.md), но пусть будет аккуратно.
    usort($rows, fn (array $a, array $b): int => ($a['parent_id'] ?? -1) <=> ($b['parent_id'] ?? -1));

    $detail = [
        'id' => $cfg['id'],
        'code' => $code,
        'description' => $cfg['description'],
        'frost_line' => $cfg['frost_line'],
        'habitable_zone_inner' => $cfg['habitable_zone_inner'],
        'habitable_zone_outer' => $cfg['habitable_zone_outer'],
        'info_url' => null,
        'name' => $cfg['name'],
        'position_x' => $cfg['position'][0],
        'position_y' => $cfg['position'][1],
        'position_z' => $cfg['position'][2],
        'shader_data' => null,
        'status' => 'P',
        'time_modified' => '2026-01-01 00:00:00',
        'type' => $cfg['type'],
        'affiliation' => $bootup['data']['systems']['resultset'][array_search($code, array_column($bootup['data']['systems']['resultset'], 'code'), true)]['affiliation'],
        'celestial_objects' => $rows,
    ];
    demoWrite(DEMO_API . '/star-systems/' . $code . '.json', [
        'success' => 1, 'code' => 'OK', 'msg' => 'OK',
        'data' => ['resultset' => [$detail]],
    ]);
}

foreach (OBJECTS as $code => $o) {
    $row = objectRow($code);
    demoWrite(DEMO_API . '/celestial-objects/' . $code . '.json', [
        'success' => 1, 'code' => 'OK', 'msg' => 'OK',
        'data' => ['resultset' => [$row]],
    ]);
}

// поисковый индекс для /api/starmap/find
$systems = array_map(fn (string $c): array => systemRow($c, SYSTEMS[$c]), array_keys(SYSTEMS));
$objects = array_map(fn (string $c): array => [
    'id' => OBJECTS[$c]['id'],
    'code' => $c,
    'designation' => OBJECTS[$c]['designation'],
    'name' => OBJECTS[$c]['name'],
    'type' => OBJECTS[$c]['type'],
    'star_system_id' => SYSTEMS[explode('.', $c)[0]]['id'],
    'star_system' => ['id' => SYSTEMS[explode('.', $c)[0]]['id'], 'code' => explode('.', $c)[0], 'name' => SYSTEMS[explode('.', $c)[0]]['name'], 'type' => SYSTEMS[explode('.', $c)[0]]['type']],
], array_keys(OBJECTS));
demoWrite(DEMO_API . '/_index.json', [
    'systems' => $systems,
    'objects' => $objects,
    'sysById' => $systems,
]);

// fallback-иконка для систем без фракции (код "NONE"): без неё спрайт = белый квадрат
if (is_file(FACTION_ICON_SOURCE) && (!is_file(FACTION_ICON_FALLBACK) || !empty($GLOBALS['FORCE']))) {
    copy(FACTION_ICON_SOURCE, FACTION_ICON_FALLBACK);
    demoOut('factions: NONE.png (fallback)');
}

demoOut('web_demo/api/starmap/: bootup.json, star-systems/' . count(SYSTEMS) . ', celestial-objects/' . count(OBJECTS) . ', _index.json');
