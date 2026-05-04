/* eslint-disable no-console */
const app = require('./app');
const env = require('./config/env');
const { sequelize } = require('./models');
const { startTimeoutChecker } = require('./jobs/timeoutCheckerJob');
const { startDiscordReminderJob } = require('./jobs/discordReminderJob');

const start = async () => {
  try {
    await sequelize.authenticate();

    app.listen(env.app.port, () => {
      console.info(`External receiver listening on port ${env.app.port}`);
      console.info(
        `[discord-alert] enabled=${env.discord.enabled} configured=${Boolean(env.discord.botToken && env.discord.channelId)} interval=${env.discord.alertIntervalSeconds}s`
      );
      startTimeoutChecker();
      startDiscordReminderJob();
    });
  } catch (error) {
    console.error('Unable to start service:', error.message);
    process.exit(1);
  }
};

start();
