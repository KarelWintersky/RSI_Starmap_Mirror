<?php

declare(strict_types=1);

/**
 * Генератор тестового датасета SpringGalaxy → api/starmap/.
 *
 * Формат (см. PLAN.md «Данные»):
 *   bootup.json                    — системы, туннели, фракции, конфиг
 *   star-systems/{CODE}.json       — ВСЯ инфа по системе в одном файле
 *                                    (celestial-objects/* не нужны вообще)
 *
 * Запуск: php web_spring/make_data.php
 */

const SPRING_API = __DIR__ . '/api/starmap';

// ---------------------------------------------------------------- сеттинг

const SYSTEMS = [
    'ALPHA' => [
        'id' => 1,
        'name' => 'Alpha',
        'type' => 'SINGLE_STAR',
        'position' => [0.0, 0.0, 0.0],
        'description' => 'Домашняя система. Голубая планета Avalon у тёплой звезды, пояс астероидов и древний аномальный объект.',
        'habitable_zone_inner' => 0.5,
        'habitable_zone_outer' => 1.2,
        'frost_line' => 2.5,
        'oort_radius' => 40.0,
        'star_color' => ['#ffe9a0', '#ffffff'],
        'affiliation' => ['myfaction'],
    ],
    'BETA' => [
        'id' => 2,
        'name' => 'Beta',
        'type' => 'SINGLE_STAR',
        'position' => [60.0, 0.0, 40.0],
        'description' => 'Голубоватая звезда, газовый гигант Bex и промышленный узел.',
        'habitable_zone_inner' => 0.4,
        'habitable_zone_outer' => 1.0,
        'frost_line' => 2.0,
        'oort_radius' => 35.0,
        'star_color' => ['#9ec9ff', '#ffffff'],
        'affiliation' => ['myfaction'],
    ],
    'GAMMA' => [
        'id' => 3,
        'name' => 'Gamma',
        'type' => 'SINGLE_STAR',
        'position' => [130.0, 0.0, -20.0],
        'description' => 'Жёлтая звезда, станция-форпост перед системой Чёрной Дыры.',
        'habitable_zone_inner' => 0.6,
        'habitable_zone_outer' => 1.1,
        'frost_line' => 2.2,
        'oort_radius' => 30.0,
        'star_color' => ['#ffd27a', '#fff7d6'],
        'affiliation' => ['myfaction'],
    ],
    'VOID' => [
        'id' => 4,
        'name' => 'Void',
        'type' => 'SINGLE_STAR',
        'position' => [200.0, 0.0, -70.0],
        'description' => 'Система Чёрной Дыры. Дальше — только тишина.',
        'habitable_zone_inner' => 0.0,
        'habitable_zone_outer' => 0.0,
        'frost_line' => 0.0,
        'oort_radius' => 50.0,
        'star_color' => null,
        'affiliation' => [],
    ],
    'DELTA' => [
        'id' => 5,
        'name' => 'Delta',
        'type' => 'SINGLE_STAR',
        'position' => [-90.0, 0.0, 20.0],
        'description' => 'Холодный красный карлик, почти мёртвая система.',
        'habitable_zone_inner' => 0.2,
        'habitable_zone_outer' => 0.5,
        'frost_line' => 1.0,
        'oort_radius' => 25.0,
        'star_color' => ['#ff9d7a', '#ffd9c8'],
        'affiliation' => [],
    ],
];

