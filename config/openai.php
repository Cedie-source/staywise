<?php
/**
 * OpenAI API Configuration
 * Uses free $5 credit (or paid API)
 * 
 * SETUP INSTRUCTIONS:
 * 1. Go to: https://platform.openai.com/account/api-keys
 * 2. Create new API key
 * 3. Set OPENAI_API_KEY below
 * 4. Done!
 */

// === OPENAI API ===
// Get your API key from: https://platform.openai.com/account/api-keys
// Free $5 credit for new users (valid 3 months)
putenv('OPENAI_API_KEY=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');

// Model choice:
// - gpt-4o-mini: Fastest, cheapest (~$0.15 per 1M input tokens)
// - gpt-3.5-turbo: Legacy, cheaper (~$0.50 per 1M tokens)
putenv('OPENAI_MODEL=gpt-4o-mini');

// OpenAI API endpoint
putenv('OPENAI_API_URL=https://api.openai.com/v1/chat/completions');

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
// error_log('OpenAI API: Using model ' . getenv('OPENAI_MODEL'));
?>
