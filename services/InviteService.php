<?php
/**
 * Invite Service
 * Handles tenant-aware member invitations via email
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;

class InviteService {

    private MailgunService $mailer;
    private string $tenant;
    private string $baseUrl;

    public function __construct() {
        $this->mailer = new MailgunService();
        $this->tenant = $_SESSION['tenant_slug'] ?? 'default';
        $this->baseUrl = Flight::get('baseurl') ?? 'https://myctobot.ai';
    }

    /**
     * Generate a secure invite token
     */
    private function generateToken(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Create an invitation for a new member
     *
     * @param string $email Email to invite
     * @param int $level Permission level for the new member
     * @param int $invitedBy Member ID of the inviter
     * @param string|null $displayName Optional display name
     * @return array Result with success status and member/error
     */
    public function createInvite(
        string $email,
        int $level,
        int $invitedBy,
        ?string $displayName = null
    ): array {
        // Check if email already exists
        $existing = Bean::findOne('member', 'email = ?', [$email]);
        if ($existing) {
            if ($existing->status === 'pending') {
                // Resend invite for pending member
                return $this->resendInvite($existing->id, $invitedBy);
            }
            return [
                'success' => false,
                'error' => 'A member with this email already exists'
            ];
        }

        // Generate invite token
        $token = $this->generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        // Create pending member
        $member = Bean::dispense('member');
        $member->email = $email;
        $member->username = $displayName ?: explode('@', $email)[0];
        $member->display_name = $displayName;
        $member->level = $level;
        $member->status = 'pending';
        $member->invite_token = $token;
        $member->invite_sent_at = date('Y-m-d H:i:s');
        $member->invite_expires_at = $expiresAt;
        $member->invited_by = $invitedBy;
        $member->created_at = date('Y-m-d H:i:s');

        try {
            Bean::store($member);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to create member: ' . $e->getMessage()
            ];
        }

        // Send invite email
        $emailResult = $this->sendInviteEmail($member, $token);

        if (!$emailResult['success']) {
            // Log but don't fail - member is created
            Flight::get('logger')?->warning('Failed to send invite email', [
                'member_id' => $member->id,
                'email' => $email,
                'error' => $emailResult['error'] ?? 'Unknown error'
            ]);
        }

        return [
            'success' => true,
            'member' => $member,
            'email_sent' => $emailResult['success'],
            'email_error' => $emailResult['error'] ?? null
        ];
    }

    /**
     * Resend invitation to a pending member
     */
    public function resendInvite(int $memberId, int $invitedBy): array {
        $member = Bean::load('member', $memberId);
        if (!$member->id) {
            return ['success' => false, 'error' => 'Member not found'];
        }

        if ($member->status !== 'pending') {
            return ['success' => false, 'error' => 'Member has already accepted their invitation'];
        }

        // Generate new token
        $token = $this->generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        $member->invite_token = $token;
        $member->invite_sent_at = date('Y-m-d H:i:s');
        $member->invite_expires_at = $expiresAt;
        $member->invited_by = $invitedBy;

        Bean::store($member);

        // Send invite email
        $emailResult = $this->sendInviteEmail($member, $token);

        return [
            'success' => true,
            'member' => $member,
            'email_sent' => $emailResult['success'],
            'email_error' => $emailResult['error'] ?? null
        ];
    }

    /**
     * Send the invite email
     */
    private function sendInviteEmail(object $member, string $token): array {
        if (!$this->mailer->isEnabled()) {
            return [
                'success' => false,
                'error' => 'Email service not configured'
            ];
        }

        // Build tenant-aware invite URL
        $inviteUrl = $this->buildInviteUrl($token);

        // Get inviter info
        $inviter = Bean::load('member', $member->invited_by);
        $inviterName = $inviter->display_name ?: $inviter->username ?: 'An administrator';

        // Get tenant display name
        $tenantName = $this->getTenantDisplayName();

        // Build email content
        $subject = "You've been invited to join {$tenantName} on MyCTOBot";

        $markdown = <<<MD
# Welcome to MyCTOBot!

**{$inviterName}** has invited you to join **{$tenantName}** as a team member.

MyCTOBot is an AI-powered development assistant that helps teams manage projects, analyze sprints, and automate development tasks.

## Accept Your Invitation

Click the button below to set up your account:

[Accept Invitation]({$inviteUrl})

Or copy and paste this link into your browser:
{$inviteUrl}

---

This invitation will expire in **7 days**.

If you didn't expect this invitation, you can safely ignore this email.
MD;

        try {
            $sent = $this->mailer->sendMarkdownEmail(
                $subject,
                $markdown,
                $member->email
            );

            return ['success' => $sent];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Build tenant-aware invite URL
     */
    private function buildInviteUrl(string $token): string {
        // For tenant invites, build URL to tenant subdomain
        // URL pattern: https://{tenant}.myctobot.ai/auth/invite/{token}
        if ($this->tenant && $this->tenant !== 'default') {
            $baseDomain = Flight::get('app.domain') ?? 'myctobot.ai';
            $protocol = Flight::get('app.protocol') ?? 'https';
            return "{$protocol}://{$this->tenant}.{$baseDomain}/auth/invite/{$token}";
        }
        return "{$this->baseUrl}/auth/invite/{$token}";
    }

    /**
     * Get tenant display name
     */
    private function getTenantDisplayName(): string {
        // Try to get from enterprisesettings
        $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['company_name']);
        if ($setting && $setting->setting_value) {
            return $setting->setting_value;
        }

        // Fall back to tenant slug formatted nicely
        if ($this->tenant && $this->tenant !== 'default') {
            return ucwords(str_replace(['-', '_'], ' ', $this->tenant));
        }

        return 'MyCTOBot';
    }

    /**
     * Validate an invite token and return the member
     */
    public function validateToken(string $token, ?string $tenant = null): array {
        // If tenant specified, we need to switch database context first
        // This is handled by the bootstrap before this is called

        $member = Bean::findOne('member', 'invite_token = ?', [$token]);

        if (!$member) {
            return ['valid' => false, 'error' => 'Invalid invitation link'];
        }

        if ($member->status !== 'pending') {
            return ['valid' => false, 'error' => 'This invitation has already been used'];
        }

        if (strtotime($member->invite_expires_at) < time()) {
            return ['valid' => false, 'error' => 'This invitation has expired'];
        }

        return [
            'valid' => true,
            'member' => $member
        ];
    }

    /**
     * Accept an invitation - set password and activate account
     */
    public function acceptInvite(string $token, string $password, ?string $displayName = null): array {
        $validation = $this->validateToken($token);

        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        $member = $validation['member'];

        // Validate password
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters'];
        }

        // Update member
        $member->password = password_hash($password, PASSWORD_DEFAULT);
        $member->status = 'active';
        $member->invite_token = null; // Clear token after use
        $member->email_verified = 1;
        $member->updated_at = date('Y-m-d H:i:s');

        if ($displayName) {
            $member->display_name = $displayName;
            $member->username = $displayName;
        }

        try {
            Bean::store($member);
            return ['success' => true, 'member' => $member];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Failed to activate account: ' . $e->getMessage()];
        }
    }

    /**
     * Get invite status text for display
     */
    public static function getInviteStatus(object $member): array {
        if ($member->status !== 'pending') {
            return [
                'status' => 'active',
                'label' => 'Active',
                'color' => 'success',
                'icon' => 'check-circle-fill'
            ];
        }

        if (!$member->invite_sent_at) {
            return [
                'status' => 'not_sent',
                'label' => 'Invite Not Sent',
                'color' => 'secondary',
                'icon' => 'envelope'
            ];
        }

        if ($member->invite_expires_at && strtotime($member->invite_expires_at) < time()) {
            return [
                'status' => 'expired',
                'label' => 'Invite Expired',
                'color' => 'danger',
                'icon' => 'clock-history'
            ];
        }

        return [
            'status' => 'pending',
            'label' => 'Invite Pending',
            'color' => 'warning',
            'icon' => 'hourglass-split'
        ];
    }

    /**
     * Format time ago for display
     */
    public static function timeAgo(?string $datetime): string {
        if (!$datetime) {
            return 'Never';
        }

        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }
}
