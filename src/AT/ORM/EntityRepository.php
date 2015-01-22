<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AT\ORM;

/**
 * Description of EntityRepository
 *
 * @author Andrey Tkachenko
 */
class EntityRepository
{
    /**
     * @var EntityMetaData 
     */
    protected $metadata;
            
    /**
     * @var string 
     */
    protected $entityClass;

    /**
     * @var EntityManager 
     */
    protected $entityManager;

    /**
     * @param EntityManager $em
     * @param EntityMetaData $classMetaData
     * @throws Exception
     */
    public function __construct(EntityManager $em, EntityMetaData $classMetaData)
    {
        $this->metadata      = $classMetaData;
        $this->entityManager = $em;
        $this->entityClass   = $classMetaData->getClassName();
    }
    
    public function __destruct()
    {
        if (count($this->openedTransactions)) {
            foreach ($this->openedTransactions as $transaction) {
                $this->executeRollbackTransaction($transaction);
            }
        }
    }
    
    public function store($entity, array $deep = null, $merge = false)
    {
        $data = $this->getEntityUpdateData($entity, $deep, $entity->getId() ? $merge : false);
        $status = $this->runUpdatesInTransaction($data);
        foreach ($status as $item) {
            $entity = $item['entity'];
            $meta = $this->entityManager->getEntityMetaData($entity);
            $setter = $meta->getPrimaryProperty('setter');
            $entity->$setter($item['id']);
        }
    }
    
    public function delete($entity)
    {
        $getter = $this->metadata->getPrimaryProperty('getter');
        $id = is_object($entity) ? $entity->$getter() : $entity;
        
        return $this->executeDeleteQuery(
            $this->metadata->getTableName(),
            array(
                $this->metadata->getPrimaryColumn() => $id
            )
        );
    }

    public function findBy(array $where, $orderBy = array(), $limit = null, $offset = null, $joins = null)
    {
        $queryData = array(
            'deep_map' => $joins,
            'table_counter' => 0,
            'table_map' => array(),
            'table_meta' => array(),
            'path_map' => array()
        );
        
        $rows = $this->query($queryData, $where, $orderBy, $limit, $offset);
        
        return $this->hydrate($rows, $queryData);
    }
    
    public function findOneBy(array $where, $joins = null)
    {
        $queryData = array(
            'deep_map' => $joins,
            'table_counter' => 0,
            'table_map' => array(),
            'table_meta' => array(),
            'path_map' => array()
        );
        
        $rows = $this->query($queryData, $where, array());
        $objects = $this->hydrate($rows, $queryData);
        
        return array_shift($objects);
    }
    
    public function find($id, $joins = null)
    {
        return $this->findOneBy(array('id' => $id), $joins);
    }
    
    public function findAll($orderBy = array(), $limit = null, $offset = null, $joins = null)
    {
        return $this->findBy(array(), $orderBy, $limit, $offset, $joins);
    }
    
    protected function executeSelectQuery(array $select, $from, $fromAs, array $joins, array $whereSql, array $orderBySql, $limit, $offset)
    {
    }
    
    protected function executeGetLastInsertId()
    {
    }
    
    protected function executeBeginTransaction()
    {
    }
    
    protected function executeCommitTransaction($transaction)
    {
    }
    
    protected function executeRollbackTransaction($transaction)
    {
    }
    
    protected function executeUpdateQuery($table, $data, $where)
    {
    }
    
    protected function executeInsertQuery($into, $data)
    {
    }
    
    protected function executeDeleteQuery($from, $where)
    {
    }
    
    protected function where(&$context, $where)
    {
        $sql = $this->replaceWhere($context, $where);
    }
    
    private function getAsType($value, $type)
    {
        switch (strtolower($type)) {
            case 'bool':
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'datetime':
                return new DateTime($value);
            case 'array':
                return json_decode($value, true);
            default:
            case 'string':
                return $value;
        }
    }
    
