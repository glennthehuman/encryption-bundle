<?php

namespace Jagilpe\EncryptionBundle\Crypt\FieldMapping;

use Jagilpe\EncryptionBundle\Service\EncryptionService;

/**
 * Base implementation for the Field Mapping
 *
 * @author Javier Gil Pereda <javier.gil@module-7.com>
 */
abstract class AbstractEncryptedFieldMapping implements EncryptedFieldMappingInterface
{
    /**
     * @var \Jagilpe\EncryptionBundle\Service\EncryptionService
     */
    protected $encryptionService;

    /**
     * @var array
     */
    private $fieldMapping;

    public function __construct(EncryptionService $encryptionService, array $fieldMapping)
    {
        $this->encryptionService = $encryptionService;
        $this->fieldMapping = $fieldMapping;
    }

    protected function getFieldMapping()
    {
        $fieldMapping = $this->fieldMapping;
        $fieldMapping['_old_type'] = $fieldMapping['type'];
        return $fieldMapping;
    }
}