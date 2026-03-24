<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'error' => 'Método não permitido'], 405);
}

$transactionId = trim((string) ($_GET['transactionId'] ?? ''));
if ($transactionId === '') {
    json_response(['success' => false, 'error' => 'transactionId é obrigatório'], 422);
}

$metaPurchaseEventId = meta_transaction_event_id('purchase', $transactionId);

$localTransaction = store_get_transaction($transactionId);
$localAmount = $localTransaction !== null && isset($localTransaction['amount']) ? (int) $localTransaction['amount'] : null;
$localPixCode = $localTransaction !== null && isset($localTransaction['pixCode']) ? (string) $localTransaction['pixCode'] : null;
if ($localTransaction !== null && is_completed_status((string) ($localTransaction['status'] ?? ''))) {
    maybe_send_meta_purchase_for_transaction($transactionId, $localTransaction, '');
    json_response([
        'success' => true,
        'transactionId' => $transactionId,
        'status' => 'COMPLETED',
        'paidAt' => $localTransaction['paidAt'] ?? null,
        'amount' => $localAmount,
        'pixCode' => $localPixCode,
        'metaPurchaseEventId' => $metaPurchaseEventId,
        'source' => 'local',
    ]);
}

$url = config('duttyfy_pix_url_encrypted') . '?transactionId=' . urlencode($transactionId);

try {
    [$httpCode, $gatewayResponse] = duttyfy_request('GET', $url);
} catch (RuntimeException $e) {
    if ($localTransaction !== null) {
        json_response([
            'success' => true,
            'transactionId' => $transactionId,
            'status' => strtoupper((string) ($localTransaction['status'] ?? 'PENDING')),
            'paidAt' => $localTransaction['paidAt'] ?? null,
            'amount' => $localAmount,
            'pixCode' => $localPixCode,
            'metaPurchaseEventId' => $metaPurchaseEventId,
            'source' => 'local_fallback',
        ]);
    }

    json_response(['success' => false, 'error' => $e->getMessage()], 502);
}

if ($httpCode >= 400) {
    $errorMessage = (string) ($gatewayResponse['error'] ?? 'Erro ao consultar status na DuttyFy');

    if ($localTransaction !== null) {
        json_response([
            'success' => true,
            'transactionId' => $transactionId,
            'status' => strtoupper((string) ($localTransaction['status'] ?? 'PENDING')),
            'paidAt' => $localTransaction['paidAt'] ?? null,
            'amount' => $localAmount,
            'pixCode' => $localPixCode,
            'metaPurchaseEventId' => $metaPurchaseEventId,
            'source' => 'local_fallback',
            'warning' => $errorMessage,
        ]);
    }

    json_response(
        [
            'success' => false,
            'error' => $errorMessage,
            'gateway_status' => $httpCode,
        ],
        $httpCode
    );
}

$status = strtoupper((string) ($gatewayResponse['status'] ?? ''));
$paidAt = isset($gatewayResponse['paidAt']) ? (string) $gatewayResponse['paidAt'] : null;

if ($status === '') {
    if ($localTransaction !== null) {
        json_response([
            'success' => true,
            'transactionId' => $transactionId,
            'status' => strtoupper((string) ($localTransaction['status'] ?? 'PENDING')),
            'paidAt' => $localTransaction['paidAt'] ?? null,
            'amount' => $localAmount,
            'pixCode' => $localPixCode,
            'metaPurchaseEventId' => $metaPurchaseEventId,
            'source' => 'local_fallback',
        ]);
    }

    json_response(['success' => false, 'error' => 'Resposta inválida da DuttyFy'], 502);
}

$patch = [
    'status' => $status,
    'source' => 'poll',
];

if ($paidAt !== null && $paidAt !== '') {
    $patch['paidAt'] = $paidAt;
}

if ($localTransaction !== null && isset($localTransaction['amount'])) {
    $patch['amount'] = (int) $localTransaction['amount'];
}

store_upsert_transaction($transactionId, $patch);

if ($status === 'COMPLETED') {
    $latest = store_get_transaction($transactionId);
    maybe_send_meta_purchase_for_transaction($transactionId, $latest, '');
}

json_response([
    'success' => true,
    'transactionId' => $transactionId,
    'status' => $status,
    'paidAt' => $paidAt,
    'amount' => $localAmount,
    'pixCode' => $localPixCode,
    'metaPurchaseEventId' => $metaPurchaseEventId,
    'source' => 'gateway',
]);
