<?php

namespace Module7\EncryptionBundle\Crypt\FieldNormalizer;

use Module7\EncryptionBundle\Exception\EncryptionException;

/**
 * Implementation of the EncryptedFieldNormalizerInterface for simple array fields
 *
 * @author Javier Gil Pereda <javier.gil@module-7.com>
 */
class SerializableObjectFieldNormalizer implements EncryptedFieldNormalizerInterface
{
    /**
     *
     * {@inheritdoc}
     *
     * @see \Module7\EncryptionBundle\Crypt\FieldNormalizer\EncryptedFieldNormalizerInterface::normalize()
     */
    public function normalize($clearValue)
    {
        $normalizedValue = null;

        if ($clearValue !== null) {
            if (is_string($clearValue)) {
                $normalizedValue = unserialize($clearValue);
            }
            else {
                $normalizedValue = $clearValue;
            }
        }

        return $normalizedValue;
    }
}