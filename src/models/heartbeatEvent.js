const { DataTypes } = require('sequelize');
const { NODE_STATUSES } = require('../constants/status');

module.exports = (sequelize) => {
  const HeartbeatEvent = sequelize.define(
    'HeartbeatEvent',
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
      received_at: {
        type: DataTypes.DATE,
        allowNull: false
      },
      node_timestamp: {
        type: DataTypes.DATE,
        allowNull: false
      },
      status: {
        type: DataTypes.ENUM(...NODE_STATUSES),
        allowNull: false
      },
      payload_json: {
        type: DataTypes.JSON,
        allowNull: true
      },
      signature_valid: {
        type: DataTypes.BOOLEAN,
        allowNull: false,
        defaultValue: false
      },
      ip_address: {
        type: DataTypes.STRING(120),
        allowNull: true
      },
      user_agent: {
        type: DataTypes.STRING(500),
        allowNull: true
      }
    },
    {
      tableName: 'heartbeat_events',
      underscored: true
    }
  );

  return HeartbeatEvent;
};
