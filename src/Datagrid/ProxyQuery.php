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

namespace Sonata\DoctrineORMAdminBundle\Datagrid;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

/**
 * This class try to unify the query usage with Doctrine.
 *
 * @final since sonata-project/doctrine-orm-admin-bundle 3.29
 *
 * @method Query\Expr    expr()
 * @method QueryBuilder  setCacheable($cacheable)
 * @method bool          isCacheable()
 * @method QueryBuilder  setCacheRegion($cacheRegion)
 * @method string|null   getCacheRegion()
 * @method int           getLifetime()
 * @method QueryBuilder  setLifetime($lifetime)
 * @method int           getCacheMode()
 * @method QueryBuilder  setCacheMode($cacheMode)
 * @method int           getType()
 * @method EntityManager getEntityManager()
 * @method int           getState()
 * @method string        getDQL()
 * @method Query         getQuery()
 * @method string        getRootAlias()
 * @method array         getRootAliases()
 * @method array         getAllAliases()
 * @method array         getRootEntities()
 * @method QueryBuilder  setParameter($key, $value, $type = null)
 * @method QueryBuilder  setParameters($parameters)
 * @method QueryBuilder  getParameters()
 * @method QueryBuilder  getParameter($key)
 * @method QueryBuilder  add($dqlPartName, $dqlPart, $append = false)
 * @method QueryBuilder  select($select = null)
 * @method QueryBuilder  distinct($flag = true)
 * @method QueryBuilder  addSelect($select = null)
 * @method QueryBuilder  delete($delete = null, $alias = null)
 * @method QueryBuilder  update($update = null, $alias = null)
 * @method QueryBuilder  from($from, $alias, $indexBy = null)
 * @method QueryBuilder  indexBy($alias, $indexBy)
 * @method QueryBuilder  join($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
 * @method QueryBuilder  innerJoin($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
 * @method QueryBuilder  leftJoin($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
 * @method QueryBuilder  set($key, $value)
 * @method QueryBuilder  where($where)
 * @method QueryBuilder  andWhere($where)
 * @method QueryBuilder  orWhere($where)
 * @method QueryBuilder  groupBy($groupBy)
 * @method QueryBuilder  addGroupBy($groupBy)
 * @method QueryBuilder  having($having)
 * @method QueryBuilder  andHaving($having)
 * @method QueryBuilder  orHaving($having)
 * @method QueryBuilder  orderBy($sort, $order = null)
 * @method QueryBuilder  addOrderBy($sort, $order = null)
 * @method QueryBuilder  addCriteria(Criteria $criteria)
 * @method mixed         getDQLPart($queryPartName)
 * @method array         getDQLParts()
 * @method QueryBuilder  resetDQLParts($parts = null)
 * @method QueryBuilder  resetDQLPart($part)
 */
class ProxyQuery implements ProxyQueryInterface
{
    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var string|null
     */
    protected $sortBy;

    /**
     * @var string|null
     */
    protected $sortOrder;

    /**
     * @var int
     */
    protected $uniqueParameterId;

    /**
     * @var string[]
     */
    protected $entityJoinAliases;

    /**
     * NEXT_MAJOR: Remove this property.
     *
     * For BC reasons, this property is true by default.
     *
     * @var bool
     */
    private $distinct = true;

    /**
     * The map of query hints.
     *
     * @var array<string,mixed>
     */
    private $hints = [];

    /**
     * @param QueryBuilder $queryBuilder
     */
    public function __construct($queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        $this->uniqueParameterId = 0;
        $this->entityJoinAliases = [];
    }

    public function __call($name, $args)
    {
        return \call_user_func_array([$this->queryBuilder, $name], $args);
    }

    public function __get($name)
    {
        return $this->queryBuilder->$name;
    }

    public function __clone()
    {
        $this->queryBuilder = clone $this->queryBuilder;
    }

    /**
     * NEXT_MAJOR: Remove this method.
     *
     * @deprecated since sonata-project/doctrine-orm-admin-bundle 3.32, to be removed in 4.0.
     *
     * Optimize queries with a lot of rows.
     * It is not recommended to use "false" with left joins.
     *
     * @param bool $distinct
     *
     * @return self
     */
    final public function setDistinct($distinct)
    {
        @trigger_error(sprintf(
            'The method "%s()" is deprecated since sonata-project/doctrine-orm-admin-bundle 3.32'
            .' and will be removed in version 4.0.',
            __METHOD__
        ), \E_USER_DEPRECATED);

        if (!\is_bool($distinct)) {
            throw new \InvalidArgumentException('$distinct is not a boolean');
        }

        $this->distinct = $distinct;

        return $this;
    }

