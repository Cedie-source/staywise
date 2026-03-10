<?php
/**
 * Quick Groq Setup Reference
 */
?><!DOCTYPE html>
<html>
<head>
    <title>StayWise - Groq AI Setup</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px; }
        .status { padding: 20px; border-radius: 8px; margin: 20px 0; }
        .ok { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .steps { background: #f9f9f9; padding: 15px; border-left: 4px solid #007bff; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>🚀 StayWise AI Setup (Groq - Ultra-Fast & Free)</h1>
    
    <div class="steps">
        <h3>⚡ 3-Minute Setup</h3>
        <ol>
            <li><strong>Get free API key:</strong> <a href="https://console.groq.com" target="_blank">console.groq.com</a></li>
            <li><strong>Sign up or login</strong> (Google/GitHub)</li>
            <li><strong>Copy your API key</strong></li>
            <li><strong>Edit file:</strong> <code>config/groq.php</code></li>
            <li><strong>Replace:</strong> <code>putenv('GROQ_API_KEY=YOUR_KEY_HERE');</code> with your key</li>
            <li><strong>Test:</strong> <a href="../tenant/chatbot.php">Open chatbot</a> and ask a question</li>
        </ol>
    </div>

    <?php
    $configFile = __DIR__ . '/config/groq.php';
    $configExists = file_exists($configFile);
    $content = $configExists ? file_get_contents($configFile) : '';
    $hasKey = $configExists && strpos($content, 'YOUR_KEY_HERE') === false && strpos($content, 'gsk_') !== false;
    ?>

    <div class="status <?php echo $configExists ? 'ok' : 'error'; ?>">
        <strong>✓ Config File Status:</strong> 
        <?php echo $configExists ? 'Found (/config/groq.php)' : 'Missing - contact admin'; ?>
    </div>

    <div class="status <?php echo $hasKey ? 'ok' : 'warning'; ?>">
        <strong><?php echo $hasKey ? '✓' : '⚠'; ?> API Key Status:</strong>
        <?php 
            if (!$configExists) {
                echo 'Cannot check (config missing)';
            } elseif ($hasKey) {
                echo 'Configured ✓ (key found)';
            } else {
                echo 'NOT configured - Replace YOUR_KEY_HERE with your Groq API key';
            }
        ?>
    </div>

    <h3>Why Groq? ⚡</h3>
    <ul>
        <li>✅ <strong>Ultra-Fast</strong> - 2-3 seconds per response</li>
        <li>✅ <strong>Completely Free</strong> - No credit card needed</li>
        <li>✅ <strong>Reliable</strong> - No deprecation issues</li>
        <li>✅ <strong>Multiple Models</strong> - Mixtral, Llama 2, Gemma</li>
        <li>✅ <strong>OpenAI Compatible</strong> - Easy to maintain</li>
    </ul>

    <h3>Available Models (All Free)</h3>
    <ul>
        <li><strong>mixtral-8x7b-32768</strong> (Default) - Best balance, ~2 sec</li>
        <li><strong>llama2-70b-4096</strong> - Highest quality, ~3 sec</li>
        <li><strong>gemma-7b-it</strong> - Lightweight, fastest (~1 sec)</li>
    </ul>

    <h3>Free Tier Limits</h3>
    <ul>
        <li>✅ <strong>No rate limits</strong> on free tier (thousands of calls/month)</li>
        <li>✅ <strong>No credit card</strong> required</li>
        <li>✅ <strong>Unlimited usage</strong> for qualifying conditions</li>
    </ul>

    <h3>Security</h3>
    <ul>
        <li>🔒 API key only makes text generation calls</li>
        <li>🔒 Messages are not logged/stored by Groq</li>
        <li>🔒 No tenant data sent except AI request</li>
        <li>⚠️ Keep API key private (don't commit to git)</li>
    </ul>

    <h3>Need Help?</h3>
    <ul>
        <li>Check browser console (F12) for error details</li>
        <li>Verify API key at: <a href="https://console.groq.com" target="_blank">Groq Console</a></li>
        <li>API Docs: <a href="https://console.groq.com/docs" target="_blank">Groq Docs</a></li>
    </ul>

</body>
</html>
