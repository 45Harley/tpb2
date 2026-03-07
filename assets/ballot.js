/* ==========================================================================
   Ballot JS — Voting UI, drag-drop ranked choice, live tally refresh
   ========================================================================== */

/**
 * Submit a vote (yes/no, multi-choice, etc.)
 * @param {number|string} pollId
 * @param {object} voteData - e.g. {vote: 'yea'}, {option_id: 5}, etc.
 */
async function ballotVote(pollId, voteData) {
  try {
    const resp = await fetch('/api/ballot.php?action=vote', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ poll_id: pollId, ...voteData })
    });
    const data = await resp.json();
    if (!resp.ok || data.error) {
      ballotShowToast(data.error || 'Vote failed', true);
      return;
    }
    const msg = data.updated ? 'Vote updated' : 'Vote recorded';
    ballotShowToast(msg, false);
    ballotRefreshCard(pollId);
  } catch (err) {
    ballotShowToast('Network error — please try again', true);
  }
}

/**
 * Submit ranked-choice rankings
 * @param {number|string} pollId
 * @param {string} prefix - DOM id prefix, e.g. "poll-7" so list is #poll-7-ranked-list
 */
async function ballotSubmitRanked(pollId, prefix) {
  const list = document.getElementById(prefix + '-ranked-list');
  if (!list) return;

  const items = list.querySelectorAll('.ranked-item');
  const rankings = {};
  items.forEach(function (item, idx) {
    const optionId = item.dataset.optionId;
    if (optionId) {
      rankings[optionId] = idx + 1;
    }
  });

  try {
    const resp = await fetch('/api/ballot.php?action=vote', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ poll_id: pollId, rankings: rankings })
    });
    const data = await resp.json();
    if (!resp.ok || data.error) {
      ballotShowToast(data.error || 'Ranking submission failed', true);
      return;
    }
    const msg = data.updated ? 'Vote updated' : 'Vote recorded';
    ballotShowToast(msg, false);
    ballotRefreshCard(pollId);
  } catch (err) {
    ballotShowToast('Network error — please try again', true);
  }
}

/**
 * Refresh tally display for a single ballot card
 * @param {number|string} pollId
 */
async function ballotRefreshCard(pollId) {
  try {
    const resp = await fetch('/api/ballot.php?action=tally&poll_id=' + encodeURIComponent(pollId));
    const data = await resp.json();
    if (!resp.ok || data.error) return;

    const card = document.querySelector('[data-poll-id="' + pollId + '"]');
    if (!card) return;

    // Yes/No tally
    if (data.yes !== undefined || data.yea !== undefined) {
      var yea = data.yea || data.yes || 0;
      var nay = data.nay || data.no || 0;
      var total = yea + nay;

      var yeaBar = card.querySelector('.tally-yea-bar');
      var nayBar = card.querySelector('.tally-nay-bar');
      if (yeaBar) yeaBar.style.width = total ? ((yea / total * 100).toFixed(1) + '%') : '0%';
      if (nayBar) nayBar.style.width = total ? ((nay / total * 100).toFixed(1) + '%') : '0%';

      var yeaCount = card.querySelector('.tally-yea');
      var nayCount = card.querySelector('.tally-nay');
      var totalCount = card.querySelector('.tally-total');
      if (yeaCount) yeaCount.textContent = yea + ' Yea';
      if (nayCount) nayCount.textContent = nay + ' Nay';
      if (totalCount) totalCount.textContent = total + ' votes';

      // Threshold badge
      var passedEl = card.querySelector('.tally-passed');
      if (passedEl && data.threshold !== undefined) {
        if (total > 0 && (yea / total * 100) >= data.threshold) {
          passedEl.textContent = 'Passed';
          passedEl.style.display = '';
        } else {
          passedEl.style.display = 'none';
        }
      }
    }

    // Multi-choice option counts
    if (data.options && Array.isArray(data.options)) {
      data.options.forEach(function (opt) {
        var optionEl = card.querySelector('[data-option-id="' + opt.id + '"]');
        if (!optionEl) return;
        var countEl = optionEl.querySelector('.option-count');
        if (countEl) countEl.textContent = opt.count || 0;
      });
    }
  } catch (err) {
    // Silently fail on tally refresh
  }
}

/**
 * Show a toast notification
 * @param {string} msg
 * @param {boolean} isError
 */
function ballotShowToast(msg, isError) {
  var toast = document.getElementById('ballot-toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'ballot-toast';
    toast.className = 'ballot-toast';
    document.body.appendChild(toast);
  }

  toast.textContent = msg;
  toast.classList.remove('show', 'error');
  if (isError) toast.classList.add('error');

  // Force reflow then show
  void toast.offsetWidth;
  toast.classList.add('show');

  clearTimeout(toast._hideTimer);
  toast._hideTimer = setTimeout(function () {
    toast.classList.remove('show');
  }, 2500);
}

/* ==========================================================================
   Drag-and-Drop for Ranked Choice
   ========================================================================== */

document.addEventListener('DOMContentLoaded', function () {
  var lists = document.querySelectorAll('.ballot-ranked-list');
  lists.forEach(function (list) {
    initRankedDragDrop(list);
  });
});

function initRankedDragDrop(list) {
  var dragItem = null;

  list.addEventListener('dragstart', function (e) {
    var item = e.target.closest('.ranked-item');
    if (!item) return;
    dragItem = item;
    item.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', '');
  });

  list.addEventListener('dragend', function (e) {
    var item = e.target.closest('.ranked-item');
    if (item) item.classList.remove('dragging');
    // Clean up drag-over from all items
    list.querySelectorAll('.ranked-item').forEach(function (el) {
      el.classList.remove('drag-over');
    });
    dragItem = null;
    updateRankNumbers(list);
  });

  list.addEventListener('dragover', function (e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
  });

  list.addEventListener('dragenter', function (e) {
    var item = e.target.closest('.ranked-item');
    if (item && item !== dragItem) {
      item.classList.add('drag-over');
    }
  });

  list.addEventListener('dragleave', function (e) {
    var item = e.target.closest('.ranked-item');
    if (item) {
      item.classList.remove('drag-over');
    }
  });

  list.addEventListener('drop', function (e) {
    e.preventDefault();
    var target = e.target.closest('.ranked-item');
    if (!target || !dragItem || target === dragItem) return;

    target.classList.remove('drag-over');

    // Determine position: insert before or after
    var items = Array.from(list.querySelectorAll('.ranked-item'));
    var dragIdx = items.indexOf(dragItem);
    var targetIdx = items.indexOf(target);

    if (dragIdx < targetIdx) {
      list.insertBefore(dragItem, target.nextSibling);
    } else {
      list.insertBefore(dragItem, target);
    }

    updateRankNumbers(list);
  });

  // Make items draggable
  list.querySelectorAll('.ranked-item').forEach(function (item) {
    item.setAttribute('draggable', 'true');
  });
}

function updateRankNumbers(list) {
  var items = list.querySelectorAll('.ranked-item');
  items.forEach(function (item, idx) {
    var numEl = item.querySelector('.ranked-num');
    if (numEl) numEl.textContent = idx + 1;
  });
}
