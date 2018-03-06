<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Factory;
use Doctrine\ORM\Reflection\ReflectionService;

/**
 * Class ClassMetadataBuildingContext
 */
class ClassMetadataBuildingContext
{
    /** @var AbstractClassMetadataFactory */
    private $classMetadataFactory;

    /** @var ReflectionService */
    private $reflectionService;

    /** @var NamingStrategy */
    private $namingStrategy;

    /** @var SecondPass[] */
    protected $secondPassList = [];

    /** @var bool */
    private $inSecondPass = false;

    public function __construct(
        AbstractClassMetadataFactory $classMetadataFactory,
        ReflectionService $reflectionService,
        ?NamingStrategy $namingStrategy = null
    ) {
        $this->classMetadataFactory = $classMetadataFactory;
        $this->reflectionService    = $reflectionService;
        $this->namingStrategy       = $namingStrategy ?: new DefaultNamingStrategy();
    }

    public function getClassMetadataFactory() : AbstractClassMetadataFactory
    {
        return $this->classMetadataFactory;
    }

    public function getReflectionService() : ReflectionService
    {
        return $this->reflectionService;
    }

    public function getNamingStrategy() : NamingStrategy
    {
        return $this->namingStrategy;
    }

    public function addSecondPass(SecondPass $secondPass) : void
    {
        $this->secondPassList[] = $secondPass;
    }

    public function isInSecondPass() : bool
    {
        return $this->inSecondPass;
    }

    public function validate() : void
    {
        $this->inSecondPass = true;

        foreach ($this->secondPassList as $secondPass) {
            /** @var SecondPass $secondPass */
            $secondPass->process($this);
        }

        $this->inSecondPass = false;
    }
}
