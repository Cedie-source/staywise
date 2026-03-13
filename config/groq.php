<?php
/**
 * Groq API Configuration
 * Uses Railway environment variables — do NOT hardcode keys here.
 * Set GROQ_API_KEY, GROQ_MODEL, GROQ_API_URL in your Railway dashboard.
 */

// Only set fallbacks if Railway env vars are not already set
if (!getenv('GROQ_API_KEY')) {
    putenv('GROQ_API_KEY=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');
}
if (!getenv('GROQ_MODEL')) {
    putenv('GROQ_MODEL=llama-3.3-70b-versatile');
}
if (!getenv('GROQ_API_URL')) {
    putenv('GROQ_API_URL=https://api.groq.com/openai/v1/chat/completions');
}

// === SYSTEM PROMPT ===
if (!getenv('AI_SYSTEM_PROMPT')) {
    putenv('AI_SYSTEM_PROMPT=' . 'You are StayWise AI Assistant, a helpful property management assistant for tenants.

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
- Always be helpful and courteous');
}
