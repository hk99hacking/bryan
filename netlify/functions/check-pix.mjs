import {
  config,
  duttyfyRequest,
  isCompletedStatus,
  jsonResponse,
  maybeSendMetaPurchaseForTransaction,
  metaTransactionEventId,
  methodNotAllowed,
  storeGetTransaction,
  storeUpsertTransaction,
} from './_lib/core.mjs';

function getQueryParam(event, key) {
  if (event?.queryStringParameters && event.queryStringParameters[key] !== undefined) {
    return String(event.queryStringParameters[key] || '').trim();
  }

  const rawQuery = String(event?.rawQuery || '').trim();
  if (!rawQuery) {
    return '';
  }

  const query = new URLSearchParams(rawQuery);
  return String(query.get(key) || '').trim();
}

export const handler = async (event) => {
  if (event.httpMethod !== 'GET') {
    return methodNotAllowed();
  }

  const transactionId = getQueryParam(event, 'transactionId');
  if (!transactionId) {
    return jsonResponse({ success: false, error: 'transactionId é obrigatório' }, 422);
  }

  const metaPurchaseEventId = metaTransactionEventId('purchase', transactionId);

  const localTransaction = await storeGetTransaction(transactionId);
  const localAmount = localTransaction && Number.isFinite(Number(localTransaction.amount))
    ? Number(localTransaction.amount)
    : null;
  const localPixCode = localTransaction && localTransaction.pixCode ? String(localTransaction.pixCode) : null;

  if (localTransaction && isCompletedStatus(localTransaction.status || '')) {
    await maybeSendMetaPurchaseForTransaction(event, transactionId, localTransaction, '');
    return jsonResponse({
      success: true,
      transactionId,
      status: 'COMPLETED',
      paidAt: localTransaction.paidAt || null,
      amount: localAmount,
      pixCode: localPixCode,
      metaPurchaseEventId,
      source: 'local',
    });
  }

  const url = `${config.duttyfyPixUrlEncrypted}?transactionId=${encodeURIComponent(transactionId)}`;

  let httpCode;
  let gatewayResponse;
  try {
    [httpCode, gatewayResponse] = await duttyfyRequest('GET', url);
  } catch (error) {
    if (localTransaction) {
      return jsonResponse({
        success: true,
        transactionId,
        status: String(localTransaction.status || 'PENDING').toUpperCase(),
        paidAt: localTransaction.paidAt || null,
        amount: localAmount,
        pixCode: localPixCode,
        metaPurchaseEventId,
        source: 'local_fallback',
      });
    }

    return jsonResponse({ success: false, error: error.message || 'Falha ao consultar status' }, 502);
  }

  if (httpCode >= 400) {
    const errorMessage = String(gatewayResponse?.error || 'Erro ao consultar status na DuttyFy');

    if (localTransaction) {
      return jsonResponse({
        success: true,
        transactionId,
        status: String(localTransaction.status || 'PENDING').toUpperCase(),
        paidAt: localTransaction.paidAt || null,
        amount: localAmount,
        pixCode: localPixCode,
        metaPurchaseEventId,
        source: 'local_fallback',
        warning: errorMessage,
      });
    }

    return jsonResponse(
      {
        success: false,
        error: errorMessage,
        gateway_status: httpCode,
      },
      httpCode
    );
  }

  const status = String(gatewayResponse?.status || '').toUpperCase();
  const paidAt = gatewayResponse?.paidAt ? String(gatewayResponse.paidAt) : null;

  if (!status) {
    if (localTransaction) {
      return jsonResponse({
        success: true,
        transactionId,
        status: String(localTransaction.status || 'PENDING').toUpperCase(),
        paidAt: localTransaction.paidAt || null,
        amount: localAmount,
        pixCode: localPixCode,
        metaPurchaseEventId,
        source: 'local_fallback',
      });
    }

    return jsonResponse({ success: false, error: 'Resposta inválida da DuttyFy' }, 502);
  }

  const patch = {
    status,
    source: 'poll',
  };

  if (paidAt) {
    patch.paidAt = paidAt;
  }

  if (localTransaction && localTransaction.amount !== undefined) {
    patch.amount = Number(localTransaction.amount);
  }

  await storeUpsertTransaction(transactionId, patch);

  if (status === 'COMPLETED') {
    const latest = await storeGetTransaction(transactionId);
    await maybeSendMetaPurchaseForTransaction(event, transactionId, latest, '');
  }

  return jsonResponse({
    success: true,
    transactionId,
    status,
    paidAt,
    amount: localAmount,
    pixCode: localPixCode,
    metaPurchaseEventId,
    source: 'gateway',
  });
};
