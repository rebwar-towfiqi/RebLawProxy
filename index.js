const express = require("express");
const cors = require("cors");
const fetch = require("node-fetch");

const app = express();
app.use(cors());
app.use(express.json({ limit: "1mb" }));

const PORT = process.env.PORT || 8080;
const OPENAI_API_KEY = process.env.OPENAI_API_KEY;

if (!OPENAI_API_KEY) {
  console.error("❌ OPENAI_API_KEY is not set");
}

function errJson(res, status, trace_id, message, extra = {}) {
  return res.status(status).json({
    ok: false,
    error: message,
    trace_id,
    ...extra,
  });
}

app.post("/chat", async (req, res) => {
  const trace_id = Date.now().toString(36);

  try {
    const body = req.body;

    if (!body || !Array.isArray(body.messages)) {
      console.error("❌ Invalid body:", body);
      return errJson(res, 400, trace_id, "Invalid request body: messages[] required");
    }

    const payload = {
      model: body.model || "gpt-3.5-turbo",
      messages: body.messages,
      temperature: body.temperature ?? 0.2,
    };

    const r = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${OPENAI_API_KEY}`,
      },
      body: JSON.stringify(payload),
    });

    const text = await r.text();

    let data;
    try {
      data = JSON.parse(text);
    } catch {
      console.error("❌ OpenAI non-JSON response:", text);
      return errJson(res, 502, trace_id, "Invalid OpenAI response");
    }

    if (!r.ok) {
      console.error("❌ OpenAI error:", data);
      return errJson(res, r.status, trace_id, "OpenAI API error", data);
    }

    return res.json({
      ok: true,
      trace_id,
      data,
    });

  } catch (e) {
    console.error("❌ Proxy crash:", e);
    return errJson(res, 500, trace_id, "Proxy server error");
  }
});

app.listen(PORT, () => {
  console.log(`RebLaw AI Proxy listening on port ${PORT}`);
});
