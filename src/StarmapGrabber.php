<?php

declare(strict_types=1);

namespace Starmap;

use RuntimeException;

/**
 * Граббер данных ARK Starmap.
 *
 * Последовательность шагов:
 *   fetch  -> bootup + все системы + все небесные объекты (JSON в data/)
 *   index  -> собрать все media-URL из data/ (в data/urlindex.json)
 *   assets -> зеркалировать CDN-статика (models, sounds, css, js, fonts, ui-images)
 *   media  -> скачать media-файлы (текстуры/изображения) в web/media/
 *   build  -> собрать web/: index.html, патченный bundle, локальный API, поисковый индекс
 *   all    -> всё перечисленное по порядку
 */
final class StarmapGrabber
{
    private HttpClient $http;

    public function __construct(private int $concurrency = 12)
    {
        $this->http = new HttpClient($this->concurrency);
    }

    /** @var list<string> */
    private const MODELS = [
        'AsteroidField.dae',
        'AsteroidsEND.dae',
        'AsteroidsFAR.dae',
        'AsteroidsFRONT.dae',
        'AsteroidsMIDDLE.dae',
        'Blackhole.dae',
        'DustGoTrhu.dae',
        'HoverGizmo.dae',
        'JumpGoTrhu.dae',
        'JumpHead.dae',
        'JumpTail.dae',
        'LandingZone.dae',
        'Middle_Nebula.dae',
        'Planet_Blue.dae',
        'Planet_Brown.dae',
        'Planet_Gas.dae',
        'Planet_Green.dae',
        'PlanetRing.dae',
        'SpaceCube_Back.dae',
        'SpaceCube_Nebulas.dae',
        'SpaceStation.dae',
        'SunGodRays.dae',
    ];

    /** @var list<string> */
    private const SOUNDS = [
        'CAM_Dolly_LOOP.wav',
        'CAM_FastMove_LOOP.wav',
        'SE_ControlDisk_Label.wav',
        'SE_EnterJumpPointCompleted.wav',
        'SE_EnterJumpPointStart.wav',
        'SE_EnterStarSystemCompleted.wav',
        'SE_EnterStarSystemStart.wav',
        'SE_HideHeatmap.wav',
        'SE_HideLongRangeScanner.wav',
        'SE_HoverBodyIn.wav',
        'SE_HoverBodyOut.wav',
        'SE_HoverJumpPointIn.wav',
        'SE_HoverJumpPointOut.wav',
        'SE_HoverSystemIn.wav',
        'SE_HoverSystemOut.wav',
        'SE_LeaveStarSystemToGalaxyCompleted.wav',
        'SE_LeaveStarSystemToGalaxyStart.wav',
        'SE_LoadingBackgroundExtra_LOOP.wav',
        'SE_LoadingBackground_LOOP.wav',
        'SE_LoadingEnterButtonAppear.wav',
        'SE_LoadingEnterButtonHover.wav',
        'SE_LoadingLogo1.wav',
        'SE_LoadingLogo2.wav',
        'SE_SelectBelt_LOOP.wav',
        'SE_SelectJumpPoint_LOOP.wav',
        'SE_SelectMoon_LOOP.wav',
        'SE_SelectNothing.wav',
        'SE_SelectPlanet_LOOP.wav',
        'SE_SelectSpaceStation_LOOP.wav',
        'SE_SelectStar_LOOP.wav',
        'SE_ShowHeatmap.wav',
        'SE_ShowLongRangeScanner.wav',
        'SE_ShowOrbitals.wav',
        'SE_StartGalaxyAmbiance_LOOP.wav',
        'SE_StartStarSystemAmbiance_LOOP.wav',
        'SE_UpdateHeatmap.wav',
        'UI_BB_RoutingRouteFound.wav',
        'UI_BB_RoutingSearchForRoute.wav',
        'UI_BB_ToggleButtonActivate.wav',
        'UI_BB_ToggleButtonDeactivate.wav',
        'UI_DiscClick.wav',
        'UI_DiscClose.wav',
        'UI_DiscOpen.wav',
        'UI_DiscToggleActivate.wav',
        'UI_DiscToggleDeactivate.wav',
    ];

    /** @var list<string> */
    private const MUSIC_FORMATS = ['StarMapOdyssaeRemix1.ogg', 'StarMapOdyssaeRemix1.mp3', 'StarMapOdyssaeRemix1.wav'];

