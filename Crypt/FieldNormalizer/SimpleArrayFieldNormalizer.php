<?php

namespace Jagilpe\EncryptionBundle\Crypt\FieldNormalizer;

use Jagilpe\EncryptionBundle\Exception\EncryptionException;

/**
 * Implementation of the EncryptedFieldNormalizerInterface for simple array fields
 *
 * @author Javier Gil Pereda <javier.gil@module-7.com>
 */
class SimpleArrayFieldNormalizer implements EncryptedFieldNormalizerInterface
{
    /**
     *
     * {@inheritdoc}
     *
     * @see \Jagilpe\EncryptionBundle\Crypt\FieldNormalizer\EncryptedFieldNormalizerInterface::normalize()
     */
    public function normalize($clearValue)
    {
        $normalizedValue = null;

        if ($clearValue !== null) {
            if (is_string($clearValue)) {
                $normalizedValue = explode(',', $clearValue);
            }
            elseif (is_array($clearValue)) {
                $normalizedValue = $clearValue;
            }
            else {
                throw new EncryptionException('Value type not supported');
            }
        }

        return $normalizedValue;
    }
}