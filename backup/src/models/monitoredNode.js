const { DataTypes } = require('sequelize');
const { NODE_STATUSES } = require('../constants/status');

module.exports = (sequelize) => {
  const MonitoredNode = sequelize.define(
    'MonitoredNode',
    {
      id: {
        type: DataTypes.BIGINT.UNSIGNED,
        autoIncrement: true,
        primaryKey: true
      },
      node_id: {
        type: DataTypes.STRING(120),
        allowNull: false,
        unique: true
      },
      name: {
        type: DataTypes.STRING(255),
        allowNull: false,
        defaultValue: 'Unnamed Node'
      },
      heartbeat_interval_seconds: {
        type: DataTypes.INTEGER.UNSIGNED,
        allowNull: false,
        defaultValue: 60
      },
      timeout_threshold_seconds: {
        type: DataTypes.INTEGER.UNSIGNED,
        allowNull: false,
        defaultValue: 180
      },
      secret_reference: {
        type: DataTypes.STRING(255),
        allowNull: true
      },
      current_status: {
        type: DataTypes.ENUM(...NODE_STATUSES),
        allowNull: false,
        defaultValue: 'unknown'
      },
      last_heartbeat_at: {
        type: DataTypes.DATE,
        allowNull: true
      },
      last_payload_json: {
        type: DataTypes.JSON,
        allowNull: true
      },
      last_summary_json: {
        type: DataTypes.JSON,
        allowNull: true
      },
      last_alert_at: {
        type: DataTypes.DATE,
        allowNull: true
      }
    },
    {
      tableName: 'monitored_nodes',
      underscored: true
    }
  );

  return MonitoredNode;
};
