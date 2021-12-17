<?php

declare(strict_types=1);

final class Db_PdoStatement extends PDOStatement
{
    private Db_Pdo $adapter;
    private ?int $queryId;

    protected function __construct(Db_Pdo $adapter, ?int $fetchMode = null)
    {
        $this->adapter = $adapter;

        if (null !== $fetchMode) {
            $this->setFetchMode($fetchMode);
        }

        $this->queryId = $this->adapter->getProfiler()->queryStart($this->queryString);
    }

    /**
     * @param null|array<int|string, mixed> $bound_input_params
     */
    public function execute(?array $bound_input_params = null): bool
    {
        if (null === $this->queryId) {
            return parent::execute($bound_input_params);
        }

        $prof = $this->adapter->getProfiler();
        $qp   = $prof->getQueryProfile($this->queryId);

        if ($qp->hasEnded()) {
            $this->queryId = $prof->queryClone($qp);
            $qp            = $prof->getQueryProfile($this->queryId);
        }

        if (! empty($bound_input_params)) {
            $qp->bindParams($bound_input_params);
        }

        $qp->start();

        $retval = parent::execute($bound_input_params);

        $prof->queryEnd($this->queryId);

        return $retval;
    }
}
