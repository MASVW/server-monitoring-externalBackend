const { z } = require('zod');
const { NODE_STATUSES, INCIDENT_EVENT_TYPES } = require('../constants/status');

const querySchema = z.object({
  node_id: z.string().optional(),
  page: z.coerce.number().int().min(1).default(1),
  limit: z.coerce.number().int().min(1).max(100).default(20),
  status: z.enum(NODE_STATUSES).optional(),
  event_type: z.enum(INCIDENT_EVENT_TYPES).optional()
});

const validateIncidentsQuery = (query) => {
  return querySchema.safeParse(query);
};

module.exports = {
  validateIncidentsQuery
};
