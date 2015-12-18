<?php

namespace YouTrack\Exception;

use Guzzle\Http\Message\Response;

/**
 * @author Bart van den Burg <bart@samson-it.nl>
 */
class APIException extends \RuntimeException
{
    private $method;
    
    private $response;
    
    public function __construct($method, Response $response)
    {
        $this->method = $method;
        $this->response = $response;
        
        parent::__construct('The server responded with a '.$response->getStatusCode().' status code in method '.$method.': '.$response->getBody(true));
    }
    
    public function getMethod()
    {
        return $this->method;
    }

    public function getResponse()
    {
        return $this->response;
    }
}