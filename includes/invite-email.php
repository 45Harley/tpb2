<?php
/**
 * Invite email builder functions.
 *
 * buildInviteEmail()               — HTML sent to the invitee
 * buildInvitorNotificationEmail()  — HTML sent to the invitor when friend joins
 */

function buildInviteEmail(string $invitorEmail, string $acceptUrl, string $baseUrl): string {
    $ie = htmlspecialchars($invitorEmail);
    $au = htmlspecialchars($acceptUrl);
    $bu = htmlspecialchars($baseUrl);

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

  <!-- Header -->
  <tr>
    <td style="background:#1a1a2e;padding:20px 24px;">
      <span style="color:#c8a415;font-size:20px;font-weight:bold;">The People&#8217;s Branch</span>
      <span style="color:#aaa;font-size:14px;float:right;padding-top:4px;">You&#8217;re Invited</span>
    </td>
  </tr>

  <!-- Personal intro -->
  <tr>
    <td style="padding:24px 24px 8px;">
      <h2 style="margin:0;font-size:20px;color:#1a1a2e;line-height:1.4;">
        Your friend <span style="color:#c8a415;">{$ie}</span> thinks you should be part of this.
      </h2>
    </td>
  </tr>

  <!-- What is TPB -->
  <tr>
    <td style="padding:12px 24px 16px;">
      <p style="margin:0;font-size:15px;color:#444;line-height:1.6;">
        The People&#8217;s Branch is a civic platform built on one idea: <strong>government should serve the people, not the other way around.</strong>
      </p>
      <p style="margin:12px 0 0;font-size:15px;color:#444;line-height:1.6;">
        Founded on the <a href="{$bu}/goldenrule.html" style="color:#c8a415;text-decoration:underline;">Golden Rule</a> &mdash; the one ethical command shared by every major world philosophy &mdash; TPB gives citizens the tools to participate directly in democracy, not just once every few years at the ballot box, but on every issue that affects their lives.
      </p>
    </td>
  </tr>

  <!-- Just Imagine sequence -->
  <tr>
    <td style="padding:16px 24px 0;">
      <p style="margin:0;font-size:15px;color:#444;line-height:1.7;">
        You can protest, and it&#8217;s your right and privilege. But now&hellip;
      </p>
    </td>
  </tr>
  <tr>
    <td style="padding:12px 24px;">
      <p style="margin:0 0 14px;font-size:15px;color:#333;line-height:1.7;">
        <strong style="color:#c8a415;">Just imagine&hellip;</strong> you can be heard &mdash; by your community, your town hall, your State, and your elected officials in D.C. &mdash; all at TPB.
      </p>
      <p style="margin:0 0 14px;font-size:15px;color:#333;line-height:1.7;">
        <strong style="color:#c8a415;">Just imagine&hellip;</strong> you can sign up to receive daily threats to democracy, and vote 24/7 on the severest threats facing our country.
      </p>
      <p style="margin:0 0 14px;font-size:15px;color:#333;line-height:1.7;">
        <strong style="color:#c8a415;">Just imagine&hellip;</strong> you can dictate your thoughts, ideas, and complaints with the help of an AI civic clerk who adds your voice and votes to your Reps in D.C. &mdash; instantly aggregated so elected officials see what the people are saying.
      </p>
      <p style="margin:0 0 14px;font-size:15px;color:#333;line-height:1.7;">
        <strong style="color:#c8a415;">Just imagine&hellip;</strong> you can volunteer your skills and experience to help build TPB in your Town and State.
      </p>
      <p style="margin:0;font-size:15px;color:#333;line-height:1.7;">
        &hellip;and all of this is <strong>free</strong> and <strong>ad-free</strong>. Just We The People, at The People&#8217;s Branch &mdash; the 4th branch of government, online and starting up.
      </p>
      <p style="margin:14px 0 0;font-size:15px;color:#1a1a2e;line-height:1.7;font-weight:600;">
        Be among the first 1,000 members. Become one of the first <span style="color:#c8a415;">Founding Volunteers</span>.
      </p>
    </td>
  </tr>

  <!-- CTA Button -->
  <tr>
    <td style="padding:8px 24px 24px;text-align:center;">
      <a href="{$au}" style="display:inline-block;background:#c8a415;color:#fff;padding:14px 36px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;letter-spacing:0.3px;">
        Accept Invitation &rarr;
      </a>
    </td>
  </tr>

  <!-- How it works — prominent section -->
  <tr>
    <td style="padding:0 24px 20px;">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#faf6e8;border:1px solid #e8ddb5;border-radius:6px;">
        <tr>
          <td style="padding:16px 20px;">
            <p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#1a1a2e;">
              &#x2B50; How Invitations Work
            </p>
            <p style="margin:0;font-size:13px;color:#555;line-height:1.6;">
              When you join, your friend <strong>{$ie}</strong> earns <strong style="color:#c8a415;">100 Civic Points</strong> &mdash;
              our way of rewarding citizens who grow the movement. Civic Points track your participation in democracy:
              voting, discussing, volunteering, and inviting others. It takes 60 seconds to create your free account.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="background:#f9f9f9;padding:16px 24px;border-top:1px solid #eee;">
      <p style="margin:0;font-size:12px;color:#999;line-height:1.5;">
        Invited by {$ie} via The People&#8217;s Branch.<br>
        You received this because a TPB member thought you&#8217;d care about democracy.<br>
        If you&#8217;re not interested, simply ignore this email &mdash; no further messages will be sent.<br><br>
        <strong>The People&#8217;s Branch</strong> &mdash; No Kings. Only Citizens.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function buildInvitorNotificationEmail(string $inviteeEmail, int $pointsTotal, string $baseUrl): string {
    $ee = htmlspecialchars($inviteeEmail);
    $bu = htmlspecialchars($baseUrl);

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
  <tr>
    <td style="background:#1a1a2e;padding:20px 24px;">
      <span style="color:#c8a415;font-size:20px;font-weight:bold;">The People&#8217;s Branch</span>
      <span style="color:#aaa;font-size:14px;float:right;padding-top:4px;">Referral Update</span>
    </td>
  </tr>
  <tr>
    <td style="padding:24px;">
      <h2 style="margin:0 0 12px;font-size:20px;color:#1a1a2e;">Your friend joined!</h2>
      <p style="margin:0 0 16px;font-size:15px;color:#444;line-height:1.6;">
        <strong style="color:#c8a415;">{$ee}</strong> accepted your invitation and is now a TPB member.
      </p>
      <div style="background:#faf6e8;border:1px solid #e8ddb5;border-radius:6px;padding:16px 20px;margin-bottom:16px;">
        <p style="margin:0;font-size:15px;color:#333;">
          <strong style="color:#c8a415;">+100 Civic Points</strong> have been added to your total.
          <br>Your new balance: <strong>{$pointsTotal} pts</strong>
        </p>
      </div>
      <p style="margin:0;font-size:14px;color:#666;">
        <a href="{$bu}/invite/" style="color:#c8a415;text-decoration:underline;">Invite more friends</a> to keep growing the movement.
      </p>
    </td>
  </tr>
  <tr>
    <td style="background:#f9f9f9;padding:12px 24px;border-top:1px solid #eee;">
      <p style="margin:0;font-size:12px;color:#999;"><strong>The People&#8217;s Branch</strong> &mdash; No Kings. Only Citizens.</p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