    private function getTypeAsValue($value, $type)
    {
        switch (strtolower($type)) {
            case 'bool':
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'datetime':
                return $value->format('Y-m-d H:i:s');
            case 'array':
                return json_encode($value);
            default:
            case 'string':
                return $value;
        }
    }
    
    private function collapseRow(array $elements)
    {
        $arr = array();
        
        foreach ($elements as $item) {
            list($path, $entity, $metadata) = $item;
            
            array_unshift($path, 'root');
            $pathStr = implode('.', $path);
            $field = array_pop($path);
            $parentStr = implode('.', $path);
            
            if (isset($arr[$parentStr])) {
                list($parent, $parentMeta) = $arr[$parentStr];
                
                $relation = $parentMeta->getFieldProperty($field, 'relation');
                $setter   = $parentMeta->getFieldProperty($field, 'setter');
                
                if ($relation['type'] == 'onetomany') {
                    $getter = $parentMeta->getFieldProperty($field, 'getter');
                    $res = $parent->$getter();
                    
                    if (!is_array($res)) {
                        $res = array($entity);
                    } else {
                        if (!in_array($entity, $res)) {
                            $res[] = $entity;
                        }
                    }
                    
                    $parent->$setter($res);
                } else {
                    $parent->$setter($entity);
                }
            }
            
            $arr[$pathStr] = array($entity, $metadata);
        }

        return $arr['root'][0];
    }
    
    private function getOrCreateEntity($metadata, array $row)
    {
        $class = $metadata->getClassName();
        $pc = $metadata->getPrimaryColumn();
        $id = $row[$pc];
        
        if ($id === null) {
            return null;
        }
        
        $entity = $this->entityManager->lookup($class, $id);
        
        if (empty($entity)) {
            $entity = new $class();
            
            foreach ($row as $field => $value) {
                $type = $metadata->getFieldProperty($field, 'type');
                $setter = $metadata->getFieldProperty($field, 'setter');
                if ($value !== null) {
                    $entity->$setter($this->getAsType($value, $type));
                }
            }
            
            $this->entityManager->put($entity);
        }
        
        return $entity;
    }
    
    private function hydrate(array $rows, array &$queryData)
    {
        $index = array();
        
        foreach ($rows as $dbrow) {
            $tableRow = array();
            
            foreach ($dbrow as $fieldName => $value) {
                list($tableId, $field) = explode(':', $fieldName);
                $tableRow[$tableId][$field] = $value;
            }
            
            $bundle = array();
            foreach ($tableRow as $tableId => $row) {
                $metadata = $queryData['table_meta'][$tableId];
                $entity = $this->getOrCreateEntity($metadata, $row);
                
                if (!empty($entity)) {
                    $bundle[] = array($queryData['table_map'][$tableId], $entity, $metadata);
                }
            }
            
            $entity = $this->collapseRow($bundle);
            
            if (!in_array($entity, $index)) {
                $index[] = $entity;
            }
        }

        return $index;
    }
    
    private function matchPath($array, $path)
    {
        $tmp = $array;
        foreach ($path as $p) {
            if (!is_array($tmp)) {
                return false;
            }
            
            if (isset($tmp[$p])) {
                $tmp = $tmp[$p];
            } elseif (in_array($p, $tmp)) {
                $tmp = true;
            } else {
                $tmp = false;
            }
        }
        
        return $tmp || is_array($tmp) ? true : false;
    }
    
    private function getMDataAndField(&$queryData, $key)
    {
        if (isset($queryData['path_map'][$key])) {
            $tableId = $queryData['path_map'][$key];
            $field = null;
        } else {
            $parts = explode('.', $key);
            $field = array_pop($parts);
            if (!count($parts)) {
                $tableId = 't_0';
            } else {
                $key1 = implode('.', $parts);
                if (isset($queryData['path_map'][$key1])) {
                    $tableId = $queryData['path_map'][$key1];
                }
            }
        }

        if (!$tableId) {
            throw new Exception("Field $key doesn't found! In where clause!");
        }

        $metadata = $queryData['table_meta'][$tableId];
        $fields = $metadata->getFields();
        
        if ($field && !isset($fields[$field])) {
            throw new Exception("Field $key doesn't found! In where clause!");
        }

        if (!$field) {
            $field = $metadata->getPrimaryColumn();
        }
        
        return array($tableId, $metadata, $field);
    }
    
