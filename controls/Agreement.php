<?php
/**
 * Agreement Controller
 * Handles sales rep referral agreement signing with digital signature.
 *
 * Routes:
 *   GET  /agreement         -> index() - Show agreement with signature pad
 *   POST /agreement/sign    -> sign()  - Process signature submission
 *   GET  /agreement/view    -> view()  - View signed agreement
 *   GET  /agreement/download -> download() - Download PDF of signed agreement
 *
 * Access: SALES level (75) and above
 *
 * Sales reps must sign the agreement before accessing the CRM.
 * The CRM controller checks Agreement::hasSignedAgreement() on every request.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\PdfRenderer;

class Agreement extends BaseControls\Control {

    private const AGREEMENT_VERSION = '1.0';

    /**
     * Override render to use CRM layout
     */
    protected function render($template, $data = [], $layout = true) {
        $data = array_merge($this->viewData, $data);
        if ($layout) {
            Flight::render($template, $data, 'body_content');
            Flight::render('layouts/crm-layout', $data);
        } else {
            Flight::render($template, $data);
        }
    }

    /**
     * Show the agreement for signing
     */
    public function index() {
        $member = $this->member;

        // Already signed? Redirect to view
        $existing = Bean::findOne('salesrepagreement',
            'member_id = ? AND status = ? AND agreement_version = ?',
            [$member->id, 'signed', self::AGREEMENT_VERSION]
        );
        if ($existing) {
            Flight::redirect('/agreement/view');
            return;
        }

        $this->render('agreement/sign', [
            'title' => 'Sales Referral Agreement',
            'crm_page' => 'agreement',
            'hide_nav' => true,
            'member' => $member,
            'agreementVersion' => self::AGREEMENT_VERSION,
            'agreementHtml' => self::getAgreementHtml($member),
        ]);
    }

    /**
     * Process the signature submission
     */
    public function sign() {
        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/agreement');
            return;
        }

        if (!$this->validateCSRF()) {
            $this->flash('danger', 'Invalid security token. Please try again.');
            Flight::redirect('/agreement');
            return;
        }

        $member = $this->member;
        $signatureData = $this->getParam('signature_data', '');
        $typedName = $this->sanitize($this->getParam('typed_name', ''));
        $agreedCheckbox = $this->getParam('agreed', '');

        // Validate
        if (empty($signatureData) || empty($typedName) || $agreedCheckbox !== '1') {
            $this->flash('danger', 'Please complete all fields: type your name, draw your signature, and check the agreement box.');
            Flight::redirect('/agreement');
            return;
        }

        // Validate signature is a data URL (base64 PNG from canvas)
        if (!preg_match('/^data:image\/png;base64,/', $signatureData)) {
            $this->flash('danger', 'Invalid signature data.');
            Flight::redirect('/agreement');
            return;
        }

        // Check for existing signed agreement
        $existing = Bean::findOne('salesrepagreement',
            'member_id = ? AND status = ? AND agreement_version = ?',
            [$member->id, 'signed', self::AGREEMENT_VERSION]
        );
        if ($existing) {
            Flight::redirect('/agreement/view');
            return;
        }

        // Store the agreement
        $agreement = Bean::dispense('salesrepagreement');
        $agreement->memberId = $member->id;
        $agreement->agreementVersion = self::AGREEMENT_VERSION;
        $agreement->agreementType = 'sales_referral';
        $agreement->typedName = $typedName;
        $agreement->signatureData = $signatureData;
        $agreement->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $agreement->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $agreement->agreementText = self::getAgreementText();
        $agreement->status = 'signed';
        $agreement->signedAt = date('Y-m-d H:i:s');
        $agreement->createdAt = date('Y-m-d H:i:s');
        Bean::store($agreement);

        // Log activity
        $activity = Bean::dispense('crmactivity');
        $activity->memberId = $member->id;
        $activity->action = 'agreement_signed';
        $activity->details = 'Signed Sales Referral Agreement v' . self::AGREEMENT_VERSION;
        $activity->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $activity->createdAt = date('Y-m-d H:i:s');
        Bean::store($activity);

        $this->flash('success', 'Agreement signed successfully. Welcome to the team!');
        Flight::redirect('/crm');
    }

    /**
     * View the signed agreement
     */
    public function view() {
        $member = $this->member;

        $agreement = Bean::findOne('salesrepagreement',
            'member_id = ? AND status = ? ORDER BY signed_at DESC',
            [$member->id, 'signed']
        );

        if (!$agreement) {
            Flight::redirect('/agreement');
            return;
        }

        $this->render('agreement/view', [
            'title' => 'Signed Agreement',
            'crm_page' => 'agreement',
            'member' => $member,
            'agreement' => $agreement,
        ]);
    }

    /**
     * Download the signed agreement (for the logged-in sales rep) as a PDF.
     * Route: GET /agreement/download
     */
    public function download() {
        $member = $this->member;

        $agreement = Bean::findOne('salesrepagreement',
            'member_id = ? AND status = ? ORDER BY signed_at DESC',
            [$member->id, 'signed']
        );

        if (!$agreement) {
            Flight::redirect('/agreement');
            return;
        }

        self::streamSignedPdf($agreement, $member);
    }

    /**
     * Build the PDF for a signed agreement and stream it as a download.
     * Shared by the member-facing download() and the admin download route.
     *
     * @param object $agreement  A signed salesrepagreement bean.
     * @param object $member      The member who signed it.
     */
    public static function streamSignedPdf($agreement, $member): void {
        $html = self::buildSignedDocumentHtml($agreement, $member);

        $name = trim((string) ($member->display_name ?? '')) ?: $agreement->typedName;
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $name);
        $slug = trim($slug, '-') ?: 'sales-rep';
        $filename = "sales-referral-agreement-{$slug}.pdf";

        $pdf = PdfRenderer::fromHtml($html, [
            'title'   => 'Sales Referral Agreement',
            'author'  => 'ClickSimple LLC (DBA ShipCannon)',
            'subject' => 'Signed Sales Referral Agreement',
        ]);

        // Stream the PDF directly; bypass the view layer entirely.
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }
        echo $pdf;
        exit;
    }

    /**
     * Render a complete, self-contained HTML document for a signed agreement,
     * including the full agreement text and the signing record + signature image.
     * Reflects the exact text that was stored when the agreement was signed.
     */
    public static function buildSignedDocumentHtml($agreement, $member): string {
        $bodyHtml = self::formatAgreementTextHtml((string) $agreement->agreementText);
        // The shared formatter targets the browser and uses `rem` font sizes,
        // which TCPDF does not understand (it collapses the text to an unreadable
        // size). Drop the override so clause text inherits the base PDF font.
        $bodyHtml = str_replace('font-size:0.9rem;', '', $bodyHtml);

        $signedName  = htmlspecialchars((string) $agreement->typedName);
        $signedAt    = $agreement->signedAt
            ? date('F j, Y \a\t g:i A T', strtotime($agreement->signedAt))
            : 'Unknown';
        $version     = htmlspecialchars((string) $agreement->agreementVersion);
        $ipAddress   = htmlspecialchars((string) $agreement->ipAddress);
        $email       = htmlspecialchars((string) ($member->email ?? ''));
        $signatureImg = '';
        if (preg_match('/^data:image\/(png|jpe?g);base64,/', (string) $agreement->signatureData)) {
            $signatureImg = '<img src="' . htmlspecialchars($agreement->signatureData)
                . '" style="width:220px;height:auto;" alt="Signature" />';
        }

        // Inline CSS only — TCPDF supports a CSS subset.
        return <<<HTML
<style>
    h1 { font-size: 15pt; text-align: center; }
    h2.subtitle { font-size: 10pt; color: #666666; text-align: center; }
    .agreement-body { font-size: 9.5pt; line-height: 1.5; }
    .sig-block { border: 1px solid #cccccc; padding: 10px; }
    .sig-label { font-size: 8pt; color: #888888; }
    .sig-value { font-size: 10pt; font-weight: bold; }
    td { vertical-align: top; padding: 4px; }
</style>

<div class="agreement-body">
{$bodyHtml}
</div>

<br><br>
<h2 style="font-size:11pt; font-weight:bold;">Signature & Signing Record</h2>
<div class="sig-block">
    <table cellpadding="4" style="width:100%;">
        <tr>
            <td style="width:50%;">
                <span class="sig-label">Signed By</span><br>
                <span class="sig-value">{$signedName}</span><br>
                <span class="sig-label">{$email}</span>
            </td>
            <td style="width:50%;">
                <span class="sig-label">Date &amp; Time</span><br>
                <span class="sig-value">{$signedAt}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="sig-label">Agreement Version</span><br>
                <span class="sig-value">v{$version}</span>
            </td>
            <td>
                <span class="sig-label">IP Address</span><br>
                <span class="sig-value">{$ipAddress}</span>
            </td>
        </tr>
    </table>
    <br>
    <span class="sig-label">Signature</span><br>
    {$signatureImg}
</div>
HTML;
    }

    /**
     * Check if a member has signed the current agreement version.
     * Called by CRM controller to gate access.
     */
    public static function hasSignedAgreement(int $memberId): bool {
        $agreement = Bean::findOne('salesrepagreement',
            'member_id = ? AND status = ? AND agreement_version = ?',
            [$memberId, 'signed', self::AGREEMENT_VERSION]
        );
        return !empty($agreement);
    }

    /**
     * Get the agreement text (plain text for storage)
     */
    private static function getAgreementText(): string {
        return <<<'AGREEMENT'
CLICKSIMPLE LLC (DBA SHIPCANNON) / CANNONWMS SALES REFERRAL AGREEMENT
Version 1.0

This Sales Referral Agreement ("Agreement") is entered into between ClickSimple LLC, doing business as ShipCannon ("Company"), and the undersigned Sales Representative ("Representative").

1. ENGAGEMENT
Representative is engaged as an independent sales representative to identify, contact, and close new customers for CannonWMS warehouse management software. Representative is not an employee of the Company and is responsible for their own taxes, insurance, and expenses.

2. COMPENSATION
a) Representative shall receive twenty percent (20%) of monthly recurring revenue from each customer account they close, for as long as Representative remains active with the Company.
b) If Representative recruits another sales representative who closes an account, compensation splits as follows: the closing representative receives fifteen percent (15%) and the recruiting representative receives a five percent (5%) override.
c) Commission is calculated on the customer's monthly invoice amount, excluding one-time implementation fees and third-party pass-through costs (e.g., carrier shipping charges).
d) "Close" means the customer has signed a CannonWMS service agreement and made their first payment.

