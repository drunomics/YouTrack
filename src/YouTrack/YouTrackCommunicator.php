<?php

namespace YouTrack;

use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;
use YouTrack\Entity\Issue;
use YouTrack\Entity\Project;
use YouTrack\Entity\WorkItem;
use YouTrack\Exception\APIException;

/**
 * REST wrapper api for youtrack.
 * Docs:
 * https://confluence.jetbrains.com/display/YTD4/YouTrack+REST+API+Reference
 * @author Bart van den Burg <bart@samson-it.nl>
 *
 * Updated December 2015 : JUR
 * - Fixes and refactoring for
 */
class YouTrackCommunicator
{
    private $guzzle;
    private $options;
    private $cookie = null;
    private $regexp = '\w+-\d+'; // youtrack issue id validation;
    private $issueCache = array(); // unique map of all issues.
    private $projectCache = array(); // unique map of all projects attached to issues.
    private $toLoad = array();
    private $executed = array();

    /**
     * Construct communicator and inject Guzzle instance.
     *
     * @param Guzzle Mockable guzzle instance
     * @param array   $options
     */
    public function __construct(Client $guzzle, $options = array())
    {
        $this->guzzle = $guzzle;
        $this->options = $options;
        $this->login();
    }

    /**
     * Exception throwing options fetcher.
     *
     * @throws InvalidArgumentException
     * @param $option parameter to fetch
     * @return mixed
     */
    protected function getOption($option)
    {
        if (!isset($this->options[$option])) {
            throw new \InvalidArgumentException('The option '.$option.' does not exist');
        }
        return $this->options[$option];
    }

    /**
     * Execute GET request on the base endpoint and return JSON.
     * @throws APIException
     * @return \Guzzle\Http\Client $client
     */
    protected function GETRequest($path, $data=array())
    {
        $startTime = microtime(true);
        $response = $this->guzzle->get($path, array(), $data)->send();
        if ($response->isError()) {
            throw new Exception\APIException(__METHOD__, $response);
        }
        $duration = microtime(true) - $startTime;
        $this->executed[] = Array('method'=> 'GET', 'duration'=> $duration, 'path'=> $path, 'data'=> $data);
        return $response->json();
    }

    /**
     * Execute POST request on the base endpoint and return JSON.
     * @throws APIException
     * @param $path
     * @param array $data POST data
     * @param array $headers optional extra headers.
     * @return array parsed json
     */
    protected function POSTRequest($path, $data = array(), $headers = array()) {
        $startTime = microtime(true);
        $response = $this->guzzle->post($path, $headers, $data)->send();
        $duration = microtime(true) - $startTime;
        $this->executed[] = Array('method'=> 'GET', 'duration'=> $duration, 'path'=> $path, 'data'=> $data);

        if ($response->isError()) {
            throw new Exception\APIException(__METHOD__, $response);
        }
        return $response->json();
    }

    /**
     * Login with the passed credentials.
     * Stores cookie when login success,.
     *
     * @throws APIException
     */
    public function login()
    {
        $response = $this->guzzle->post('rest/user/login', array(
            'Content-Type' => 'application/x-www-form-urlencoded',
        ), array(
            'login' => $this->getOption('username'),
            'password' => $this->getOption('password'),
        ))->send();

        if ($response->isError()) {
            throw new Exception\APIException(__METHOD__, $response);
        } else {
            $this->cookie = $response->getHeader('Set-Cookie')->__toString();
            $this->guzzle->setDefaultOption('headers', array(
                    'Cookie' => $this->cookie,
                    'Accept' => 'application/json')
            );
        }
    }

    /**
     * @param $string
     *
     * @return int
     */
    public function supports($string)
    {
        return preg_match('/^'.$this->regexp.'$/', $string);
    }

    /**
     * Public interface to parse a string for YouTrack Issue ID's ( #projectshortname-[0-9]+ )
     * You can basically pass a standard git commit string to this function and it will fetch an array of
     * Youtrack id's, which you can then validate by fetching them all with self::getIssues.
     *
     * @see getIssues
     *
     * @param $string
     *
     * @return array of string id's.
     */
    public function findIds($string)
    {
        preg_match_all('/#('.$this->regexp.')/', $string, $m);
        return $m[1];
    }

