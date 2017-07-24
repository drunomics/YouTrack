<?php

namespace YouTrack\Entity;

/**
 * YouTrack Issue Entity.
 * Wrapper for the interesting information of a project.
 *
 * @author Bart van den Burg <bart@samson-it.nl>
 */
class Issue {

    private $id;
    private $projectEntity;
    private $status;
    private $summary;
    private $description;
    private $parent;
    private $estimate;
    private $children = array();
    private $timetrackEntries = array();
    private $relatedTicket;
    private $tags = array();
    private $sprint = array();
    private $clientSprint = array();
    private $assignee = array();
    private $developer = array();
    private $storyPoints;
    private $priority;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * Project detail data (name, config, etc)
     * @return mixed
     */
    public function getProjectEntity() {
        return $this->projectEntity;
    }

    /**
     * Set the project detail data.
     * @param Project $project
     */
    public function setProjectEntity(Project $project) {
        $this->projectEntity = $project;
    }

    /**
     * return Project Name.
     * Method not renamed for historical reasons.
     * @return mixed
     */
    public function getProject()
    {
        return $this->projectEntity->getName();
    }

    public function setProject($project)
    {
        $this->projectEntity->setName($project);
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getSummary()
    {
        return $this->summary;
    }

    public function setSummary($summary)
    {
        $this->summary = $summary;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function getTags() {
      return $this->tags;
    }

    public function setTags($tags) {
      $this->tags = $tags;
    }

    public function hasTag($key) {
      foreach ($this->tags as $tag) {
        if ($tag['value'] == $key) {
          return TRUE;
        }
      }

      return FALSE;
    }

    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Add a parent relationship to another issue for this issue.
     * @param Issue $parent
     * @param bool $addChild
     */
    public function setParent(Issue $parent, $addChild = true )
    {
        $this->parent = $parent;
        if( $addChild ) {
            $parent->addChild( $this, false );
        }
    }

    /**
     * Store estimate property
     * @param $estimate
     */
    public function setEstimate( $estimate ) {
        $this->estimate = $estimate;
    }

    public function getEstimate() {
        return $this->estimate;
    }

    /**
     * Add a child relationship to another issue for this issue.
     * @param Issue $child
     * @param bool $addParent
     */
    public function addChild( Issue $child, $addParent = true ) {
        $this->children[] = $child;
        if( $addParent ) {
            $child->setParent( $this, false );
        }
    }
    
    public function getChildren() {
        return $this->children;
    }

    /**
     * @return array
     */
    public function getTimetrackEntries()
    {
        return $this->timetrackEntries;
    }

    /**
     * @param array $timetrackEntries
     */
    public function setTimetrackEntries($timetrackEntries)
    {
        $this->timetrackEntries = $timetrackEntries;
    }

    /**
     * @return string|NULL
     */
    public function getRelatedTicket() {
        return $this->relatedTicket;
    }

    /**
     * @param string $relatedTicket
     */
    public function setRelatedTicket($relatedTicket) {
        $this->relatedTicket = $relatedTicket;
    }

  /**
   * @return array
   */
  public function getSprint() {
    return $this->sprint;
  }

  /**
   * @param array $sprint
   */
  public function setSprint($sprint) {
    $this->sprint = $sprint;
  }

  /**
   * @return array
   */
  public function getClientSprint() {
    return $this->clientSprint;
  }

  /**
   * @param array $clientSprint
   */
  public function setClientSprint($clientSprint) {
    $this->clientSprint = $clientSprint;
  }

  /**
   * @return array
   */
  public function getAssignee() {
    return $this->assignee;
  }

  /**
   * @param array $assignee
   */
  public function setAssignee($assignee) {
    $this->assignee = $assignee;
  }

  /**
   * @return array
   */
  public function getDeveloper() {
    return $this->developer;
  }

  /**
   * @param array $developer
   */
  public function setDeveloper($developer) {
    $this->developer = $developer;
  }

  /**
   * @return mixed
   */
  public function getStoryPoints() {
    return $this->storyPoints;
  }

  /**
   * @param mixed $storyPoints
   */
  public function setStoryPoints($storyPoints) {
    $this->storyPoints = $storyPoints;
  }

  /**
   * @return mixed
   */
  public function getPriority() {
    return $this->priority;
  }

  /**
   * @param mixed $priority
   */
  public function setPriority($priority) {
    $this->priority = $priority;
  }

}