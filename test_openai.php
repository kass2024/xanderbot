<?php
/**
 * OpenAI Connection Test
 * 
 * Tests if OpenAI is properly configured and working
 */

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

echo "<h2>🤖 OpenAI Connection Test</h2>\n";

// Check configuration
$apiKey = config('services.openai.key');
$model = config('services.openai.model', 'gpt-4');

echo "<h3>📋 Configuration Check</h3>\n";
echo "<p>API Key: " . ($apiKey ? "✅ Configured (" . substr($apiKey, 0, 10) . "...)" : "❌ Not configured") . "</p>\n";
echo "<p>Model: $model</p>\n";

if (!$apiKey) {
    echo "<p style='color: red;'>❌ OpenAI API key not found in configuration</p>\n";
    echo "<p>Please run configure_openai.php first</p>\n";
    exit;
}

// Test OpenAI API connection
echo "<h3>🔗 API Connection Test</h3>\n";

try {
    $testPrompt = "You are a helpful assistant. Say 'Hello, OpenAI is working!' in a friendly way.";
    
    $response = Http::withToken($apiKey)
        ->timeout(30)
        ->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $testPrompt]
            ],
            'temperature' => 0.3,
            'max_tokens' => 50
        ]);

    if ($response->successful()) {
        $result = $response->json();
        $content = $result['choices'][0]['message']['content'] ?? 'No content';
        $tokens = $result['usage']['total_tokens'] ?? 0;
        
        echo "<p style='color: green;'>✅ OpenAI API connection successful!</p>\n";
        echo "<p><strong>Response:</strong> $content</p>\n";
        echo "<p><strong>Tokens used:</strong> $tokens</p>\n";
        
        // Test with visa-related question
        echo "<h3>🎓 Visa Consultant Test</h3>\n";
        
        $visaPrompt = "As a visa consultant, briefly explain what documents are typically needed for a student visa application.";
        
        $visaResponse = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system', 
                        'content' => 'You are a professional visa and education consultant for Xander Global Scholars. Provide accurate, helpful information about visa services and study abroad programs.'
                    ],
                    ['role' => 'user', 'content' => $visaPrompt]
                ],
                'temperature' => 0.3,
                'max_tokens' => 200
            ]);

        if ($visaResponse->successful()) {
            $visaResult = $visaResponse->json();
            $visaContent = $visaResult['choices'][0]['message']['content'] ?? 'No content';
            $visaTokens = $visaResult['usage']['total_tokens'] ?? 0;
            
            echo "<p style='color: green;'>✅ Visa consultant test successful!</p>\n";
            echo "<p><strong>Response:</strong> " . substr($visaContent, 0, 200) . "...</p>\n";
            echo "<p><strong>Tokens used:</strong> $visaTokens</p>\n";
            
        } else {
            echo "<p style='color: orange;'>⚠️ Visa consultant test failed</p>\n";
            echo "<p>Status: " . $visaResponse->status() . "</p>\n";
        }
        
    } else {
        echo "<p style='color: red;'>❌ OpenAI API connection failed</p>\n";
        echo "<p><strong>Status:</strong> " . $response->status() . "</p>\n";
        echo "<p><strong>Response:</strong> " . $response->body() . "</p>\n";
        
        // Common errors and solutions
        echo "<h3>🔧 Common Issues & Solutions</h3>\n";
        $status = $response->status();
        
        if ($status === 401) {
            echo "<p><strong>Error 401 - Invalid API Key:</strong><br>\n";
            echo "• Check that your API key is correct<br>\n";
            echo "• Ensure the key has sufficient credits<br>\n";
            echo "• Verify the key is active and not expired</p>\n";
        } elseif ($status === 429) {
            echo "<p><strong>Error 429 - Rate Limited:</strong><br>\n";
            echo "• You've hit the rate limit<br>\n";
            echo "• Wait a few minutes and try again<br>\n";
            echo "• Check your OpenAI usage limits</p>\n";
        } elseif ($status === 403) {
            echo "<p><strong>Error 403 - Access Denied:</strong><br>\n";
            echo "• Check your API key permissions<br>\n";
            echo "• Ensure your account is in good standing</p>\n";
        } else {
            echo "<p><strong>Error $status:</strong><br>\n";
            echo "• Check your internet connection<br>\n";
            echo "• Verify the API key is correct<br>\n";
            echo "• Try again in a few moments</p>\n";
        }
    }
    
} catch (\Exception $e) {
    echo "<p style='color: red;'>❌ Exception occurred: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<h3>📊 Test Summary</h3>\n";
echo "<table border='1' style='border-collapse: collapse; padding: 5px;'>\n";
echo "<tr><th>Test</th><th>Status</th><th>Result</th></tr>\n";
echo "<tr><td>Configuration</td><td>" . ($apiKey ? '✅ OK' : '❌ Missing') . "</td><td>API key loaded</td></tr>\n";
echo "<tr><td>API Connection</td><td>" . ($response->successful() ?? '❌ Failed') . "</td><td>OpenAI reachable</td></tr>\n";
echo "<tr><td>Visa Consultant</td><td>" . ($visaResponse->successful() ?? '❌ Failed') . "</td><td>Professional responses</td></tr>\n";
echo "</table>\n";

if ($response->successful() ?? false) {
    echo "<h3>🚀 Ready for Chatbot!</h3>\n";
    echo "<p>Your OpenAI configuration is working. The chatbot will now:</p>\n";
    echo "<ul>\n";
    echo "<li>✅ Use OpenAI for questions not found in FAQs</li>\n";
    echo "<li>✅ Provide accurate visa and education information</li>\n";
    echo "<li>✅ Respond professionally as Xander Global Scholars</li>\n";
    echo "<li>✅ Handle complex queries intelligently</li>\n";
    echo "</ul>\n";
}

?>
