<?php

declare(strict_types=1);

final class Db_PdoStatement extends PDOStatement
{
    protected $adapter;

    protected $queryId;

    protected function __construct($adapter, $fetchMode = '')
    {
        $this->adapter = $adapter;

        if (! empty($fetchMode)) {
            $this->setFetchMode($fetchMode);
        }

        $this->queryId = $this->adapter->getProfiler()->queryStart($this->queryString);
    }

    public function execute($bound_input_params = null)
    {
        if (null === $this->queryId) {
            return parent::execute($bound_input_params);
        }

        $prof = $this->adapter->getProfiler();
        $qp = $prof->getQueryProfile($this->queryId);

        if ($qp->hasEnded()) {
            $this->queryId = $prof->queryClone($qp);
            $qp = $prof->getQueryProfile($this->queryId);
        }

        if (! empty($bound_input_params)) {
            $qp->bindParams($bound_input_params);
        }

        $qp->start($this->queryId);

        $retval = parent::execute($bound_input_params);

        $prof->queryEnd($this->queryId);

        return $retval;
    }
}
