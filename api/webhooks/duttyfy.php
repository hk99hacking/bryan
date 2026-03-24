<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => true, 'message' => 'Webhook DuttyFy ativo']);
}

$payload = json_input();

$transactionId = trim((string) ($payload['transactionId'] ?? ''));
if ($transactionId === '' && isset($payload['_id']) && is_array($payload['_id'])) {
    $transactionId = trim((string) ($payload['_id']['$oid'] ?? ''));
}

$status = strtoupper((string) ($payload['status'] ?? ''));

if ($transactionId === '' || $status === '') {
    json_response(['ok' => true, 'ignored' => true, 'reason' => 'payload incompleto']);
}

$existing = store_get_transaction($transactionId);
$alreadyCompleted = $existing !== null && is_completed_status((string) ($existing['status'] ?? '')) && $status === 'COMPLETED';

if (!$alreadyCompleted) {
    $patch = [
        'status' => $status,
        'source' => 'webhook',
    ];

    if (isset($payload['amount']) && is_numeric($payload['amount'])) {
        $patch['amount'] = (int) $payload['amount'];
    }

    if (isset($payload['result']) && is_numeric($payload['result'])) {
        $patch['result'] = (int) $payload['result'];
    }

    if (isset($payload['paidAt']) && is_string($payload['paidAt']) && trim($payload['paidAt']) !== '') {
        $patch['paidAt'] = trim($payload['paidAt']);
    } elseif ($status === 'COMPLETED') {
        $patch['paidAt'] = gmdate('c');
    }

    store_upsert_transaction($transactionId, $patch);
}

if ($status === 'COMPLETED') {
    $latest = store_get_transaction($transactionId);
    maybe_send_meta_purchase_for_transaction($transactionId, $latest, '');
}

json_response([
    'ok' => true,
    'transactionId' => $transactionId,
    'status' => $status,
    'idempotent' => $alreadyCompleted,
]);
