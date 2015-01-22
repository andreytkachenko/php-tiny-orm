<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AT\ORM;

/**
 * Description of EntityManager
 *
 * @author Andrey Tkachenko
 */
class EntityManager
{
    protected $metadata = array();
    
    protected $elements;
    
    protected $log;
    
    public function __construct()
    {
        $this->elements = array();
    }
    
    /**
     * @param string $className
     * @return EntityRepository
     * @throws Exception
     */
    public function getRepository($className)
    {
        if (!class_exists($className)) {
            throw new Exception("EntityManager::getRepository: Class not found! $className");
        }
        
        return new EntityRepository($this, $this->getClassMetaData($className));
    }
    
    /**
     * @param string $class
     * @return EntityMetaData
     */
    public function getClassMetaData($class)
    {
        if (!isset($this->metadata[$class])) {
            $this->metadata[$class] = new EntityMetaData($this, $class);
            $this->metadata[$class]->loadMetadata();
        }
        
        return $this->metadata[$class];
    }
    
    /**
     * @param mixed $entity
     * @return EntityMetaData
     */
    public function getEntityMetaData($entity)
    {
        return $this->getClassMetaData(get_class($entity));
    }
    
    public function lookup($class, $id)
    {
        $result = $this->get($class, $id);
        
        return $result ? $result['obj'] : null;
    }
    
    public function get($class, $id)
    {
        $className = is_object($class) ? get_class($class) : $class;
        
        if (isset($this->elements[$className]) && 
            isset($this->elements[$className][$id])) {
            
            return $this->elements[$className][$id];
        }
        
        return null;
    }
    
    public function isChanged($entity)
    {
        $data = $this->get($entity, $entity->getId());
        
        if ($data === null) {
            return true;
        }
        
        return $data['hash'] != $this->hash($entity);
    }
    
    public function put($entity)
    {
        if (null !== $this->get($entity, $entity->getId())) {
            return;
        }
        
        $className = get_class($entity);
        
        $this->elements[$className][$entity->getId()] = array(
            'obj' => $entity,
            'hash' => $this->hash($entity)
        );
    }
    
    protected function getTypeAsValue($value, $type)
    {
        switch (strtolower($type)) {
            case 'int':
                return (int)$value;
            case 'string':
                return $value;
            case 'datetime':
                return $value->format('Y-m-d H:i:s');
            case 'array':
                return json_encode($value);
        }
    }
    
    public function hash($entity)
    {
        $arr = array();
        $meta = $this->getEntityMetaData($entity);
        foreach ($meta->getColumns() as $fName => $fMeta) {
            $getter = $fMeta['getter'];
            $value = $entity->$getter();
            if ($value !== null) {
                if (isset($fMeta['relation'])) {
                    if ($fMeta['relation']['type'] == 'onetomany') {
                        foreach ($value as $child) {
                            $arr[] = $this->hash($child);
                        }
                    } elseif ($fMeta['relation']['type'] == 'manytoone') {
                        $arr[] = $this->hash($value);
                    }
                } else {
                    $arr[] = $this->getTypeAsValue($value, $fMeta['type']);
                }
            }
        } 
        
        return sha1(implode(':', $arr));
    }
}
