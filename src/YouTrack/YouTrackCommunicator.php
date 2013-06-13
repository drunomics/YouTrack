<?php

namespace YouTrack;

use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Buzz\Message\Response;

/**
 * @author Bart van den Burg <bart@samson-it.nl>
 */
class YouTrackCommunicator
{
    private $browser;

    private $options;

    private $cookie = null;

    private $regexp = '\w+-\d+';

    private $issueCache = array();
    
    private $toLoad = array();
    
    public function __construct(Browser $browser, array $options)
    {
        if ($browser->getClient() instanceof FileGetContents) {
            throw new \InvalidArgumentException('The FileGetContents client is known not to work with this library. Please instantiate the Browser with an instance of \Buzz\Client\Curl');
        }
        $this->browser = $browser;
        $this->options = $options;
    }

    protected function getOption($option)
    {
        if (!isset($this->options[$option])) {
            throw new \InvalidArgumentException('The option '.$option.' does not exist');
        }
        return $this->options[$option];
    }

    protected function getBrowser()
    {
        return $this->browser;
    }

    protected function buildHeaders(array $headers = array())
    {
        if (null === $this->cookie) {
            $this->login();
        }
        foreach ($this->cookie as $cookie) {
            $headers[] = 'Cookie: '.$cookie;
        }
        $headers[] = 'Accept: application/json';

        return $headers;
    }

    public function login()
    {
        $response = $this->browser->post($this->getOption('uri').'/rest/user/login', array(
            'Content-Type' => 'application/x-www-form-urlencoded'
            ), array('login' => $this->getOption('username'), 'password' => $this->getOption('password')));

        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }

