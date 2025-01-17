<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\DoctrineORMAdminBundle\Tests\Filter;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineExtensions\Query\Mysql\Binary;
use Sonata\AdminBundle\Form\Type\Operator\StringOperatorType;
use Sonata\DoctrineORMAdminBundle\Datagrid\ProxyQuery;
use Sonata\DoctrineORMAdminBundle\Filter\StringFilter;

class StringFilterTest extends FilterTestCase
{
    public function testEmpty(): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->expects($this->never())
            ->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(Binary::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['field_options' => ['class' => 'FooBar']]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());

        $filter->filter($proxyQuery, 'alias', 'field', null);
        $filter->filter($proxyQuery, 'alias', 'field', '');
        $filter->filter($proxyQuery, 'alias', 'field', []);

        $this->assertSameQuery([], $proxyQuery);
        $this->assertFalse($filter->isActive());
    }

    public function getValuesForDefaultType(): iterable
    {
        yield 'filter by normal value' => [['WHERE alias.field LIKE :field_name_0'], 'asd', false];
        yield 'filter by normal value without "case_sensitive" option' => [
            ['WHERE alias.field LIKE :field_name_0'],
            'asd',
            false,
            [],
            ['binary' => Binary::class],
        ];
        yield 'filter by normal value using `null` at "case_sensitive" option' => [
            ['WHERE alias.field LIKE :field_name_0'],
            'asd',
            false,
            ['case_sensitive' => null],
            ['binary' => Binary::class],
        ];
        yield 'filter by normal value using `false` at "case_sensitive" option' => [
            ['WHERE LOWER(alias.field) LIKE :field_name_0'],
            'asd',
            false,
            ['case_sensitive' => false],
            ['binary' => Binary::class],
        ];
        yield 'filter by normal value using `true` at "case_sensitive" option' => [
            ['WHERE alias.field LIKE BINARY(:field_name_0)'],
            'asd',
            false,
            ['case_sensitive' => true],
            ['binary' => Binary::class],
        ];
        yield 'filter by normal value using `true` at "case_sensitive" option and wrong filter name' => [
            ['WHERE alias.field LIKE :field_name_0'],
            'asd',
            false,
            ['case_sensitive' => true],
            ['binary_extension' => Binary::class],
        ];
        yield 'filter by normal value using `true` at "case_sensitive" option and wrong filter value' => [
            ['WHERE alias.field LIKE :field_name_0'],
            'asd',
            false,
            ['case_sensitive' => true],
            ['binary' => \stdClass::class],
        ];
        yield 'not filter by empty string' => [[], '', false];
        yield 'filter by empty string' => [[], '', true];
        yield 'not filter by null' => [[], null, false];
        yield 'filter by null' => [[], null, true];
        yield 'not filter by 0' => [['WHERE alias.field LIKE :field_name_0'], 0, false];
        yield 'not filter by 0 with BINARY() function' => [
            ['WHERE alias.field LIKE BINARY(:field_name_0)'],
            0,
            false,
            ['case_sensitive' => true],
            ['binary' => Binary::class],
        ];
        yield 'filter by 0' => [['WHERE alias.field LIKE :field_name_0'], 0, true];
        yield 'not filter by \'0\'' => [['WHERE alias.field LIKE :field_name_0'], '0', false];
        yield 'filter by \'0\'' => [['WHERE alias.field LIKE :field_name_0'], '0', true];
    }

    /**
     * @dataProvider getValuesForDefaultType
     */
    public function testDefaultType(array $expected, $value, bool $allowEmpty, array $options = [], array $dqlExtensions = []): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->expects($this->exactly(!($options['case_sensitive'] ?? null) || '' === (string) $value ? 0 : 1))
            ->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn($dqlExtensions['binary'] ?? null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['allow_empty' => $allowEmpty] + $options);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => $value, 'type' => null]);

        $this->assertSameQuery($expected, $proxyQuery);

        if ('' !== (string) $value) {
            $this->assertSameQueryParameters(['field_name_0' => sprintf('%%%s%%', $value)], $proxyQuery);
            $this->assertTrue($filter->isActive());
        } else {
            $this->assertFalse($filter->isActive());
        }
    }

    public function getValues(): iterable
    {
        return [
            'filter by normal value' => ['asd', false],
            'not filter by empty string' => ['', false],
            'filter by empty string' => ['', true],
            'not filter by null' => [null, false],
            'filter by null' => [null, true],
            'not filter by 0' => [0, false],
            'filter by 0' => [0, true],
            'not filter by \'0\'' => ['0', false],
            'filter by \'0\'' => ['0', true],
        ];
    }

    /**
     * @dataProvider getValues
     */
    public function testContains($value, bool $allowEmpty): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['allow_empty' => $allowEmpty]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => $value, 'type' => StringOperatorType::TYPE_CONTAINS]);

        if ('' !== (string) $value) {
            $this->assertSameQuery(['WHERE alias.field LIKE :field_name_0'], $proxyQuery);
            $this->assertSameQueryParameters(['field_name_0' => sprintf('%%%s%%', $value)], $proxyQuery);
            $this->assertTrue($filter->isActive());
        } else {
            $this->assertSameQuery([], $proxyQuery);
            $this->assertFalse($filter->isActive());
        }
    }

    /**
     * @dataProvider getValues
     */
    public function testStartsWith($value, bool $allowEmpty): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['allow_empty' => $allowEmpty]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => $value, 'type' => StringOperatorType::TYPE_STARTS_WITH]);

        if ('' !== (string) $value) {
            $this->assertSameQuery(['WHERE alias.field LIKE :field_name_0'], $proxyQuery);
            $this->assertSameQueryParameters(['field_name_0' => sprintf('%s%%', $value)], $proxyQuery);
            $this->assertTrue($filter->isActive());
        } else {
            $this->assertSameQuery([], $proxyQuery);
            $this->assertFalse($filter->isActive());
        }
    }

    /**
     * @dataProvider getValues
     */
    public function testEndsWith($value, bool $allowEmpty): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['allow_empty' => $allowEmpty]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => $value, 'type' => StringOperatorType::TYPE_ENDS_WITH]);

        if ('' !== (string) $value) {
            $this->assertSameQuery(['WHERE alias.field LIKE :field_name_0'], $proxyQuery);
            $this->assertSameQueryParameters(['field_name_0' => sprintf('%%%s', $value)], $proxyQuery);
            $this->assertTrue($filter->isActive());
        } else {
            $this->assertSameQuery([], $proxyQuery);
            $this->assertFalse($filter->isActive());
        }
    }

    /**
     * @dataProvider getValues
     */
    public function testNotContains($value, bool $allowEmpty): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['allow_empty' => $allowEmpty]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => $value, 'type' => StringOperatorType::TYPE_NOT_CONTAINS]);

        if ('' !== (string) $value) {
            $this->assertSameQuery(['WHERE alias.field NOT LIKE :field_name_0 OR alias.field IS NULL'], $proxyQuery);
            $this->assertSameQueryParameters(['field_name_0' => sprintf('%%%s%%', $value)], $proxyQuery);
            $this->assertTrue($filter->isActive());
        } else {
            $this->assertSameQuery([], $proxyQuery);
            $this->assertFalse($filter->isActive());
        }
    }

    /**
     * @dataProvider getValues
     */
    public function testEquals($value, bool $allowEmpty): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['allow_empty' => $allowEmpty]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => $value, 'type' => StringOperatorType::TYPE_EQUAL]);

        if ('' !== (string) $value || $allowEmpty) {
            $this->assertSameQuery(['WHERE alias.field = :field_name_0'], $proxyQuery);
            $this->assertSameQueryParameters(['field_name_0' => (string) ($value ?? '')], $proxyQuery);
            $this->assertTrue($filter->isActive());
        } else {
            $this->assertSameQuery([], $proxyQuery);
            $this->assertFalse($filter->isActive());
        }
    }

    /**
     * @dataProvider getValues
     */
    public function testNotEquals($value, bool $allowEmpty): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['allow_empty' => $allowEmpty]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => $value, 'type' => StringOperatorType::TYPE_NOT_EQUAL]);

        if ('' !== (string) $value || $allowEmpty) {
            $this->assertSameQuery(['WHERE alias.field <> :field_name_0 OR alias.field IS NULL'], $proxyQuery);
            $this->assertSameQueryParameters(['field_name_0' => (string) ($value ?? '')], $proxyQuery);
            $this->assertTrue($filter->isActive());
        } else {
            $this->assertSameQuery([], $proxyQuery);
            $this->assertFalse($filter->isActive());
        }
    }

    public function testEqualsWithValidParentAssociationMappings(): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', [
            'field_name' => 'field_name',
            'parent_association_mappings' => [
                [
                    'fieldName' => 'association_mapping',
                ],
                [
                    'fieldName' => 'sub_association_mapping',
                ],
                [
                    'fieldName' => 'sub_sub_association_mapping',
                ],
            ],
        ]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->apply($proxyQuery, ['type' => StringOperatorType::TYPE_EQUAL, 'value' => 'asd']);

        $this->assertSameQuery([
            'LEFT JOIN o.association_mapping AS s_association_mapping',
            'LEFT JOIN s_association_mapping.sub_association_mapping AS s_association_mapping_sub_association_mapping',
            'LEFT JOIN s_association_mapping_sub_association_mapping.sub_sub_association_mapping AS s_association_mapping_sub_association_mapping_sub_sub_association_mapping',
            'WHERE s_association_mapping_sub_association_mapping_sub_sub_association_mapping.field_name = :field_name_0',
        ], $proxyQuery);
        $this->assertSameQueryParameters(['field_name_0' => 'asd'], $proxyQuery);
        $this->assertTrue($filter->isActive());
    }

    /**
     * @dataProvider caseSensitiveDataProvider
     */
    public function testCaseSensitive(?bool $caseSensitive, int $operatorType, string $expectedQuery, string $expectedParameter, array $dqlExtensions = []): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->expects($caseSensitive ? $this->once() : $this->never())
            ->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn($dqlExtensions['binary'] ?? null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['case_sensitive' => $caseSensitive]);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => 'FooBar', 'type' => $operatorType]);
        $this->assertSameQuery([$expectedQuery], $proxyQuery);
        $this->assertSameQueryParameters(['field_name_0' => $expectedParameter], $proxyQuery);
        $this->assertTrue($filter->isActive());
    }

    public function caseSensitiveDataProvider(): iterable
    {
        return [
            [false, StringOperatorType::TYPE_CONTAINS, 'WHERE LOWER(alias.field) LIKE :field_name_0', '%foobar%', ['binary' => Binary::class]],
            [false, StringOperatorType::TYPE_CONTAINS, 'WHERE LOWER(alias.field) LIKE :field_name_0', '%foobar%'],
            [false, StringOperatorType::TYPE_NOT_CONTAINS, 'WHERE LOWER(alias.field) NOT LIKE :field_name_0 OR alias.field IS NULL', '%foobar%'],
            [false, StringOperatorType::TYPE_EQUAL, 'WHERE LOWER(alias.field) = :field_name_0', 'foobar'],
            [false, StringOperatorType::TYPE_NOT_EQUAL, 'WHERE LOWER(alias.field) <> :field_name_0 OR alias.field IS NULL', 'foobar'],
            [false, StringOperatorType::TYPE_STARTS_WITH, 'WHERE LOWER(alias.field) LIKE :field_name_0', 'foobar%'],
            [false, StringOperatorType::TYPE_ENDS_WITH, 'WHERE LOWER(alias.field) LIKE :field_name_0', '%foobar'],
            [true, StringOperatorType::TYPE_CONTAINS, 'WHERE alias.field LIKE :field_name_0', '%FooBar%'],
            [true, StringOperatorType::TYPE_CONTAINS, 'WHERE alias.field LIKE BINARY(:field_name_0)', '%FooBar%', ['binary' => Binary::class]],
            [true, StringOperatorType::TYPE_NOT_CONTAINS, 'WHERE alias.field NOT LIKE :field_name_0 OR alias.field IS NULL', '%FooBar%'],
            [true, StringOperatorType::TYPE_NOT_CONTAINS, 'WHERE alias.field NOT LIKE BINARY(:field_name_0) OR alias.field IS NULL', '%FooBar%', ['binary' => Binary::class]],
            [true, StringOperatorType::TYPE_EQUAL, 'WHERE alias.field = :field_name_0', 'FooBar'],
            [true, StringOperatorType::TYPE_NOT_EQUAL, 'WHERE alias.field <> :field_name_0 OR alias.field IS NULL', 'FooBar'],
            [null, StringOperatorType::TYPE_NOT_EQUAL, 'WHERE alias.field <> :field_name_0 OR alias.field IS NULL', 'FooBar'],
            [null, StringOperatorType::TYPE_NOT_EQUAL, 'WHERE alias.field <> :field_name_0 OR alias.field IS NULL', 'FooBar', ['binary' => Binary::class]],
            [true, StringOperatorType::TYPE_NOT_EQUAL, 'WHERE alias.field <> BINARY(:field_name_0) OR alias.field IS NULL', 'FooBar', ['binary' => Binary::class]],
            [true, StringOperatorType::TYPE_NOT_EQUAL, 'WHERE alias.field <> :field_name_0 OR alias.field IS NULL', 'FooBar', ['binary' => \stdClass::class]],
            [true, StringOperatorType::TYPE_NOT_EQUAL, 'WHERE alias.field <> :field_name_0 OR alias.field IS NULL', 'FooBar', ['binary_extension' => Binary::class]],
            [true, StringOperatorType::TYPE_STARTS_WITH, 'WHERE alias.field LIKE :field_name_0', 'FooBar%'],
            [true, StringOperatorType::TYPE_ENDS_WITH, 'WHERE alias.field LIKE :field_name_0', '%FooBar'],
        ];
    }

    /**
     * NEXT_MAJOR: Remove this test.
     *
     * @group legacy
     *
     * @expectedDeprecation The "format" option is deprecated since sonata-project/doctrine-orm-admin-bundle 3.21 and will be removed in version 4.0.
     */
    public function testFormatOption(): void
    {
        $doctrineConfig = $this->createMock(Configuration::class);
        $doctrineConfig->method('getCustomStringFunction')
            ->with('binary')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getConfiguration')
            ->willReturn($doctrineConfig);

        $filter = new StringFilter($em);
        $filter->initialize('field_name', ['format' => '%s']);

        $proxyQuery = new ProxyQuery($this->createQueryBuilderStub());
        $this->assertSameQuery([], $proxyQuery);

        $filter->filter($proxyQuery, 'alias', 'field', ['value' => 'asd', 'type' => StringOperatorType::TYPE_CONTAINS]);
        $this->assertSameQuery(['WHERE alias.field LIKE :field_name_0'], $proxyQuery);
        $this->assertSameQueryParameters(['field_name_0' => 'asd'], $proxyQuery);
    }
}
