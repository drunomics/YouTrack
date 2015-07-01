<?php

namespace YouTrack\Entity;

/**
 * @author Jelle Ursem <jelle@samson-it.nl>
 */
class Project
{
    private $name;
    private $id;


    private $settings = array();

    public function __construct($name) {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return $id | null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param $id | null
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * Fetch all settings for this project.
     * @return array
     */
    public function getSettings() {
        return $this->settings;
    }

    /**
     * swap out the whole settings config for this project.
     * @param array $settings
     */
    public function setSettings($settings = array())
    {
        $this->settings = $settings;
    }

    /**
     * Change an individual setting for this project.
     * @param $setting
     * @param $value
     */
    public function setSetting($setting, $value)
    {
        $this->settings[$setting] = $value;
    }

    /**
     * Fetch a setting for this project.
     * @param $setting
     * @return setting | null
     */
    public function getSetting($setting)
    {
        if(array_key_exists($setting, $this->settings)) {
            return $this->settings[$setting];
        }
        return null;
    }



}