    private function getSelect(&$queryData, $metadata, $deep = array())
    {
        $select = array();
        $joins  = array();
        $tableId = "t_" . $queryData['table_counter']++;
        $tableMap  = &$queryData['table_map'];
        $pathMap   = &$queryData['path_map'];
        $tableMeta = &$queryData['table_meta'];
        $tableMap[$tableId] = $deep;
        $pathMap[implode('.', $deep)] = $tableId;
        $tableMeta[$tableId] = $metadata;
        
        foreach ($metadata->getFields() as $fName => $fMeta) {
            if (isset($fMeta['relation'])) {
                $path = $deep;
                $path[] = $fName;
                
                $fetch = isset($fMeta['relation']['fetch']) ?
                                $fMeta['relation']['fetch'] == 'eager' : 
                                $this->matchPath($queryData['deep_map'], $path);
                
                $relationColumn = $fMeta['relation']['column'];
                $innerTableId = "t_" . $queryData['table_counter'];
                $relationMetadata = $fMeta['relation']['meta'];
                
                $data = null;
                
                if ($fMeta['relation']['type'] == 'onetomany') {
                    if ($fetch) {
                        $pk = $metadata->getPrimaryColumn();
                        $joins[] = array('left', "{$relationMetadata->getTableName()} {$innerTableId}", "{$tableId}.{$pk} = {$innerTableId}.{$relationColumn}");
                        $data = $this->getSelect($queryData, $relationMetadata, $path);
                    }
                } else {
                    if ($fetch) {
                        $join = $fMeta['nullable'] ? 'left' : 'inner';
                        
                        $joins[] = array($join, "{$relationMetadata->getTableName()} {$innerTableId}", "{$tableId}.{$fMeta['column']} = {$innerTableId}.{$relationColumn}");
                        $data = $this->getSelect($queryData, $relationMetadata, $path);
                    } else {
                        $select[] = "{$tableId}.{$fMeta['column']} as {$innerTableId}:{$relationColumn}";
                        $queryData['table_counter']++;
                        $tableMap[$innerTableId] = $path;
                        $pathMap[implode('.', $path)] = $innerTableId;
                        $tableMeta[$innerTableId] = $relationMetadata;
                    }
                }

                if ($data) {
                    $select = array_merge($select, $data[0]);
                    $joins  = array_merge($joins, $data[1]);
                }
            } else {
                $select[] = "{$tableId}.{$fMeta['column']} as {$tableId}:{$fName}";
            }
        }
        
        return array($select, $joins);
    }
    
    private function getMappedColumnName(&$queryData, $path)
    {
        list($tableId, $metadata, $field) = $this->getMDataAndField($queryData, $path);
        
        if ($path != $this->metadata->getPrimaryColumn() &&
            $field == $metadata->getPrimaryColumn()) {
            $parts = $queryData['table_map'][$tableId];
            $parentField = array_pop($parts);
            list ($tableCl, $parentMeta, $_) = $this->getMDataAndField($queryData, implode('.', $parts));
            $fieldCl = $parentMeta->getFieldProperty($parentField, 'column');
        } else {
            $fields = $metadata->getFields();
            $tableCl = $tableId;
            $fieldCl = $fields[$field]['column'];
        }
        
        return array($tableCl, $fieldCl, $metadata);
    }
    
