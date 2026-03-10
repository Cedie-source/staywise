<?php
/**
 * Hugging Face Inference API Configuration
 * Free cloud AI - no credit card required
 * 
 * SETUP INSTRUCTIONS:
 * 1. Get free API key from: https://huggingface.co/settings/tokens
 * 2. Create new token with "read" permission
 * 3. Set HUGGINGFACE_API_KEY below
 * 4. Done! No additional setup needed
 */

// === HUGGING FACE FREE API ===
// Get your free token from: https://huggingface.co/settings/tokens
putenv('HUGGINGFACE_API_KEY=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');

// Model choice (all free on inference API):
// - mistral-7b: Fastest, best quality for chat
// - neural-chat: Optimized for conversation
// - zephyr-7b: Good balance of speed/quality
putenv('HUGGINGFACE_MODEL=mistralai/Mistral-7B-Instruct-v0.1');

// API endpoint (official, free tier)
putenv('HUGGINGFACE_API_URL=https://router.huggingface.co/models/');

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
// error_log('HF API: Using model ' . getenv('HUGGINGFACE_MODEL'));
?>
