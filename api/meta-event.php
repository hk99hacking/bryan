<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido'], 405);
}

$input = json_input();

$rawName = strtoupper(trim((string) ($input['event_name'] ?? '')));
$allowed = [
    'PAGEVIEW' => 'PageView',
    'VIEWCONTENT' => 'ViewContent',
    'INITIATECHECKOUT' => 'InitiateCheckout',
    'PURCHASE' => 'Purchase',
];

if (!isset($allowed[$rawName])) {
    json_response(['success' => false, 'error' => 'event_name inválido'], 422);
}

$eventName = $allowed[$rawName];
$transactionId = trim((string) ($input['transaction_id'] ?? ''));

$eventId = trim((string) ($input['event_id'] ?? ''));
if ($eventId === '') {
    if ($transactionId !== '' && $eventName === 'InitiateCheckout') {
        $eventId = meta_transaction_event_id('ic', $transactionId);
    } elseif ($transactionId !== '' && $eventName === 'Purchase') {
        $eventId = meta_transaction_event_id('purchase', $transactionId);
    } else {
        $eventId = meta_build_random_event_id(strtolower($eventName));
    }
}

$eventSourceUrl = trim((string) ($input['event_source_url'] ?? ''));
$customData = isset($input['custom_data']) && is_array($input['custom_data']) ? $input['custom_data'] : [];

$result = meta_capi_send_event(
    $eventName,
    $eventId,
    $customData,
    [
        'event_source_url' => $eventSourceUrl,
        'user_data' => meta_build_user_data([
            'external_id' => $transactionId,
        ]),
    ]
);

$success = !empty($result['sent']) || !empty($result['skipped']);
json_response(
    [
        'success' => $success,
        'event_name' => $eventName,
        'event_id' => $eventId,
        'capi' => $result,
    ],
    $success ? 200 : 502
);
