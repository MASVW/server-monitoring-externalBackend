/* eslint-disable no-console */
const { sequelize } = require('../models');
const { runOnce } = require('./discordReminderJob');

const main = async () => {
  try {
    await sequelize.authenticate();
    await runOnce();
  } catch (error) {
    console.error('[discord-reminder-cli] failed:', error.message);
    process.exitCode = 1;
  } finally {
    await sequelize.close();
  }
};

main();
