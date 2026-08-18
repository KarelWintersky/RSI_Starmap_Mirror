<?php
/**
 * import_json_to_db.php — Импорт JSON-файлов data/ в MariaDB.
 *
 * Источники:
 *   data/bootup.json                    → affiliations, species, star_systems, tunnels
 *   data/star-systems/{CODE}.json       → star_systems (shader_data), celestial_objects
 *   data/celestial-objects/{CODE}.json  → (доп. поля, children — для статистики)
 *
 * Запуск: php import_json_to_db.php
 */

declare(strict_types=1);

$DATA_DIR = __DIR__ . '/data';
$DB_HOST  = 'localhost';
$DB_NAME  = 'rsi_starmap';
$DB_USER  = 'rsi_starmap';
$DB_PASS  = 'password';

// ─── Подключение ────────────────────────────────────────────────

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

echo "Connected to $DB_NAME@$DB_HOST\n";

// ─── Очистка таблиц ─────────────────────────────────────────────

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach (['object_affiliations','system_affiliations','tunnels','celestial_objects',
          'star_systems','engine_config','affiliations','species'] as $t) {
    $pdo->exec("TRUNCATE TABLE $t");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "Tables truncated.\n";

// ─── Загрузка bootup.json ───────────────────────────────────────

$bootupFile = "$DATA_DIR/bootup.json";
if (!is_file($bootupFile)) {
    die("ERROR: $bootupFile not found. Run: php grab.php fetch\n");
}

$bootup = json_decode(file_get_contents($bootupFile), true)['data'];
echo "Bootup loaded: " . $bootup['systems']['rowcount'] . " systems, "
    . $bootup['tunnels']['rowcount'] . " tunnels\n";

// ─── Вставка affiliations ───────────────────────────────────────

$affilStmt = $pdo->prepare("INSERT IGNORE INTO affiliations (id, code, name, color) VALUES (?, ?, ?, ?)");
$affils = $bootup['affiliations']['resultset'] ?? [];
foreach ($affils as $a) {
    $affilStmt->execute([$a['id'], $a['code'], $a['name'], $a['color']]);
}
echo "Affiliations: " . count($affils) . "\n";

// ─── Вставка species ────────────────────────────────────────────

$specStmt = $pdo->prepare("INSERT IGNORE INTO species (id, code, name) VALUES (?, ?, ?)");
$specs = $bootup['species']['resultset'] ?? [];
foreach ($specs as $s) {
    $specStmt->execute([$s['id'], $s['code'], $s['name']]);
}
echo "Species: " . count($specs) . "\n";

// ─── Вставка star_systems ───────────────────────────────────────

$sysStmt = $pdo->prepare("
    INSERT IGNORE INTO star_systems
    (id, code, name, description, type, status,
     position_x, position_y, position_z,
     habitable_zone_inner, habitable_zone_outer, frost_line, oort_radius,
     aggregated_size, aggregated_population, aggregated_economy, aggregated_danger,
     shader_data, thumbnail_slug, thumbnail_source)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

$sysAffilStmt = $pdo->prepare("INSERT IGNORE INTO system_affiliations (system_id, affiliation_id, membership_id) VALUES (?,?,?)");

$systems = $bootup['systems']['resultset'] ?? [];
$systemIds = [];

foreach ($systems as $s) {
    $thumb = $s['thumbnail'] ?? null;
    $thumbSlug  = $thumb['slug'] ?? null;
    $thumbSource = $thumb['source'] ?? null;

    $sysStmt->execute([
        $s['id'], $s['code'], $s['name'],
        $s['description'] ?? null,
        $s['type'], $s['status'],
        $s['position_x'], $s['position_y'], $s['position_z'],
        null, null, null, 39.5,
        (float)($s['aggregated_size'] ?? 0),
        (float)($s['aggregated_population'] ?? 0),
        (float)($s['aggregated_economy'] ?? 0),
        (float)($s['aggregated_danger'] ?? 0),
        null,
        $thumbSlug, $thumbSource,
    ]);
    $systemIds[] = $s['id'];

    // affiliations
    foreach (($s['affiliation'] ?? []) as $af) {
        $memId = $af['membership.id'] ?? null;
        $sysAffilStmt->execute([$s['id'], $af['id'], $memId]);
    }
}
echo "Star systems: " . count($systems) . "\n";

// ─── Вставка celestial_objects из star-systems/ ──────────────────

$coStmt = $pdo->prepare("
    INSERT IGNORE INTO celestial_objects
    (id, code, name, designation, description,
     type, appearance, subtype_id, subtype_name,
     star_system_id, parent_id,
     distance, latitude, longitude,
     size, age, axial_tilt, orbit_period,
     habitable, fairchanceact,
     sensor_danger, sensor_economy, sensor_population,
     show_label, show_orbitlines,
     shader_data, texture_slug, texture_source,
     model_slug, model_source, time_modified)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

$sysDir = "$DATA_DIR/star-systems";
$allObjects = []; // id => obj (для tunnel mapping)
$totalObjects = 0;
$systemsProcessed = 0;

foreach (scandir($sysDir) as $file) {
    if (!str_ends_with($file, '.json')) continue;

    $raw = json_decode(file_get_contents("$sysDir/$file"), true);
    $resultset = $raw['data']['resultset'] ?? [];
    if (!$resultset) continue;

    $sys = $resultset[0];
    $sysId = $sys['id'];
    $shaderData = $sys['shader_data'] ? json_encode($sys['shader_data'], JSON_UNESCAPED_SLASHES) : null;

    // Update system with shader_data and zones
    $upd = $pdo->prepare("
        UPDATE star_systems SET
            habitable_zone_inner = ?,
            habitable_zone_outer = ?,
            frost_line = ?,
            oort_radius = ?,
            shader_data = ?
        WHERE id = ?
    ");
    $upd->execute([
        $sys['habitable_zone_inner'] ?? null,
        $sys['habitable_zone_outer'] ?? null,
        $sys['frost_line'] ?? null,
        $sys['oort_radius'] ?? 39.5,
        $shaderData,
        $sysId,
    ]);

    $systemsProcessed++;

    foreach (($sys['celestial_objects'] ?? []) as $obj) {
        $shader = $obj['shader_data'] ? json_encode($obj['shader_data'], JSON_UNESCAPED_SLASHES) : null;

        $tex = $obj['texture'] ?? null;
        $model = $obj['model'] ?? null;

        $habitable = $obj['habitable'] === true ? 1 : ($obj['habitable'] === false ? 0 : null);
        $fairchance = $obj['fairchanceact'] === true ? 1 : ($obj['fairchanceact'] === false ? 0 : null);
        $showLabel = $obj['show_label'] === true ? 1 : ($obj['show_label'] === false ? 0 : null);
        $showOrbits = $obj['show_orbitlines'] === true ? 1 : ($obj['show_orbitlines'] === false ? 0 : null);

        $subtype = $obj['subtype'] ?? null;

        $coStmt->execute([
            $obj['id'],
            $obj['code'],
            $obj['name'] ?? null,
            $obj['designation'] ?? null,
            $obj['description'] ?? null,
            $obj['type'],
            $obj['appearance'] ?? null,
            $subtype['id'] ?? $obj['subtype_id'] ?? null,
            $subtype['name'] ?? null,
            $sysId,
            $obj['parent_id'] ?? null,
            $obj['distance'] ?? 0,
            $obj['latitude'] ?? 0,
            $obj['longitude'] ?? 0,
            $obj['size'] ?? 0,
            $obj['age'] ?? null,
            $obj['axial_tilt'] ?? null,
            $obj['orbit_period'] ?? null,
            $habitable,
            $fairchance,
            (string)($obj['sensor_danger'] ?? '0'),
            (string)($obj['sensor_economy'] ?? '0'),
            (string)($obj['sensor_population'] ?? '0'),
            $showLabel,
            $showOrbits,
            $shader,
            $tex['slug'] ?? null,
            $tex['source'] ?? null,
            $model['slug'] ?? null,
            $model['source'] ?? null,
            $obj['time_modified'] ?? null,
        ]);

        $allObjects[$obj['id']] = $obj;
        $totalObjects++;

        // affiliations
        foreach (($obj['affiliation'] ?? []) as $af) {
            $memId = $af['membership.id'] ?? null;
            $pdo->prepare("INSERT IGNORE INTO object_affiliations (object_id, affiliation_id, membership_id) VALUES (?,?,?)")
                 ->execute([$obj['id'], $af['id'], $memId]);
        }
    }
}

echo "Celestial objects: $totalObjects (from $systemsProcessed systems)\n";

// ─── Вставка tunnels ────────────────────────────────────────────

$tunStmt = $pdo->prepare("INSERT IGNORE INTO tunnels (id, name, direction, size, entry_id, exit_id) VALUES (?,?,?,?,?,?)");
$tunnels = $bootup['tunnels']['resultset'] ?? [];
$tunnelCount = 0;
$tunnelSkip = 0;

foreach ($tunnels as $t) {
    $entryId = $t['entry']['id'] ?? null;
    $exitId  = $t['exit']['id'] ?? null;

    if (!$entryId || !$exitId) { $tunnelSkip++; continue; }

    // Проверяем что оба JP существуют в БД
    $chk = $pdo->prepare("SELECT COUNT(*) FROM celestial_objects WHERE id IN (?,?)");
    $chk->execute([$entryId, $exitId]);
    if ($chk->fetchColumn() < 2) {
        // JP не найдены — пропускаем туннель (система могла быть не загружена)
        $tunnelSkip++;
        continue;
    }

    $tunStmt->execute([
        $t['id'],
        $t['name'] ?? null,
        $t['direction'] ?? 'B',
        $t['size'] ?? 'M',
        $entryId,
        $exitId,
    ]);
    $tunnelCount++;
}
echo "Tunnels: $tunnelCount (skipped $tunnelSkip)\n";

// ─── Engine config ──────────────────────────────────────────────

$cfgStmt = $pdo->prepare("INSERT IGNORE INTO engine_config (id, config) VALUES (1, ?)");
$cfgStmt->execute([json_encode($bootup['config'] ?? [], JSON_UNESCAPED_SLASHES)]);
echo "Engine config: saved\n";

// ─── Итого ──────────────────────────────────────────────────────

echo "\n=== ИТОГО ===\n";
foreach (['affiliations','species','star_systems','celestial_objects',
          'tunnels','object_affiliations','system_affiliations'] as $t) {
    $cnt = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "  $t: $cnt\n";
}

echo "\nDone.\n";
