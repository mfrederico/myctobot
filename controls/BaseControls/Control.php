<?php
/**
 * Base Controller Class
 * All controllers should extend this class
 */

namespace app\BaseControls;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;

abstract class Control {
    
    protected $logger;
    protected $member;
    protected $viewData = [];
    
    public function __construct() {
        $this->logger = Flight::get('log');
        $this->member = Flight::getMember();
        
        // Initialize view data
        $this->viewData = [
            'member' => $this->member,
            'isLoggedIn' => Flight::isLoggedIn(),
            'menu' => Flight::loadMenu(),
            'title' => 'App',
            'csrf' => Flight::csrf()->getTokenArray()
        ];
        
        $this->logger->debug('Controller initialized: ' . get_class($this));
    }
    
    /**
     * Render a view with the sandwich layout (header/footer)
     */
    protected function render($template, $data = [], $layout = true) {
        // Merge with default view data
        $data = array_merge($this->viewData, $data);
        
        // Log the render action
        $this->logger->debug("Rendering view: {$template}");
        
        if ($layout) {
            // Render with layout (header/footer sandwich)
            Flight::render('layouts/header', $data, 'header_content');
            Flight::render($template, $data, 'body_content');
            Flight::render('layouts/footer', $data, 'footer_content');
            Flight::render('layouts/layout', $data);
        } else {
            // Render without layout (for AJAX, modals, etc)
            Flight::render($template, $data);
        }
    }
    
    /**
     * Check if user has required permission level
     */
    protected function requireLevel($level) {
        if (!Flight::hasLevel($level)) {
            $this->logger->warning('Access denied - insufficient level', [
                'required' => $level,
                'actual' => $this->member->level,
                'user' => $this->member->id
            ]);
            
            if (Flight::request()->ajax) {
                Flight::jsonError('Access denied', 403);
            } else {
                Flight::redirect('/error/forbidden');
            }
            return false;
        }
        return true;
    }
    
    /**
     * Check if user is logged in
     */
    protected function requireLogin() {
        if (!Flight::isLoggedIn()) {
            $this->logger->debug('Login required');
            
            if (Flight::request()->ajax) {
                Flight::jsonError('Login required', 401);
            } else {
                $redirect = urlencode(Flight::request()->url);
                Flight::redirect("/auth/login?redirect={$redirect}");
            }
            return false;
        }
        return true;
    }
    
    /**
     * Validate CSRF token
     */
    protected function validateCSRF() {
        if (Flight::request()->method !== 'GET') {
            try {
                if (!Flight::csrf()->validateRequest()) {
                    $this->logger->warning('CSRF validation failed');
                    
                    if (Flight::request()->ajax) {
                        Flight::jsonError('CSRF validation failed', 403);
                    } else {
                        Flight::redirect('/error/forbidden');
                    }
                    return false;
                }
            } catch (Exception $e) {
                $this->logger->error('CSRF validation error: ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get request parameter with optional default
     */
    protected function getParam($key, $default = null) {
        return Flight::request()->data->$key ?? 
               Flight::request()->query->$key ?? 
               $_REQUEST[$key] ?? 
               $default;
    }
    
    /**
     * Get all request parameters
     */
    protected function getParams() {
        return array_merge(
            (array)Flight::request()->query->getData(),
            (array)Flight::request()->data->getData(),
            $_REQUEST
        );
    }
    
    /**
     * Sanitize user input
     */
    protected function sanitize($input, $type = 'string') {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            
            case 'int':
                return (int)filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            
            case 'float':
                return (float)filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            
            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);
            
            case 'html':
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            
            case 'string':
            default:
                // FILTER_SANITIZE_STRING is deprecated in PHP 8.1+
                // Use htmlspecialchars instead for basic string sanitization
                return htmlspecialchars($input ?? '', ENT_QUOTES, 'UTF-8');
        }
    }
    
    /**
     * Handle file upload
     */
    protected function handleUpload($fieldName, $allowedTypes = [], $maxSize = 5242880) {
        if (!isset($_FILES[$fieldName])) {
            throw new Exception('No file uploaded');
        }
        
        $file = $_FILES[$fieldName];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload failed with error code: ' . $file['error']);
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            throw new Exception('File too large. Maximum size: ' . ($maxSize / 1048576) . 'MB');
        }
        
        // Check file type if restrictions are set
        if (!empty($allowedTypes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                throw new Exception('File type not allowed');
            }
        }
        
        // Generate safe filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeFilename = uniqid() . '_' . time() . '.' . $extension;
        
        return [
            'tmp_name' => $file['tmp_name'],
            'original_name' => $file['name'],
            'safe_name' => $safeFilename,
            'size' => $file['size'],
            'type' => $file['type']
        ];
    }
    
    /**
     * Send JSON response
     */
    protected function json($data, $code = 200) {
        Flight::json($data, $code);
    }
    
    /**
     * Send success JSON response
     */
    protected function jsonSuccess($data = [], $message = 'Success') {
        Flight::jsonSuccess($data, $message);
    }
    
    /**
     * Send error JSON response
     */
    protected function jsonError($message = 'Error', $code = 400) {
        Flight::jsonError($message, $code);
    }
    
    /**
     * Log and handle exceptions
     */
    protected function handleException(Exception $e, $userMessage = null) {
        $this->logger->error('Exception in ' . get_class($this) . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if (Flight::request()->ajax) {
            $this->jsonError($userMessage ?? 'An error occurred', 500);
        } else {
            Flight::set('error_message', $userMessage ?? 'An error occurred');
            $this->render('error/500', [
                'title' => 'Error',
                'message' => $userMessage ?? 'An error occurred',
                'debug' => Flight::get('debug') ? $e->getMessage() : null
            ]);
        }
    }
    
    /**
     * Start database transaction
     */
    protected function beginTransaction() {
        R::begin();
        $this->logger->debug('Transaction started');
    }
    
    /**
     * Commit database transaction
     */
    protected function commit() {
        R::commit();
        $this->logger->debug('Transaction committed');
    }
    
    /**
     * Rollback database transaction
     */
    protected function rollback() {
        R::rollback();
        $this->logger->debug('Transaction rolled back');
    }
    
    /**
     * Add flash message to session
     */
    protected function flash($type, $message) {
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        $_SESSION['flash'][] = [
            'type' => $type,
            'message' => $message
        ];
    }
    
    /**
     * Get and clear flash messages
     */
    protected function getFlashMessages() {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }

    /**
     * Convert markdown to HTML (simple implementation)
     */
    protected function markdownToHtml($markdown) {
        if (empty($markdown)) {
            return '';
        }

        $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Bold and italic
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);

        // Inline code
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        // Lists
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n)+/s', "<ul>\n$0</ul>\n", $html);

        // Links
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $html);

        // Line breaks and paragraphs
        $html = preg_replace('/\n\n+/', '</p><p>', $html);
        $html = preg_replace('/\n/', '<br>', $html);
        $html = '<p>' . $html . '</p>';

        // Clean up empty paragraphs
        $html = preg_replace('/<p>\s*<\/p>/', '', $html);
        $html = preg_replace('/<p>\s*<(h[1-3]|ul)/', '<$1', $html);
        $html = preg_replace('/<\/(h[1-3]|ul)>\s*<\/p>/', '</$1>', $html);

        return $html;
    }
}