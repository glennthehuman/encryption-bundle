<?php

namespace Jagilpe\EncryptionBundle\Crypt;

use Doctrine\Common\Util\ClassUtils;
use Jagilpe\EncryptionBundle\Metadata\ClassMetadata;
use Jagilpe\EncryptionBundle\Metadata\ClassMetadataFactory;
use Jagilpe\EncryptionBundle\Service\EncryptionService;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Jagilpe\EncryptionBundle\Entity\PKEncryptionEnabledUserInterface;
use Jagilpe\EncryptionBundle\Exception\EncryptionException;
use Jagilpe\EncryptionBundle\Security\AccessCheckerInterface;

/**
 * Default implementation of the KeyManagerInterface
 *
 * @author Javier Gil Pereda <javier.gil@module-7.com>
 */
class KeyManager implements KeyManagerInterface
{
    const SESSION_PRIVATE_KEY_PARAM = 'pki_private_key';

    /**
     * @var \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
     */
    private $session;

    /**
     * @var \Jagilpe\EncryptionBundle\Crypt\CryptographyProviderInterface
     */
    private $cryptographyProvider;

    /**
     * @var \Jagilpe\EncryptionBundle\Crypt\KeyStoreInterface
     */
    private $keyStore;

    /**
     * @var \Jagilpe\EncryptionBundle\Security\AccessCheckerInterface
     */
    private $accessChecker;

