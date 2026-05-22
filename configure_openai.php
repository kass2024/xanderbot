<?php
/**
 * OpenAI Configuration Fix Script
 * 
 * This script helps configure OpenAI properly for accurate chatbot responses
 */

echo "<h2>🤖 OpenAI Configuration Setup</h2>\n";

// Check if .env exists
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "<p style='color: red;'>❌ .env file not found</p>\n";
    echo "<p>Please create a .env file first</p>\n";
    exit;
}

// Read current .env content
$envContent = file_get_contents($envFile);

// Check for OpenAI configuration
$hasApiKey = strpos($envContent, 'OPENAI_API_KEY') !== false;
$hasModel = strpos($envContent, 'OPENAI_MODEL') !== false;

echo "<h3>📋 Current Configuration Status</h3>\n";
echo "<p>OPENAI_API_KEY: " . ($hasApiKey ? "✅ Found" : "❌ Missing") . "</p>\n";
echo "<p>OPENAI_MODEL: " . ($hasModel ? "✅ Found" : "❌ Missing") . "</p>\n";

if (!$hasApiKey || !$hasModel) {
    echo "<h3>🔧 Adding OpenAI Configuration</h3>\n";
    
    // Add OpenAI configuration
    $newConfig = "\n# OpenAI Configuration for Chatbot\nOPENAI_API_KEY=your_openai_api_key_here\nOPENAI_MODEL=gpt-4\n";
    
    if (!$hasApiKey) {
        $envContent = str_replace(
            "# OpenAI Configuration for Chatbot",
            "# OpenAI Configuration for Chatbot\nOPENAI_API_KEY=your_openai_api_key_here",
            $envContent
        );
        
        if (strpos($envContent, 'OPENAI_API_KEY') === false) {
            $envContent .= $newConfig;
        }
    }
    
    if (!$hasModel) {
        if (strpos($envContent, 'OPENAI_MODEL') === false) {
            $envContent .= "OPENAI_MODEL=gpt-4\n";
        }
    }
    
    file_put_contents($envFile, $envContent);
    echo "<p style='color: green;'>✅ OpenAI configuration added to .env</p>\n";
}

// Show instructions
echo "<h3>📝 Next Steps</h3>\n";
echo "<div style='background: #f0f8ff; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
echo "<h4>Required Actions:</h4>\n";
echo "<ol>\n";
echo "<li><strong>Get OpenAI API Key:</strong><br>\n";
echo "   • Visit <a href='https://platform.openai.com/api-keys' target='_blank'>OpenAI Platform</a><br>\n";
echo "   • Create an account or sign in<br>\n";
echo "   • Generate a new API key<br>\n";
echo "   • Copy the key (starts with 'sk-')</li>\n";
echo "<br>\n";
echo "<li><strong>Update .env file:</strong><br>\n";
echo "   • Open the .env file in this directory<br>\n";
echo "   • Replace 'your_openai_api_key_here' with your actual API key<br>\n";
echo "   • Save the file</li>\n";
echo "<br>\n";
echo "<li><strong>Clear Laravel caches:</strong><br>\n";
echo "   <code>php artisan cache:clear</code><br>\n";
echo "   <code>php artisan config:clear</code></li>\n";
echo "<br>\n";
echo "<li><strong>Test the configuration:</strong><br>\n";
echo "   • Run <code>php test_chatbot.php</code><br>\n";
echo "   • Check if OpenAI connection works</li>\n";
echo "</ol>\n";
echo "</div>\n";

// Show current .env OpenAI section
echo "<h3>📄 Current .env OpenAI Section:</h3>\n";
echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px;'>\n";
$lines = explode("\n", $envContent);
$showing = false;
foreach ($lines as $line) {
    if (strpos($line, 'OPENAI') !== false || $showing) {
        if (strpos($line, 'OPENAI') !== false) {
            $showing = true;
        }
        if (trim($line) === '' && $showing) {
            break;
        }
        echo htmlspecialchars($line) . "\n";
    }
}
echo "</pre>\n";

echo "<h3>🎯 Expected Bot Behavior After Configuration:</h3>\n";
echo "<ul>\n";
echo "<li>👤 Collect name and email first (onboarding)</li>\n";
echo "<li>📚 Search FAQs for exact matches</li>\n";
echo "<li>🤖 Use OpenAI for questions not in FAQs</li>\n";
echo "<li>💬 Provide accurate, professional responses</li>\n";
echo "<li>🔗 Mention Xander Global Scholars services</li>\n";
echo "</ul>\n";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
echo "<h4>⚠️ Important Notes:</h4>\n";
echo "<ul>\n";
echo "<li>OpenAI API usage costs money based on tokens used</li>\n";
echo "<li>Keep your API key secure and never share it</li>\n";
echo "<li>The bot will use OpenAI when FAQ confidence is below 75%</li>\n";
echo "<li>Responses will be more accurate and helpful</li>\n";
echo "</ul>\n";
echo "</div>\n";

?>