    /**
     * NEXT_MAJOR: Remove this method.
     *
     * @deprecated since sonata-project/doctrine-orm-admin-bundle 3.32, to be removed in 4.0.
     *
     * @return bool
     */
    final public function isDistinct()
    {
        @trigger_error(sprintf(
            'The method "%s()" is deprecated since sonata-project/doctrine-orm-admin-bundle 3.32'
            .' and will be removed in version 4.0.',
            __METHOD__
        ), \E_USER_DEPRECATED);

        return $this->distinct;
    }

    public function execute(array $params = [], $hydrationMode = null)
    {
        // NEXT_MAJOR: Remove this check and update method signature to `execute()`.
        if (\func_num_args() > 0) {
            @trigger_error(sprintf(
                'Passing arguments to "%s()" is deprecated since sonata-project/doctrine-orm-admin-bundle 3.31.',
                __METHOD__
            ), \E_USER_DEPRECATED);
        }

        $query = $this->getDoctrineQuery();

        foreach ($this->hints as $name => $value) {
            $query->setHint($name, $value);
        }

        return $query->execute($params, $hydrationMode);
    }

    /**
     * This method alters the query in order to
     *     - update the sortBy of the doctrine query in order to use the one provided
     *       by the ProxyQueryInterface Api.
     *     - add a sort on the identifier fields of the first used entity in the query,
     *       because RDBMS do not guarantee a particular order when no ORDER BY clause
     *       is specified, or when the field used for sorting is not unique.
     */
    public function getDoctrineQuery(): Query
    {
        // Always clone the original queryBuilder
        $queryBuilder = clone $this->queryBuilder;

        $rootAlias = current($queryBuilder->getRootAliases());

        if ($this->getSortBy()) {
            $orderByDQLPart = $queryBuilder->getDQLPart('orderBy');
            $queryBuilder->resetDQLPart('orderBy');

            $sortBy = $this->getSortBy();
            if (false === strpos($sortBy, '.')) {
                $sortBy = $rootAlias.'.'.$sortBy;
            }

            $queryBuilder->addOrderBy($sortBy, $this->getSortOrder());
            foreach ($orderByDQLPart as $orderBy) {
                $queryBuilder->addOrderBy($orderBy);
            }
        }

        $identifierFields = $queryBuilder
            ->getEntityManager()
            ->getMetadataFactory()
            ->getMetadataFor(current($queryBuilder->getRootEntities()))
            ->getIdentifierFieldNames();

        $existingOrders = [];
        foreach ($queryBuilder->getDQLPart('orderBy') as $order) {
            foreach ($order->getParts() as $part) {
                $existingOrders[] = trim(str_replace([Criteria::DESC, Criteria::ASC], '', $part));
            }
        }

        foreach ($identifierFields as $identifierField) {
            $field = $rootAlias.'.'.$identifierField;

            if (!\in_array($field, $existingOrders, true)) {
                $queryBuilder->addOrderBy($field, $this->getSortOrder());
            }
        }

        return $queryBuilder->getQuery();
    }

    public function setSortBy($parentAssociationMappings, $fieldMapping)
    {
        $alias = $this->entityJoin($parentAssociationMappings);
        $this->sortBy = $alias.'.'.$fieldMapping['fieldName'];

        return $this;
    }

    public function getSortBy()
    {
        return $this->sortBy;
    }

    public function setSortOrder($sortOrder)
    {
        if (!\in_array(strtoupper($sortOrder), $validSortOrders = ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a valid sort order, valid values are "%s"',
                $sortOrder,
                implode(', ', $validSortOrders)
            ));
        }
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getSortOrder()
    {
        return $this->sortOrder;
    }

    /**
     * NEXT_MAJOR: Remove this method.
     *
     * @deprecated since sonata-project/doctrine-orm-admin-bundle 3.31, to be removed in 4.0.
     */
    public function getSingleScalarResult()
    {
        @trigger_error(sprintf(
            'The method "%s()" is deprecated since sonata-project/doctrine-orm-admin-bundle 3.31'
            .' and will be removed in version 4.0.',
            __METHOD__
        ), \E_USER_DEPRECATED);

        $query = $this->queryBuilder->getQuery();

        return $query->getSingleScalarResult();
    }

