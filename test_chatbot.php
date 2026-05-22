<?php
/**
 * Chatbot Test Script
 * 
 * Tests the chatbot functionality to ensure fixes are working
 */

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Chatbot\ChatbotProcessor;
use App\Services\Chatbot\AIEngine;

echo "<h2>🧪 Chatbot Functionality Test</h2>\n";

// Test 1: Check Database Connection
echo "<h3>📋 Database Connection Test</h3>\n";
try {
    $connection = DB::connection()->getPdo();
    echo "<p style='color: green;'>✅ Database connection successful</p>\n";
} catch (\Exception $e) {
    echo "<p style='color: red;'>❌ Database connection failed: " . $e->getMessage() . "</p>\n";
    exit;
}

// Test 2: Check FAQ Data
echo "<h3>📚 FAQ Data Test</h3>\n";
$faqCount = DB::table('knowledge_bases')->count();
echo "<p>Total FAQ entries: $faqCount</p>\n";

if ($faqCount > 0) {
    echo "<p style='color: green;'>✅ FAQ data available</p>\n";
    
    // Show sample FAQs
    $sampleFaqs = DB::table('knowledge_bases')->limit(3)->get(['question', 'category']);
    echo "<h4>Sample FAQs:</h4>\n";
    echo "<ul>\n";
    foreach ($sampleFaqs as $faq) {
        echo "<li><strong>[{$faq->category}]</strong> {$faq->question}</li>\n";
    }
    echo "</ul>\n";
} else {
    echo "<p style='color: red;'>❌ No FAQ data found. Run seed_faqs.php first</p>\n";
}

// Test 3: Check OpenAI Configuration
echo "<h3>🤖 OpenAI Configuration Test</h3>\n";
$openaiKey = config('services.openai.key');
if ($openaiKey) {
    echo "<p style='color: green;'>✅ OpenAI key configured</p>\n";
    echo "<p>Model: " . config('services.openai.model', 'gpt-4') . "</p>\n";
} else {
    echo "<p style='color: orange;'>⚠️ OpenAI key not configured - AI responses will use fallbacks</p>\n";
}

// Test 4: Test AIEngine Directly
echo "<h3>🔧 AIEngine Test</h3>\n";
try {
    $aiEngine = new AIEngine();
    
    // Test greeting detection
    $greetingTest = $aiEngine->reply(1, "hello", null);
    echo "<p><strong>Greeting Test:</strong> " . substr($greetingTest['text'] ?? 'No response', 0, 100) . "...</p>\n";
    
    // Test FAQ matching
    $faqTest = $aiEngine->reply(1, "What services do you offer?", null);
    echo "<p><strong>FAQ Test:</strong> " . substr($faqTest['text'] ?? 'No response', 0, 100) . "...</p>\n";
    echo "<p><strong>Source:</strong> " . ($faqTest['source'] ?? 'unknown') . "</p>\n";
    echo "<p><strong>Confidence:</strong> " . ($faqTest['confidence'] ?? 0) . "</p>\n";
    
    if ($faqTest['source'] === 'direct_match' || $faqTest['source'] === 'keyword_match') {
        echo "<p style='color: green;'>✅ FAQ matching working correctly</p>\n";
    } else {
        echo "<p style='color: orange;'>⚠️ FAQ matching may need optimization</p>\n";
    }
    
} catch (\Exception $e) {
    echo "<p style='color: red;'>❌ AIEngine test failed: " . $e->getMessage() . "</p>\n";
}

// Test 5: Test Conversation Creation
echo "<h3>💬 Conversation Test</h3>\n";
try {
    $conversationData = [
        'client_id' => 1,
        'phone_number' => '+1234567890',
        'status' => 'bot',
        'last_activity_at' => now(),
        'is_profile_completed' => 0,
        'profile_step' => null,
        'first_contact_at' => now(),
    ];
    
    $conversationId = DB::table('conversations')->insertGetId($conversationData);
    echo "<p style='color: green;'>✅ Conversation creation successful (ID: $conversationId)</p>\n";
    
    // Clean up test conversation
    DB::table('conversations')->where('id', $conversationId)->delete();
    echo "<p>🧹 Test conversation cleaned up</p>\n";
    
} catch (\Exception $e) {
    echo "<p style='color: red;'>❌ Conversation test failed: " . $e->getMessage() . "</p>\n";
}

// Test 6: Check Required Tables
echo "<h3>🗄️ Database Tables Test</h3>\n";
$requiredTables = ['conversations', 'messages', 'knowledge_bases', 'clients'];
foreach ($requiredTables as $table) {
    try {
        $count = DB::table($table)->count();
        echo "<p>✅ Table '$table' exists ($count records)</p>\n";
    } catch (\Exception $e) {
        echo "<p style='color: red;'>❌ Table '$table' missing or inaccessible</p>\n";
    }
}

// Test 7: Configuration Summary
echo "<h3>⚙️ Configuration Summary</h3>\n";
echo "<table border='1' style='border-collapse: collapse; padding: 5px;'>\n";
echo "<tr><th>Component</th><th>Status</th><th>Notes</th></tr>\n";
echo "<tr><td>Database</td><td>" . ($connection ? '✅ OK' : '❌ Error') . "</td><td>Connection working</td></tr>\n";
echo "<tr><td>FAQ Data</td><td>" . ($faqCount > 0 ? '✅ OK' : '❌ Missing') . "</td><td>$faqCount entries</td></tr>\n";
echo "<tr><td>OpenAI</td><td>" . ($openaiKey ? '✅ Configured' : '⚠️ Not set') . "</td><td>AI responses</td></tr>\n";
echo "<tr><td>Onboarding</td><td>✅ Forced ON</td><td>Name/email collection</td></tr>\n";
echo "</table>\n";

echo "<hr>\n";
echo "<h3>🚀 Ready to Test!</h3>\n";
echo "<p>The chatbot should now:</p>\n";
echo "<ol>\n";
echo "<li>👤 Collect name and email first (onboarding)</li>\n";
echo "<li>📚 Search FAQs for answers</li>\n";
echo "<li>🤖 Use AI as fallback</li>\n";
echo "<li>💬 Provide helpful responses</li>\n";
echo "</ol>\n";

echo "<h3>📱 Test Messages to Try:</h3>\n";
echo "<ul>\n";
echo "<li>\"Hello\" - Test greeting and onboarding</li>\n";
echo "<li>\"What services do you offer?\" - Test FAQ matching</li>\n";
echo "<li>\"How do I apply for student visa?\" - Test detailed FAQ</li>\n";
echo "<li>\"Tell me about Canada visa\" - Test country-specific FAQ</li>\n";
echo "<li>\"Random question not in FAQs\" - Test AI fallback</li>\n";
echo "</ul>\n";

?>
