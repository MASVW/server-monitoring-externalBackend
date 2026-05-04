const { z } = require('zod');
const { NODE_STATUSES } = require('../constants/status');

const serviceSchema = z
  .object({
    name: z.string().min(1),
    status: z.string().min(1),
    pm_id: z.number().int().optional(),
    restart_count: z.number().int().optional(),
    cpu: z.number().optional(),
    memory_mb: z.number().optional()
  })
  .passthrough();

const heartbeatPayloadSchema = z
  .object({
    node_id: z.string().min(1),
    timestamp: z.string().datetime(),
    overall_status: z.enum(NODE_STATUSES),
    host: z.record(z.any()),
    services: z.array(serviceSchema),
    problems: z.array(z.any()).optional()
  })
  .passthrough();

const validateHeartbeatPayload = (payload) => {
  return heartbeatPayloadSchema.safeParse(payload);
};

module.exports = {
  validateHeartbeatPayload
};
