<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AT\ORM;

/**
 * Description of EntityMetaWrapper
 *
 * @author Andrey Tkachenko
 */
class EntityMetaWrapper
{
    private $entityManager;
    private $entity;
    private $entityMetadata;
    
    public function __construct(EntityManager $entityManager, $entity)
    {
        $this->entityManager = $entityManager;
        $this->entity = $entity;
        $this->entityMetadata = $this->entityManager->getEntityMetaData($entity);
    }
    
    public function getPropertyValue($name)
    {
        $fields = $this->entityMetadata->getFields();
        $getter = $fields[$name]['getter'];
        
        return $this->entity->$getter();
    }
    
    public function setPropertyValue($name, $value)
    {
        $fields = $this->entityMetadata->getFields();
        $setter = $fields[$name]['setter'];
        
        return $this->entity->$setter($value);
    }
    
    public function getId()
    {
        return $this->getPropertyValue($this->entityMetadata->getPrimaryColumn());
    }
}