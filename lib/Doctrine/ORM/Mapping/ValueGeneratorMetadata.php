<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Sequencing;

/**
 * Class ValueGeneratorMetadata
 */
class ValueGeneratorMetadata
{
    /** @var Property */
    protected $declaringProperty;

    /** @var string */
    protected $type;

    /** @var mixed[] */
    protected $definition;

    /**
     * @param mixed[] $definition
     */
    public function __construct(string $type, array $definition = [])
    {
        $this->type       = $type;
        $this->definition = $definition;
    }

    /**
     * @return Property
     */
    public function getDeclaringProperty() : Property
    {
        return $this->declaringProperty;
    }

    /**
     * @param Property $declaringProperty
     */
    public function setDeclaringProperty(Property $declaringProperty) : void
    {
        $this->declaringProperty = $declaringProperty;
    }

    public function getType() : string
    {
        return $this->type;
    }

    /**
     * @return mixed[]
     */
    public function getDefinition() : array
    {
        return $this->definition;
    }

    /**
     * @param AbstractPlatform $platform
     *
     * @return Sequencing\Generator
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getSequencingGenerator(AbstractPlatform $platform) : Sequencing\Generator
    {
        $class = $this->declaringProperty->getDeclaringClass();

        switch ($this->type) {
            case GeneratorType::IDENTITY:
                return $this->declaringProperty->getTypeName() === 'bigint'
                    ? new Sequencing\BigIntegerIdentityGenerator()
                    : new Sequencing\IdentityGenerator();

            case GeneratorType::SEQUENCE:
                return new Sequencing\SequenceGenerator(
                    $platform->quoteIdentifier($this->definition['sequenceName']),
                    $this->definition['allocationSize']
                );

            case GeneratorType::UUID:
                return new Sequencing\UuidGenerator();
                break;

            case GeneratorType::CUSTOM:
                $class = $this->definition['class'];

                return new $class();
        }
    }
}
