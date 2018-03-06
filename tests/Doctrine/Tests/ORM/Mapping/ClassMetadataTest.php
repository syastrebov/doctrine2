<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DiscriminatorColumnMetadata;
use Doctrine\ORM\Mapping\Factory\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Factory\UnderscoreNamingStrategy;
use Doctrine\ORM\Mapping\JoinColumnMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Reflection\RuntimeReflectionService;
use Doctrine\ORM\Reflection\StaticReflectionService;
use Doctrine\Tests\Models\CMS;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyContractListener;
use Doctrine\Tests\Models\CustomType\CustomTypeParent;
use Doctrine\Tests\Models\DDC117\DDC117Article;
use Doctrine\Tests\Models\DDC117\DDC117ArticleDetails;
use Doctrine\Tests\Models\DDC6412\DDC6412File;
use Doctrine\Tests\Models\DDC964\DDC964Address;
use Doctrine\Tests\Models\DDC964\DDC964Admin;
use Doctrine\Tests\Models\DDC964\DDC964Guest;
use Doctrine\Tests\Models\Routing\RoutingLeg;
use Doctrine\Tests\OrmTestCase;
use DoctrineGlobal_Article;

require_once __DIR__ . '/../../Models/Global/GlobalNamespaceModel.php';

class ClassMetadataTest extends OrmTestCase
{
    /**
     * @var Mapping\ClassMetadataBuildingContext|\PHPUnit_Framework_MockObject_MockObject
     */
    private $metadataBuildingContext;

    public function setUp()
    {
        parent::setUp();

        $this->metadataBuildingContext = new Mapping\ClassMetadataBuildingContext(
            $this->createMock(Mapping\ClassMetadataFactory::class),
            new RuntimeReflectionService()
        );
    }

