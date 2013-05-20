<?php

namespace Jackalope\Transport\DoctrineDBAL\Query;

use Jackalope\Query\QOM\PropertyValue;
use Jackalope\Transport\DoctrineDBAL\RepositorySchema;
use PHPCR\NamespaceException;
use PHPCR\NodeType\NodeTypeManagerInterface;
use PHPCR\Query\InvalidQueryException;
use PHPCR\Query\QOM;

use Jackalope\NotImplementedException;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use PHPCR\Util\QOM\NotSupportedOperandException;

/**
 * Converts QOM to SQL Statements for the Doctrine DBAL database backend.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0, January 2004
 */
class QOMWalker
{
    /**
     * @var NodeTypeManagerInterface
     */
    private $nodeTypeManager;

    /**
     * @var array
     */
    private $alias = array();

    /**
     * @var \Doctrine\DBAL\Connection
     */
    private $conn;

    /**
     * @var AbstractPlatform
     */
    private $platform;

    /**
     * @var array
     */
    private $namespaces;

    /**
     * @var \Doctrine\DBAL\Schema\Schema
     */
    private $schema;

    /**
     * @param \PHPCR\NodeType\NodeTypeManagerInterface $manager
     * @param Connection $conn
     * @param array $namespaces
     */
    public function __construct(NodeTypeManagerInterface $manager, Connection $conn, array $namespaces = array())
    {
        $this->conn = $conn;
        $this->nodeTypeManager = $manager;
        $this->platform = $conn->getDatabasePlatform();
        $this->namespaces = $namespaces;
        $this->schema = RepositorySchema::create();
    }

    /**
     * Generate a table alias
     *
     * @param $selectorName
     * @return string
     */
    private function getTableAlias($selectorName)
    {
        $selectorAlias = $this->getSelectorAlias($selectorName);

        if (!isset($this->alias[$selectorAlias])) {
            $this->alias[$selectorAlias] = "n" . count($this->alias);
        }

        return $this->alias[$selectorAlias];
    }

    /**
     * @param $selectorName
     * @return mixed|string
     */
    private function getSelectorAlias($selectorName)
    {
        if (null === $selectorName) {
            if (count($this->alias)) { // We have aliases, use the first
                $selectorAlias = array_search('n0', $this->alias);
            } else { // Currently no aliases, use an empty string as index
                $selectorAlias = '';
            }
        } else if (strpos($selectorName, ".") === false) {
            $selectorAlias = $selectorName;
        } else {
            $parts = explode(".", $selectorName);
            $selectorAlias = reset($parts);
        }

        return $selectorAlias;
    }

    /**
     * @param \PHPCR\Query\QOM\QueryObjectModelInterface $qom
     * @return string
     */
    public function walkQOMQuery(QOM\QueryObjectModelInterface $qom)
    {
        $sourceSql = " " . $this->walkSource($qom->getSource());
        $constraintSql = '';
        if ($constraint = $qom->getConstraint()) {
            $constraintSql = " AND " . $this->walkConstraint($constraint);
        }

        $orderingSql = '';
        if ($orderings = $qom->getOrderings()) {
            $orderingSql = " " . $this->walkOrderings($orderings);
        }

        $sql = "SELECT";
        $sql .= " " . $this->walkColumns($qom->getColumns());
        $sql .= $sourceSql;
        $sql .= $constraintSql;
        $sql .= $orderingSql;

        return $sql;
    }

    /**
     * @param $columns
     * @return string
     */
    public function walkColumns($columns)
    {
        $sqlColumns = array();
        foreach ($this->schema->getTable('phpcr_nodes')->getColumns() as $column) {
            $sqlColumns[] = $column->getName();
        }

        if (count($this->alias)) {
            $aliasSql = array();
            foreach ($this->alias as $selectorAlias => $alias) {
                if ('' !== $selectorAlias) {
                    $selectorAlias = $selectorAlias . '_';
                }
                foreach ($sqlColumns as $sqlColumn) {
                    $aliasSql[] = sprintf('%s.%s AS %s%s', $alias, $sqlColumn, $selectorAlias, $sqlColumn);
                }
            }
            $sql = join(', ', $aliasSql);
        } else {
            $sql = '*';
        }

        return $sql;
    }

