<?php
// api/chat.php - Working with Gemini 2.5 Flash (FREE)

function handleChatRequest() {
    $message = $_POST['message'] ?? '';
    $chatId = $_POST['chat_id'] ?? '';
    $fileContent = $_POST['file_content'] ?? '';
    $fileMimeType = $_POST['file_mime_type'] ?? '';
    
    if (empty($message) && empty($fileContent)) {
        echo json_encode(['error' => 'Message is required']);
        return;
    }
    
    // Get API key
    $apiKey = $_POST['api_key'] ?? getenv('GEMINI_API_KEY') ?? '';
    
    if (empty($apiKey)) {
        echo json_encode(['error' => 'API key is required. Get free key from https://aistudio.google.com/apikey']);
        return;
    }
    
    // Use the working model
    $model = 'gemini-2.5-flash'; // ✅ CONFIRMED WORKING
    
    // Build conversation
    $contents = [];
    
    // Add conversation history
    if ($chatId) {
        $history = getChatHistory($chatId);
        if (!empty($history)) {
            $contents = $history;
        }
    }
    
    // Add current message
    $parts = [];
    
    if ($fileContent && $fileMimeType) {
        if (strpos($fileMimeType, 'image/') === 0) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $fileMimeType,
                    'data' => $fileContent
                ]
            ];
        }
        if (!empty($message)) {
            $parts[] = ['text' => $message . "\n\nFile content: " . $fileContent];
        } else {
            $parts[] = ['text' => "Analyze this: " . $fileContent];
        }
    } else {
        $parts[] = ['text' => $message];
    }
    
    $contents[] = [
        'role' => 'user',
        'parts' => $parts
    ];
    
    // Call Gemini API
    $response = callGeminiAPI($apiKey, $model, $contents);
    
    if ($response['success']) {
        echo json_encode([
            'success' => true,
            'message' => $response['data'],
            'model' => $model
        ]);
    } else {
        echo json_encode(['error' => $response['error'] ?? 'Failed to get response']);
    }
}

function callGeminiAPI($apiKey, $model, $contents) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    
    $data = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => floatval($_POST['temperature'] ?? 0.7),
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 8192,
        ]
    ];
    
    $jsonData = json_encode($data);
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($jsonData),
            'content' => $jsonData,
            'timeout' => 60,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        return ['success' => false, 'error' => 'Connection failed: ' . ($error['message'] ?? 'Unknown error')];
    }
    
    $decoded = json_decode($response, true);
    
    if (!$decoded) {
        return ['success' => false, 'error' => 'Invalid API response'];
    }
    
    if (isset($decoded['error'])) {
        return ['success' => false, 'error' => $decoded['error']['message'] ?? 'API Error'];
    }
    
    if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        $finishReason = $decoded['candidates'][0]['finishReason'] ?? 'UNKNOWN';
        return ['success' => false, 'error' => 'No response. Reason: ' . $finishReason];
    }
    
    return ['success' => true, 'data' => $decoded['candidates'][0]['content']['parts'][0]['text']];
}

function handleFileUpload() {
    if (!isset($_FILES['file'])) {
        echo json_encode(['error' => 'No file uploaded']);
        return;
    }
    
    $file = $_FILES['file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Upload error: ' . $file['error']]);
        return;
    }
    
    $uploadDir = __DIR__ . '/../uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['error' => 'File too large. Max 10MB']);
        return;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $filename = uniqid() . '_' . basename($file['name']);
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        $content = '';
        
        if (strpos($mimeType, 'image/') === 0) {
            // For images, return base64
            $content = base64_encode(file_get_contents($filepath));
        } else {
            // For text files
            $content = file_get_contents($filepath);
            if ($content !== false) {
                $content = substr($content, 0, 50000);
            }
        }
        
        echo json_encode([
            'success' => true,
            'filename' => $file['name'],
            'filepath' => $filepath,
            'content' => $content,
            'mime_type' => $mimeType,
            'size' => $file['size']
        ]);
    } else {
        echo json_encode(['error' => 'Failed to save file']);
    }
}

function getChats() {
    $chatsFile = __DIR__ . '/../data/chats.json';
    
    if (!file_exists($chatsFile)) {
        echo json_encode([]);
        return;
    }
    
    $chats = json_decode(file_get_contents($chatsFile), true) ?? [];
    $userId = $_SESSION['user_id'] ?? 'default';
    
    $userChats = array_filter($chats, function($chat) use ($userId) {
        return ($chat['user_id'] ?? '') === $userId;
    });
    
    echo json_encode(array_values($userChats));
}

