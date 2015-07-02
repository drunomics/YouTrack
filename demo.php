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


require('vendor/autoload.php');
require('src/YouTrack/YouTrackCommunicator.php');

$client = new Buzz\Browser;
$client->setClient(new Buzz\Client\Curl());

$api = new \YouTrack\YouTrackCommunicator($client, array(
    'uri' => 'https://youtrack.myhost.com',
    'username' => 'myUser',
    'password' => 'myPassword'
));

$myIssue = $api->getIssue('MYPRJ-1');

$api->executeCommands($myIssue, ['State', 'Built'], 'I just closed this automagically.');

$api->trackTimeOnIssue($myIssue, 120, 'Time booked via YouTrackCommunicator API');

$loggedWork = $api->getWorkItemsForIssue($myIssue);

var_dump($loggedWork);