<?php

namespace YouTrack;

use Guzzle\Http\Client;
use YouTrack\Exception\APIException;

/**
 * REST wrapper api for youtrack.
 *
 * Docs:
 * https://confluence.jetbrains.com/display/YTD4/YouTrack+REST+API+Reference
 *
 * @author Bart van den Burg <bart@samson-it.nl>
 */
class YouTrackCommunicator
{
    private $username;
    private $password;
    private $uri;
    private $regexp = '\w+-\d+'; // youtrack issue id validation;
    private $issueCache = array(); // unique map of all issues.
    private $projectCache = array(); // unique map of all projects attached to issues.
    private $toLoad = array();
    private $client;

    /**
     * YouTrackCommunicator constructor.
     * @param $username
     * @param $password
     */
    public function __construct($uri, $username, $password)
    {
        $this->uri = $uri;
        $this->username = $username;
        $this->password = $password;
        $this->client = new Client();
    }

    /**
     * Set default JSON headers and cookie injection.
     * @param  array $headers
     * @return array
     */
    protected function buildHeaders(array $headers = array())
    {
        $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        $headers[] = 'Accept: application/json';
        return $headers;
    }

    /**
     * Login with the passed credentials.
     * Stores cookie when login success,
     * @throws APIException
     */
    public function login()
    {
        $response = $this->client->get($this->uri . '/rest/user/login', array('Content-Type' => 'application/x-www-form-urlencoded'), array('login' => $this->username, 'password' => $this->password));

        if (!$response->getSt) {
            throw new Exception\APIException(__METHOD__, $response);
        }
    }

    /**
     *
     * @param $string
     * @return int
     */
    public function supports($string)
    {
        return preg_match('/^' . $this->regexp . '$/', $string);
    }

    /**
     * Public interface to parse a string for YouTrack Issue ID's ( #projectshortname-[0-9]+ )
     * You can basically pass a standard git commit string to this function and it will fetch an array of
     * Youtrack id's, which you can then validate by fetching them all with self::getIssues
     *
     * @see getIssues
     * @param $string
     * @return array of string id's.
     */
    public function findIds($string)
    {
        preg_match_all('/#(' . $this->regexp . ')/', $string, $m);
        return $m[1];
    }

