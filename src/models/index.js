const sequelize = require('../config/database');
const buildMonitoredNode = require('./monitoredNode');
const buildHeartbeatEvent = require('./heartbeatEvent');
const buildIncidentEvent = require('./incidentEvent');

const MonitoredNode = buildMonitoredNode(sequelize);
const HeartbeatEvent = buildHeartbeatEvent(sequelize);
const IncidentEvent = buildIncidentEvent(sequelize);

module.exports = {
  sequelize,
  MonitoredNode,
  HeartbeatEvent,
  IncidentEvent
};
