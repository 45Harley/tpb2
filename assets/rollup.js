/**
 * Rollup / Pulse / Beam-to-Desk — Client-side interactions
 * Phase 5 of the Civic Engine
 */

/* ------------------------------------------------------------------ */
/*  Copy beam message to clipboard                                     */
/* ------------------------------------------------------------------ */
function copyBeamMessage() {
    var msg = document.getElementById('beamMessage');
    var btn = document.getElementById('beamCopyBtn');
    if (!msg || !btn) return;

    var text = msg.textContent || msg.innerText;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            btn.textContent = 'Copied!';
            btn.style.background = '#27ae60';
            setTimeout(function () {
                btn.textContent = 'Copy to Clipboard';
                btn.style.background = '#d4af37';
            }, 2000);
        }).catch(function () {
            fallbackCopy(msg, btn);
        });
    } else {
        fallbackCopy(msg, btn);
    }
}

function fallbackCopy(el, btn) {
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);

    try {
        document.execCommand('copy');
        btn.textContent = 'Copied!';
        btn.style.background = '#27ae60';
        setTimeout(function () {
            btn.textContent = 'Copy to Clipboard';
            btn.style.background = '#d4af37';
        }, 2000);
    } catch (e) {
        btn.textContent = 'Select text manually';
        setTimeout(function () {
            btn.textContent = 'Copy to Clipboard';
        }, 3000);
    }

    sel.removeAllRanges();
}

/* ------------------------------------------------------------------ */
/*  Pulse score animation on page load                                 */
/* ------------------------------------------------------------------ */
(function () {
    // Animate pulse numbers counting up
    var pulseEl = document.querySelector('.rollup-pulse-number');
    if (pulseEl) {
        var target = parseFloat(pulseEl.textContent) || 0;
        if (target > 0) {
            pulseEl.textContent = '0';
            animateNumber(pulseEl, 0, target, 800);
        }
    }

    var stateBigScore = document.querySelector('.sd-pulse-big-score');
    if (stateBigScore) {
        var sTarget = parseFloat(stateBigScore.textContent) || 0;
        if (sTarget > 0) {
            stateBigScore.textContent = '0';
            animateNumber(stateBigScore, 0, sTarget, 800);
        }
    }

    function animateNumber(el, start, end, duration) {
        var startTime = null;
        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            var current = Math.round(start + (end - start) * eased * 10) / 10;
            el.textContent = current % 1 === 0 ? current.toFixed(0) : current.toFixed(1);
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = end % 1 === 0 ? end.toFixed(0) : end.toFixed(1);
            }
        }
        requestAnimationFrame(step);
    }

    // Animate pulse bars growing from 0
    var bars = document.querySelectorAll('.rollup-pulse-bar-fill, .sd-pulse-bar-fill, .sd-town-pulse-fill');
    bars.forEach(function (bar) {
        var targetWidth = bar.style.width;
        bar.style.width = '0%';
        setTimeout(function () {
            bar.style.width = targetWidth;
        }, 200);
    });
})();
