// main.js - Gemini AI Chat App
console.log('🚀 Initializing Gemini AI Chat App...');

const AppState = {
    currentChatId: null,
    chats: [],
    messages: [],
    isProcessing: false,
    uploadedFile: null,
    apiKey: localStorage.getItem('gemini_api_key') || '',
model: localStorage.getItem('gemini_model') || 'gemini-2.5-flash',
    temperature: parseFloat(localStorage.getItem('gemini_temperature') || '0.7')
};

let DOM = {};

document.addEventListener('DOMContentLoaded', function() {
    cacheDomElements();
    loadSettings();
    loadChats();
    setupEventListeners();
    checkSharedChat();
    updateSendButton();
    console.log('✅ App ready! Model:', AppState.model);
    console.log('📝 API Key:', AppState.apiKey ? 'Set ✓' : 'Not set - Get free key at https://aistudio.google.com/apikey');
});

function cacheDomElements() {
    DOM.sidebar = document.getElementById('sidebar');
    DOM.pinnedChats = document.getElementById('pinnedChats');
    DOM.recentChats = document.getElementById('recentChats');
    DOM.messagesContainer = document.getElementById('messagesContainer');
    DOM.welcomeScreen = document.getElementById('welcomeScreen');
    DOM.messageInput = document.getElementById('messageInput');
    DOM.sendButton = document.getElementById('sendButton');
    DOM.chatTitle = document.getElementById('chatTitle');
    DOM.chatActions = document.getElementById('chatActions');
    DOM.filePreview = document.getElementById('filePreview');
    DOM.fileName = document.getElementById('fileName');
    DOM.pinIcon = document.getElementById('pinIcon');
}

function setupEventListeners() {
    DOM.messageInput.addEventListener('input', function() {
        autoResize(this);
        updateSendButton();
    });
    
    DOM.messageInput.addEventListener('keydown', handleKeyDown);
    document.addEventListener('paste', handlePaste);
    
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768 && DOM.sidebar && 
            !DOM.sidebar.contains(e.target) && 
            !e.target.closest('.toggle-sidebar-btn')) {
            DOM.sidebar.classList.remove('open');
        }
    });
}

// Chat Management
async function loadChats() {
    try {
        const formData = new FormData();
        formData.append('action', 'get_chats');
        
        const response = await fetch('main.php', { method: 'POST', body: formData });
        const chats = await response.json();
        
        AppState.chats = Array.isArray(chats) ? chats : [];
        renderChats();
    } catch (error) {
        console.error('Load chats error:', error);
        AppState.chats = [];
        renderChats();
    }
}

function renderChats(filter = '') {
    if (!DOM.pinnedChats || !DOM.recentChats) return;
    
    const filtered = AppState.chats.filter(c => 
        (c.title || '').toLowerCase().includes(filter.toLowerCase())
    );
    
    const pinned = filtered.filter(c => c.pinned).sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));
    const recent = filtered.filter(c => !c.pinned).sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));
    
    DOM.pinnedChats.innerHTML = pinned.length ? pinned.map(createChatItemHTML).join('') : 
        '<div class="chat-item"><span style="color:#94a3b8;font-size:13px;">No pinned chats</span></div>';
    
    DOM.recentChats.innerHTML = recent.length ? recent.map(createChatItemHTML).join('') : 
        '<div class="chat-item"><span style="color:#94a3b8;font-size:13px;">No recent chats</span></div>';
}

