<?php

namespace YouTrack\Entity;

/**
 * @author Bart van den Burg <bart@samson-it.nl>
 */
class Issue {

    private $id;

    private $project;

    private $projectId;

    private $status;

    private $summary;

    private $description;
    
    private $parent;
    
    private $estimate;
    
    private $children = array();

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getProjectId()
    {
        return $this->projectId;
    }

    public function setProjectId($projectId)
    {
        $this->projectId = $projectId;
    }

    public function getProject()
    {
        return $this->project;
    }

    public function setProject($project)
    {
        $this->project = $project;
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

    public function getParent()
    {
        return $this->parent;
    }

    public function setParent(Issue $parent, $addChild = true )
    {
        $this->parent = $parent;
        if( $addChild ) {
            $parent->addChild( $this, false );
        }
    }
    
    public function setEstimate( $estimate ) {
        $this->estimate = $estimate;
    }
    public function getEstimate() {
        return $this->estimate;
    }
    
    public function addChild( Issue $child, $addParent = true ) {
        $this->children[] = $child;
        if( $addParent ) {
            $child->setParent( $this, false );
        }
    }
    
    public function getChildren() {
        return $this->children;
    }
    
}