    /*
     * @return string
     */
    public function walkColumn(QOM\ColumnInterface $column)
    {
        $alias = $this->getTableAlias($column->getSelectorName());
        return $this->sqlProperty($alias, $column->getPropertyName());
    }

    /**
     * @param \PHPCR\Query\QOM\SourceInterface $source
     * @return string
     * @throws \Jackalope\NotImplementedException
     */
    public function walkSource(QOM\SourceInterface $source)
    {
        if ($source instanceOf QOM\SelectorInterface) {
            return $this->walkSelectorSource($source);
        }

        if ($source instanceOf QOM\JoinInterface) {
            return $this->walkJoinSource($source);
        }
    }

    /**
     * @param QOM\SelectorInterface $source
     * @return string
     */
    public function walkSelectorSource(QOM\SelectorInterface $source)
    {
        $alias = $this->getTableAlias($source->getSelectorName());
        $nodeTypeClause = $this->sqlNodeTypeClause($alias, $source);
        $sql = "FROM phpcr_nodes $alias WHERE $alias.workspace_name = ? AND $nodeTypeClause";

        return $sql;
    }

    /**
     * @param QOM\JoinInterface $source
     * @return string
     * @throws \Jackalope\NotImplementedException
     */
    public function walkJoinSource(QOM\JoinInterface $source)
    {
        if (!$source->getLeft() instanceOf QOM\SelectorInterface || !$source->getRight() instanceOf QOM\SelectorInterface) {
            throw new NotImplementedException("Join with Joins");
        }

        $leftAlias = $this->getTableAlias($source->getLeft()->getSelectorName());
        $sql = "FROM phpcr_nodes $leftAlias ";

        $rightAlias = $this->getTableAlias($source->getRight()->getSelectorName());
        $nodeTypeClause = $this->sqlNodeTypeClause($rightAlias, $source->getRight());

        switch ($source->getJoinType()) {
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_INNER:
                $sql .= "INNER JOIN phpcr_nodes $rightAlias ";
                break;
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_LEFT_OUTER:
                $sql .= "LEFT JOIN phpcr_nodes $rightAlias ";
                break;
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_RIGHT_OUTER:
                $sql .= "RIGHT JOIN phpcr_nodes $rightAlias ";
                break;
        }

        $sql .= "ON ( $leftAlias.workspace_name = $rightAlias.workspace_name AND $nodeTypeClause ";
        $sql .= "AND " . $this->walkJoinCondition($source->getLeft(), $source->getRight(), $source->getJoinCondition()) . " ";
        $sql .= ") "; // close on-clause

        $sql .= "WHERE $leftAlias.workspace_name = ? AND $leftAlias.type IN ('" . $source->getLeft()->getNodeTypeName() ."'";
        $subTypes = $this->nodeTypeManager->getSubtypes($source->getLeft()->getNodeTypeName());
        foreach ($subTypes as $subType) {
            /* @var $subType \PHPCR\NodeType\NodeTypeInterface */
            $sql .= ", '" . $subType->getName() . "'";
        }
        $sql .= ')';


        return $sql;
    }

    public function walkJoinCondition(QOM\SelectorInterface $left, QOM\SelectorInterface $right, QOM\JoinConditionInterface $condition)
    {
        if ($condition instanceOf QOM\ChildNodeJoinConditionInterface) {
            throw new NotImplementedException("ChildNodeJoinCondition");
        }

        if ($condition instanceOf QOM\DescendantNodeJoinConditionInterface) {
            return $this->walkDescendantNodeJoinConditon($condition);
        }

        if ($condition instanceOf QOM\EquiJoinConditionInterface) {
            return $this->walkEquiJoinCondition($left->getSelectorName(), $right->getSelectorName(), $condition);
        }

        if ($condition instanceOf QOM\SameNodeJoinConditionInterface) {
            throw new NotImplementedException("SameNodeJoinCondtion");
        }
    }

