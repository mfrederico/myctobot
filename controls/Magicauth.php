<?php
/**
 * Magic Link Authentication Controller
 * Handles passwordless login for sales reps via daily HMAC links.
 * This is PUBLIC level since it IS the login mechanism.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;

class Magicauth extends BaseControls\Control {

    /**
     * Verify a magic link token and log in the sales rep
     */
    public function verify($params = null) {
        $token = $this->getParam('token', '');

        if (empty($token)) {
            $this->flash('error', 'Invalid or missing login link.');
            Flight::redirect('/login');
            return;
        }

        // Find valid magic link
        $link = Bean::findOne('magiclink',
            'token = ? AND expires_at > ? AND used_at IS NULL',
            [$token, date('Y-m-d H:i:s')]
        );

        if (!$link) {
            $this->flash('error', 'This login link has expired or already been used. Check your email for today\'s link.');
            Flight::redirect('/login');
            return;
        }

        // Load the member
        $member = Bean::load('member', $link->memberId);
        if (!$member->id || $member->level > LEVELS['SALES']) {
            $this->flash('error', 'Account not found or not authorized.');
            Flight::redirect('/login');
            return;
        }

        // Check if member is disabled
        if (!empty($member->isDisabled)) {
            $this->flash('error', 'Your account has been disabled due to inactivity. Please contact an administrator.');
            Flight::redirect('/login');
            return;
        }

        // Mark link as used
        $link->usedAt = date('Y-m-d H:i:s');
        Bean::store($link);

        // Update member login tracking
        $member->lastLogin = date('Y-m-d H:i:s');
        $member->loginCount = ($member->loginCount ?? 0) + 1;
        Bean::store($member);

        // Set session
        $_SESSION['member'] = $member->export();

        // Log activity
        $activity = Bean::dispense('crmactivity');
        $activity->memberId = $member->id;
        $activity->activityType = 'magic_login';
        $activity->description = "Logged in via magic link";
        Bean::store($activity);

        Flight::redirect('/crm');
    }
}