    // ------------------------------------------------------------------ data

    /**
     * Шаг 1. bootup.
     *
     * @return array|null данные bootup (data/...)
     */
    public function fetchBootup(bool $force = false): ?array
    {
        $path = Util::dataPath('bootup.json');
        if (!$force && is_file($path)) {
            Util::out("bootup: кэш есть, пропуск (--force для повтора)");
            $cached = Util::readJson($path);
            return is_array($cached) ? ($cached['data'] ?? null) : null;
        }
        Util::out('bootup: запрос /bootup ...');
        $r = $this->http->requestJson('POST', Util::API_BASE . '/bootup');
        $data = Util::apiData($r['json'], 'bootup');
        if ($data === null) {
            throw new RuntimeException('bootup: не удалось получить данные (' . ($r['err'] ?? 'HTTP ' . $r['code']) . ')');
        }
        Util::saveJson($path, $r['json']);
        Util::out(sprintf(
            'bootup: готово. систем=%d, туннелей=%d, фракций=%d, видов=%d',
            (int) ($data['systems']['totalrows'] ?? 0),
            (int) ($data['tunnels']['totalrows'] ?? 0),
            count($data['affiliations']['resultset'] ?? []),
            count($data['species']['resultset'] ?? [])
        ));
        return $data;
    }

    /**
     * Шаг 2. Детали всех систем.
     */
    public function fetchSystems(bool $force = false): void
    {
        $bootup = $this->fetchBootup($force);
        if ($bootup === null) {
            return;
        }
        $codes = array_column($bootup['systems']['resultset'] ?? [], 'code');
        Util::out('systems: ' . count($codes) . ' систем');

        $todo = [];
        foreach ($codes as $code) {
            $path = Util::dataPath('star-systems', $code . '.json');
            if (!$force && is_file($path)) {
                continue;
            }
            $todo[] = ['url' => Util::API_BASE . '/star-systems/' . rawurlencode($code)];
        }
        if ($todo === []) {
            Util::out('systems: все детали уже есть, пропуск');
            return;
        }
        $this->parallelSaveJson($todo, fn (array $r): ?string => (
            ($r['json'] ?? null) !== null && (int) ($r['json']['success'] ?? 0) === 1
                ? Util::dataPath('star-systems', $this->codeFromUrl($r['url']) . '.json')
                : null
        ), 'systems');
    }

    /**
     * Шаг 3. Детали всех небесных объектов (877 топ-уровневых; дерево вложено).
     */
    public function fetchObjects(bool $force = false): void
    {
        $codes = $this->collectObjectCodes();
        Util::out('objects: ' . count($codes) . ' объектов верхнего уровня');

        $todo = [];
        foreach ($codes as $code) {
            $path = Util::dataPath('celestial-objects', $code . '.json');
            if (!$force && is_file($path)) {
                continue;
            }
            $todo[] = ['url' => Util::API_BASE . '/celestial-objects/' . rawurlencode($code)];
        }
        if ($todo === []) {
            Util::out('objects: все детали уже есть, пропуск');
            return;
        }
        $this->parallelSaveJson($todo, fn (array $r): ?string => (
            ($r['json'] ?? null) !== null && (int) ($r['json']['success'] ?? 0) === 1
                ? Util::dataPath('celestial-objects', $this->codeFromUrl($r['url']) . '.json')
                : null
        ), 'objects');
    }

    /**
     * Шаг 4. Индекс всех media-URL из собранных JSON.
     *
     * @return array<string, list<string>>
     */
    public function indexUrls(): array
    {
        $byCat = Util::collectMediaUrls(Util::dataPath());
        Util::saveJson(Util::dataPath('urlindex.json'), $byCat);
        foreach ($byCat as $cat => $urls) {
            Util::out(sprintf('index: %-12s %4d', $cat, count($urls)));
        }
        return $byCat;
    }

    // ----------------------------------------------------------------- assets

