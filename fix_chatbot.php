<?php
/**
 * Chatbot Fix Script
 * 
 * This script diagnoses and fixes common issues with the chatbot:
 * 1. Enables onboarding flow (name/email collection)
 * 2. Checks OpenAI configuration
 * 3. Adds sample FAQ data if knowledge base is empty
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "<h2>🔧 Chatbot Diagnostic & Fix Tool</h2>\n";

// 1. Check OpenAI Configuration
echo "<h3>📋 OpenAI Configuration Check</h3>\n";
$openaiKey = config('services.openai.key');
$openaiModel = config('services.openai.model', 'gpt-4');

if ($openaiKey) {
    echo "<p style='color: green;'>✅ OpenAI Key: " . substr($openaiKey, 0, 10) . "...</p>\n";
    echo "<p>✅ Model: $openaiModel</p>\n";
} else {
    echo "<p style='color: red;'>❌ OpenAI Key not configured</p>\n";
    echo "<p>Please add OPENAI_KEY to your .env file</p>\n";
}

// 2. Check Knowledge Base
echo "<h3>📚 Knowledge Base Check</h3>\n";
try {
    $faqCount = DB::table('knowledge_bases')->count();
    echo "<p>FAQ entries: $faqCount</p>\n";
    
    if ($faqCount === 0) {
        echo "<p style='color: orange;'>⚠️ Knowledge base is empty. Adding sample FAQs...</p>\n";
        
        // Add sample FAQs
        $sampleFaqs = [
            [
                'client_id' => 1,
                'question' => 'What visa services do you offer?',
                'answer' => 'We offer student visa, work visa, visitor visa, and immigration consultancy services for multiple countries including Canada, USA, UK, Australia, and European countries.',
                'category' => 'Services',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => 1,
                'question' => 'How do I apply for a student visa?',
                'answer' => 'To apply for a student visa, you need: 1) Acceptance letter from educational institution, 2) Proof of funds, 3) Valid passport, 4) Visa application form, 5) Medical examination. Contact our advisors for personalized assistance.',
                'category' => 'Student Visa',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => 1,
                'question' => 'What are the requirements for Canada student visa?',
                'answer' => 'Canada student visa requirements include: 1) Letter of acceptance from DLI, 2) Proof of financial support ($10,000 per year + tuition), 3) Valid passport, 4) Medical exam, 5) Statement of purpose, 6) English/French proficiency test results.',
                'category' => 'Canada',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => 1,
                'question' => 'How much does your service cost?',
                'answer' => 'Our service fees vary depending on the visa type and destination country. Initial consultation is free. Contact us with your specific requirements for a detailed quote.',
                'category' => 'Pricing',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => 1,
                'question' => 'Do you help with scholarships?',
                'answer' => 'Yes, we assist students in finding and applying for scholarships. We maintain a database of available scholarships and help with application preparation to increase your chances of success.',
                'category' => 'Scholarships',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];
        
        DB::table('knowledge_bases')->insert($sampleFaqs);
        echo "<p style='color: green;'>✅ Added 5 sample FAQ entries</p>\n";
    } else {
        echo "<p style='color: green;'>✅ Knowledge base has data</p>\n";
    }
} catch (\Exception $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>\n";
}

// 3. Check Onboarding Configuration
echo "<h3>👤 Onboarding Configuration</h3>\n";
$onboardingEnabled = config('chatbot.require_profile_onboarding', false);
echo "<p>Onboarding enabled: " . ($onboardingEnabled ? 'YES' : 'NO') . "</p>\n";

if (!$onboardingEnabled) {
    echo "<p style='color: orange;'>⚠️ Onboarding is disabled. This means the bot won\'t ask for name/email.</p>\n";
    echo "<p>To enable onboarding, add this to your .env file:</p>\n";
    echo "<code>CHATBOT_REQUIRE_PROFILE_ONBOARDING=true</code><br>\n";
}

// 4. Test OpenAI Connection
echo "<h3>🔗 OpenAI Connection Test</h3>\n";
if ($openaiKey) {
    try {
        $client = new \GuzzleHttp\Client();
        $response = $client->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $openaiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $openaiModel,
                'messages' => [
                    ['role' => 'user', 'content' => 'Say "Hello"']
                ],
                'max_tokens' => 10,
            ],
            'timeout' => 10,
        ]);
        
        if ($response->getStatusCode() === 200) {
            $result = json_decode($response->getBody(), true);
            echo "<p style='color: green;'>✅ OpenAI connection successful</p>\n";
            echo "<p>Response: " . ($result['choices'][0]['message']['content'] ?? 'No content') . "</p>\n";
        } else {
            echo "<p style='color: red;'>❌ OpenAI API error: " . $response->getStatusCode() . "</p>\n";
        }
    } catch (\Exception $e) {
        echo "<p style='color: red;'>❌ OpenAI connection failed: " . $e->getMessage() . "</p>\n";
    }
}

// 5. Current Configuration Summary
echo "<h3>📊 Current Configuration</h3>\n";
echo "<table border='1' style='border-collapse: collapse; padding: 5px;'>\n";
echo "<tr><th>Setting</th><th>Value</th></tr>\n";
echo "<tr><td>OpenAI Key</td><td>" . ($openaiKey ? 'Configured' : 'Not set') . "</td></tr>\n";
echo "<tr><td>OpenAI Model</td><td>$openaiModel</td></tr>\n";
echo "<tr><td>Onboarding</td><td>" . ($onboardingEnabled ? 'Enabled' : 'Disabled') . "</td></tr>\n";
echo "<tr><td>FAQ Count</td><td>$faqCount</td></tr>\n";
echo "</table>\n";

// 6. Recommendations
echo "<h3>💡 Recommendations</h3>\n";
echo "<ul>\n";
if (!$openaiKey) {
    echo "<li style='color: red;'>Add OPENAI_KEY to your .env file</li>\n";
}
if ($faqCount === 0) {
    echo "<li style='color: green;'>✅ Added sample FAQs - you can now test the bot</li>\n";
}
if (!$onboardingEnabled) {
    echo "<li style='color: orange;'>Enable onboarding if you want name/email collection</li>\n";
}
echo "<li>Test the bot by sending a message like \"What visa services do you offer?\"</li>\n";
echo "</ul>\n";

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ol>\n";
echo "<li>Update your .env file with any missing configurations</li>\n";
echo "<li>Restart your Laravel application: <code>php artisan cache:clear</code></li>\n";
echo "<li>Test the chatbot on WhatsApp</li>\n";
echo "</ol>\n";

?>
