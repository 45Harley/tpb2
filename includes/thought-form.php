<?php
/**
 * TPB Shared Thought Submission Form
 * ===================================
 * Reusable component for submitting civic thoughts.
 * 
 * Required variables (must be set before including):
 *   $pdo          - Database connection
 *   $dbUser       - User data array (or null if not logged in)
 *   $sessionId    - Session ID from cookie
 *   $userTown     - User's town name (or null)
 *   $userState    - User's state abbreviation (or null)
 *   $canPost      - Boolean: can user submit thoughts?
 *   $needsParentConsent - Boolean: minor waiting for consent?
 * 
 * Optional variables:
 *   $thoughtFormId       - Form element ID (default: 'thoughtForm')
 *   $thoughtFormContext  - Context string for display (e.g., 'Putnam')
 *   $defaultIsLocal      - Pre-check local checkbox (default: false)
 * 
 * Usage:
 *   require_once __DIR__ . '/includes/thought-form.php';
 */

// Defaults for optional variables
$thoughtFormId = $thoughtFormId ?? 'thoughtForm';
$thoughtFormContext = $thoughtFormContext ?? '';
$defaultIsLocal = $defaultIsLocal ?? false;

// Get categories for form
$categories = $pdo->query("
    SELECT * FROM thought_categories 
    WHERE is_active = 1 AND (is_volunteer_only = 0 OR is_volunteer_only IS NULL) 
    ORDER BY display_order
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Thought Form Alerts -->
<?php if (!$canPost): ?>
    <div class="alert alert-warning">
        ✉️ <a href="/profile.php">Verify your email</a> to submit thoughts.
    </div>
<?php endif; ?>

<?php if ($needsParentConsent): ?>
    <div class="alert" style="background: #2a2a4a; border: 1px solid #7ab8e0; color: #7ab8e0;">
        ⏳ <strong>Waiting for Parent/Guardian Approval</strong><br>
        <span style="color: #aaa;">We sent an email to your parent/guardian. Ask them to check and approve!</span>
    </div>
<?php endif; ?>

<?php if (!$userTown): ?>
    <div class="alert alert-info">
        📍 <a href="/profile.php">Set your location</a> to post local thoughts.
    </div>
<?php endif; ?>

<div class="card">
    <p style="color: #d4af37; font-size: 0.9rem; margin-bottom: 1rem;">📝 Content must be appropriate for all ages, 5 to 125.</p>
    
    <!-- TPB Profile Notice (shows when TPB category selected but profile incomplete) -->
    <div id="tpbProfileNotice" style="display: none; background: #2a2a4a; border: 1px solid #d4af37; border-radius: 8px; padding: 12px; margin-bottom: 1rem;">
        <p style="color: #d4af37; margin: 0 0 8px; font-size: 0.95em;">⚠️ TPB feedback requires a complete profile</p>
        <p style="color: #aaa; margin: 0; font-size: 0.85em;">We need your name and phone to follow up on your <span id="tpbCategoryName">idea</span>.</p>
        <div id="tpbMissingFields" style="color: #ff6b6b; margin-top: 8px; font-size: 0.85em;"></div>
    </div>
    
    <form class="submit-form" id="<?= htmlspecialchars($thoughtFormId) ?>">
        <!-- Bot detection fields -->
        <div style="position:absolute;left:-9999px;"><input type="text" name="website_url" id="tf_website_url" tabindex="-1" autocomplete="off"></div>
        <input type="hidden" name="_form_load_time" id="tf_form_load_time" value="<?= time() ?>">
        
        <!-- Category -->
        <div class="form-group">
            <label>Category</label>
            <select id="tf_category" <?= !$canPost ? 'disabled' : '' ?>>
                <option value="">Select category (optional)</option>
                <optgroup label="── Civic Topics ──">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>"><?= $cat['icon'] ?> <?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
                </optgroup>
                <optgroup label="── About TPB Platform ──">
                    <option value="tpb-idea">💡 TPB Idea</option>
                    <option value="tpb-bug">🐛 Bug Report</option>
                    <option value="tpb-question">❓ Question</option>
                </optgroup>
            </select>
        </div>
        
        <!-- Other Topic (shows when Other selected) -->
        <div class="form-group" id="tf_otherTopicGroup" style="display: none;">
            <label>What topic is this?</label>
            <input type="text" id="tf_otherTopic" placeholder="Describe the topic..." maxlength="100">
            <div style="text-align: right; margin-top: 0.25rem;">
                <span id="tf_otherTopicCounter" style="color: #888; font-size: 0.8rem;">0 / 100</span>
            </div>
        </div>
        
        <!-- Target Level Checkboxes -->
        <div class="form-group">
            <label>Target Level * <span style="color: #666; font-weight: normal;">(check all that apply)</span></label>
            <div class="checkbox-group">
                <?php if ($userTown): ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="tf_jurisdiction" value="local" <?= !$canPost ? 'disabled' : '' ?> <?= $defaultIsLocal ? 'checked' : '' ?>>
                    <span>🏘️ Local (<?= htmlspecialchars($userTown) ?>)</span>
                </label>
                <?php endif; ?>
                <?php if ($userState): ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="tf_jurisdiction" value="state" <?= !$canPost ? 'disabled' : '' ?>>
                    <span>🗺️ State (<?= htmlspecialchars($userState) ?>)</span>
                </label>
                <?php endif; ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="tf_jurisdiction" value="federal" <?= !$canPost ? 'disabled' : '' ?>>
                    <span>🇺🇸 Federal</span>
                </label>
            </div>
        </div>
        
        <!-- Branch Checkboxes -->
        <div class="form-group">
            <label>Branch of Government <span style="color: #666; font-weight: normal;">(optional)</span></label>
            <div class="checkbox-group">
                <label class="checkbox-item">
                    <input type="checkbox" name="tf_branch" value="legislative" <?= !$canPost ? 'disabled' : '' ?>>
                    <span>⚖️ Legislative (makes laws)</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="tf_branch" value="executive" <?= !$canPost ? 'disabled' : '' ?>>
                    <span>🏛️ Executive (enforces laws)</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="tf_branch" value="judicial" <?= !$canPost ? 'disabled' : '' ?>>
                    <span>👨‍⚖️ Judicial (interprets laws)</span>
                </label>
            </div>
        </div>
        
        <!-- Thought Content with Dictate -->
        <div class="form-group">
            <label>Your Thought</label>
            <div style="position: relative;">
                <textarea id="tf_content" placeholder="What civic issue matters to you? Share an idea, concern, or suggestion..." maxlength="1000" style="min-height: 150px;" <?= !$canPost ? 'disabled' : '' ?>></textarea>
                <button type="button" id="tf_dictateBtn" class="dictate-btn" <?= !$canPost ? 'disabled' : '' ?>>🎤</button>
            </div>
            <div style="text-align: right; margin-top: 0.25rem;">
                <span id="tf_charCounter" style="color: #d4af37; font-size: 0.9rem; font-weight: bold;">0 / 1000</span>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary" <?= !$canPost ? 'disabled' : '' ?>>
            Share Thought (+25 pts)
        </button>
        <div id="tf_submitStatus"></div>
    </form>
</div>

<script>
(function() {
    // Form elements
    const form = document.getElementById('<?= htmlspecialchars($thoughtFormId) ?>');
    const contentEl = document.getElementById('tf_content');
    const charCounter = document.getElementById('tf_charCounter');
    const categorySelect = document.getElementById('tf_category');
    const otherTopicGroup = document.getElementById('tf_otherTopicGroup');
    const otherTopicEl = document.getElementById('tf_otherTopic');
    const otherTopicCounter = document.getElementById('tf_otherTopicCounter');
    const tpbNotice = document.getElementById('tpbProfileNotice');
    const tpbCategoryName = document.getElementById('tpbCategoryName');
    const tpbMissingFields = document.getElementById('tpbMissingFields');
    const dictateBtn = document.getElementById('tf_dictateBtn');
    const submitStatus = document.getElementById('tf_submitStatus');
    
    const sessionId = '<?= htmlspecialchars($sessionId ?? '') ?>';
    const canPost = <?= $canPost ? 'true' : 'false' ?>;
    
    // User profile for TPB category checks
    const userProfile = {
        firstName: <?= json_encode($dbUser['first_name'] ?? '') ?>,
        lastName: <?= json_encode($dbUser['last_name'] ?? '') ?>,
        phone: <?= json_encode($dbUser['phone'] ?? '') ?>,
        phoneVerified: <?= ($dbUser && !empty($dbUser['phone_verified'])) ? 'true' : 'false' ?>
    };
    
    // Character counter for thought content
    if (contentEl && charCounter) {
        contentEl.addEventListener('input', function() {
            const count = this.value.length;
            charCounter.textContent = count + ' / 1000';
        });
    }
    
    // Character counter for other topic
    if (otherTopicEl && otherTopicCounter) {
        otherTopicEl.addEventListener('input', function() {
            const count = this.value.length;
            otherTopicCounter.textContent = count + ' / 100';
        });
    }
    
    // Category change handler
    if (categorySelect) {
        categorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const selectedText = selectedOption ? selectedOption.text.toLowerCase() : '';
            const isTpbCategory = this.value && this.value.toString().startsWith('tpb-');
            const isOther = selectedText.includes('other');
            
            // Show/hide other topic field
            if (otherTopicGroup) {
                otherTopicGroup.style.display = isOther ? 'block' : 'none';
            }
            
            // Show/hide TPB profile notice
            if (isTpbCategory && tpbNotice) {
                const missing = [];
                if (!userProfile.firstName) missing.push('first name');
                if (!userProfile.lastName) missing.push('last name');
                if (!userProfile.phoneVerified) missing.push('verified phone');
                
                if (missing.length > 0) {
                    tpbCategoryName.textContent = selectedOption.text.replace(/^[^\s]+\s/, '');
                    tpbMissingFields.textContent = 'Missing: ' + missing.join(', ');
                    tpbNotice.style.display = 'block';
                } else {
                    tpbNotice.style.display = 'none';
                }
            } else if (tpbNotice) {
                tpbNotice.style.display = 'none';
            }
        });
    }
    
    // Dictation (speech recognition)
    // Uses continuous=false to avoid stutter — user taps mic per utterance
    let recognition = null;
    let isRecording = false;

    if (dictateBtn && ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.lang = 'en-US';

        let preExistingText = '';

        recognition.onstart = function() {
            isRecording = true;
            dictateBtn.classList.add('recording');
            dictateBtn.textContent = '⏹️';
            preExistingText = contentEl.value;
        };

        recognition.onend = function() {
            isRecording = false;
            dictateBtn.classList.remove('recording');
            dictateBtn.textContent = '🎤';
        };

        recognition.onresult = function(event) {
            let transcript = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                transcript += event.results[i][0].transcript;
            }
            contentEl.value = preExistingText + (preExistingText ? ' ' : '') + transcript;
            charCounter.textContent = contentEl.value.length + ' / 1000';
        };

        recognition.onerror = function(event) {
            console.error('Speech recognition error:', event.error);
            isRecording = false;
            dictateBtn.classList.remove('recording');
            dictateBtn.textContent = '🎤';
        };

        dictateBtn.addEventListener('click', function() {
            if (isRecording) {
                recognition.stop();
            } else {
                recognition.start();
            }
        });
    } else if (dictateBtn) {
        // Hide dictate button if speech recognition not supported
        dictateBtn.style.display = 'none';
    }
    
    // Form submission
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            if (!canPost) return;
            
            const content = contentEl.value.trim();
            const category = categorySelect.value;
            const otherTopic = otherTopicEl ? otherTopicEl.value.trim() : '';
            
            // Get checked jurisdictions
            const jurisdictions = [];
            document.querySelectorAll('input[name="tf_jurisdiction"]:checked').forEach(function(cb) {
                jurisdictions.push(cb.value);
            });
            
            // Get checked branches
            const branches = [];
            document.querySelectorAll('input[name="tf_branch"]:checked').forEach(function(cb) {
                branches.push(cb.value);
            });
            
            // Validation
            if (!content) {
                alert('Please enter your thought');
                return;
            }
            if (content.length < 10) {
                alert('Please write at least 10 characters');
                return;
            }
            if (jurisdictions.length === 0) {
                alert('Please select at least one target level');
                return;
            }
            
            // TPB category profile check
            if (category && category.toString().startsWith('tpb-')) {
                const missing = [];
                if (!userProfile.firstName) missing.push('first name');
                if (!userProfile.lastName) missing.push('last name');
                if (!userProfile.phoneVerified) missing.push('verified phone');
                
                if (missing.length > 0) {
                    alert('TPB feedback requires a complete profile. Please add: ' + missing.join(', '));
                    return;
                }
            }
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Submitting...';
            
            try {
                const response = await fetch('/api/submit-thought.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        session_id: sessionId,
                        content: content,
                        category_id: category || null,
                        other_topic: otherTopic || null,
                        is_local: jurisdictions.includes('local') ? 1 : 0,
                        is_state: jurisdictions.includes('state') ? 1 : 0,
                        is_federal: jurisdictions.includes('federal') ? 1 : 0,
                        is_legislative: branches.includes('legislative') ? 1 : 0,
                        is_executive: branches.includes('executive') ? 1 : 0,
                        is_judicial: branches.includes('judicial') ? 1 : 0,
                        // Bot detection
                        website_url: document.getElementById('tf_website_url').value,
                        _form_load_time: document.getElementById('tf_form_load_time').value
                    })
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    if (result.total_points && window.tpbUpdateNavPoints) window.tpbUpdateNavPoints(result.total_points);
                    submitStatus.innerHTML = '<div class="status-msg success" style="color: #4caf50; margin-top: 1rem;">✓ Thought submitted! Refreshing...</div>';
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    submitStatus.innerHTML = '<div class="status-msg error" style="color: #f44336; margin-top: 1rem;">' + (result.message || 'Error submitting') + '</div>';
                    btn.disabled = false;
                    btn.textContent = 'Share Thought (+25 pts)';
                }
            } catch (err) {
                submitStatus.innerHTML = '<div class="status-msg error" style="color: #f44336; margin-top: 1rem;">Error submitting thought</div>';
                btn.disabled = false;
                btn.textContent = 'Share Thought (+25 pts)';
            }
        });
    }
})();
</script>
