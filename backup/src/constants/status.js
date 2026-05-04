const NODE_STATUSES = ['ok', 'degraded', 'down', 'unknown'];

const INCIDENT_EVENT_TYPES = [
  'degraded',
  'down',
  'recovered',
  'heartbeat_received',
  'timeout_detected'
];

module.exports = {
  NODE_STATUSES,
  INCIDENT_EVENT_TYPES
};