    /**
     * Шаг 5. Зеркалирование статики CDN в web/static/starmap/ и сопутствующего.
     *
     * @return list<string> список сохранённых путей
     */
    public function mirrorAssets(bool $force = false): array
    {
        $urls = [];

        // страница приложения (сырой вид сохраняем в data/ для офлайн-сборки)
        $pagePath = Util::dataPath('page_starmap.html');
        if ($force || !is_file($pagePath)) {
            Util::out('assets: загрузка страницы /en/starmap');
            $r = $this->http->request('GET', Util::SITE_BASE . '/en/starmap');
            if ($r['err'] === null && $r['code'] === 200) {
                file_put_contents($pagePath, $r['body']);
            } else {
                Util::err('assets: страницу получить не удалось (' . ($r['err'] ?? 'HTTP ' . $r['code']) . ')');
            }
        }

        // основные файлы приложения
        $urls[] = Util::CDN_STARMAP . '/starmap.bundle.js';
        $urls[] = Util::CDN_STARMAP . '/main.css';
        $urls[] = Util::CDN_STARMAP . '/fontdetect.min.js';

        // шрифты страницы
        $urls[] = Util::CDN_STATIC . '/fonts/electrolize/Electrolize-Regular.woff2';
        $urls[] = Util::CDN_STATIC . '/fonts/orbitron/Orbitron-Regular.woff2';
        $urls[] = Util::CDN_STATIC . '/fonts/orbitron/Orbitron-Medium.woff2';
        $urls[] = Util::CDN_STATIC . '/fonts/orbitron/Orbitron-Bold.woff2';

        // 3D-модели
        foreach (self::MODELS as $m) {
            $urls[] = Util::CDN_STARMAP . '/models/' . $m;
        }

        // звуки
        foreach (self::SOUNDS as $s) {
            $urls[] = Util::CDN_STARMAP . '/sounds/' . $s;
        }
        foreach (self::MUSIC_FORMATS as $s) {
            $urls[] = Util::CDN_STARMAP . '/sounds/' . $s;
        }

        // подстраховка: звуки, на которые ссылается уже скачанный bundle
        $bundlePath = Util::webPath('static/starmap/starmap.bundle.js');
        if (is_file($bundlePath)) {
            $bundle = (string) file_get_contents($bundlePath);
            if (preg_match_all('#(?<![A-Za-z0-9_])([A-Za-z0-9_]+\.(?:wav|ogg|mp3))#', $bundle, $m)) {
                foreach (array_unique($m[1]) as $name) {
                    $urls[] = Util::CDN_STARMAP . '/sounds/' . $name;
                }
            }
        }

        // текстуры, встроенные в DAE-модели (../sourceimages/<file>)
        foreach (glob(Util::webPath('static/starmap/models/*.dae')) as $dae) {
            $daeContent = (string) file_get_contents($dae);
            if (preg_match_all('#\.\./sourceimages/([^"\'<]+)#', $daeContent, $m)) {
                foreach (array_unique($m[1]) as $name) {
                    $urls[] = Util::CDN_STARMAP . '/sourceimages/' . $name;
                }
            }
        }

        // sourceimages + фракции
        $urls[] = Util::CDN_STARMAP . '/sourceimages/Chevrons32x32.png';
        $bootup = Util::readJson(Util::dataPath('bootup.json'));
        $affCodes = array_column($bootup['data']['affiliations']['resultset'] ?? [], 'code');
        foreach (array_unique($affCodes) as $code) {
            $urls[] = Util::CDN_STARMAP . '/sourceimages/factions/' . $code . '.png';
        }

        // ui-images и fonts/icons — вытаскиваем из уже скачанного main.css
        $cssPath = Util::webPath('static/starmap/main.css');
        if (!is_file($cssPath)) {
            $this->downloadOne(Util::CDN_STARMAP . '/main.css', $cssPath);
        }
        if (is_file($cssPath)) {
            $css = (string) file_get_contents($cssPath);
            preg_match_all('#url\((["\']?)(https?[^)"\']+)\1\)#', $css, $m);
            foreach ($m[2] as $u) {
                if (str_starts_with($u, Util::CDN_STARMAP)) {
                    $urls[] = $u;
                }
            }
        }

        $urls = array_values(array_unique($urls));
        Util::out('assets: файлов к скачиванию: ' . count($urls));

        $destRoot = Util::webPath();
        $ok = [];
        $fail = [];
        $chunks = array_chunk($urls, $this->concurrency * 2);
        foreach ($chunks as $chunk) {
            $res = $this->http->downloadFiles($chunk, $destRoot);
            $ok = array_merge($ok, $res['ok']);
            $fail = array_merge($fail, $res['fail']);
            Util::out(sprintf('assets: %d готово, %d ошибок', count($res['ok']), count($res['fail'])));
        }

        // disc.svg
        $disc = Util::webPath('rsi/static/svg/disc.svg');
        if ($force || !is_file($disc)) {
            $this->downloadOne(Util::SITE_BASE . '/rsi/static/svg/disc.svg', $disc);
        }

        foreach ($fail as $f) {
            Util::err('assets: не удалось: ' . $f['url'] . ' (' . ($f['err'] ?? 'HTTP ' . $f['code']) . ')');
        }
        return $ok;
    }

