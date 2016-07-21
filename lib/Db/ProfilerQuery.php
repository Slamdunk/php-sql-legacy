<?php

final class Db_ProfilerQuery
{
    private $query;

    private $queryType;

    private $startedMicrotime;

    private $endedMicrotime;

    private $boundParams = array();

    public function __construct($query, $queryType)
    {
        $this->query = $query;
        $this->queryType = $queryType;

        $this->start();
    }

    public function __clone()
    {
        $this->boundParams = array();
        $this->endedMicrotime = null;

        $this->start();
    }

    public function start()
    {
        $this->startedMicrotime = microtime(true);
    }

    public function end()
    {
        $this->endedMicrotime = microtime(true);
    }

    public function hasEnded()
    {
        return $this->endedMicrotime !== null;
    }

    public function getQuery()
    {
        return $this->query;
    }

    public function getQueryType()
    {
        return $this->queryType;
    }

    public function bindParam($param, $variable)
    {
        $this->boundParams[$param] = $variable;
    }

    public function bindParams(array $params)
    {
        if (array_key_exists(0, $params)) {
            array_unshift($params, null);
            unset($params[0]);
        }
        foreach ($params as $param => $value) {
            $this->bindParam($param, $value);
        }
    }

    public function getQueryParams()
    {
        return $this->boundParams;
    }

    public function getElapsedSecs()
    {
        if (null === $this->endedMicrotime) {
            return false;
        }

        return $this->endedMicrotime - $this->startedMicrotime;
    }
}