    /**
     * Parse issue data. Transform it into an Issue entity.
     *
     * Iterates parent issues so that it can build a tree structure with
     * parent issue connected.
     * @param $id
     * @param  array $data
     * @return Entity\Issue
     */
    private function parseIssueData($id, array $data)
    {
        $issue = new Entity\Issue();
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
     * Store it in $this->issueCache
     * @throws APIException
     * @param $id
     * @return Issue
     */
    public function getIssue($id)
    {
        if (!isset($this->issueCache[$id])) {
            if ($id[0] == '#') {
                throw new \InvalidArgumentException('Supply the issue ID without the #');
            }

            $response = $this->browser->get($this->uri . '/rest/issue/' . $id, $this->buildHeaders());
            if ($response->isNotFound()) {
                $this->issueCache[$id] = null;

                return null;
            }
            if (!$response->isOk()) {
                throw new Exception\APIException(__METHOD__, $response);
            }

            $issueData = json_decode($response->getContent(), true);

            $project = $this->preFetchProject($issueData); // prefetch project data and config for issue when not cached.

            $this->getTodo(); // fetch issues that are on the 'to fetch' list so that children/parents are set properly for this issue

            $issue = $this->parseIssueData($id, $issueData); // parse issue arraydata into an entity.
            $issue->setProjectEntity($project); // set prefetched project onto entity.

            $this->issueCache[$id] = $issue; //inject issue into issuecache.
        }
        return $this->issueCache[$id];
    }

    /**
     * Prefetch the project entity, or return a precached one from the projectCache array.
     * When the project doesn't exist yet, it creates the new entity and fetches TimeTrack settings
     *
     * When timetrack settings are enabled on the project, they can also be fetched for the Issue entity.
     *
     * @param $issueData
     * @return Project | null
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
                $project = new Entity\Project($projectName);
                $project->setSettings($this->getTimeTrackingSettings($project));
                $this->projectCache[$projectName] = $project;
            }
            return $this->projectCache[$projectName];
        }
    }

    /**
     * Parse and cache rest response for issue id
     *
     * @param  Response $response
     * @return array[Issue]
     */
    private function getIssuesFromResponse(Response $response)
    {
        $issues = array();
        $content = json_decode($response->getContent(), true);
        foreach ($content['issue'] as $issueData) {
            $issue = $this->parseIssueData($issueData['id'], $issueData);
            $issue->setProjectEntity($this->preFetchProject($issueData)); // set prefetched project onto entity.
            try {
                $this->getWorkItemsForIssue($issue);
            } catch (APIException $E) {
                if (stripos($E->getMessage(), "is disabled") !== false) { // catch "time tracking is disabled messages, handle silently."
                    // do nothing.
                } else {
                    throw $E;
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
     * @param  array $ids
     * @return array[Issue]
     */
    public function getIssues(array $ids)
    {
        if (!count($ids)) {
            return array();
        }
        $search = implode("%20", array_map(function ($id) {
            return "%23$id";
        }, $ids));
        $response = $this->browser->get($this->uri . '/rest/issue?filter=' . $search, $this->buildHeaders());

        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }

        $issues = $this->getIssuesFromResponse($response);

        // get any todo pushed to the list, so that children/parents are set properly for this issue
        $this->getTodo();

        return $issues;
    }

    /**
     * Find a list of issues for a youtrack format filter
     * @param $filter
     * @param  array $with
     * @param  int $max
     * @param  string $after
     * @return array[Issue]
     */
    public function searchIssues($filter, $with = array(), $max = 10, $after = '')
    {
        $args = array_filter(array('filter' => $filter, 'with' => $with, 'max' => $max, 'after' => $after));
        $response = $this->browser->get($this->uri . '/rest/issue?' . http_build_query($args), $this->buildHeaders());

        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }

        $issues = $this->getIssuesFromResponse($response);
        // get any todo pushed to the list, so that children/parents are set properly for this issue
        $this->getTodo();

        return $issues;
    }

    /**
     * Internal method to fetch issues that we still need to fetch from the API.
     * Recursively calls itself until it fetched all parent/child issues
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
     * @param Entity\Issue $issue to execute commands on
     * @param array $commands array of string commands (will be joined)
     * @param $comment A comment to add to an issue.
     * @param string $group User group name. Use to specify visibility settings of a comment to be post.
     * @param bool $silent If set 'true' then no notifications about changes made with the specified command will be send. By default, is 'false'.
     * @param null $runAs Login for a user on whose behalf the command should be executed. (Note, that to use runAs parameter you should have Update project permission in issue's project)
     *
     * @return mixed API Response
     */
    public function executeCommands(Entity\Issue $issue, array $commands, $comment, $group = '', $silent = false, $runAs = null)
    {
        $post = array();
        $post[] = 'command=' . urlencode(implode(" ", $commands));
        $post[] = 'comment=' . urlencode($comment);
        $post[] = 'disableNotifications=' . ($silent ? 'true' : 'false');
        if (null !== $group) {
            $post[] = 'group=' . urlencode($group);
        }
        if (null !== $runAs) {
            $post[] = 'runAs=' . $runAs;
        }

        $response = $this->browser->post($this->uri . '/rest/issue/' . $issue->getId() . '/execute', $this->buildHeaders(), implode("&", $post));

        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }
        return $response;
    }

    /**
     * Find a youtrack internal username by passing an email
     *
     * @throws APIException
     * @param $email
     * @return Login | null
     */
    public function findUserName($email)
    {
        $response = $this->browser->get($this->uri . '/rest/admin/user?q=' . $email, $this->buildHeaders());
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }

        $data = json_decode($response->getContent(), true);
        foreach ($data as $userData) {
            $response = $this->browser->get($userData['url'], $this->buildHeaders());
            if (!$response->isOk()) {
                throw new Exception\APIException(__METHOD__, $response);
            }

            $userData = json_decode($response->getContent(), true);

            if ($userData['email'] == $email) {
                return $userData['login'];
            }
        }

        return null;
    }

    /**
     * Set a specific 'Fix Version' to 'released.' at current date.
     * @deprecated
     * @param $project
     * @param $version
     * @return bool
     */
    public function releaseVersion($project, $version)
    {
        $bundleName = $this->getFixVersionBundleName($project);
        $versionsData = $this->getVersionData($bundleName);

        $foundVersions = array();
        foreach ($versionsData['version'] as $versionData) {
            $foundVersions[] = $versionData['value'];
            if ($versionData['value'] == $version) {
                $response = $this->browser->post($this->uri . '/rest/admin/customfield/versionBundle/' . $bundleName . '/' . $version, $this->buildHeaders(), http_build_query(array(
                    'releaseDate' => time() . '000',
                    'released' => "true"
                )));
                if (!$response->isOk()) {
                    throw new Exception\APIException(__METHOD__, ' (tag version)', $response);
                }

                return true;
            }
        }

        throw new \InvalidArgumentException('The tagged version does not exist in YouTrack (found: ' . implode(", ", $foundVersions) . ')');
    }

    /**
     * Set a specific 'Fix Version' to 'released.' at current date.
     * @throws APIException
     * @throws InvalidArgumentException
     * @deprecated
     * @param $project
     * @param $version
     * @return bool
     */
    public function unreleaseVersion($project, $version)
    {
        $bundleName = $this->getFixVersionBundleName($project);
        $versionsData = $this->getVersionData($bundleName);

        $foundVersions = array();
        foreach ($versionsData['version'] as $versionData) {
            $foundVersions[] = $versionData['value'];
            if ($versionData['value'] == $version) {
                $response = $this->browser->post($this->uri . '/rest/admin/customfield/versionBundle/' . $bundleName . '/' . $version, $this->buildHeaders(), http_build_query(array(
                    'released' => "false"
                )));
                if (!$response->isOk()) {
                    throw new Exception\APIException(__METHOD__ . ' (tag version)', $response);
                }

                return true;
            }
        }

        throw new \InvalidArgumentException('The untagged version does not exist in YouTrack (found: ' . implode(", ", $foundVersions) . ')');
    }

    /**
     * Fetch the list of 'fix versions' for a project
     * @throws APIException on failure
     * @param $project youtrack project name
     * @return first        fix version from the list
     */
    private function getFixVersionBundleName($project)
    {
        $response = $this->browser->get($this->uri . '/rest/admin/project/' . $project . '/customfield/Fix%20versions', $this->buildHeaders());
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }
        $fieldData = json_decode($response->getContent(), true);

        return $fieldData['param'][0]['value'];
    }