    /**
     * Parse issue data. Transform it into an Issue entity.
     *
     * Iterates parent issues so that it can build a tree structure with
     * parent issue connected.
     *
     * @param $id
     * @param array $data
     *
     * @return Issue
     */
    private function parseIssueData($id, array $data)
    {
        $issue = new Issue();
        $issue->setId($id);

        if (array_key_exists('projectShortName', $data['field'])) {
            $issue->setProject($data['field']['value']);
        }

        foreach ($data['field'] as $fieldData) {
            switch ($fieldData['name']) {
                case 'summary':
                    $issue->setSummary($fieldData['value']);
                    break;
                case 'projectId': // does this even still work?
                    $issue->getProjectEntity()->setId($fieldData['value']);
                    break;
                case 'State':
                    $issue->setStatus($fieldData['value'][0]);
                    break;
                case 'links':
                    foreach ($fieldData['value'] as $link) {
                        if ($link['role'] == 'subtask of') {
                            if (array_key_exists($link['value'], $this->issueCache)) {
                                // already loaded, set connection
                                $issue->setParent($this->getIssue($link['value']));
                            } else {
                                // this issue will also need to be loaded later (and the child will set the connection to the parent when loaded)
                                $this->toLoad[$link['value']] = true;
                            }
                        }
                        if ($link['role'] == 'parent for') {
                            if (array_key_exists($link['value'], $this->issueCache)) {
                                $issue->addChild($this->getIssue($link['value']));
                            } else {
                                // this issue will need to be loaded later (and the parent will set the connection to the child when loaded)
                                $this->toLoad[$link['value']] = true;
                            }
                        }
                    }
                    break;
                case 'Estimation':
                    $issue->setEstimate($fieldData['value'][0]);
                    break;
            }
        }

        return $issue;
    }

    /**
     * Fetch an issue and it's parent / child issues from the rest API.
     * preFetch config for project and inject it onto the issue
     * Store it in $this->issueCache.
     *
     * @throws APIException
     *
     * @param $id
     *
     * @return Issue
     */
    public function getIssue($id)
    {
        if (!isset($this->issueCache[$id])) {
            if ($id[0] == '#') {
                throw new \InvalidArgumentException('Supply the issue ID without the #');
            }

            try {
                $issueData = $this->guzzle->get('rest/issue/'.$id)->send()->json();
                $project = $this->preFetchProject($issueData); // prefetch project data and config for issue when not cached.

                $this->getTodo(); // fetch issues that are on the 'to fetch' list so that children/parents are set properly for this issue

                $issue = $this->parseIssueData($id, $issueData); // parse issue arraydata into an entity.
                $issue->setProjectEntity($project); // set prefetched project onto entity.
                $this->issueCache[$id] = $issue; //inject issue into issuecache.

            } catch (APIException $E) {
                if ($E->getResponse()->getStatusCode() == 404) {
                    $this->issueCache[$id] = null;
                    return;
                }
            }
        }

        return $this->issueCache[$id];
    }

    /**
     * Prefetch the project entity, or return a precached one from the projectCache array.
     * When the project doesn't exist yet, it creates the new entity and fetches TimeTrack settings.
     *
     * When timetrack settings are enabled on the project, they can also be fetched for the Issue entity.
     *
     * @param $issueData
     *
     * @return Project | null
     *
     * @throws \InvalidArgumentException
     */
    private function preFetchProject($issueData)
    {
        $projectName = null;

        foreach ($issueData['field'] as $idx => $info) {
            if ($info['name'] == 'projectShortName') {
                $projectName = $info['value'];
                break;
            }
        }

        if ($projectName == null) {
            throw \InvalidArgumentException("No Project found for issue {$issueData['id']}");
        } else {
            if (!array_key_exists($projectName, $this->projectCache)) {
                $project = new Project($projectName);
                $project->setSettings($this->getTimeTrackingSettings($project));
                $this->projectCache[$projectName] = $project;
            }

            return $this->projectCache[$projectName];
        }
    }