    /**
     * @param QOM\DescendantNodeJoinConditionInterface $condition
     * @return string
     */
    public function walkDescendantNodeJoinConditon(QOM\DescendantNodeJoinConditionInterface $condition)
    {
        $rightAlias = $this->getTableAlias($condition->getDescendantSelectorName());
        $leftAlias = $this->getTableAlias($condition->getAncestorSelectorName());
        return "$rightAlias.path LIKE CONCAT($leftAlias.path, '/%') ";
    }

    /**
     * @param QOM\EquiJoinConditionInterface $condition
     * @return string
     */
    public function walkEquiJoinCondition($leftSelectorName, $rightSelectorName, QOM\EquiJoinConditionInterface $condition)
    {
        return $this->walkOperand(new PropertyValue($leftSelectorName, $condition->getProperty1Name())) . " " .
               $this->walkOperator(QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO) . " " .
               $this->walkOperand(new PropertyValue($rightSelectorName, $condition->getProperty2Name()));
    }

    /**
     * @param \PHPCR\Query\QOM\ConstraintInterface $constraint
     * @return string
     * @throws \PHPCR\Query\InvalidQueryException
     */
    public function walkConstraint(QOM\ConstraintInterface $constraint)
    {
        if ($constraint instanceof QOM\AndInterface) {
            return $this->walkAndConstraint($constraint);
        }
        if ($constraint instanceof QOM\OrInterface) {
            return $this->walkOrConstraint($constraint);
        }
        if ($constraint instanceof QOM\NotInterface) {
            return $this->walkNotConstraint($constraint);
        }
        if ($constraint instanceof QOM\ComparisonInterface) {
            return $this->walkComparisonConstraint($constraint);
        }
        if ($constraint instanceof QOM\DescendantNodeInterface) {
            return $this->walkDescendantNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\ChildNodeInterface) {
            return $this->walkChildNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\PropertyExistenceInterface) {
            return $this->walkPropertyExistenceConstraint($constraint);
        }
        if ($constraint instanceof QOM\SameNodeInterface) {
            return $this->walkSameNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\FullTextSearchInterface) {
            return $this->walkFullTextSearchConstraint($constraint);
        }

        throw new InvalidQueryException("Constraint " . get_class($constraint) . " not yet supported.");
    }

    /**
     * @param \PHPCR\Query\QOM\SameNodeInterface $constraint
     * @return string
     */
    public function walkSameNodeConstraint(QOM\SameNodeInterface $constraint)
    {
        return $this->getTableAlias($constraint->getSelectorName()) . ".path = '" . $constraint->getPath() . "'";
    }

    /**
     * @param \PHPCR\Query\QOM\FullTextSearchInterface $constraint
     * @return string
     */
    public function walkFullTextSearchConstraint(QOM\FullTextSearchInterface $constraint)
    {
        return $this->sqlXpathExtractValue($this->getTableAlias($constraint->getSelectorName()), $constraint->getPropertyName()).' LIKE '. $this->conn->quote('%'.$constraint->getFullTextSearchExpression().'%');
    }

    /**
     * @param QOM\PropertyExistenceInterface $constraint
     */
    public function walkPropertyExistenceConstraint(QOM\PropertyExistenceInterface $constraint)
    {
        return $this->sqlXpathValueExists($this->getTableAlias($constraint->getSelectorName()), $constraint->getPropertyName());
    }

    /**
     * @param QOM\DescendantNodeInterface $constraint
     * @return string
     */
    public function walkDescendantNodeConstraint(QOM\DescendantNodeInterface $constraint)
    {
        $ancestorPath = $constraint->getAncestorPath();
        if ('/' === $ancestorPath) {
            $ancestorPath = '';
        } elseif (substr($ancestorPath, -1) === '/') {
            throw new InvalidQueryException("Trailing slash in $ancestorPath");
        }

        return $this->getTableAlias($constraint->getSelectorName()) . ".path LIKE '" . $ancestorPath . "/%'";
    }

