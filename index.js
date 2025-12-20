/* =========================================================
   RebLaw Proxy + Law API (Integrated)
   ========================================================= */

const express = require("express");
const cors = require("cors");
const fetch = require("node-fetch");
const fs = require("fs");
const path = require("path");
const https = require("https");
const http = require("http");
const sqlite3 = require("sqlite3").verbose();

/* =======================
   Config
   ======================= */

const app = express();
const PORT = process.env.PORT || 3000;

const DB_PATH = process.env.DB_PATH || path.join(__dirname, "iran_laws.db");
const LAW_DB_URL = process.env.LAW_DB_URL || "";

/* =======================
   Middlewares
   ======================= */

app.use(cors());
app.use(express.json({ limit: "1mb" }));

/* =======================
   Health & Root
   ======================= */

app.get("/", (req, res) => res.send("RebLaw AI Proxy is running."));
app.get("/health", (req, res) => res.json({ ok: true }));

/* =========================================================
   🔹 Law DB Bootstrap (NO SSH REQUIRED)
   ========================================================= */

function fileOk(p, minBytes = 1024 * 1024) {
  try {
    return fs.existsSync(p) && fs.statSync(p).size >= minBytes;
  } catch {
    return false;
  }
}

function ensureDir(dir) {
  try {
    fs.mkdirSync(dir, { recursive: true });
  } catch {}
}

function downloadToFile(url, dest) {
  return new Promise((resolve, reject) => {
    const proto = url.startsWith("https") ? https : http;
    ensureDir(path.dirname(dest));

    const file = fs.createWriteStream(dest);
    const req = proto.get(url, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        file.close(() => fs.unlink(dest, () => {}));
        return resolve(downloadToFile(res.headers.location, dest));
      }
      if (res.statusCode !== 200) {
        file.close(() => fs.unlink(dest, () => {}));
        return reject(new Error(`Download failed. HTTP ${res.statusCode}`));
      }
      res.pipe(file);
      file.on("finish", () => file.close(resolve));
    });

    req.on("error", (err) => {
      file.close(() => fs.unlink(dest, () => {}));
      reject(err);
    });
  });
}

async function bootstrapLawDb() {
  console.log("[LawDB] DB_PATH =", DB_PATH);
  console.log("[LawDB] /data exists?", fs.existsSync("/data"));

  if (fileOk(DB_PATH)) {
    console.log("[LawDB] DB already present. size =", fs.statSync(DB_PATH).size);
    return;
  }

  if (!LAW_DB_URL) {
    console.log("[LawDB] DB missing and LAW_DB_URL not set.");
    return;
  }

  console.log("[LawDB] Downloading DB from LAW_DB_URL ...");
  await downloadToFile(LAW_DB_URL, DB_PATH);

  if (!fileOk(DB_PATH)) {
    throw new Error("[LawDB] Downloaded DB invalid.");
  }

  console.log("[LawDB] Download complete. size =", fs.statSync(DB_PATH).size);
}

/* =========================================================
   🔹 Law API (Article by Name)
   ========================================================= */

function normalizeLawName(s) {
  return (s || "")
    .toString()
    .trim()
    .replace(/\u200c/g, " ")
    .replace(/\s+/g, " ");
}

function openDb() {
  return new sqlite3.Database(DB_PATH);
}

function dbGet(db, sql, params = []) {
  return new Promise((resolve, reject) => {
    db.get(sql, params, (err, row) => (err ? reject(err) : resolve(row)));
  });
}

/* Fallback aliases (can later move to DB table) */
const FALLBACK_ALIASES = new Map([
  ["قانون مجازات اسلامی", "حقوق_جزا"],
  ["ق.م.ا", "حقوق_جزا"],
  ["مجازات اسلامی", "حقوق_جزا"],
  ["Islamic Penal Code", "حقوق_جزا"],
  ["Iran Penal Code", "حقوق_جزا"],
]);

