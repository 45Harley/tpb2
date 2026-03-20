<!-- Read Aloud Component -->
<div id="read-aloud-bar" class="ra-bar" style="display:none;">
    <button id="ra-play" class="ra-btn" title="Play">&#9654;</button>
    <button id="ra-pause" class="ra-btn" title="Pause" style="display:none;">&#10074;&#10074;</button>
    <button id="ra-stop" class="ra-btn" title="Stop">&#9632;</button>
    <span id="ra-progress" class="ra-progress"></span>
    <select id="ra-speed" class="ra-speed" title="Speed">
        <option value="0.75">0.75x</option>
        <option value="1" selected>1x</option>
        <option value="1.25">1.25x</option>
        <option value="1.5">1.5x</option>
        <option value="2">2x</option>
    </select>
    <button id="ra-close" class="ra-btn ra-close" title="Close">&times;</button>
</div>
<button id="ra-selection-btn" class="ra-selection-btn" style="display:none;" title="Read selected text aloud">&#9654; Read This</button>
<div id="ra-hint" class="ra-hint">&#9654; Select text to read aloud</div>

<style>
.ra-selection-btn {
    position: absolute; z-index: 950;
    background: #1a1a2e; color: #d4af37; border: 1px solid #d4af37;
    padding: 0.35rem 0.75rem; border-radius: 16px; font-size: 0.8rem;
    font-weight: 600; cursor: pointer; white-space: nowrap;
    box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    transition: opacity 0.15s, transform 0.15s;
    animation: raSelFadeIn 0.15s ease-out;
}
.ra-selection-btn:hover { background: #252540; transform: scale(1.05); }
@keyframes raSelFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
.ra-hint {
    position: fixed; bottom: 5rem; right: 1.5rem; z-index: 900;
    background: #1a1a2e; color: #d4af37; border: 1px solid #d4af37;
    padding: 0.4rem 1rem; border-radius: 16px; font-size: 0.8rem;
    font-weight: 600; pointer-events: none;
    transform: rotate(-15deg);
    opacity: 0;
    animation: raHintPulse 1.5s ease-in-out 10;
    animation-delay: 2s;
    animation-fill-mode: forwards;
}
@keyframes raHintPulse {
    0% { opacity: 0; transform: rotate(-15deg) scale(0.9); }
    20% { opacity: 1; transform: rotate(-15deg) scale(1.05); }
    40% { opacity: 1; transform: rotate(-15deg) scale(1); }
    80% { opacity: 1; transform: rotate(-15deg) scale(1); }
    100% { opacity: 0; transform: rotate(-15deg) scale(0.95); }
}
.ra-bar {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 1000;
    background: #1a1a2e; border-top: 1px solid #d4af37;
    padding: 0.5rem 1rem; display: flex; align-items: center; gap: 0.75rem;
}
.ra-btn {
    background: none; border: 1px solid #555; color: #e0e0e0;
    width: 2rem; height: 2rem; border-radius: 50%; cursor: pointer;
    font-size: 0.85rem; display: flex; align-items: center; justify-content: center;
    transition: border-color 0.2s;
}
.ra-btn:hover { border-color: #d4af37; color: #d4af37; }
.ra-close { border-radius: 4px; width: auto; padding: 0 0.5rem; margin-left: auto; font-size: 1.1rem; }
.ra-progress { color: #b0b0b0; font-size: 0.8rem; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ra-speed {
    background: #0a0a0f; color: #e0e0e0; border: 1px solid #444;
    border-radius: 4px; padding: 0.2rem 0.3rem; font-size: 0.8rem;
}
.ra-bar .ra-highlight { background: rgba(212,175,55,0.2); border-radius: 2px; }
</style>

<script>
(function() {
    const synth = window.speechSynthesis;
    if (!synth) return;

    const bar = document.getElementById('read-aloud-bar');
    const playBtn = document.getElementById('ra-play');
    const pauseBtn = document.getElementById('ra-pause');
    const stopBtn = document.getElementById('ra-stop');
    const progress = document.getElementById('ra-progress');
    const speedSel = document.getElementById('ra-speed');
    const closeBtn = document.getElementById('ra-close');

    let chunks = [];
    let currentIdx = 0;
    let speaking = false;

    function getPageText() {
        const main = document.querySelector('main')
            || document.querySelector('[role="main"]')
            || document.querySelector('.content');
        if (!main) return '';

        const clone = main.cloneNode(true);
        // Remove elements that shouldn't be read
        clone.querySelectorAll(
            'nav, footer, script, style, noscript, svg, .ra-bar, .ra-trigger, ' +
            '#claudia-widget, .claudia-widget, .mandate-chat-wrapper, ' +
            '.hp-field, [aria-hidden="true"], .view-links, .controls select, ' +
            'button, input, select, textarea, form'
        ).forEach(el => el.remove());

        return clone.innerText.replace(/\s+/g, ' ').trim();
    }

    function splitText(text) {
        // Split on sentence boundaries, keep chunks under ~200 chars
        const sentences = text.match(/[^.!?]+[.!?]+|[^.!?]+$/g) || [text];
        const result = [];
        let buf = '';
        for (const s of sentences) {
            if ((buf + s).length > 200 && buf) {
                result.push(buf.trim());
                buf = s;
            } else {
                buf += s;
            }
        }
        if (buf.trim()) result.push(buf.trim());
        return result;
    }

    function pickVoice() {
        const voices = synth.getVoices();
        // Prefer natural-sounding US English voices
        const prefs = ['Google US English', 'Samantha', 'Zira', 'Eva', 'Microsoft David', 'Alex'];
        for (const p of prefs) {
            const v = voices.find(v => v.name.includes(p));
            if (v) return v;
        }
        return voices.find(v => v.lang.startsWith('en')) || voices[0] || null;
    }

    function speakChunk(idx) {
        if (idx >= chunks.length) {
            stop();
            progress.textContent = 'Done.';
            return;
        }
        currentIdx = idx;
        const utt = new SpeechSynthesisUtterance(chunks[idx]);
        utt.rate = parseFloat(speedSel.value);
        const voice = pickVoice();
        if (voice) utt.voice = voice;
        utt.lang = 'en-US';
        utt.onend = () => speakChunk(idx + 1);
        utt.onerror = (e) => {
            progress.textContent = 'Error: ' + e.error;
            if (e.error !== 'canceled') speakChunk(idx + 1);
        };
        progress.textContent = (idx + 1) + '/' + chunks.length + ': ' + chunks[idx].substring(0, 60) + '...';
        synth.speak(utt);
    }

    function play() {
        if (synth.paused) {
            synth.resume();
            showPause();
            return;
        }
        const text = getPageText();
        if (!text) { progress.textContent = 'No content found.'; return; }
        chunks = splitText(text);
        currentIdx = 0;
        speaking = true;
        showPause();
        speakChunk(0);
    }

    function pause() {
        synth.pause();
        showPlay();
    }

    function stop() {
        synth.cancel();
        speaking = false;
        currentIdx = 0;
        showPlay();
        progress.textContent = '';
    }

    function showPlay() {
        playBtn.style.display = '';
        pauseBtn.style.display = 'none';
    }
    function showPause() {
        playBtn.style.display = 'none';
        pauseBtn.style.display = '';
    }

    playBtn.addEventListener('click', play);
    pauseBtn.addEventListener('click', pause);
    stopBtn.addEventListener('click', stop);
    closeBtn.addEventListener('click', () => {
        stop();
        bar.style.display = 'none';
    });
    speedSel.addEventListener('change', () => {
        if (speaking && !synth.paused) {
            synth.cancel();
            speakChunk(currentIdx);
        }
    });

    // Preload voices
    synth.getVoices();
    synth.onvoiceschanged = () => synth.getVoices();

    // --- Selection-based "Read This" ---
    const selBtn = document.getElementById('ra-selection-btn');
    let selTimeout = null;
    let pendingText = '';

    function readSelection(text) {
        synth.cancel();
        bar.style.display = 'flex';
        progress.textContent = 'Starting...';
        chunks = splitText(text);
        if (!chunks.length) { progress.textContent = 'No text to read.'; return; }
        currentIdx = 0;
        speaking = true;
        showPause();
        speakChunk(0);
    }

    // Show button when text is selected
    document.addEventListener('mouseup', function(e) {
        if (e.target.closest('.ra-bar, .ra-selection-btn')) return;

        clearTimeout(selTimeout);
        selTimeout = setTimeout(function() {
            const sel = window.getSelection();
            const text = (sel.toString() || '').trim();

            if (text.length > 5) {
                pendingText = text;
                const range = sel.getRangeAt(0);
                const rect = range.getBoundingClientRect();
                selBtn.style.top = (window.scrollY + rect.bottom + 6) + 'px';
                selBtn.style.left = Math.min(
                    rect.left + rect.width / 2 - 40,
                    window.innerWidth - 120
                ) + 'px';
                selBtn.style.display = '';
            } else {
                selBtn.style.display = 'none';
                pendingText = '';
            }
        }, 200);
    });

    // Use mousedown on button — fires before selectionchange clears everything
    selBtn.addEventListener('mousedown', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var text = pendingText;
        selBtn.style.display = 'none';
        pendingText = '';
        window.getSelection().removeAllRanges();
        if (text) {
            synth.cancel();
            setTimeout(function() { readSelection(text); }, 50);
        }
    });
})();
</script>
