<?php
session_start();
require_once 'api/chat.php';

// Initialize chat data file if not exists
$dataDir = __DIR__ . '/data';
$chatsFile = $dataDir . '/chats.json';

if (!file_exists($dataDir)) {
    mkdir($dataDir, 0755, true);
}

if (!file_exists($chatsFile)) {
    file_put_contents($chatsFile, json_encode([]));
}

// Initialize uploads directory
$uploadsDir = __DIR__ . '/uploads';
if (!file_exists($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

// Generate or get session ID
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = uniqid('user_', true);
}
$userId = $_SESSION['user_id'];

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'chat':
            handleChatRequest();
            break;
        case 'upload':
            handleFileUpload();
            break;
        case 'get_chats':
            getChats();
            break;
        case 'save_chat':
            saveChat();
            break;
        case 'rename_chat':
            renameChat();
            break;
        case 'delete_chat':
            deleteChat();
            break;
        case 'pin_chat':
            pinChat();
            break;
        case 'share_chat':
            shareChat();
            break;
        case 'get_shared_chat':
            getSharedChat();
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DeepSeek AI Chat Assistant</title>
    <link rel="stylesheet" href="main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <button class="new-chat-btn" onclick="createNewChat()">
                    <i class="fas fa-plus"></i> New Chat
                </button>
                <button class="toggle-sidebar-btn" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search chats..." oninput="searchChats(this.value)">
            </div>
            
            <div class="chats-container" id="chatsContainer">
                <!-- Pinned chats -->
                <div class="chat-section">
                    <h3 class="section-title">Pinned</h3>
                    <div class="chat-list" id="pinnedChats"></div>
                </div>
                
                <!-- Recent chats -->
                <div class="chat-section">
                    <h3 class="section-title">Recent</h3>
                    <div class="chat-list" id="recentChats"></div>
                </div>
            </div>
            
            <div class="sidebar-footer">
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo substr($userId, -8); ?></span>
                </div>
                <button class="settings-btn" onclick="openSettings()">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </aside>

        <!-- Main Chat Area -->
        <main class="main-content">
            <!-- Chat Header -->
            <header class="chat-header" id="chatHeader">
                <div class="chat-header-left">
                    <button class="toggle-sidebar-btn mobile" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h2 class="chat-title" id="chatTitle">New Chat</h2>
                    <span class="chat-model">DeepSeek Chat</span>
                </div>
                <div class="chat-header-right" id="chatActions" style="display: none;">
                    <button class="action-btn" onclick="togglePinChat()" title="Pin chat">
                        <i class="fas fa-thumbtack" id="pinIcon"></i>
                    </button>
                    <button class="action-btn" onclick="renameCurrentChat()" title="Rename">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="action-btn" onclick="shareCurrentChat()" title="Share">
                        <i class="fas fa-share-alt"></i>
                    </button>
                    <button class="action-btn danger" onclick="deleteCurrentChat()" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </header>

            <!-- Messages Container -->
            <div class="messages-container" id="messagesContainer">
                <div class="welcome-screen" id="welcomeScreen">
                    <div class="welcome-logo">
                        <i class="fas fa-robot"></i>
                    </div>
                    <h1>How can I help you today?</h1>
                    <div class="suggestion-grid">
                        <div class="suggestion-card" onclick="sendSuggestion('Explain quantum computing in simple terms')">
                            <i class="fas fa-atom"></i>
                            <span>Explain quantum computing</span>
                        </div>
                        <div class="suggestion-card" onclick="sendSuggestion('Write a Python script for data analysis')">
                            <i class="fas fa-code"></i>
                            <span>Write Python code</span>
                        </div>
                        <div class="suggestion-card" onclick="sendSuggestion('What are the latest AI trends in 2024?')">
                            <i class="fas fa-chart-line"></i>
                            <span>Latest AI trends</span>
                        </div>
                        <div class="suggestion-card" onclick="sendSuggestion('Help me plan a healthy meal plan')">
                            <i class="fas fa-utensils"></i>
                            <span>Plan healthy meals</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Input Area -->
            <div class="input-container">
                <div class="input-wrapper">
                    <div class="file-preview" id="filePreview" style="display: none;">
                        <div class="file-info">
                            <i class="fas fa-file"></i>
                            <span id="fileName"></span>
                            <button class="remove-file" onclick="removeFile()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="input-box">
                        <textarea 
                            id="messageInput" 
                            placeholder="Message DeepSeek..."
                            rows="1"
                            onkeydown="handleKeyDown(event)"
                            oninput="autoResize(this)"
                        ></textarea>
                        <div class="input-actions">
                            <input 
                                type="file" 
                                id="fileInput" 
                                style="display: none;" 
                                onchange="handleFileSelect(event)"
                                accept=".txt,.pdf,.doc,.docx,.csv,.json,.xml,.png,.jpg,.jpeg,.gif,.bmp,.webp"
                            >
                            <button class="upload-btn" onclick="document.getElementById('fileInput').click()" title="Upload file">
                                <i class="fas fa-paperclip"></i>
                            </button>
                            <button class="send-btn" onclick="sendMessage()" id="sendButton">
                                <i class="fas fa-arrow-up"></i>
                            </button>
                        </div>
                    </div>
                    <p class="disclaimer">DeepSeek AI Assistant. Verify important information.</p>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <div class="modal" id="shareModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Share Chat</h3>
                <button class="close-btn" onclick="closeModal('shareModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Share this chat with others via the link below:</p>
                <div class="share-link-container">
                    <input type="text" id="shareLink" readonly>
                    <button onclick="copyShareLink()">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="settingsModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Settings</h3>
			
                <button class="close-btn" onclick="closeModal('settingsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="setting-item">
                    <label>API Key</label>
                    <input type="password" id="apiKeyInput" placeholder="Enter your DeepSeek API key">
                    <button onclick="saveApiKey()">Save</button>
                </div>
                <div class="setting-item">
                    <label>Model</label>
<select id="modelSelect">
    <option value="gemini-2.5-flash">Gemini 2.5 Flash ⚡ (Best Free)</option>
    <option value="gemini-2.0-flash">Gemini 2.0 Flash</option>
    <option value="gemini-2.5-pro">Gemini 2.5 Pro 🧠</option>
    <option value="gemini-3-flash-preview">Gemini 3 Flash Preview</option>
</select>	
                </div>
                <div class="setting-item">
                    <label>Temperature</label>
                    <input type="range" id="temperatureRange" min="0" max="2" step="0.1" value="0.7">
                    <span id="temperatureValue">0.7</span>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="deleteModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Chat</h3>
                <button class="close-btn" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this chat? This action cannot be undone.</p>
                <div class="modal-actions">
                    <button class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                    <button class="btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <div id="toastContainer" class="toast-container"></div>

    <script src="main.js"></script>
</body>
</html>