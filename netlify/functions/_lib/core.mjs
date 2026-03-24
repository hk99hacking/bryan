import { createHash, randomBytes, randomInt } from 'node:crypto';
import net from 'node:net';
import { getStore } from '@netlify/blobs';

function envValue(key, defaultValue = '') {
  const value = process.env[key];
  if (value === undefined || value === null || String(value).trim() === '') {
    return defaultValue;
  }

  return String(value);
}

export const config = {
  duttyfyApiKey: envValue('DUTTYFY_API_KEY', '836f5760a222475db368599bb1feceae'),
  duttyfyPixUrlEncrypted: envValue(
    'DUTTYFY_PIX_URL_ENCRYPTED',
    'https://www.pagamentos-seguros.app/api-pix/sVkPgB_nj-9o6XJFffzx9Yu9-Gh_nsyYa3N4IlDN2yUlJxPKBDT5yzIQhCKnos7aaoe3pOMxrc5dxrPApl8Htw'
  ),
  duttyfyWebhookUrl: envValue('DUTTYFY_WEBHOOK_URL', 'https://ajudebryan.netlify.app/api/webhooks/duttyfy'),
  defaultCustomerName: envValue('DUTTYFY_DEFAULT_CUSTOMER_NAME', 'Doacao Solidaria'),
  defaultCustomerDocument: envValue('DUTTYFY_DEFAULT_CUSTOMER_DOCUMENT', '25747510860'),
  defaultCustomerEmail: envValue('DUTTYFY_DEFAULT_CUSTOMER_EMAIL', 'doacao@fundacaoesperancasolidaria.online'),
  defaultCustomerPhone: envValue('DUTTYFY_DEFAULT_CUSTOMER_PHONE', '11987654321'),
  metaPixelId: envValue('META_PIXEL_ID', '1629319954874639'),
  metaCapiAccessToken: envValue('META_CONVERSIONS_API_ACCESS_TOKEN', ''),
  metaGraphApiVersion: envValue('META_GRAPH_API_VERSION', 'v20.0'),
  metaTestEventCode: envValue('META_TEST_EVENT_CODE', ''),
};

const txStore = getStore({ name: 'pix-transactions', consistency: 'strong' });

export function jsonResponse(payload, statusCode = 200) {
  return {
    statusCode,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
    },
    body: JSON.stringify(payload),
  };
}

export function methodNotAllowed() {
  return jsonResponse({ success: false, error: 'Método não permitido' }, 405);
}

export function parseJsonBody(event) {
  const rawBody = event?.body ?? '';
  if (!rawBody || String(rawBody).trim() === '') {
    return {};
  }

  const payload = event?.isBase64Encoded
    ? Buffer.from(String(rawBody), 'base64').toString('utf8')
    : String(rawBody);

  let parsed;
  try {
    parsed = JSON.parse(payload);
  } catch {
    throw new Error('JSON inválido');
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new Error('JSON inválido');
  }

  return parsed;
}

export function digitsOnly(value) {
  return String(value ?? '').replace(/\D+/g, '');
}

export function normalizeCustomer(customerInput = {}) {
  const nameRaw = String(customerInput.name ?? config.defaultCustomerName).trim();
  let document = digitsOnly(customerInput.document ?? config.defaultCustomerDocument);
  let email = String(customerInput.email ?? config.defaultCustomerEmail).trim();
  let phone = digitsOnly(customerInput.phone ?? config.defaultCustomerPhone);

  const name = nameRaw || 'Doacao Solidaria';

  if (document.length !== 11) {
    document = digitsOnly(config.defaultCustomerDocument);
  }

  if (!email || !email.includes('@')) {
    email = 'doacao@fundacaoesperancasolidaria.online';
  }

  if (phone.length < 10) {
    phone = digitsOnly(config.defaultCustomerPhone);
  }

  return { name, document, email, phone };
}

function txKey(transactionId) {
  return `tx/${String(transactionId).trim()}.json`;
}

