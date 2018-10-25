<?php

namespace Jagilpe\EncryptionBundle\Security;

/**
 * Default implementation of the AccessCheckerInterface
 *
 * @author Javier Gil Pereda <javier.gil@module-7.com>
 */
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
        return [$entity->getOwnerUser()];//TODO verify
    }

    /**
     * {@inheritdoc}
     */
    public function canUseOwnerPrivateKey($entity, $user = null)
    {
        return true;//TODO implement proper checking
    }
}
