<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/firestore_rest.php';

$credentials = getenv('FIREBASE_CREDENTIALS') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS');
if (!$credentials || !is_file($credentials)) {
    fwrite(STDERR, "Set FIREBASE_CREDENTIALS to the Firebase service-account JSON file path.\n");
    exit(1);
}

$host = getenv('UAEP_DB_HOST') ?: '127.0.0.1';
$name = getenv('UAEP_DB_NAME') ?: 'uaep_smart_access';
$user = getenv('UAEP_DB_USER') ?: 'root';
$password = getenv('UAEP_DB_PASS') ?: '';
$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$firestore = new FirestoreRest($credentials);

function imported_value(array $row, string $key): mixed {
    if (!array_key_exists($key, $row) || $row[$key] === null) return null;
    if (in_array($key, ['details', 'payload'], true)) {
        return json_decode((string)$row[$key], true) ?? [];
    }
    if (in_array($key, ['created_at', 'last_seen', 'sampled_at'], true)) {
        $timestamp = strtotime((string)$row[$key]);
        return $timestamp === false ? null : date(DATE_ATOM, $timestamp);
    }
    if ($key === 'device_id') return (string)$row[$key];
    if (in_array($key, ['pir_motion', 'ultrasonic_ok', 'obstacle', 'gas_alarm', 'fire_detected', 'dht_ok', 'is_dark', 'street_led_on', 'buzzer_on', 'alarm_active'], true)) {
        return $row[$key] === null ? null : (bool)$row[$key];
    }
    if (is_numeric($row[$key]) && is_string($row[$key])) {
        return str_contains($row[$key], '.') ? (float)$row[$key] : (int)$row[$key];
    }
    return $row[$key];
}

function migrate_collection(PDO $pdo, FirestoreRest $firestore, string $table, string $collection): int {
    $rows = $pdo->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll();
    foreach ($rows as $row) {
        $id = (string)$row['id'];
        unset($row['id']);
        $document = [];
        foreach ($row as $key => $value) $document[$key] = imported_value($row, $key);
        $firestore->setDocument($collection, $id, $document);
    }
    return count($rows);
}

try {
    $counts = [
        'devices' => migrate_collection($pdo, $firestore, 'devices', 'devices'),
        'events' => migrate_collection($pdo, $firestore, 'events', 'events'),
        'sensor_readings' => migrate_collection($pdo, $firestore, 'sensor_readings', 'sensor_readings'),
    ];
    foreach ($counts as $collection => $count) fwrite(STDOUT, "Imported {$count} {$collection} documents.\n");
    fwrite(STDOUT, "Migration complete. MySQL was not modified.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: {$error->getMessage()}\n");
    exit(1);
}
