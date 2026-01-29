<?php
/**
 * Developer Studio Controller
 * Code-focused workspace for developers
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Studiodev extends BaseControls\Control {

    /**
     * Developer Studio main view
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Set studio preference
        Flight::setStudio('developer');

        // Get studio configuration
        $studioKey = 'developer';
        $studio = Studio::getStudioConfig($studioKey);
        $studios = Studio::getAllStudios();

        // Get GitHub connections
        $gitHubConnections = Bean::find('githubtokens', ' member_id = ? ', [$this->member->id]);

        // Get Jira/Atlassian connections
        $atlassianConnections = Bean::find('atlassiantoken', ' member_id = ? ', [$this->member->id]);

        // Get connected repos
        $repos = Bean::find('repoconnections',
            ' member_id = ? ORDER BY created_at DESC LIMIT 5 ',
            [$this->member->id]
        );

        // Get Jira boards
        $boards = Bean::find('jiraboards',
            ' member_id = ? ORDER BY created_at DESC LIMIT 5 ',
            [$this->member->id]
        );

        // Get AI agents
        $agents = Bean::find('aiagents', ' 1 ORDER BY name ASC LIMIT 5 ');

        // Get recent AI dev jobs
        $jobs = Bean::find('aidevjobs',
            ' member_id = ? ORDER BY created_at DESC LIMIT 5 ',
            [$this->member->id]
        );

        $this->render('studio/developer', [
            'title' => 'Developer Studio',
            'studio' => $studio,
            'studios' => $studios,
            'studioKey' => $studioKey,
            'gitHubConnected' => count($gitHubConnections) > 0,
            'gitHubConnections' => $gitHubConnections,
            'atlassianConnected' => count($atlassianConnections) > 0,
            'atlassianConnections' => $atlassianConnections,
            'repos' => $repos,
            'boards' => $boards,
            'agents' => $agents,
            'jobs' => $jobs
        ]);
    }
}
