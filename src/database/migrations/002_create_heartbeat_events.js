module.exports = {
  up: async (queryInterface, Sequelize, transaction) => {
    await queryInterface.createTable(
      'heartbeat_events',
      {
        id: {
          type: Sequelize.BIGINT.UNSIGNED,
          autoIncrement: true,
          primaryKey: true,
          allowNull: false
        },
        node_id: {
          type: Sequelize.STRING(120),
          allowNull: false
        },
        received_at: {
          type: Sequelize.DATE,
          allowNull: false
        },
        node_timestamp: {
          type: Sequelize.DATE,
          allowNull: false
        },
        status: {
          type: Sequelize.ENUM('ok', 'degraded', 'down', 'unknown'),
          allowNull: false
        },
        payload_json: {
          type: Sequelize.JSON,
          allowNull: true
        },
        signature_valid: {
          type: Sequelize.BOOLEAN,
          allowNull: false,
          defaultValue: false
        },
        ip_address: {
          type: Sequelize.STRING(120),
          allowNull: true
        },
        user_agent: {
          type: Sequelize.STRING(500),
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

    await queryInterface.addIndex('heartbeat_events', ['node_id', 'received_at'], {
      name: 'idx_heartbeat_events_node_received',
      transaction
    });
  },

  down: async (queryInterface, Sequelize, transaction) => {
    await queryInterface.dropTable('heartbeat_events', { transaction });
  }
};
