<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a1a2e">
    <title>Help - Talk</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#x2753;</text></svg>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
            color: #eee;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 1.3rem;
            color: #4fc3f7;
        }

        .header-links {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
        }

        .header-links a {
            color: #4fc3f7;
            text-decoration: none;
        }

        .header-links a:hover { text-decoration: underline; }

        h2 {
            color: #d4af37;
            font-size: 1.2rem;
            margin: 2rem 0 0.75rem;
            padding-bottom: 0.4rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.25);
        }

        h2:first-of-type { margin-top: 1rem; }

        h3 {
            color: #4fc3f7;
            font-size: 1rem;
            margin: 1.25rem 0 0.5rem;
        }

        p, li {
            color: #ccc;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        p { margin-bottom: 0.75rem; }

        ul, ol {
            margin: 0.5rem 0 1rem 1.5rem;
        }

        li { margin-bottom: 0.4rem; }

        a { color: #4fc3f7; }

        .flow-diagram {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 16px 20px;
            font-family: monospace;
            font-size: 0.85rem;
            color: #aaa;
            overflow-x: auto;
            white-space: pre;
            margin: 1rem 0;
            line-height: 1.5;
        }

        .page-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 16px 20px;
            margin: 0.75rem 0;
        }

        .page-card h3 {
            margin-top: 0;
        }

        .page-card .page-link {
            font-size: 0.8rem;
            color: #888;
        }

        strong { color: #eee; }

        .faq-item {
            margin-bottom: 1.25rem;
        }

        .faq-item summary {
            cursor: pointer;
            color: #eee;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 8px 0;
            list-style: none;
        }

        .faq-item summary::-webkit-details-marker { display: none; }

        .faq-item summary::before {
            content: '+ ';
            color: #d4af37;
            font-weight: bold;
        }

        .faq-item[open] summary::before {
            content: '- ';
        }

        .faq-item .answer {
            padding: 8px 0 8px 18px;
            border-left: 2px solid rgba(212, 175, 55, 0.25);
            margin-left: 4px;
        }

        .quick-ref {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin: 1rem 0;
        }

        .quick-ref a {
            display: block;
            background: rgba(79, 195, 247, 0.08);
            border: 1px solid rgba(79, 195, 247, 0.2);
            border-radius: 8px;
            padding: 12px;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
        }

        .quick-ref a:hover {
            border-color: #4fc3f7;
            background: rgba(79, 195, 247, 0.15);
        }

        .quick-ref .qr-icon {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 4px;
        }

        .quick-ref .qr-label {
            font-size: 0.85rem;
            color: #ccc;
        }

        .quick-ref .qr-desc {
            font-size: 0.7rem;
            color: #888;
            margin-top: 2px;
        }

        .status-flow {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin: 0.5rem 0 1rem;
            font-size: 0.85rem;
        }

        .status-pill {
            padding: 4px 10px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.08);
            color: #aaa;
        }

        .status-arrow { color: #555; }

        @media (max-width: 480px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            .header-links { gap: 0.75rem; font-size: 0.8rem; }
            .quick-ref { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1>Help &amp; FAQ</h1>
            <div class="header-links">
                <a href="index.php">Quick Capture</a>
                <a href="brainstorm.php">Brainstorm</a>
                <a href="groups.php">Groups</a>
                <a href="history.php">History</a>
                <a href="brainstorm.php?help">🤖 Ask AI</a>
            </div>
        </div>

        <!-- ═══════ QUICK REFERENCE ═══════ -->

        <div class="quick-ref">
            <a href="index.php">
                <span class="qr-icon">&#x1f4a1;</span>
                <span class="qr-label">Quick Capture</span>
                <span class="qr-desc">Dump a thought fast</span>
            </a>
            <a href="brainstorm.php">
                <span class="qr-icon">&#x1f9e0;</span>
                <span class="qr-label">Brainstorm</span>
                <span class="qr-desc">Think deeper with AI</span>
            </a>
            <a href="history.php">
                <span class="qr-icon">&#x1f4da;</span>
                <span class="qr-label">History</span>
                <span class="qr-desc">Review past thoughts</span>
            </a>
            <a href="groups.php">
                <span class="qr-icon">&#x1f465;</span>
                <span class="qr-label">Groups</span>
                <span class="qr-desc">Collaborate on proposals</span>
            </a>
        </div>

        <!-- ═══════ HOW IT WORKS ═══════ -->

        <h2>How /talk Works</h2>

        <p>/talk turns your scattered thoughts into concrete proposals. Here's the flow:</p>

<div class="flow-diagram">You have a thought &#x2500;&#x2500;&#x2192; Quick Capture (dump it fast)
                            &#x2502;
Want to go deeper?  &#x2500;&#x2500;&#x2192; Brainstorm (chat with AI)
                            &#x2502;
Review what you said &#x2500;&#x2192; History (filter, promote, share)
                            &#x2502;
Work with others &#x2500;&#x2500;&#x2500;&#x2500;&#x2192; Groups (deliberate, crystallize)
                            &#x2502;
                       Proposal (the deliverable)</div>

        <!-- Quick Capture -->
        <div class="page-card">
            <h3>&#x1f4a1; Quick Capture</h3>
            <span class="page-link">/talk/</span>
            <p>The fastest on-ramp. Tap the mic or type. Pick a category (Idea, Decision, Todo, Note, Question). Hit Save. Done in 10 seconds.</p>
            <ul>
                <li><strong>Voice works great</strong> — tap the mic, speak naturally, it fills in the text</li>
                <li><strong>Ctrl+Enter</strong> (Cmd+Enter on Mac) saves without clicking the button</li>
                <li>Thoughts are <strong>private by default</strong> — nobody sees them until you choose to share</li>
            </ul>
        </div>

        <!-- Brainstorm -->
        <div class="page-card">
            <h3>&#x1f9e0; Brainstorm</h3>
            <span class="page-link">/talk/brainstorm.php</span>
            <p>Chat with AI to go deeper. You talk, it responds, asks follow-up questions, and <strong>automatically captures the good ideas</strong> from your conversation.</p>
            <ul>
                <li>Green messages like "&#x1f4a1; Idea #42 captured" mean the AI saved a clean version of your idea</li>
                <li><strong>Group dropdown</strong> — switch to a group context and the AI sees everyone's shared ideas</li>
                <li><strong>Shareable toggle</strong> — when ON, your thoughts are visible to your groups</li>
            </ul>
        </div>

        <!-- History -->
        <div class="page-card">
            <h3>&#x1f4da; History</h3>
            <span class="page-link">/talk/history.php</span>
            <p>Everything you've said lives here. Filter by category or status. Switch between flat and threaded views. Promote ideas as they mature.</p>
            <p><strong>Idea maturity:</strong></p>
            <div class="status-flow">
                <span class="status-pill">Raw</span>
                <span class="status-arrow">&#x2192;</span>
                <span class="status-pill">Refining</span>
                <span class="status-arrow">&#x2192;</span>
                <span class="status-pill">Distilled</span>
                <span class="status-arrow">&#x2192;</span>
                <span class="status-pill">Actionable</span>
            </div>
            <ul>
                <li><strong>Share</strong> a thought to make it visible to your groups</li>
                <li><strong>Promote</strong> to advance an idea through the maturity stages</li>
                <li><strong>Threaded view</strong> shows conversation trees with replies indented</li>
            </ul>
        </div>

        <!-- Groups -->
        <div class="page-card">
            <h3>&#x1f465; Groups</h3>
            <span class="page-link">/talk/groups.php</span>
            <p>Groups are where individual thoughts become collective proposals. Create a group, invite people, brainstorm together, and let the AI synthesize everything into a structured proposal.</p>
            <p><strong>The deliberation flow:</strong></p>
            <ol>
                <li><strong>Create</strong> a group with a clear purpose</li>
                <li><strong>Invite</strong> members — share the link</li>
                <li><strong>Brainstorm</strong> — everyone shares ideas (mark them shareable)</li>
                <li><strong>Gather</strong> — facilitator runs the AI gatherer to find connections</li>
                <li><strong>Crystallize</strong> — AI produces a structured proposal document</li>
                <li><strong>Iterate</strong> — add more ideas, re-gather, re-crystallize</li>
                <li><strong>Archive</strong> — lock the final proposal</li>
            </ol>
            <p><strong>Roles:</strong> Facilitator (manages everything), Member (brainstorms + shares), Observer (read-only).</p>
        </div>

        <!-- ═══════ FAQ ═══════ -->

        <h2>Frequently Asked Questions</h2>

        <details class="faq-item">
            <summary>Do I need an account to use /talk?</summary>
            <div class="answer">
                <p>No — you can try Quick Capture and Brainstorm without an account. But your thoughts are tied to your browser tab. Close the tab and they're gone forever. <a href="/join.php">Create an account</a> to keep your work.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What happens to my thoughts if I'm not logged in?</summary>
            <div class="answer">
                <p>They're stored with a temporary session ID that lives in your browser tab. If you close the tab, switch browsers, or clear your data, the link is broken. The thoughts still exist in the database but nobody — including you — can find them again.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Can other people see my thoughts?</summary>
            <div class="answer">
                <p>Not by default. Thoughts are <strong>private</strong> unless you explicitly share them. In Brainstorm, use the "Shareable" toggle. In History, check the "Share" box on individual thoughts. Only your group members can see shared thoughts.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Does the AI brainstorm cost money?</summary>
            <div class="answer">
                <p>Not to you — brainstorm sessions are free. Each session costs TPB roughly one cent in AI processing. That's why we ask you to <a href="/join.php">create an account</a> — so the value isn't wasted on throwaway sessions.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What does the AI do with my ideas?</summary>
            <div class="answer">
                <p>The AI is a tool, not a decision-maker. It helps organize your thoughts:</p>
                <ul>
                    <li><strong>Saves</strong> clean versions of good ideas from your conversation</li>
                    <li><strong>Tags</strong> them with relevant topics</li>
                    <li><strong>Finds connections</strong> between group members' ideas (gatherer)</li>
                    <li><strong>Synthesizes</strong> proposals from all the group's input (crystallizer)</li>
                </ul>
                <p>It never publishes anything without a human (the facilitator) pressing the button.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What's the difference between Quick Capture and Brainstorm?</summary>
            <div class="answer">
                <p><strong>Quick Capture</strong> = one thought, save it, move on. No AI involved. Like jotting a sticky note.</p>
                <p><strong>Brainstorm</strong> = a conversation. The AI asks follow-ups, challenges assumptions, adds data, and captures ideas automatically. Like thinking out loud with a smart partner.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>How do groups work?</summary>
            <div class="answer">
                <p>A group is 2+ people around a topic. Anyone with an account can create one. Members brainstorm individually or together, mark their ideas as shareable, and the facilitator runs AI tools to synthesize everything into a proposal.</p>
                <p>Groups have three access levels: <strong>Open</strong> (anyone can join), <strong>Observable</strong> (anyone can see, only members contribute), and <strong>Closed</strong> (invitation only).</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What is "crystallization"?</summary>
            <div class="answer">
                <p>Crystallization is when the AI reads all of a group's shared ideas, gatherer digests, and conversation threads, then produces a structured proposal document — with key findings, proposed actions, and attribution back to the people who contributed. Think of it as turning a messy whiteboard into a polished brief.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Can I use /talk as a personal idea tracker?</summary>
            <div class="answer">
                <p>Yes. Quick Capture works as a personal thought journal. Brainstorm works as a 1-on-1 thinking partner. History gives you a filterable archive. You never have to join a group if you don't want to — but the real power is in collective deliberation.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Is this partisan? Does it favor any political side?</summary>
            <div class="answer">
                <p>No. /talk is non-partisan by design. The AI describes but doesn't editorialize. It serves all citizens — left, right, center, or none of the above. The goal is to help people think clearly and work together, not to push any agenda.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>How do I create an account?</summary>
            <div class="answer">
                <p>Go to <a href="/join.php">/join.php</a>. Enter your email — that's the only required field. We'll send a verification link. Click it, and you're in. Name, phone, and age are optional but help us serve you better.</p>
            </div>
        </details>

        <div style="text-align: center; margin: 2rem 0 1rem;">
            <p style="color: #aaa; font-size: 0.9rem; margin-bottom: 0.75rem;">Still have questions?</p>
            <a href="brainstorm.php?help" style="display: inline-block; padding: 12px 28px; background: linear-gradient(145deg, #4fc3f7, #0288d1); color: #fff; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 1rem; transition: transform 0.2s, box-shadow 0.2s;">Ask AI</a>
        </div>

        <p style="color: #555; font-size: 0.8rem; margin-top: 2rem; text-align: center;">
            The People's Branch &middot; Your voice, aggregated
        </p>
    </div>
</body>
</html>
