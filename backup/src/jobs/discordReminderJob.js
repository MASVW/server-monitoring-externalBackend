/* eslint-disable no-console */
const env = require('../config/env');
const { MonitoredNode } = require('../models');
const { isDiscordConfigured, sendReminderAlert } = require('../services/alertService');

let timer = null;

const shouldSendReminder = (node, now) => {
  if (!node.last_alert_at) {
    return true;
  }

  const elapsedSeconds = Math.floor((now.getTime() - new Date(node.last_alert_at).getTime()) / 1000);
  return elapsedSeconds >= env.discord.alertIntervalSeconds;
};

const hasUnhealthyServices = (summary) => {
  if (!summary || !summary.services || !summary.services.by_status) {
    return false;
  }

  const byStatus = summary.services.by_status;
  return Object.keys(byStatus).some((status) => {
    if (status.toLowerCase() === 'online') {
      return false;
    }

    return Number(byStatus[status] || 0) > 0;
  });
};

const shouldAlertNode = (node) => {
  if (['down', 'degraded'].includes(node.current_status)) {
    return true;
  }

  return hasUnhealthyServices(node.last_summary_json);
};

const runOnce = async () => {
  if (!isDiscordConfigured()) {
    return;
  }

  const now = new Date();

  const nodes = await MonitoredNode.findAll();

  let sentCount = 0;

  for (const node of nodes) {
    if (!shouldAlertNode(node)) {
      continue;
    }

    if (!shouldSendReminder(node, now)) {
      continue;
    }

    try {
      const result = await sendReminderAlert(node);
      if (result.sent) {
        sentCount += 1;
        await node.update({ last_alert_at: now });
      }
    } catch (error) {
      console.error(`[discord-reminder] failed for node=${node.node_id}: ${error.message}`);
    }
  }

  console.info(`[discord-reminder] checked=${nodes.length} sent=${sentCount} at=${now.toISOString()}`);
};

const startDiscordReminderJob = () => {
  if (!isDiscordConfigured()) {
    console.info('[discord-reminder] disabled: not configured');
    return;
  }

  if (timer) {
    return;
  }

  const intervalMs = env.discord.alertIntervalSeconds * 1000;
  timer = setInterval(runOnce, intervalMs);
  timer.unref();

  console.info(`[discord-reminder] started with interval=${env.discord.alertIntervalSeconds}s`);
};

const stopDiscordReminderJob = () => {
  if (!timer) {
    return;
  }

  clearInterval(timer);
  timer = null;
};

module.exports = {
  runOnce,
  startDiscordReminderJob,
  stopDiscordReminderJob
};
