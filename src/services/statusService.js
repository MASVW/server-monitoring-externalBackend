const { MonitoredNode } = require('../models');

const getNodeStatus = async (nodeId) => {
  const node = await MonitoredNode.findOne({
    where: { node_id: nodeId }
  });

  if (!node) {
    return null;
  }

  return {
    node_id: node.node_id,
    current_status: node.current_status,
    last_heartbeat_at: node.last_heartbeat_at ? new Date(node.last_heartbeat_at).toISOString() : null,
    heartbeat_interval_seconds: node.heartbeat_interval_seconds,
    timeout_threshold_seconds: node.timeout_threshold_seconds,
    summary: node.last_summary_json || {
      host: {},
      services: {}
    }
  };
};

module.exports = {
  getNodeStatus
};
