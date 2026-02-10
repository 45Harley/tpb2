/**
 * TPB2 Modal Help System - JavaScript Library
 * ============================================
 * Universal modal help system that works on any HTML page
 * 
 * Usage:
 *   1. Include this script: <script src="modal-help.js"></script>
 *   2. Include the CSS: <link rel="stylesheet" href="modal-help.css">
 *   3. Add help tags: <span class="tpb-help" data-modal="voting_explained"></span>
 *   4. Initialize: TPBModalHelp.init({ apiBase: '/api/modal', page: 'voting_page' });
 * 
 * Version: 1.0
 * Date: November 25, 2025
 */

(function(window) {
    'use strict';

    // Icon mapping
    const ICONS = {
        info:       'ℹ️',
        help:       '❓',
        preview:    '⏳',
        warning:    '⚠️',
        tip:        '💡',
        docs:       '📘',
        tutorial:   '🎓',
        feature:    '🎯',
        success:    '✅',
        new:        '🚀',
        security:   '🔒',
        important:  '⭐',
        location:   '📍',
        external:   '🔗',
        social:     '👥',
        philosophy: '🎪'
    };

    // Default configuration
    const DEFAULT_CONFIG = {
        apiBase: '/api/modal',
        page: 'default',
        selector: '.tpb-help',
        enableAnalytics: true,
        enableTooltips: true,
        tooltipDelay: 300,
        modalAnimation: true,
        debug: false
    };

    // Module state
    let config = {};
    let modalsCache = {};
    let modalOverlay = null;
    let activeModal = null;

    /**
     * Initialize the Modal Help System
     * @param {Object} options Configuration options
     */
    function init(options = {}) {
        config = { ...DEFAULT_CONFIG, ...options };
        
        if (config.debug) {
            console.log('[TPBModalHelp] Initializing with config:', config);
        }

        // Create modal overlay container
        createModalOverlay();

        // Load modals for this page
        loadPageModals();

        // Set up event delegation
        setupEventListeners();

        if (config.debug) {
            console.log('[TPBModalHelp] Initialization complete');
        }
    }

    /**
     * Create the modal overlay container
     */
    function createModalOverlay() {
        // Check if already exists
        if (document.getElementById('tpb-modal-overlay')) {
            modalOverlay = document.getElementById('tpb-modal-overlay');
            return;
        }

        modalOverlay = document.createElement('div');
        modalOverlay.id = 'tpb-modal-overlay';
        modalOverlay.className = 'tpb-modal-overlay';
        modalOverlay.innerHTML = `
            <div class="tpb-modal-container">
                <div class="tpb-modal-header">
                    <span class="tpb-modal-icon"></span>
                    <h2 class="tpb-modal-title"></h2>
                    <button class="tpb-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="tpb-modal-content"></div>
                <div class="tpb-modal-footer">
                    <span class="tpb-modal-category"></span>
                </div>
            </div>
        `;
        
        document.body.appendChild(modalOverlay);

        // Close on overlay click
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) {
                closeModal();
            }
        });

        // Close button
        modalOverlay.querySelector('.tpb-modal-close').addEventListener('click', closeModal);

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && activeModal) {
                closeModal();
            }
        });
    }

    /**
     * Load all modals for the current page
     */
    async function loadPageModals() {
        try {
            const response = await fetch(`${config.apiBase}/get-page-modals.php?page=${config.page}`);
            const data = await response.json();

            if (data.status === 'success' && data.modals) {
                data.modals.forEach(modal => {
                    // Cache the modal data
                    modalsCache[modal.modal_key] = modal;

                    // Find and activate the help tag
                    const tag = document.querySelector(`${config.selector}[data-modal="${modal.modal_key}"]`);
                    if (tag) {
                        activateHelpTag(tag, modal);
                    }
                });

                if (config.debug) {
                    console.log(`[TPBModalHelp] Loaded ${data.count} modals for page: ${config.page}`);
                }
            }
        } catch (error) {
            console.error('[TPBModalHelp] Failed to load page modals:', error);
        }
    }

    /**
     * Activate a help tag with its icon and behavior
     * @param {HTMLElement} tag The help tag element
     * @param {Object} modal The modal data
     */
    function activateHelpTag(tag, modal) {
        // Set the icon
        const icon = ICONS[modal.icon_type] || ICONS.info;
        tag.innerHTML = icon;
        tag.classList.add('tpb-help-active');
        tag.classList.add(`tpb-help-${modal.icon_type}`);
        tag.setAttribute('data-icon-type', modal.icon_type);
        tag.setAttribute('title', modal.tooltip_preview || modal.title);
        tag.setAttribute('role', 'button');
        tag.setAttribute('tabindex', '0');
        tag.setAttribute('aria-label', `Help: ${modal.title}`);

        // Add tooltip if enabled
        if (config.enableTooltips && modal.tooltip_preview) {
            tag.classList.add('tpb-has-tooltip');
            tag.setAttribute('data-tooltip', modal.tooltip_preview);
        }
    }

    /**
     * Set up event listeners
     */
    function setupEventListeners() {
        // Click handler using event delegation
        document.addEventListener('click', function(e) {
            const helpTag = e.target.closest(config.selector);
            if (helpTag && helpTag.classList.contains('tpb-help-active')) {
                e.preventDefault();
                openModal(helpTag.getAttribute('data-modal'), helpTag);
            }
        });

        // Keyboard support
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                const helpTag = e.target.closest(config.selector);
                if (helpTag && helpTag.classList.contains('tpb-help-active')) {
                    e.preventDefault();
                    openModal(helpTag.getAttribute('data-modal'), helpTag);
                }
            }
        });
    }

    /**
     * Open a modal by key
     * @param {string} modalKey The modal key
     * @param {HTMLElement} triggerElement The element that triggered the modal
     */
    async function openModal(modalKey, triggerElement = null) {
        let modal = modalsCache[modalKey];

        // If not cached, fetch it
        if (!modal) {
            try {
                const response = await fetch(`${config.apiBase}/get-modal.php?key=${modalKey}`);
                const data = await response.json();
                
                if (data.status === 'success') {
                    modal = data.modal;
                    modalsCache[modalKey] = modal;
                } else {
                    console.error('[TPBModalHelp] Modal not found:', modalKey);
                    return;
                }
            } catch (error) {
                console.error('[TPBModalHelp] Failed to fetch modal:', error);
                return;
            }
        }

        // Populate modal content
        const icon = ICONS[modal.icon_type] || ICONS.info;
        modalOverlay.querySelector('.tpb-modal-icon').textContent = icon;
        modalOverlay.querySelector('.tpb-modal-title').textContent = modal.title;
        modalOverlay.querySelector('.tpb-modal-content').innerHTML = parseMarkdown(modal.markdown_content);
        modalOverlay.querySelector('.tpb-modal-category').textContent = modal.category;
        
        // Add icon type class to container
        const container = modalOverlay.querySelector('.tpb-modal-container');
        container.className = 'tpb-modal-container';
        container.classList.add(`tpb-modal-${modal.icon_type}`);

        // Show modal
        modalOverlay.classList.add('tpb-modal-visible');
        activeModal = modalKey;

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        // Log analytics
        if (config.enableAnalytics) {
            logClick(modalKey, triggerElement);
        }

        if (config.debug) {
            console.log('[TPBModalHelp] Opened modal:', modalKey);
        }
    }

    /**
     * Close the current modal
     */
    function closeModal() {
        if (!activeModal) return;

        modalOverlay.classList.remove('tpb-modal-visible');
        document.body.style.overflow = '';
        activeModal = null;

        if (config.debug) {
            console.log('[TPBModalHelp] Modal closed');
        }
    }

    /**
     * Log a click for analytics
     * @param {string} modalKey The modal key
     * @param {HTMLElement} triggerElement The trigger element
     */
    async function logClick(modalKey, triggerElement) {
        try {
            const payload = {
                modal_key: modalKey,
                page_name: config.page,
                tag_identifier: triggerElement?.closest('[data-tag-id]')?.getAttribute('data-tag-id') || null,
                session_id: getSessionId()
            };

            await fetch(`${config.apiBase}/log-click.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (config.debug) {
                console.log('[TPBModalHelp] Click logged:', payload);
            }
        } catch (error) {
            // Fail silently for analytics
            if (config.debug) {
                console.warn('[TPBModalHelp] Failed to log click:', error);
            }
        }
    }

    /**
     * Get or create a session ID
     * @returns {string} Session ID
     */
    function getSessionId() {
        let sessionId = sessionStorage.getItem('tpb_session_id');
        if (!sessionId) {
            sessionId = 'sess_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
            sessionStorage.setItem('tpb_session_id', sessionId);
        }
        return sessionId;
    }

    /**
     * Parse markdown to HTML (simple implementation)
     * @param {string} markdown The markdown content
     * @returns {string} HTML content
     */
    function parseMarkdown(markdown) {
        if (!markdown) return '';

        let html = markdown;

        // Escape HTML first
        html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        // Headers (must be before other processing)
        html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // Bold
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Italic
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');

        // Links - open in new tab
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

        // Code blocks
        html = html.replace(/```([^`]+)```/gs, '<pre><code>$1</code></pre>');

        // Inline code
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Process lists
        const lines = html.split('\n');
        let result = [];
        let inList = false;

        for (let line of lines) {
            const trimmed = line.trim();

            if (trimmed.match(/^- /)) {
                if (!inList) {
                    result.push('<ul>');
                    inList = true;
                }
                result.push('<li>' + trimmed.substring(2) + '</li>');
            } else if (trimmed.match(/^\d+\. /)) {
                if (!inList) {
                    result.push('<ol>');
                    inList = true;
                }
                result.push('<li>' + trimmed.replace(/^\d+\. /, '') + '</li>');
            } else {
                if (inList) {
                    result.push(inList === 'ol' ? '</ol>' : '</ul>');
                    inList = false;
                }
                if (trimmed === '') {
                    result.push('</p><p>');
                } else if (!trimmed.startsWith('<h') && !trimmed.startsWith('<pre')) {
                    result.push(line);
                } else {
                    result.push(line);
                }
            }
        }

        if (inList) {
            result.push('</ul>');
        }

        html = '<p>' + result.join('\n') + '</p>';

        // Clean up empty paragraphs
        html = html.replace(/<p>\s*<\/p>/g, '');
        html = html.replace(/<p>\s*(<h[1-3]>)/g, '$1');
        html = html.replace(/(<\/h[1-3]>)\s*<\/p>/g, '$1');
        html = html.replace(/<p>\s*(<ul>)/g, '$1');
        html = html.replace(/(<\/ul>)\s*<\/p>/g, '$1');
        html = html.replace(/<p>\s*(<ol>)/g, '$1');
        html = html.replace(/(<\/ol>)\s*<\/p>/g, '$1');
        html = html.replace(/<p>\s*(<pre>)/g, '$1');
        html = html.replace(/(<\/pre>)\s*<\/p>/g, '$1');

        return html;
    }

    /**
     * Manually show a modal by key (for programmatic use)
     * @param {string} modalKey The modal key to show
     */
    function show(modalKey) {
        openModal(modalKey);
    }

    /**
     * Manually hide the current modal
     */
    function hide() {
        closeModal();
    }

    /**
     * Clear the modal cache
     */
    function clearCache() {
        modalsCache = {};
        if (config.debug) {
            console.log('[TPBModalHelp] Cache cleared');
        }
    }

    /**
     * Get current configuration
     * @returns {Object} Current config
     */
    function getConfig() {
        return { ...config };
    }

    // Export public API
    window.TPBModalHelp = {
        init,
        show,
        hide,
        clearCache,
        getConfig,
        ICONS
    };

})(window);
