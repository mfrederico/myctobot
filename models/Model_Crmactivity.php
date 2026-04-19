<?php
/**
 * CRM Activity FUSE Model
 * Audit log for CRM actions (logins, contact changes, etc.)
 */

class Model_Crmactivity extends \RedBeanPHP\SimpleModel {

    public function update(): void {
        if (empty($this->bean->createdAt)) {
            $this->bean->createdAt = date('Y-m-d H:i:s');
        }
    }
}
