/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');
const sequelize = require('../config/database');

const MIGRATIONS_DIR = path.join(__dirname, 'migrations');
const isUndo = process.argv.includes('--undo');

const ensureMigrationsTable = async () => {
  await sequelize.query(`
    CREATE TABLE IF NOT EXISTS schema_migrations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL UNIQUE,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  `);
};

const getAppliedMigrations = async () => {
  const [rows] = await sequelize.query('SELECT name FROM schema_migrations ORDER BY id ASC');
  return rows.map((row) => row.name);
};

const run = async () => {
  await sequelize.authenticate();
  await ensureMigrationsTable();

  const files = fs
    .readdirSync(MIGRATIONS_DIR)
    .filter((file) => file.endsWith('.js'))
    .sort();

  const applied = await getAppliedMigrations();

  if (isUndo) {
    const last = applied[applied.length - 1];
    if (!last) {
      console.log('No migration to undo.');
      return;
    }

    const migration = require(path.join(MIGRATIONS_DIR, last));
    await sequelize.transaction(async (transaction) => {
      await migration.down(sequelize.getQueryInterface(), sequelize.Sequelize, transaction);
      await sequelize.query('DELETE FROM schema_migrations WHERE name = ?', {
        replacements: [last],
        transaction
      });
    });

    console.log(`Undone migration: ${last}`);
    return;
  }

  const pending = files.filter((file) => !applied.includes(file));

  if (pending.length === 0) {
    console.log('No pending migrations.');
    return;
  }

  for (const file of pending) {
    const migration = require(path.join(MIGRATIONS_DIR, file));
    await sequelize.transaction(async (transaction) => {
      await migration.up(sequelize.getQueryInterface(), sequelize.Sequelize, transaction);
      await sequelize.query('INSERT INTO schema_migrations (name) VALUES (?)', {
        replacements: [file],
        transaction
      });
    });
    console.log(`Applied migration: ${file}`);
  }
};

run()
  .catch((error) => {
    console.error('Migration failed:', error.message);
    process.exitCode = 1;
  })
  .finally(async () => {
    await sequelize.close();
  });