    /**
     * Grab one of the version fields from the versionbundle
     * Fetches information on if a version was released, when, and on if it is active.
     * @see https://confluence.jetbrains.com/display/YTD3/Get+a+Version+Bundle
     * @param $bundleName
     * @return mixed
     */
    private function getVersionData($bundleName)
    {
        $response = $this->browser->get($this->uri . '/rest/admin/customfield/versionBundle/' . $bundleName, $this->buildHeaders());
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__ . ' (get version field data)', $response);
        }

        return json_decode($response->getContent(), true);
    }

    /**
     * Fetch timetracking settings for the project.
     * Response includes at least an 'enabled' (0 | 1) field.
     * If Enabled == 1, you will also receive a list of enabled fields that can be queried using the project/customField api.
     * @param Entity\Project $project
     * @return mixed
     */
    private function getTimeTrackingSettings(Entity\Project $project)
    {
        $response = $this->browser->get($this->uri . '/rest/admin/project/' . $project->getName() . '/timetracking', $this->buildHeaders());
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }
        return json_decode($response->getContent(), true);
    }

    /**
     * Tracks time on an issue instance passed.
     * Whenever a project has the 'time tracking' setting enabled, this will sum up the amount of minutes booked on an issue
     * When it' s not enabled, you can still log work on an issue, it just doens't show up in the totals.
     *
     * @see https://confluence.jetbrains.com/display/YTD6/Create%20New%20Work%20Item
     * @param Entity\Issue $issue
     * @param int $timeToBook in minutes
     * @param string $comment (optional, default: "Added via YouTrackCommunicator API"))
     * @param string $type (optional, default: "Development"). Possible: One of the allowed types by YouTrack: 'No type', 'Development', 'Testing', 'Documentation'
     * @return boolean added
     */
    public function trackTimeOnIssue(Entity\Issue $issue, $timeToBook = 0, $comment = 'Added via YouTrackCommunicator API.', $type = 'Development')
    {
        $output = false;

        if ($issue->getProjectEntity()->getSetting('enabled') == 1) {

            $xml = sprintf("<workItem>
                <date>%s</date>
                <duration>%d</duration>
                <description>%s</description>
                <worktype><name>%s</name></worktype>
                </workItem>", time() * 1000, $timeToBook, $comment, $type);

            $response = $this->browser->post(
                $this->uri . '/rest/issue/' . $issue->getId() . '/timetracking/workitem',
                $this->buildHeaders(array('Content-Type: application/xml; charset=UTF-8', 'Content-Length: ' . strlen($xml))),
                $xml);

            if ($response->getStatusCode() != 201) {
                throw new Exception\APIException(__METHOD__ . ' WorkItem record not created. ', $response);
            } else {
                $output = true;
            }
        }
        return $output;
    }


    /**
     * Fetch WorkItem list for an issue (TimeTracking instances)
     * Removes some cruft and returns them as WorkItem entities.
     * @param Entity\Issue $issue
     * @return Array(Entity\WorkItem)
     */
    public function getWorkItemsForIssue(Entity\Issue $issue)
    {

        $response = $this->browser->get(
            $this->uri . '/rest/issue/' . $issue->getId() . '/timetracking/workitem',
            $this->buildHeaders());

        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__ . ' Could not fetch workItem records. ', $response);
        } else {
            $output = array();
            $items = json_decode($response->getContent());

            foreach ($items as $item) {
                $labour = new Entity\WorkItem();
                $labour->setId($item->id);
                $labour->setWorkItemUrl($item->url);
                $labour->setAuthorUrl($item->author->url);
                $labour->setDate($item->date);
                $labour->setDuration($item->duration);
                $labour->setAuthorName($item->author->login);
                if (isset($item->description)) {
                    $labour->setComment($item->description);
                }
                if (isset($item->worktype)) {
                    $labour->setType($item->worktype->name);
                }

                $output[] = $labour;
            }

            return $output;
        }
    }
}