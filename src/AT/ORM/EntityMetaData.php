<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AT\ORM;

/**
 * Description of EntityMetaData
 *
 * @author Andrey Tkachenko
 */
class EntityMetaData 
{
    private $entityManager;
    private $className;
    private $columns;
    private $fields;
    private $tableName;
    private $primaryColumn;
    
    public function __construct($entityManager, $className)
    {
        if (!class_exists($className)) {
            throw new Exception("Class doesn't exists $className");
        }
        
        $this->entityManager = $entityManager;
        $this->className = $className;
    }
    
    public function getPrimaryColumn()
    {
        return $this->primaryColumn;
    }
    
    public function getColumns()
    {
        return $this->columns;
    }
    
    public function getClassName()
    {
        return $this->className;
    }
    
    public function getTableName()
    {
        return $this->tableName;
    }
    
    public function getFields()
    {
        return $this->fields;
    }
    
    public function getFieldProperty($field, $property)
    {
        return $this->fields[$field][$property];
    }
    
    public function getPrimaryProperty($property)
    {
        $field = $this->getPrimaryColumn();
        
        return $this->fields[$field][$property];
    }
    
    public function loadMetadata()
    {
        $className = $this->className;
        if (!isset($className::$meta)) {
            throw new Exception("Cannot read class metadata $className");
        }
        
        $meta = $className::$meta;
        if (!isset($meta['table'])) {
            throw new Exception("Class Metadata doesn't contains 'table' - $className");
        }
        
        $this->tableName = $meta['table'];
        
        if (!isset($meta['columns']) || !is_array($meta['columns'])) {
            throw new Exception("Class Metadata doesn't contains 'fields' - $className");
        }
        
        $methods = get_class_methods($className);
        
        foreach ($meta['columns'] as $fName => $fMeta) {
            if (isset($fMeta['primary']) && $fMeta['primary']) {
                if ($this->primaryColumn) {
                    throw new Exception("$className: Second primary column found - $fName! Previous - {$this->primaryColumn}");
                }
                $this->primaryColumn = $fName;
            }
            
            $setter = isset($fMeta['setter']) ? $fMeta['setter'] : 'set' . ucfirst($fName);
            $getter = isset($fMeta['getter']) ? $fMeta['getter'] : 'get' . ucfirst($fName);
            
            if (!in_array($setter, $methods)) {
                throw new Exception("Class $className doesn't contains setter - $setter!");
            }
            
            if (!in_array($getter, $methods)) {
                throw new Exception("Class $className doesn't contains getter - $getter!");
            }
            
            $columnName = isset($fMeta['name']) ? $fMeta['name'] : $fName;
            
            $relType = isset($fMeta['relation']) && isset($fMeta['relation']['type']) ? 
                        strtolower($fMeta['relation']['type']) : null;
            
            $columnProperties = array(
                'nullable' => isset($fMeta['nullable']) ? (bool)$fMeta['nullable'] : false,
                'type' => $this->getColumnType($fMeta, $fName, $relType),
                'column' => $columnName,
                'field' => $fName,
                'setter' => $setter,
                'getter' => $getter,
                'primary' => (isset($fMeta['primary']) && $fMeta['primary']) ? true : false
            );
            
            $this->columns[$columnName] = $columnProperties;
            $this->fields[$fName] = $columnProperties;
            
            $relation = $this->getColumnRelation($fMeta, $fName);
            
            if (!empty($relation)) {
                $this->columns[$columnName]['relation'] = $relation;
                $this->fields[$fName]['relation'] = $relation;
            }
        }

        if (!$this->primaryColumn) {
            throw new Exception("$className: No primary column found!");
        }
    }
    
    private function getColumnRelation($meta, $field)
    {
        if (isset($meta['relation'])) {
            
            $relation = $meta['relation'];
            
            if (!isset($relation['class']) || empty($relation['class'])) {
                throw new Exception("Relation should have class in {$this->className}::{$field}");
            }
            
            if (!isset($relation['type']) || empty($relation['type'])) {
                throw new Exception("Relation should have type in {$this->className}::{$field}");
            }
            
            $relationType = strtolower($relation['type']);
            
            if (!in_array($relationType, array('onetomany', 'manytoone')) ) {
                throw new Exception("Unsupported relation type in {$this->className}::{$field}. OneToMany and ManyToOne are supported");
            }
            
            $relationMetadata = $this->entityManager->getClassMetaData($relation['class']);
            
            $mappedBy = null;
            if ($relationType == 'onetomany') {
                $relColumns = $relationMetadata->getColumns();
                
                if (!isset($relColumns[$relation['column']]) || !isset($relColumns[$relation['column']]['field'])) {
                    throw new Exception("Mapped Column Not Defined in {$relationMetadata->getClassName()} for {$this->className}::{$field}");
                }
                
                $mappedBy = $relColumns[$relation['column']]['field'];
            }
            
            return array(
                'type' => $relationType,
                'class' => $relation['class'],
                'meta' => $relationMetadata,
                'mapped-by' => $mappedBy,
                'column' => isset($relation['column']) ? $relation['column'] : 
                    ($relationType == 'manytoone' ? "{$relationMetadata->getPrimaryColumn()}" : "{$relationMetadata->getTableName()}_id")
            );
        } else {
            return null;
        }
    }

    private function getColumnName($meta, $default, $relation)
    {
        if (empty($relation)) {
            return isset($meta['name']) ? $meta['name'] : $default;
        } elseif ($relation['type'] == 'manytoone') {
            return isset($meta['name']) ? $meta['name'] : "{$relation['meta']->getTableName()}_id";
        }
    }
    
    private function getColumnType($fMeta, $field, $relationType)
    {
        if ($relationType == 'manytoone') {
            return 'int';
        } elseif ($relationType === null) {
            if (!isset($fMeta['type']) || empty($fMeta['type'])) {
                throw new Exception("Column Type is empty {$this->className}::{$field}");
            }
            
            $type = strtolower($fMeta['type']);
            
            if (!in_array($type, array(
                    'int', 'date', 'datetime', 'float', 
                    'real', 'array', 'string', 'bool'
                ))) {
                
                throw new Exception("Unknown Column Type: {$fMeta['type']} in {$this->className}::{$field}");
            }
            
            return $type;
        }
    }
}