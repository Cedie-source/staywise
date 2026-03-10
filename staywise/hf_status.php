<?php
/**
 * Quick Hugging Face Setup Reference
 */
?><!DOCTYPE html>
<html>
<head>
    <title>StayWise - Hugging Face AI Setup</title>
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
    <h1>🤖 StayWise AI Setup (Hugging Face)</h1>
    
    <div class="steps">
        <h3>⚡ 3-Minute Setup</h3>
        <ol>
            <li><strong>Get free API key:</strong> <a href="https://huggingface.co/settings/tokens" target="_blank">huggingface.co/settings/tokens</a></li>
            <li><strong>Edit file:</strong> <code>config/huggingface.php</code></li>
            <li><strong>Replace:</strong> <code>putenv('HUGGINGFACE_API_KEY=YOUR_TOKEN_HERE');</code> with your token</li>
            <li><strong>Test:</strong> <a href="../tenant/chatbot.php">Open chatbot</a> and ask a question</li>
        </ol>
    </div>

    <?php
    $configFile = __DIR__ . '/config/huggingface.php';
    $configExists = file_exists($configFile);
    $content = $configExists ? file_get_contents($configFile) : '';
    $hasToken = $configExists && strpos($content, 'YOUR_TOKEN_HERE') === false && strpos($content, 'hf_') !== false;
    ?>

    <div class="status <?php echo $configExists ? 'ok' : 'error'; ?>">
        <strong>✓ Config File Status:</strong> 
        <?php echo $configExists ? 'Found (/config/huggingface.php)' : 'Missing - contact admin'; ?>
    </div>

    <div class="status <?php echo $hasToken ? 'ok' : 'warning'; ?>">
        <strong><?php echo $hasToken ? '✓' : '⚠'; ?> API Key Status:</strong>
        <?php 
            if (!$configExists) {
                echo 'Cannot check (config missing)';
            } elseif ($hasToken) {
                echo 'Configured ✓ (token found)';
            } else {
                echo 'NOT configured - Replace YOUR_TOKEN_HERE with your Hugging Face token';
            }
        ?>
    </div>

    <h3>Available Models (Fast & Free)</h3>
    <ul>
        <li><strong>Mistral 7B</strong> (Default) - Best quality, ~5-10 sec/response</li>
        <li><strong>Microsoft Phi 2</strong> - Very fast, ~3 sec/response</li>
        <li><strong>Llama 2 7B</strong> - High quality, ~10 sec/response</li>
        <li><strong>Zephyr 7B</strong> - Conversational, balanced speed</li>
    </ul>

    <h3>Free Tier Limits</h3>
    <ul>
        <li>25,000 API calls/month (free)</li>
        <li>Typical usage: 30 tenants × 5 questions/day = 900/month ✓</li>
        <li>First response from a model takes 5s (model warming up)</li>
        <li>Subsequent responses: 2-3 seconds</li>
    </ul>

    <h3>Security</h3>
    <ul>
        <li>✓ API token is read-only (text generation only)</li>
        <li>✓ Messages are not logged/stored on Hugging Face</li>
        <li>✓ No tenant data sent anywhere except AI request</li>
        <li>⚠ Keep API key private (don't commit to git)</li>
    </ul>

    <h3>Need Help?</h3>
    <ul>
        <li>Check browser console (F12) for error details</li>
        <li>Verify API key at: <a href="https://huggingface.co/docs/hub/security-tokens" target="_blank">Hugging Face Token Docs</a></li>
        <li>Test API key manually using provided script</li>
    </ul>

</body>
</html>
