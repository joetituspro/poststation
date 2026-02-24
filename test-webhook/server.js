const express = require("express");
const bodyParser = require("body-parser");
const axios = require("axios");
const https = require("https");

const app = express();
const port = 3000;

// Configure axios to ignore SSL certificate verification (for testing only)
const axiosInstance = axios.create({
  httpsAgent: new https.Agent({
    rejectUnauthorized: false,
  }),
});

// Middleware to parse JSON bodies
app.use(bodyParser.json());

// Webhook endpoint
app.post("/webhook", async (req, res) => {
  console.log("Received webhook request:", req.body);

  const {
    block_id,
    article_url,
    post_type,
    categories,
    tags,
    callback_url,
    api_key,
  } = req.body;

  // Send immediate response
  res.status(200).json({ message: "Processing started" });

  // Simulate article processing (wait 5 seconds)
  await new Promise((resolve) => setTimeout(resolve, 5000));

  try {
    // Simulate processed content
    const processedContent = {
      block_id: block_id,
      title: `Processed Article ${block_id}`,
      content: `This is the processed content for article at ${article_url}.\n\nProcessed by test webhook server.`,
      excerpt: "This is a test excerpt.",
      thumbnail_url: "https://picsum.photos/800/600", // Random image for testing
      status: "draft",
      post_type,
      categories,
      tags,
    };

    // Send processed content back to WordPress using the configured axios instance
    console.log("Sending processed content to:", callback_url);
    console.log("Sending data:", processedContent);
    const response = await axiosInstance.post(callback_url, processedContent, {
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${api_key}`,
      },
    });

    console.log("WordPress response:", response.data);
  } catch (error) {
    console.error("Error processing webhook:", error.message);
    if (error.response) {
      console.error("Response data:", error.response.data);
      console.error("Response status:", error.response.status);
      console.error("Response headers:", error.response.headers);
    } else if (error.request) {
      console.error("No response received:", error.request);
    } else {
      console.error("Error details:", error);
    }
  }
});

// Add error handling for uncaught exceptions
process.on("uncaughtException", (error) => {
  console.error("Uncaught Exception:", error);
});

process.on("unhandledRejection", (error) => {
  console.error("Unhandled Rejection:", error);
});

// Start server
app.listen(port, () => {
  console.log(`Test webhook server running at http://localhost:${port}`);
});
