<?php
namespace YouTrack\Entity;

/**
 * Class WorkItem
 * YouTrack labour registration.
 * Used to register work on an issue for a specific type.
 *
 * @see https://confluence.jetbrains.com/display/YTD6/YouTrack+REST+API+Reference
 * @see https://confluence.jetbrains.com/display/YTD6/Get+Available+Work+Items+of+Issue
 * @package YouTrack\Entity
 */
class WorkItem
{

    private $id;
    private $comment;
    private $date;
    private $duration = 0;
    private $type = array();
    private $authorName;
    private $authorUrl;
    private $workItemUrl;

    /**
     * @return mixed
     */
    public function getAuthorName()
    {
        return $this->authorName;
    }

    /**
     * @param mixed $authorName
     * @return WorkItem
     */
    public function setAuthorName($authorName)
    {
        $this->authorName = $authorName;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getAuthorUrl()
    {
        return $this->authorUrl;
    }

    /**
     * @param mixed $authorUrl
     * @return WorkItem
     */
    public function setAuthorUrl($authorUrl)
    {
        $this->authorUrl = $authorUrl;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getWorkItemUrl()
    {
        return $this->workItemUrl;
    }

    /**
     * @param mixed $workItemUrl
     * @return WorkItem
     */
    public function setWorkItemUrl($workItemUrl)
    {
        $this->workItemUrl = $workItemUrl;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     * @return WorkItem
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getComment()
    {
        return $this->comment;
    }

    /**
     * @param mixed $comment
     * @return WorkItem
     */
    public function setComment($comment)
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * @return array
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param array $type
     * @return WorkItem
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return int
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * @param int $duration
     * @return WorkItem
     */
    public function setDuration($duration)
    {
        $this->duration = $duration;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * @param mixed $date
     * @return WorkItem
     */
    public function setDate($date)
    {
        $this->date = $date;
        return $this;
    }

}