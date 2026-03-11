<!-- C Widget — Claudia, Your Civic Guide -->
<link rel="stylesheet" href="/assets/claudia/claudia.css">

<!-- Widget HTML -->
<div id="claudia-widget">
    <!-- Mode picker overlay -->
    <div id="claudia-mode-overlay">
        <div class="claudia-mode-picker">
            <h3>Hi, I'm Claudia</h3>
            <p class="claudia-intro">You can call me C. I'll guide you through getting set up. How would you like to chat?</p>
            <button class="claudia-mode-btn" data-mode="voice">
                <span class="claudia-mode-icon">🎤</span> Voice
                <span class="claudia-mode-desc">I'll speak, you speak</span>
            </button>
            <button class="claudia-mode-btn" data-mode="text">
                <span class="claudia-mode-icon">⌨️</span> Text
                <span class="claudia-mode-desc">Read and type</span>
            </button>
            <button class="claudia-mode-btn" data-mode="both">
                <span class="claudia-mode-icon">🔀</span> Both
                <span class="claudia-mode-desc">I'll speak + show text, you type or speak</span>
            </button>
            <button class="claudia-mode-dismiss" id="claudia-mode-dismiss">Maybe later</button>
        </div>
    </div>

    <!-- Collapsed bubble -->
    <div id="claudia-bubble">C</div>

    <!-- Expanded panel -->
    <div id="claudia-panel">
        <div class="claudia-header">
            <span class="claudia-header-title">C — Your Civic Guide</span>
            <button class="claudia-header-btn" id="claudia-settings-btn" title="Settings">⚙</button>
            <button class="claudia-header-btn" id="claudia-minimize-btn" title="Close">✕</button>
            <div class="claudia-settings-menu" id="claudia-settings-menu">
                <div class="claudia-settings-item" data-action="change-mode">Change interaction mode</div>
                <div class="claudia-settings-item" data-action="clear-chat">Clear conversation</div>
            </div>
        </div>
        <div class="claudia-messages" id="claudia-messages"></div>
        <div class="claudia-typing" id="claudia-typing">
            Claudia is thinking<span>.</span><span>.</span><span>.</span>
        </div>
        <div class="claudia-input-area">
            <button class="claudia-mic-btn" id="claudia-mic-btn" title="Voice input">🎤</button>
            <input type="text" class="claudia-text-input" id="claudia-text-input" placeholder="Type your message..." autocomplete="off">
            <button class="claudia-send-btn" id="claudia-send-btn">Send</button>
        </div>
    </div>
</div>

<script src="/assets/claudia/claudia-core.js"></script>
