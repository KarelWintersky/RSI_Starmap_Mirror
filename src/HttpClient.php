<?php

declare(strict_types=1);

namespace Starmap;

use RuntimeException;

/**
 * Параллельный HTTP-клиент на curl_multi.
 *
 * Один класс на все запросы граббера: JSON (bootup/systems/objects),
 * CDN-ассеты и медиа-файлы (streaming в файлы).
 */
final class HttpClient
{
    public const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    private int $concurrency;

    /** @var array<int, mixed> */
    private array $defaults;

    public function __construct(int $concurrency = 12)
    {
        $this->concurrency = max(1, $concurrency);
        $this->defaults = [
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'Accept-Language: en-US,en;q=0.9',
                'Origin: https://robertsspaceindustries.com',
                'Referer: https://robertsspaceindustries.com/en/starmap',
            ],
        ];
    }

    /**
     * Единичный запрос.
     *
     * @return array{code:int, body:string, err:?string, url:string}
     */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        $ch = $this->handle($method, $url, $body, $headers);
        $bodyStr = curl_exec($ch);
        $err = curl_error($ch) ?: null;
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'code' => $code,
            'body' => is_string($bodyStr) ? $bodyStr : '',
            'err'  => $err,
            'url'  => $url,
        ];
    }

    /**
     * JSON-запрос: тело — JSON, ответ разбирается.
     *
     * @param array<string, mixed>|null $json
     * @return array{code:int, body:string, json:?array, err:?string, url:string}
     */
    public function requestJson(string $method, string $url, ?array $json = null): array
    {
        $headers = ['Content-Type: application/json; charset=utf-8'];
        $body = $json === null ? null : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $r = $this->request($method, $url, $body, $headers);
        $r['json'] = null;
        if ($r['err'] === null && $r['code'] >= 200 && $r['code'] < 300 && $r['body'] !== '') {
            $decoded = json_decode($r['body'], true);
            if (is_array($decoded)) {
                $r['json'] = $decoded;
            }
        }
        return $r;
    }

    /**
     * Выполняет набор JSON-задач параллельно.
     *
     * @param list<array{method?:string, url:string, json?:?array<string,mixed>}> $tasks
     * @return list<array{code:int, body:string, json:?array, err:?string, url:string}>
     */
    public function parallelJson(array $tasks): array
    {
        $results = array_fill(0, count($tasks), null);
        $mh = curl_multi_init();
        $handles = [];
        $queue = [];

        foreach ($tasks as $i => $task) {
            $queue[$i] = $task;
        }

        $active = 0;
        $idx = 0;
        $start = function (array $task): \CurlHandle {
            $method = $task['method'] ?? 'POST';
            $json = $task['json'] ?? null;
            $body = $json === null ? null : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $this->handle($method, $task['url'], $body, ['Content-Type: application/json; charset=utf-8']);
        };

        // заполняем окно
        while ($idx < count($queue) && $active < $this->concurrency) {
            $ch = $start($queue[$idx]);
            $handles[$idx] = $ch;
            curl_multi_add_handle($mh, $ch);
            $active++;
            $idx++;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($status !== CURLM_OK) {
                break;
            }
            // запускаем новые по мере освобождения
            while (($info = curl_multi_info_read($mh)) !== false) {
                $doneCh = $info['handle'];
                $doneIdx = null;
                foreach ($handles as $i => $ch) {
                    if ($ch === $doneCh) {
                        $doneIdx = $i;
                        break;
                    }
                }
                if ($doneIdx !== null) {
                    $task = $queue[$doneIdx];
                    $err = curl_error($doneCh) ?: null;
                    $code = (int) curl_getinfo($doneCh, CURLINFO_RESPONSE_CODE);
                    $raw = curl_multi_getcontent($doneCh);
                    $body = is_string($raw) ? $raw : '';
                    $json = null;
                    if ($err === null && $code >= 200 && $code < 300 && $body !== '') {
                        $decoded = json_decode($body, true);
                        if (is_array($decoded)) {
                            $json = $decoded;
                        }
                    }
                    $results[$doneIdx] = [
                        'code' => $code,
                        'body' => $body,
                        'json' => $json,
                        'err'  => $err,
                        'url'  => $task['url'],
                    ];
                    curl_multi_remove_handle($mh, $doneCh);
                    curl_close($doneCh);
                    unset($handles[$doneIdx]);
                    $active--;

                    if ($idx < count($queue)) {
                        $ch = $start($queue[$idx]);
                        $handles[$idx] = $ch;
                        curl_multi_add_handle($mh, $ch);
                        $active++;
                        $idx++;
                    }
                }
            }
            if ($running > 0) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running > 0 || $idx < count($queue));

        curl_multi_close($mh);

        return $results;
    }

    /**
     * Скачивает файлы параллельно в каталог (структура пути сохраняется).
     * Уже скачанные (непустые) файлы пропускаются.
     *
     * @param list<string> $urls
     * @return array{ok:list<string>, fail:list<array{url:string, err:?string, code:int}>}
     */
    public function downloadFiles(array $urls, string $destRoot, bool $force = false): array
    {
        $ok = [];
        $fail = [];

        $tasks = [];
        foreach ($urls as $url) {
            $path = $this->destForUrl($url, $destRoot);
            if (!$force && is_file($path) && filesize($path) > 0) {
                $ok[] = $path;
                continue;
            }
            $tasks[] = ['url' => $url, 'path' => $path];
        }

        if ($tasks === []) {
            return ['ok' => $ok, 'fail' => $fail];
        }

        $mh = curl_multi_init();
        $handles = [];
        $tmpFiles = [];
        $active = 0;
        $idx = 0;

        $start = function (array $task) use (&$tmpFiles): \CurlHandle {
            $dir = dirname($task['path']);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create dir: $dir");
            }
            $tmp = $task['path'] . '.part';
            $fp = fopen($tmp, 'w+b');
            $tmpFiles[$task['url']] = [$fp, $tmp, $task['path']];
            $ch = $this->handle('GET', $task['url'], null, []);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            return $ch;
        };

        while ($idx < count($tasks) && $active < $this->concurrency) {
            $ch = $start($tasks[$idx]);
            $handles[$idx] = $ch;
            curl_multi_add_handle($mh, $ch);
            $active++;
            $idx++;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($status !== CURLM_OK) {
                break;
            }
            while (($info = curl_multi_info_read($mh)) !== false) {
                $doneCh = $info['handle'];
                $doneIdx = null;
                foreach ($handles as $i => $ch) {
                    if ($ch === $doneCh) {
                        $doneIdx = $i;
                        break;
                    }
                }
                if ($doneIdx !== null) {
                    $task = $tasks[$doneIdx];
                    $err = curl_error($doneCh) ?: null;
                    $code = (int) curl_getinfo($doneCh, CURLINFO_RESPONSE_CODE);
                    [$fp, $tmp, $path] = $tmpFiles[$task['url']];
                    fclose($fp);
                    curl_multi_remove_handle($mh, $doneCh);
                    curl_close($doneCh);
                    unset($handles[$doneIdx], $tmpFiles[$task['url']]);
                    $active--;

                    if ($err === null && $code >= 200 && $code < 300) {
                        rename($tmp, $path);
                        $ok[] = $path;
                        $size = is_file($path) ? (int) filesize($path) : 0;
                        Util::out(sprintf('  ✓ %s (%s)', $path, Util::bytes($size)));
                    } else {
                        @unlink($tmp);
                        $fail[] = ['url' => $task['url'], 'err' => $err, 'code' => $code];
                        Util::err(sprintf('  ✗ %s (%s)', $task['url'], $err ?? 'HTTP ' . $code));
                    }

                    if ($idx < count($tasks)) {
                        $ch = $start($tasks[$idx]);
                        $handles[$idx] = $ch;
                        curl_multi_add_handle($mh, $ch);
                        $active++;
                        $idx++;
                    }
                }
            }
            if ($running > 0) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running > 0 || $idx < count($tasks));

        curl_multi_close($mh);

        return ['ok' => $ok, 'fail' => $fail];
    }

    /**
     * Превращает URL вида https://host/path в локальный путь $destRoot/path.
     */
    public function destForUrl(string $url, string $destRoot): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            $path = 'index.html';
        }
        return rtrim($destRoot, '/') . '/' . $path;
    }

    /**
     * @return \CurlHandle
     */
    private function handle(string $method, string $url, ?string $body, array $headers): \CurlHandle
    {
        $ch = curl_init($url);
        foreach ($this->defaults as $opt => $value) {
            curl_setopt($ch, $opt, $value);
        }
        $method = strtoupper($method);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($headers !== []) {
            $all = array_merge($this->defaults[CURLOPT_HTTPHEADER], $headers);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $all);
        }
        return $ch;
    }
}
