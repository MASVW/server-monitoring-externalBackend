module.exports = {
  up: async (queryInterface, Sequelize, transaction) => {
    await queryInterface.createTable(
      'incident_events',
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
        from_status: {
          type: Sequelize.ENUM('ok', 'degraded', 'down', 'unknown'),
          allowNull: true
        },
        to_status: {
          type: Sequelize.ENUM('ok', 'degraded', 'down', 'unknown'),
          allowNull: false
        },
        event_type: {
          type: Sequelize.ENUM('degraded', 'down', 'recovered', 'heartbeat_received', 'timeout_detected'),
          allowNull: false
        },
        message: {
          type: Sequelize.STRING(500),
          allowNull: false
        },
        metadata_json: {
          type: Sequelize.JSON,
          allowNull: true
        },
        occurred_at: {
          type: Sequelize.DATE,
          allowNull: false
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

    await queryInterface.addIndex('incident_events', ['node_id', 'occurred_at'], {
      name: 'idx_incident_events_node_occurred',
      transaction
    });

    await queryInterface.addIndex('incident_events', ['event_type'], {
      name: 'idx_incident_events_type',
      transaction
    });
  },

  down: async (queryInterface, Sequelize, transaction) => {
    await queryInterface.dropTable('incident_events', { transaction });
  }
};