    /**
     * Шаг 6. Скачивание media-файлов (текстуры/картинки) в web/media/.
     *
     * @param list<string> $cats категории из urlindex; [] = все
     */
    public function downloadMedia(array $cats = [], bool $force = false): array
    {
        $index = Util::readJson(Util::dataPath('urlindex.json'));
        if (!is_array($index)) {
            throw new RuntimeException('Нет data/urlindex.json — сначала запустите "index" (или "all")');
        }
        $all = $cats === [] ? array_keys($index) : $cats;
        $urls = [];
        foreach ($all as $cat) {
            foreach ($index[$cat] ?? [] as $u) {
                $urls[] = $u;
            }
        }
        $urls = array_values(array_unique($urls));

        $destRoot = Util::webPath();
        $todo = [];
        foreach ($urls as $url) {
            $path = $this->http->destForUrl($url, $destRoot);
            if (!$force && is_file($path) && filesize($path) > 0) {
                continue;
            }
            $todo[] = $url;
        }
        Util::out('media: всего ' . count($urls) . ', к скачиванию ' . count($todo));

        $ok = 0;
        $fail = [];
        foreach (array_chunk($todo, $this->concurrency * 2) as $chunk) {
            $res = $this->http->downloadFiles($chunk, $destRoot);
            $ok += count($res['ok']);
            foreach ($res['fail'] as $f) {
                $fail[] = $f;
            }
            Util::out(sprintf('media: %d скачано, ошибок %d', $ok, count($fail)));
        }
        foreach ($fail as $f) {
            Util::err('media: не удалось: ' . $f['url'] . ' (' . ($f['err'] ?? 'HTTP ' . $f['code']) . ')');
        }
        return ['ok' => $ok, 'fail' => $fail];
    }

    // ------------------------------------------------------------------- build

    /**
     * Шаг 7. Сборка web/: index.html, патченный bundle, локальный API, поиск.
     */
    public function build(): void
    {
        $web = Util::webPath();
        if (!is_dir($web) && !mkdir($web, 0777, true)) {
            throw new RuntimeException("Не могу создать web/: $web");
        }

        $this->buildIndexHtml();
        $this->buildBundle();
        $this->rewriteCss(
            Util::webPath('static/starmap/main.css'),
            'https://cdn.robertsspaceindustries.com/static/starmap/',
            '/static/starmap/'
        );
        $this->buildApi();
        $this->buildFindIndex();

        Util::out('build: web/ собран');
    }

    private function buildIndexHtml(): void
    {
        $raw = Util::dataPath('page_starmap.html');
        $page = is_file($raw) ? (string) file_get_contents($raw) : null;
        if ($page === null || $page === '') {
            $page = $this->defaultPageHtml();
        }

        // убираем платформенную навигацию и внешние скрипты, которые в офлайне не нужны
        $page = preg_replace(
            '#<script[^>]*\bsrc="(?:https?:)?//(?:consent\.cookiebot\.com|static\.robertsspaceindustries\.com|robertsspaceindustries\.com/tag-manager|cdn\.robertsspaceindustries\.com/static/platform)[^"]*"[^>]*>\s*</script>#s',
            '',
            $page
        ) ?? $page;
        $page = preg_replace('#<div\s+data-rsi-component="[^"]*"[^>]*>\s*</div>#s', '', $page) ?? $page;
        $page = preg_replace('#<script[^>]*id="(?:TagManager|ThirdPartyAnalyticsSetup|Cookiebot|CookieConsent|ThirdPartyAnalyticsSetup)"[^>]*>.*?</script>#s', '', $page) ?? $page;

        // локальные пути вместо CDN
        $page = str_replace(
            'https://cdn.robertsspaceindustries.com/static/starmap/',
            '/static/starmap/',
            $page
        );
        $page = str_replace(
            'href="https://robertsspaceindustries.com/rsi/assets/fonts.css?family=Electrolize|Orbitron:400,500,700"',
            'href="/assets/fonts.css"',
            $page
        );

        $out = Util::webPath('index.html');
        file_put_contents($out, $page);
        Util::out('build: web/index.html');
    }

