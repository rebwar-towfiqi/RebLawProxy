// migrate_aliases.js
const sqlite3 = require("sqlite3").verbose();
const path = require("path");

const DB_PATH = process.env.DB_PATH || path.join(__dirname, "iran_laws.db");

const db = new sqlite3.Database(DB_PATH);

db.serialize(() => {
  db.run(`
    CREATE TABLE IF NOT EXISTS law_aliases (
      alias TEXT PRIMARY KEY,
      law_code TEXT NOT NULL
    )
  `);

  db.run(`CREATE INDEX IF NOT EXISTS idx_law_aliases_code ON law_aliases(law_code)`);

  const stmt = db.prepare(`INSERT OR REPLACE INTO law_aliases(alias, law_code) VALUES (?, ?)`);

  const rows = [
    ["قانون مجازات اسلامی", "حقوق_جزا"],
    ["ق.م.ا", "حقوق_جزا"],
    ["مجازات اسلامی", "حقوق_جزا"],
    ["Islamic Penal Code", "حقوق_جزا"],
    ["Iran Penal Code", "حقوق_جزا"],
  ];

  for (const [alias, code] of rows) stmt.run(alias, code);

  stmt.finalize();
});

db.close(() => console.log("Migration complete:", DB_PATH));
