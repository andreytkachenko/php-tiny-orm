# PHP Tiny ORM
## Just implement those methods and use ORM
```
    protected function executeSelectQuery(array $select, $from, $fromAs, array $joins, array $whereSql, array $orderBySql, $limit, $offset)
    {
        $index = 0;

        $qb = $this->connection->createCommand()
                ->select($select)
                ->from("{$from} as {$fromAs}");

        foreach ($joins as $join) {
            if ($join[0] == 'inner') {
                $qb->join($join[1], $join[2]);
            } elseif ($join[0] == 'left') {
                $qb->leftJoin($join[1], $join[2]);
            }
        }
        
        foreach ($whereSql as $condition) {
            $qb->andWhere("`{$condition[0]}`.`{$condition[1]}` {$condition[2]} :p_{$index}", array(":p_{$index}" => $condition[3]));
            $index++;
        }
        
        if (!empty($orderBySql)) {
            $qb->order(array_map(function ($orderBy) {
                return "{$orderBy[0]}.{$orderBy[1]} {$orderBy[2]}";
            }, $orderBySql));
        }
                
        if ($limit) {
            $qb->limit($limit);
        }
        
        if ($offset) {
            $qb->offset($offset);
        }
                
        return $qb->queryAll();
    }
    
    protected function executeGetLastInsertId()
    {
        return $this->connection->getLastInsertID();
    }
    
    protected function executeBeginTransaction()
    {
        $transaction = $this->connection->beginTransaction();
        $index = count($this->openedTransactions);
        $this->openedTransactions[$index] = $transaction;
        
        return $index;
    }
    
    protected function executeCommitTransaction($transaction)
    {
        $idx = array_search($transaction, $this->openedTransactions);
        
        $transaction->commit();
        
        if ($idx !== false) {
            unset($this->openedTransactions[$idx]);
        }
    }
    
    protected function executeRollbackTransaction($transaction)
    {
        $idx = array_search($transaction, $this->openedTransactions);
        
        $transaction->rollback();
        
        if ($idx !== false) {
            unset($this->openedTransactions[$idx]);
        }
    }
    
    protected function executeUpdateQuery($table, $data, $where)
    {
        $whereSql = array();
        $params   = array();
        
        $index = 0;
        foreach ($where as $key => $value) {
            $whereSql[] = "`{$key}` = :u_$index";
            $params[":u_$index"] = $value;
        }
        
        $this->connection->createCommand()->update($table, $data, implode(' AND ', $whereSql), $params);
    }
    
    protected function executeInsertQuery($into, $data)
    {
        $this->connection->createCommand()->insert($into, $data);
    }
    
    protected function executeDeleteQuery($from, $where)
    {
        $whereSql = array();
        $params   = array();
        
        $index = 0;
        foreach ($where as $key => $value) {
            $whereSql[] = "`{$key}` = :d_$index";
            $params[":d_$index"] = $value;
        }
        
        return $this->connection->createCommand()->delete($from, implode(' AND ', $whereSql), $params);
    }
```
