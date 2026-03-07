/**
 * Facilitator Tools — client-side functions for group deliberation.
 * Makes AJAX calls to /api/facilitator.php
 */

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function facilitatorApi(action, method, data) {
    const url = '/api/facilitator.php?action=' + encodeURIComponent(action);
    const opts = {
        method: method,
        headers: { 'Content-Type': 'application/json' },
    };
    if (method === 'POST' && data) {
        opts.body = JSON.stringify(data);
    }
    return fetch(url, opts).then(function(r) {
        return r.json().then(function(json) {
            if (!r.ok) {
                return Promise.reject(json.error || 'Request failed.');
            }
            return json;
        });
    });
}

function facilitatorToast(msg, isError) {
    // Reuse ballot toast if available, otherwise simple alert
    var toast = document.querySelector('.ballot-toast');
    if (toast) {
        toast.textContent = msg;
        toast.classList.toggle('error', !!isError);
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 3000);
        return;
    }
    // Fallback: create a temporary toast
    var t = document.createElement('div');
    t.className = 'ballot-toast show' + (isError ? ' error' : '');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1a1a2e;border:1px solid ' +
        (isError ? '#f44336' : '#d4af37') + ';color:#e0e0e0;padding:12px 24px;border-radius:8px;z-index:9999;font-size:0.9rem;';
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 3000);
}

/* ------------------------------------------------------------------ */
/*  Call Vote                                                          */
/* ------------------------------------------------------------------ */

function facilitatorCallVote(event, groupId) {
    event.preventDefault();

    var form = document.getElementById('call-vote-form-' + groupId);
    var question = form.querySelector('[name="question"]').value.trim();
    var voteType = form.querySelector('[name="vote_type"]').value;
    var thresholdType = form.querySelector('[name="threshold_type"]').value;

    if (!question) {
        facilitatorToast('Question is required.', true);
        return false;
    }

    var data = {
        group_id: groupId,
        question: question,
        vote_type: voteType,
        threshold_type: thresholdType,
    };

    // Collect options for multi/ranked
    if (voteType === 'multi_choice' || voteType === 'ranked_choice') {
        var optionInputs = form.querySelectorAll('[name="options[]"]');
        var options = [];
        optionInputs.forEach(function(input) {
            var val = input.value.trim();
            if (val) options.push(val);
        });
        if (options.length < 2) {
            facilitatorToast('At least 2 options are required for ' + voteType.replace('_', ' ') + '.', true);
            return false;
        }
        data.options = options;
    }

    facilitatorApi('call_vote', 'POST', data).then(function(res) {
        facilitatorToast('Vote created (Poll #' + res.poll_id + ')');
        setTimeout(function() { location.reload(); }, 1200);
    }).catch(function(err) {
        facilitatorToast(err, true);
    });

    return false;
}

/* ------------------------------------------------------------------ */
/*  Vote Type Toggle — show/hide options field                         */
/* ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', function() {
    // Attach change listeners to vote type selects
    document.querySelectorAll('[id^="fac-vote-type-"]').forEach(function(sel) {
        var groupId = sel.id.replace('fac-vote-type-', '');
        sel.addEventListener('change', function() {
            var wrap = document.getElementById('fac-options-wrap-' + groupId);
            if (wrap) {
                var needsOptions = (sel.value === 'multi_choice' || sel.value === 'ranked_choice');
                wrap.style.display = needsOptions ? 'block' : 'none';
            }
        });
    });
});

/* ------------------------------------------------------------------ */
/*  Add Option                                                         */
/* ------------------------------------------------------------------ */

function facilitatorAddOption(groupId) {
    var list = document.getElementById('fac-options-list-' + groupId);
    if (!list) return;
    var count = list.querySelectorAll('.fac-option-input').length + 1;
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'options[]';
    input.placeholder = 'Option ' + count;
    input.className = 'fac-option-input';
    list.appendChild(input);
}

/* ------------------------------------------------------------------ */
/*  New Round                                                          */
/* ------------------------------------------------------------------ */

function facilitatorNewRound(pollId) {
    if (!confirm('Start a new voting round? The current round will be closed.')) return;

    facilitatorApi('new_round', 'POST', { poll_id: pollId }).then(function(res) {
        facilitatorToast('New round started (Round ' + res.round + ', Poll #' + res.poll_id + ')');
        setTimeout(function() { location.reload(); }, 1200);
    }).catch(function(err) {
        facilitatorToast(err, true);
    });
}

/* ------------------------------------------------------------------ */
/*  Merge Options                                                      */
/* ------------------------------------------------------------------ */

function facilitatorMergeOptions(keepOptionId, mergeOptionId, newText) {
    if (!newText) {
        newText = prompt('Enter the merged option text:');
        if (!newText) return;
    }

    facilitatorApi('merge_options', 'POST', {
        keep_option_id: keepOptionId,
        merge_option_id: mergeOptionId,
        new_text: newText
    }).then(function() {
        facilitatorToast('Options merged.');
        setTimeout(function() { location.reload(); }, 1200);
    }).catch(function(err) {
        facilitatorToast(err, true);
    });
}

/* ------------------------------------------------------------------ */
/*  Surface Option                                                     */
/* ------------------------------------------------------------------ */

function facilitatorSurfaceOption(groupId, ideaId, pollId) {
    facilitatorApi('surface_option', 'POST', {
        group_id: groupId,
        idea_id: ideaId,
        poll_id: pollId
    }).then(function(res) {
        facilitatorToast('Option surfaced (ID #' + res.option_id + ')');
        setTimeout(function() { location.reload(); }, 1200);
    }).catch(function(err) {
        facilitatorToast(err, true);
    });
}

/* ------------------------------------------------------------------ */
/*  Draft Declaration                                                  */
/* ------------------------------------------------------------------ */

function facilitatorShowDraftForm(pollId, groupId) {
    var wrap = document.getElementById('draft-declaration-form-wrap-' + groupId);
    if (wrap) {
        wrap.style.display = 'block';
        document.getElementById('draft-poll-id-' + groupId).value = pollId;
        wrap.scrollIntoView({ behavior: 'smooth' });
    }
}

function facilitatorDraftDeclaration(event, groupId) {
    event.preventDefault();

    var form = document.getElementById('draft-declaration-form-' + groupId);
    var pollId = parseInt(document.getElementById('draft-poll-id-' + groupId).value, 10);
    var title = form.querySelector('[name="title"]').value.trim();
    var body = form.querySelector('[name="body"]').value.trim();

    if (!pollId || !title || !body) {
        facilitatorToast('All fields are required.', true);
        return false;
    }

    facilitatorApi('draft_declaration', 'POST', {
        group_id: groupId,
        poll_id: pollId,
        title: title,
        body: body
    }).then(function(res) {
        facilitatorToast('Declaration drafted (#' + res.declaration_id + ')');
        setTimeout(function() { location.reload(); }, 1200);
    }).catch(function(err) {
        facilitatorToast(err, true);
    });

    return false;
}

/* ------------------------------------------------------------------ */
/*  Ratify Declaration                                                 */
/* ------------------------------------------------------------------ */

function facilitatorRatify(declarationId) {
    if (!confirm('Ratify this declaration? This action is final.')) return;

    facilitatorApi('ratify', 'POST', {
        declaration_id: declarationId
    }).then(function() {
        facilitatorToast('Declaration ratified!');
        setTimeout(function() { location.reload(); }, 1200);
    }).catch(function(err) {
        facilitatorToast(err, true);
    });
}