        $this->cookie = $response->getHeader('Set-Cookie', false);
    }

    public function supports($string)
    {
        return preg_match('/^'.$this->regexp.'$/', $string);
    }

    public function findIds($string)
    {
        preg_match_all('/#('.$this->regexp.')/', $string, $m);
        return $m[1];
    }

    private function parseIssueData($id, array $data)
    {
        
        $issue = new Entity\Issue;
        $issue->setId($id);

        foreach ($data['field'] as $fieldData) {
            switch ($fieldData['name']) {
                case 'summary':
                    $issue->setSummary($fieldData['value']);
                    break;
                case 'projectShortName':
                    $issue->setProject($fieldData['value']);
                    break;
                case 'State':
                    $issue->setStatus($fieldData['value'][0]);
                    break;
                case 'links':
                    foreach ($fieldData['value'] as $link) {
                        if ($link['role'] == 'subtask of') {
                            if( array_key_exists( $link['value'], $this->issueCache ) ) {
                                // already loaded, set connection
                                $issue->setParent($this->getIssue($link['value']));
                            }
                            else {
                                // this issue will also need to be loaded later (and the child will set the connection to the parent when loaded)
                                $this->toLoad[ $link['value'] ] = true;
                            }
                        }
                        if($link['role'] == 'parent for' ) {
                            if( array_key_exists( $link['value'], $this->issueCache ) ) {
                                $issue->addChild( $this->getIssue( $link['value']));
                            }
                            else {
                                // this issue will need to be loaded later (and the parent will set the connection to the child when loaded)
                                $this->toLoad[ $link['value'] ] = true;
                            }
                        }
                    }
                    break;
                case 'Estimation':
                    $issue->setEstimate( $fieldData['value'][0] );
                    break;
            }
        }
        return $issue;
    }

    public function getIssue($id)
    {
        if( !isset( $this->issueCache[ $id ] )) {
            if ($id[0] == '#') {
                throw new \InvalidArgumentException('Supply the issue ID without the #');
            }

            $response = $this->browser->get($this->getOption('uri').'/rest/issue/'.$id, $this->buildHeaders());
            if ($response->isNotFound()) {
                $this->issueCache[ $id ] = null;
                return null;
            }
            if (!$response->isOk()) {
                throw new Exception\APIException(__METHOD__, $response);
            }

            $issueData = json_decode($response->getContent(), true);
            
            // get any todo pushed to the list so that children/parents are set properly for this issue
            $this->getTodo();
            
            $this->issueCache[ $id ] = $this->parseIssueData($id, $issueData);
        }
        
        return $this->issueCache[ $id ];
    }

    private function getIssuesFromResponse(Response $response)
    {
        $issues = array();
        $content = json_decode($response->getContent(), true);
        foreach ($content['issue'] as $issueData) {
            $this->issueCache[$issueData['id']] = $this->parseIssueData($issueData['id'], $issueData);
            $issues[] = $this->issueCache[$issueData['id']];
        }
        return $issues;
    }

    public function getIssues(array $ids)
    {
        if (!count($ids)) {
            return array();
        }
        $search = implode("%20", array_map(function($id) {
            return "%23$id";
        }, $ids));
        $response = $this->browser->get($this->getOption('uri').'/rest/issue?filter='.$search, $this->buildHeaders());

        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }

        $issues = $this->getIssuesFromResponse($response);
        // get any todo pushed to the list, so that children/parents are set properly for this issue
        $this->getTodo();

        return $issues;
    }

    public function searchIssues($filter, $with = array(), $max = 10, $after = '')
    {
        $args = array_filter(array('filter' => $filter,'with' => $with,'max' => $max,'after' => $after));
        $response = $this->browser->get($this->getOption('uri').'/rest/issue?'. http_build_query($args), $this->buildHeaders());

        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }

        $issues = $this->getIssuesFromResponse($response);
        // get any todo pushed to the list, so that children/parents are set properly for this issue
        $this->getTodo();
        return $issues;
    }

    private function getTodo() {
        // if we have a todo, load those issues as well
        if( count( $this->toLoad ) > 0 ) {
            $load = $this->toLoad;
            $this->toLoad = array();
            $issues = $this->getIssues( array_keys( $load ) );
            return array_merge( $issues, $this->getTodo() );
        }
        return array();
    }
    
    public function executeCommands(Entity\Issue $issue, array $commands, $comment, $group = '', $silent = false, $runAs = null)
    {
        $post = array();
        $post[] = 'command='.urlencode(implode(" ", $commands));
        $post[] = 'comment='.urlencode($comment);
        $post[] = 'disableNotifications='.($silent ? 'true' : 'false');
        if (null !== $group) {
            $post[] = 'group='.urlencode($group);
        }
        if (null !== $runAs) {
            $post[] = 'runAs='.$runAs;
        }

        $response = $this->browser->post($this->getOption('uri').'/rest/issue/'.$issue->getId().'/execute', $this->buildHeaders(), implode("&", $post));

        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }
    }

    public function findUserName($email)
    {
        $response = $this->browser->get($this->getOption('uri').'/rest/admin/user?q='.$email, $this->buildHeaders());
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

    public function releaseVersion($project, $version)
    {
        $bundleName = $this->getFixVersionBundleName($project);
        $versionsData = $this->getVersionData($bundleName);

        $foundVersions = array();
        foreach($versionsData['version'] as $versionData) {
            $foundVersions[] = $versionData['value'];
            if ($versionData['value'] == $version) {
                $response = $this->browser->post($this->getOption('uri').'/rest/admin/customfield/versionBundle/'.$bundleName.'/'.$version, $this->buildHeaders(), http_build_query(array(
                    'releaseDate' => time().'000',
                    'released' => "true"
                )));
                if (!$response->isOk()) {
                    throw new Exception\APIException(__METHOD__, ' (tag version)', $response);
                }
                return true;
            }
        }

        throw new \InvalidArgumentException('The tagged version does not exist in YouTrack (found: '.implode(", ", $foundVersions).')');
    }

    public function unreleaseVersion($project, $version)
    {
        $bundleName = $this->getFixVersionBundleName($project);
        $versionsData = $this->getVersionData($bundleName);

        $foundVersions = array();
        foreach($versionsData['version'] as $versionData) {
            $foundVersions[] = $versionData['value'];
            if ($versionData['value'] == $version) {
                $response = $this->browser->post($this->options['uri'].'/rest/admin/customfield/versionBundle/'.$bundleName.'/'.$version, $this->buildHeaders(), http_build_query(array(
                    'released' => "false"
                )));
                if (!$response->isOk()) {
                    throw new Exception\APIException(__METHOD__.' (tag version)', $response);
                }
                return true;
            }
        }

        throw new \InvalidArgumentException('The untagged version does not exist in YouTrack (found: '.implode(", ", $foundVersions).')');
    }
    
    private function getFixVersionBundleName($project) {
        
        $response = $this->browser->get($this->options['uri'].'/rest/admin/project/'.$project.'/customfield/Fix%20versions', $this->buildHeaders());
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }
        $fieldData = json_decode($response->getContent(), true);
        return $fieldData['param'][0]['value'];
    }
    
    private function getVersionData($bundleName)
    {
        

        $response = $this->browser->get($this->options['uri'].'/rest/admin/customfield/versionBundle/'.$bundleName, $this->buildHeaders());
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__.' (get version field data)', $response);
        }
        
        return json_decode($response->getContent(), true);
    }
}
