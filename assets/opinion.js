/* ==========================================================================
   Opinion JS — AJAX submit, sentiment refresh, comment toggle
   ========================================================================== */

/**
 * Submit an opinion (agree/disagree/mixed).
 * @param {string} prefix  The opinion card DOM prefix (e.g. 'td-decl-5')
 * @param {string} stance  'agree'|'disagree'|'mixed'
 */
async function opinionSubmit(prefix, stance) {
  const card = document.getElementById(prefix + '-card');
  if (!card) return;

  const targetType = card.dataset.targetType;
  const targetId   = card.dataset.targetId;

  // Get optional comment from textarea (if open)
  const commentEl = document.getElementById(prefix + '-comment-input');
  const comment = commentEl ? commentEl.value.trim() : null;

  try {
    const resp = await fetch('/api/opinion.php?action=submit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        target_type: targetType,
        target_id: parseInt(targetId, 10),
        stance: stance,
        comment: comment || null
      })
    });

    const data = await resp.json();

    if (!resp.ok || data.error) {
      opinionShowToast(data.error || 'Failed to submit opinion', true);
      return;
    }

    const msg = data.action === 'updated' ? 'Opinion updated' : 'Opinion recorded';
    opinionShowToast(msg, false);

    // Update button active states locally
    opinionSetActive(prefix, stance);

    // Refresh sentiment from server
    opinionRefreshSentiment(prefix, targetType, targetId);

  } catch (err) {
    opinionShowToast('Network error — please try again', true);
  }
}

/**
 * Set active class on the correct button.
 */
function opinionSetActive(prefix, stance) {
  ['agree', 'disagree', 'mixed'].forEach(function (s) {
    const btn = document.getElementById(prefix + '-' + s);
    if (btn) {
      btn.classList.toggle('active', s === stance);
    }
  });
}

/**
 * Refresh sentiment bar and counts from server.
 */
async function opinionRefreshSentiment(prefix, targetType, targetId) {
  try {
    const resp = await fetch(
      '/api/opinion.php?action=sentiment&target_type=' +
      encodeURIComponent(targetType) +
      '&target_id=' + encodeURIComponent(targetId)
    );
    const data = await resp.json();

    if (!data.success || !data.sentiment) return;

    const s = data.sentiment;

    // Update counts
    const agreeCount = document.getElementById(prefix + '-agree-count');
    const disagreeCount = document.getElementById(prefix + '-disagree-count');
    const mixedCount = document.getElementById(prefix + '-mixed-count');

    if (agreeCount) agreeCount.textContent = s.agree;
    if (disagreeCount) disagreeCount.textContent = s.disagree;
    if (mixedCount) mixedCount.textContent = s.mixed;

    // Update total
    const totalEl = document.getElementById(prefix + '-total');
    if (totalEl) {
      totalEl.textContent = s.total + ' opinion' + (s.total !== 1 ? 's' : '');
    }

    // Update sentiment bar
    const barEl = document.getElementById(prefix + '-bar');
    if (barEl && s.total > 0) {
      const agreeP    = ((s.agree / s.total) * 100).toFixed(1);
      const disagreeP = ((s.disagree / s.total) * 100).toFixed(1);
      const mixedP    = ((s.mixed / s.total) * 100).toFixed(1);

      barEl.classList.remove('opinion-empty-bar');
      barEl.innerHTML =
        (s.agree > 0 ? '<div class="sentiment-segment sentiment-agree" style="width:' + agreeP + '%" title="Agree: ' + agreeP + '%"></div>' : '') +
        (s.disagree > 0 ? '<div class="sentiment-segment sentiment-disagree" style="width:' + disagreeP + '%" title="Disagree: ' + disagreeP + '%"></div>' : '') +
        (s.mixed > 0 ? '<div class="sentiment-segment sentiment-mixed" style="width:' + mixedP + '%" title="Mixed: ' + mixedP + '%"></div>' : '');
    }

  } catch (err) {
    // Silent fail on refresh — user already has feedback from submit
  }
}

/**
 * Toggle comment textarea visibility.
 */
function opinionToggleComment(prefix) {
  const form = document.getElementById(prefix + '-comment-form');
  if (!form) return;

  const isHidden = form.style.display === 'none' || form.style.display === '';
  form.style.display = isHidden ? 'block' : 'none';

  if (isHidden) {
    const input = document.getElementById(prefix + '-comment-input');
    if (input) input.focus();
  }
}

/**
 * Save comment (re-submits opinion with current stance + comment).
 */
async function opinionSaveComment(prefix) {
  const card = document.getElementById(prefix + '-card');
  if (!card) return;

  const targetType = card.dataset.targetType;
  const targetId   = card.dataset.targetId;
  const commentEl  = document.getElementById(prefix + '-comment-input');
  const comment    = commentEl ? commentEl.value.trim() : '';

  // Find current active stance
  let stance = null;
  ['agree', 'disagree', 'mixed'].forEach(function (s) {
    const btn = document.getElementById(prefix + '-' + s);
    if (btn && btn.classList.contains('active')) {
      stance = s;
    }
  });

  if (!stance) {
    opinionShowToast('Select a stance (Agree/Disagree/Mixed) first', true);
    return;
  }

  try {
    const resp = await fetch('/api/opinion.php?action=submit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        target_type: targetType,
        target_id: parseInt(targetId, 10),
        stance: stance,
        comment: comment || null
      })
    });

    const data = await resp.json();

    if (!resp.ok || data.error) {
      opinionShowToast(data.error || 'Failed to save comment', true);
      return;
    }

    opinionShowToast('Comment saved', false);

    // Update toggle text
    const toggleBtn = document.getElementById(prefix + '-comment-toggle');
    if (toggleBtn) {
      toggleBtn.textContent = comment ? 'Edit comment' : 'Add a comment';
    }

    // Hide the form
    opinionToggleComment(prefix);

  } catch (err) {
    opinionShowToast('Network error — please try again', true);
  }
}

/**
 * Show a toast notification.
 * @param {string}  message
 * @param {boolean} isError
 */
function opinionShowToast(message, isError) {
  // Reuse ballot toast if available, else create own
  if (typeof ballotShowToast === 'function') {
    ballotShowToast(message, isError);
    return;
  }

  // Remove existing opinion toast
  const existing = document.getElementById('opinion-toast');
  if (existing) existing.remove();

  const toast = document.createElement('div');
  toast.id = 'opinion-toast';
  toast.style.cssText =
    'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);' +
    'padding:10px 24px;border-radius:8px;font-size:0.9rem;z-index:9999;' +
    'color:#fff;box-shadow:0 4px 12px rgba(0,0,0,0.4);transition:opacity 0.3s;' +
    (isError
      ? 'background:#e74c3c;'
      : 'background:#27ae60;');

  toast.textContent = message;
  document.body.appendChild(toast);

  setTimeout(function () {
    toast.style.opacity = '0';
    setTimeout(function () { toast.remove(); }, 300);
  }, 3000);
}
