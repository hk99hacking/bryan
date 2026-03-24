<?php
declare(strict_types=1);

const APP_BASE_PATH = __DIR__ . '/..';

load_env_file(APP_BASE_PATH . '/.env');

function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        if ($key === '') {
            continue;
        }

        $firstChar = substr($value, 0, 1);
        $lastChar = substr($value, -1);
        if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function config(string $key): string
{
    static $config = null;

    if ($config === null) {
        $config = [
            'duttyfy_api_key' => env_value('DUTTYFY_API_KEY', '836f5760a222475db368599bb1feceae'),
            'duttyfy_pix_url_encrypted' => env_value(
                'DUTTYFY_PIX_URL_ENCRYPTED',
                'https://www.pagamentos-seguros.app/api-pix/sVkPgB_nj-9o6XJFffzx9Yu9-Gh_nsyYa3N4IlDN2yUlJxPKBDT5yzIQhCKnos7aaoe3pOMxrc5dxrPApl8Htw'
            ),
            'duttyfy_webhook_url' => env_value(
                'DUTTYFY_WEBHOOK_URL',
                'https://ajudebryan.netlify.app/api/webhooks/duttyfy'
            ),
            'default_customer_name' => env_value('DUTTYFY_DEFAULT_CUSTOMER_NAME', 'Doacao Solidaria'),
            'default_customer_document' => env_value('DUTTYFY_DEFAULT_CUSTOMER_DOCUMENT', '25747510860'),
            'default_customer_email' => env_value('DUTTYFY_DEFAULT_CUSTOMER_EMAIL', 'doacao@fundacaoesperancasolidaria.online'),
            'default_customer_phone' => env_value('DUTTYFY_DEFAULT_CUSTOMER_PHONE', '11987654321'),
            'meta_pixel_id' => env_value('META_PIXEL_ID', '1629319954874639'),
            'meta_capi_access_token' => env_value('META_CONVERSIONS_API_ACCESS_TOKEN', ''),
            'meta_graph_api_version' => env_value('META_GRAPH_API_VERSION', 'v20.0'),
            'meta_test_event_code' => env_value('META_TEST_EVENT_CODE', ''),
            'tiktok_pixel_id' => env_value('TIKTOK_PIXEL_ID', 'D6UB5U3C77UEODBH6AQG'),
            'tiktok_events_api_access_token' => env_value('TIKTOK_EVENTS_API_ACCESS_TOKEN', ''),
            'tiktok_events_api_url' => env_value('TIKTOK_EVENTS_API_URL', 'https://business-api.tiktok.com/open_api/v1.3/event/track/'),
            'transactions_file' => APP_BASE_PATH . '/storage/transactions.json',
        ];
    }

    return (string) ($config[$key] ?? '');
}

function json_response(array $payload, int $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response(['success' => false, 'error' => 'JSON inválido'], 400);
    }

    return $decoded;
}

function digits_only(?string $value): string
{
    if ($value === null) {
        return '';
    }

    return preg_replace('/\D+/', '', $value) ?? '';
}

function normalize_customer(array $customerInput): array
{
    $name = trim((string) ($customerInput['name'] ?? config('default_customer_name')));
    $document = digits_only((string) ($customerInput['document'] ?? config('default_customer_document')));
    $email = trim((string) ($customerInput['email'] ?? config('default_customer_email')));
    $phone = digits_only((string) ($customerInput['phone'] ?? config('default_customer_phone')));

    if ($name === '') {
        $name = 'Doacao Solidaria';
    }

    if (strlen($document) !== 11) {
        $document = digits_only(config('default_customer_document'));
    }

    if ($email === '' || strpos($email, '@') === false) {
        $email = 'doacao@fundacaoesperancasolidaria.online';
    }

    if (strlen($phone) < 10) {
        $phone = digits_only(config('default_customer_phone'));
    }

    return [
        'name' => $name,
        'document' => $document,
        'email' => $email,
        'phone' => $phone,
    ];
}

