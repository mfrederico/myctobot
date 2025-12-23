<?php
namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use app\BaseControls\Control;

class Member extends Control {
    
    public function __construct() {
        parent::__construct();
        
        // Require login for all member pages
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
    }
    
    /**
     * Member profile page
     */
    public function profile($params = []) {
        $this->viewData['title'] = 'My Profile';
        $this->render('member/profile', $this->viewData);
    }
    
    /**
     * Edit profile
     */
    public function edit($params = []) {
        if (Flight::request()->method === 'POST') {
            // Validate CSRF token
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
            
            $request = Flight::request();
            $member = R::load('member', $this->member->id);
            
            // Validate input
            $email = trim($request->data->email ?? '');
            $first_name = trim($request->data->first_name ?? '');
            $last_name = trim($request->data->last_name ?? '');
            $bio = trim($request->data->bio ?? '');
            
            if (empty($email)) {
                $this->viewData['error'] = 'Email is required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->viewData['error'] = 'Invalid email format';
            } else {
                // Check for duplicate email (excluding current member)
                $existingEmail = R::findOne('member', 'email = ? AND id != ?', [$email, $member->id]);
                
                if ($existingEmail) {
                    $this->viewData['error'] = 'Email already exists';
                } else {
                    // Update allowed fields
                    $member->email = $email;
                    $member->first_name = $first_name;
                    $member->last_name = $last_name;
                    $member->bio = $bio;
                }
            }
            
            // Update password if provided
            if (!empty($request->data->password) || !empty($request->data->current_password)) {
                // Verify current password if changing password
                if (!empty($request->data->current_password)) {
                    if (!password_verify($request->data->current_password, $member->password)) {
                        $this->viewData['error'] = 'Current password is incorrect';
                    } else if (empty($request->data->password)) {
                        $this->viewData['error'] = 'Please enter a new password';
                    } else if ($request->data->password !== $request->data->password_confirm) {
                        $this->viewData['error'] = 'New passwords do not match';
                    } else if (strlen($request->data->password) < 8) {
                        $this->viewData['error'] = 'Password must be at least 8 characters';
                    } else {
                        $member->password = password_hash($request->data->password, PASSWORD_DEFAULT);
                        $this->viewData['success'] = 'Profile and password updated successfully';
                    }
                }
            }
            
            if (empty($this->viewData['error'])) {
                $member->updated_at = date('Y-m-d H:i:s');
                
                try {
                    R::store($member);
                    $_SESSION['member'] = $member->export();
                    $this->member = $member; // Update current member object
                    if (empty($this->viewData['success'])) {
                        $this->viewData['success'] = 'Profile updated successfully';
                    }
                    $this->logger->info('Member profile updated', ['member_id' => $member->id]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to update member profile', [
                        'member_id' => $member->id,
                        'error' => $e->getMessage()
                    ]);
                    $this->viewData['error'] = 'Error updating profile: ' . $e->getMessage();
                }
            }
            }
        }
        
        $this->viewData['title'] = 'Edit Profile';
        $this->render('member/edit', $this->viewData);
    }
    
    /**
     * Member dashboard
     */
    public function dashboard($params = []) {
        $this->viewData['title'] = 'Dashboard';
        $this->render('member/dashboard', $this->viewData);
    }
    
    /**
     * Member settings
     */
    public function settings($params = []) {
        $request = Flight::request();
        if ($request->method === 'POST') {
            // Save settings
            foreach ($request->data as $key => $value) {
                if ($key !== 'csrf_token' && $key !== 'csrf_token_name') {
                    Flight::setSetting($key, $value);
                }
            }
            $this->viewData['success'] = 'Settings saved successfully';
        }
        
        // Get user settings via association
        $this->viewData['settings'] = $this->member->ownSettingsList;
        $this->viewData['title'] = 'Settings';
        $this->render('member/settings', $this->viewData);
    }
}