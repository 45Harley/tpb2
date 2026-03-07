<?php
/**
 * TPB Shared Footer
 * =================
 * Self-contained: CSS + HTML in one include.
 * Place before closing </body></html> or at end of page content.
 *
 * 4-column layout: The People's Branch | Learn | Build | Connect
 */
?>
<style>
    .tpb-footer {
        background: #0a0a0a;
        border-top: 1px solid #222;
        padding: 40px 20px 20px;
        color: #b0b0b0;
    }

    .tpb-footer .footer-columns {
        display: flex;
        justify-content: space-around;
        max-width: 900px;
        margin: 0 auto 30px;
        gap: 30px;
    }

    .tpb-footer .footer-col h4 {
        color: #d4af37;
        font-size: 0.95em;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .tpb-footer .footer-col a {
        color: #b0b0b0;
        text-decoration: none;
        font-size: 0.9em;
        display: block;
        padding: 3px 0;
        transition: color 0.2s;
    }

    .tpb-footer .footer-col a:hover {
        color: #d4af37;
    }

    .tpb-footer .footer-col .email-link {
        color: #62a4d0;
    }

    .tpb-footer .footer-col .email-link:hover {
        color: #d4af37;
    }

    .tpb-footer .footer-col a.external::after {
        content: ' \2197';
        font-size: 0.75em;
    }

    .tpb-footer .footer-bottom {
        text-align: center;
        border-top: 1px solid #1a1a1a;
        padding-top: 15px;
        font-size: 0.85em;
        color: #b0b0b0;
    }

    .tpb-footer .footer-bottom a {
        color: #b0b0b0;
        text-decoration: none;
        transition: color 0.2s;
    }

    .tpb-footer .footer-bottom a:hover {
        color: #d4af37;
    }

    .tpb-footer .footer-bottom .footer-tip {
        position: relative;
        display: inline-block;
    }

    .tpb-footer .footer-bottom .footer-tip .tip-text {
        visibility: hidden;
        opacity: 0;
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: #1a1a1a;
        border: 1px solid #333;
        color: #ccc;
        padding: 12px 15px;
        border-radius: 6px;
        font-size: 0.85em;
        line-height: 1.5;
        width: 260px;
        text-align: left;
        transition: opacity 0.25s, visibility 0.25s;
        z-index: 10;
        pointer-events: none;
        margin-bottom: 8px;
    }

    .tpb-footer .footer-bottom .footer-tip .tip-text::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 6px solid transparent;
        border-top-color: #333;
    }

    .tpb-footer .footer-bottom .footer-tip:hover .tip-text {
        visibility: visible;
        opacity: 1;
    }

    @media (max-width: 600px) {
        .tpb-footer .footer-columns {
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 25px;
        }
        .tpb-footer .footer-bottom .footer-tip .tip-text {
            left: 0;
            transform: none;
        }
        .tpb-footer .footer-bottom .footer-tip .tip-text::after {
            left: 30px;
        }
    }
</style>

<footer class="tpb-footer">
    <div class="footer-columns">
        <div class="footer-col">
            <h4>The People's Branch</h4>
            <a href="/story.php">Our Story</a>
            <a href="/goldenrule.html">The Golden Rule</a>
            <a href="/constitution/">Constitution</a>
            <a href="#" id="tpbContactEmail" class="email-link"></a>
        </div>
        <div class="footer-col">
            <h4>Learn</h4>
            <a href="/welcome.php">Getting Started</a>
            <a href="/help/">User Guides</a>
            <a href="/docs/metaphysics-of-democracy.md">The Metaphysics</a>
        </div>
        <div class="footer-col">
            <h4>Build</h4>
            <a href="/volunteer/">Volunteer</a>
            <a href="/volunteer/state-builder-start.php">State Build Kit</a>
            <a href="#">Town Build Kit</a>
        </div>
        <div class="footer-col">
            <h4>Connect</h4>
            <a href="/invite/">Invite a Citizen</a>
            <a href="#">Invite a Rep</a>
            <a href="https://github.com/45Harley/tpb2" target="_blank" rel="noopener" class="external">GitHub</a>
        </div>
    </div>
    <div class="footer-bottom">
        &copy; 2025 The People's Branch &middot;
        <span class="footer-tip">
            <a href="#">Privacy</a>
            <span class="tip-text">We collect only what's needed to make civic engagement work — your town, your voice, your vote. Nothing is sold. Ever.</span>
        </span> &middot;
        <span class="footer-tip">
            <a href="#">Terms</a>
            <span class="tip-text">Be civil. Be honest. This platform belongs to the people — use it to build, not to tear down.</span>
        </span>
    </div>
</footer>
<script>
(function(){var e=document.getElementById('tpbContactEmail');if(e){var u='contact',d='4tpb',t='org';var a=u+'@'+d+'.'+t;e.href='mai'+'lto:'+a;e.textContent=a;}})();
</script>