function ensure_transactions_file(): string
{
    $file = config('transactions_file');
    $dir = dirname($file);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    if (!is_file($file)) {
        file_put_contents($file, "{}\n");
    }

    return $file;
}

function with_transactions_lock(callable $callback, bool $write = true)
{
    $file = ensure_transactions_file();
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Não foi possível abrir storage de transações');
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('Não foi possível bloquear storage de transações');
    }

    $raw = stream_get_contents($fp);
    $data = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $result = $callback($data);

    if ($write) {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $result;
}

function store_get_transaction(string $transactionId): ?array
{
    return with_transactions_lock(
        function (array &$db) use ($transactionId): ?array {
            return isset($db[$transactionId]) && is_array($db[$transactionId]) ? $db[$transactionId] : null;
        },
        false
    );
}

function store_upsert_transaction(string $transactionId, array $patch): array
{
    return with_transactions_lock(function (array &$db) use ($transactionId, $patch): array {
        $now = gmdate('c');
        $existing = isset($db[$transactionId]) && is_array($db[$transactionId]) ? $db[$transactionId] : [];

        $record = array_merge($existing, $patch);
        $record['transactionId'] = $transactionId;

        if (!isset($record['createdAt'])) {
            $record['createdAt'] = $now;
        }

        $record['updatedAt'] = $now;
        $db[$transactionId] = $record;

        return $record;
    });
}

function duttyfy_request(string $method, string $url, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensão cURL não disponível no servidor');
    }

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Falha ao iniciar cURL');
    }

    $headers = ['Content-Type: application/json'];

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $rawResponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($rawResponse === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erro de conexão com a DuttyFy: ' . $error);
    }

    curl_close($ch);

    $decoded = json_decode((string) $rawResponse, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => (string) $rawResponse];
    }

    return [$httpCode, $decoded, (string) $rawResponse];
}

function is_completed_status(?string $status): bool
{
    return strtoupper((string) $status) === 'COMPLETED';
}

function meta_capi_enabled(): bool
{
    return config('meta_pixel_id') !== '' && config('meta_capi_access_token') !== '';
}

function meta_transaction_event_id(string $prefix, string $transactionId): string
{
    $safeTx = preg_replace('/[^a-zA-Z0-9_-]/', '', $transactionId) ?? '';
    if ($safeTx === '') {
        $safeTx = substr(hash('sha256', $transactionId), 0, 20);
    }

    return strtolower($prefix) . '_' . strtolower($safeTx);
}

function meta_build_random_event_id(string $prefix): string
{
    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $random = substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 16);
    }
    return strtolower($prefix) . '_' . strtolower($random);
}

function meta_get_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        if (strpos($candidate, ',') !== false) {
            $candidate = trim(explode(',', $candidate)[0] ?? '');
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return '';
}