    /**
     * @return QueryBuilder
     */
    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }

    public function setFirstResult($firstResult)
    {
        $this->queryBuilder->setFirstResult($firstResult);

        return $this;
    }

    public function getFirstResult()
    {
        return $this->queryBuilder->getFirstResult();
    }

    public function setMaxResults($maxResults)
    {
        $this->queryBuilder->setMaxResults($maxResults);

        return $this;
    }

    public function getMaxResults()
    {
        return $this->queryBuilder->getMaxResults();
    }

    public function getUniqueParameterId()
    {
        return $this->uniqueParameterId++;
    }

    public function entityJoin(array $associationMappings)
    {
        $alias = current($this->queryBuilder->getRootAliases());

        $newAlias = 's';

        $joinedEntities = $this->queryBuilder->getDQLPart('join');

        foreach ($associationMappings as $associationMapping) {
            // Do not add left join to already joined entities with custom query
            foreach ($joinedEntities as $joinExprList) {
                foreach ($joinExprList as $joinExpr) {
                    $newAliasTmp = $joinExpr->getAlias();

                    if (sprintf('%s.%s', $alias, $associationMapping['fieldName']) === $joinExpr->getJoin()) {
                        $this->entityJoinAliases[] = $newAliasTmp;
                        $alias = $newAliasTmp;

                        continue 3;
                    }
                }
            }

            $newAlias .= '_'.$associationMapping['fieldName'];
            if (!\in_array($newAlias, $this->entityJoinAliases, true)) {
                $this->entityJoinAliases[] = $newAlias;
                $this->queryBuilder->leftJoin(sprintf('%s.%s', $alias, $associationMapping['fieldName']), $newAlias);
            }

            $alias = $newAlias;
        }

        return $alias;
    }

    /**
     * Sets a {@see \Doctrine\ORM\Query} hint. If the hint name is not recognized, it is silently ignored.
     *
     * @param string $name  the name of the hint
     * @param mixed  $value the value of the hint
     *
     * @return ProxyQueryInterface
     *
     * @see \Doctrine\ORM\Query::setHint
     * @see \Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER
     */
    final public function setHint($name, $value)
    {
        $this->hints[$name] = $value;

        return $this;
    }

    /**
     * NEXT_MAJOR: Remove this method.
     *
     * @deprecated since sonata-project/doctrine-orm-admin-bundle 3.31, to be removed in 4.0.
     *
     * This method alters the query to return a clean set of object with a working
     * set of Object.
     *
     * @return QueryBuilder
     */
    protected function getFixedQueryBuilder(QueryBuilder $queryBuilder)
    {
        @trigger_error(sprintf(
            'The method "%s()" is deprecated since sonata-project/doctrine-orm-admin-bundle 3.31'
            .' and will be removed in version 4.0.',
            __METHOD__
        ), \E_USER_DEPRECATED);

        $queryBuilderId = clone $queryBuilder;
        $rootAlias = current($queryBuilderId->getRootAliases());

        // step 1 : retrieve the targeted class
        $from = $queryBuilderId->getDQLPart('from');
        $class = $from[0]->getFrom();
        $metadata = $queryBuilderId->getEntityManager()->getMetadataFactory()->getMetadataFor($class);

        // step 2 : retrieve identifier columns
        $idNames = $metadata->getIdentifierFieldNames();

        // step 3 : retrieve the different subjects ids
        $selects = [];
        $idxSelect = '';
        foreach ($idNames as $idName) {
            $select = sprintf('%s.%s', $rootAlias, $idName);
            // Put the ID select on this array to use it on results QB
            $selects[$idName] = $select;
            // Use IDENTITY if id is a relation too.
            // See: http://doctrine-orm.readthedocs.org/en/latest/reference/dql-doctrine-query-language.html
            // Should work only with doctrine/orm: ~2.2
            $idSelect = $select;
            if ($metadata->hasAssociation($idName)) {
                $idSelect = sprintf('IDENTITY(%s) as %s', $idSelect, $idName);
            }
            $idxSelect .= ('' !== $idxSelect ? ', ' : '').$idSelect;
        }
        $queryBuilderId->select($idxSelect);
        $queryBuilderId->distinct($this->isDistinct());

        // for SELECT DISTINCT, ORDER BY expressions must appear in idxSelect list
        /* Consider
            SELECT DISTINCT x FROM tab ORDER BY y;
        For any particular x-value in the table there might be many different y
        values.  Which one will you use to sort that x-value in the output?
        */
        $queryId = $queryBuilderId->getQuery();
        $queryId->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [OrderByToSelectWalker::class]);
        $results = $queryId->execute([], Query::HYDRATE_ARRAY);
        $platform = $queryBuilderId->getEntityManager()->getConnection()->getDatabasePlatform();
        $idxMatrix = [];
        foreach ($results as $id) {
            foreach ($idNames as $idName) {
                // Convert ids to database value in case of custom type, if provided.
                $fieldType = $metadata->getTypeOfField($idName);
                $idxMatrix[$idName][] = $fieldType && Type::hasType($fieldType)
                    ? Type::getType($fieldType)->convertToDatabaseValue($id[$idName], $platform)
                    : $id[$idName];
            }
        }

        // step 4 : alter the query to match the targeted ids
        foreach ($idxMatrix as $idName => $idx) {
            if (\count($idx) > 0) {
                $idxParamName = sprintf('%s_idx', $idName);
                $idxParamName = preg_replace('/[^\w]+/', '_', $idxParamName);
                $queryBuilder->andWhere(sprintf('%s IN (:%s)', $selects[$idName], $idxParamName));
                $queryBuilder->setParameter($idxParamName, $idx);
                $queryBuilder->setMaxResults(null);
                $queryBuilder->setFirstResult(null);
            }
        }

        return $queryBuilder;
    }
}
