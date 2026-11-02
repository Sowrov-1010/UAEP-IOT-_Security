<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/firestore_rest.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function db(): FirestoreRest {
    static $database = null;
    if ($database !== null) return $database;
    $credentials = getenv('FIREBASE_CREDENTIALS') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if (!$credentials || !is_file($credentials)) {
        throw new DomainException('Firebase credentials are not configured. Set FIREBASE_CREDENTIALS in Apache to your service-account JSON path; see README-XAMPP.txt.');
    }
    $database = new FirestoreRest($credentials);
    return $database;
}

function collection_rows(string $collection): array {
    return db()->listCollection($collection);
}

function query_rows(string $collection, string $field, bool $descending, int $limit, string $deviceId = ''): array {
    return db()->query($collection, $field, $descending, $limit, $deviceId);
}

function sort_rows(array &$rows, string $field, bool $descending = true): void {
    usort($rows, static function (array $left, array $right) use ($field, $descending): int {
        $order = strcmp((string)($left[$field] ?? ''), (string)($right[$field] ?? ''));
        return $descending ? -$order : $order;
    });
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) respond(400, ['success' => false, 'message' => 'Request body must be a JSON object']);
    return $data;
}

function get_device(string $id): ?array {
    return db()->getDocument('devices', $id);
}

function log_event(?string $deviceId, string $category, string $message, $details = null): void {
    db()->createDocument('events', [
        'device_id' => $deviceId,
        'category' => $category,
        'message' => mb_substr($message, 0, 255),
        'details' => $details,
        'created_at' => date(DATE_ATOM),
    ]);
}

function device_request(array $device, string $path, int $timeout = 6): array {
    $url = 'http://' . $device['ip'] . $path;
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: UAEP-XAMPP-Dashboard/1.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 502;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $match)) $status = (int)$match[1];
    if ($raw === false) return [502, ['success' => false, 'message' => 'Could not reach the ESP32. Check its IP and Wi-Fi connection.']];
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = ['message' => mb_substr($raw, 0, 4000)];
    return [$status, $payload];
}

function field_value(array $data, array $names) {
    foreach ($names as $name) if (array_key_exists($name, $data)) return $data[$name];
    return null;
}

function db_bool($value): ?int {
    if ($value === null) return null;
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_numeric($value)) return ((int)$value) ? 1 : 0;
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === null ? null : ($parsed ? 1 : 0);
}

function db_number($value): ?float {
    return $value === null || !is_numeric($value) ? null : (float)$value;
}

function store_sensor_reading(array $device, array $data): void {
    $distance = db_number(field_value($data, ['distanceCm', 'distance']));
    if ($distance !== null && $distance < 0) $distance = null;
    db()->createDocument('sensor_readings', [
        'device_id' => (string)$device['id'],
        'device_name' => mb_substr((string)$device['name'], 0, 60),
        'device_ip' => (string)$device['ip'],
        'sampled_at' => date(DATE_ATOM),
        'pir_motion' => db_bool(field_value($data, ['pirMotion','pir','motion'])),
        'distance_cm' => $distance,
        'ultrasonic_ok' => db_bool(field_value($data, ['ultrasonicOk'])),
        'obstacle' => db_bool(field_value($data, ['obstacle','near'])),
        'gas_raw' => is_numeric(field_value($data, ['gasRaw','gas'])) ? (int)field_value($data, ['gasRaw','gas']) : null,
        'gas_alarm' => db_bool(field_value($data, ['gasAlarm','gasDetected'])),
        'fire_detected' => db_bool(field_value($data, ['fireDetected','fire'])),
        'fire_level' => is_numeric(field_value($data, ['fireLevel'])) ? (int)$data['fireLevel'] : null,
        'temperature_c' => db_number(field_value($data, ['temperatureC','temperature'])),
        'humidity_pct' => db_number(field_value($data, ['humidityPct','humidity'])),
        'dht_ok' => db_bool(field_value($data, ['dhtOk','dhtValid'])),
        'light_raw' => is_numeric(field_value($data, ['lightRaw','ldrRaw','ldr'])) ? (int)field_value($data, ['lightRaw','ldrRaw','ldr']) : null,
        'is_dark' => db_bool(field_value($data, ['dark','isDark'])),
        'street_led_on' => db_bool(field_value($data, ['streetLedOn','led'])),
        'buzzer_on' => db_bool(field_value($data, ['buzzerOn','buzzer'])),
        'alarm_active' => db_bool(field_value($data, ['alarm'])),
        'alarm_reason' => isset($data['alarmReason']) ? mb_substr((string)$data['alarmReason'], 0, 40) : null,
        'payload' => $data,
    ]);
}