function createChatItemHTML(chat) {
    const isActive = chat.id === AppState.currentChatId;
    const date = new Date(chat.updated_at).toLocaleDateString();
    
    return `
        <div class="chat-item ${isActive ? 'active' : ''}" onclick="openChat('${chat.id}')">
            <div class="chat-item-content">
                <div class="chat-item-title">${escapeHtml(chat.title || 'Untitled Chat')}</div>
                <div class="chat-item-date">${date}</div>
            </div>
            <div class="chat-item-actions">
                <button onclick="event.stopPropagation(); togglePinChat('${chat.id}')">
                    <i class="fas fa-thumbtack" style="color:${chat.pinned ? '#4f46e5' : '#94a3b8'}"></i>
                </button>
                <button onclick="event.stopPropagation(); renameChatPrompt('${chat.id}')">
                    <i class="fas fa-edit"></i>
                </button>
                <button onclick="event.stopPropagation(); shareChat('${chat.id}')">
                    <i class="fas fa-share-alt"></i>
                </button>
                <button onclick="event.stopPropagation(); deleteChatPrompt('${chat.id}')">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
}

function createNewChat() {
    AppState.currentChatId = 'chat_' + Date.now();
    AppState.messages = [];
    AppState.uploadedFile = null;
    
    DOM.chatTitle.textContent = 'New Chat';
    DOM.chatActions.style.display = 'none';
    DOM.welcomeScreen.style.display = 'flex';
    
    const msgs = DOM.messagesContainer.querySelectorAll('.message');
    msgs.forEach(m => m.remove());
    
    if (!DOM.messagesContainer.contains(DOM.welcomeScreen)) {
        DOM.messagesContainer.appendChild(DOM.welcomeScreen);
    }
    
    removeFile();
    updateSendButton();
    renderChats();
    
    if (window.innerWidth <= 768 && DOM.sidebar) {
        DOM.sidebar.classList.remove('open');
    }
}

function openChat(chatId) {
    const chat = AppState.chats.find(c => c.id === chatId);
    if (!chat) return;
    
    AppState.currentChatId = chatId;
    AppState.messages = chat.messages || [];
    AppState.uploadedFile = null;
    
    DOM.chatTitle.textContent = chat.title || 'Untitled Chat';
    DOM.chatActions.style.display = 'flex';
    DOM.welcomeScreen.style.display = 'none';
    
    updatePinIcon(chat.pinned);
    renderMessages();
    renderChats();
}

// Messaging
async function sendMessage(messageText = null) {
    if (AppState.isProcessing) return;
    
    const text = messageText || DOM.messageInput.value.trim();
    
    if (!text && !AppState.uploadedFile) return;
    
    if (!AppState.apiKey) {
        showToast('Please set your Gemini API key in Settings ⚙️', 'error');
        openModal('settingsModal');
        return;
    }
    
    console.log('📤 Sending:', text.substring(0, 50));
    
    AppState.isProcessing = true;
    updateSendButton();
    
    DOM.welcomeScreen.style.display = 'none';
    DOM.chatActions.style.display = 'flex';
    
    const userMessage = {
        role: 'user',
        content: AppState.uploadedFile ? `[File: ${AppState.uploadedFile.name}]\n${text || 'Analyze this file'}` : text
    };
    
    AppState.messages.push(userMessage);
    renderMessages();
    DOM.messageInput.value = '';
    autoResize(DOM.messageInput);
    
    const typingId = showTypingIndicator();
    
    try {
        const formData = new FormData();
        formData.append('action', 'chat');
        formData.append('message', text);
        formData.append('chat_id', AppState.currentChatId);
        formData.append('api_key', AppState.apiKey);
        formData.append('model', AppState.model);
        formData.append('temperature', AppState.temperature.toString());
        
        if (AppState.uploadedFile) {
            formData.append('file_content', AppState.uploadedFile.content || '');
            formData.append('file_mime_type', AppState.uploadedFile.mime_type || '');
            if (AppState.uploadedFile.filepath) {
                formData.append('file_path', AppState.uploadedFile.filepath);
            }
        }
        
        console.log('🌐 Calling Gemini API...');
        
        const response = await fetch('main.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });
        
        const responseText = await response.text();
        console.log('📥 Response:', responseText.substring(0, 300));
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            throw new Error('Invalid server response');
        }
        
        removeTypingIndicator(typingId);
        
        if (data.success && data.message) {
            AppState.messages.push({
                role: 'assistant',
                content: data.message
            });
            renderMessages();
            
            if (AppState.messages.length === 2) {
                DOM.chatTitle.textContent = text.substring(0, 50) + (text.length > 50 ? '...' : '');
            }
            
            await saveCurrentChat();
            console.log('✅ Success!');
        } else {
            throw new Error(data.error || 'No response');
        }
    } catch (error) {
        removeTypingIndicator(typingId);
        console.error('❌ Error:', error);
        showToast(error.message || 'Network error', 'error');
        AppState.messages.pop();
        renderMessages();
    }
    
    AppState.isProcessing = false;
    AppState.uploadedFile = null;
    removeFile();
    updateSendButton();
}

function renderMessages() {
    if (!DOM.messagesContainer) return;
    
    if (DOM.welcomeScreen && DOM.welcomeScreen.parentNode === DOM.messagesContainer) {
        DOM.messagesContainer.removeChild(DOM.welcomeScreen);
    }
    
    DOM.messagesContainer.querySelectorAll('.message').forEach(m => m.remove());
    
    AppState.messages.forEach(msg => {
        const div = document.createElement('div');
        div.className = `message ${msg.role === 'user' ? 'user' : 'assistant'}`;
        div.innerHTML = `
            <div class="message-avatar">
                <i class="fas fa-${msg.role === 'user' ? 'user' : 'robot'}"></i>
            </div>
            <div class="message-content">${formatMessage(msg.content)}</div>
        `;
        DOM.messagesContainer.appendChild(div);
    });
    
    DOM.messagesContainer.scrollTop = DOM.messagesContainer.scrollHeight;
}

function formatMessage(content) {
    if (!content) return '';
    let html = escapeHtml(content);
    html = html.replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    html = html.replace(/\n/g, '<br>');
    return html;
}

function showTypingIndicator() {
    if (!DOM.messagesContainer) return null;
    const id = 'typing-' + Date.now();
    const div = document.createElement('div');
    div.className = 'message assistant';
    div.id = id;
    div.innerHTML = `
        <div class="message-avatar"><i class="fas fa-robot"></i></div>
        <div class="message-content">
            <div class="typing-indicator"><span></span><span></span><span></span></div>
        </div>
    `;
    DOM.messagesContainer.appendChild(div);
    DOM.messagesContainer.scrollTop = DOM.messagesContainer.scrollHeight;
    return id;
}

function removeTypingIndicator(id) {
    if (!id) return;
    const el = document.getElementById(id);
    if (el) el.remove();
}

async function saveCurrentChat() {
    if (!AppState.currentChatId || !AppState.messages.length) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'save_chat');
        formData.append('chat_id', AppState.currentChatId);
        formData.append('title', DOM.chatTitle.textContent);
        formData.append('messages', JSON.stringify(AppState.messages));
        
        await fetch('main.php', { method: 'POST', body: formData });
        await loadChats();
    } catch (error) {
        console.error('Save error:', error);
    }
}

// File Handling
async function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    if (file.size > 10 * 1024 * 1024) {
        showToast('File too large. Max 10MB', 'error');
        event.target.value = '';
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('file', file);
    
    try {
        const response = await fetch('main.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            AppState.uploadedFile = {
                name: data.filename,
                content: data.content,
                mime_type: data.mime_type,
                filepath: data.filepath,
                size: data.size
            };
            showFilePreview(data.filename);
            updateSendButton();
            showToast('File uploaded ✓', 'success');
        } else {
            throw new Error(data.error || 'Upload failed');
        }
    } catch (error) {
        showToast('Upload failed: ' + error.message, 'error');
    }
    
    event.target.value = '';
}

function showFilePreview(filename) {
    DOM.filePreview.style.display = 'block';
    DOM.fileName.textContent = filename;
}

function removeFile() {
    AppState.uploadedFile = null;
    DOM.filePreview.style.display = 'none';
    updateSendButton();
}

async function handlePaste(event) {
    const items = event.clipboardData?.items;
    if (!items) return;
    
    for (const item of items) {
        if (item.type.startsWith('image/')) {
            event.preventDefault();
            const file = item.getAsFile();
            
            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('file', file, 'pasted-image.png');
            
            try {
                const response = await fetch('main.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    AppState.uploadedFile = {
                        name: 'pasted-image.png',
                        content: data.content,
                        mime_type: data.mime_type,
                        filepath: data.filepath,
                        size: data.size
                    };
                    showFilePreview('pasted-image.png');
                    updateSendButton();
                    showToast('Image attached ✓', 'success');
                }
            } catch (error) {
                console.error('Paste error:', error);
            }
            break;
        }
    }
}

// Chat Actions
async function togglePinChat(chatId = null) {
    const id = chatId || AppState.currentChatId;
    if (!id) return;
    
    const chat = AppState.chats.find(c => c.id === id);
    if (!chat) return;
    
    const pinned = !chat.pinned;
    
    const formData = new FormData();
    formData.append('action', 'pin_chat');
    formData.append('chat_id', id);
    formData.append('pinned', pinned.toString());
    
    try {
        await fetch('main.php', { method: 'POST', body: formData });
        await loadChats();
        if (id === AppState.currentChatId) updatePinIcon(pinned);
        showToast(pinned ? 'Pinned ✓' : 'Unpinned', 'success');
    } catch (error) {
        showToast('Failed to pin', 'error');
    }
}

function updatePinIcon(pinned) {
    if (!DOM.pinIcon) return;
    DOM.pinIcon.style.color = pinned ? '#4f46e5' : '#94a3b8';
    DOM.pinIcon.style.transform = pinned ? 'rotate(0deg)' : 'rotate(45deg)';
}

async function renameChatPrompt(chatId) {
    const chat = AppState.chats.find(c => c.id === chatId);
    if (!chat) return;
    
    const newTitle = prompt('Enter new name:', chat.title);
    if (!newTitle?.trim()) return;
    
    const formData = new FormData();
    formData.append('action', 'rename_chat');
    formData.append('chat_id', chatId);
    formData.append('title', newTitle.trim());
    
    try {
        await fetch('main.php', { method: 'POST', body: formData });
        if (chatId === AppState.currentChatId) DOM.chatTitle.textContent = newTitle.trim();
        await loadChats();
        showToast('Renamed ✓', 'success');
    } catch (error) {
        showToast('Failed to rename', 'error');
    }
}

async function shareChat(chatId) {
    const formData = new FormData();
    formData.append('action', 'share_chat');
    formData.append('chat_id', chatId);
    
    try {
        const response = await fetch('main.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('shareLink').value = data.share_url;
            openModal('shareModal');
        }
    } catch (error) {
        showToast('Failed to share', 'error');
    }
}

function copyShareLink() {
    const input = document.getElementById('shareLink');
    input.select();
    document.execCommand('copy');
    showToast('Link copied! ✓', 'success');
}

async function deleteChatPrompt(chatId) {
    openModal('deleteModal');
    document.getElementById('confirmDeleteBtn').onclick = async () => {
        const formData = new FormData();
        formData.append('action', 'delete_chat');
        formData.append('chat_id', chatId);
        
        try {
            await fetch('main.php', { method: 'POST', body: formData });
            if (chatId === AppState.currentChatId) createNewChat();
            await loadChats();
            closeModal('deleteModal');
            showToast('Deleted ✓', 'success');
        } catch (error) {
            showToast('Failed to delete', 'error');
        }
    };
}

// Settings
function openSettings() {
    document.getElementById('apiKeyInput').value = AppState.apiKey;
    document.getElementById('modelSelect').value = AppState.model;
    document.getElementById('temperatureRange').value = AppState.temperature;
    document.getElementById('temperatureValue').textContent = AppState.temperature;
    openModal('settingsModal');
}

function saveApiKey() {
    const apiKey = document.getElementById('apiKeyInput').value.trim();
    
    if (!apiKey) {
        showToast('Please enter API key', 'error');
        return;
    }
    
    AppState.apiKey = apiKey;
    localStorage.setItem('gemini_api_key', apiKey);
    showToast('API key saved! ✓', 'success');
    console.log('🔑 Key saved');
}

function loadSettings() {
    document.getElementById('modelSelect').addEventListener('change', function() {
        AppState.model = this.value;
        localStorage.setItem('gemini_model', this.value);
    });
    
    document.getElementById('temperatureRange').addEventListener('input', function() {
        AppState.temperature = parseFloat(this.value);
        document.getElementById('temperatureValue').textContent = this.value;
        localStorage.setItem('gemini_temperature', this.value);
    });
}

// Shared Chat
async function checkSharedChat() {
    const urlParams = new URLSearchParams(window.location.search);
    const shareId = urlParams.get('share');
    if (!shareId) return;
    
    try {
        const response = await fetch(`main.php?action=get_shared_chat&share_id=${shareId}`);
        const data = await response.json();
        
        if (data.success) {
            AppState.messages = data.chat.messages || [];
            DOM.chatTitle.textContent = (data.chat.title || 'Shared Chat') + ' (Shared)';
            DOM.welcomeScreen.style.display = 'none';
            DOM.chatActions.style.display = 'none';
            renderMessages();
        }
    } catch (error) {
        console.error('Shared chat error:', error);
    }
}

// UI Helpers
function toggleSidebar() {
    if (window.innerWidth <= 768) {
        DOM.sidebar.classList.toggle('open');
    } else {
        DOM.sidebar.classList.toggle('closed');
    }
}

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const icons = { success: '✓', error: '✗', info: 'ℹ' };
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = `${icons[type] || ''} ${message}`;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function handleKeyDown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

function autoResize(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 200) + 'px';
}

function updateSendButton() {
    const hasContent = DOM.messageInput.value.trim() || AppState.uploadedFile;
    DOM.sendButton.disabled = !hasContent || AppState.isProcessing;
    DOM.sendButton.innerHTML = AppState.isProcessing ? 
        '<i class="fas fa-spinner fa-spin"></i>' : 
        '<i class="fas fa-arrow-up"></i>';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Global functions
window.createNewChat = createNewChat;
window.openChat = openChat;
window.sendMessage = sendMessage;
window.togglePinChat = togglePinChat;
window.renameChatPrompt = renameChatPrompt;
window.renameCurrentChat = () => { if (AppState.currentChatId) renameChatPrompt(AppState.currentChatId); };
window.shareChat = shareChat;
window.shareCurrentChat = () => { if (AppState.currentChatId) shareChat(AppState.currentChatId); };
window.deleteChatPrompt = deleteChatPrompt;
window.deleteCurrentChat = () => { if (AppState.currentChatId) deleteChatPrompt(AppState.currentChatId); };
window.searchChats = renderChats;
window.openSettings = openSettings;
window.saveApiKey = saveApiKey;
window.toggleSidebar = toggleSidebar;
window.openModal = openModal;
window.closeModal = closeModal;
window.copyShareLink = copyShareLink;
window.sendSuggestion = (text) => { DOM.messageInput.value = text; sendMessage(); };
window.handleFileSelect = handleFileSelect;
window.removeFile = removeFile;
window.handleKeyDown = handleKeyDown;