    /**
     * @var array
     */
    private $settings;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var ClassMetadataFactory
     */
    private $metadataFactory;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        SessionInterface $session,
        CryptographyProviderInterface $cryptographyProvider,
        KeyStoreInterface $keyStore,
        EventDispatcherInterface $dispatcher,
        AccessCheckerInterface $accessChecker,
        ClassMetadataFactory $classMetadataFactory,
        $settings
    )
    {
        $this->tokenStorage = $tokenStorage;
        $this->session = $session;
        $this->cryptographyProvider = $cryptographyProvider;
        $this->keyStore = $keyStore;
        $this->dispatcher = $dispatcher;
        $this->accessChecker = $accessChecker;
        $this->settings = $settings;
        $this->metadataFactory = $classMetadataFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function generateUserPKIKeys(PKEncryptionEnabledUserInterface $user)
    {
        list($publicKey, $privateKey) = $this->generatePKIKeys();

        $user->setPublicKey($publicKey);
        $user->setPrivateKey($privateKey);

        $password = $user->getPlainPassword();

        $this->encryptPrivateKey($user);

        if ($password) {
            // Store the password digest for encrypting the private key after the user is persisted
            $passwordDigest = $this->cryptographyProvider->getPasswordDigest($password, $user->getSalt());
            $user->setPasswordDigest(base64_encode($passwordDigest));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function storeUserPKIKeys(PKEncryptionEnabledUserInterface $user)
    {
        $passwordDigest = $user->getPasswordDigest();
        $params = array('password_digest' => $passwordDigest);
        $privateKey = $this->getUserPrivateKey($user, $params);

        $this->keyStore->addKeys($user, $privateKey);
    }

    /**
     * {@inheritdoc}
     *
     */
    public function handleUserPasswordChange(PKEncryptionEnabledUserInterface $user, $currentPassword)
    {
        $params = array('password' => $currentPassword);

        // Decrypt the private key of the user using the current password
        $privateKey = $this->getUserPrivateKey($user, $params);
        $user->setPrivateKey($privateKey);
        $user->setPrivateKeyIv(null);
        $user->setPrivateKeyEncrypted(false);

        $this->encryptPrivateKey($user);

        $this->keyStore->addKeys($user, $privateKey);
    }

    /**
     * {@inheritdoc}
     *
     */
    public function handleUserPasswordReset(PKEncryptionEnabledUserInterface $user)
    {

        // Check if the private key was already encrypted
        if ($user->isPrivateKeyEncrypted()) {
            // We don't have the user's password so we have to get the key from the key store
            $privateKey = $this->keyStore->getPrivateKey($user);
            $keyInStore = true;
        }
        else {
            // Get the private key from the user and save it in the key store
            $privateKey = $user->getPrivateKey();
            $keyInStore = false;
        }

        $user->setPrivateKey($privateKey);
        $user->setPrivateKeyIv(null);
        $user->setPrivateKeyEncrypted(false);

        $this->encryptPrivateKey($user);

        if (!$keyInStore) {
            $this->keyStore->addKeys($user, $privateKey);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getEntityEncryptionKeyData($entity)
    {
        $key = $this->getEntityEncryptionKey($entity);
        $iv = $this->getEntityEncryptionIv($entity);

        $keyData = $key ? new KeyData($key, $iv) : null;

        return $keyData;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserPublicKey(PKEncryptionEnabledUserInterface $user, array $params = array())
    {
        return $user->getPublicKey();
    }

    /**
     * {@inheritdoc}
     */
    public function getUserPrivateKey(PKEncryptionEnabledUserInterface $user, array $params = array())
    {
        if ($user->isPrivateKeyEncrypted()) {
            $privateKey = $this->decryptPrivateKey($user, $params);
        }
        else {
            $privateKey = $user->getPrivateKey();
        }

        return $privateKey;
    }

    /**
     * Returns the encryption key used to encrypt/decrpyt an entity
     *
     * @param mixed $entity
     *
     * @return string
     */
    private function getEntityEncryptionKey($entity)
    {
        $encryptedKey = $entity->getKey();

        if ($encryptedKey) {
            $key = $this->decryptSymmetricKey($entity);
        }
        else {
            $key = $this->generateSymmetricKey();

            // Insert the encrypted key in the entity
            $entity->setKey($this->encryptSymmetricKey($key, $entity));
        }

        return $key;
    }

    /**
     * Returns the initialization vector key used to encrypt/decrpyt an entity
     *
     * @param mixed $entity
     *
     * @return string
     */
    private function getEntityEncryptionIv($entity)
    {
        $iv = $entity->getIv();

        if (!$iv) {
            $iv = $this->cryptographyProvider->generateIV(CryptographyProviderInterface::PROPERTY_ENCRYPTION);
            $entity->setIv($iv);
        }

        return $iv;
    }

    /**
     * Returns the public key of the user
     *
     * @param mixed $user
     *
     * @return string
     */
    private function getPublicKey(PKEncryptionEnabledUserInterface $user = null)
    {
        return $user->getPublicKey();
    }

    /**
     * Returns the private key of the user logged in user
     *
     * @return string
     */
    private function getPrivateKey()
    {
        return $this->session->get('pki_private_key');
    }

    /**
     * Generates a symmetric key for the encryption of an Entity
     *
     * @return string
     */
    private function generateSymmetricKey()
    {
        return $this->cryptographyProvider->generateSecureKey();
    }

    /**
     * @param string $clearKey
     * @param mixed $entity
     * @return SymmetricKey
     * @throws EncryptionException
     */
    private function encryptSymmetricKey($clearKey, $entity)
    {
        $encryptionMode = $this->getEncryptionMode($entity);
        switch ($encryptionMode) {
            case EncryptionService::MODE_PER_USER_SHAREABLE:
                $symmetricKey = $this->encryptSymmetricKeyWithUserKey($clearKey, $entity);
                break;
            case EncryptionService::MODE_SYSTEM_ENCRYPTION:
                $symmetricKey = $this->encryptSymmetricKeyWithSystemKey($clearKey, $entity);
                break;
            default:
                throw new EncryptionException(sprintf('Encryption mode %s not supported.', $encryptionMode));
        }

        return $symmetricKey;
    }

    /**
     * Encrypts the Symmetric Key of the entity using Per User Encryption
     *
     * @param string $clearKey
     * @param mixed $entity
     *
     * @return SymmetricKey
     */
    private function encryptSymmetricKeyWithUserKey($clearKey, $entity)
    {
        $users = $this->accessChecker->getAllowedUsers($entity);
        $symmetricKey = new SymmetricKey();

        foreach ($users as $user) {
            $publicKey = $this->getPublicKey($user);
            $encryptedKey = base64_encode($this->cryptographyProvider->encryptWithPublicKey($clearKey, $publicKey));
            $symmetricKey->addKey($user, $encryptedKey);
        }

        return $symmetricKey;
    }

    /**
     * Encrypts the Symmetric Key of the entity using System Encryption
     *
     * @param string $clearKey
     * @param mixed $entity
     *
     * @return SymmetricKey
     */
    private function encryptSymmetricKeyWithSystemKey($clearKey, $entity)
    {
        $publicMasterKey = $this->keyStore->getPublicMasterKey();
        $symmetricKey = new SymmetricKey();
        $encryptedKey = base64_encode($this->cryptographyProvider->encryptWithPublicKey($clearKey, $publicMasterKey));
        $symmetricKey->addSystemKey($encryptedKey);

        return $symmetricKey;
    }

    /**
     * Decrypts the Symmetric Key used to encrypt the fields of the entity
     *
     * @param mixed $entity
     *
     * @throws EncryptionException
     *
     * @return string
     */
    private function decryptSymmetricKey($entity)
    {
        $decryptedKey = null;

        $encryptionMode = $this->getEncryptionMode($entity);
        switch ($encryptionMode) {
            case EncryptionService::MODE_PER_USER_SHAREABLE:
                $decryptedKey = $this->decryptSymmetricKeyWithUserKey($entity);
                break;
            case EncryptionService::MODE_SYSTEM_ENCRYPTION:
                $decryptedKey = $this->decryptSymmetricKeyWithSystemKey($entity);
                break;
            default:
                throw new EncryptionException(sprintf('Encryption mode %s not supported.', $encryptionMode));
        }

        return $decryptedKey;
    }

    /**
     * Decrypts the Symmetric Key of the entity using Per User Encryption
     *
     * @param $entity
     *
     * @return string
     */
    private function decryptSymmetricKeyWithUserKey($entity)
    {
        $encryptedKey = $entity->getKey();
        $decryptedKey = null;
        $user = $this->getUser();

        if ($user instanceof PKEncryptionEnabledUserInterface && $userKey = $encryptedKey->getKey($user)) {
            $userKey = base64_decode($userKey);
            $privateKey = $this->getPrivateKey();
        }
        elseif ($this->accessChecker->canUseOwnerPrivateKey($entity, $user)) {
            // Check if the logged in user can decrpyt the data without private key
            $vivaUser = $entity->getOwnerUser();//TODO verify
            $userKey = base64_decode($encryptedKey->getKey($vivaUser));
            $privateKey = $this->keyStore->getPrivateKey($vivaUser);
        }
        else {
            return null;
        }

        $decryptedKey = $this->cryptographyProvider->decryptWithPrivateKey($userKey, $privateKey);

        return $decryptedKey;
    }

    /**
     * Decrypts the Symmetric Key of the entity using System Encryption
     *
     * @param $entity
     *
     * @return string
     */
    private function decryptSymmetricKeyWithSystemKey($entity)
    {
        /** @var SymmetricKey $encryptedKey */
        $encryptedKey = $entity->getKey();
        $decryptedKey = null;

        $systemKey = $encryptedKey->getSystemKey();
        $privateKey = $this->keyStore->getPrivateMasterKey();

        if ($systemKey) {
            $systemKey = base64_decode($systemKey);
            $decryptedKey = $this->cryptographyProvider->decryptWithPrivateKey($systemKey, $privateKey);
        }

        return $decryptedKey;
    }

    private function getUser()
    {
        $token = $this->tokenStorage->getToken();
        $user = $token ? $token->getUser() : null;

        return $user;
    }

    /**
     * Generates a pki keys pair
     *
     * @return array
     *
     * @throws EncryptionException
     */
    private function generatePKIKeys()
    {
        // OPENSSL config
        $config = array(
            'digest_alg' => $this->settings['private_key']['digest_method'],
            'private_key_bits' => $this->settings['private_key']['bits'],
            'private_key_type' => $this->settings['private_key']['type'],
        );

        $privateKey = null;
        $resource = openssl_pkey_new($config);

        openssl_pkey_export($resource, $privateKey);

        if(!$privateKey) {
            throw new EncryptionException('Private key could not be generated');
        }

        $publicKeyDetails = openssl_pkey_get_details($resource);
        $publicKey = $publicKeyDetails['key'];

        return array($publicKey, $privateKey);
    }

    /**
     * Encrypts the Private key of the user using his password
     *
     * @param \Jagilpe\EncryptionBundle\Entity\PKEncryptionEnabledUserInterface $user
     *
     * @return array
     */
    private function encryptPrivateKey(PKEncryptionEnabledUserInterface $user)
    {
        if (!$user->isPrivateKeyEncrypted()) {
            $privateKey = $user->getPrivateKey();
            $passwordDigest = $this->getUserPasswordDigest($user);

            if ($passwordDigest) {
                $iv = $this->cryptographyProvider->generateIV(CryptographyProviderInterface::PRIVATE_KEY_ENCRYPTION);

                $keyData = new KeyData($passwordDigest, $iv);
                try {
                    $encryptedPrivateKey = $this->cryptographyProvider->encrypt(
                                    $privateKey,
                                    $keyData,
                                    CryptographyProviderInterface::PRIVATE_KEY_ENCRYPTION);

                    $encrypted = true;
                }
                catch (\Exception $ex) {
                    $encryptedPrivateKey = $privateKey;
                    $iv = null;
                    $encrypted = false;
                }
            }
            else {
                $encryptedPrivateKey = $privateKey;
                $iv = null;
                $encrypted = false;
            }

            $user->setPrivateKey($encryptedPrivateKey);
            if ($encrypted) {
                $user->setPrivateKeyIv($iv);
                $user->setPrivateKeyEncrypted(true);
            }
        }
    }

    /**
     * Returns the password digest of the password of the user
     *
     * @param \Jagilpe\EncryptionBundle\Entity\PKEncryptionEnabledUserInterface $user
     *
     * @return string
     */
    private function getUserPasswordDigest(PKEncryptionEnabledUserInterface $user)
    {
        $passwordDigest = $user->getPasswordDigest();

        if (!$passwordDigest) {
            $password = $user->getPlainPassword();

            if ($password) {
                $salt = $user->getSalt();
                $passwordDigest = $this->cryptographyProvider->getPasswordDigest($password, $salt);
            }
        }

        return $passwordDigest;
    }

    /**
     * Encrypts the Private key of the user using his password or a digest of it
     *
     * @param \Jagilpe\EncryptionBundle\Entity\PKEncryptionEnabledUserInterface $user
     * @param array $params
     *
     * @return string|boolean
     *
     * @throws EncryptionException
     */
    private function decryptPrivateKey(PKEncryptionEnabledUserInterface $user, array $params = array())
    {
        if (isset($params['password_digest'])) {
            $passwordDigest = base64_decode($params['password_digest']);
        }
        else {
            if (isset($params['password'])) {
                $salt = $user->getSalt();
                $passwordDigest = $this->cryptographyProvider->getPasswordDigest($params['password'], $salt);
            }
            else {
                throw new EncryptionException('Could not retrieve the user\'s key');
            }
        }

        $iv = $user->getPrivateKeyIv();
        $keyData = new KeyData($passwordDigest, $iv);
        $encryptedPrivateKey = $user->getPrivateKey();

        $privateKey = $this->cryptographyProvider->decrypt(
                        $encryptedPrivateKey,
                        $keyData,
                        CryptographyProviderInterface::PRIVATE_KEY_ENCRYPTION);

        return $privateKey;
    }

    /**
     * Returns the encryption mode for the given entity
     *
     * @param $entity
     *
     * @return ClassMetadata
     */
    private function getEncryptionMode($entity)
    {
        $className = ClassUtils::getClass($entity);
        $classMetadata = $this->metadataFactory->getMetadataFor($className);

        return $classMetadata->encryptionMode;
    }
}
