<?php
/**
 * Groq API Configuration
 * Free cloud AI - ultra-fast and reliable
 * 
 * SETUP INSTRUCTIONS:
 * 1. Get free API key from: https://console.groq.com
 * 2. Create API key
 * 3. Set GROQ_API_KEY below
 * 4. Done! No credit card needed
 */

// === GROQ FREE API ===
// Get your free token from: https://console.groq.com
putenv('GROQ_API_KEY=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');

// Model choice (all free on Groq, ultra-fast):
// - llama-3.3-70b-versatile: Latest, best quality (RECOMMENDED)
// - llama-3.1-8b-instant: Lightweight, fastest
putenv('GROQ_MODEL=llama-3.3-70b-versatile');

// Groq API endpoint
putenv('GROQ_API_URL=https://api.groq.com/openai/v1/chat/completions');

// === SYSTEM PROMPT ===
putenv('AI_SYSTEM_PROMPT=' . <<<'PROMPT'
You are StayWise AI Assistant, a helpful property management assistant for tenants.

Your responsibilities:
1. Answer questions about rent payments, maintenance requests, and building rules
2. Guide tenants through the StayWise application features
3. Provide general property management information
4. Be friendly, concise, and professional

Guidelines:
- Keep responses short and clear (under 150 words)
- For account-specific questions, direct users to their profile or admin
- Never ask for or accept payment information
- If unsure, suggest contacting the property admin
- Always be helpful and courteous

Current date: February 6, 2026
PROMPT
);

// === DEVELOPER OPTIONS ===
// Uncomment to debug API calls:
// error_log('Groq API: Using model ' . getenv('GROQ_MODEL'));
?>