    /**
     * Parse and cache rest response for issue id.
     *
     * @param Response $response
     *
     * @return array[Issue]
     */
    private function getIssuesFromResponse(array $response, $withTimeTracking=false)
    {
        $issues = array();
        foreach ($response['issue'] as $issueData) {
            $issue = $this->parseIssueData($issueData['id'], $issueData);
            $issue->setProjectEntity($this->preFetchProject($issueData)); // set prefetched project onto entity.
            if($withTimeTracking) {
                try {
                    $this->getWorkItemsForIssue($issue);
                } catch (APIException $E) {
                    if (stripos($E->getMessage(), 'is disabled') !== false) { // catch "time tracking is disabled messages, handle silently."
                        // do nothing.
                    } else {
                        throw $E;
                    }
                }
            }
            $this->issueCache[$issueData['id']] = $issue;
            $issues[] = $this->issueCache[$issueData['id']];
        }

        return $issues;
    }

    /**
     * Fetch a list of issues from the api.
     *
     * @param array $ids
     *
     * @return array[Issue]
     */
    public function getIssues(array $ids, $withTimeTracking=true)
    {
        if (!count($ids)) {
            return array();
        }
        $search = implode('%20', array_map(function ($id) {
            return "%23$id";
        }, $ids));
        $response = $this->GETrequest('rest/issue?filter='.$search);
        $issues = $this->getIssuesFromResponse($response, $withTimeTracking);

        // get any todo pushed to the list, so that children/parents are set properly for this issue
        $this->getTodo();

        return $issues;
    }

    /**
     * Find a list of issues for a youtrack format filter.
     *
     * @param $filter
     * @param array  $with
     * @param int    $max
     * @param string $after
     *
     * @return array[Issue]
     */
    public function searchIssues($filter, $with = array(), $max = 10, $after = '')
    {
        $args = array_filter(array('filter' => $filter, 'with' => $with, 'max' => $max, 'after' => $after));
        $response = $this->GETRequest('rest/issue?'.http_build_query($args));
        $issues = $this->getIssuesFromResponse($response, true);
        // get any todo pushed to the list, so that children/parents are set properly for this issue
        $this->getTodo();
        return $issues;
    }

    /**
     * Internal method to fetch issues that we still need to fetch from the API.
     * Recursively calls itself until it fetched all parent/child issues.
     *
     * @return array
     */
    private function getTodo()
    {
        // if we have a todo, load those issues as well
        if (count($this->toLoad) > 0) {
            $load = $this->toLoad;
            $this->toLoad = array();
            $issues = $this->getIssues(array_keys($load));

            return array_merge($issues, $this->getTodo());
        }

        return array();
    }

    /**
     * Apply a command on an issue.
     *
     * @see https://confluence.jetbrains.com/display/YTD4/Command+Grammar
     * @see https://confluence.jetbrains.com/display/YTD4/Search+and+Command+Attributes
     * @see https://confluence.jetbrains.com/display/YTD3/Apply+Command+to+an+Issue
     *
     * @param Issue $issue    to execute commands on
     * @param array        $commands array of string commands (will be joined)
     * @param $comment A comment to add to an issue.
     * @param string $group  User group name. Use to specify visibility settings of a comment to be post.
     * @param bool   $silent If set 'true' then no notifications about changes made with the specified command will be send. By default, is 'false'.
     * @param null   $runAs  Login for a user on whose behalf the command should be executed. (Note, that to use runAs parameter you should have Update project permission in issue's project)
     *
     * @return mixed API Response
     */
    public function executeCommands(Issue $issue, array $commands, $comment, $group = '', $silent = false, $runAs = null)
    {
        $post = array();
        $post[] = 'command='.urlencode(implode(' ', $commands));
        $post[] = 'comment='.urlencode($comment);
        $post[] = 'disableNotifications='.($silent ? 'true' : 'false');
        if (null !== $group) {
            $post[] = 'group='.urlencode($group);
        }
        if (null !== $runAs) {
            $post[] = 'runAs='.$runAs;
        }

        return $this->POSTRequest('rest/issue/'.$issue->getId().'/execute', implode('&', $post));
    }

    /**
     * Find a youtrack internal username by passing an email.
     *
     * @throws APIException
     *
     * @param $email
     *
     * @return Login | null
     */
    public function findUserName($email)
    {
        $data = $this->GETRequest('rest/admin/user?q='.$email);
        foreach ($data as $userData) {
            $userData = $this->GETRequest($userData['url']);
            if ($userData['email'] == $email) {
                return $userData['login'];
            }
        }
        return;
    }

