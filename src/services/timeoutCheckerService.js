const { Op } = require('sequelize');
const { MonitoredNode, sequelize } = require('../models');
const { resolveTimeoutTransition } = require('./stateTransitionService');
const { createIncident } = require('./incidentService');
const { sendAlert } = require('./alertService');

const secondsSince = (date, now) => {
  return Math.floor((now.getTime() - new Date(date).getTime()) / 1000);
};

const checkTimeouts = async () => {
  const now = new Date();

  const candidates = await MonitoredNode.findAll({
    where: {
      current_status: { [Op.in]: ['ok', 'degraded'] },
      last_heartbeat_at: { [Op.ne]: null }
    }
  });

  const results = {
    checked: candidates.length,
    marked_down: 0
  };

  for (const candidate of candidates) {
    const elapsed = secondsSince(candidate.last_heartbeat_at, now);
    const threshold = candidate.timeout_threshold_seconds || 180;

    if (elapsed <= threshold) {
      continue;
    }

    const incidentToNotify = await sequelize.transaction(async (transaction) => {
      const node = await MonitoredNode.findOne({
        where: { id: candidate.id },
        transaction,
        lock: transaction.LOCK.UPDATE
      });

      if (!node || node.current_status === 'down') {
        return null;
      }

      const transition = resolveTimeoutTransition(node.current_status);
      if (!transition) {
        return null;
      }

      const previousStatus = node.current_status;

      await node.update(
        {
          current_status: 'down',
          last_alert_at: now
        },
        { transaction }
      );

      const incident = await createIncident({
        nodeId: node.node_id,
        fromStatus: previousStatus,
        toStatus: transition.to_status,
        eventType: transition.event_type,
        message: `${transition.message} (${elapsed}s > ${threshold}s)`,
        metadata: {
          source: 'timeout_checker',
          elapsed_seconds: elapsed,
          threshold_seconds: threshold
        },
        occurredAt: now,
        transaction
      });

      return {
        id: incident.id,
        nodeId: node.node_id,
        fromStatus: previousStatus,
        toStatus: transition.to_status,
        eventType: transition.event_type,
        message: transition.message
      };
    });

    if (incidentToNotify) {
      results.marked_down += 1;
      await sendAlert(incidentToNotify);
    }
  }

  return results;
};

module.exports = {
  checkTimeouts
};
