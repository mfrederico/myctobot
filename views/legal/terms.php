<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h1 class="mb-4">Terms of Service</h1>
            <p class="text-muted mb-4">Last updated: <?= date('F j, Y') ?></p>

            <div class="card mb-4">
                <div class="card-body">
                    <p>Welcome to MyCTOBot. These Terms of Service ("Terms") govern your use of the MyCTOBot service operated by ClickSimple, Inc. ("Company", "we", "us", or "our").</p>
                    <p>By accessing or using MyCTOBot, you agree to be bound by these Terms. If you disagree with any part of the terms, you may not access the service.</p>
                </div>
            </div>

            <h2 class="h4 mt-5 mb-3">1. Description of Service</h2>
            <p>MyCTOBot is an AI-powered development platform that integrates with Atlassian Jira and GitHub. The service includes:</p>
            <ul>
                <li><strong>Sprint Analysis:</strong> AI-powered daily digest emails with priority recommendations based on your Jira board data</li>
                <li><strong>AI Developer (Enterprise):</strong> Automated code implementation where AI reads your Jira tickets and creates pull requests in your GitHub repositories</li>
            </ul>
            <p>The service uses artificial intelligence (Claude by Anthropic) to analyze your data, understand requirements, and generate code implementations.</p>

            <h2 class="h4 mt-5 mb-3">2. Account Registration</h2>
            <p>To use MyCTOBot, you must:</p>
            <ul>
                <li>Create an account using a valid email address (Google OAuth available on main site only)</li>
                <li>Provide accurate and complete registration information</li>
                <li>Maintain the security of your account credentials</li>
                <li>Be at least 18 years old or have parental consent</li>
                <li>Have authorization to connect the Jira boards you configure</li>
            </ul>
            <p>You are responsible for all activities that occur under your account.</p>

            <h2 class="h4 mt-5 mb-3">3. Subscription Plans and Billing</h2>
            <h5>Free Tier</h5>
            <p>The free tier includes limited features with usage restrictions as described on our pricing page.</p>

            <h5>Pro Tier</h5>
            <p>The Pro subscription is billed monthly at the current rate displayed at checkout. Subscriptions automatically renew unless canceled before the renewal date.</p>

            <h5>Billing</h5>
            <ul>
                <li>Payments are processed securely through Stripe</li>
                <li>You authorize us to charge your payment method on a recurring basis</li>
                <li>Prices may change with 30 days notice to existing subscribers</li>
                <li>No refunds are provided for partial billing periods</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">4. Atlassian/Jira Integration</h2>
            <p>By connecting your Atlassian account:</p>
            <ul>
                <li>You authorize MyCTOBot to access your Jira data via OAuth 2.0</li>
                <li>You represent that you have permission to access the connected Jira boards</li>
                <li>You understand that we read Jira issue data to provide our analysis service</li>
                <li>You can revoke access at any time through your Atlassian account settings</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">5. GitHub Integration and AI Developer</h2>
            <p>By connecting your GitHub account and enabling AI Developer:</p>
            <ul>
                <li>You authorize MyCTOBot to access, read, and write to your specified repositories</li>
                <li>You represent that you have permission to grant repository access</li>
                <li>You understand AI will create branches, commits, and pull requests</li>
                <li>You accept responsibility for reviewing and merging AI-generated code</li>
                <li>You can revoke access at any time through GitHub settings</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">6. AI-Generated Code and Content</h2>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> <strong>Important:</strong> AI-generated code must be reviewed before deployment to production systems.
            </div>
            <p>MyCTOBot uses artificial intelligence (Claude by Anthropic) to analyze your data and generate code. You acknowledge that:</p>
            <ul>
                <li><strong>No Warranty:</strong> AI-generated code is provided "as is" without warranty of any kind</li>
                <li><strong>Review Required:</strong> All AI-generated code must be reviewed by qualified personnel before use</li>
                <li><strong>Your Responsibility:</strong> You are solely responsible for testing, validating, and deploying any code</li>
                <li><strong>No Liability:</strong> We are not liable for any damages resulting from AI-generated code, including bugs, security vulnerabilities, or production issues</li>
                <li><strong>Accuracy:</strong> We do not guarantee that AI implementations will correctly fulfill ticket requirements</li>
                <li><strong>Security:</strong> You must review AI-generated code for security vulnerabilities before deployment</li>
            </ul>

            <h5>Code Ownership</h5>
            <ul>
                <li>AI-generated code committed to your repositories is owned by you</li>
                <li>You grant us no rights to your proprietary code beyond what is needed to provide the service</li>
                <li>We do not claim ownership of any code in your repositories</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">7. Acceptable Use</h2>
            <p>You agree not to:</p>
            <ul>
                <li>Use the service for any unlawful purpose</li>
                <li>Attempt to gain unauthorized access to our systems</li>
                <li>Interfere with or disrupt the service</li>
                <li>Reverse engineer or attempt to extract source code</li>
                <li>Resell or redistribute the service without authorization</li>
                <li>Use automated tools to access the service beyond normal API usage</li>
                <li>Submit false, misleading, or harmful content</li>
                <li>Use AI Developer to generate malicious code or attack other systems</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">8. Intellectual Property</h2>
            <p>The MyCTOBot service, including its original content, features, and functionality, is owned by ClickSimple, Inc. and is protected by international copyright, trademark, and other intellectual property laws.</p>
            <p>Your Jira data remains your property. You grant us a limited license to process your data solely for providing the service.</p>

            <h2 class="h4 mt-5 mb-3">9. Data Retention and Deletion</h2>
            <ul>
                <li>We retain your data while your account is active</li>
                <li>You may request data deletion by contacting support</li>
                <li>Upon account deletion, we will remove your data within 30 days</li>
                <li>Some data may be retained as required by law or for legitimate business purposes</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">10. Service Availability</h2>
            <p>We strive to maintain high availability but do not guarantee uninterrupted service. We may:</p>
            <ul>
                <li>Perform scheduled maintenance with reasonable notice</li>
                <li>Experience unplanned outages due to technical issues</li>
                <li>Modify or discontinue features with notice to users</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">11. Limitation of Liability</h2>
            <p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, CLICKSIMPLE, INC. SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING BUT NOT LIMITED TO LOSS OF PROFITS, DATA, OR BUSINESS OPPORTUNITIES.</p>
            <p>Our total liability for any claims arising from your use of the service shall not exceed the amount paid by you in the twelve (12) months preceding the claim.</p>
            <p><strong>Specifically regarding AI-generated code:</strong> We are not liable for any damages arising from bugs, security vulnerabilities, data loss, system failures, or any other issues caused by AI-generated code, whether or not such code was reviewed before deployment.</p>

            <h2 class="h4 mt-5 mb-3">12. Disclaimer of Warranties</h2>
            <p>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, WHETHER EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT.</p>
            <p>WE SPECIFICALLY DISCLAIM ANY WARRANTY THAT AI-GENERATED CODE WILL BE FREE OF ERRORS, SECURE, OR FIT FOR ANY PARTICULAR PURPOSE.</p>

            <h2 class="h4 mt-5 mb-3">13. Indemnification</h2>
            <p>You agree to indemnify and hold harmless ClickSimple, Inc. and its officers, directors, employees, and agents from any claims, damages, losses, or expenses arising from your use of the service or violation of these Terms, including any claims related to AI-generated code you deploy.</p>

            <h2 class="h4 mt-5 mb-3">14. Termination</h2>
            <p>We may terminate or suspend your account immediately, without prior notice, for conduct that we believe:</p>
            <ul>
                <li>Violates these Terms</li>
                <li>Is harmful to other users or third parties</li>
                <li>Is fraudulent or illegal</li>
            </ul>
            <p>You may terminate your account at any time through your account settings or by contacting support.</p>

            <h2 class="h4 mt-5 mb-3">15. Changes to Terms</h2>
            <p>We reserve the right to modify these Terms at any time. We will notify users of material changes via email or through the service. Continued use after changes constitutes acceptance of the new Terms.</p>

            <h2 class="h4 mt-5 mb-3">16. Governing Law</h2>
            <p>These Terms shall be governed by and construed in accordance with the laws of the State of Delaware, United States, without regard to its conflict of law provisions.</p>

            <h2 class="h4 mt-5 mb-3">17. Contact Us</h2>
            <p>If you have questions about these Terms, please contact us:</p>
            <ul>
                <li>Email: legal@myctobot.ai</li>
                <li>Company: ClickSimple, Inc.</li>
            </ul>

            <div class="mt-5 pt-4 border-top">
                <p class="text-muted small">By using MyCTOBot, you acknowledge that you have read, understood, and agree to be bound by these Terms of Service.</p>
            </div>
        </div>
    </div>
</div>
