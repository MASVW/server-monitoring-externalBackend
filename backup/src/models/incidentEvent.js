const { DataTypes } = require('sequelize');
const { NODE_STATUSES, INCIDENT_EVENT_TYPES } = require('../constants/status');

module.exports = (sequelize) => {
  const IncidentEvent = sequelize.define(
    'IncidentEvent',
    {
      id: {
        type: DataTypes.BIGINT.UNSIGNED,
        autoIncrement: true,
        primaryKey: true
      },
      node_id: {
        type: DataTypes.STRING(120),
        allowNull: false
      },
      from_status: {
        type: DataTypes.ENUM(...NODE_STATUSES),
        allowNull: true
      },
      to_status: {
        type: DataTypes.ENUM(...NODE_STATUSES),
        allowNull: false
      },
      event_type: {
        type: DataTypes.ENUM(...INCIDENT_EVENT_TYPES),
        allowNull: false
      },
      message: {
        type: DataTypes.STRING(500),
        allowNull: false
      },
      metadata_json: {
        type: DataTypes.JSON,
        allowNull: true
      },
      occurred_at: {
        type: DataTypes.DATE,
        allowNull: false
      }
    },
    {
      tableName: 'incident_events',
      underscored: true
    }
  );

  return IncidentEvent;
};
