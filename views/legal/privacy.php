<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h1 class="mb-4">Privacy Policy</h1>
            <p class="text-muted mb-4">Last updated: <?= date('F j, Y') ?></p>

            <div class="card mb-4">
                <div class="card-body">
                    <p>ClickSimple, Inc. ("Company", "we", "us", or "our") operates MyCTOBot. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our service.</p>
                    <p>Please read this privacy policy carefully. By using MyCTOBot, you consent to the data practices described in this policy.</p>
                </div>
            </div>

            <h2 class="h4 mt-5 mb-3">1. Information We Collect</h2>

            <h5>Account Information</h5>
            <p>When you create an account, we collect:</p>
            <ul>
                <li>Email address</li>
                <li>Name (if provided via Google OAuth)</li>
                <li>Profile picture URL (if provided via Google OAuth)</li>
                <li>Password (hashed, if not using OAuth)</li>
            </ul>

            <h5>Jira Integration Data</h5>
            <p>When you connect your Atlassian account, we access:</p>
            <ul>
                <li>Jira board information (board names, IDs)</li>
                <li>Sprint data (sprint names, dates, status)</li>
                <li>Issue data (keys, summaries, descriptions, status, priority, assignees)</li>
                <li>User information (display names, account IDs for assignees and reporters)</li>
            </ul>
            <p>We do not store your Atlassian password. Authentication is handled via OAuth 2.0 tokens.</p>

            <h5>Usage Data</h5>
            <p>We automatically collect:</p>
            <ul>
                <li>Log data (IP address, browser type, pages visited, timestamps)</li>
                <li>Feature usage (analyses run, digests sent, boards configured)</li>
                <li>Error reports and performance metrics</li>
            </ul>

            <h5>Payment Information</h5>
            <p>Payment processing is handled by Stripe. We do not store your credit card numbers. We receive:</p>
            <ul>
                <li>Stripe customer ID</li>
                <li>Subscription status and billing history</li>
                <li>Last four digits of payment method (for display purposes)</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">2. How We Use Your Information</h2>
            <p>We use collected information to:</p>
            <ul>
                <li><strong>Provide the Service:</strong> Analyze your Jira data and generate digest emails</li>
                <li><strong>Process Payments:</strong> Manage subscriptions and billing</li>
                <li><strong>Send Communications:</strong> Daily digests, service announcements, and support responses</li>
                <li><strong>Improve the Service:</strong> Analyze usage patterns and fix bugs</li>
                <li><strong>Ensure Security:</strong> Detect and prevent fraud or abuse</li>
                <li><strong>Comply with Law:</strong> Respond to legal requests and enforce our terms</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">3. AI Processing and Code Access</h2>
            <div class="alert alert-info">
                <i class="bi bi-robot"></i> <strong>Important:</strong> MyCTOBot uses AI to analyze and implement code changes in your repositories.
            </div>

            <h5>AI Analysis</h5>
            <p>Your Jira data is processed by AI (Claude by Anthropic) to generate analysis and recommendations:</p>
            <ul>
                <li>Data is sent to Anthropic's API for processing</li>
                <li>Anthropic does not use your data to train their models (per their API terms)</li>
                <li>AI-generated analysis is stored temporarily for caching purposes</li>
                <li>You can request deletion of cached analysis at any time</li>
            </ul>

            <h5>AI Developer Feature (Enterprise)</h5>
            <p>When you enable the AI Developer feature, you grant MyCTOBot permission to:</p>
            <ul>
                <li><strong>Read your source code:</strong> AI accesses your GitHub repositories to understand your codebase architecture, patterns, and existing implementations</li>
                <li><strong>Write and modify code:</strong> AI creates, edits, and commits code to implement Jira tickets</li>
                <li><strong>Create branches and pull requests:</strong> AI creates feature branches and submits PRs to your repository</li>
                <li><strong>Access Jira ticket details:</strong> AI reads ticket descriptions, comments, and attachments to understand requirements</li>
                <li><strong>Post updates to Jira:</strong> AI comments on tickets with progress updates and completion status</li>
            </ul>

            <h5>Code Processing</h5>
            <p>When implementing tickets:</p>
            <ul>
                <li>Your code is sent to Anthropic's Claude API for analysis and generation</li>
                <li>Code is processed in Anthropic's secure environment</li>
                <li>Anthropic does <strong>not</strong> use your code to train AI models (per their commercial API terms)</li>
                <li>Generated code is committed to your repository with clear attribution</li>
                <li>All changes are made via pull requests for your review before merging</li>
            </ul>

            <h5>Data You Control</h5>
            <p>You maintain full control over AI Developer:</p>
            <ul>
                <li>Choose which boards/projects enable AI Developer</li>
                <li>Select which label triggers AI processing (default: <code>ai-dev</code>)</li>
                <li>Review all changes before merging pull requests</li>
                <li>Disconnect GitHub access at any time</li>
                <li>All code remains in your GitHub repository</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">4. Data Sharing and Disclosure</h2>
            <p>We do not sell your personal information. We may share data with:</p>

            <h5>Service Providers</h5>
            <ul>
                <li><strong>Anthropic:</strong> AI processing for analysis and code generation</li>
                <li><strong>GitHub:</strong> Repository access for AI Developer feature</li>
                <li><strong>Atlassian:</strong> Jira integration for ticket management</li>
                <li><strong>Stripe:</strong> Payment processing</li>
                <li><strong>Mailgun:</strong> Email delivery</li>
                <li><strong>Cloud hosting providers:</strong> Infrastructure and data storage</li>
            </ul>

            <h5>Legal Requirements</h5>
            <p>We may disclose information if required by law, court order, or government request, or to protect our rights, safety, or property.</p>

            <h5>Business Transfers</h5>
            <p>In the event of a merger, acquisition, or sale of assets, user data may be transferred. We will notify you of any such change.</p>

            <h2 class="h4 mt-5 mb-3">5. Data Storage and Security</h2>
            <ul>
                <li>Data is stored on secure servers in the United States</li>
                <li>We use encryption in transit (TLS/SSL) and at rest</li>
                <li>OAuth tokens are encrypted before storage</li>
                <li>Access to user data is restricted to authorized personnel</li>
                <li>We conduct regular security assessments</li>
            </ul>
            <p>While we implement safeguards, no method of transmission over the Internet is 100% secure. We cannot guarantee absolute security.</p>

            <h2 class="h4 mt-5 mb-3">6. Data Retention</h2>
            <ul>
                <li><strong>Account Data:</strong> Retained while your account is active</li>
                <li><strong>Jira Data:</strong> Cached temporarily for performance; refreshed on each analysis</li>
                <li><strong>Analysis Results:</strong> Stored for 30 days for reference</li>
                <li><strong>Logs:</strong> Retained for 90 days for debugging and security</li>
                <li><strong>Billing Records:</strong> Retained as required by tax law (typically 7 years)</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">7. Your Rights and Choices</h2>

            <h5>Access and Portability</h5>
            <p>You can request a copy of your personal data by contacting support.</p>

            <h5>Correction</h5>
            <p>You can update your account information through the settings page.</p>

            <h5>Deletion</h5>
            <p>You can request account deletion by contacting support. We will delete your data within 30 days, except as required by law.</p>

            <h5>Revoke Atlassian Access</h5>
            <p>You can disconnect your Jira integration at any time through your Atlassian account settings or our settings page.</p>

            <h5>Email Preferences</h5>
            <p>You can manage digest frequency and disable email notifications in your board settings.</p>

            <h5>Do Not Track</h5>
            <p>We do not currently respond to Do Not Track browser signals.</p>

            <h2 class="h4 mt-5 mb-3">8. Cookies and Tracking</h2>
            <p>We use cookies for:</p>
            <ul>
                <li><strong>Essential Cookies:</strong> Session management and authentication</li>
                <li><strong>Preference Cookies:</strong> Remembering your settings</li>
            </ul>
            <p>We do not use third-party advertising or tracking cookies.</p>

            <h2 class="h4 mt-5 mb-3">9. International Data Transfers</h2>
            <p>Your information may be transferred to and processed in the United States. By using our service, you consent to this transfer. We ensure appropriate safeguards are in place for international transfers.</p>

            <h2 class="h4 mt-5 mb-3">10. Children's Privacy</h2>
            <p>MyCTOBot is not intended for children under 18. We do not knowingly collect information from children. If you believe we have collected data from a child, please contact us immediately.</p>

            <h2 class="h4 mt-5 mb-3">11. California Privacy Rights (CCPA)</h2>
            <p>California residents have additional rights:</p>
            <ul>
                <li>Right to know what personal information is collected</li>
                <li>Right to delete personal information</li>
                <li>Right to opt-out of sale of personal information (we do not sell data)</li>
                <li>Right to non-discrimination for exercising privacy rights</li>
            </ul>
            <p>To exercise these rights, contact us at privacy@myctobot.ai.</p>

            <h2 class="h4 mt-5 mb-3">12. European Privacy Rights (GDPR)</h2>
            <p>If you are in the European Economic Area, you have rights under GDPR including:</p>
            <ul>
                <li>Right of access to your data</li>
                <li>Right to rectification of inaccurate data</li>
                <li>Right to erasure ("right to be forgotten")</li>
                <li>Right to restrict processing</li>
                <li>Right to data portability</li>
                <li>Right to object to processing</li>
            </ul>
            <p>Our legal basis for processing is your consent and legitimate business interests.</p>

            <h2 class="h4 mt-5 mb-3">13. Changes to This Policy</h2>
            <p>We may update this Privacy Policy from time to time. We will notify you of material changes via email or through the service. Your continued use after changes constitutes acceptance of the updated policy.</p>

            <h2 class="h4 mt-5 mb-3">14. Contact Us</h2>
            <p>If you have questions about this Privacy Policy or our data practices, please contact us:</p>
            <ul>
                <li>Email: privacy@myctobot.ai</li>
                <li>Company: ClickSimple, Inc.</li>
            </ul>
            <p>For data protection inquiries in the EU, you may also contact your local data protection authority.</p>

            <div class="mt-5 pt-4 border-top">
                <p class="text-muted small">By using MyCTOBot, you acknowledge that you have read and understood this Privacy Policy.</p>
            </div>
        </div>
    </div>
</div>
