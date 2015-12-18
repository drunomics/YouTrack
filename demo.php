<?php
/**
 * Run composer install, first ofcourse.
 *
 * Example implementation usage
 * - Creates new Buzz/Curl instance
 * - Logs in to API
 * - Fetches issue, plus it's children / parent
 * - Sets issue status to built, with a comment.
 * - Logs time on issue
 * - Fetches all WorkItem lines for the issue (logged time)
 */

use Guzzle\Http\Client;

require('vendor/autoload.php');
require('src/YouTrack/YouTrackCommunicator.php');

$http = new Client('https://my.youtrack.endpoint');

$api = new \YouTrack\YouTrackCommunicator($http, array(
    'username' => 'myUser',
    'password' => 'myPassword'
));

// find issue entities from a string, by parsing issue
$issueIDs = $api->findIds("re #MYPRJ-1, fixes #MYPRJ-2, reopens #MYPRJ-3");
if(count($issueIDs) > 0) {
    $issues = $api->searchIssues($issueIDs);
    print_r($issues);
}

// finding a single issue does not use the #prefix findIds does!
$myIssue = $api->getIssue('MYPRJ-1');
$api->executeCommands($myIssue, ['State', 'Built'], 'I just closed this automagically.');
$api->trackTimeOnIssue($myIssue, 120, 'Time booked via YouTrackCommunicator API');

$loggedWork = $api->getWorkItemsForIssue($myIssue);