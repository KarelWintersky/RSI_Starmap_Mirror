<?php

declare(strict_types=1);

namespace Starmap;

use RuntimeException;

/**
 * Локальная реализация /api/starmap/routes/find.
 *
 * Воспроизводит ответ оригинального API: граф туннелей (прыжки) + полёты
 * внутри систем (3D-расстояние между точками прыжков), два критерия:
 *   shortest   -> минимальная суммарная дистанция полётов;
 *   leastjumps -> минимальное число прыжков (при равенстве — короче).
 *
 * Данные — из зеркала bootup.json.
 */
final class OfflineRouter
{
    /** @var array<string, array{id:int, code:string, name:string, type:?string}> */
    private array $systems = [];

    /** @var array<int, string> id => code */
    private array $sysCodeById = [];

    /** @var array<string, array{id:int, code:string, designation:?string, system:?string, xyz:array{0:float,1:float,2:float}, size:?string}> */
    private array $jumpPoints = [];

    /** @var list<array{a: string, b: string, size: string, name: string}> */
    private array $tunnels = [];

    /** @var array<string, list<string>> system code => jp codes */
    private array $jpsBySystem = [];

    public function __construct(private string $bootupPath)
    {
        $this->load();
    }

    private function load(): void
    {
        $bootup = Util::readJson($this->bootupPath);
        $data = $bootup['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('bootup.json не прочитан: ' . $this->bootupPath);
        }
        foreach ($data['systems']['resultset'] ?? [] as $s) {
            $this->systems[$s['code']] = [
                'id' => (int) $s['id'],
                'code' => $s['code'],
                'name' => $s['name'],
                'type' => $s['type'] ?? null,
            ];
            $this->sysCodeById[(int) $s['id']] = $s['code'];
        }
        foreach ($data['tunnels']['resultset'] ?? [] as $t) {
            $entry = $t['entry'] ?? null;
            $exit = $t['exit'] ?? null;
            if (!is_array($entry) || !is_array($exit)) {
                continue;
            }
            $entryKey = $this->registerJp($entry);
            $exitKey = $this->registerJp($exit);
            if ($entryKey === null || $exitKey === null) {
                continue;
            }
            $this->tunnels[] = [
                'a' => $entryKey,
                'b' => $exitKey,
                'size' => $t['size'] ?? 'S',
                'name' => $entry['designation'] ?? ($entry['code'] ?? ''),
            ];
            $this->jpsBySystem[$this->jumpPoints[$entryKey]['system']][] = $entryKey;
            $this->jpsBySystem[$this->jumpPoints[$exitKey]['system']][] = $exitKey;
        }
        // система может упоминаться и без прыжков — создаём пустые списки
        foreach (array_keys($this->systems) as $code) {
            $this->jpsBySystem[$code] ??= [];
        }
        foreach ($this->jpsBySystem as &$list) {
            $list = array_values(array_unique($list));
        }
        unset($list);
    }

    /**
     * @param array{code?:mixed, id?:mixed, designation?:mixed, star_system_id?:mixed, distance?:mixed, latitude?:mixed, longitude?:mixed} $jp
     */
    private function registerJp(array $jp): ?string
    {
        $code = $jp['code'] ?? null;
        if (!is_string($code) || $code === '') {
            return null;
        }
        if (isset($this->jumpPoints[$code])) {
            return $code;
        }
        $systemCode = $this->sysCodeById[(int) ($jp['star_system_id'] ?? 0)] ?? null;
        $distance = (float) ($jp['distance'] ?? 0);
        $lat = deg2rad((float) ($jp['latitude'] ?? 0));
        $lon = deg2rad((float) ($jp['longitude'] ?? 0));
        $this->jumpPoints[$code] = [
            'id' => (int) ($jp['id'] ?? 0),
            'code' => $code,
            'designation' => is_string($jp['designation'] ?? null) ? $jp['designation'] : null,
            'system' => $systemCode,
            'xyz' => [
                $distance * cos($lat) * cos($lon),
                $distance * sin($lat),
                $distance * cos($lat) * sin($lon),
            ],
            'size' => is_string($jp['size'] ?? null) ? $jp['size'] : null,
        ];
        return $code;
    }

    // ----------------------------------------------------------------- public

