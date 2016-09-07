<?php

namespace Module7\EncryptionBundle\Crypt\FieldEncrypter;

use Module7\EncryptionBundle\Crypt\KeyDataInterface;

/**
 * Implementation of the FieldEncrypterInterface for serializable objects
 *
 * @author Javier Gil Pereda <javier.gil@module-7.com>
 */
class SerializableObjectFieldEncrypter extends DefaultFieldEncrypter
{
    /**
     * {@inheritdoc}
     */
    public function encrypt($clearValue, KeyDataInterface $keyData)
    {
        $serializedValue = $clearValue !== null ? serialize($clearValue) : null;
        return parent::encrypt($serializedValue, $keyData);
    }

    /**
     * {@inheritdoc}
     */
    public function decrypt($encryptedValue, KeyDataInterface $keyData)
    {
        $serializedValue = parent::decrypt($encryptedValue, $keyData);
        return $serializedValue !== null ? unserialize($serializedValue) : null;
    }
}