    /**
     * @param \PHPCR\Query\QOM\ChildNodeInterface $constraint
     * @return string
     */
    public function walkChildNodeConstraint(QOM\ChildNodeInterface $constraint)
    {
        return $this->getTableAlias($constraint->getSelectorName()) . ".parent = '" . $constraint->getParentPath() . "'";
    }

    /**
     * @param QOM\AndInterface $constraint
     * @return string
     */
    public function walkAndConstraint(QOM\AndInterface $constraint)
    {
        return "(" . $this->walkConstraint($constraint->getConstraint1()) . " AND " . $this->walkConstraint($constraint->getConstraint2()) . ")";
    }

    /**
     * @param QOM\OrInterface $constraint
     * @return string
     */
    public function walkOrConstraint(QOM\OrInterface $constraint)
    {
        return "(" . $this->walkConstraint($constraint->getConstraint1()) . " OR " . $this->walkConstraint($constraint->getConstraint2()) . ")";
    }

    /**
     * @param QOM\NotInterface $constraint
     * @return string
     */
    public function walkNotConstraint(QOM\NotInterface $constraint)
    {
        return "NOT (" . $this->walkConstraint($constraint->getConstraint()) . ")";
    }

    /**
     * @param QOM\ComparisonInterface $constraint
     */
    public function walkComparisonConstraint(QOM\ComparisonInterface $constraint)
    {
        return $this->walkOperand($constraint->getOperand1()) . " " .
               $this->walkOperator($constraint->getOperator()) . " " .
               $this->walkOperand($constraint->getOperand2());
    }

    /**
     * @param string $operator
     * @return string
     */
    public function walkOperator($operator)
    {
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO) {
            return "=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN) {
            return ">";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN_OR_EQUAL_TO) {
            return ">=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN) {
            return "<";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN_OR_EQUAL_TO) {
            return "<=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_NOT_EQUAL_TO) {
            return "!=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LIKE) {
            return "LIKE";
        }

