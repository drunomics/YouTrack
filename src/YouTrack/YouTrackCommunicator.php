<?php

namespace YouTrack;

use Buzz\Browser;
use Buzz\Client\Curl;

/**
 * @author Bart van den Burg <bart@samson-it.nl>
 */
class YouTrackCommunicator
{
    private $browser;

    private $options;

    private $cookie = null;

    private $regexp = '[a-zA-Z_]+-\d+';

    public function __construct(Browser $browser, array $options)
    {
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
            }
        }
        return $issue;
    }

    public function getIssue($id)
    {
        if ($id[0] == '#') {
            throw new \InvalidArgumentException('Supply the issue ID without the #');
        }

        $response = $this->browser->get($this->getOption('uri').'/rest/issue/'.$id, $this->buildHeaders());
        if ($response->isNotFound()) {
            return null;
        }
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }

        $issueData = json_decode($response->getContent(), true);
        $issue = $this->parseIssueData($id, $issueData);

        return $issue;
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

        $issues = array();
        $content = json_decode($response->getContent(), true);
        foreach ($content['issue'] as $issueData) {
            $issues[] = $this->parseIssueData($issueData['id'], $issueData);
        }

        return $issues;
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
        $response = $this->browser->get($this->getOption('uri').'/rest/admin/project/'.$project.'/customfield/Fix%20versions', $this->buildHeaders());
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }
        $fieldData = json_decode($response->getContent(), true);
        $bundleName = $fieldData['param'][0]['value'];

        $response = $this->browser->get($this->getOption('uri').'/rest/admin/customfield/versionBundle/'.$bundleName, $this->buildHeaders());
        if (!$response->isOk()) {
            throw new Exception\APIException(__METHOD__, $response);
        }

        $versionsData = json_decode($response->getContent(), true);
        $foundVersions = array();
        foreach($versionsData['version'] as $versionData) {
            $foundVersions[] = $versionData['value'];
            if ($versionData['value'] == $version) {
                $response = $this->browser->post($this->getOption('uri').'/rest/admin/customfield/versionBundle/'.$bundleName.'/'.$version, $this->buildHeaders(), http_build_query(array(
                    'releaseDate' => time().'000',
                    'released' => "true"
                )));
                if (!$response->isOk()) {
                    throw new Exception\APIException(__METHOD__, $response);
                }
                return true;
            }
        }

        throw new \InvalidArgumentException('The tagged version does not exist in YouTrack (found: '.implode(", ", $foundVersions).')');
    }
}
