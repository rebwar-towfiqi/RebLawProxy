const express = require("express");
const cors = require("cors");
const fetch = require("node-fetch");

const app = express();
const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json());

app.get("/", (req, res) => {
  res.send("RebLaw AI Proxy is running.");
});

app.post("/ask", async (req, res) => {
  const { question } = req.body;

  if (!question) {
    return res.status(400).json({
      success: false,
      message: "No question provided",
    });
  }

  try {
    const response = await fetch("https://api.openai.com/v1/responses", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${process.env.OPENAI_API_KEY}`,
      },
      body: JSON.stringify({
        model: "gpt-5.2",
        input: question,
      }),
    });

    const data = await response.json();

    if (!response.ok) {
      console.error("OpenAI error:", data);
      return res.status(response.status).json({
        success: false,
        message: "OpenAI API error",
        details: data,
      });
    }

    const answer =
      data?.output_text?.trim() ||
      "پاسخی از هوش مصنوعی دریافت نشد.";

    return res.json({
      success: true,
      answer,
    });

  } catch (err) {
    console.error("Proxy server error:", err);
    return res.status(500).json({
      success: false,
      message: "Proxy server error",
    });
  }
});

app.listen(PORT, "0.0.0.0", () => {
  console.log(`RebLaw AI Proxy listening on port ${PORT}`);
});
