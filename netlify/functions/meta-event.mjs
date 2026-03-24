import {
  jsonResponse,
  metaBuildRandomEventId,
  metaBuildUserData,
  metaCapiSendEvent,
  metaTransactionEventId,
  methodNotAllowed,
  parseJsonBody,
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

  const rawName = String(input.event_name || '').trim().toUpperCase();
  const allowed = {
    PAGEVIEW: 'PageView',
    VIEWCONTENT: 'ViewContent',
    INITIATECHECKOUT: 'InitiateCheckout',
    PURCHASE: 'Purchase',
  };

  if (!allowed[rawName]) {
    return jsonResponse({ success: false, error: 'event_name inválido' }, 422);
  }

  const eventName = allowed[rawName];
  const transactionId = String(input.transaction_id || '').trim();

  let eventId = String(input.event_id || '').trim();
  if (!eventId) {
    if (transactionId && eventName === 'InitiateCheckout') {
      eventId = metaTransactionEventId('ic', transactionId);
    } else if (transactionId && eventName === 'Purchase') {
      eventId = metaTransactionEventId('purchase', transactionId);
    } else {
      eventId = metaBuildRandomEventId(eventName.toLowerCase());
    }
  }

  const eventSourceUrl = String(input.event_source_url || '').trim();
  const customData = input.custom_data && typeof input.custom_data === 'object' && !Array.isArray(input.custom_data)
    ? input.custom_data
    : {};

  const result = await metaCapiSendEvent(event, eventName, eventId, customData, {
    event_source_url: eventSourceUrl,
    user_data: metaBuildUserData(event, {
      external_id: transactionId,
    }),
  });

  const success = Boolean(result.sent || result.skipped);
  return jsonResponse(
    {
      success,
      event_name: eventName,
      event_id: eventId,
      capi: result,
    },
    success ? 200 : 502
  );
};