    private function buildBundle(): void
    {
        $src = Util::webPath('static/starmap/starmap.bundle.js');
        $content = is_file($src) ? (string) file_get_contents($src) : '';
        if ($content === '') {
            Util::err('build: нет starmap.bundle.js (запустите "assets")');
            return;
        }
        $content = str_replace(
            'https://cdn.robertsspaceindustries.com/static/starmap',
            '/static/starmap',
            $content
        );
        file_put_contents($src, $content);
        Util::out('build: starmap.bundle.js (resourcePath -> /static/starmap)');
    }

    private function rewriteCss(string $path, string $from, string $to): void
    {
        if (!is_file($path)) {
            return;
        }
        $css = (string) file_get_contents($path);
        if (str_contains($css, $from)) {
            file_put_contents($path, str_replace($from, $to, $css));
            Util::out('build: css URL переписаны (' . basename($path) . ')');
        }

        // fonts.css для страницы
        $fontsCss = "/* offline starmap fonts */\n"
            . "@font-face{font-family:Electrolize;font-style:normal;font-weight:400;font-display:swap;src:url('/static/fonts/electrolize/Electrolize-Regular.woff2') format('woff2')}\n"
            . "@font-face{font-family:Orbitron;font-style:normal;font-weight:400;font-display:swap;src:url('/static/fonts/orbitron/Orbitron-Regular.woff2') format('woff2')}\n"
            . "@font-face{font-family:Orbitron;font-style:normal;font-weight:500;font-display:swap;src:url('/static/fonts/orbitron/Orbitron-Medium.woff2') format('woff2')}\n"
            . "@font-face{font-family:Orbitron;font-style:normal;font-weight:700;font-display:swap;src:url('/static/fonts/orbitron/Orbitron-Bold.woff2') format('woff2')}\n";
        $assetsDir = Util::webPath('assets');
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0777, true);
        }
        file_put_contents($assetsDir . '/fonts.css', $fontsCss);
    }

    private function buildApi(): void
    {
        $destApi = Util::webPath('api/starmap');

        // bootup
        $src = Util::dataPath('bootup.json');
        if (is_file($src)) {
            $this->writeRewritten($src, $destApi . '/bootup.json');
        }

        // системы
        $this->copyDirRewritten(Util::dataPath('star-systems'), $destApi . '/star-systems');
        // объекты
        $this->copyDirRewritten(Util::dataPath('celestial-objects'), $destApi . '/celestial-objects');

        Util::out('build: api/starmap/*');
    }

    private function buildFindIndex(): void
    {
        $bootup = Util::readJson(Util::dataPath('bootup.json'));
        $systems = [];
        $sysById = [];
        if (is_array($bootup)) {
            foreach ($bootup['data']['systems']['resultset'] ?? [] as $s) {
                $systems[] = [
                    'id' => (int) $s['id'],
                    'code' => $s['code'],
                    'name' => $s['name'],
                    'type' => $s['type'],
                ];
                $sysById[(int) $s['id']] = $systems[count($systems) - 1];
            }
        }
        $sysByCode = [];
        foreach ($systems as $s) {
            $sysByCode[$s['code']] = $s;
        }

        $objects = [];
        foreach (Util::globRecursive(Util::dataPath('celestial-objects'), ['*.json']) as $file) {
            $d = Util::readJson($file);
            $obj = $d['data']['resultset'][0] ?? null;
            if (!is_array($obj)) {
                continue;
            }
            $sysCode = strtok((string) $obj['code'], '.');
            $sys = $sysByCode[$sysCode] ?? null;
            $objects[] = [
                'id' => (int) $obj['id'],
                'code' => $obj['code'],
                'designation' => $obj['designation'] ?? null,
                'name' => $obj['name'] ?? null,
                'type' => $obj['type'] ?? null,
                'star_system_id' => $sys ? $sys['id'] : null,
                'star_system' => $sys ? ['id' => $sys['id'], 'code' => $sys['code'], 'name' => $sys['name'], 'type' => $sys['type']] : null,
            ];
        }

        $index = [
            'systems' => $systems,
            'objects' => $objects,
            'sysById' => array_values($sysById),
        ];
        Util::saveJson(Util::webPath('api/starmap/_index.json'), $index);
        Util::out(sprintf('build: поисковый индекс (систем: %d, объектов: %d)', count($systems), count($objects)));
    }

    // ------------------------------------------------------------------ utils

    private function copyDirRewritten(string $srcDir, string $destDir): void
    {
        if (!is_dir($srcDir)) {
            return;
        }
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        foreach (glob($srcDir . '/*.json') ?: [] as $file) {
            $this->writeRewritten($file, $destDir . '/' . basename($file));
        }
    }

    private function writeRewritten(string $srcFile, string $destFile): void
    {
        $text = (string) file_get_contents($srcFile);
        $text = Util::rewriteUrls($text);
        $dir = dirname($destFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($destFile, $text);
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function collectObjectCodes(): array
    {
        $codes = [];
        foreach (Util::globRecursive(Util::dataPath('star-systems'), ['*.json']) as $file) {
            $d = Util::readJson($file);
            foreach ($d['data']['resultset'][0]['celestial_objects'] ?? [] as $co) {
                if (isset($co['code'])) {
                    $codes[$co['code']] = true;
                }
            }
        }
        return array_keys($codes);
    }

    /**
     * Запускает набор POST-запросов и сохраняет результаты с ретраями на 429/5xx.
     *
     * @param list<array{url:string, json?:?array}> $todo
     * @param callable(array):?string $pathFor  путь сохранения по результату
     */
    private function parallelSaveJson(array $todo, callable $pathFor, string $label): void
    {
        $batchSize = $this->concurrency * 2;
        $saved = 0;
        $failures = [];

        foreach (array_chunk($todo, $batchSize) as $chunk) {
            $res = $this->http->parallelJson($chunk);
            foreach ($res as $i => $r) {
                $path = $pathFor($r);
                if ($path === null) {
                    $failures[] = $chunk[$i]['url'];
                    continue;
                }
                Util::saveJson($path, $r['json']);
                $saved++;
            }
            Util::out(sprintf('%s: %d сохранено', $label, $saved));
            usleep(150_000);
        }

        // ретраи: последовательно, с паузами
        $attempt = 0;
        while ($failures !== [] && $attempt < 4) {
            $attempt++;
            Util::out(sprintf('%s: ретрай %d/%d (осталось %d)', $label, $attempt, 4, count($failures)));
            $next = [];
            foreach ($failures as $url) {
                usleep(300_000 + random_int(0, 400_000));
                $r = $this->http->requestJson('POST', $url);
                $path = $pathFor($r);
                if ($path !== null) {
                    Util::saveJson($path, $r['json']);
                    $saved++;
                } else {
                    $next[] = $url;
                }
            }
            $failures = $next;
        }
        foreach ($failures as $url) {
            Util::err(sprintf('%s: не удалось после ретраев: %s', $label, $url));
        }
    }

    private function codeFromUrl(string $url): string
    {
        return rawurldecode((string) basename(parse_url($url, PHP_URL_PATH)));
    }

    private function downloadOne(string $url, string $dest): void
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }
        $fp = fopen($dest . '.part', 'w+b');
        if ($fp === false) {
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERAGENT => HttpClient::UA,
            CURLOPT_ENCODING => 'gzip, deflate, br',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FILE => $fp,
        ]);
        curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);
        if ($err === null && $err === '' && $code >= 200 && $code < 300) {
            rename($dest . '.part', $dest);
        } else {
            @unlink($dest . '.part');
            Util::err(sprintf('downloadOne: %s -> %s (%s, HTTP %d)', $url, $dest, $err, $code));
        }
    }

    private function defaultPageHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ARK Starmap (offline)</title>
<meta name="viewport" content="width=device-width">
<link rel="stylesheet" href="/assets/fonts.css">
<link rel="stylesheet" href="/static/starmap/main.css">
</head>
<body>
<div id="starmap-application"></div>
<script type="text/javascript" src="/static/starmap/fontdetect.min.js"></script>
<script type="text/javascript" src="/static/starmap/starmap.bundle.js"></script>
</body>
</html>
HTML;
    }
}
