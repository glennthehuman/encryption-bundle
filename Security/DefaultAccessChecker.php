<?php

namespace EHEncryptionBundle\Security;

class DefaultAccessChecker implements AccessCheckerInterface
{
    private $settings;

    public function __construct($settings)
    {
        $this->settings = $settings;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedUsers($entity)
    {
        return array($this->getUser($entity));
    }

    private function getUser($entity)
    {
        return $entity->getUser();
    }
}