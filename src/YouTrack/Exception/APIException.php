<?php

namespace YouTrack\Exception;

use Buzz\Message\Response;

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
        
        parent::__construct('The server responded with a '.$response->getStatusCode().' status code in method '.$method);
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