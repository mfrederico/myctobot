<?php
/**
 * CRM Note FUSE Model
 * Enables FUSE discovery for crmnote beans.
 */

class Model_Crmnote extends \RedBeanPHP\SimpleModel {

    public function update(): void {
        if (empty($this->bean->createdAt)) {
            $this->bean->createdAt = date('Y-m-d H:i:s');
        }
    }
}