async function resolveLawCode(db, lawName) {
  const name = normalizeLawName(lawName);
  if (!name) return null;

  // direct code match
  const direct = await dbGet(
    db,
    "SELECT 1 FROM articles WHERE code=? LIMIT 1",
    [name]
  );
  if (direct) return name;

  // alias table (optional)
  try {
    const row = await dbGet(
      db,
      "SELECT law_code FROM law_aliases WHERE alias=? LIMIT 1",
      [name]
    );
    if (row?.law_code) return row.law_code;
  } catch {}

  // fallback map
  return FALLBACK_ALIASES.get(name) || null;
}

app.post("/api/article-by-name", async (req, res) => {
  res.setHeader("Content-Type", "application/json; charset=utf-8");

  try {
    const law_name = normalizeLawName(req.body?.law_name);
    const article_number = Number(req.body?.article_number);

    if (!law_name || !Number.isInteger(article_number)) {
      return res.json({ success: false, error: "پارامترها نامعتبر است." });
    }

    if (!fileOk(DB_PATH)) {
      return res.json({ success: false, error: "DB قوانین در دسترس نیست." });
    }

    const db = openDb();
    try {
      const law_code = await resolveLawCode(db, law_name);
      if (!law_code) {
        return res.json({ success: false, error: "نام قانون ناشناخته است." });
      }

      const row = await dbGet(
        db,
        "SELECT text FROM articles WHERE code=? AND id=? LIMIT 1",
        [law_code, article_number]
      );

      if (!row?.text) {
        return res.json({ success: false, error: "متن ماده یافت نشد." });
      }

      return res.json({
        success: true,
        law_name,
        law_code,
        article_number,
        text: row.text,
        source: "RebLaw DB",
      });
    } finally {
      db.close();
    }
  } catch (e) {
    console.error("[LawAPI]", e);
    return res.status(500).json({ success: false, error: "خطای داخلی Law API" });
  }
});

/* =========================================================
   🔹 Existing AI Proxy (/ask)
   ========================================================= */

function normalizeIncoming(reqBody) {
  const body = reqBody || {};

  if (Array.isArray(body.messages) && body.messages.length) {
    return { messages: body.messages, meta: body.meta || {} };
  }

  if (typeof body.question === "string" && body.question.trim()) {
    const systemPrompt = `You are RebLaw, a professional legal assistant.
Always respond in the same language as the user (Persian/Farsi, Kurdish, or English).
Be clear, structured, and practical.`;

    return {
      messages: [
        { role: "system", content: systemPrompt },
        { role: "user", content: body.question.trim() },
      ],
      meta: body.meta || {},
    };
  }

  return null;
}

async function callOpenAI({ model, messages, temperature }) {
  const resp = await fetch("https://api.openai.com/v1/chat/completions", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${process.env.OPENAI_API_KEY}`,
    },
    body: JSON.stringify({ model, messages, temperature }),
  });

  const data = await resp.json();
  return { ok: resp.ok, status: resp.status, data };
}

async function handleAsk(req, res) {
  const normalized = normalizeIncoming(req.body);

  if (!normalized) {
    return res.status(400).json({ success: false, message: "Invalid payload" });
  }

  const model = process.env.OPENAI_MODEL || "gpt-3.5-turbo";

  try {
    const { ok, data } = await callOpenAI({
      model,
      messages: normalized.messages,
      temperature: 0.2,
    });

    const answer = data?.choices?.[0]?.message?.content?.trim();
    if (!ok || !answer) throw new Error("AI error");

    return res.json({ success: true, answer });
  } catch (e) {
    console.error("[Proxy]", e);
    return res.status(500).json({ success: false, message: "Proxy error" });
  }
}

app.post("/ask", handleAsk);
app.post("/api/ask", handleAsk);

/* =========================================================
   🔹 Start Server (with DB bootstrap)
   ========================================================= */

bootstrapLawDb()
  .then(() => {
    app.listen(PORT, "0.0.0.0", () => {
      console.log(`RebLaw Proxy + Law API running on port ${PORT}`);
    });
  })
  .catch((e) => {
    console.error("[BOOT] Failed:", e.message);
    process.exit(1);
  });