    /**
     * Полный ответ в формате API.
     *
     * @param array{departure?:mixed, destination?:mixed, ship_size?:mixed} $params
     * @return array<string, mixed>
     */
    public function route(array $params): array
    {
        $dep = $params['departure'] ?? null;
        $dest = $params['destination'] ?? null;
        $ship = strtoupper((string) ($params['ship_size'] ?? ''));

        if (!in_array($ship, ['S', 'M', 'L'], true)) {
            return [
                'success' => 0,
                'code' => 'ErrValidationFailed',
                'msg' => 'Validation failed',
                'data' => ['ship_size' => 'ship_size is not valid'],
            ];
        }

        $depPoint = $this->resolvePoint(is_string($dep) ? $dep : '');
        $destPoint = $this->resolvePoint(is_string($dest) ? $dest : '');
        if ($depPoint === null) {
            return ['success' => 0, 'code' => 'ErrInvalidObject', 'msg' => 'Invalid object specified', 'data' => $dep];
        }
        if ($destPoint === null) {
            return ['success' => 0, 'code' => 'ErrInvalidObject', 'msg' => 'Invalid object specified', 'data' => $dest];
        }

        $shortest = $this->findPath($depPoint, $destPoint, $ship, false);
        $least = $this->findPath($depPoint, $destPoint, $ship, true);

        if ($shortest === null && $least === null) {
            return ['success' => 1, 'code' => 'OK', 'msg' => 'OK', 'data' => []];
        }
        if ($shortest === null) {
            $shortest = $least;
        }
        if ($least === null) {
            $least = $shortest;
        }

        $routeData = function (array $path) use ($depPoint, $destPoint): array {
            $segments = $this->buildSegments($depPoint, $destPoint, $path['tunnels'], $path['firstJp'], $path['lastJp']);
            $flightDistance = 0.0;
            foreach ($segments as $seg) {
                if (is_numeric($seg['segment_distance'] ?? null)) {
                    $flightDistance += (float) $seg['segment_distance'];
                }
            }
            return [
                'name' => $depPoint['label'] . ' to ' . $destPoint['label'],
                'label' => $path['label'],
                'first_jump' => $path['firstJump'],
                'flight_distance' => round($flightDistance, 10),
                'jumps' => $path['jumpCount'],
                'segments' => $segments,
            ];
        };

        return [
            'success' => 1,
            'code' => 'OK',
            'msg' => 'OK',
            'data' => [
                'shortest' => $routeData($shortest),
                'leastjumps' => $routeData($least),
            ],
        ];
    }

    // ------------------------------------------------------------------ engine

