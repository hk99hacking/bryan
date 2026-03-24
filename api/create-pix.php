<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido'], 405);
}

$input = json_input();

$amount = $input['amount'] ?? $input['amountCents'] ?? null;
if (!is_numeric($amount)) {
    json_response(['success' => false, 'error' => 'Valor inválido'], 422);
}

$amountCents = (int) $amount;
if ($amountCents < 100) {
    json_response(['success' => false, 'error' => 'Valor mínimo de R$ 1,00'], 422);
}
$requestedAmountCents = $amountCents;

if (($requestedAmountCents % 100) === 0) {
    try {
        $deltaCents = random_int(-15, 15);
    } catch (Throwable $e) {
        $deltaCents = mt_rand(-15, 15);
    }

    if ($deltaCents === 0) {
        $deltaCents = 1;
    }

    $adjustedAmount = $requestedAmountCents + $deltaCents;
    if ($adjustedAmount < 100) {
        $adjustedAmount = $requestedAmountCents + abs($deltaCents);
    }

    $amountCents = $adjustedAmount;
}

$customerInput = isset($input['customer']) && is_array($input['customer']) ? $input['customer'] : [];
$customer = normalize_customer($customerInput);

$itemInput = isset($input['item']) && is_array($input['item']) ? $input['item'] : [];
$itemTitle = trim((string) ($itemInput['title'] ?? 'Doação solidária'));
if ($itemTitle === '') {
    $itemTitle = 'Doação solidária';
}

$description = trim((string) ($input['description'] ?? 'Doação via PIX'));
if ($description === '') {
    $description = 'Doação via PIX';
}

$utmInput = $input['utm'] ?? '';
$utm = '';
if (is_array($utmInput)) {
    $utm = http_build_query($utmInput);
} elseif (is_string($utmInput)) {
    $utm = trim($utmInput);
}

$payload = [
    'amount' => $amountCents,
    'description' => $description,
    'customer' => $customer,
    'item' => [
        'title' => $itemTitle,
        'price' => $amountCents,
        'quantity' => 1,
    ],
    'paymentMethod' => 'PIX',
];

if ($utm !== '') {
    $payload['utm'] = $utm;
}

try {
    [$httpCode, $gatewayResponse] = duttyfy_request('POST', config('duttyfy_pix_url_encrypted'), $payload);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 502);
}

if ($httpCode >= 400) {
    $errorMessage = (string) ($gatewayResponse['error'] ?? 'Erro ao criar PIX na DuttyFy');
    json_response(
        [
            'success' => false,
            'error' => $errorMessage,
            'gateway_status' => $httpCode,
        ],
        $httpCode
    );
}

$transactionId = trim((string) ($gatewayResponse['transactionId'] ?? ''));
$pixCode = trim((string) ($gatewayResponse['pixCode'] ?? ''));
$status = strtoupper((string) ($gatewayResponse['status'] ?? 'PENDING'));

if ($transactionId === '' || $pixCode === '') {
    json_response(['success' => false, 'error' => 'Resposta inválida da DuttyFy'], 502);
}

$initiateCheckoutEventId = meta_transaction_event_id('ic', $transactionId);
$pageUrl = trim((string) ($input['pageUrl'] ?? ''));
$metaContext = [
    'fbc' => meta_get_cookie_value('_fbc'),
    'fbp' => meta_get_cookie_value('_fbp'),
    'clientIp' => meta_get_client_ip(),
    'clientUserAgent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
];

$capiResult = meta_capi_send_event(
    'InitiateCheckout',
    $initiateCheckoutEventId,
    meta_custom_data_from_amount($amountCents, $transactionId, $itemTitle),
    [
        'event_source_url' => $pageUrl,
        'user_data' => meta_build_user_data([
            'customer' => $customer,
            'external_id' => $transactionId,
        ]),
    ]
);

store_upsert_transaction($transactionId, [
    'status' => $status === '' ? 'PENDING' : $status,
    'requestedAmount' => $requestedAmountCents,
    'amount' => $amountCents,
    'pixCode' => $pixCode,
    'customer' => $customer,
    'pageUrl' => $pageUrl,
    'utm' => $utm,
    'metaContext' => $metaContext,
    'gateway' => 'DUTTYFY',
    'source' => 'create',
    'webhookUrl' => config('duttyfy_webhook_url'),
    'metaInitiateCheckoutEventId' => $initiateCheckoutEventId,
    'metaInitiateCheckoutSentAt' => !empty($capiResult['sent']) ? gmdate('c') : null,
    'metaInitiateCheckoutStatusCode' => (int) ($capiResult['status_code'] ?? 0),
]);

json_response([
    'success' => true,
    'transactionId' => $transactionId,
    'pixCode' => $pixCode,
    'status' => $status === '' ? 'PENDING' : $status,
    'amount' => $amountCents,
    'metaEventIdInitiateCheckout' => $initiateCheckoutEventId,
]);
