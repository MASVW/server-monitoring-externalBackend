const crypto = require('crypto');
const env = require('../config/env');

const normalizeSignature = (signatureHeader) => {
  if (!signatureHeader || typeof signatureHeader !== 'string') {
    return '';
  }

  if (signatureHeader.startsWith('sha256=')) {
    return signatureHeader.slice('sha256='.length);
  }

  return signatureHeader;
};

const computeSignature = (rawBody, timestamp, secret) => {
  return crypto
    .createHmac('sha256', secret)
    .update(`${rawBody}${timestamp}`)
    .digest('hex');
};

const safeCompare = (left, right) => {
  const a = Buffer.from(left, 'utf8');
  const b = Buffer.from(right, 'utf8');

  if (a.length !== b.length) {
    return false;
  }

  return crypto.timingSafeEqual(a, b);
};

const validateTimestampDrift = (timestamp) => {
  const date = new Date(timestamp);

  if (Number.isNaN(date.getTime())) {
    return {
      ok: false,
      reason: 'Invalid timestamp format'
    };
  }

  const nowMs = Date.now();
  const diffSeconds = Math.abs(Math.floor((nowMs - date.getTime()) / 1000));

  if (diffSeconds > env.heartbeat.allowedDriftSeconds) {
    return {
      ok: false,
      reason: 'Timestamp outside allowed drift window'
    };
  }

  return {
    ok: true,
    date
  };
};

const verifyRequestSignature = ({ rawBody, timestamp, signature, secret }) => {
  const normalizedSignature = normalizeSignature(signature);

  if (!secret) {
    return {
      ok: false,
      reason: 'HMAC secret is not configured'
    };
  }

  const expected = computeSignature(rawBody, timestamp, secret);

  return {
    ok: safeCompare(normalizedSignature, expected),
    expected
  };
};

module.exports = {
  computeSignature,
  normalizeSignature,
  validateTimestampDrift,
  verifyRequestSignature
};
