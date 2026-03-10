<?php
// Copy to config/ai.php and optionally define defaults for local dev.
// Prefer environment variables in production.

// putenv('AI_PROVIDER=openai');
// putenv('OPENAI_API_KEY=sk-...');
// putenv('OPENAI_API_MODEL=gpt-4o-mini');
// putenv('OPENAI_API_BASE=https://api.openai.com');

// Azure example:
// putenv('AI_PROVIDER=azure');
// putenv('AZURE_OPENAI_API_KEY=...');
// putenv('AZURE_OPENAI_ENDPOINT=https://<your-resource>.openai.azure.com');
// putenv('AZURE_OPENAI_DEPLOYMENT=gpt-4o-mini');
// putenv('AZURE_OPENAI_API_VERSION=2024-06-01');

// Ollama example (local model):
// putenv('AI_PROVIDER=ollama');
// putenv('OLLAMA_BASE_URL=http://127.0.0.1:11434');
// putenv('OLLAMA_MODEL=llama3.1:8b');

// Optional system prompt:
// putenv('AI_SYSTEM_PROMPT=You are StayWise, a helpful assistant for tenants...');