    /**
     * @return array{system:?string, object:?string, label:string, kind:'system'|'object', xyz:array{0:float,1:float,2:float}, name:string, id:?int}|null
     */
    private function resolvePoint(string $code): ?array
    {
        if ($code === '') {
            return null;
        }
        if (isset($this->systems[$code])) {
            $s = $this->systems[$code];
            return [
                'system' => $code,
                'object' => null,
                'label' => $s['name'],
                'kind' => 'system',
                'name' => $s['name'],
                'id' => null,
                'xyz' => [0.0, 0.0, 0.0],
            ];
        }
        // объект: код начинается с кода системы
        $sysCode = strtok($code, '.');
        if (is_string($sysCode) && isset($this->systems[$sysCode])) {
            $obj = $this->findObject($code);
            if ($obj === null) {
                return null;
            }
            $label = $obj['designation'] ?? $obj['code'];
            if (!empty($obj['name'])) {
                $label .= ' (' . $obj['name'] . ')';
            }
            $distance = (float) ($obj['distance'] ?? 0);
            $lat = deg2rad((float) ($obj['latitude'] ?? 0));
            $lon = deg2rad((float) ($obj['longitude'] ?? 0));
            return [
                'system' => $sysCode,
                'object' => $code,
                'label' => $label,
                'kind' => 'object',
                'name' => $label,
                'id' => (int) ($obj['id'] ?? 0),
                'xyz' => [
                    $distance * cos($lat) * cos($lon),
                    $distance * sin($lat),
                    $distance * cos($lat) * sin($lon),
                ],
            ];
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function findObject(string $code): ?array
    {
        // ищем в зеркале объектов (celestial-objects) либо в системных деталях
        $path = Util::dataPath('celestial-objects', $code . '.json');
        $d = Util::readJson($path);
        if (is_array($d) && isset($d['data']['resultset'][0])) {
            return $d['data']['resultset'][0];
        }
        // по системным деталям (краткие объекты)
        $sysCode = strtok($code, '.');
        if (!is_string($sysCode)) {
            return null;
        }
        $sysPath = Util::dataPath('star-systems', $sysCode . '.json');
        $sd = Util::readJson($sysPath);
        foreach ($sd['data']['resultset'][0]['celestial_objects'] ?? [] as $co) {
            if (($co['code'] ?? null) === $code) {
                return $co;
            }
        }
        return null;
    }

    /**
     * @return array{tunnels:list<array{from:string,to:string,name:string,size:string}>, firstJp:?string, lastJp:?string,
     *                jumpCount:int, firstJump:?string, label:string}|null
     */
    private function findPath(array $dep, array $dest, string $ship, bool $byJumps): ?array
    {
        // одна система — прямой перелёт без прыжков
        if ($dep['system'] === $dest['system']) {
            return [
                'tunnels' => [],
                'firstJp' => null,
                'lastJp' => null,
                'jumpCount' => 0,
                'firstJump' => null,
                'label' => 'Local to ' . ($this->systems[$dest['system']]['name'] ?? $dest['system']),
            ];
        }

        // ship S -> все туннели; M -> M,L; L -> только L
        $minTunnelSize = ['S' => 'S', 'M' => 'M', 'L' => 'L'][$ship] ?? 'S';
        $rank = ['S' => 0, 'M' => 1, 'L' => 2];

        // граф: узлы = точки прыжков (+ dep/dest объекты)
        // вес ребра: [dist, jumps]
        $nodes = [];
        $nodeIdx = [];

        // стартовая виртуальная точка
        $start = 'START';
        $nodes[$start] = true;
        $nodeIdx[$start] = 0;

        $adj = [];

        $addEdge = function (string $from, string $to, float $dist, int $jumps) use (&$adj): void {
            $adj[$from][$to] = ['dist' => $dist, 'jumps' => $jumps];
        };

        // точки прыжков (узлы графа)
        $jpBySystem = [];
        foreach ($this->jpsBySystem as $sys => $codes) {
            foreach ($codes as $code) {
                $jpBySystem[$sys][] = $code;
                $nodes[$code] = true;
                $nodeIdx[$code] = count($nodes);
            }
        }

        // старт
        if ($dep['kind'] === 'object') {
            $nodes[$dep['object']] = true;
            $nodeIdx[$dep['object']] = count($nodes);
            $addEdge($start, $dep['object'], 0.0, 0);
            foreach ($jpBySystem[$dep['system']] ?? [] as $jp) {
                $addEdge($dep['object'], $jp, self::d3($dep['xyz'], $this->jumpPoints[$jp]['xyz']), 0);
            }
        } else {
            foreach ($jpBySystem[$dep['system']] ?? [] as $jp) {
                $addEdge($start, $jp, 0.0, 0);
            }
        }

        // цель
        $goal = null;
        if ($dest['kind'] === 'object') {
            $nodes[$dest['object']] = true;
            $nodeIdx[$dest['object']] = count($nodes);
            $goal = $dest['object'];
            foreach ($jpBySystem[$dest['system']] ?? [] as $jp) {
                $addEdge($jp, $dest['object'], self::d3($this->jumpPoints[$jp]['xyz'], $dest['xyz']), 0);
            }
        }

        // туннели (межсистемные, 1 прыжок, dist 0)
        foreach ($this->tunnels as $t) {
            if (($rank[$t['size']] ?? 0) < $rank[$minTunnelSize]) {
                continue;
            }
            if (isset($nodes[$t['a']]) && isset($nodes[$t['b']])) {
                $addEdge($t['a'], $t['b'], 0.0, 1);
                $addEdge($t['b'], $t['a'], 0.0, 1);
            }
        }

        // внутрисистемные полёты между прыжками
        foreach ($jpBySystem as $codes) {
            foreach ($codes as $i => $a) {
                foreach ($codes as $b) {
                    if ($a === $b) {
                        continue;
                    }
                    $addEdge($a, $b, self::d3($this->jumpPoints[$a]['xyz'], $this->jumpPoints[$b]['xyz']), 0);
                }
            }
        }

        $isGoal = function (string $node) use ($goal, $dest): bool {
            if ($goal !== null) {
                return $node === $goal;
            }
            // системная цель: пришли в любую точку прыжка целевой системы
            return ($this->jumpPoints[$node]['system'] ?? null) === $dest['system'];
        };

        [$dist, $jumps, $prev] = self::dijkstra($adj, $start, $isGoal, $byJumps);

        if ($dist === INF || $jumps === INF) {
            return null;
        }

        // восстановление пути
        $chain = [];
        $cur = null;
        foreach (array_keys($nodeIdx) as $node) {
            if ($isGoal($node) && $dist[$node] < INF) {
                $cur = $node;
                break;
            }
        }
        if ($cur === null) {
            return null;
        }
        while ($cur !== $start && $cur !== null) {
            $chain[] = $cur;
            $cur = $prev[$cur] ?? null;
        }
        $chain[] = $start;
        $chain = array_reverse($chain);

        // извлекаем туннели и граничные точки
        $tunnels = [];
        $firstJp = null;
        $lastJp = null;
        foreach ($chain as $i => $node) {
            if ($node === $start || !isset($this->jumpPoints[$node])) {
                continue;
            }
            $next = $chain[$i + 1] ?? null;
            if ($next !== null && isset($this->jumpPoints[$next])
                && $this->jumpPoints[$node]['system'] !== $this->jumpPoints[$next]['system']) {
                // межсистемное ребро = туннель (направление по цепочке)
                $t = $this->tunnelBetween($node, $next);
                if ($t !== null) {
                    $tunnels[] = [
                        'from' => $node,
                        'to' => $next,
                        'name' => $t['name'],
                        'size' => $t['size'],
                    ];
                }
            }
            if ($firstJp === null) {
                $firstJp = $node;
            }
            $lastJp = $node;
        }

        $jumpCount = count($tunnels);

        $firstTunnel = $tunnels[0] ?? null;
        if ($firstTunnel !== null) {
            $firstJumpSystem = $this->jumpPoints[$firstTunnel['to']]['system'];
            $label = 'Through ' . ($this->systems[$firstJumpSystem]['name'] ?? '');
            $firstJump = $firstTunnel['name'];
        } else {
            $label = 'Local to ' . ($this->systems[$dest['system']]['name'] ?? $dest['system']);
            $firstJump = null;
        }

        return [
            'tunnels' => $tunnels,
            'firstJp' => $firstJp,
            'lastJp' => $lastJp,
            'jumpCount' => $jumpCount,
            'firstJump' => $firstJump,
            'label' => $label,
        ];
    }

    /**
     * @param array<string, array<string, array{dist:float, jumps:int}>> $adj
     * @return array{0: array<string,float>, 1: array<string,int>, 2: array<string,?string>}
     */
    private function dijkstra(array $adj, string $start, callable $isGoal, bool $byJumps): array
    {
        $dist = [];
        $jumps = [];
        $prev = [];
        $visited = [];

        $key = static function (string $node) use (&$dist, &$jumps, $byJumps): int {
            if ($byJumps) {
                return ($jumps[$node] ?? PHP_INT_MAX) * 1_000_000_000 + (int) round(($dist[$node] ?? INF) * 1e3);
            }
            return (int) round(($dist[$node] ?? INF) * 1e6) * 1000 + ($jumps[$node] ?? PHP_INT_MAX);
        };

        $pq = new \SplPriorityQueue();
        $pq->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $dist[$start] = 0.0;
        $jumps[$start] = 0;
        $pq->insert($start, -$key($start));

        while (!$pq->isEmpty()) {
            $cur = $pq->extract();
            $node = $cur['data'];
            if ($visited[$node] ?? false) {
                continue;
            }
            $visited[$node] = true;
            if ($isGoal($node)) {
                break;
            }
            foreach ($adj[$node] ?? [] as $next => $w) {
                if ($visited[$next] ?? false) {
                    continue;
                }
                $nd = $dist[$node] + $w['dist'];
                $nj = $jumps[$node] + $w['jumps'];
                if (!isset($dist[$next]) || self::less([$nj, $nd], [$jumps[$next] ?? PHP_INT_MAX, $dist[$next] ?? INF], $byJumps)) {
                    $dist[$next] = $nd;
                    $jumps[$next] = $nj;
                    $prev[$next] = $node;
                    $pq->insert($next, -$key($next));
                }
            }
        }

        return [$dist, $jumps, $prev];
    }

    /**
     * Лексикографическое сравнение (a лучше b) по выбранному критерию.
     *
     * @param array{0:int,1:float} $a
     * @param array{0:int,1:float} $b
     */
    private static function less(array $a, array $b, bool $byJumps): bool
    {
        $order = $byJumps ? [0, 1] : [1, 0];
        foreach ($order as $k) {
            if ($a[$k] !== $b[$k]) {
                return $a[$k] < $b[$k];
            }
        }
        return false;
    }

    /**
     * @param list<array{from:string,to:string,name:string,size:string}> $tunnels
     * @return list<array<string, mixed>>
     */
    private function buildSegments(array $dep, array $dest, array $tunnels, ?string $firstJp, ?string $lastJp): array
    {
        $segments = [];

        // департ-сегмент
        $segments[] = [
            'id' => $dep['id'] ?? ($dep['system'] ?? null),
            'name' => $dep['name'],
            'type' => $dep['kind'],
            'system_id' => $this->systems[$dep['system']]['id'] ?? null,
            'system_code' => $dep['system'],
            'object_id' => $dep['kind'] === 'object' ? $dep['id'] : null,
            'object_code' => $dep['kind'] === 'object' ? $dep['object'] : null,
            'segment_type' => 'F',
            'segment_distance' => $this->departureDistance($dep, $dest, $tunnels, $firstJp),
            'is_departure' => 1,
            'is_destination' => false,
        ];

        // прыжки + полёты
        foreach ($tunnels as $i => $t) {
            $from = $this->jumpPoints[$t['from']];
            $to = $this->jumpPoints[$t['to']];

            $segments[] = [
                'id' => $from['id'],
                'name' => $from['designation'] ?? $t['name'],
                'type' => 'jump',
                'system_id' => $this->systems[$from['system']]['id'] ?? null,
                'system_code' => $from['system'],
                'object_id' => $from['id'],
                'object_code' => $from['code'],
                'segment_type' => 'J',
                'segment_distance' => 0,
                'is_departure' => false,
                'is_destination' => false,
            ];

            // полёт внутри системы к следующему прыжку
            $next = $tunnels[$i + 1] ?? null;
            if ($next !== null) {
                $nextFrom = $this->jumpPoints[$next['from']];
                $segments[] = [
                    'id' => $to['id'],
                    'name' => $to['designation'] ?? '',
                    'type' => 'jump',
                    'system_id' => $this->systems[$to['system']]['id'] ?? null,
                    'system_code' => $to['system'],
                    'object_id' => $to['id'],
                    'object_code' => $to['code'],
                    'segment_type' => 'F',
                    'segment_distance' => round(self::d3($to['xyz'], $nextFrom['xyz']), 10),
                    'is_departure' => false,
                    'is_destination' => false,
                ];
            } elseif ($lastJp !== null) {
                // прибытие в систему назначения
                $arrive = $this->jumpPoints[$lastJp];
                $dist = $dest['kind'] === 'object'
                    ? round(self::d3($arrive['xyz'], $dest['xyz']), 10)
                    : 0;
                $segments[] = [
                    'id' => $arrive['id'],
                    'name' => $arrive['designation'] ?? '',
                    'type' => 'jump',
                    'system_id' => $this->systems[$arrive['system']]['id'] ?? null,
                    'system_code' => $arrive['system'],
                    'object_id' => $arrive['id'],
                    'object_code' => $arrive['code'],
                    'segment_type' => 'F',
                    'segment_distance' => $dist,
                    'is_departure' => false,
                    'is_destination' => false,
                ];
            }
        }

        // финальный сегмент назначения
        $segments[] = [
            'id' => $dest['id'] ?? ($dest['system'] ?? null),
            'name' => $dest['name'],
            'type' => $dest['kind'],
            'system_id' => $this->systems[$dest['system']]['id'] ?? null,
            'system_code' => $dest['system'],
            'object_id' => $dest['kind'] === 'object' ? $dest['id'] : null,
            'object_code' => $dest['kind'] === 'object' ? $dest['object'] : null,
            'segment_type' => 'F',
            'segment_distance' => null,
            'is_departure' => false,
            'is_destination' => 1,
        ];

        return $segments;
    }

    /**
     * Дистанция первого полёта (департ -> первый прыжок / -> цель).
     *
     * @param list<array{from:string,to:string,name:string,size:string}> $tunnels
     */
    private function departureDistance(array $dep, array $dest, array $tunnels, ?string $firstJp): float
    {
        if ($tunnels === []) {
            // внутри одной системы
            return round(self::d3($dep['xyz'], $dest['xyz']), 10);
        }
        if ($firstJp === null) {
            return 0;
        }
        if ($dep['kind'] === 'object') {
            return round(self::d3($dep['xyz'], $this->jumpPoints[$firstJp]['xyz']), 10);
        }
        return 0;
    }

    /**
     * @return array{a:string,b:string,name:string,size:string}|null
     */
    private function tunnelBetween(string $a, string $b): ?array
    {
        foreach ($this->tunnels as $t) {
            if (($t['a'] === $a && $t['b'] === $b) || ($t['a'] === $b && $t['b'] === $a)) {
                return $t;
            }
        }
        return null;
    }

    /**
     * @param array{0:float,1:float,2:float} $a
     * @param array{0:float,1:float,2:float} $b
     */
    public static function d3(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }
}