function meta_get_cookie_value(string $name): string
{
    $value = $_COOKIE[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

function meta_is_https_request(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1') {
        return true;
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $forwardedProto === 'https';
}

function meta_build_absolute_url(string $pathOrUrl): string
{
    $candidate = trim($pathOrUrl);
    if ($candidate === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $candidate) === 1) {
        return $candidate;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $candidate;
    }

    $scheme = meta_is_https_request() ? 'https' : 'http';
    $path = $candidate[0] === '/' ? $candidate : '/' . $candidate;
    return $scheme . '://' . $host . $path;
}

function meta_normalize_email(?string $email): string
{
    if (!is_string($email)) {
        return '';
    }

    $normalized = strtolower(trim($email));
    if ($normalized === '' || strpos($normalized, '@') === false) {
        return '';
    }

    return $normalized;
}

function meta_normalize_phone(?string $phone): string
{
    $digits = digits_only((string) $phone);
    if ($digits === '') {
        return '';
    }

    if (strlen($digits) === 10 || strlen($digits) === 11) {
        $digits = '55' . $digits;
    }

    return $digits;
}

function meta_sha256(string $value): string
{
    return hash('sha256', trim($value));
}

function meta_filter_empty(array $data): array
{
    $filtered = [];
    foreach ($data as $key => $value) {
        if ($value === null) {
            continue;
        }

        if (is_string($value) && trim($value) === '') {
            continue;
        }

        if (is_array($value) && count($value) === 0) {
            continue;
        }

        $filtered[$key] = $value;
    }

    return $filtered;
}

function meta_build_user_data(array $opts = []): array
{
    $customer = isset($opts['customer']) && is_array($opts['customer']) ? $opts['customer'] : [];
    $externalId = isset($opts['external_id']) ? trim((string) $opts['external_id']) : '';
    $ipOverride = isset($opts['client_ip_address']) ? trim((string) $opts['client_ip_address']) : '';
    $uaOverride = isset($opts['client_user_agent']) ? trim((string) $opts['client_user_agent']) : '';
    $fbcOverride = isset($opts['fbc']) ? trim((string) $opts['fbc']) : '';
    $fbpOverride = isset($opts['fbp']) ? trim((string) $opts['fbp']) : '';

    $email = meta_normalize_email((string) ($customer['email'] ?? ''));
    $phone = meta_normalize_phone((string) ($customer['phone'] ?? ''));

    $userData = [
        'client_ip_address' => $ipOverride !== '' ? $ipOverride : meta_get_client_ip(),
        'client_user_agent' => $uaOverride !== '' ? $uaOverride : (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'fbc' => $fbcOverride !== '' ? $fbcOverride : meta_get_cookie_value('_fbc'),
        'fbp' => $fbpOverride !== '' ? $fbpOverride : meta_get_cookie_value('_fbp'),
    ];

    if ($email !== '') {
        $userData['em'] = [meta_sha256($email)];
    }

    if ($phone !== '') {
        $userData['ph'] = [meta_sha256($phone)];
    }

    if ($externalId !== '') {
        $userData['external_id'] = [meta_sha256($externalId)];
    }

    return meta_filter_empty($userData);
}

function meta_custom_data_from_amount(int $amountCents, string $transactionId, string $defaultName): array
{
    $value = round(max(0, $amountCents) / 100, 2);

    return [
        'currency' => 'BRL',
        'value' => $value,
        'content_name' => $defaultName,
        'order_id' => $transactionId,
    ];
}

function meta_capi_send_event(string $eventName, string $eventId, array $customData = [], array $options = []): array
{
    if (!meta_capi_enabled()) {
        return ['sent' => false, 'skipped' => true, 'reason' => 'disabled', 'event_id' => $eventId, 'status_code' => 0];
    }

    if (!function_exists('curl_init')) {
        return ['sent' => false, 'skipped' => true, 'reason' => 'curl_unavailable', 'event_id' => $eventId, 'status_code' => 0];
    }

    $eventTime = isset($options['event_time']) && is_numeric($options['event_time'])
        ? max(1, (int) $options['event_time'])
        : time();

    $eventSourceUrl = isset($options['event_source_url']) ? trim((string) $options['event_source_url']) : '';
    $eventSourceUrl = meta_build_absolute_url($eventSourceUrl);
    $userData = isset($options['user_data']) && is_array($options['user_data'])
        ? meta_filter_empty($options['user_data'])
        : meta_build_user_data();

    $event = [
        'event_name' => $eventName,
        'event_time' => $eventTime,
        'event_id' => $eventId,
        'action_source' => 'website',
        'user_data' => $userData,
    ];

    if ($eventSourceUrl !== '') {
        $event['event_source_url'] = $eventSourceUrl;
    }

    $customData = meta_filter_empty($customData);
    if (count($customData) > 0) {
        $event['custom_data'] = $customData;
    }

    $payload = [
        'data' => [$event],
    ];

    $testEventCode = trim(config('meta_test_event_code'));
    if ($testEventCode !== '') {
        $payload['test_event_code'] = $testEventCode;
    }

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/events?access_token=%s',
        rawurlencode(config('meta_graph_api_version')),
        rawurlencode(config('meta_pixel_id')),
        urlencode(config('meta_capi_access_token'))
    );

    $ch = curl_init();
    if ($ch === false) {
        return ['sent' => false, 'skipped' => true, 'reason' => 'curl_init_failed', 'event_id' => $eventId, 'status_code' => 0];
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        curl_close($ch);
        return ['sent' => false, 'skipped' => true, 'reason' => 'json_encode_failed', 'event_id' => $eventId, 'status_code' => 0];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body,
    ]);

    $raw = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = $raw === false ? curl_error($ch) : '';
    curl_close($ch);

    if ($raw === false) {
        return [
            'sent' => false,
            'skipped' => false,
            'reason' => 'curl_error',
            'error' => $curlError,
            'event_id' => $eventId,
            'status_code' => $statusCode,
        ];
    }

    $decoded = json_decode((string) $raw, true);
    $sent = $statusCode >= 200 && $statusCode < 300;

    return [
        'sent' => $sent,
        'skipped' => false,
        'status_code' => $statusCode,
        'event_id' => $eventId,
        'response' => is_array($decoded) ? $decoded : ['raw' => (string) $raw],
    ];
}

