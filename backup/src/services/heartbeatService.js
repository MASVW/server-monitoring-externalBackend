const { MonitoredNode, HeartbeatEvent, sequelize } = require('../models');
const { resolveHeartbeatTransition } = require('./stateTransitionService');
const { createIncident } = require('./incidentService');
const { sendAlert } = require('./alertService');

const buildServicesSummary = (services = []) => {
  const byStatus = services.reduce((acc, service) => {
    const key = service.status || 'unknown';
    acc[key] = (acc[key] || 0) + 1;
    return acc;
  }, {});

  return {
    total: services.length,
    by_status: byStatus,
    list: services
  };
};

const buildSummary = (payload) => {
  return {
    host: payload.host || {},
    services: buildServicesSummary(payload.services || []),
    problems: payload.problems || []
  };
};

const persistInvalidHeartbeat = async ({
  nodeId,
  payload,
  nodeTimestamp,
  ipAddress,
  userAgent,
  reason
}) => {
  const now = new Date();

  await HeartbeatEvent.create({
    node_id: nodeId || 'unknown',
    received_at: now,
    node_timestamp: nodeTimestamp || now,
    status: 'unknown',
    payload_json: {
      reason,
      payload: payload || null
    },
    signature_valid: false,
    ip_address: ipAddress,
    user_agent: userAgent
  });
};

const processHeartbeat = async ({ payload, receivedAt, ipAddress, userAgent }) => {
  const incidentToNotify = await sequelize.transaction(async (transaction) => {
    let node = await MonitoredNode.findOne({
      where: { node_id: payload.node_id },
      transaction,
      lock: transaction.LOCK.UPDATE
    });

    if (!node) {
      node = await MonitoredNode.create(
        {
          node_id: payload.node_id,
          name: payload.node_id,
          current_status: 'unknown',
          secret_reference: 'env:HEARTBEAT_HMAC_SECRET'
        },
        { transaction }
      );
    }

    const previousStatus = node.current_status;
    const nextStatus = payload.overall_status;
    const summary = buildSummary(payload);

    await HeartbeatEvent.create(
      {
        node_id: payload.node_id,
        received_at: receivedAt,
        node_timestamp: new Date(payload.timestamp),
        status: nextStatus,
        payload_json: payload,
        signature_valid: true,
        ip_address: ipAddress,
        user_agent: userAgent
      },
      { transaction }
    );

    await node.update(
      {
        current_status: nextStatus,
        last_heartbeat_at: receivedAt,
        last_payload_json: payload,
        last_summary_json: summary,
        secret_reference: node.secret_reference || 'env:HEARTBEAT_HMAC_SECRET'
      },
      { transaction }
    );

    const transition = resolveHeartbeatTransition(previousStatus, nextStatus);

    if (!transition) {
      return null;
    }

    await node.update(
      {
        last_alert_at: receivedAt
      },
      { transaction }
    );

    const incident = await createIncident({
      nodeId: payload.node_id,
      fromStatus: previousStatus,
      toStatus: nextStatus,
      eventType: transition.event_type,
      message: transition.message,
      metadata: {
        source: 'heartbeat',
        node_timestamp: payload.timestamp
      },
      occurredAt: receivedAt,
      transaction
    });

    return {
      id: incident.id,
      nodeId: payload.node_id,
      fromStatus: previousStatus,
      toStatus: nextStatus,
      eventType: transition.event_type,
      message: transition.message
    };
  });

  if (incidentToNotify) {
    try {
      await sendAlert(incidentToNotify);
    } catch (error) {
      // Do not fail heartbeat ingestion due to outbound alert error.
      // eslint-disable-next-line no-console
      console.error(`[discord-alert] failed for node=${incidentToNotify.nodeId}: ${error.message}`);
    }
  }

  return {
    node_id: payload.node_id,
    status: payload.overall_status,
    received_at: receivedAt.toISOString()
  };
};

module.exports = {
  processHeartbeat,
  persistInvalidHeartbeat,
  buildSummary,
  buildServicesSummary
};
