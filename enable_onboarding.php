<?php
/**
 * Enable Chatbot Onboarding
 * 
 * This script enables the name/email collection flow
 * like the working WABA version
 */

// Read current .env
$envFile = __DIR__ . '/.env';
$envContent = file_exists($envFile) ? file_get_contents($envFile) : '';

// Add onboarding configuration if not present
if (strpos($envContent, 'CHATBOT_REQUIRE_PROFILE_ONBOARDING') === false) {
    $envContent .= "\n# Enable chatbot onboarding (name/email collection)\nCHATBOT_REQUIRE_PROFILE_ONBOARDING=true\n";
    file_put_contents($envFile, $envContent);
    echo "<h2>✅ Onboarding Enabled</h2>";
    echo "<p>Added CHATBOT_REQUIRE_PROFILE_ONBOARDING=true to .env file</p>";
} else {
    // Update existing setting
    $envContent = preg_replace(
        '/CHATBOT_REQUIRE_PROFILE_ONBOARDING=.*/',
        'CHATBOT_REQUIRE_PROFILE_ONBOARDING=true',
        $envContent
    );
    file_put_contents($envFile, $envContent);
    echo "<h2>✅ Onboarding Updated</h2>";
    echo "<p>Updated CHATBOT_REQUIRE_PROFILE_ONBOARDING=true in .env file</p>";
}

echo "<h3>🔄 Next Steps:</h3>";
echo "<ol>";
echo "<li>Clear Laravel cache: <code>php artisan cache:clear</code></li>";
echo "<li>Clear config cache: <code>php artisan config:clear</code></li>";
echo "<li>Restart your application server</li>";
echo "<li>Test the bot - it should now ask for name and email first!</li>";
echo "</ol>";

echo "<p><strong>Expected Flow:</strong></p>";
echo "<ul>";
echo "<li>User sends first message → Bot asks for name</li>";
echo "<li>User provides name → Bot asks for email</li>";
echo "<li>User provides email → Bot confirms and allows questions</li>";
echo "<li>Then it searches FAQs first, then uses AI if needed</li>";
echo "</ul>";

?>
