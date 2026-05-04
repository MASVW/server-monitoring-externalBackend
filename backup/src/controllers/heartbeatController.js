const env = require('../config/env');
const AppError = require('../utils/appError');
const { successResponse } = require('../utils/response');
const { getClientIp } = require('../utils/ip');
const { validateHeartbeatPayload } = require('../validators/heartbeatValidator');
const {
  validateTimestampDrift,
  verifyRequestSignature
} = require('../services/hmacService');
const { processHeartbeat, persistInvalidHeartbeat } = require('../services/heartbeatService');

const validateRequiredHeaders = (headers) => {
  const nodeId = headers['x-node-id'];
  const timestamp = headers['x-timestamp'];
  const signature = headers['x-signature'];

  if (!nodeId || !timestamp || !signature) {
    throw new AppError('Missing required heartbeat headers', 400, 'Bad Request');
  }

  return { nodeId, timestamp, signature };
};

const createHeartbeat = async (req, res, next) => {
  const receivedAt = new Date();
  const ipAddress = getClientIp(req);
  const userAgent = req.get('user-agent') || null;

  try {
    const { nodeId, timestamp, signature } = validateRequiredHeaders(req.headers);

    const payloadValidation = validateHeartbeatPayload(req.body);
    if (!payloadValidation.success) {
      throw new AppError('Invalid heartbeat payload', 422, 'Unprocessable Entity');
    }

    const payload = payloadValidation.data;

    if (payload.node_id !== nodeId) {
      throw new AppError('Header node id does not match payload node id', 400, 'Bad Request');
    }

    const timestampResult = validateTimestampDrift(timestamp);
    if (!timestampResult.ok) {
      await persistInvalidHeartbeat({
        nodeId,
        payload,
        nodeTimestamp: new Date(payload.timestamp),
        ipAddress,
        userAgent,
        reason: timestampResult.reason
      }).catch(() => undefined);

      throw new AppError('Invalid request timestamp', 401, 'Unauthorized');
    }

    const payloadTimestampResult = validateTimestampDrift(payload.timestamp);
    if (!payloadTimestampResult.ok) {
      throw new AppError('Invalid payload timestamp', 422, 'Unprocessable Entity');
    }

    const timestampDelta = Math.abs(
      Math.floor((new Date(timestamp).getTime() - new Date(payload.timestamp).getTime()) / 1000)
    );
    if (timestampDelta > env.heartbeat.allowedDriftSeconds) {
      throw new AppError('Timestamp header and payload are inconsistent', 400, 'Bad Request');
    }

    const signatureResult = verifyRequestSignature({
      rawBody: req.rawBody || '',
      timestamp,
      signature,
      secret: env.heartbeat.hmacSecret
    });

    if (!signatureResult.ok) {
      if (signatureResult.reason === 'HMAC secret is not configured') {
        throw new AppError('Heartbeat secret is not configured', 500, 'Internal Server Error');
      }

      await persistInvalidHeartbeat({
        nodeId,
        payload,
        nodeTimestamp: new Date(payload.timestamp),
        ipAddress,
        userAgent,
        reason: 'Invalid signature'
      }).catch(() => undefined);

      throw new AppError('Invalid heartbeat signature', 401, 'Unauthorized');
    }

    const processed = await processHeartbeat({
      payload,
      receivedAt,
      ipAddress,
      userAgent
    });

    return successResponse(res, {
      message: 'Heartbeat received',
      data: {
        node_id: processed.node_id,
        status: processed.status,
        received_at: processed.received_at
      }
    });
  } catch (error) {
    return next(error);
  }
};

module.exports = {
  createHeartbeat
};
