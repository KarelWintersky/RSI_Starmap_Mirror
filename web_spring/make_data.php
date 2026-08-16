<?php

declare(strict_types=1);

/**
 * Генератор данных SpringGalaxy из confmap.svg (корень проекта).
 *
 * Источники внутри SVG:
 *   слой Stars&Channels — звёзды (пятиугольники id="star_XXX", центр
 *     sodipodi:cx/sodipodi:cy) и гиперканалы (коннекторы с
 *     inkscape:connection-start/end="#star_XXX"; пунктирные stroke-dasharray);
 *   слой 2 (Labels) — русские названия систем (id="st_XXX" → текст tspan).
 *
 * Наполнение систем (планеты/лоция) — отдельная задача, пока в системе
 * только STAR (обязателен движку) и OORT (лимит zoom-out).
 *
 * Результат → api/starmap/:
 *   bootup.json               — системы, гиперканалы, конфиг
 *   star-systems/{CODE}.json  — по одному файлу на систему
 *   _index.json               — поисковый индекс (фаза 3)
 *
 * Запуск: php web_spring/make_data.php
 */

const SPRING_API = __DIR__ . '/api/starmap';
const CONFMAP = __DIR__ . '/../confmap.svg';

const TARGET_SPAN = 300.0;   // размах карты галактики в мировых единицах
const DEFAULT_OORT = 30.0;   // временный радиус Оорта (лоция систем — позже)
const STAR_SIZE = 2.0;       // условный размер звезды в системном виде

// Старые/легаси имена звёзд в коннекторах SVG → реальные id ("star_XXX"):
// Фоллхейм = Роканнон, Хальдемар = KCD-X4, Ахерон = V-AMD5 (star_acheron).
const STAR_ALIAS = [
    'FOLLHEIM' => 'ROKANNON',
    'HALDEMAR' => 'KCDX4',
    'AHERON'   => 'ACHERON',
];

// Палитра звёзд: раздаётся детерминированно по коду системы.
const STAR_PALETTE = [
    '#9ec9ff', '#cfe3ff', '#ffe9a0', '#ffd27a', '#ff9d7a', '#ffc9e8', '#fff7d6', '#7ae0c3',
];

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

// ---------------------------------------------------------------- SVG → данные

