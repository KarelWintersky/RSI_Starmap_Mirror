<?php

declare(strict_types=1);

/**
 * Генератор планетарных систем для web_spring/api/starmap/star-systems/*.json.
 *
 * Потребляет готовую топологию (bootup.json из web_spring/make_data.php) и
 * генерит случайную лоцию каждой системы:
 *   - 2..6 планет (обозначения A..Z) на орбитах «как в Солнечной системе»;
 *   - с шансом belt_chance вместо 5-й планеты — пояс астероидов;
 *   - гиперканалы в районе орбиты 6-й планеты (по одному на туннель);
 *   - облако Оорта в районе «Плутона» (опция generate_oort).
 *
 * Пока генерация случайная. В будущем лоция будет читаться из текстового
 * файла — заменяется только тело genSystemLayout().
 *
 * Настройки — config.confmap.php (корень проекта, return [...]).
 * Результат → web_spring/api/starmap/:
 *   star-systems/{CODE}.json  — по файлу на систему
 *   _index.json               — системы + объекты для поиска (фаза 3)
 *
 * Запуск: php web_spring/generate_systems.php
 */

const SPRING_API = __DIR__ . '/api/starmap';

// Орбиты (АЕ) и радиусы (км) «как в Солнечной системе» — первые шесть тел.
// Слот 5 — кандидат на пояс астероидов; слот 6 — орбита гиперканалов.
const SOLAR_SLOTS = [
    ['au' => 0.39,  'radius_km' => 2439,  'name' => 'Меркурий'],
    ['au' => 0.72,  'radius_km' => 6051,  'name' => 'Венера'],
    ['au' => 1.00,  'radius_km' => 6371,  'name' => 'Земля'],
    ['au' => 1.52,  'radius_km' => 3389,  'name' => 'Марс'],
    ['au' => 5.20,  'radius_km' => 69911, 'name' => 'Юпитер'],
    ['au' => 9.54,  'radius_km' => 58232, 'name' => 'Сатурн'],
];

const PLANET_APPEARANCES = ['PLANET_DEFAULT', 'PLANET_BLUE', 'PLANET_GREEN'];

const HABITABLE_INNER = 0.7;  // АЕ
const HABITABLE_OUTER = 1.6;  // АЕ
const FROST_LINE = 2.7;       // АЕ

// ---------------------------------------------------------------- helpers

function springOut(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}

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

// значение ± t% (jitter для расстояний и размеров)
function jitter(float $v, float $t = 0.1): float
{
    return $v * (1.0 + (mt_rand(-1000, 1000) / 1000.0) * $t);
}

function randLon(): float
{
    return mt_rand(0, 35999) / 100.0;
}

function randLat(): float
{
    return mt_rand(-500, 500) / 1000.0;
}

// равномерный float из [0,1) (mt_rand — сид для детерминированной генерации)
function chance(): float
{
    return mt_rand() / mt_getrandmax();
}

function genConfig(): array
{
    $defaults = [
        'planets_min' => 2,
        'planets_max' => 6,
        'belt_chance' => 0.5,
        'generate_oort' => true,
        'jump_radius_au' => 9.54,
        'oort_radius_au' => 39.5,
        'seed' => null,
    ];
    $cfgFile = __DIR__ . '/../config.confmap.php';
    $cfg = is_file($cfgFile) ? (require $cfgFile) : [];
    return array_merge($defaults, $cfg['generation'] ?? []);
}

// ---------------------------------------------------------------- объекты

function obj(
    int $id,
    string $code,
    ?string $name,
    ?string $designation,
    string $type,
    ?int $parent,
    float $distance,
    float $lat,
    float $lon,
    ?float $size,
    bool $showLabel,
    bool $showOrbitlines,
    ?array $shaderData,
): array {
    return [
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'designation' => $designation,
        'type' => $type,
        'appearance' => 'DEFAULT',
        'parent_id' => $parent,
        'distance' => $distance,
        'latitude' => $lat,
        'longitude' => $lon,
        'size' => $size,
        'show_label' => $showLabel,
        'show_orbitlines' => $showOrbitlines,
        'shader_data' => $shaderData,
        'habitable' => false,
        'description' => null,
        'affiliation' => [],
    ];
}

// лоция системы: звезда + планеты/пояс + гиперканалы + OORT
// → [celestial_objects[], oort_radius]
function genSystemLayout(array $sys, array $links, array $cfg): array
{
    $code = $sys['code'];
    $name = $sys['name'];
    $starId = 1000 + (int) $sys['id'] * 10;
    $nextId = $starId + 2;
    $objects = [];

    // звезда (обязательна движку: PointLight, цвет свечения)
    $objects[] = obj($starId, $code . '.STAR.' . $code, $name, null, 'STAR', null,
        0.0, 0.0, 0.0, 2.0, true, false,
        ['sun' => ['color1' => $sys['color'] ?? '#ffe9a0', 'color2' => '#ffffff']]);

    // планеты (A..Z) на орбитах «как в Солнечной системе»; пояс вместо 5-й планеты
    $planets = mt_rand((int) $cfg['planets_min'], (int) $cfg['planets_max']);
    $planetNo = 0;
    for ($slot = 0; $slot < $planets; $slot++) {
        $s = SOLAR_SLOTS[$slot];
        $id = $nextId++;
        if ($slot === 4 && chance() < $cfg['belt_chance']) {
            $objects[] = obj($id, $code . '.ASTEROID_BELT', $name . ' — пояс астероидов', null,
                'ASTEROID_BELT', $starId, jitter($s['au']), 0.0, randLon(), jitter(1.4, 0.4),
                false, false, null);
            continue;
        }
        $letter = chr(ord('A') + $planetNo++);
        $p = obj($id, $code . '.PLANET.' . $letter, $name . ' ' . $letter, $letter, 'PLANET', $starId,
            jitter($s['au']), randLat(), randLon(), jitter((float) $s['radius_km'], 0.15),
            true, true, null);
        $p['appearance'] = PLANET_APPEARANCES[mt_rand(0, count(PLANET_APPEARANCES) - 1)];
        $objects[] = $p;
    }

    // гиперканалы — на орбите 6-й планеты, по одному на туннель системы
    foreach ($links as $dest) {
        $objects[] = obj($nextId++, $code . '.JUMP.' . $dest['code'], 'Гиперканал → ' . $dest['name'], null,
            'JUMPPOINT', $starId, jitter((float) $cfg['jump_radius_au']), randLat(), randLon(), 1.0,
            true, false, null);
    }

    // облако Оорта в районе «Плутона» (опция)
    $oortRadius = (float) $cfg['oort_radius_au'];
    if ($cfg['generate_oort']) {
        $objects[] = obj($starId + 1, $code . '.OORT', $name . ' Oort Cloud', null, 'OORT', $starId,
            $oortRadius, 0.0, 0.0, 1.0, false, false, null);
    }

    return [$objects, $oortRadius];
}