export async function storeGetTransaction(transactionId) {
  const safeId = String(transactionId || '').trim();
  if (!safeId) {
    return null;
  }

  const value = await txStore.get(txKey(safeId), { type: 'json', consistency: 'strong' });
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return value;
}

export async function storeUpsertTransaction(transactionId, patch) {
  const safeId = String(transactionId || '').trim();
  if (!safeId) {
    throw new Error('transactionId é obrigatório');
  }

  const now = new Date().toISOString();
  const existing = (await storeGetTransaction(safeId)) || {};
  const record = {
    ...existing,
    ...patch,
    transactionId: safeId,
    createdAt: existing.createdAt || now,
    updatedAt: now,
  };

  await txStore.setJSON(txKey(safeId), record);
  return record;
}

export async function duttyfyRequest(method, url, payload = null) {
  const options = {
    method: String(method || 'GET').toUpperCase(),
    headers: {
      'Content-Type': 'application/json',
    },
  };

  if (payload !== null) {
    options.body = JSON.stringify(payload);
  }

  let response;
  try {
    response = await fetch(url, options);
  } catch (error) {
    throw new Error(`Erro de conexão com a DuttyFy: ${error?.message || 'falha de rede'}`);
  }

  const rawResponse = await response.text();

  let decoded;
  try {
    decoded = JSON.parse(rawResponse);
  } catch {
    decoded = { raw: rawResponse };
  }

  return [response.status, decoded, rawResponse];
}

export function isCompletedStatus(status) {
  return String(status || '').toUpperCase() === 'COMPLETED';
}

export function metaCapiEnabled() {
  return config.metaPixelId !== '' && config.metaCapiAccessToken !== '';
}

export function metaSha256(value) {
  return createHash('sha256').update(String(value || '').trim()).digest('hex');
}

export function metaTransactionEventId(prefix, transactionId) {
  let safeTx = String(transactionId || '').replace(/[^a-zA-Z0-9_-]/g, '');
  if (!safeTx) {
    safeTx = metaSha256(String(transactionId || '')).slice(0, 20);
  }

  return `${String(prefix || '').toLowerCase()}_${safeTx.toLowerCase()}`;
}

export function metaBuildRandomEventId(prefix) {
  let random = '';
  try {
    random = randomBytes(8).toString('hex');
  } catch {
    random = `${Date.now().toString(16)}${Math.random().toString(16).slice(2, 10)}`;
  }

  return `${String(prefix || '').toLowerCase()}_${String(random).toLowerCase()}`;
}

function normalizeHeaders(headers = {}) {
  const normalized = {};
  Object.entries(headers || {}).forEach(([key, value]) => {
    normalized[String(key).toLowerCase()] = value;
  });
  return normalized;
}

export function getClientIp(event) {
  const headers = normalizeHeaders(event?.headers || {});
  const candidates = [
    headers['x-nf-client-connection-ip'],
    headers['cf-connecting-ip'],
    headers['x-forwarded-for'],
    headers['client-ip'],
  ];

  for (const candidate of candidates) {
    if (!candidate || typeof candidate !== 'string') {
      continue;
    }

    const first = candidate.includes(',') ? candidate.split(',')[0].trim() : candidate.trim();
    if (net.isIP(first)) {
      return first;
    }
  }

  return '';
}

function parseCookies(event) {
  const headers = normalizeHeaders(event?.headers || {});
  const cookieHeader = headers.cookie;
  if (!cookieHeader || typeof cookieHeader !== 'string') {
    return {};
  }

  const cookies = {};
  cookieHeader.split(';').forEach((part) => {
    const idx = part.indexOf('=');
    if (idx <= 0) {
      return;
    }

    const key = part.slice(0, idx).trim();
    const value = part.slice(idx + 1).trim();
    if (!key) {
      return;
    }

    cookies[key] = decodeURIComponent(value);
  });

  return cookies;
}

export function getCookieValue(event, name) {
  const cookies = parseCookies(event);
  const value = cookies[String(name || '').trim()];
  return typeof value === 'string' ? value.trim() : '';
}

