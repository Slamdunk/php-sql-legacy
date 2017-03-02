<?php

declare(strict_types=1);

final class Db_Profiler
{
    const CONNECT = 1;
    const QUERY = 2;
    const INSERT = 4;
    const UPDATE = 8;
    const DELETE = 16;
    const SELECT = 32;
    const TRANSACTION = 64;
    const STORED = 'stored';
    const IGNORED = 'ignored';

    private static $map = array(
        'insert' => self::INSERT,
        'update' => self::UPDATE,
        'delete' => self::DELETE,
        'select' => self::SELECT,
    );

    private $queryProfiles = array();

    private $enabled = false;

    public function getEnabled()
    {
        return $this->enabled;
    }

    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;

        return $this;
    }

    public function clear()
    {
        $this->queryProfiles = array();

        return $this;
    }

    public function queryClone(Db_ProfilerQuery $query)
    {
        $this->queryProfiles[] = clone $query;

        end($this->queryProfiles);

        return key($this->queryProfiles);
    }

    public function queryStart($queryText, $queryType = null)
    {
        if (! $this->enabled) {
            return;
        }

        if (null === $queryType) {
            $queryType = self::QUERY;
            $guess = strtolower(substr(ltrim($queryText), 0, 6));
            if (isset(self::$map[$guess])) {
                $queryType = self::$map[$guess];
            }
        }

        $this->queryProfiles[] = new Db_ProfilerQuery($queryText, $queryType);

        end($this->queryProfiles);

        return key($this->queryProfiles);
    }

    public function queryEnd($queryId)
    {
        if (! $this->enabled) {
            return self::IGNORED;
        }

        if (! isset($this->queryProfiles[$queryId])) {
            throw new Db_Exception("Profiler has no query with handle '$queryId'.");
        }

        $qp = $this->queryProfiles[$queryId];

        if ($qp->hasEnded()) {
            throw new Db_Exception("Query with profiler handle '$queryId' has already ended.");
        }

        $qp->end();

        return self::STORED;
    }

    public function getQueryProfile($queryId)
    {
        if (! array_key_exists($queryId, $this->queryProfiles)) {
            throw new Db_Exception("Query handle '$queryId' not found in profiler log.");
        }

        return $this->queryProfiles[$queryId];
    }

    public function getQueryProfiles($queryType = null, $showUnfinished = false)
    {
        $queryProfiles = array();
        foreach ($this->queryProfiles as $key => $qp) {
            if ($queryType === null) {
                $condition = true;
            } else {
                $condition = ($qp->getQueryType() & $queryType);
            }

            if (($qp->hasEnded() || $showUnfinished) && $condition) {
                $queryProfiles[$key] = $qp;
            }
        }

        return $queryProfiles;
    }

    public function getTotalElapsedSecs($queryType = null)
    {
        $elapsedSecs = 0;
        foreach ($this->queryProfiles as $key => $qp) {
            if (null === $queryType) {
                $condition = true;
            } else {
                $condition = ($qp->getQueryType() & $queryType);
            }
            if (($qp->hasEnded()) && $condition) {
                $elapsedSecs += $qp->getElapsedSecs();
            }
        }

        return $elapsedSecs;
    }

    public function getTotalNumQueries($queryType = null)
    {
        if (null === $queryType) {
            return count($this->queryProfiles);
        }

        $numQueries = 0;
        foreach ($this->queryProfiles as $qp) {
            if ($qp->hasEnded() && ($qp->getQueryType() & $queryType)) {
                ++$numQueries;
            }
        }

        return $numQueries;
    }

    public function getLastQueryProfile()
    {
        if (empty($this->queryProfiles)) {
            return false;
        }

        end($this->queryProfiles);

        return current($this->queryProfiles);
    }
}
