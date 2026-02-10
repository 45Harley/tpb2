<?php
/**
 * TPB Shared Footer
 * =================
 * Self-contained: CSS + HTML in one include.
 * Place before closing </body></html> or at end of page content.
 */
?>
<style>
    .tpb-footer {
        background: #0a0a0a;
        border-top: 1px solid #222;
        padding: 40px 20px 20px;
        color: #888;
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
    
    .tpb-footer .footer-col p {
        font-size: 0.9em;
        line-height: 1.5;
        margin: 0 0 8px;
    }
    
    .tpb-footer .footer-col a {
        color: #aaa;
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
    
    .tpb-footer .footer-tip {
        position: relative;
        display: inline-block;
    }
    
    .tpb-footer .footer-tip .tip-text {
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
    
    .tpb-footer .footer-tip .tip-text::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 6px solid transparent;
        border-top-color: #333;
    }
    
    .tpb-footer .footer-tip:hover .tip-text {
        visibility: visible;
        opacity: 1;
    }
    
    .tpb-footer .footer-bottom {
        text-align: center;
        border-top: 1px solid #1a1a1a;
        padding-top: 15px;
        font-size: 0.85em;
        color: #555;
    }
    
    @media (max-width: 600px) {
        .tpb-footer .footer-columns {
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 25px;
        }
        .tpb-footer .footer-tip .tip-text {
            left: 0;
            transform: none;
        }
        .tpb-footer .footer-tip .tip-text::after {
            left: 30px;
        }
    }
</style>

<footer class="tpb-footer">
    <div class="footer-columns">
        <div class="footer-col">
            <h4>The People's Branch</h4>
            <p>Your voice in democracy</p>
            <a href="#" id="tpbContactEmail" class="email-link"></a>
        </div>
        <div class="footer-col">
            <h4>Platform</h4>
            <a href="/index.php">Home</a>
            <a href="/story.php">Our Story</a>
            <a href="/constitution/">Constitution</a>
            <a href="/help/">Help</a>
        </div>
        <div class="footer-col">
            <h4>Get Involved</h4>
            <a href="/join.php">Join</a>
            <a href="/volunteer/">Volunteer</a>
            <a href="/profile.php">Profile</a>
            <a href="/reps.php">Your Reps</a>
        </div>
        <div class="footer-col">
            <h4>Legal</h4>
            <span class="footer-tip">
                <a href="#">Privacy</a>
                <span class="tip-text">We collect only what's needed to make civic engagement work — your town, your voice, your vote. Nothing is sold. Ever.</span>
            </span>
            <span class="footer-tip">
                <a href="#">Terms</a>
                <span class="tip-text">Be civil. Be honest. This platform belongs to the people — use it to build, not to tear down.</span>
            </span>
        </div>
    </div>
    <div class="footer-bottom">
        &copy; 2025 The People's Branch
    </div>
</footer>
<script>
(function(){var e=document.getElementById('tpbContactEmail');if(e){var u='contact',d='4tpb',t='org';var a=u+'@'+d+'.'+t;e.href='mai'+'lto:'+a;e.textContent=a;}})();
</script>