function isHttpsRequest(event) {
  const headers = normalizeHeaders(event?.headers || {});
  const proto = String(headers['x-forwarded-proto'] || '').split(',')[0].trim().toLowerCase();
  return proto === 'https';
}

function buildAbsoluteUrl(event, pathOrUrl) {
  const candidate = String(pathOrUrl || '').trim();
  if (!candidate) {
    return '';
  }

  if (/^https?:\/\//i.test(candidate)) {
    return candidate;
  }

  const headers = normalizeHeaders(event?.headers || {});
  const host = String(headers.host || '').trim();
  if (!host) {
    return candidate;
  }

  const scheme = isHttpsRequest(event) ? 'https' : 'http';
  const path = candidate.startsWith('/') ? candidate : `/${candidate}`;
  return `${scheme}://${host}${path}`;
}

function metaNormalizeEmail(email) {
  const normalized = String(email || '').trim().toLowerCase();
  if (!normalized || !normalized.includes('@')) {
    return '';
  }

  return normalized;
}

function metaNormalizePhone(phone) {
  let digits = digitsOnly(phone || '');
  if (!digits) {
    return '';
  }

  if (digits.length === 10 || digits.length === 11) {
    digits = `55${digits}`;
  }

  return digits;
}

function metaFilterEmpty(data = {}) {
  const filtered = {};

  Object.entries(data || {}).forEach(([key, value]) => {
    if (value === null || value === undefined) {
      return;
    }

    if (typeof value === 'string' && value.trim() === '') {
      return;
    }

    if (Array.isArray(value) && value.length === 0) {
      return;
    }

    filtered[key] = value;
  });

  return filtered;
}

export function metaBuildUserData(event, opts = {}) {
  const customer = opts.customer && typeof opts.customer === 'object' ? opts.customer : {};
  const externalId = String(opts.external_id || '').trim();
  const ipOverride = String(opts.client_ip_address || '').trim();
  const uaOverride = String(opts.client_user_agent || '').trim();
  const fbcOverride = String(opts.fbc || '').trim();
  const fbpOverride = String(opts.fbp || '').trim();

  const email = metaNormalizeEmail(customer.email || '');
  const phone = metaNormalizePhone(customer.phone || '');

  const userData = {
    client_ip_address: ipOverride || getClientIp(event),
    client_user_agent: uaOverride || String(normalizeHeaders(event?.headers || {})['user-agent'] || ''),
    fbc: fbcOverride || getCookieValue(event, '_fbc'),
    fbp: fbpOverride || getCookieValue(event, '_fbp'),
  };

  if (email) {
    userData.em = [metaSha256(email)];
  }

  if (phone) {
    userData.ph = [metaSha256(phone)];
  }

  if (externalId) {
    userData.external_id = [metaSha256(externalId)];
  }

  return metaFilterEmpty(userData);
}

export function metaCustomDataFromAmount(amountCents, transactionId, defaultName) {
  const value = Number((Math.max(0, Number(amountCents || 0)) / 100).toFixed(2));

  return {
    currency: 'BRL',
    value,
    content_name: defaultName,
    order_id: transactionId,
  };
}

