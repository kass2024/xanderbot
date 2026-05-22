<?php
/**
 * Onboarding Flow Test
 * 
 * Tests that the chatbot properly collects name and email
 * before allowing any chat functionality
 */

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Chatbot\ChatbotProcessor;
use App\Services\Chatbot\AIEngine;

echo "<h2>👤 Chatbot Onboarding Flow Test</h2>\n";

// Create test conversation
echo "<h3>🧪 Setting Up Test Conversation</h3>\n";
try {
    // Clean up any existing test conversations
    DB::table('conversations')->where('phone_number', '+1234567890')->delete();
    
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
    echo "<p style='color: green;'>✅ Test conversation created (ID: $conversationId)</p>\n";
    
} catch (\Exception $e) {
    echo "<p style='color: red;'>❌ Failed to create test conversation: " . $e->getMessage() . "</p>\n";
    exit;
}

// Initialize chatbot processor
echo "<h3>🔧 Initializing Chatbot</h3>\n";
try {
    $processor = new ChatbotProcessor(app(AIEngine::class));
    echo "<p style='color: green;'>✅ Chatbot processor initialized</p>\n";
} catch (\Exception $e) {
    echo "<p style='color: red;'>❌ Failed to initialize chatbot: " . $e->getMessage() . "</p>\n";
    exit;
}

// Test onboarding flow
echo "<h3>📋 Testing Onboarding Flow</h3>\n";

$testSteps = [
    [
        'step' => 'First Message (Greeting)',
        'message' => 'hello',
        'expected_contains' => ['full name', 'name'],
        'should_not_contain' => ['visa', 'student', 'services'],
    ],
    [
        'step' => 'User Provides Name',
        'message' => 'John Smith',
        'expected_contains' => ['thank you', 'email'],
        'should_not_contain' => ['visa', 'student', 'services'],
    ],
    [
        'step' => 'User Provides Email',
        'message' => 'john.smith@email.com',
        'expected_contains' => ['thank you', 'complete', 'ready', 'help'],
        'should_not_contain' => ['email', 'name'],
    ],
    [
        'step' => 'First Real Question',
        'message' => 'What services do you offer?',
        'expected_contains' => ['services', 'offer'],
        'should_not_contain' => ['name', 'email', 'full name'],
    ],
];

$stepNumber = 1;
foreach ($testSteps as $testStep) {
    echo "<h4>Step $stepNumber: {$testStep['step']}</h4>\n";
    echo "<p><strong>Message:</strong> \"{$testStep['message']}\"</p>\n";
    
    try {
        // Get fresh conversation data
        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
        
        // Simulate webhook payload
        $payload = [
            'from' => '+1234567890',
            'text' => $testStep['message'],
            'client_id' => 1,
            'message_id' => 'test_msg_' . $stepNumber,
        ];
        
        // Process message
        $response = $processor->process($payload);
        
        if ($response) {
            $responseText = strtolower($response['text'] ?? '');
            echo "<p><strong>Bot Response:</strong> \"" . substr($response['text'], 0, 200) . "...</p>\n";
            
            // Check expected content
            $allExpectedFound = true;
            foreach ($testStep['expected_contains'] as $expected) {
                if (strpos($responseText, $expected) === false) {
                    echo "<p style='color: red;'>❌ Missing expected: \"$expected\"</p>\n";
                    $allExpectedFound = false;
                } else {
                    echo "<p style='color: green;'>✅ Found expected: \"$expected\"</p>\n";
                }
            }
            
            // Check unwanted content
            $allUnwantedAvoided = true;
            foreach ($testStep['should_not_contain'] as $unwanted) {
                if (strpos($responseText, $unwanted) !== false) {
                    echo "<p style='color: red;'>❌ Contains unwanted: \"$unwanted\"</p>\n";
                    $allUnwantedAvoided = false;
                } else {
                    echo "<p style='color: green;'>✅ Avoided unwanted: \"$unwanted\"</p>\n";
                }
            }
            
            if ($allExpectedFound && $allUnwantedAvoided) {
                echo "<p style='color: green;'><strong>✅ Step $stepNumber PASSED</strong></p>\n";
            } else {
                echo "<p style='color: red;'><strong>❌ Step $stepNumber FAILED</strong></p>\n";
            }
            
            // Show conversation status
            $conversation = DB::table('conversations')->where('id', $conversationId)->first();
            echo "<p><em>Profile Status: " . ($conversation->is_profile_completed ? 'Completed' : 'Incomplete') . "</em></p>\n";
            echo "<p><em>Current Step: " . ($conversation->profile_step ?? 'None') . "</em></p>\n";
            
        } else {
            echo "<p style='color: red;'>❌ No response from bot</p>\n";
        }
        
    } catch (\Exception $e) {
        echo "<p style='color: red;'>❌ Error processing step: " . $e->getMessage() . "</p>\n";
    }
    
    echo "<hr>\n";
    $stepNumber++;
}

// Clean up test data
echo "<h3>🧹 Cleaning Up</h3>\n";
try {
    DB::table('conversations')->where('id', $conversationId)->delete();
    DB::table('messages')->where('conversation_id', $conversationId)->delete();
    echo "<p style='color: green;'>✅ Test data cleaned up</p>\n";
} catch (\Exception $e) {
    echo "<p style='color: orange;'>⚠️ Could not clean up test data: " . $e->getMessage() . "</p>\n";
}

// Summary
echo "<h3>📊 Test Summary</h3>\n";
echo "<div style='background: #f0f8ff; padding: 15px; border-radius: 5px;'>\n";
echo "<h4>Expected Onboarding Flow:</h4>\n";
echo "<ol>\n";
echo "<li><strong>User sends any message</strong> → Bot asks for full name</li>\n";
echo "<li><strong>User provides name</strong> → Bot thanks them and asks for email</li>\n";
echo "<li><strong>User provides email</strong> → Bot confirms profile complete and lists available topics</li>\n";
echo "<li><strong>User asks questions</strong> → Bot provides FAQ answers or uses AI</li>\n";
echo "</ol>\n";
echo "</div>\n";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin-top: 10px;'>\n";
echo "<h4>⚠️ Important Notes:</h4>\n";
echo "<ul>\n";
echo "<li>During onboarding, bot should NEVER answer visa/education questions</li>\n";
echo "<li>Bot should ONLY ask for name and email until profile is complete</li>\n";
echo "<li>No AI responses should happen during onboarding</li>\n";
echo "<li>Only after email confirmation should bot answer questions</li>\n";
echo "</ul>\n";
echo "</div>\n";

echo "<h3>🚀 Ready for Production!</h3>\n";
echo "<p>If all tests passed, the onboarding flow is working correctly.</p>\n";

?>
