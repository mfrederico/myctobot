<?php
/**
 * SalesRepAgreement Model
 * FUSE model for salesrepagreement table.
 *
 * Fields:
 *   memberId, agreementVersion, agreementType, typedName,
 *   signatureData, ipAddress, userAgent, agreementText,
 *   status (pending|signed), signedAt, createdAt
 */

class Model_Salesrepagreement extends \RedBeanPHP\SimpleModel {

    public function update() {
        $bean = $this->bean;
        if (!$bean->createdAt) {
            $bean->createdAt = date('Y-m-d H:i:s');
        }
    }
}