    public function testClassMetadataInstanceSimpleState()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        self::assertInstanceOf(\ReflectionClass::class, $cm->getReflectionClass());
        self::assertEquals(CMS\CmsUser::class, $cm->getClassName());
        self::assertEquals(CMS\CmsUser::class, $cm->getRootClassName());
        self::assertEquals([], $cm->getSubClasses());
        self::assertCount(0, $cm->getAncestorsIterator());
        self::assertEquals(Mapping\InheritanceType::NONE, $cm->inheritanceType);
    }

    public function testClassMetadataInstanceSerialization()
    {
        $parent = new ClassMetadata(CMS\CmsEmployee::class, null, $this->metadataBuildingContext);
        $parent->setTable(new Mapping\TableMetadata('cms_employee'));

        $cm     = new ClassMetadata(CMS\CmsUser::class, $parent, $this->metadataBuildingContext);
        $cm->setTable($parent->table);

        $discrColumn = new DiscriminatorColumnMetadata();

        $discrColumn->setColumnName('disc');
        $discrColumn->setType(Type::getType('integer'));

        $cm->setInheritanceType(Mapping\InheritanceType::SINGLE_TABLE);
        $cm->setSubclasses([
            'Doctrine\Tests\Models\CMS\One',
            'Doctrine\Tests\Models\CMS\Two',
            'Doctrine\Tests\Models\CMS\Three'
        ]);
        $cm->setCustomRepositoryClassName('Doctrine\Tests\Models\CMS\UserRepository');
        $cm->setDiscriminatorColumn($discrColumn);
        $cm->asReadOnly();

        $association = new Mapping\OneToOneAssociationMetadata('phonenumbers');

        $association->setTargetEntity(CMS\CmsAddress::class);
        $association->setMappedBy('foo');

        $cm->addProperty($association);

        self::assertCount(1, $cm->getDeclaredPropertiesIterator());

        $serialized = serialize($cm);
        $cm = unserialize($serialized);

        $cm->wakeupReflection(new RuntimeReflectionService());

        // Check state
        self::assertInstanceOf(\ReflectionClass::class, $cm->getReflectionClass());
        self::assertEquals(CMS\CmsUser::class, $cm->getClassName());
        self::assertEquals(CMS\CmsEmployee::class, $cm->getRootClassName());
        self::assertEquals('Doctrine\Tests\Models\CMS\UserRepository', $cm->getCustomRepositoryClassName());
        self::assertEquals(
            [
                'Doctrine\Tests\Models\CMS\One',
                'Doctrine\Tests\Models\CMS\Two',
                'Doctrine\Tests\Models\CMS\Three'
            ],
            $cm->getSubClasses()
        );
        self::assertCount(1, $cm->getAncestorsIterator());
        self::assertEquals(CMS\CmsEmployee::class, $cm->getAncestorsIterator()->current()->getClassName());
        self::assertEquals($discrColumn, $cm->discriminatorColumn);
        self::assertTrue($cm->isReadOnly());
        self::assertCount(1, $cm->getDeclaredPropertiesIterator());
        self::assertInstanceOf(Mapping\OneToOneAssociationMetadata::class, $cm->getProperty('phonenumbers'));

        $oneOneMapping = $cm->getProperty('phonenumbers');

        self::assertEquals(Mapping\FetchMode::LAZY, $oneOneMapping->getFetchMode());
        self::assertEquals(CMS\CmsAddress::class, $oneOneMapping->getTargetEntity());
    }

    public function testFieldIsNullable()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $metadata->setTable(new Mapping\TableMetadata('cms_users'));

        // Explicit Nullable
        $fieldMetadata = new Mapping\FieldMetadata('status');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setLength(50);
        $fieldMetadata->setNullable(true);

        $metadata->addProperty($fieldMetadata);

        $property = $metadata->getProperty('status');

        self::assertTrue($property->isNullable());

        // Explicit Not Nullable
        $fieldMetadata = new Mapping\FieldMetadata('username');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setLength(50);
        $fieldMetadata->setNullable(false);

        $metadata->addProperty($fieldMetadata);

        $property = $metadata->getProperty('username');

        self::assertFalse($property->isNullable());

        // Implicit Not Nullable
        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setLength(50);

        $metadata->addProperty($fieldMetadata);

        $property = $metadata->getProperty('name');

        self::assertFalse($property->isNullable(), "By default a field should not be nullable.");
    }

    /**
     * @group DDC-115
     */
    public function testMapAssociationInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata(DoctrineGlobal_Article::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('doctrine_global_article'));

        $joinTable = new Mapping\JoinTableMetadata();
        $joinTable->setName('bar');

        $joinColumn = new Mapping\JoinColumnMetadata();
        $joinColumn->setColumnName("bar_id");
        $joinColumn->setReferencedColumnName("id");

        $joinTable->addJoinColumn($joinColumn);

        $joinColumn = new Mapping\JoinColumnMetadata();
        $joinColumn->setColumnName("baz_id");
        $joinColumn->setReferencedColumnName("id");

        $joinTable->addInverseJoinColumn($joinColumn);

        $association = new Mapping\ManyToManyAssociationMetadata('author');

        $association->setJoinTable($joinTable);
        $association->setTargetEntity('DoctrineGlobal_User');

        $cm->addProperty($association);

        self::assertEquals("DoctrineGlobal_User", $cm->getProperty('author')->getTargetEntity());
    }

    public function testMapManyToManyJoinTableDefaults()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $association = new Mapping\ManyToManyAssociationMetadata('groups');

        $association->setTargetEntity(CMS\CmsGroup::class);

        $cm->addProperty($association);

        $association = $cm->getProperty('groups');

        $joinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName("cmsuser_id");
        $joinColumn->setReferencedColumnName("id");
        $joinColumn->setOnDelete("CASCADE");

        $joinColumns[] = $joinColumn;

        $inverseJoinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName("cmsgroup_id");
        $joinColumn->setReferencedColumnName("id");
        $joinColumn->setOnDelete("CASCADE");

        $inverseJoinColumns[] = $joinColumn;

        $joinTable = $association->getJoinTable();

        self::assertEquals('cmsuser_cmsgroup', $joinTable->getName());
        self::assertEquals($joinColumns, $joinTable->getJoinColumns());
        self::assertEquals($inverseJoinColumns, $joinTable->getInverseJoinColumns());
    }

    public function testSerializeManyToManyJoinTableCascade()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $association = new Mapping\ManyToManyAssociationMetadata('groups');

        $association->setTargetEntity(CMS\CmsGroup::class);

        $cm->addProperty($association);

        $association = $cm->getProperty('groups');
        $association = unserialize(serialize($association));

        $joinTable = $association->getJoinTable();

        foreach ($joinTable->getJoinColumns() as $joinColumn) {
            self::assertEquals('CASCADE', $joinColumn->getOnDelete());
        }
    }

    /**
     * @group DDC-115
     */
    public function testSetDiscriminatorMapInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata('DoctrineGlobal_User', null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('doctrine_global_user'));

        $cm->setDiscriminatorMap(['descr' => 'DoctrineGlobal_Article', 'foo' => 'DoctrineGlobal_User']);

        self::assertEquals("DoctrineGlobal_Article", $cm->discriminatorMap['descr']);
        self::assertEquals("DoctrineGlobal_User", $cm->discriminatorMap['foo']);
    }

    /**
     * @group DDC-115
     */
    public function testSetSubClassesInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata('DoctrineGlobal_User', null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('doctrine_global_user'));

        $cm->setSubclasses(['DoctrineGlobal_Article']);

        self::assertEquals("DoctrineGlobal_Article", $cm->getSubClasses()[0]);
    }

    /**
     * @group DDC-268
     */
    public function testSetInvalidVersionMapping_ThrowsException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $property = new Mapping\VersionFieldMetadata('foo');

        $property->setDeclaringClass($cm);
        $property->setColumnName('foo');
        $property->setType(Type::getType('string'));

        $this->expectException(MappingException::class);

        $cm->addProperty($property);
    }

    public function testGetSingleIdentifierFieldName_MultipleIdentifierEntity_ThrowsException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $fieldMetadata = new Mapping\FieldMetadata('name');
        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('username');
        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);

        $cm->setIdentifier(['name', 'username']);

        $this->expectException(MappingException::class);

        $cm->getSingleIdentifierFieldName();
    }

    public function testGetSingleIdentifierFieldName_NoIdEntity_ThrowsException()
    {
        $cm = new ClassMetadata(DDC6412File::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('ddc6412_file'));

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $cm->getSingleIdentifierFieldName();
    }

    public function testDuplicateAssociationMappingException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $association = new Mapping\OneToOneAssociationMetadata('foo');

        $association->setDeclaringClass($cm);
        $association->setSourceEntity(\stdClass::class);
        $association->setTargetEntity(\stdClass::class);
        $association->setMappedBy('foo');

        $cm->addInheritedProperty($association);

        $this->expectException(MappingException::class);

        $association = new Mapping\OneToOneAssociationMetadata('foo');

        $association->setDeclaringClass($cm);
        $association->setSourceEntity(\stdClass::class);
        $association->setTargetEntity(\stdClass::class);
        $association->setMappedBy('foo');

        $cm->addInheritedProperty($association);
    }

    public function testDuplicateColumnName_ThrowsMappingException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);

        $this->expectException(MappingException::class);

        $fieldMetadata = new Mapping\FieldMetadata('username');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setColumnName('name');

        $cm->addProperty($fieldMetadata);
    }

    public function testDuplicateColumnName_DiscriminatorColumn_ThrowsMappingException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);

        $discrColumn = new DiscriminatorColumnMetadata();

        $discrColumn->setColumnName('name');
        $discrColumn->setType(Type::getType('string'));
        $discrColumn->setLength(255);

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $cm->setDiscriminatorColumn($discrColumn);
    }

    public function testDuplicateColumnName_DiscriminatorColumn2_ThrowsMappingException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $discrColumn = new DiscriminatorColumnMetadata();

        $discrColumn->setColumnName('name');
        $discrColumn->setType(Type::getType('string'));
        $discrColumn->setLength(255);

        $cm->setDiscriminatorColumn($discrColumn);

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);
    }

    public function testDuplicateFieldAndAssociationMapping1_ThrowsException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $association = new Mapping\OneToOneAssociationMetadata('name');

        $association->setTargetEntity(CMS\CmsUser::class);

        $cm->addProperty($association);
    }

    public function testDuplicateFieldAndAssociationMapping2_ThrowsException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null,  $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $association = new Mapping\OneToOneAssociationMetadata('name');

        $association->setTargetEntity(CMS\CmsUser::class);

        $cm->addProperty($association);

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);
    }

    /**
     * @group DDC-1224
     */
    public function testGetTemporaryTableNameSchema()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $tableMetadata = new Mapping\TableMetadata();

        $tableMetadata->setSchema('foo');
        $tableMetadata->setName('bar');

        $cm->setTable($tableMetadata);

        self::assertEquals('foo_bar_id_tmp', $cm->getTemporaryIdTableName());
    }

    public function testDefaultTableName()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('CmsUser'));

        // When table's name is not given
        self::assertEquals('CmsUser', $cm->getTableName());
        self::assertEquals('CmsUser', $cm->table->getName());

        $cm = new ClassMetadata(CMS\CmsAddress::class, null, $this->metadataBuildingContext);

        // When joinTable's name is not given
        $joinTable = new Mapping\JoinTableMetadata();

        $joinColumn = new Mapping\JoinColumnMetadata();
        $joinColumn->setReferencedColumnName("id");

        $joinTable->addJoinColumn($joinColumn);

        $joinColumn = new Mapping\JoinColumnMetadata();
        $joinColumn->setReferencedColumnName("id");

        $joinTable->addInverseJoinColumn($joinColumn);

        $association = new Mapping\ManyToManyAssociationMetadata('user');

        $association->setJoinTable($joinTable);
        $association->setTargetEntity(CMS\CmsUser::class);
        $association->setInversedBy('users');

        $cm->addProperty($association);

        $association = $cm->getProperty('user');

        self::assertEquals('cmsaddress_cmsuser', $association->getJoinTable()->getName());
    }

    public function testDefaultJoinColumnName()
    {
        $cm = new ClassMetadata(CMS\CmsAddress::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_address'));

        // this is really dirty, but it's the simplest way to test whether
        // joinColumn's name will be automatically set to user_id
        $joinColumns = [];

        $joinColumn = new JoinColumnMetadata();

        $joinColumn->setReferencedColumnName('id');

        $joinColumns[] = $joinColumn;

        $association = new Mapping\OneToOneAssociationMetadata('user');

        $association->setJoinColumns($joinColumns);
        $association->setTargetEntity(CMS\CmsUser::class);

        $cm->addProperty($association);

        $association = $cm->getProperty('user');
        $joinColumns = $association->getJoinColumns();
        $joinColumn  = reset($joinColumns);

        self::assertEquals('user_id', $joinColumn->getColumnName());

        $cm = new ClassMetadata(CMS\CmsAddress::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_address'));

        $joinTable = new Mapping\JoinTableMetadata();
        $joinTable->setName('user_CmsUser');

        $joinColumn = new JoinColumnMetadata();
        $joinColumn->setReferencedColumnName('id');

        $joinTable->addJoinColumn($joinColumn);

        $joinColumn = new JoinColumnMetadata();
        $joinColumn->setReferencedColumnName('id');

        $joinTable->addInverseJoinColumn($joinColumn);

        $association = new Mapping\ManyToManyAssociationMetadata('user');

        $association->setJoinTable($joinTable);
        $association->setTargetEntity(CMS\CmsUser::class);
        $association->setInversedBy('users');

        $cm->addProperty($association);

        $association        = $cm->getProperty('user');
        $joinTable          = $association->getJoinTable();
        $joinColumns        = $joinTable->getJoinColumns();
        $joinColumn         = reset($joinColumns);
        $inverseJoinColumns = $joinTable->getInverseJoinColumns();
        $inverseJoinColumn  = reset($inverseJoinColumns);

        self::assertEquals('cmsaddress_id', $joinColumn->getColumnName());
        self::assertEquals('cmsuser_id', $inverseJoinColumn->getColumnName());
    }

    /**
     * @group DDC-559
     */
    public function testOneToOneUnderscoreNamingStrategyDefaults()
    {
        $namingStrategy = new UnderscoreNamingStrategy(CASE_UPPER);

        $this->metadataBuildingContext = new Mapping\ClassMetadataBuildingContext(
            $this->createMock(Mapping\ClassMetadataFactory::class),
            new RuntimeReflectionService(),
            $namingStrategy
        );

        $metadata = new ClassMetadata(CMS\CmsAddress::class, null, $this->metadataBuildingContext);
        $metadata->setTable(new Mapping\TableMetadata('cms_address'));

        $association = new Mapping\OneToOneAssociationMetadata('user');

        $association->setTargetEntity(CMS\CmsUser::class);

        $metadata->addProperty($association);

        $association = $metadata->getProperty('user');
        $joinColumns = $association->getJoinColumns();
        $joinColumn  = reset($joinColumns);

        self::assertEquals('USER_ID', $joinColumn->getColumnName());
        self::assertEquals('ID', $joinColumn->getReferencedColumnName());
    }

    /**
     * @group DDC-559
     */
    public function testManyToManyUnderscoreNamingStrategyDefaults()
    {
        $namingStrategy = new UnderscoreNamingStrategy(CASE_UPPER);

        $this->metadataBuildingContext = new Mapping\ClassMetadataBuildingContext(
            $this->createMock(Mapping\ClassMetadataFactory::class),
            new RuntimeReflectionService(),
            $namingStrategy
        );

        $metadata = new ClassMetadata(CMS\CmsAddress::class, null, $this->metadataBuildingContext);
        $metadata->setTable(new Mapping\TableMetadata('cms_address'));

        $association = new Mapping\ManyToManyAssociationMetadata('user');

        $association->setTargetEntity(CMS\CmsUser::class);

        $metadata->addProperty($association);

        $association        = $metadata->getProperty('user');
        $joinTable          = $association->getJoinTable();
        $joinColumns        = $joinTable->getJoinColumns();
        $joinColumn         = reset($joinColumns);
        $inverseJoinColumns = $joinTable->getInverseJoinColumns();
        $inverseJoinColumn  = reset($inverseJoinColumns);

        self::assertEquals('CMS_ADDRESS_CMS_USER', $joinTable->getName());

        self::assertEquals('CMS_ADDRESS_ID', $joinColumn->getColumnName());
        self::assertEquals('ID', $joinColumn->getReferencedColumnName());

        self::assertEquals('CMS_USER_ID', $inverseJoinColumn->getColumnName());
        self::assertEquals('ID', $inverseJoinColumn->getReferencedColumnName());

        $cm = new ClassMetadata('DoctrineGlobal_Article', null, $this->metadataBuildingContext);

        $association = new Mapping\ManyToManyAssociationMetadata('author');

        $association->setTargetEntity(CMS\CmsUser::class);

        $cm->addProperty($association);

        $association = $cm->getProperty('author');

        self::assertEquals('DOCTRINE_GLOBAL_ARTICLE_CMS_USER', $association->getJoinTable()->getName());
    }

    /**
     * @group DDC-886
     */
    public function testSetMultipleIdentifierSetsComposite()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $fieldMetadata = new Mapping\FieldMetadata('name');
        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('username');
        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);

        $cm->setIdentifier(['name', 'username']);

        self::assertTrue($cm->isIdentifierComposite());
    }

    /**
     * @group DDC-961
     */
    public function testJoinTableMappingDefaults()
    {
        $cm = new ClassMetadata('DoctrineGlobal_Article', null, $this->metadataBuildingContext);

        $association = new Mapping\ManyToManyAssociationMetadata('author');

        $association->setTargetEntity(CMS\CmsUser::class);

        $cm->addProperty($association);

        $association = $cm->getProperty('author');

        self::assertEquals('doctrineglobal_article_cmsuser', $association->getJoinTable()->getName());
    }

    /**
     * @group DDC-117
     */
    public function testMapIdentifierAssociation()
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata("ddc117_article_details"));

        $association = new Mapping\OneToOneAssociationMetadata('article');

        $association->setTargetEntity(DDC117Article::class);
        $association->setPrimaryKey(true);

        $cm->addProperty($association);

        self::assertEquals(["article"], $cm->identifier);
    }

    /**
     * @group DDC-117
     */
    public function testOrphanRemovalIdentifierAssociation()
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata("ddc117_article_details"));

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('The orphan removal option is not allowed on an association that');

        $association = new Mapping\OneToOneAssociationMetadata('article');

        $association->setTargetEntity(DDC117Article::class);
        $association->setPrimaryKey(true);
        $association->setOrphanRemoval(true);

        $cm->addProperty($association);
    }

    /**
     * @group DDC-117
     */
    public function testInverseIdentifierAssociation()
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata("ddc117_article_details"));

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('An inverse association is not allowed to be identifier in');

        $association = new Mapping\OneToOneAssociationMetadata('article');

        $association->setTargetEntity(DDC117Article::class);
        $association->setPrimaryKey(true);
        $association->setMappedBy('details');
        $association->setOwningSide(false);

        $cm->addProperty($association);
    }

    /**
     * @group DDC-117
     */
    public function testIdentifierAssociationManyToMany()
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata("ddc117_article_details"));

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Many-to-many or one-to-many associations are not allowed to be identifier in');

        $association = new Mapping\ManyToManyAssociationMetadata('article');

        $association->setTargetEntity(DDC117Article::class);
        $association->setPrimaryKey(true);

        $cm->addProperty($association);
    }

    /**
     * @group DDC-996
     */
    public function testEmptyFieldNameThrowsException()
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("The field or association mapping misses the 'fieldName' attribute in entity '" . CMS\CmsUser::class . "'.");

        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $fieldMetadata = new Mapping\FieldMetadata('');

        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);
    }

    /**
     * @group DDC-2451
     */
    public function testSerializeEntityListeners()
    {
        $metadata = new ClassMetadata(CompanyContract::class, null, $this->metadataBuildingContext);

        $metadata->addEntityListener(Events::prePersist, CompanyContractListener::class, 'prePersistHandler');
        $metadata->addEntityListener(Events::postPersist, CompanyContractListener::class, 'postPersistHandler');

        $serialize = serialize($metadata);
        $unserialize = unserialize($serialize);

        self::assertEquals($metadata->entityListeners, $unserialize->entityListeners);
    }

    /**
     * @group DDC-1068
     */
    public function testClassCaseSensitivity()
    {
        $cm = new ClassMetadata(strtoupper(CMS\CmsUser::class), null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        self::assertEquals(CMS\CmsUser::class, $cm->getClassName());
    }

    /**
     * @group DDC-659
     */
    public function testLifecycleCallbackNotFound()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $cm->addLifecycleCallback('notfound', 'postLoad');

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("Entity '" . CMS\CmsUser::class . "' has no method 'notfound' to be registered as lifecycle callback.");

        $cm->validateLifecycleCallbacks(new RuntimeReflectionService());
    }

    /**
     * @group ImproveErrorMessages
     */
    public function testTargetEntityNotFound()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $association = new Mapping\ManyToOneAssociationMetadata('address');

        $association->setTargetEntity('UnknownClass');

        $cm->addProperty($association);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("The target-entity 'UnknownClass' cannot be found in '" . CMS\CmsUser::class . "#address'.");

        $cm->validateAssociations();
    }

    /**
     * @group DDC-1746
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage You have specified invalid cascade options for Doctrine\Tests\Models\CMS\CmsUser::$address: 'invalid'; available options: 'remove', 'persist', and 'refresh'
     */
    public function testInvalidCascade()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $association = new Mapping\ManyToOneAssociationMetadata('address');

        $association->setTargetEntity('UnknownClass');
        $association->setCascade(['invalid']);

        $cm->addProperty($association);
    }

    /**
     * @group DDC-964
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Invalid field override named 'invalidPropertyName' for class 'Doctrine\Tests\Models\DDC964\DDC964Admin'
     */
    public function testInvalidPropertyAssociationOverrideNameException()
    {
        $cm = new ClassMetadata(DDC964Admin::class, null,  $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata("ddc964_admin"));

        $association = new Mapping\ManyToOneAssociationMetadata('address');

        $association->setTargetEntity(DDC964Address::class);

        $cm->addProperty($association);

        $cm->setPropertyOverride(new Mapping\ManyToOneAssociationMetadata('invalidPropertyName'));
    }

    /**
     * @group DDC-964
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Invalid field override named 'invalidPropertyName' for class 'Doctrine\Tests\Models\DDC964\DDC964Guest'.
     */
    public function testInvalidPropertyAttributeOverrideNameException()
    {
        $cm = new ClassMetadata(DDC964Guest::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata("ddc964_guest"));

        $fieldMetadata = new Mapping\FieldMetadata('name');
        $fieldMetadata->setType(Type::getType('string'));

        $cm->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('invalidPropertyName');
        $fieldMetadata->setType(Type::getType('string'));

        $cm->setPropertyOverride($fieldMetadata);
    }

    /**
     * @group DDC-1955
     *
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Entity Listener "\InvalidClassName" declared on "Doctrine\Tests\Models\CMS\CmsUser" not found.
     */
    public function testInvalidEntityListenerClassException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $cm->addEntityListener(Events::postLoad, '\InvalidClassName', 'postLoadHandler');
    }

    /**
     * @group DDC-1955
     *
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Entity Listener "Doctrine\Tests\Models\Company\CompanyContractListener" declared on "Doctrine\Tests\Models\CMS\CmsUser" has no method "invalidMethod".
     */
    public function testInvalidEntityListenerMethodException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $cm->addEntityListener(Events::postLoad, 'Doctrine\Tests\Models\Company\CompanyContractListener', 'invalidMethod');
    }

    public function testManyToManySelfReferencingNamingStrategyDefaults()
    {
        $cm = new ClassMetadata(CustomTypeParent::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata("custom_type_parent"));

        $association = new Mapping\ManyToManyAssociationMetadata('friendsWithMe');

        $association->setTargetEntity(CustomTypeParent::class);

        $cm->addProperty($association);

        $association = $cm->getProperty('friendsWithMe');

        $joinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName("customtypeparent_source");
        $joinColumn->setReferencedColumnName("id");
        $joinColumn->setOnDelete("CASCADE");

        $joinColumns[] = $joinColumn;

        $inverseJoinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName("customtypeparent_target");
        $joinColumn->setReferencedColumnName("id");
        $joinColumn->setOnDelete("CASCADE");

        $inverseJoinColumns[] = $joinColumn;

        $joinTable = $association->getJoinTable();

        self::assertEquals('customtypeparent_customtypeparent', $joinTable->getName());
        self::assertEquals($joinColumns, $joinTable->getJoinColumns());
        self::assertEquals($inverseJoinColumns, $joinTable->getInverseJoinColumns());
    }

    /**
     * @group DDC-2662
     * @group 6682
     */
    public function testQuotedSequenceName() : void
    {
        self::markTestIncomplete(
            '@guilhermeblanco, in #6683 we added allocationSize/initialValue as to the sequence definition but with the'
            . ' changes you have made I am not sure if the "initialValue" should still be verified here or if it should'
            . ' part of the metadata drivers'
        );

        $cm = new ClassMetadata(CMS\CmsUser::class, null, $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata('cms_users'));

        $id = new Mapping\FieldMetadata('id');
        $id->setValueGenerator(new Mapping\ValueGeneratorMetadata(
            Mapping\GeneratorType::SEQUENCE,
            [
                'sequenceName' => 'foo',
                'allocationSize' => 1,
            ]
        ));
        $cm->addProperty($id);

        self::assertEquals(
            ['sequenceName' => 'foo', 'allocationSize' => 1, 'initialValue' => '1'],
            $cm->getProperty('id')->getValueGenerator()->getDefinition()
        );
    }

    /**
     * @group DDC-2700
     */
    public function testIsIdentifierMappedSuperClass()
    {
        $class = new ClassMetadata(DDC2700MappedSuperClass::class, null, $this->metadataBuildingContext);

        self::assertFalse($class->isIdentifier('foo'));
    }

    /**
     * @group embedded
     */
    public function testWakeupReflectionWithEmbeddableAndStaticReflectionService()
    {
        $metadata = new ClassMetadata(TestEntity1::class, null,  $this->metadataBuildingContext);
        $cm->setTable(new Mapping\TableMetadata("test_entity1"));

        $metadata->mapEmbedded(
            [
                'fieldName'    => 'test',
                'class'        => TestEntity1::class,
                'columnPrefix' => false,
            ]
        );

        $fieldMetadata = new Mapping\FieldMetadata('test.embeddedProperty');
        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        /*
        $mapping = [
            'originalClass' => TestEntity1::class,
            'declaredField' => 'test',
            'originalField' => 'embeddedProperty'
        ];

        $metadata->addProperty('test.embeddedProperty', Type::getType('string'), $mapping);
        */

        $metadata->wakeupReflection(new StaticReflectionService());

        self::assertEquals(
            [
                'test'                  => null,
                'test.embeddedProperty' => null
            ],
            $metadata->getReflectionProperties()
        );
    }
}

/**
 * @ORM\MappedSuperclass
 */
class DDC2700MappedSuperClass
{
    /** @ORM\Column */
    private $foo;
}

class MyNamespacedNamingStrategy extends DefaultNamingStrategy
{
    /**
     * {@inheritdoc}
     */
    public function classToTableName(string $className) : string
    {
        if (strpos($className, '\\') !== false) {
            $className = str_replace('\\', '_', str_replace('Doctrine\Tests\Models\\', '', $className));
        }

        return strtolower($className);
    }
}

class MyPrefixNamingStrategy extends DefaultNamingStrategy
{
    /**
     * {@inheritdoc}
     */
    public function propertyToColumnName(string $propertyName, ?string $className = null) : string
    {
        return strtolower($this->classToTableName($className)) . '_' . $propertyName;
    }
}
