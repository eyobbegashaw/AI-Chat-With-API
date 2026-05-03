<?php
// test_gemini.php - Test your Gemini API key

$apiKey = 'AIzaSyBcJ-BL4rNqkdLKkPrdhhobs7Xqd2kzb3w';

// List available models
$url = "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}";

$response = @file_get_contents($url);

if ($response === false) {
    die("❌ Cannot connect to Gemini API. Check your internet connection.");
}

$data = json_decode($response, true);

echo "<h2>✅ API Key is working!</h2>";
echo "<h3>Available Models:</h3>";
echo "<pre>";

if (isset($data['models'])) {
    foreach ($data['models'] as $model) {
        $name = $model['name'];
        // Remove 'models/' prefix
        $shortName = str_replace('models/', '', $name);
        echo "📌 Model: <strong>{$shortName}</strong><br>";
        echo "   Description: " . ($model['description'] ?? 'N/A') . "<br>";
        echo "   Supported methods: " . implode(', ', $model['supportedGenerationMethods'] ?? []) . "<br><br>";
    }
} else {
    echo "Error: " . ($data['error']['message'] ?? 'Unknown error');
}
echo "</pre>";

// Try a simple chat test with the first available model
if (isset($data['models']) && count($data['models']) > 0) {
    $firstModel = str_replace('models/', '', $data['models'][0]['name']);
    
    echo "<h3>Testing chat with model: <strong>{$firstModel}</strong></h3>";
    
    $chatUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$firstModel}:generateContent?key={$apiKey}";
    
    $postData = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => 'Say "Hello! Your API is working!" in English and Amharic']
                ]
            ]
        ]
    ]);
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $postData,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $chatResponse = @file_get_contents($chatUrl, false, $context);
    
    if ($chatResponse) {
        $chatData = json_decode($chatResponse, true);
        if (isset($chatData['candidates'][0]['content']['parts'][0]['text'])) {
            echo "<div style='background:#1e293b;color:#f1f5f9;padding:20px;border-radius:8px;'>";
            echo "<strong>✅ Response:</strong><br><br>";
            echo nl2br(htmlspecialchars($chatData['candidates'][0]['content']['parts'][0]['text']));
            echo "</div>";
        } else {
            echo "<pre>Response: " . print_r($chatData, true) . "</pre>";
        }
    }
}
?>