function saveChat() {
    $chatsFile = __DIR__ . '/../data/chats.json';
    $dataDir = dirname($chatsFile);
    
    if (!file_exists($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    
    $chats = file_exists($chatsFile) ? 
        json_decode(file_get_contents($chatsFile), true) ?? [] : [];
    
    $chatId = $_POST['chat_id'] ?? uniqid('chat_', true);
    $title = $_POST['title'] ?? 'New Chat';
    $messages = json_decode($_POST['messages'] ?? '[]', true);
    $userId = $_SESSION['user_id'] ?? 'default';
    
    $found = false;
    foreach ($chats as &$chat) {
        if ($chat['id'] === $chatId) {
            $chat['messages'] = $messages;
            $chat['title'] = $title;
            $chat['updated_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    unset($chat);
    
    if (!$found) {
        $chats[] = [
            'id' => $chatId,
            'user_id' => $userId,
            'title' => $title,
            'messages' => $messages,
            'pinned' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    file_put_contents($chatsFile, json_encode($chats, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'chat_id' => $chatId]);
}

function renameChat() {
    $chatsFile = __DIR__ . '/../data/chats.json';
    $chats = json_decode(file_get_contents($chatsFile), true) ?? [];
    
    $chatId = $_POST['chat_id'] ?? '';
    $newTitle = $_POST['title'] ?? '';
    
    foreach ($chats as &$chat) {
        if ($chat['id'] === $chatId) {
            $chat['title'] = $newTitle;
            break;
        }
    }
    
    file_put_contents($chatsFile, json_encode($chats, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
}

function deleteChat() {
    $chatsFile = __DIR__ . '/../data/chats.json';
    $chats = json_decode(file_get_contents($chatsFile), true) ?? [];
    
    $chatId = $_POST['chat_id'] ?? '';
    
    $chats = array_values(array_filter($chats, function($chat) use ($chatId) {
        return $chat['id'] !== $chatId;
    }));
    
    file_put_contents($chatsFile, json_encode($chats, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
}

function pinChat() {
    $chatsFile = __DIR__ . '/../data/chats.json';
    $chats = json_decode(file_get_contents($chatsFile), true) ?? [];
    
    $chatId = $_POST['chat_id'] ?? '';
    $pinned = ($_POST['pinned'] ?? 'false') === 'true';
    
    foreach ($chats as &$chat) {
        if ($chat['id'] === $chatId) {
            $chat['pinned'] = $pinned;
            break;
        }
    }
    
    file_put_contents($chatsFile, json_encode($chats, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
}

function shareChat() {
    $chatsFile = __DIR__ . '/../data/chats.json';
    $chats = json_decode(file_get_contents($chatsFile), true) ?? [];
    
    $chatId = $_POST['chat_id'] ?? '';
    $shareId = uniqid('share_', true);
    
    foreach ($chats as &$chat) {
        if ($chat['id'] === $chatId) {
            $chat['share_id'] = $shareId;
            $chat['shared'] = true;
            break;
        }
    }
    
    file_put_contents($chatsFile, json_encode($chats, JSON_PRETTY_PRINT));
    
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['REQUEST_URI'] ?? '/');
    $shareUrl = $protocol . $host . $path . '/main.php?share=' . $shareId;
    
    echo json_encode(['success' => true, 'share_url' => $shareUrl]);
}

function getChatHistory($chatId) {
    $chatsFile = __DIR__ . '/../data/chats.json';
    
    if (!file_exists($chatsFile)) {
        return [];
    }
    
    $chats = json_decode(file_get_contents($chatsFile), true) ?? [];
    
    foreach ($chats as $chat) {
        if ($chat['id'] === $chatId) {
            $contents = [];
            $messages = $chat['messages'] ?? [];
            
            foreach ($messages as $msg) {
                if ($msg['role'] === 'user') {
                    $contents[] = [
                        'role' => 'user',
                        'parts' => [['text' => $msg['content']]]
                    ];
                } else if ($msg['role'] === 'assistant') {
                    $contents[] = [
                        'role' => 'model',
                        'parts' => [['text' => $msg['content']]]
                    ];
                }
            }
            
            return $contents;
        }
    }
    
    return [];
}

function getSharedChat() {
    $shareId = $_GET['share_id'] ?? '';
    $chatsFile = __DIR__ . '/../data/chats.json';
    
    if (!file_exists($chatsFile)) {
        echo json_encode(['error' => 'No chats found']);
        return;
    }
    
    $chats = json_decode(file_get_contents($chatsFile), true) ?? [];
    
    foreach ($chats as $chat) {
        if (($chat['share_id'] ?? '') === $shareId) {
            echo json_encode(['success' => true, 'chat' => $chat]);
            return;
        }
    }
    
    echo json_encode(['error' => 'Shared chat not found']);
}
?>
