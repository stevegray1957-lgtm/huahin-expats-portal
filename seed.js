'use strict';
require('dotenv').config();
const path = require('path');
const fs = require('fs');
const db = require('./database');

const DATA_FILE = path.join(__dirname, 'data', 'directory.json');

async function seed() {
  if (!fs.existsSync(DATA_FILE)) {
    console.error('ERROR: data/directory.json not found.');
    process.exit(1);
  }

  const listings = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));

  console.log('Initialising schema…');
  await db.initSchema();

  console.log(`Seeding ${listings.length} listings…`);
  for (const item of listings) {
    await db.upsertListing(item);
  }

  const cats = await db.getCategories();
  const total = cats.reduce((s, c) => s + c.count, 0);
  console.log(`✅ Seeded ${total} listings across ${cats.length} categories.`);
  process.exit(0);
}

seed().catch(err => { console.error(err); process.exit(1); });
