const resolveHeartbeatTransition = (fromStatus, toStatus) => {
  if (fromStatus === toStatus) {
    return null;
  }

  if (toStatus === 'ok' && ['unknown', 'down', 'degraded'].includes(fromStatus)) {
    return {
      event_type: 'recovered',
      message: `Node recovered from ${fromStatus} to ok`
    };
  }

  if (toStatus === 'degraded' && fromStatus === 'ok') {
    return {
      event_type: 'degraded',
      message: 'Node transitioned from ok to degraded'
    };
  }

  if (toStatus === 'down' && fromStatus !== 'down') {
    return {
      event_type: 'down',
      message: `Node transitioned from ${fromStatus} to down`
    };
  }

  return null;
};

const resolveTimeoutTransition = (fromStatus) => {
  if (fromStatus === 'down') {
    return null;
  }

  if (['ok', 'degraded'].includes(fromStatus)) {
    return {
      event_type: 'timeout_detected',
      to_status: 'down',
      message: 'Timeout detected: heartbeat missing beyond threshold'
    };
  }

  return null;
};

module.exports = {
  resolveHeartbeatTransition,
  resolveTimeoutTransition
};
