import {
  isCompletedStatus,
  jsonResponse,
  maybeSendMetaPurchaseForTransaction,
  parseJsonBody,
  storeGetTransaction,
  storeUpsertTransaction,
} from './_lib/core.mjs';

export const handler = async (event) => {
  if (event.httpMethod !== 'POST') {
    return jsonResponse({ ok: true, message: 'Webhook DuttyFy ativo' }, 200);
  }

  let payload;
  try {
    payload = parseJsonBody(event);
  } catch {
    return jsonResponse({ ok: true, ignored: true, reason: 'JSON inválido' }, 200);
  }

  let transactionId = String(payload.transactionId || '').trim();
  if (!transactionId && payload._id && typeof payload._id === 'object') {
    transactionId = String(payload._id.$oid || '').trim();
  }

  const status = String(payload.status || '').toUpperCase();

  if (!transactionId || !status) {
    return jsonResponse({ ok: true, ignored: true, reason: 'payload incompleto' }, 200);
  }

  const existing = await storeGetTransaction(transactionId);
  const alreadyCompleted = Boolean(
    existing &&
    isCompletedStatus(existing.status || '') &&
    status === 'COMPLETED'
  );

  if (!alreadyCompleted) {
    const patch = {
      status,
      source: 'webhook',
    };

    if (Number.isFinite(Number(payload.amount))) {
      patch.amount = Math.trunc(Number(payload.amount));
    }

    if (Number.isFinite(Number(payload.result))) {
      patch.result = Math.trunc(Number(payload.result));
    }

    if (typeof payload.paidAt === 'string' && payload.paidAt.trim()) {
      patch.paidAt = payload.paidAt.trim();
    } else if (status === 'COMPLETED') {
      patch.paidAt = new Date().toISOString();
    }

    await storeUpsertTransaction(transactionId, patch);
  }

  if (status === 'COMPLETED') {
    const latest = await storeGetTransaction(transactionId);
    await maybeSendMetaPurchaseForTransaction(event, transactionId, latest, '');
  }

  return jsonResponse({
    ok: true,
    transactionId,
    status,
    idempotent: alreadyCompleted,
  });
};
