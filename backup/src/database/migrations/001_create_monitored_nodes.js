module.exports = {
  up: async (queryInterface, Sequelize, transaction) => {
    await queryInterface.createTable(
      'monitored_nodes',
      {
        id: {
          type: Sequelize.BIGINT.UNSIGNED,
          autoIncrement: true,
          primaryKey: true,
          allowNull: false
        },
        node_id: {
          type: Sequelize.STRING(120),
          allowNull: false,
          unique: true
        },
        name: {
          type: Sequelize.STRING(255),
          allowNull: false,
          defaultValue: 'Unnamed Node'
        },
        heartbeat_interval_seconds: {
          type: Sequelize.INTEGER.UNSIGNED,
          allowNull: false,
          defaultValue: 60
        },
        timeout_threshold_seconds: {
          type: Sequelize.INTEGER.UNSIGNED,
          allowNull: false,
          defaultValue: 180
        },
        secret_reference: {
          type: Sequelize.STRING(255),
          allowNull: true
        },
        current_status: {
          type: Sequelize.ENUM('ok', 'degraded', 'down', 'unknown'),
          allowNull: false,
          defaultValue: 'unknown'
        },
        last_heartbeat_at: {
          type: Sequelize.DATE,
          allowNull: true
        },
        last_payload_json: {
          type: Sequelize.JSON,
          allowNull: true
        },
        last_summary_json: {
          type: Sequelize.JSON,
          allowNull: true
        },
        last_alert_at: {
          type: Sequelize.DATE,
          allowNull: true
        },
        created_at: {
          type: Sequelize.DATE,
          allowNull: false,
          defaultValue: Sequelize.literal('CURRENT_TIMESTAMP')
        },
        updated_at: {
          type: Sequelize.DATE,
          allowNull: false,
          defaultValue: Sequelize.literal('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')
        }
      },
      { transaction }
    );

    await queryInterface.addIndex('monitored_nodes', ['node_id'], {
      unique: true,
      name: 'uniq_monitored_nodes_node_id',
      transaction
    });
  },

  down: async (queryInterface, Sequelize, transaction) => {
    await queryInterface.dropTable('monitored_nodes', { transaction });
  }
};