function route_parts(): array {
    $route = trim((string)($_GET['route'] ?? ''), '/');
    return $route === '' ? [] : explode('/', $route);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $parts = route_parts();
    $route = implode('/', $parts);

    if ($method === 'GET' && $route === 'health') {
        db()->query('devices', null, true, 1);
        respond(200, ['success' => true, 'service' => 'UAEP XAMPP dashboard', 'database' => 'Firestore', 'time' => date(DATE_ATOM)]);
    }

    if ($method === 'GET' && $route === 'devices') {
        $devices = collection_rows('devices');
        sort_rows($devices, 'created_at', false);
        respond(200, ['success' => true, 'devices' => $devices]);
    }

    if ($method === 'GET' && $route === 'events') {
        $events = query_rows('events', 'created_at', true, 100);
        $devices = collection_rows('devices');
        $deviceNames = [];
        foreach ($devices as $device) $deviceNames[(string)$device['id']] = $device['name'];
        foreach ($events as &$event) {
            $event['device_name'] = $deviceNames[(string)($event['device_id'] ?? '')] ?? null;
        }
        unset($event);
        $total = db()->count('events');
        respond(200, ['success' => true, 'events' => $events, 'total' => $total]);
    }

    if ($method === 'GET' && $route === 'people') {
        $people = collection_rows('people');
        sort_rows($people, 'name', false);
        respond(200, ['success' => true, 'people' => $people]);
    }

    if ($method === 'POST' && $route === 'people') {
        $data = body_json();
        $name = trim((string)($data['name'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $fingerprintId = trim((string)($data['fingerprint_id'] ?? ''));
        $rfidUid = strtoupper((string)preg_replace('/[\\s:-]/', '', (string)($data['rfid_uid'] ?? '')));
        if ($name === '') respond(400, ['success' => false, 'message' => 'Person name is required']);
        if ($address === '') respond(400, ['success' => false, 'message' => 'Address is required']);
        if ($fingerprintId !== '' && (!ctype_digit($fingerprintId) || (int)$fingerprintId < 1 || (int)$fingerprintId > 127)) {
            respond(400, ['success' => false, 'message' => 'Fingerprint ID must be between 1 and 127']);
        }
        if ($rfidUid !== '' && !preg_match('/^(?:[0-9A-F]{2}){4,10}$/', $rfidUid)) {
            respond(400, ['success' => false, 'message' => 'Enter a valid RFID UID in hexadecimal']);
        }
        if ($fingerprintId === '' && $rfidUid === '') {
            respond(400, ['success' => false, 'message' => 'Add a fingerprint ID, an RFID UID, or both']);
        }
        foreach (collection_rows('people') as $existing) {
            if ($fingerprintId !== '' && (string)($existing['fingerprint_id'] ?? '') === $fingerprintId) {
                respond(409, ['success' => false, 'message' => 'That fingerprint ID is already registered']);
            }
            if ($rfidUid !== '' && strtoupper((string)($existing['rfid_uid'] ?? '')) === $rfidUid) {
                respond(409, ['success' => false, 'message' => 'That RFID UID is already registered']);
            }
        }
        $id = db()->createDocument('people', [
            'name' => mb_substr($name, 0, 80),
            'address' => mb_substr($address, 0, 200),
            'fingerprint_id' => $fingerprintId === '' ? null : $fingerprintId,
            'fingerprint_owner_name' => mb_substr(trim((string)($data['fingerprint_owner_name'] ?? '')) ?: $name, 0, 80),
            'rfid_uid' => $rfidUid === '' ? null : $rfidUid,
            'rfid_owner_name' => mb_substr(trim((string)($data['rfid_owner_name'] ?? '')) ?: $name, 0, 80),
            'created_at' => date(DATE_ATOM),
        ]);
        log_event(null, 'person', 'Registered ' . $name, ['person_id' => $id]);
        respond(201, ['success' => true, 'person' => db()->getDocument('people', $id)]);
    }

    if ($method === 'GET' && $route === 'attendance') {
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) respond(400, ['success' => false, 'message' => 'Date must use YYYY-MM-DD']);
        $records = db()->query('attendance', 'work_date', false, 500, '', 'work_date', $date);
        respond(200, ['success' => true, 'attendance' => $records, 'date' => $date]);
    }

    if ($method === 'GET' && $route === 'attendance.csv') {
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) respond(400, ['success' => false, 'message' => 'Date must use YYYY-MM-DD']);
        $records = db()->query('attendance', 'work_date', false, 500, '', 'work_date', $date);
        usort($records, static fn(array $left, array $right): int => strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? '')));
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="uaep-attendance-' . $date . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $columns = ['work_date','name','address','fingerprint_owner_name','fingerprint_id','rfid_owner_name','rfid_uid','time_in','time_out'];
        fputcsv($out, $columns);
        foreach ($records as $record) {
            $row = array_map(fn($column) => $record[$column] ?? '', $columns);
            foreach ($row as &$cell) {
                $cell = (string)$cell;
                if (preg_match('/^[\t\r ]*[=+\-@]/', $cell)) $cell = "'" . $cell;
            }
            unset($cell);
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    if ($method === 'POST' && $route === 'attendance') {
        $data = body_json();
        $personId = trim((string)($data['person_id'] ?? ''));
        $direction = strtolower(trim((string)($data['direction'] ?? '')));
        if (!in_array($direction, ['in', 'out'], true)) respond(400, ['success' => false, 'message' => 'Choose time in or time out']);
        $person = db()->getDocument('people', $personId);
        if (!$person) respond(404, ['success' => false, 'message' => 'Registered person not found']);
        $workDate = (string)($data['work_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $workDate)) respond(400, ['success' => false, 'message' => 'Work date must use YYYY-MM-DD']);
        $recordId = hash('sha256', $personId . '|' . $workDate);
        $record = db()->getDocument('attendance', $recordId) ?? [];
        $timestamp = date(DATE_ATOM);
        $attendance = [
            'person_id' => $personId,
            'name' => $person['name'],
            'address' => $person['address'],
            'fingerprint_id' => $person['fingerprint_id'] ?? null,
            'fingerprint_owner_name' => $person['fingerprint_owner_name'] ?? $person['name'],
            'rfid_uid' => $person['rfid_uid'] ?? null,
            'rfid_owner_name' => $person['rfid_owner_name'] ?? $person['name'],
            'work_date' => $workDate,
            'time_in' => $record['time_in'] ?? null,
            'time_out' => $record['time_out'] ?? null,
            'updated_at' => $timestamp,
        ];
        $attendance[$direction === 'in' ? 'time_in' : 'time_out'] = $timestamp;
        db()->setDocument('attendance', $recordId, $attendance);
        log_event(null, 'attendance', 'Time ' . $direction . ': ' . $person['name'], ['person_id' => $personId, 'work_date' => $workDate]);
        respond(200, ['success' => true, 'attendance' => array_merge($attendance, ['id' => $recordId])]);
    }

    if ($method === 'GET' && $route === 'readings') {
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $deviceId = (string)($_GET['device_id'] ?? '');
        $readings = query_rows('sensor_readings', 'sampled_at', true, $limit, $deviceId);
        respond(200, ['success' => true, 'readings' => $readings]);
    }

    if ($method === 'GET' && $route === 'readings.csv') {
        $deviceId = (string)($_GET['device_id'] ?? '');
        $readings = collection_rows('sensor_readings');
        if ($deviceId !== '') $readings = array_values(array_filter($readings, static fn(array $row): bool => (string)($row['device_id'] ?? '') === $deviceId));
        sort_rows($readings, 'sampled_at', false);
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="uaep-sensor-readings.csv"');
        $out = fopen('php://output', 'w');
        $columns = ['id','device_name','device_ip','device_id','sampled_at','pir_motion','distance_cm','ultrasonic_ok','obstacle','gas_raw','gas_alarm','fire_detected','fire_level','temperature_c','humidity_pct','dht_ok','light_raw','is_dark','street_led_on','buzzer_on','alarm_active','alarm_reason','payload'];
        fputcsv($out, $columns);
        foreach ($readings as $row) {
            $row['payload'] = json_encode($row['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            fputcsv($out, array_map(fn($column) => $row[$column] ?? '', $columns));
        }
        fclose($out);
        exit;
    }

    if ($method === 'POST' && $route === 'devices') {
        $data = body_json();
        $name = trim((string)($data['name'] ?? ''));
        $ip = trim((string)($data['ip'] ?? ''));
        $role = (string)($data['role'] ?? 'sensors');
        if ($name === '') respond(400, ['success' => false, 'message' => 'Device name is required']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) respond(400, ['success' => false, 'message' => 'Enter a valid IPv4 address']);
        if (!in_array($role, ['access','sensors','camera','other'], true)) $role = 'other';
        foreach (collection_rows('devices') as $existing) {
            if (($existing['ip'] ?? '') === $ip) respond(409, ['success' => false, 'message' => 'That IP address is already registered']);
        }
        $id = db()->createDocument('devices', [
            'name' => mb_substr($name, 0, 60),
            'ip' => $ip,
            'role' => $role,
            'state' => 'offline',
            'last_seen' => null,
            'created_at' => date(DATE_ATOM),
        ]);
        log_event($id, 'device', 'Added ' . $name, ['ip' => $ip, 'role' => $role]);
        respond(201, ['success' => true, 'device' => get_device($id)]);
    }

    if (count($parts) === 3 && $parts[0] === 'devices' && preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1])) {
        $id = $parts[1];
        $device = get_device($id);
        if (!$device) respond(404, ['success' => false, 'message' => 'Device not found']);
        $action = $parts[2];

        if ($method === 'POST' && $action === 'connect') {
            [$status, $payload] = device_request($device, '/api/test');
            $online = $status < 400 && ($payload['success'] ?? true) !== false;
            $updates = ['state' => $online ? 'online' : 'offline'];
            if ($online) $updates['last_seen'] = date(DATE_ATOM);
            db()->setDocument('devices', $id, $updates, true);
            log_event($id, 'connection', $online ? 'Device connected' : 'Device connection failed', $payload);
            respond($online ? 200 : $status, ['success' => $online, 'device' => get_device($id), 'response' => $payload]);
        }

        if ($method === 'GET' && $action === 'status') {
            [$status, $payload] = device_request($device, '/api/status');
            $online = $status < 400 && ($payload['success'] ?? true) !== false;
            $updates = ['state' => $online ? 'online' : 'offline'];
            if ($online) $updates['last_seen'] = date(DATE_ATOM);
            db()->setDocument('devices', $id, $updates, true);
            if ($online && is_array($payload)) store_sensor_reading($device, $payload);
            respond($status, ['success' => $online, 'device' => get_device($id), 'status' => $payload]);
        }

        if ($method === 'GET' && $action === 'command') {
            $devicePath = (string)($_GET['path'] ?? '');
            if (!str_starts_with($devicePath, '/api/') || str_contains($devicePath, '..')) respond(400, ['success' => false, 'message' => 'Only ESP32 /api/ routes are allowed']);
            [$status, $payload] = device_request($device, $devicePath, 45);
            log_event($id, 'command', 'Device API: ' . strtok($devicePath, '?'), ['http_status' => $status, 'response' => $payload]);
            respond($status, ['success' => $status < 400, 'data' => $payload]);
        }
    }

    if ($method === 'DELETE' && count($parts) === 2 && $parts[0] === 'devices' && preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1])) {
        $id = $parts[1];
        $device = get_device($id);
        if (!$device) respond(404, ['success' => false, 'message' => 'Device not found']);
        db()->deleteDocument('devices', $id);
        log_event(null, 'device', 'Removed device ' . $device['name'], ['ip' => $device['ip']]);
        respond(200, ['success' => true]);
    }

    respond(404, ['success' => false, 'message' => 'API endpoint not found']);
} catch (Throwable $e) {
    error_log('UAEP dashboard API error: ' . $e->getMessage());
    $status = $e instanceof DomainException ? 503 : 500;
    $message = $e instanceof DomainException
        ? $e->getMessage()
        : 'Firestore request failed. Check Apache/PHP logs and Firebase project access.';
    respond($status, ['success' => false, 'message' => $message]);
}