// объекты: код => [id, type, parent(код|null), distance(АЕ), lat, lon, size(км), appearance, name, designation, texture, shader_data, orbitlines, label]
// size для звёзд/ЧД/поясов — «условные» (не км), движок нормализует сам.
const OBJECTS = [
    // ---------------------------------------------------------- ALPHA
    'ALPHA.STAR.ALPHA'          => ['id' => 100, 'type' => 'STAR',        'parent' => null,  'distance' => 0.0,  'lat' => 0,   'lon' => 0,    'size' => 2.0,    'appearance' => 'DEFAULT',     'name' => 'Alpha',      'designation' => null,          'texture' => null, 'shader' => ['sun' => ['color1' => '#ffe9a0', 'color2' => '#ffffff']], 'orbit' => false, 'label' => true],
    'ALPHA.MANMADE.DOCK'        => ['id' => 101, 'type' => 'MANMADE',     'parent' => 100,  'distance' => 0.35, 'lat' => 5,   'lon' => 40,   'size' => 1.5,    'appearance' => 'DEFAULT',     'name' => 'Deep Space Dock', 'designation' => null,           'texture' => null, 'shader' => null, 'orbit' => true,  'label' => true],
    'ALPHA.PLANETS.AVALON'      => ['id' => 102, 'type' => 'PLANET',      'parent' => 100,  'distance' => 0.8,  'lat' => 2,   'lon' => -15,  'size' => 6100,   'appearance' => 'PLANET_BLUE', 'name' => 'Avalon',     'designation' => 'Alpha I',     'texture' => '/media/planet-one.jpg', 'shader' => null, 'orbit' => true, 'label' => true],
    'ALPHA.MOONS.AVALONA'       => ['id' => 103, 'type' => 'SATELLITE',   'parent' => 102,  'distance' => 0.006,'lat' => 0,   'lon' => 45,   'size' => 900,    'appearance' => 'DEFAULT',     'name' => 'Avalon Prime', 'designation' => 'Alpha Ia',      'texture' => null, 'shader' => null, 'orbit' => true,  'label' => true],
    'ALPHA.LZ.NEWHAVEN'         => ['id' => 104, 'type' => 'LZ',          'parent' => 102,  'distance' => 0.008,'lat' => 12,  'lon' => 80,   'size' => 0.1,    'appearance' => 'DEFAULT',     'name' => 'New Haven',   'designation' => null,          'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'ALPHA.PLANETS.ASHDOR'      => ['id' => 105, 'type' => 'PLANET',      'parent' => 100,  'distance' => 1.4,  'lat' => -3,  'lon' => 120,  'size' => 8200,   'appearance' => 'PLANET_GREEN', 'name' => 'Ashdor',    'designation' => 'Alpha II',    'texture' => '/media/planet-two.jpg', 'shader' => null, 'orbit' => true, 'label' => true],
    'ALPHA.JUMPPOINTS.BETA'     => ['id' => 106, 'type' => 'JUMPPOINT',   'parent' => 100,  'distance' => 1.8,  'lat' => -5,  'lon' => 130,  'size' => 0,      'appearance' => 'DEFAULT',     'name' => null,         'designation' => 'Alpha - Beta', 'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'ALPHA.BELTS.ALPHA'         => ['id' => 107, 'type' => 'ASTEROID_BELT', 'parent' => 100,'distance' => 2.2,  'lat' => 0,   'lon' => 0,    'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'Alpha Belt',  'designation' => null,          'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'ALPHA.FIELDS.ALPHA'        => ['id' => 108, 'type' => 'ASTEROID_FIELD', 'parent' => 100, 'distance' => 3.0, 'lat' => 8,  'lon' => -60,  'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'Tumble Field','designation' => null,          'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'ALPHA.ANOMALY.RIFT'        => ['id' => 109, 'type' => 'ANOMALY',     'parent' => 100,  'distance' => 4.0,  'lat' => 15,  'lon' => 200,  'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'The Rift',    'designation' => null,          'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'ALPHA.POI.WRECK'           => ['id' => 110, 'type' => 'POI',         'parent' => 100,  'distance' => 2.7,  'lat' => -10, 'lon' => -120, 'size' => 0.5,    'appearance' => 'DEFAULT',     'name' => 'Ancient Wreck','designation' => null,         'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'ALPHA.JUMPPOINTS.DELTA'    => ['id' => 111, 'type' => 'JUMPPOINT',   'parent' => 100,  'distance' => 5.5,  'lat' => 2,   'lon' => -30,  'size' => 0,      'appearance' => 'DEFAULT',     'name' => null,         'designation' => 'Alpha - Delta', 'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'ALPHA.OORT'                => ['id' => 112, 'type' => 'OORT',        'parent' => 100,  'distance' => 40.0, 'lat' => 0,   'lon' => 0,    'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'Alpha Oort Cloud', 'designation' => null,         'texture' => null, 'shader' => null, 'orbit' => false, 'label' => false],
    // ---------------------------------------------------------- BETA
    'BETA.STAR.BETA'            => ['id' => 200, 'type' => 'STAR',        'parent' => null,  'distance' => 0.0,  'lat' => 0,   'lon' => 0,    'size' => 1.8,    'appearance' => 'DEFAULT',     'name' => 'Beta',       'designation' => null,          'texture' => null, 'shader' => ['sun' => ['color1' => '#9ec9ff', 'color2' => '#ffffff']], 'orbit' => false, 'label' => true],
    'BETA.PLANETS.BANE'         => ['id' => 201, 'type' => 'PLANET',      'parent' => 200,  'distance' => 0.6,  'lat' => 4,   'lon' => -20,  'size' => 7600,   'appearance' => 'PLANET_BROWN', 'name' => 'Bane',      'designation' => 'Beta I',      'texture' => null, 'shader' => null, 'orbit' => true,  'label' => true],
    'BETA.PLANETS.BEX'          => ['id' => 202, 'type' => 'PLANET',      'parent' => 200,  'distance' => 3.5,  'lat' => 0,   'lon' => 90,   'size' => 140000, 'appearance' => 'PLANET_GAS',  'name' => 'Bex',        'designation' => 'Beta II',      'texture' => null, 'shader' => null, 'orbit' => true,  'label' => true],
    'BETA.MOONS.BEXI'           => ['id' => 203, 'type' => 'SATELLITE',   'parent' => 202,  'distance' => 0.004,'lat' => 0,   'lon' => 10,   'size' => 600,    'appearance' => 'DEFAULT',     'name' => 'Bexi',       'designation' => 'Beta IIa',     'texture' => null, 'shader' => null, 'orbit' => true,  'label' => true],
    'BETA.JUMPPOINTS.ALPHA'     => ['id' => 204, 'type' => 'JUMPPOINT',   'parent' => 200,  'distance' => 9.0,  'lat' => -1,  'lon' => -5,   'size' => 0,      'appearance' => 'DEFAULT',     'name' => null,         'designation' => 'Beta - Alpha', 'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'BETA.JUMPPOINTS.GAMMA'     => ['id' => 205, 'type' => 'JUMPPOINT',   'parent' => 200,  'distance' => 6.0,  'lat' => 3,   'lon' => -70,  'size' => 0,      'appearance' => 'DEFAULT',     'name' => null,         'designation' => 'Beta - Gamma', 'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'BETA.OORT'                 => ['id' => 206, 'type' => 'OORT',        'parent' => 200,  'distance' => 35.0, 'lat' => 0,   'lon' => 0,    'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'Beta Oort Cloud', 'designation' => null,         'texture' => null, 'shader' => null, 'orbit' => false, 'label' => false],
    // ---------------------------------------------------------- GAMMA
    'GAMMA.STAR.GAMMA'          => ['id' => 300, 'type' => 'STAR',        'parent' => null,  'distance' => 0.0,  'lat' => 0,   'lon' => 0,    'size' => 1.9,    'appearance' => 'DEFAULT',     'name' => 'Gamma',      'designation' => null,          'texture' => null, 'shader' => ['sun' => ['color1' => '#ffd27a', 'color2' => '#fff7d6']], 'orbit' => false, 'label' => true],
    'GAMMA.PLANETS.GAEL'        => ['id' => 301, 'type' => 'PLANET',      'parent' => 300,  'distance' => 0.9,  'lat' => -2,  'lon' => 30,   'size' => 5800,   'appearance' => 'PLANET_BLUE', 'name' => 'Gael',       'designation' => 'Gamma I',      'texture' => '/media/planet-one.jpg', 'shader' => null, 'orbit' => true, 'label' => true],
    'GAMMA.MANMADE.OUTPOST'     => ['id' => 302, 'type' => 'MANMADE',     'parent' => 300,  'distance' => 0.45, 'lat' => -6,  'lon' => -140, 'size' => 1.2,    'appearance' => 'DEFAULT',     'name' => 'Forward Outpost', 'designation' => null,         'texture' => null, 'shader' => null, 'orbit' => true,  'label' => true],
    'GAMMA.BELTS.GAMMA'         => ['id' => 303, 'type' => 'ASTEROID_BELT', 'parent' => 300, 'distance' => 2.0, 'lat' => 0,   'lon' => 0,    'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'Gamma Belt',  'designation' => null,          'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'GAMMA.JUMPPOINTS.BETA'     => ['id' => 304, 'type' => 'JUMPPOINT',   'parent' => 300,  'distance' => 5.0,  'lat' => -3,  'lon' => 110,  'size' => 0,      'appearance' => 'DEFAULT',     'name' => null,         'designation' => 'Gamma - Beta', 'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'GAMMA.JUMPPOINTS.VOID'     => ['id' => 305, 'type' => 'JUMPPOINT',   'parent' => 300,  'distance' => 7.0,  'lat' => 5,   'lon' => -90,  'size' => 0,      'appearance' => 'DEFAULT',     'name' => null,         'designation' => 'Gamma - Void', 'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'GAMMA.OORT'                => ['id' => 306, 'type' => 'OORT',        'parent' => 300,  'distance' => 30.0, 'lat' => 0,   'lon' => 0,    'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'Gamma Oort Cloud', 'designation' => null,         'texture' => null, 'shader' => null, 'orbit' => false, 'label' => false],
    // ---------------------------------------------------------- VOID (чёрная дыра)
    'VOID.BLACKHOLE.VOID'       => ['id' => 400, 'type' => 'BLACKHOLE',   'parent' => null,  'distance' => 0.0,  'lat' => 0,   'lon' => 0,    'size' => 1.5,    'appearance' => 'DEFAULT',     'name' => 'The Void',   'designation' => null,          'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'VOID.POI.ECHO'             => ['id' => 401, 'type' => 'POI',         'parent' => 400,  'distance' => 3.0,  'lat' => 10,  'lon' => 45,   'size' => 0.4,    'appearance' => 'DEFAULT',     'name' => 'Sagittarius Echo', 'designation' => null,         'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'VOID.JUMPPOINTS.GAMMA'     => ['id' => 402, 'type' => 'JUMPPOINT',   'parent' => 400,  'distance' => 8.0,  'lat' => -4,  'lon' => -160, 'size' => 0,      'appearance' => 'DEFAULT',     'name' => null,         'designation' => 'Void - Gamma', 'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'VOID.OORT'                 => ['id' => 403, 'type' => 'OORT',        'parent' => 400,  'distance' => 50.0, 'lat' => 0,   'lon' => 0,    'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'Void Oort Cloud', 'designation' => null,          'texture' => null, 'shader' => null, 'orbit' => false, 'label' => false],
    // ---------------------------------------------------------- DELTA
    'DELTA.STAR.DELTA'          => ['id' => 500, 'type' => 'STAR',        'parent' => null,  'distance' => 0.0,  'lat' => 0,   'lon' => 0,    'size' => 1.2,    'appearance' => 'DEFAULT',     'name' => 'Delta',      'designation' => null,          'texture' => null, 'shader' => ['sun' => ['color1' => '#ff9d7a', 'color2' => '#ffd9c8']], 'orbit' => false, 'label' => true],
    'DELTA.JUMPPOINTS.ALPHA'    => ['id' => 501, 'type' => 'JUMPPOINT',   'parent' => 500,  'distance' => 4.0,  'lat' => 0,   'lon' => 0,    'size' => 0,      'appearance' => 'DEFAULT',     'name' => null,         'designation' => 'Delta - Alpha', 'texture' => null, 'shader' => null, 'orbit' => false, 'label' => true],
    'DELTA.OORT'                => ['id' => 502, 'type' => 'OORT',        'parent' => 500,  'distance' => 25.0, 'lat' => 0,   'lon' => 0,    'size' => 1,      'appearance' => 'DEFAULT',     'name' => 'Delta Oort Cloud', 'designation' => null,         'texture' => null, 'shader' => null, 'orbit' => false, 'label' => false],
];

const TUNNELS = [
    ['id' => 10, 'size' => 'M', 'direction' => 'B', 'a' => 'ALPHA.JUMPPOINTS.BETA',   'b' => 'BETA.JUMPPOINTS.ALPHA'],
    ['id' => 11, 'size' => 'S', 'direction' => 'B', 'a' => 'BETA.JUMPPOINTS.GAMMA',   'b' => 'GAMMA.JUMPPOINTS.BETA'],
    ['id' => 12, 'size' => 'L', 'direction' => 'B', 'a' => 'GAMMA.JUMPPOINTS.VOID',   'b' => 'VOID.JUMPPOINTS.GAMMA'],
    ['id' => 13, 'size' => 'M', 'direction' => 'B', 'a' => 'ALPHA.JUMPPOINTS.DELTA',  'b' => 'DELTA.JUMPPOINTS.ALPHA'],
];

const AFFILIATIONS = [
    ['id' => 1, 'code' => 'myfaction', 'color' => '#48bbd4', 'name' => 'My Faction'],
];

// ---------------------------------------------------------------- helpers

function springOut(string $s): void { fwrite(STDOUT, $s . "\n"); }

function springJson(mixed $data): string
{
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

function springWrite(string $path, mixed $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, springJson($data));
}

function springSys(string $code, array $cfg): array
{
    [$x, $y, $z] = $cfg['position'];
    return [
        'id' => $cfg['id'],
        'code' => $code,
        'name' => $cfg['name'],
        'type' => $cfg['type'],
        'description' => $cfg['description'],
        'position_x' => $x,
        'position_y' => $y,
        'position_z' => $z,
        'status' => 'P',
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

function springObj(string $code): array
{
    $o = OBJECTS[$code];
    return [
        'id' => $o['id'],
        'code' => $code,
        'name' => $o['name'],
        'designation' => $o['designation'],
        'type' => $o['type'],
        'appearance' => $o['appearance'],
        'parent_id' => $o['parent'],
        'distance' => $o['distance'],
        'latitude' => $o['lat'],
        'longitude' => $o['lon'],
        'size' => $o['size'],
        'show_label' => $o['label'],
        'show_orbitlines' => $o['orbit'],
        'shader_data' => $o['shader'],
        'habitable' => $o['type'] === 'PLANET',
        'description' => null,
        'affiliation' => [],
    ] + ($o['texture'] ? ['texture' => ['slug' => 't', 'source' => $o['texture'], 'images' => []]] : []);
}

// ---------------------------------------------------------------- генерация

$bootup = [
    'success' => 1,
    'code' => 'OK',
    'msg' => 'OK',
    'data' => [
        'config' => [
            'galaxyScale' => 1.0,
            'galaxyStarSize' => 2.5,
            'starfield' => ['radius' => 400, 'count' => 1500, 'color1' => '#7a7a7a', 'color2' => '#ffffff'],
        ],
        'systems' => ['resultset' => array_map(fn (string $c): array => springSys($c, SYSTEMS[$c]), array_keys(SYSTEMS))],
        'tunnels' => ['resultset' => array_map(
            fn (array $t): array => [
                'id' => $t['id'],
                'direction' => $t['direction'],
                'entry_id' => OBJECTS[$t['a']]['id'],
                'exit_id' => OBJECTS[$t['b']]['id'],
                'name' => null,
                'size' => $t['size'],
                'entry' => ['id' => OBJECTS[$t['a']]['id'], 'code' => $t['a'], 'star_system_id' => SYSTEMS[explode('.', $t['a'])[0]]['id']],
                'exit' => ['id' => OBJECTS[$t['b']]['id'], 'code' => $t['b'], 'star_system_id' => SYSTEMS[explode('.', $t['b'])[0]]['id']],
            ],
            TUNNELS
        )],
        'species' => ['resultset' => []],
        'affiliations' => ['resultset' => AFFILIATIONS],
    ],
];
springWrite(SPRING_API . '/bootup.json', $bootup);

foreach (SYSTEMS as $code => $cfg) {
    $objects = array_filter(array_keys(OBJECTS), fn (string $c): bool => explode('.', $c)[0] === $code);
    $rows = array_map(fn (string $c): array => springObj($c), $objects);
    // корневые (STAR/BLACKHOLE) сначала — чтобы parent_id был определён раньше (движку без разницы, но аккуратно)
    usort($rows, fn (array $a, array $b): int => ($a['parent_id'] ?? -1) <=> ($b['parent_id'] ?? -1));

    $detail = [
        'id' => $cfg['id'],
        'code' => $code,
        'name' => $cfg['name'],
        'type' => $cfg['type'],
        'description' => $cfg['description'],
        'position_x' => $cfg['position'][0],
        'position_y' => $cfg['position'][1],
        'position_z' => $cfg['position'][2],
        'habitable_zone_inner' => $cfg['habitable_zone_inner'],
        'habitable_zone_outer' => $cfg['habitable_zone_outer'],
        'frost_line' => $cfg['frost_line'],
        'oort_radius' => $cfg['oort_radius'],
        'status' => 'P',
        'affiliation' => $bootup['data']['systems']['resultset'][array_search($code, array_column($bootup['data']['systems']['resultset'], 'code'), true)]['affiliation'],
        'celestial_objects' => $rows,
    ];
    springWrite(SPRING_API . '/star-systems/' . $code . '.json', [
        'success' => 1, 'code' => 'OK', 'msg' => 'OK',
        'data' => ['resultset' => [$detail]],
    ]);
}

// поисковый индекс (фаза 3)
$systems = array_map(fn (string $c): array => springSys($c, SYSTEMS[$c]), array_keys(SYSTEMS));
$objects = array_map(fn (string $c): array => [
    'id' => OBJECTS[$c]['id'],
    'code' => $c,
    'name' => OBJECTS[$c]['name'],
    'designation' => OBJECTS[$c]['designation'],
    'type' => OBJECTS[$c]['type'],
    'star_system_id' => SYSTEMS[explode('.', $c)[0]]['id'],
], array_keys(OBJECTS));
springWrite(SPRING_API . '/_index.json', ['systems' => $systems, 'objects' => $objects]);

springOut('web_spring/api/starmap/: bootup.json, star-systems/' . count(SYSTEMS) . ', _index.json');
