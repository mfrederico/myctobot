<?php
/**
 * Detachableapppipelines Model
 * FUSE model for detachableapppipelines table
 *
 * Binds pipelines to a detachable app with an alias for the proxy endpoint.
 * Belongs to detachableapps via detachableapps_id.
 * Belongs to pipelines via pipelines_id.
 */

use \app\Bean;

class Model_Detachableapppipelines extends \RedBeanPHP\SimpleModel
{
    public function dispense()
    {
        $this->bean->is_active = 1;
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    public function update()
    {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        // Auto-generate alias from pipeline slug if empty
        if (empty($this->bean->alias) && $this->bean->pipelines_id) {
            $pipeline = Bean::load('pipelines', (int) $this->bean->pipelines_id);
            if ($pipeline && $pipeline->slug) {
                $this->bean->alias = $pipeline->slug;
            }
        }
    }

    public function toArray(): array
    {
        return [
            'id' => (int) $this->bean->id,
            'detachableapps_id' => (int) $this->bean->detachableapps_id,
            'pipelines_id' => (int) $this->bean->pipelines_id,
            'alias' => $this->bean->alias,
            'is_active' => (bool) $this->bean->is_active,
            'created_at' => $this->bean->created_at,
        ];
    }
}
