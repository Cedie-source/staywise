<?php
/**
 * Quick OpenAI Setup Reference
 */
?><!DOCTYPE html>
<html>
<head>
    <title>StayWise - OpenAI Setup</title>
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
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f4f4f4; }
    </style>
</head>
<body>
    <h1>🔑 StayWise AI Setup (OpenAI)</h1>
    
    <div class="steps">
        <h3>⚡ 3-Minute Setup</h3>
        <ol>
            <li><strong>Get free API key:</strong> <a href="https://platform.openai.com/account/api-keys" target="_blank">platform.openai.com/account/api-keys</a></li>
            <li><strong>Sign up or login</strong> (you get $5 free credit!)</li>
            <li><strong>Create API key</strong> and copy it</li>
            <li><strong>Edit file:</strong> <code>config/openai.php</code></li>
            <li><strong>Replace:</strong> <code>putenv('OPENAI_API_KEY=YOUR_API_KEY_HERE');</code> with your key</li>
            <li><strong>Test:</strong> <a href="../tenant/chatbot.php">Open chatbot</a> and ask a question</li>
        </ol>
    </div>

    <?php
    $configFile = __DIR__ . '/config/openai.php';
    $configExists = file_exists($configFile);
    $content = $configExists ? file_get_contents($configFile) : '';
    $hasKey = $configExists && strpos($content, 'YOUR_API_KEY_HERE') === false && strpos($content, 'sk-') !== false;
    ?>

    <div class="status <?php echo $configExists ? 'ok' : 'error'; ?>">
        <strong>✓ Config File Status:</strong> 
        <?php echo $configExists ? 'Found (/config/openai.php)' : 'Missing - contact admin'; ?>
    </div>

    <div class="status <?php echo $hasKey ? 'ok' : 'warning'; ?>">
        <strong><?php echo $hasKey ? '✓' : '⚠'; ?> API Key Status:</strong>
        <?php 
            if (!$configExists) {
                echo 'Cannot check (config missing)';
            } elseif ($hasKey) {
                echo 'Configured ✓ (key found)';
            } else {
                echo 'NOT configured - Replace YOUR_API_KEY_HERE with your OpenAI API key';
            }
        ?>
    </div>

    <h3>Free Credit 💰</h3>
    <ul>
        <li>✅ New OpenAI accounts get <strong>$5 free credit</strong></li>
        <li>✅ Valid for 3 months from signup</li>
        <li>✅ Perfect for testing</li>
        <li>✅ No credit card required to use free tier</li>
    </ul>

    <h3>Pricing After Free Credit</h3>
    <table>
        <tr>
            <th>Model</th>
            <th>Input Token Cost</th>
            <th>Output Token Cost</th>
            <th>Typical Usage</th>
        </tr>
        <tr>
            <td><strong>gpt-4o-mini</strong> (Default)</td>
            <td>$0.15 / 1M</td>
            <td>$0.60 / 1M</td>
            <td>~$0.01 per question</td>
        </tr>
        <tr>
            <td>gpt-3.5-turbo</td>
            <td>$0.50 / 1M</td>
            <td>$1.50 / 1M</td>
            <td>~$0.002 per question (cheaper)</td>
        </tr>
    </table>

    <h3>Cost Example (After Free Credit)</h3>
    <ul>
        <li>30 tenants × 5 questions/day = 150 questions/day</li>
        <li>150 × 30 days = 4,500 questions/month</li>
        <li>4,500 × $0.01 = <strong>$45/month</strong> (very affordable)</li>
    </ul>

    <h3>Available Models</h3>
    <ul>
        <li><strong>gpt-4o-mini</strong> - Best quality, very fast, cheapest smart model</li>
        <li><strong>gpt-3.5-turbo</strong> - Legacy, faster, cheaper (but less capable)</li>
    </ul>

    <h3>Security</h3>
    <ul>
        <li>🔒 API key only makes chat completion calls</li>
        <li>🔒 Messages are not stored by OpenAI (per their API policy)</li>
        <li>🔒 No tenant data sent except AI request</li>
        <li>⚠️ Keep API key private (don't commit to git)</li>
    </ul>

    <h3>Next Steps</h3>
    <ol>
        <li>Get API key from <a href="https://platform.openai.com/account/api-keys" target="_blank">OpenAI</a></li>
        <li>Edit <code>config/openai.php</code> and add your key</li>
        <li>Test at <a href="../tenant/chatbot.php">chatbot.php</a></li>
        <li>Check browser console (F12) if there are issues</li>
    </ol>

</body>
</html>
