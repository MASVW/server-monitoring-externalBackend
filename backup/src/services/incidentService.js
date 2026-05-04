const { IncidentEvent } = require('../models');

const createIncident = async ({
  nodeId,
  fromStatus,
  toStatus,
  eventType,
  message,
  metadata,
  occurredAt,
  transaction
}) => {
  return IncidentEvent.create(
    {
      node_id: nodeId,
      from_status: fromStatus,
      to_status: toStatus,
      event_type: eventType,
      message,
      metadata_json: metadata || null,
      occurred_at: occurredAt || new Date()
    },
    { transaction }
  );
};

const getPaginatedIncidents = async ({ filters, page, limit }) => {
  const where = {};

  if (filters.node_id) {
    where.node_id = filters.node_id;
  }

  if (filters.status) {
    where.to_status = filters.status;
  }

  if (filters.event_type) {
    where.event_type = filters.event_type;
  }

  const offset = (page - 1) * limit;

  const { rows, count } = await IncidentEvent.findAndCountAll({
    where,
    offset,
    limit,
    order: [['occurred_at', 'DESC'], ['id', 'DESC']]
  });

  return {
    data: rows,
    meta: {
      page,
      limit,
      total: count,
      total_pages: Math.ceil(count / limit)
    }
  };
};

module.exports = {
  createIncident,
  getPaginatedIncidents
};
