/**
 * RebLaw AI Proxy
 * Stable, production-ready version
 * Compatible with Node.js 18+ (including v22)
 */

const express = require("express");
const cors = require("cors");

const app = express();

/* =========================
   Basic middleware
========================= */
app.use(cors());
app.use(express.json({ limit: "1mb" }));

/* =========================
   Environment & constants
========================= */
const PORT = process.env.PORT || 3000;
const DEFAULT_MODEL = "gpt-4.1-mini";

/* =========================
   Health & root
========================= */
app.get("/", (req, res) => {
  res.send("RebLaw AI Proxy is running.");
});

app.get("/health", (req, res) => {
  res.json({ ok: true });
});

/* =========================
   Optional proxy auth
   Header:
   Authorization: Bearer <REBLAW_AI_PROXY_TOKEN>
========================= */
function checkAuth(req) {
  const required = (process.env.REBLAW_AI_PROXY_TOKEN || "").trim();
  if (!required) return { ok: true };

  const auth = (req.headers.authorization || "").trim();
  if (!auth.startsWith("Bearer ")) {
    return { ok: false, status: 401, error: "Missing Authorization header" };
  }

  const token = auth.slice("Bearer ".length).trim();
  if (token !== required) {
    return { ok: false, status: 403, error: "Invalid proxy token" };
  }

  return { ok: true };
}

/* =========================
   Normalize incoming payload
========================= */
function normalizeIncoming(body) {
  const reqBody = body || {};

  // OpenAI-style payload
  if (Array.isArray(reqBody.messages) && reqBody.messages.length) {
    return {
      model:
        typeof reqBody.model === "string" && reqBody.model.trim()
          ? reqBody.model.trim()
          : null,
      messages: reqBody.messages,
      temperature:
        typeof reqBody.temperature === "number"
          ? reqBody.temperature
          : null,
      max_tokens:
        typeof reqBody.max_tokens === "number" ? reqBody.max_tokens : null,
      top_p: typeof reqBody.top_p === "number" ? reqBody.top_p : null,
      presence_penalty:
        typeof reqBody.presence_penalty === "number"
          ? reqBody.presence_penalty
          : null,
      frequency_penalty:
        typeof reqBody.frequency_penalty === "number"
          ? reqBody.frequency_penalty
          : null,
      response_format:
        reqBody.response_format && typeof reqBody.response_format === "object"
          ? reqBody.response_format
          : null,
    };
  }

  // Simple clients: { question: "..." }
  if (typeof reqBody.question === "string" && reqBody.question.trim()) {
    const systemPrompt = `You are RebLaw, a professional legal assistant.
Always respond in the same language as the user (Persian/Farsi, Kurdish, or English).
Be clear, structured, and practical.`;

    return {
      model: null,
      temperature: null,
      max_tokens: null,
      top_p: null,
      presence_penalty: null,
      response_format: null,
      frequency_penalty: null,
      messages: [
        { role: "system", content: systemPrompt },
        { role: "user", content: reqBody.question.trim() },
      ],
    };
  }

  return null;
}

/* =========================
   Call OpenAI
   (uses native fetch in Node 18+)
========================= */
async function callOpenAI(payload) {
  const response = await fetch("https://api.openai.com/v1/chat/completions", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${process.env.OPENAI_API_KEY}`,
    },
    body: JSON.stringify(payload),
  });

  const raw = await response.text();

  let json = null;
  try {
    json = JSON.parse(raw);
  } catch (_) {
    // Non-JSON response (HTML, WAF, etc.)
  }

  return {
    ok: response.ok,
    status: response.status,
    json,
    raw,
  };
}

/* =========================
   Extract AI content safely
========================= */
function extractContent(json) {
  if (!json || typeof json !== "object") return "";

  const c1 = json?.choices?.[0]?.message?.content;
  if (typeof c1 === "string" && c1.trim()) return c1.trim();

  const c2 = json?.content;
  if (typeof c2 === "string" && c2.trim()) return c2.trim();

  const c3 = json?.answer;
  if (typeof c3 === "string" && c3.trim()) return c3.trim();

  return "";
}

/* =========================
   Error helper
========================= */
function errJson(res, status, trace_id, message, extra = {}) {
  return res.status(status).json({
    success: false,
    ok: false,
    trace_id,
    error: message,
    ...extra,
  });
}

/* =========================
   Main handler
========================= */
async function handleAsk(req, res) {
  const trace_id = Date.now().toString(36);

  const auth = checkAuth(req);
  if (!auth.ok) {
    return errJson(res, auth.status, trace_id, auth.error);
  }

  if (!process.env.OPENAI_API_KEY) {
    return errJson(
      res,
      500,
      trace_id,
      "OPENAI_API_KEY is not set on the server"
    );
  }

  const normalized = normalizeIncoming(req.body);
  if (!normalized) {
    return errJson(
      res,
      400,
      trace_id,
      'Invalid payload. Send {messages:[...]} or {question:"..."}'
    );
  }

  const model =
    normalized.model ||
    (process.env.OPENAI_MODEL && process.env.OPENAI_MODEL.trim()) ||
    DEFAULT_MODEL;

  const payload = {
    model,
    messages: normalized.messages,
    temperature:
      normalized.temperature !== null
        ? normalized.temperature
        : process.env.OPENAI_TEMPERATURE
        ? Number(process.env.OPENAI_TEMPERATURE)
        : 0.2,
  };

  if (normalized.max_tokens !== null)
    payload.max_tokens = normalized.max_tokens;
  if (normalized.top_p !== null) payload.top_p = normalized.top_p;
  if (normalized.presence_penalty !== null)
    payload.presence_penalty = normalized.presence_penalty;
  if (normalized.frequency_penalty !== null)
    payload.frequency_penalty = normalized.frequency_penalty;

  if (normalized.response_format !== null) {
    payload.response_format = normalized.response_format;
  }

  try {
    const { ok, status, json, raw } = await callOpenAI(payload);

    if (!ok) {
      return errJson(res, status, trace_id, "OpenAI API error", {
        upstream_status: status,
        details: json || raw.slice(0, 800),
      });
    }

    const content = extractContent(json);
    if (!content) {
      return errJson(res, 502, trace_id, "No usable content from upstream", {
        raw_snippet: raw.slice(0, 800),
      });
    }

    return res.json({
      success: true,
      ok: true,
      trace_id,
      content,
      answer: content,
    });
  } catch (e) {
    console.error("❌ Proxy crash:", e);
    return errJson(res, 500, trace_id, "Proxy server error");
  }
}

/* =========================
   Routes (compatibility)
========================= */
app.post("/ask", handleAsk);
app.post("/api/ask", handleAsk);
app.post("/v1/ask", handleAsk);
app.post("/reblaw/ask", handleAsk);
app.post("/reblaw-ai", handleAsk);

/* =========================
   Start server
========================= */
app.listen(PORT, "0.0.0.0", () => {
  console.log(`🚀 RebLaw AI Proxy listening on port ${PORT}`);
});