function svgParse(): array
{
    $doc = new DOMDocument();
    if (!@$doc->load(CONFMAP)) {
        fwrite(STDERR, 'Не удалось прочитать ' . CONFMAP . "\n");
        exit(1);
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('s', 'http://www.w3.org/2000/svg');

    // русские названия систем (слой Labels)
    $names = [];
    foreach ($xp->query('//s:text[starts-with(@id, "st_")]') as $t) {
        $sys = substr($t->getAttribute('id'), 3);
        $txt = trim($t->textContent);
        if ($txt !== '') {
            $names[$sys] = $txt;
        }
    }

    // звёзды: id="star_XXX", центр sodipodi:cx/cy
    $systems = [];
    $sysId = 1;
    foreach ($xp->query('//s:path[starts-with(@id, "star_")]') as $p) {
        $sys = substr($p->getAttribute('id'), 5);
        $code = strtoupper($sys);
        $systems[$code] = [
            'id' => $sysId++,
            'sys' => $sys,
            'name' => $names[$sys] ?? $sys,
            'svgX' => (float) $p->getAttribute('sodipodi:cx'),
            'svgY' => (float) $p->getAttribute('sodipodi:cy'),
        ];
    }

    // гиперканалы: коннекторы между звёздами; пунктир = свойство dashed
    $tunnelMap = [];
    foreach ($xp->query('//s:path[@inkscape:connection-start]') as $p) {
        $a = strtoupper(substr($p->getAttribute('inkscape:connection-start'), 6));
        $b = strtoupper(substr($p->getAttribute('inkscape:connection-end'), 6));
        $a = STAR_ALIAS[$a] ?? $a;
        $b = STAR_ALIAS[$b] ?? $b;
        if (!isset($systems[$a]) || !isset($systems[$b])) {
            continue;
        }
        $pair = [$a, $b];
        sort($pair, SORT_STRING);
        $key = $pair[0] . '-' . $pair[1];
        $dashed = preg_match('/stroke-dasharray:\s*[1-9]/', $p->getAttribute('style')) === 1;
        $tunnelMap[$key] ??= ['a' => $a, 'b' => $b, 'dashed' => false];
        $tunnelMap[$key]['dashed'] = $tunnelMap[$key]['dashed'] || $dashed;
    }
    ksort($systems);
    $tunnels = array_values($tunnelMap);

    // масштаб: весь размах карты → TARGET_SPAN мировых единиц (центрировано)
    $xs = array_column($systems, 'svgX');
    $ys = array_column($systems, 'svgY');
    $scale = TARGET_SPAN / max(max($xs) - min($xs), max($ys) - min($ys));
    $midX = (min($xs) + max($xs)) / 2;
    $midY = (min($ys) + max($ys)) / 2;
    foreach ($systems as $code => &$s) {
        $s['x'] = ($s['svgX'] - $midX) * $scale;
        $s['y'] = ($s['svgY'] - $midY) * $scale;
        $s['color'] = STAR_PALETTE[crc32($code) % count(STAR_PALETTE)];
    }
    unset($s);

    return ['systems' => $systems, 'tunnels' => $tunnels, 'scale' => $scale];
}

// ---------------------------------------------------------------- генерация

$src = svgParse();
$systems = $src['systems'];
$tunnels = $src['tunnels'];

// система → строка bootup.resultset
$bootupSystems = [];
foreach ($systems as $code => $s) {
    // world = (position_x, position_z, -position_y); хотим (x, 0, y)
    $bootupSystems[] = [
        'id' => $s['id'],
        'code' => $code,
        'name' => $s['name'],
        'type' => 'SINGLE_STAR',
        'description' => null,
        'position_x' => $s['x'],
        'position_y' => -$s['y'],
        'position_z' => 0.0,
        'status' => 'P',
        'affiliation' => [],
        'color' => $s['color'],
        'aggregated_size' => '1',
        'aggregated_population' => 1,
        'aggregated_economy' => 1,
        'aggregated_danger' => 1,
    ];
}

$bootupTunnels = [];
foreach ($tunnels as $i => $t) {
    $a = $systems[$t['a']];
    $b = $systems[$t['b']];
    $bootupTunnels[] = [
        'id' => $i + 1,
        'code' => $t['a'] . '-' . $t['b'],
        'direction' => 'B',
        'entry_id' => $a['id'],
        'exit_id' => $b['id'],
        'name' => null,
        'size' => 'M',
        'dashed' => $t['dashed'],
        'entry' => ['id' => $a['id'], 'code' => $t['a'], 'star_system_id' => $a['id']],
        'exit' => ['id' => $b['id'], 'code' => $t['b'], 'star_system_id' => $b['id']],
    ];
}

$bootup = [
    'success' => 1,
    'code' => 'OK',
    'msg' => 'OK',
    'data' => [
        'config' => [
            'galaxyScale' => 1.0,
            'galaxyStarSize' => 4.0,
            'starfield' => ['count' => 2500, 'color1' => '#6a7394', 'color2' => '#ffffff'],
        ],
        'systems' => ['resultset' => $bootupSystems],
        'tunnels' => ['resultset' => $bootupTunnels],
        'species' => ['resultset' => []],
        'affiliations' => ['resultset' => []],
    ],
];
springWrite(SPRING_API . '/bootup.json', $bootup);

$indexSystems = [];
foreach ($systems as $code => $s) {
    $starId = 1000 + $s['id'] * 10;
    $star = [
        'id' => $starId,
        'code' => $code . '.STAR.' . $code,
        'name' => $s['name'],
        'designation' => null,
        'type' => 'STAR',
        'appearance' => 'DEFAULT',
        'parent_id' => null,
        'distance' => 0.0,
        'latitude' => 0.0,
        'longitude' => 0.0,
        'size' => STAR_SIZE,
        'show_label' => true,
        'show_orbitlines' => false,
        'shader_data' => ['sun' => ['color1' => $s['color'], 'color2' => '#ffffff']],
        'habitable' => false,
        'description' => null,
        'affiliation' => [],
    ];
    $oort = [
        'id' => $starId + 1,
        'code' => $code . '.OORT',
        'name' => $s['name'] . ' Oort Cloud',
        'designation' => null,
        'type' => 'OORT',
        'appearance' => 'DEFAULT',
        'parent_id' => $starId,
        'distance' => DEFAULT_OORT,
        'latitude' => 0.0,
        'longitude' => 0.0,
        'size' => 1,
        'show_label' => false,
        'show_orbitlines' => false,
        'shader_data' => null,
        'habitable' => false,
        'description' => null,
        'affiliation' => [],
    ];

    $detail = [
        'id' => $s['id'],
        'code' => $code,
        'name' => $s['name'],
        'type' => 'SINGLE_STAR',
        'description' => null,
        'position_x' => $s['x'],
        'position_y' => -$s['y'],
        'position_z' => 0.0,
        'habitable_zone_inner' => null,
        'habitable_zone_outer' => null,
        'frost_line' => null,
        'oort_radius' => DEFAULT_OORT,
        'status' => 'P',
        'affiliation' => [],
        'celestial_objects' => [$star, $oort],
    ];
    springWrite(SPRING_API . '/star-systems/' . $code . '.json', [
        'success' => 1, 'code' => 'OK', 'msg' => 'OK',
        'data' => ['resultset' => [$detail]],
    ]);

    $indexSystems[] = [
        'id' => $s['id'],
        'code' => $code,
        'name' => $s['name'],
        'designation' => null,
        'type' => 'SINGLE_STAR',
        'star_system_id' => $s['id'],
    ];
}

springWrite(SPRING_API . '/_index.json', ['systems' => $indexSystems, 'objects' => []]);

springOut(sprintf(
    'confmap.svg → api/starmap/: %d систем, %d гиперканалов (%d пунктирных), %d деталей систем',
    count($systems),
    count($tunnels),
    count(array_filter($tunnels, fn (array $t): bool => $t['dashed'])),
    count($systems),
));