3. PAYMENT TERMS
a) Commission payments are made monthly, net-30 after the customer's payment is received by the Company.
b) If a customer fails to pay or disputes a charge, commission on that amount is withheld until the payment is collected.
c) If a customer cancels or churns, commission ceases on the effective cancellation date.

4. ACCOUNT OWNERSHIP
a) Representative owns the customer relationship for accounts they close.
b) Account ownership remains with Representative as long as they are active with the Company.
c) Upon termination, Representative's accounts are reassigned to the next available sales representative in the same region. The departing Representative has no further claim to commission on reassigned accounts.

5. NON-SOLICITATION
a) During the term of this Agreement and for twelve (12) months after termination, Representative shall not directly or indirectly solicit, contact, or attempt to divert any customer of the Company for the purpose of selling competing warehouse management software or services.
b) "Customer" includes any entity that is or was a paying CannonWMS customer during Representative's engagement.

6. CONFIDENTIALITY
a) Representative agrees to keep confidential all proprietary information of the Company, including but not limited to: customer lists, lead databases, customer data, pricing strategies, product roadmap, internal processes, and business plans.
b) Representative shall not disclose confidential information to any third party without prior written consent of the Company.
c) This obligation survives termination of this Agreement indefinitely.

7. CUSTOMER DATA
a) Representative acknowledges that all customer data accessed through Company systems remains the property of the Company.
b) Representative shall not copy, export, or retain customer data outside of Company-provided tools (CRM, email, etc.).
c) Upon termination, Representative shall immediately cease accessing Company systems and delete any locally stored customer information.

