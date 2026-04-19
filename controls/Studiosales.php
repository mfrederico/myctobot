<?php
/**
 * Sales & Marketing Studio Controller
 * CRM, live chat, and sales outreach workspace
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Studiosales extends BaseControls\Control {

    /**
     * Sales Studio main view
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Set studio preference
        Flight::setStudio('sales');

        $studioKey = 'sales';
        $studio = Studio::getStudioConfig($studioKey);
        $studios = Studio::getAllStudios();

        $memberId = $this->member->id;
        $isAdmin = $this->member->level <= LEVELS['ADMIN'];

        // Pipeline stage counts
        $stages = ['cold', 'warm', 'hot', 'closing'];
        $pipelineCounts = [];
        foreach ($stages as $stage) {
            $sql = "pipeline_stage = ? AND status_category = 'prospect'";
            $params = [$stage];
            if (!$isAdmin) {
                $sql .= ' AND member_id = ?';
                $params[] = $memberId;
            }
            $pipelineCounts[$stage] = Bean::count('crmcontact', $sql, $params);
        }

        // Customer count
        $custSql = "status_category = 'customer'";
        $customerCount = Bean::count('crmcontact', $custSql . ($isAdmin ? '' : ' AND member_id = ?'), $isAdmin ? [] : [$memberId]);

        // Total prospects
        $prospectCount = array_sum($pipelineCounts);

        // Recent activity
        $actSql = $isAdmin ? '1=1 ORDER BY created_at DESC LIMIT 10' : 'member_id = ? ORDER BY created_at DESC LIMIT 10';
        $recentActivity = Bean::find('crmactivity', $actSql, $isAdmin ? [] : [$memberId]);

        // Agreement status
        $hasSigned = Agreement::hasSignedAgreement($memberId);

        $this->render('studio/sales', [
            'title' => 'Sales & Marketing Studio',
            'studio' => $studio,
            'studios' => $studios,
            'studioKey' => $studioKey,
            'pipelineCounts' => $pipelineCounts,
            'prospectCount' => $prospectCount,
            'customerCount' => $customerCount,
            'recentActivity' => $recentActivity,
            'hasSigned' => $hasSigned,
            'isAdmin' => $isAdmin,
        ]);
    }
}
