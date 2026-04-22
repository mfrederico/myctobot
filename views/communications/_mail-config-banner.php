<?php
/**
 * Shared banner explaining which Mailgun config will be used to send this
 * message. Renders on the compose and thread-reply forms.
 *
 * @var array $mailConfig  NotifyService::describeConfig() output:
 *   [configured:bool, source:string, domain:string, from_email:string, is_personal:bool]
 */

$cfg = $mailConfig ?? ['configured' => false, 'source' => 'none', 'domain' => '', 'is_personal' => false];

if (!$cfg['configured']):
    // No mailgun configured anywhere — sending will fail. Block with a
    // clear CTA rather than let the rep hit Send and wonder why.
?>
    <div class="alert alert-warning small mb-3 d-flex align-items-start gap-2">
        <i class="fas fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>No Mailgun configured.</strong>
            Connect Mailgun under <a href="/connections" class="alert-link">Connections</a> before sending.
        </div>
    </div>
<?php elseif (!$cfg['is_personal']):
    // Using a shared/workspace default — let the rep know they're sending
    // from the platform domain, not their own, so there are no surprises
    // about what the recipient sees.
    $domain = htmlspecialchars((string)$cfg['domain']);
?>
    <div class="alert alert-info small mb-3 d-flex align-items-start gap-2">
        <i class="fas fa-circle-info mt-1"></i>
        <div>
            Sending through the MyCTOBot default mail relay
            <?php if ($domain !== ''): ?>(<code><?= $domain ?></code>)<?php endif; ?>.
            <a href="/connections" class="alert-link">Connect your own Mailgun</a> to send from your domain.
        </div>
    </div>
<?php endif; ?>