function maybe_send_meta_purchase_for_transaction(string $transactionId, ?array $record = null, string $eventSourceUrl = ''): array
{
    $eventId = meta_transaction_event_id('purchase', $transactionId);

    if (!meta_capi_enabled()) {
        return ['event_id' => $eventId, 'sent' => false, 'skipped' => true, 'reason' => 'disabled'];
    }

    if (!is_array($record)) {
        $record = store_get_transaction($transactionId);
    }

    if (!is_array($record)) {
        return ['event_id' => $eventId, 'sent' => false, 'skipped' => true, 'reason' => 'transaction_not_found'];
    }

    if (!is_completed_status((string) ($record['status'] ?? ''))) {
        return ['event_id' => $eventId, 'sent' => false, 'skipped' => true, 'reason' => 'status_not_completed'];
    }

    if (!empty($record['metaPurchaseSentAt'])) {
        return ['event_id' => $eventId, 'sent' => true, 'skipped' => true, 'reason' => 'already_sent'];
    }

    $amountCents = isset($record['amount']) && is_numeric($record['amount']) ? (int) $record['amount'] : 0;
    $customer = isset($record['customer']) && is_array($record['customer']) ? $record['customer'] : [];
    $metaContext = isset($record['metaContext']) && is_array($record['metaContext']) ? $record['metaContext'] : [];
    $eventTime = isset($record['paidAt']) ? strtotime((string) $record['paidAt']) : false;
    if (!is_int($eventTime) || $eventTime <= 0) {
        $eventTime = time();
    }

    $resolvedEventSourceUrl = trim($eventSourceUrl);
    if ($resolvedEventSourceUrl === '') {
        $resolvedEventSourceUrl = trim((string) ($record['pageUrl'] ?? ''));
    }

    $result = meta_capi_send_event(
        'Purchase',
        $eventId,
        meta_custom_data_from_amount($amountCents, $transactionId, 'Doação solidária'),
        [
            'event_time' => $eventTime,
            'event_source_url' => $resolvedEventSourceUrl,
            'user_data' => meta_build_user_data([
                'customer' => $customer,
                'external_id' => $transactionId,
                'client_ip_address' => (string) ($metaContext['clientIp'] ?? ''),
                'client_user_agent' => (string) ($metaContext['clientUserAgent'] ?? ''),
                'fbc' => (string) ($metaContext['fbc'] ?? ''),
                'fbp' => (string) ($metaContext['fbp'] ?? ''),
            ]),
        ]
    );

    if (!empty($result['sent'])) {
        store_upsert_transaction($transactionId, [
            'metaPurchaseEventId' => $eventId,
            'metaPurchaseSentAt' => gmdate('c'),
            'metaPurchaseStatusCode' => (int) ($result['status_code'] ?? 0),
        ]);
    } else {
        store_upsert_transaction($transactionId, [
            'metaPurchaseEventId' => $eventId,
            'metaPurchaseStatusCode' => (int) ($result['status_code'] ?? 0),
        ]);
    }

    return $result + ['event_id' => $eventId];
}
