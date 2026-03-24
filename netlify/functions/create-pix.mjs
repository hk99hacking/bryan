import {
  config,
  duttyfyRequest,
  getClientIp,
  getCookieValue,
  jsonResponse,
  metaBuildUserData,
  metaCapiSendEvent,
  metaCustomDataFromAmount,
  metaTransactionEventId,
  methodNotAllowed,
  normalizeCustomer,
  parseJsonBody,
  randomDeltaCents,
  storeUpsertTransaction,
} from './_lib/core.mjs';

export const handler = async (event) => {
  if (event.httpMethod !== 'POST') {
    return methodNotAllowed();
  }

  let input;
  try {
    input = parseJsonBody(event);
  } catch (error) {
    return jsonResponse({ success: false, error: error.message || 'JSON inválido' }, 400);
  }

  const amount = input.amount ?? input.amountCents ?? null;
  if (!Number.isFinite(Number(amount))) {
    return jsonResponse({ success: false, error: 'Valor inválido' }, 422);
  }

  let amountCents = Math.trunc(Number(amount));
  if (amountCents < 100) {
    return jsonResponse({ success: false, error: 'Valor mínimo de R$ 1,00' }, 422);
  }

  const requestedAmountCents = amountCents;
  if ((requestedAmountCents % 100) === 0) {
    const deltaCents = randomDeltaCents();
    let adjusted = requestedAmountCents + deltaCents;
    if (adjusted < 100) {
      adjusted = requestedAmountCents + Math.abs(deltaCents);
    }
    amountCents = adjusted;
  }

  const customerInput = input.customer && typeof input.customer === 'object' ? input.customer : {};
  const customer = normalizeCustomer(customerInput);

  const itemInput = input.item && typeof input.item === 'object' ? input.item : {};
  let itemTitle = String(itemInput.title ?? 'Doação solidária').trim();
  if (!itemTitle) {
    itemTitle = 'Doação solidária';
  }

  let description = String(input.description ?? 'Doação via PIX').trim();
  if (!description) {
    description = 'Doação via PIX';
  }

  const utmInput = input.utm ?? '';
  let utm = '';
  if (utmInput && typeof utmInput === 'object' && !Array.isArray(utmInput)) {
    utm = new URLSearchParams(utmInput).toString();
  } else if (typeof utmInput === 'string') {
    utm = utmInput.trim();
  }

  const payload = {
    amount: amountCents,
    description,
    customer,
    item: {
      title: itemTitle,
      price: amountCents,
      quantity: 1,
    },
    paymentMethod: 'PIX',
  };

  if (utm) {
    payload.utm = utm;
  }

  let httpCode;
  let gatewayResponse;
  try {
    [httpCode, gatewayResponse] = await duttyfyRequest('POST', config.duttyfyPixUrlEncrypted, payload);
  } catch (error) {
    return jsonResponse({ success: false, error: error.message || 'Falha ao criar PIX' }, 502);
  }

  if (httpCode >= 400) {
    return jsonResponse(
      {
        success: false,
        error: String(gatewayResponse?.error ?? 'Erro ao criar PIX na DuttyFy'),
        gateway_status: httpCode,
      },
      httpCode
    );
  }

  const transactionId = String(gatewayResponse?.transactionId ?? '').trim();
  const pixCode = String(gatewayResponse?.pixCode ?? '').trim();
  const status = String(gatewayResponse?.status ?? 'PENDING').toUpperCase() || 'PENDING';

  if (!transactionId || !pixCode) {
    return jsonResponse({ success: false, error: 'Resposta inválida da DuttyFy' }, 502);
  }

  const initiateCheckoutEventId = metaTransactionEventId('ic', transactionId);
  const pageUrl = String(input.pageUrl ?? '').trim();
  const metaContext = {
    fbc: getCookieValue(event, '_fbc'),
    fbp: getCookieValue(event, '_fbp'),
    clientIp: getClientIp(event),
    clientUserAgent: String(event?.headers?.['user-agent'] || event?.headers?.['User-Agent'] || ''),
  };

  const capiResult = await metaCapiSendEvent(
    event,
    'InitiateCheckout',
    initiateCheckoutEventId,
    metaCustomDataFromAmount(amountCents, transactionId, itemTitle),
    {
      event_source_url: pageUrl,
      user_data: metaBuildUserData(event, {
        customer,
        external_id: transactionId,
      }),
    }
  );

  await storeUpsertTransaction(transactionId, {
    status,
    requestedAmount: requestedAmountCents,
    amount: amountCents,
    pixCode,
    customer,
    pageUrl,
    utm,
    metaContext,
    gateway: 'DUTTYFY',
    source: 'create',
    webhookUrl: config.duttyfyWebhookUrl,
    metaInitiateCheckoutEventId: initiateCheckoutEventId,
    metaInitiateCheckoutSentAt: capiResult.sent ? new Date().toISOString() : null,
    metaInitiateCheckoutStatusCode: Number(capiResult.status_code || 0),
  });

  return jsonResponse({
    success: true,
    transactionId,
    pixCode,
    status,
    amount: amountCents,
    metaEventIdInitiateCheckout: initiateCheckoutEventId,
  });
};