        return $operator; // no-op for simplicity, not standard conform (but using the constants is a pain)
    }

    /**
     * @param QOM\OperandInterface $operand
     */
    public function walkOperand(QOM\OperandInterface $operand)
    {
        if ($operand instanceof QOM\NodeNameInterface) {
            $selector = $operand->getSelectorName();
            $alias = $this->getTableAlias($selector);

            return $this->platform->getConcatExpression("$alias.namespace", "(CASE $alias.namespace WHEN '' THEN '' ELSE ':' END)", "$alias.local_name");
        }
        if ($operand instanceof QOM\NodeLocalNameInterface) {
            $selector = $operand->getSelectorName();
            $alias = $this->getTableAlias($selector);

            return "$alias.local_name";
        }
        if ($operand instanceof QOM\LowerCaseInterface) {
            return $this->platform->getLowerExpression($this->walkOperand($operand->getOperand()));
        }
        if ($operand instanceof QOM\UpperCaseInterface) {
            return $this->platform->getUpperExpression($this->walkOperand($operand->getOperand()));
        }
        if ($operand instanceof QOM\LiteralInterface) {
            $namespace = '';

            $value = $operand->getLiteralValue();

            if ($value instanceof \DateTime) {
                $literal = $value->format('c');
            } else {
                $literal = trim($value, '"');
                if (($aliasLength = strpos($literal, ':')) !== false) {
                    $alias = substr($literal, 0, $aliasLength);
                    if (!isset($this->namespaces[$alias])) {
                        throw new NamespaceException('the namespace ' . $alias . ' was not registered.');
                    }
                    if (!empty($this->namespaces[$alias])) {
                        $namespace = $this->namespaces[$alias].':';
                    }

                    $literal = substr($literal, $aliasLength + 1);
                }
            }

            return $this->conn->quote($namespace.$literal);
        }
        if ($operand instanceof QOM\PropertyValueInterface) {
            $alias = $this->getTableAlias($operand->getSelectorName() . '.' . $operand->getPropertyName());
            $property = $operand->getPropertyName();
            if ($property == "jcr:path") {
                return $alias . ".path";
            }
            if ($property == "jcr:uuid") {
                return $alias . ".identifier";
            }

            return $this->sqlXpathExtractValue($alias, $property);
        }
        if ($operand instanceof QOM\LengthInterface) {
            $alias = $this->getTableAlias($operand->getPropertyValue()->getSelectorName());
            $property = $operand->getPropertyValue()->getPropertyName();

            return $this->sqlProperty($alias, $property);
        }

        throw new InvalidQueryException("Dynamic operand " . get_class($operand) . " not yet supported.");
    }

    /**
     * @param array $orderings
     * @return string
     */
    public function walkOrderings(array $orderings)
    {
        $sql = '';
        foreach ($orderings as $ordering) {
            $sql .= empty($sql) ? "ORDER BY " : ", ";
            $sql .= $this->walkOrdering($ordering);
        }
        return $sql;
    }

    /**
     * @param \PHPCR\Query\QOM\OrderingInterface $ordering
     * @return string
     */
    public function walkOrdering(QOM\OrderingInterface $ordering)
    {
        return $this->walkOperand($ordering->getOperand()) . " " .
               (($ordering->getOrder() == QOM\QueryObjectModelConstantsInterface::JCR_ORDER_ASCENDING) ? "ASC" : "DESC");
    }

    /**
     * SQL to execute an XPATH expression checking if the property exist on the node with the given alias.
     *
     * @param string $alias
     * @param string $property
     * @return string
     */
    private function sqlXpathValueExists($alias, $property)
    {
        if ($this->platform instanceof MySqlPlatform) {
            return "EXTRACTVALUE($alias.props, 'count(//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1])') = 1";
        }
        if ($this->platform instanceof PostgreSqlPlatform) {
            return "xpath_exists('//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]', CAST($alias.props AS xml), ".$this->sqlXpathPostgreSQLNamespaces().") = 't'";
        }
        if ($this->platform instanceof SqlitePlatform) {
            return "EXTRACTVALUE($alias.props, 'count(//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1])') = 1";
        }

        throw new NotImplementedException("Xpath evaluations cannot be executed with '" . $this->platform->getName() . "' yet.");
    }

    /**
     * SQL to execute an XPATH expression extracting the property value on the node with the given alias.
     *
     * @param string $alias
     * @param string $property
     * @return string
     */
    private function sqlXpathExtractValue($alias, $property)
    {
        if ($this->platform instanceof MySqlPlatform) {
            return "EXTRACTVALUE($alias.props, '//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]')";
        }
        if ($this->platform instanceof PostgreSqlPlatform) {
            return "(xpath('//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]/text()', CAST($alias.props AS xml), ".$this->sqlXpathPostgreSQLNamespaces()."))[1]::text";
        }
        if ($this->platform instanceof SqlitePlatform) {
            return "EXTRACTVALUE($alias.props, '//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]')";
        }

        throw new NotImplementedException("Xpath evaluations cannot be executed with '" . $this->platform->getName() . "' yet.");
    }

    /**
     * @return string
     */
    private function sqlXpathPostgreSQLNamespaces()
    {
        return "ARRAY[ARRAY['sv', 'http://www.jcp.org/jcr/sv/1.0']]";
    }

    /**
     * Returns the SQL part to select the given property
     *
     * @param $alias
     * @param $propertyName
     * @return string
     */
    private function sqlProperty($alias, $propertyName)
    {
        if ('jcr:uuid' === $propertyName) {
            return "$alias.identifier";
        }

        if ('jcr:path' === $propertyName) {
            return "$alias.path";
        }

        return $this->sqlXpathExtractValue($alias, $propertyName);
    }

    /**
     * @param QOM\SelectorInterface $source
     * @param string $alias
     * @return string
     */
    private function sqlNodeTypeClause($alias, QOM\SelectorInterface $source)
    {
        $sql = "$alias.type IN ('" . $source->getNodeTypeName() ."'";

        $subTypes = $this->nodeTypeManager->getSubtypes($source->getNodeTypeName());
        foreach ($subTypes as $subType) {
            /* @var $subType \PHPCR\NodeType\NodeTypeInterface */
            $sql .= ", '" . $subType->getName() . "'";
        }
        $sql .= ')';

        return $sql;
    }
}
