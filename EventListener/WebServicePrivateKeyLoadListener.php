<?php

namespace Module7\EncryptionBundle\EventListener;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use AppWebServiceBundle\Security\Authentication\Token\WsseUserToken;
use Module7\EncryptionBundle\Crypt\KeyManagerInterface;
use Module7\EncryptionBundle\Entity\PKEncryptionEnabledUserInterface;

/**
 * Event listener to load the private key of user in the Web Service
 *
 * @author Javier Gil Pereda <javier.gil@module-7.com>
 *
 */
class WebServicePrivateKeyLoadListener
{
    const PASSWORD_DIGEST_HEADER = 'pv-pd';

    /**
     * @var array
     */
    private $settings;

    /**
     * @var \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var \Module7\EncryptionBundle\Crypt\KeyManagerInterface
     */
    private $keyManager;

    public function __construct(
                    array $settings,
                    TokenStorageInterface $tokenStorage,
                    KeyManagerInterface $keyManager)
    {
        $this->settings = $settings;
        $this->tokenStorage = $tokenStorage;
        $this->keyManager = $keyManager;
    }

    /**
     * @param Symfony\Component\HttpKernel\Event\FilterControllerEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        // Check if we are authenticating the user using WSSE
        $token = $this->tokenStorage->getToken();

        if ($token instanceof WsseUserToken) {
            $request = $event->getRequest();
            $user = $this->getUser();
            if ($user && $user instanceof PKEncryptionEnabledUserInterface) {
                $params = array(
                    'password_digest' => $request->headers->get(self::PASSWORD_DIGEST_HEADER),
                );
                $privateKey = $this->keyManager->getUserPrivateKey($user, $params);
                $request->getSession()->set('pki_private_key', $privateKey);
            }
        }
    }

    /**
     * Returns the logged in user
     *
     * @return \Module7\EncryptionBundle\Entity\PKEncryptionEnabledUserInterface
     */
    private function getUser()
    {
        $token = $this->tokenStorage->getToken();
        $user = $token ? $token->getUser() : null;

        return $user;
    }
}