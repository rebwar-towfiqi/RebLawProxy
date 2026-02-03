const express = require("express");
const cors = require("cors");
const fetch = require("node-fetch");

const app = express();
const PORT = process.env.PORT || 3000;

// Optional: lock CORS to specific origins (comma-separated). If empty, allow all.
const allowedOrigins = (process.env.CORS_ORIGIN || "")
  .split(",")
  .map((s) => s.trim())
  .filter(Boolean);

app.use(
  cors({
    origin: (origin, cb) => {
      // Server-to-server (WordPress -> Proxy) usually has no origin.
      if (!origin) return cb(null, true);
      if (!allowedOrigins.length) return cb(null, true);
      return cb(null, allowedOrigins.includes(origin));
    },
    methods: ["GET", "POST", "OPTIONS"],
    allowedHeaders: ["Content-Type", "Authorization"],
  })
);

app.use(express.json({ limit: "1mb" }));

app.get("/", (req, res) => res.send("RebLaw AI Proxy is running."));
app.get("/health", (req, res) => res.json({ ok: true }));

/**
 * If REBLAW_AI_PROXY_TOKEN is set, the proxy requires:
 *   Authorization: Bearer <token>
 */
function checkAuth(req) {
  const required = (process.env.REBLAW_AI_PROXY_TOKEN || "").trim();
  if (!required) return { ok: true };

  const auth = (req.headers.authorization || "").trim();
  if (!auth.startsWith("Bearer ")) return { ok: false, status: 401, error: "Missing Authorization" };

  const tok = auth.slice("Bearer ".length).trim();
  if (tok !== required) return { ok: false, status: 403, error: "Invalid token" };

  return { ok: true };
}

function normalizeIncoming(reqBody) {
  const body = reqBody || {};

  // Preferred: OpenAI style {model, messages, temperature, ...}
  if (Array.isArray(body.messages) && body.messages.length) {
    return {
      model: typeof body.model === "string" && body.model.trim() ? body.model.trim() : 'gpt-3.5-turbo',
      messages: body.messages,
      temperature: typeof body.temperature === "number" ? body.temperature : null,
      max_tokens: typeof body.max_tokens === "number" ? body.max_tokens : null,
      top_p: typeof body.top_p === "number" ? body.top_p : null,
      presence_penalty: typeof body.presence_penalty === "number" ? body.presence_penalty : null,
      frequency_penalty: typeof body.frequency_penalty === "number" ? body.frequency_penalty : null,
    };
  }

  // Simple clients { question:"..." }
  if (typeof body.question === "string" && body.question.trim()) {
    const systemPrompt = `You are RebLaw, a professional legal assistant.
Always respond in the same language as the user (Persian/Farsi, Kurdish, or English).
Be clear, structured, and practical.`;

    return {
      model: 'gpt-3.5-turbo',
      temperature: null,
      max_tokens: null,
      top_p: null,
      presence_penalty: null,
      frequency_penalty: null,
      messages: [
        { role: "system", content: systemPrompt },
        { role: "user", content: body.question.trim() },
      ],
    };
  }

  return null;
}

async function callOpenAI(payload) {
  const resp = await fetch("https://api.openai.com/v1/chat/completions", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${process.env.OPENAI_API_KEY}`,
    },
    body: JSON.stringify(payload),
  });

  const raw = await resp.text();

  let json = null;
  try {
    json = JSON.parse(raw);
  } catch (_) {
    // leave json null
  }

  return { ok: resp.ok, status: resp.status, json, raw };
}

function extractContent(json) {
  if (!json || typeof json !== "object") return "";
  const c = json?.choices?.[0]?.message?.content;
  if (typeof c === "string" && c.trim()) return c.trim();
  const c2 = json?.content;
  if (typeof c2 === "string" && c2.trim()) return c2.trim();
  const c3 = json?.answer;
  if (typeof c3 === "string" && c3.trim()) return c3.trim();
  return "";
}

function errJson(res, status, trace_id, message, extra = {}) {
  return res.status(status).json({
    success: false,
    message,
    trace_id,
    ...extra,
  });
}

async function handleAsk(req, res) {
  const trace_id = Date.now().toString(36);

  const auth = checkAuth(req);
  if (!auth.ok) return errJson(res, auth.status, trace_id, auth.error);

  const normalized = normalizeIncoming(req.body);
  if (!normalized) {
    return errJson(
      res,
      400,
      trace_id,
      'Invalid payload. Send {messages:[...]} (preferred) or {question:"..."}'
    );
  }

  if (!process.env.OPENAI_API_KEY) {
    return errJson(res, 500, trace_id, "OPENAI_API_KEY is not set on the server.");
  }

  const payload = {
    model: normalized.model,
    messages: normalized.messages,
    temperature: normalized.temperature || 0.7,
    max_tokens: normalized.max_tokens,
    top_p: normalized.top_p,
    presence_penalty: normalized.presence_penalty,
    frequency_penalty: normalized.frequency_penalty,
  };

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
      return errJson(res, 502, trace_id, "Upstream returned no usable content", {
        upstream_status: status,
        hint: "If you see HTML or a block page here, your server/network is interfering.",
        raw_snippet: raw.slice(0, 800),
      });
    }

    return res.json({
      success: true,
      answer: content,
      content,
      trace_id,
    });
  } catch (err) {
    return errJson(res, 500, trace_id, "Proxy server error", {
      details: err?.message || String(err),
    });
  }
}

app.post("/ask", handleAsk);
app.post("/api/ask", handleAsk);
app.post("/v1/ask", handleAsk);
app.post("/reblaw/ask", handleAsk);
app.post("/reblaw-ai", handleAsk);

app.listen(PORT, "0.0.0.0", () => {
  console.log(`RebLaw AI Proxy listening on port ${PORT}`);
});