8. TERMINATION
a) Either party may terminate this Agreement at any time with written notice (email is sufficient).
b) Upon termination, unpaid commissions for accounts already closed and paid by customers will be paid through the next scheduled payment cycle.
c) Future commissions on the Representative's accounts cease upon termination, and accounts are reassigned per Section 4(c).

9. NO BASE SALARY
Representative acknowledges that this is a commission-only engagement. There is no base salary, draw, or guaranteed minimum compensation. Representative's sole compensation is the commission described in Section 2.

10. INDEPENDENT CONTRACTOR
Representative is an independent contractor, not an employee. Company will not withhold taxes, provide benefits, or make employer contributions on Representative's behalf. Representative is responsible for all applicable self-employment taxes and obligations.

11. GOVERNING LAW
This Agreement shall be governed by the laws of the State in which the Company is incorporated, without regard to conflict of law principles.

12. ENTIRE AGREEMENT
This Agreement constitutes the entire agreement between the parties regarding the subject matter herein and supersedes all prior negotiations, representations, or agreements. Amendments must be in writing and signed by both parties.
AGREEMENT;
    }

    /**
     * Get the agreement as formatted HTML (for display)
     */
    private static function getAgreementHtml($member): string {
        return self::formatAgreementTextHtml(self::getAgreementText());
    }

    /**
     * Format an agreement plain-text body into structured HTML.
     * Operates on whatever text is passed in (e.g. the stored, signed copy)
     * so rendered output always reflects exactly what was agreed to.
     */
    private static function formatAgreementTextHtml(string $text): string {
        $html = '';

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }
            // Main title
            if (strpos($trimmed, 'CLICKSIMPLE') === 0) {
                $html .= '<h4 class="text-center fw-bold mb-1">' . htmlspecialchars($trimmed) . '</h4>';
            }
            // Version line
            elseif (strpos($trimmed, 'Version') === 0) {
                $html .= '<p class="text-center text-muted mb-4">' . htmlspecialchars($trimmed) . '</p>';
            }
            // Section headers (numbered)
            elseif (preg_match('/^\d+\.\s+[A-Z]/', $trimmed)) {
                $html .= '<h6 class="fw-bold mt-4 mb-2">' . htmlspecialchars($trimmed) . '</h6>';
            }
            // Sub-items (lettered)
            elseif (preg_match('/^[a-z]\)/', $trimmed)) {
                $html .= '<p class="ms-3 mb-2" style="font-size:0.9rem;">' . htmlspecialchars($trimmed) . '</p>';
            }
            // Intro paragraph
            elseif (strpos($trimmed, 'This Sales Referral') === 0) {
                $html .= '<p class="mb-3" style="font-size:0.9rem;">' . htmlspecialchars($trimmed) . '</p>';
            }
            else {
                $html .= '<p class="mb-2" style="font-size:0.9rem;">' . htmlspecialchars($trimmed) . '</p>';
            }
        }

        return $html;
    }
}
