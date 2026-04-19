<div class="container" data-assist-page="help" data-assist-purpose="Help center with searchable documentation">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mb-4">Help Center</h1>
            
            <div class="row">
                <!-- Search Box -->
                <div class="col-md-12 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">How can we help you?</h5>
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Search for help..." id="helpSearch">
                                <button class="btn btn-primary" type="button">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Getting Started -->
                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-rocket-takeoff text-primary"></i> Getting Started
                            </h5>
                            <p class="card-text">New to TikNix? Start here to learn the basics.</p>
                            <ul class="list-unstyled">
                                <li><a href="#create-account">Creating an Account</a></li>
                                <li><a href="#first-login">Your First Login</a></li>
                                <li><a href="#profile-setup">Setting Up Your Profile</a></li>
                                <li><a href="#navigation">Navigating the Dashboard</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Account Management -->
                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-person-gear text-success"></i> Account Management
                            </h5>
                            <p class="card-text">Learn how to manage your account settings.</p>
                            <ul class="list-unstyled">
                                <li><a href="#change-password">Changing Your Password</a></li>
                                <li><a href="#update-profile">Updating Profile Information</a></li>
                                <li><a href="#privacy-settings">Privacy Settings</a></li>
                                <li><a href="#notifications">Managing Notifications</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Features -->
                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-tools text-info"></i> Features & Tools
                            </h5>
                            <p class="card-text">Explore all available features and tools.</p>
                            <ul class="list-unstyled">
                                <li><a href="#dashboard">Using the Dashboard</a></li>
                                <li><a href="#permissions">Understanding Permissions</a></li>
                                <li><a href="#admin-panel">Admin Panel (Admins Only)</a></li>
                                <li><a href="#api-access">API Access</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- FAQ Section -->
            <div class="row">
                <div class="col-md-12">
                    <h2 class="mb-3">Frequently Asked Questions</h2>
                    
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    How do I reset my password?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    To reset your password, click on "Forgot Password" on the login page. Enter your email address, and we'll send you instructions to reset your password. You can also change your password from your profile settings if you're already logged in.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    What are permission levels?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Permission levels control what features and areas you can access:
                                    <ul>
                                        <li><strong>Level 1 (ROOT):</strong> Full system access</li>
                                        <li><strong>Level 50 (ADMIN):</strong> Administrative access</li>
                                        <li><strong>Level 100 (MEMBER):</strong> Regular member access</li>
                                        <li><strong>Level 101 (PUBLIC):</strong> Guest/public access</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    How do I contact support?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    You can contact our support team by using the <a href="/contact">Contact Form</a>. We typically respond within 24-48 hours. For urgent issues, please indicate that in your message.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    Is my data secure?
                                </button>
                            </h2>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Yes! We take security seriously. All passwords are encrypted, sessions are secured, and we implement CSRF protection on all forms. We also regularly update our security measures to protect your data.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Still Need Help -->
            <div class="row mt-5">
                <div class="col-md-12">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <h4>Still need help?</h4>
                            <p>Can't find what you're looking for? Our support team is here to help!</p>
                            <a href="/contact" class="btn btn-primary">
                                <i class="bi bi-envelope"></i> Contact Support
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    transition: transform 0.2s;
}
.card:hover {
    transform: translateY(-5px);
}
.accordion-button:not(.collapsed) {
    background-color: #f8f9fa;
}
</style>