    private function replaceWhere(&$queryData, $where)
    {
        return preg_replace_callback("#(?P<string>'(?:''|[^'])*')|(?P<column>\"[a-z_][a-z0-9_]+\")#i", function ($matches) use (&$queryData) {
            if (isset($matches['column'])) {
                $mappedColumnName = $this->getMappedColumnName($queryData, $matches['column']);
                
                return "{$mappedColumnName[0]}.{$mappedColumnName[1]}";
            }
            
            return $matches['string'];
        }, $where);
    }
    
    private function getWhere(array &$queryData, array $where = null)
    {
        $whereRes = array();
        
        foreach ($where as $key => $value) {
            list($tableCl, $fieldCl, $metadata) = $this->getMappedColumnName($queryData, $key);
            $fields = $metadata->getFields();
            
            if (is_array($value)) {
                $result = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        $getter = $fields[$metadata->getPrimaryColumn()]['getter'];
                        $result[] = $item->$getter();
                    } else {
                        $result[] = $item;
                    }
                }
            } elseif (is_object($value)) {
                $getter = $fields[$metadata->getPrimaryColumn()]['getter'];
                $result = $value->$getter();
            } else {
                $result = $value;
            }
            
            if (is_array($result)) {
                $whereRes[] = array($tableCl, $fieldCl, 'in', $result);
            } else {
                $whereRes[] = array($tableCl, $fieldCl, '=', $result);
            }
        }
        
        return $whereRes;
    }
    
    private function getOrderBy(array &$queryData, array $orderBy = null)
    {
        if (!empty($orderBy)) {
            $orderByData = array();
            foreach ($orderBy as $key => $value) {
                list($tableId, $metadata, $field) = $this->getMDataAndField($queryData, $key);
                $fields = $metadata->getFields();
                
                $orderByData[] = array($tableId, $fields[$field]['column'], $value);
            }
            
            return $orderByData;
        }
        
        return null;
    }
    
    private function query(array &$context, array $where = array(), array $orderBy = array(), $limit = null, $offset = null)
    {
        $whereSql = array();
        $orderBySql = array();
        
        list ($select, $joins) = $this->getSelect($context, $this->metadata, array());
        
        if (!empty($where)) {
            $whereSql = $this->getWhere($context, $where);
        }
        
        if (!empty($orderBy)) {
            $orderBySql = $this->getOrderBy($context, $orderBy);
        }
        
        return $this->executeSelectQuery($select, $this->metadata->getTableName(), 't_0', $joins, $whereSql, $orderBySql, $limit, $offset);
    }
    
    private function getEntityUpdateData($entity, array $deep = null, $merge = false, array &$history = array(), $path = array())
    {
        $splHash = spl_object_hash($entity);
        if (isset($history[$splHash])) {
            return array();
        }
        
        $sql = array();
        $row = array();
        
        $meta = $this->entityManager->getEntityMetaData($entity);
        $history[$splHash] = true;
                
        $pkGetter = $meta->getPrimaryProperty('getter');
        $id = $entity->$pkGetter();
        $insertIdColumns = array();
        
        foreach ($meta->getFields() as $fName => $fMeta) {
            $getter = $fMeta['getter'];
            $column = $fMeta['column'];
            $value = $entity->$getter();
            
            if ($value !== null) {
                if (isset($fMeta['relation'])) {
                    if ($fMeta['relation']['type'] != 'onetomany') {
                        $getter = $fMeta['relation']['meta']->getPrimaryProperty('getter');
                        $tmpHash = spl_object_hash($value);
                        if ($value->$getter() !== null) {
                            $row[$column] = $value->$getter();
                        } elseif (!isset($history[$tmpHash])) {
                            $sql = array_merge($sql, $this->getEntityUpdateData($value, $deep, $merge, $history, $path));
                            $sql[] = array('lastInsertIdBegin', $meta->getTableName(), $column);
                            $insertIdColumns[] = $column;
                        }
                    }
                } else {
                    $row[$column] = $this->getTypeAsValue($value, $fMeta['type']);
                }
            } elseif (!$merge && !isset($fMeta['relation'])) {
                if (!$fMeta['primary']) {
                    $row[$column] = null;
                }
            }
        }
        
        if ($id) {
            $sql[] = array('update', $meta->getTableName(), $row, array($meta->getPrimaryColumn() => $id));
        } else {
            $sql[] = array('insert', $meta->getTableName(), $row, $entity);
        }
        
        if (!empty($insertIdColumns)) {
            foreach ($insertIdColumns as $column) {
                $sql[] = array('lastInsertIdEnd', $meta->getTableName(), $column);
            }
            
            $insertIdColumns = array();
        }
        
        foreach ($meta->getFields() as $fName => $fMeta) {
            if (isset($fMeta['relation']) && $fMeta['relation']['type'] == 'onetomany') {
                $getter = $fMeta['getter'];
                $value = $entity->$getter();
                
                if ($value !== null) {
                    $ids = array();
                    $relationMeta  = $fMeta['relation']['meta'];
                    $relationTable = $relationMeta->getTableName();
                    
                    $mappedSetter = $relationMeta->getFieldProperty(
                                        $fMeta['relation']['mapped-by'], 'setter');
                    
                    $mappedColumn = $relationMeta->getFieldProperty(
                                        $fMeta['relation']['mapped-by'], 'column');
                    
                    if ($id) {
                        $tmp = $this->executeSelectQuery(
                                array('t_0.id as t_0:id'),
                                $relationTable, 't_0', 
                                array(), array(array('t_0', $mappedColumn, '=', $id)), array(), null, null);

                        $ids = array();
                        foreach ($tmp as $relId) {
                            $ids[] = $relId['t_0:id'];
                        }
                    }

                    $idgetter = $relationMeta->getPrimaryProperty('getter');
                    
                    if (!$id) {
                        $sql[] = array('lastInsertIdBegin', $relationTable, $mappedColumn);
                    } 
                    
                    foreach ($value as $child) {
                        $idx = array_search($child->$idgetter(), $ids);
                        if ($idx !== false) {
                            unset($ids[$idx]);
                        }

                        $child->$mappedSetter($entity);

                        $sql = array_merge($sql, $this->getEntityUpdateData($child, $deep, $merge, $history, $path));
                    }
                    
                    foreach ($ids as $relId) {
                        $this->executeDeleteQuery($relationTable, array(
                            $relationMeta->getPrimaryColumn() => $relId
                        ));
                    }
                    
                    if (!$id) {
                        $sql[] = array('lastInsertIdEnd', $relationTable, $mappedColumn);
                    } 
                }
            }
        }
        
        return $sql;
    }
    
    private function runUpdatesInTransaction($updates)
    {
        $result = array();
        $stack = array();
        
        if (!count($updates)) {
            return ;
        }
        
        $transactionId = $this->executeBeginTransaction();
        $lastInsertId = null;
        
        foreach ($updates as $update) {
            $param = array();
            if (count($stack) > 0) {
                $top = $stack[count($stack) - 1];
                $param[$top['key']] = $top['value'];
            }
            
            if ('lastInsertIdBegin' == $update[0]) {
                array_push($stack, array(
                    'table' => $update[1],
                    'key' => $update[2],
                    'value' => $lastInsertId
                ));
                
                continue;
            }
            
            if ('lastInsertIdEnd' == $update[0]) {
                array_pop($stack);
                
                continue;
            }
            
            $table = $update[1];
            $row   = $param + $update[2];
            
            if ('insert' == $update[0]) {
                $this->executeInsertQuery($table, $row);
                $lastInsertId = $this->executeGetLastInsertId();
                $result[] = array('entity' => $update[3], 'id' => $lastInsertId);
            }
            
            if ('update' == $update[0]) {
                $this->executeUpdateQuery($table, $row, $update[3]);
            }
        }
        
        $this->executeCommitTransaction($transactionId);
        
        return $result;
    }
}
