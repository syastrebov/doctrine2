<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Repository;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataBuildingContext;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Reflection\ReflectionService;
use Doctrine\ORM\Repository\DefaultRepositoryFactory;
use Doctrine\Tests\DoctrineTestCase;
use Doctrine\Tests\Models\DDC753\DDC753DefaultRepository;
use Doctrine\Tests\Models\DDC869\DDC869PaymentRepository;

/**
 * Tests for {@see \Doctrine\ORM\Repository\DefaultRepositoryFactory}
 *
 * @covers \Doctrine\ORM\Repository\DefaultRepositoryFactory
 */
class DefaultRepositoryFactoryTest extends DoctrineTestCase
{
    /**
     * @var \Doctrine\ORM\EntityManagerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $entityManager;

    /**
     * @var \Doctrine\ORM\Configuration|\PHPUnit_Framework_MockObject_MockObject
     */
    private $configuration;

    /**
     * @var DefaultRepositoryFactory
     */
    private $repositoryFactory;

    /**
     * @var ClassMetadataBuildingContext|\PHPUnit_Framework_MockObject_MockObject
     */
    private $metadataBuildingContext;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $this->metadataBuildingContext = new ClassMetadataBuildingContext(
            $this->createMock(ClassMetadataFactory::class),
            $this->createMock(ReflectionService::class)
        );
        $this->configuration           = $this->createMock(Configuration::class);
        $this->entityManager           = $this->createEntityManager();
        $this->repositoryFactory       = new DefaultRepositoryFactory();

        $this->configuration
            ->expects($this->any())
            ->method('getDefaultRepositoryClassName')
            ->will($this->returnValue(DDC869PaymentRepository::class));
    }

    public function testCreatesRepositoryFromDefaultRepositoryClass()
    {
        $this->entityManager
            ->expects($this->any())
            ->method('getClassMetadata')
            ->will($this->returnCallback([$this, 'buildClassMetadata']));

        self::assertInstanceOf(
            DDC869PaymentRepository::class,
            $this->repositoryFactory->getRepository($this->entityManager, __CLASS__)
        );
    }

    public function testCreatedRepositoriesAreCached()
    {
        $this->entityManager
            ->expects($this->any())
            ->method('getClassMetadata')
            ->will($this->returnCallback([$this, 'buildClassMetadata']));

        self::assertSame(
            $this->repositoryFactory->getRepository($this->entityManager, __CLASS__),
            $this->repositoryFactory->getRepository($this->entityManager, __CLASS__)
        );
    }

    public function testCreatesRepositoryFromCustomClassMetadata()
    {
        $customMetadata = $this->buildClassMetadata(__DIR__);

        $customMetadata->setCustomRepositoryClassName(DDC753DefaultRepository::class);

        $this->entityManager
            ->expects($this->any())
            ->method('getClassMetadata')
            ->will($this->returnValue($customMetadata))
        ;

        self::assertInstanceOf(
            DDC753DefaultRepository::class,
            $this->repositoryFactory->getRepository($this->entityManager, __CLASS__)
        );
    }

    public function testCachesDistinctRepositoriesPerDistinctEntityManager()
    {
        $em1 = $this->createEntityManager();
        $em2 = $this->createEntityManager();

        $em1->expects($this->any())
            ->method('getClassMetadata')
            ->will($this->returnCallback([$this, 'buildClassMetadata']));

        $em2->expects($this->any())
            ->method('getClassMetadata')
            ->will($this->returnCallback([$this, 'buildClassMetadata']));

        $repo1 = $this->repositoryFactory->getRepository($em1, __CLASS__);
        $repo2 = $this->repositoryFactory->getRepository($em2, __CLASS__);

        self::assertSame($repo1, $this->repositoryFactory->getRepository($em1, __CLASS__));
        self::assertSame($repo2, $this->repositoryFactory->getRepository($em2, __CLASS__));

        self::assertNotSame($repo1, $repo2);
    }

    /**
     * @private
     *
     * @param string $className
     *
     * @return ClassMetadata
     */
    public function buildClassMetadata($className)
    {
        $metadata = new ClassMetadata($className, null, $this->metadataBuildingContext);

        $metadata->setCustomRepositoryClassName(null);

        return $metadata;
    }

    /**
     * @return \Doctrine\ORM\EntityManagerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private function createEntityManager()
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $entityManager->expects($this->any())
            ->method('getConfiguration')
            ->will($this->returnValue($this->configuration));

        return $entityManager;
    }
}
