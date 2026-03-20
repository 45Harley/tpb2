<?php
/**
 * Shared threat bulletin email builder.
 * Used by both the daily cron and the on-demand subscribe send.
 */

function buildBulletinHtml($threats, $baseUrl, $threatCount, $token) {
    $authBase = "{$baseUrl}/api/verify-bulletin-token.php?bt={$token}";
    $threatsLink = $authBase . '&dest=/elections/threats.php';
    $fightLink = $authBase . '&dest=/elections/the-fight.php';

    $rows = '';
    foreach ($threats as $t) {
        $zone = getSeverityZone($t['severity_score']);
        $color = $zone['color'];
        $score = $t['severity_score'] ?? '?';
        $date = date('M j', strtotime($t['threat_date']));
        $official = htmlspecialchars($t['official_name'] ?? 'Unknown');
        $title = htmlspecialchars($t['title']);
        $branch = ucfirst($t['branch'] ?? 'executive');

        $rows .= <<<ROW
        <tr>
          <td style="padding:8px 12px;vertical-align:top;width:60px;">
            <span style="display:inline-block;background:{$color};color:#fff;font-weight:bold;font-size:13px;padding:3px 8px;border-radius:4px;text-align:center;min-width:36px;">{$score}</span>
          </td>
          <td style="padding:8px 12px;font-size:15px;line-height:1.4;">
            <a href="{$threatsLink}" style="color:#1a1a2e;text-decoration:none;font-weight:600;">{$title}</a>
            <br><span style="color:#666;font-size:13px;">{$official} &middot; {$branch} &middot; {$date}</span>
          </td>
        </tr>
ROW;
    }

    $plural = $threatCount !== 1 ? 's' : '';

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
      <span style="color:#c8a415;font-size:20px;font-weight:bold;">The People's Branch</span>
      <span style="color:#aaa;font-size:14px;float:right;padding-top:4px;">Threat Alert</span>
    </td>
  </tr>

  <!-- Headline -->
  <tr>
    <td style="padding:20px 24px 12px;">
      <h2 style="margin:0;font-size:18px;color:#1a1a2e;">{$threatCount} new threat{$plural} to constitutional order</h2>
      <p style="margin:6px 0 0;color:#666;font-size:14px;">In the last 48 hours, ordered by severity:</p>
    </td>
  </tr>

  <!-- Criminality Scale -->
  <tr>
    <td style="padding:4px 24px 12px;">
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;font-size:11px;">
        <tr>
          <td colspan="10" style="background:#f5f5f5;padding:6px 10px;font-weight:bold;color:#555;font-size:12px;">Criminality Scale (0&ndash;1000) &mdash; rates the act, not the actor</td>
        </tr>
        <tr style="text-align:center;">
          <td style="background:#4caf50;color:#fff;padding:4px 2px;">0<br>Clean</td>
          <td style="background:#8bc34a;color:#fff;padding:4px 2px;">1&ndash;10<br>Question&shy;able</td>
          <td style="background:#cddc39;color:#333;padding:4px 2px;">11&ndash;30<br>Mis&shy;conduct</td>
          <td style="background:#ffeb3b;color:#333;padding:4px 2px;">31&ndash;70<br>Misde&shy;meanor</td>
          <td style="background:#ff9800;color:#fff;padding:4px 2px;">71&ndash;150<br>Felony</td>
          <td style="background:#ff5722;color:#fff;padding:4px 2px;">151&ndash;300<br>Serious Felony</td>
          <td style="background:#f44336;color:#fff;padding:4px 2px;">301&ndash;500<br>High Crime</td>
          <td style="background:#d32f2f;color:#fff;padding:4px 2px;">501&ndash;700<br>Atrocity</td>
          <td style="background:#b71c1c;color:#fff;padding:4px 2px;">701&ndash;900<br>Crime v. Humanity</td>
          <td style="background:#000;color:#fff;padding:4px 2px;">901+<br>Genocide</td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Threat rows -->
  <tr>
    <td style="padding:0 12px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        {$rows}
      </table>
    </td>
  </tr>

  <!-- CTAs -->
  <tr>
    <td style="padding:20px 24px;">
      <a href="{$threatsLink}" style="display:inline-block;background:#f44336;color:#fff;padding:10px 20px;border-radius:5px;text-decoration:none;font-weight:bold;font-size:14px;">View All Threats</a>
      &nbsp;&nbsp;
      <a href="{$fightLink}" style="display:inline-block;background:#1a1a2e;color:#fff;padding:10px 20px;border-radius:5px;text-decoration:none;font-weight:bold;font-size:14px;">Take Action</a>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="background:#f9f9f9;padding:16px 24px;border-top:1px solid #eee;">
      <p style="margin:0;font-size:12px;color:#999;line-height:1.5;">
        You subscribed to TPB Threat Alerts.<br>
        To unsubscribe, visit <a href="{$threatsLink}" style="color:#666;">the threat stream</a> and toggle off.<br>
        <strong>The People's Branch</strong> &mdash; No Kings. Only Citizens.
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
