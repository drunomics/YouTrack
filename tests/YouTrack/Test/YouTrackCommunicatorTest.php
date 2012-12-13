<?php

namespace Samson\YouTrack\Test;

/**
 * @author Bart van den Burg <bart@samson-it.nl>
 */
class YouTrackCommunicatorTest extends \PHPUnit_Framework_TestCase
{

    public function testLogin()
    {
        $browser = $this->getMockBrowser();
        $comm = $this->getCommunicator($browser);

        $response = $this->getMock('Buzz\Message\Response');
        $response->expects($this->once())->method('isOk')->will($this->returnValue(true));

        $browser->expects($this->once())->method('post')->with(
             'http://nowhere/rest/user/login',
             array('Content-Type' => 'application/x-www-form-urlencoded'),
             array('login' => 'someone', 'password' => 'password')
        )->will($this->returnValue($response));

        $comm->login();
    }

    /**
     * @expectedException YouTrack\Exception\APIException
     */
    public function testFailedLoginThrowsException()
    {
        $browser = $this->getMockBrowser();
        $comm = $this->getCommunicator($browser);

        $response = $this->getMock('Buzz\Message\Response');
        $response->expects($this->once())->method('isOk')->will($this->returnValue(false));

        $browser->expects($this->once())->method('post')->with(
             'http://nowhere/rest/user/login',
             array('Content-Type' => 'application/x-www-form-urlencoded'),
             array('login' => 'someone', 'password' => 'password')
        )->will($this->returnValue($response));

        $comm->login();
    }

    private function getMockBrowser()
    {
        return $this->getMockBuilder('Buzz\Browser')->disableOriginalConstructor()->getMock();
    }

    private function getCommunicator(\Buzz\Browser $browser)
    {
        return new \YouTrack\YouTrackCommunicator($browser, array(
            'uri' => 'http://nowhere',
            'username' => 'someone',
            'password' => 'password'
        ));
    }
}