export async function metaCapiSendEvent(event, eventName, eventId, customData = {}, options = {}) {
  if (!metaCapiEnabled()) {
    return { sent: false, skipped: true, reason: 'disabled', event_id: eventId, status_code: 0 };
  }

  const eventTime = Number.isFinite(Number(options.event_time))
    ? Math.max(1, Number(options.event_time))
    : Math.floor(Date.now() / 1000);

  const eventSourceUrl = buildAbsoluteUrl(event, String(options.event_source_url || '').trim());
  const userData = options.user_data && typeof options.user_data === 'object'
    ? metaFilterEmpty(options.user_data)
    : metaBuildUserData(event);

  const eventPayload = {
    event_name: eventName,
    event_time: eventTime,
    event_id: eventId,
    action_source: 'website',
    user_data: userData,
  };

  if (eventSourceUrl) {
    eventPayload.event_source_url = eventSourceUrl;
  }

  const cleanCustomData = metaFilterEmpty(customData);
  if (Object.keys(cleanCustomData).length > 0) {
    eventPayload.custom_data = cleanCustomData;
  }

  const payload = { data: [eventPayload] };
  if (config.metaTestEventCode) {
    payload.test_event_code = config.metaTestEventCode;
  }

  const url = `https://graph.facebook.com/${encodeURIComponent(config.metaGraphApiVersion)}/${encodeURIComponent(config.metaPixelId)}/events?access_token=${encodeURIComponent(config.metaCapiAccessToken)}`;

  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  } catch (error) {
    return {
      sent: false,
      skipped: false,
      reason: 'fetch_error',
      error: error?.message || 'Falha na requisição',
      event_id: eventId,
      status_code: 0,
    };
  }

  const raw = await response.text();
  let decoded;
  try {
    decoded = JSON.parse(raw);
  } catch {
    decoded = { raw };
  }

  return {
    sent: response.status >= 200 && response.status < 300,
    skipped: false,
    status_code: response.status,
    event_id: eventId,
    response: decoded,
  };
}

export async function maybeSendMetaPurchaseForTransaction(event, transactionId, record = null, eventSourceUrl = '') {
  const eventId = metaTransactionEventId('purchase', transactionId);

  if (!metaCapiEnabled()) {
    return { event_id: eventId, sent: false, skipped: true, reason: 'disabled' };
  }

  const txRecord = record && typeof record === 'object' ? record : await storeGetTransaction(transactionId);
  if (!txRecord || typeof txRecord !== 'object') {
    return { event_id: eventId, sent: false, skipped: true, reason: 'transaction_not_found' };
  }

  if (!isCompletedStatus(txRecord.status || '')) {
    return { event_id: eventId, sent: false, skipped: true, reason: 'status_not_completed' };
  }

  if (txRecord.metaPurchaseSentAt) {
    return { event_id: eventId, sent: true, skipped: true, reason: 'already_sent' };
  }

  const amountCents = Number.isFinite(Number(txRecord.amount)) ? Number(txRecord.amount) : 0;
  const customer = txRecord.customer && typeof txRecord.customer === 'object' ? txRecord.customer : {};
  const metaContext = txRecord.metaContext && typeof txRecord.metaContext === 'object' ? txRecord.metaContext : {};

  let eventTime = Math.floor(Date.now() / 1000);
  if (txRecord.paidAt) {
    const ts = Date.parse(String(txRecord.paidAt));
    if (!Number.isNaN(ts) && ts > 0) {
      eventTime = Math.floor(ts / 1000);
    }
  }

  const resolvedEventSourceUrl = String(eventSourceUrl || txRecord.pageUrl || '').trim();

  const result = await metaCapiSendEvent(
    event,
    'Purchase',
    eventId,
    metaCustomDataFromAmount(amountCents, transactionId, 'Doação solidária'),
    {
      event_time: eventTime,
      event_source_url: resolvedEventSourceUrl,
      user_data: metaBuildUserData(event, {
        customer,
        external_id: transactionId,
        client_ip_address: String(metaContext.clientIp || ''),
        client_user_agent: String(metaContext.clientUserAgent || ''),
        fbc: String(metaContext.fbc || ''),
        fbp: String(metaContext.fbp || ''),
      }),
    }
  );

  if (result.sent) {
    await storeUpsertTransaction(transactionId, {
      metaPurchaseEventId: eventId,
      metaPurchaseSentAt: new Date().toISOString(),
      metaPurchaseStatusCode: Number(result.status_code || 0),
    });
  } else {
    await storeUpsertTransaction(transactionId, {
      metaPurchaseEventId: eventId,
      metaPurchaseStatusCode: Number(result.status_code || 0),
    });
  }

  return { ...result, event_id: eventId };
}

export function randomDeltaCents() {
  let delta = 1;

  try {
    delta = randomInt(-15, 16);
  } catch {
    delta = Math.floor(Math.random() * 31) - 15;
  }

  if (delta === 0) {
    delta = 1;
  }

  return delta;
}