// ---------------------------------------------------------------- генерация

$cfg = genConfig();
if ($cfg['seed'] !== null) {
    mt_srand((int) $cfg['seed']);
}

$bootupPath = SPRING_API . '/bootup.json';
$bootup = json_decode((string) file_get_contents($bootupPath), true);
if (!is_array($bootup) || ($bootup['success'] ?? 0) !== 1) {
    fwrite(STDERR, 'Нет ' . $bootupPath . " — сначала: php web_spring/make_data.php\n");
    exit(1);
}

$data = $bootup['data'];
$systems = $data['systems']['resultset'] ?? [];
$tunnels = $data['tunnels']['resultset'] ?? [];

// туннели системы: code → список связанных систем (entry/exit) с именами
$sysNames = [];
foreach ($systems as $s) {
    $sysNames[$s['code']] = $s['name'];
}
$links = [];
foreach ($tunnels as $t) {
    if (isset($t['entry']['code'], $t['exit']['code'])) {
        $links[$t['entry']['code']][] = ['code' => $t['exit']['code'], 'name' => $sysNames[$t['exit']['code']] ?? $t['exit']['code']];
        $links[$t['exit']['code']][] = ['code' => $t['entry']['code'], 'name' => $sysNames[$t['entry']['code']] ?? $t['entry']['code']];
    }
}

$indexSystems = [];
$indexObjects = [];
$tot = ['planets' => 0, 'belts' => 0, 'jumps' => 0, 'oorts' => 0];

foreach ($systems as $sys) {
    [$objects, $oortRadius] = genSystemLayout($sys, $links[$sys['code']] ?? [], $cfg);

    $detail = [
        'id' => $sys['id'],
        'code' => $sys['code'],
        'name' => $sys['name'],
        'type' => $sys['type'] ?? 'SINGLE_STAR',
        'description' => null,
        'position_x' => $sys['position_x'],
        'position_y' => $sys['position_y'],
        'position_z' => $sys['position_z'],
        'habitable_zone_inner' => HABITABLE_INNER,
        'habitable_zone_outer' => HABITABLE_OUTER,
        'frost_line' => FROST_LINE,
        'oort_radius' => $oortRadius,
        'status' => $sys['status'] ?? 'P',
        'affiliation' => $sys['affiliation'] ?? [],
        'celestial_objects' => $objects,
    ];
    springWrite(SPRING_API . '/star-systems/' . $sys['code'] . '.json', [
        'success' => 1, 'code' => 'OK', 'msg' => 'OK',
        'data' => ['resultset' => [$detail]],
    ]);

    $indexSystems[] = [
        'id' => $sys['id'],
        'code' => $sys['code'],
        'name' => $sys['name'],
        'designation' => null,
        'type' => $sys['type'] ?? 'SINGLE_STAR',
        'star_system_id' => $sys['id'],
    ];
    foreach ($objects as $o) {
        if ($o['type'] === 'STAR') {
            continue;
        }
        $indexObjects[] = [
            'id' => $o['id'],
            'code' => $o['code'],
            'name' => $o['name'],
            'designation' => $o['designation'],
            'type' => $o['type'],
            'star_system_id' => $sys['id'],
            'parent_id' => $o['parent_id'],
        ];
    }
}
$tot['planets'] = count(array_filter($indexObjects, fn (array $o): bool => $o['type'] === 'PLANET'));
$tot['belts'] = count(array_filter($indexObjects, fn (array $o): bool => $o['type'] === 'ASTEROID_BELT'));
$tot['jumps'] = count(array_filter($indexObjects, fn (array $o): bool => $o['type'] === 'JUMPPOINT'));
$tot['oorts'] = count(array_filter($indexObjects, fn (array $o): bool => $o['type'] === 'OORT'));

springWrite(SPRING_API . '/_index.json', ['systems' => $indexSystems, 'objects' => $indexObjects]);

// вычистить устаревшие файлы систем, которых больше нет в bootup
$codes = array_column($systems, 'code');
foreach (glob(SPRING_API . '/star-systems/*.json') ?: [] as $f) {
    if (!in_array(basename($f, '.json'), $codes, true)) {
        unlink($f);
    }
}

springOut(sprintf(
    'bootup.json → star-systems/: %d систем, планет %d, поясов %d, гиперканалов %d, OORT %d',
    count($systems),
    $tot['planets'],
    $tot['belts'],
    $tot['jumps'],
    $tot['oorts'],
));
