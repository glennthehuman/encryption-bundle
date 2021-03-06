<?php

namespace Jagilpe\EncryptionBundle\Security;

/**
 * Implementation of the AccessCheckerInterface that chains the response of a list of othe AccessCheckers
 *
 * @author Javier Gil Pereda <javier.gil@module-7.com>
 */
class ChainedAccessChecker implements AccessCheckerInterface
{
    /**
     * The Encryption Bundle Settings
     *
     * @var array
     */
    private $settings;

    /**
     * The Access Checkers configured to check the permission to access the decryption
     *
     * @var array<AccessCheckerInterface>
     */
    private $accessCheckers;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedUsers($entity)
    {
        $users = array();
        foreach ($this->accessCheckers as $accessChecker) {
            $users = array_merge($users, $accessChecker->getAllowedUsers($entity));
        }
        return $users;
    }

    /**
     * {@inheritdoc}
     */
    public function canUseOwnerPrivateKey($entity, $user = null)
    {
        $canAccess = false;
        foreach ($this->accessCheckers as $accessChecker) {
            if ($accessChecker->canUseOwnerPrivateKey($entity, $user)) {
                $canAccess = true;
                break;
            }
        }
        return $canAccess;
    }

    /**
     * Adds an Access Checker to the chain
     *
     * @param AccessCheckerInterface $accessChecker
     */
    public function addAccessChecker(AccessCheckerInterface $accessChecker)
    {
        $this->accessCheckers[] = $accessChecker;
    }

    private function getUser($entity)
    {
        return $entity->getUser();
    }
}