/* eslint-disable no-console */
const env = require('../config/env');

const isDiscordConfigured = () => {
  return Boolean(env.discord.enabled && env.discord.botToken && env.discord.channelId);
};

const clip = (text, max = 1900) => {
  if (!text) {
    return '';
  }

  if (text.length <= max) {
    return text;
  }

  return `${text.slice(0, max - 3)}...`;
};

const getDownServiceCount = (summary) => {
  if (!summary || !summary.services || !summary.services.by_status) {
    return 0;
  }

  const byStatus = summary.services.by_status;
  return Object.keys(byStatus).reduce((count, status) => {
    if (status.toLowerCase() === 'online') {
      return count;
    }

    return count + Number(byStatus[status] || 0);
  }, 0);
};

const postDiscordMessage = async (content) => {
  if (!isDiscordConfigured()) {
    return { sent: false, reason: 'discord_not_configured' };
  }

  const url = `${env.discord.apiBaseUrl}/channels/${env.discord.channelId}/messages`;
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      Authorization: `Bot ${env.discord.botToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ content: clip(content) })
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`Discord API error ${response.status}: ${clip(body, 300)}`);
  }

  return { sent: true };
};

const sendAlert = async ({ nodeId, fromStatus, toStatus, eventType, message }) => {
  if (!isDiscordConfigured()) {
    console.info('[discord-alert] skipped: not configured');
    return { sent: false, reason: 'discord_not_configured' };
  }

  const lines = [
    '[Server Monitoring Alert]',
    `Node: ${nodeId}`,
    `Type: ${eventType}`,
    `Status: ${fromStatus || 'unknown'} -> ${toStatus}`,
    `Message: ${message}`,
    `Time (UTC): ${new Date().toISOString()}`
  ];

  await postDiscordMessage(lines.join('\n'));
  return { sent: true };
};

const sendReminderAlert = async (node) => {
  if (!isDiscordConfigured()) {
    return { sent: false, reason: 'discord_not_configured' };
  }

  const summary = node.last_summary_json || {};
  const downServices = getDownServiceCount(summary);
  const statusAlertable = ['down', 'degraded'].includes(node.current_status);

  if (!statusAlertable && downServices <= 0) {
    return { sent: false, reason: 'status_not_alertable' };
  }

  const lines = [
    '[Server Monitoring Reminder]',
    `Node: ${node.node_id}`,
    `Current Status: ${node.current_status}`,
    `Last Heartbeat (UTC): ${node.last_heartbeat_at ? new Date(node.last_heartbeat_at).toISOString() : 'never'}`,
    `Timeout Threshold: ${node.timeout_threshold_seconds}s`,
    `Potential Unhealthy Services: ${downServices}`,
    `Time (UTC): ${new Date().toISOString()}`
  ];

  await postDiscordMessage(lines.join('\n'));
  return { sent: true };
};

const sendTestAlert = async () => {
  const lines = [
    '[Server Monitoring Test]',
    'If you can read this, Discord bot delivery is working.',
    `Time (UTC): ${new Date().toISOString()}`
  ];

  await postDiscordMessage(lines.join('\n'));
  return { sent: true };
};

module.exports = {
  isDiscordConfigured,
  postDiscordMessage,
  sendAlert,
  sendReminderAlert,
  sendTestAlert
};