    /**
     * Fetch the list of 'fix versions' for a project.
     *
     * @throws APIException on failure
     *
     * @param $project youtrack project name
     *
     * @return first fix version from the list
     */
    private function getFixVersionBundleName($project)
    {
        $fieldData = $this->GETRequest('rest/admin/project/'.$project.'/customfield/Fix%20versions');
        return $fieldData['param'][0]['value'];
    }

    /**
     * Grab one of the version fields from the versionbundle
     * Fetches information on if a version was released, when, and on if it is active.
     *
     * @see https://confluence.jetbrains.com/display/YTD3/Get+a+Version+Bundle
     *
     * @param $bundleName
     * @return mixed
     */
    private function getVersionData($bundleName)
    {
        return $this->GETRequest('rest/admin/customfield/versionBundle/'.$bundleName);
    }

    /**
     * Fetch timetracking settings for the project.
     * Response includes at least an 'enabled' (0 | 1) field.
     * If Enabled == 1, you will also receive a list of enabled fields that can be queried using the project/customField api.
     *
     * @param Project $project
     *
     * @return mixed
     */
    private function getTimeTrackingSettings(Project $project)
    {
        return $this->GETRequest('rest/admin/project/'.$project->getName().'/timetracking');
    }

    /**
     * Tracks time on an issue instance passed.
     * Whenever a project has the 'time tracking' setting enabled, this will sum up the amount of minutes booked on an issue
     * When it' s not enabled, you can still log work on an issue, it just doens't show up in the totals.
     *
     * @see https://confluence.jetbrains.com/display/YTD6/Create%20New%20Work%20Item
     *
     * @param Issue $issue
     * @param int          $timeToBook in minutes
     * @param string       $comment    (optional, default: "Added via YouTrackCommunicator API"))
     * @param string       $type       (optional, default: "Development"). Possible: One of the allowed types by YouTrack: 'No type', 'Development', 'Testing', 'Documentation'
     * @param DateTime     $atDate     (optional) default date/time of booking, or current date when not passed.
     * @return bool added
     */
    public function trackTimeOnIssue(Issue $issue, $timeToBook = 0, $comment = 'Added via YouTrackCommunicator API.', $type = 'Development', $atDate=null)
    {
        $output = false;
        $atDate = $atDate !== null ? new \DateTime() : new DateTime($atDate);

        if ($issue->getProjectEntity()->getSetting('enabled') == 1) {
            $xml = sprintf('<workItem>
                <date>%s</date>
                <duration>%d</duration>
                <description>%s</description>
                <worktype><name>%s</name></worktype>
                </workItem>', $atDate->getTimestamp() * 1000, $timeToBook, $comment, $type);

            $response = $this->guzzle->post('rest/issue/'.$issue->getId().'/timetracking/workitem', array(
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Content-Length' => strlen($xml),
            ),  $xml)->send();

            if ($response->getStatusCode() != 201) {
                throw new Exception\APIException(__METHOD__.' WorkItem record not created. ', $response);
            } else {
                $output = true;
            }
        }

        return $output;
    }

    /**
     * Fetch WorkItem list for an issue (TimeTracking instances)
     * Removes some cruft and returns them as WorkItem entities.
     *
     * @param Issue $issue
     *
     * @return Array(WorkItem)
     */
    public function getWorkItemsForIssue(Issue $issue)
    {
        $items = $this->GETRequest('rest/issue/'.$issue->getId().'/timetracking/workitem');
        $output = array();

        foreach ($items as $item) {
            $labour = new WorkItem();
            $labour->setId($item['id']);
            $labour->setWorkItemUrl($item['url']);
            $labour->setAuthorUrl($item['author']['url']);
            $labour->setDate($item['date']);
            $labour->setDuration($item['duration']);
            $labour->setAuthorName($item['author']['login']);
            if (isset($item['description'])) {
                $labour->setComment($item['description']);
            }
            if (isset($item['worktype'])) {
                $labour->setType($item['worktype']['name']);
            }
            $output[] = $labour;
        }
        return $output;
    }
}