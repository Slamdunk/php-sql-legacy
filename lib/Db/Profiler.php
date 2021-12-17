<?php

declare(strict_types=1);

final class Db_Profiler
{
    public const CONNECT     = 1;
    public const QUERY       = 2;
    public const INSERT      = 4;
    public const UPDATE      = 8;
    public const DELETE      = 16;
    public const SELECT      = 32;
    public const TRANSACTION = 64;
    public const STORED      = 'stored';
    public const IGNORED     = 'ignored';

    private const MAP = [
        'insert' => self::INSERT,
        'update' => self::UPDATE,
        'delete' => self::DELETE,
        'select' => self::SELECT,
    ];

    /**
     * @var array<int, Db_ProfilerQuery>
     */
    private array $queryProfiles = [];
    private bool $enabled        = false;

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function clear(): self
    {
        $this->queryProfiles = [];

        return $this;
    }

    public function queryClone(Db_ProfilerQuery $query): int
    {
        $this->queryProfiles[] = clone $query;

        \end($this->queryProfiles);
        $key = \key($this->queryProfiles);
        \assert(\is_int($key));

        return $key;
    }

    public function queryStart(string $queryText, ?int $queryType = null): ?int
    {
        if (! $this->enabled) {
            return null;
        }

        if (null === $queryType) {
            $queryType = self::QUERY;
            $guess     = \strtolower(\substr(\ltrim($queryText), 0, 6));
            if (isset(self::MAP[$guess])) {
                $queryType = self::MAP[$guess];
            }
        }

        $this->queryProfiles[] = new Db_ProfilerQuery($queryText, $queryType);

        \end($this->queryProfiles);

        return \key($this->queryProfiles);
    }

    public function queryEnd(?int $queryId): string
    {
        if (! $this->enabled) {
            return self::IGNORED;
        }

        if (! isset($this->queryProfiles[$queryId])) {
            throw new Db_Exception("Profiler has no query with handle '${queryId}'.");
        }

        $qp = $this->queryProfiles[$queryId];

        if ($qp->hasEnded()) {
            throw new Db_Exception("Query with profiler handle '${queryId}' has already ended.");
        }

        $qp->end();

        return self::STORED;
    }

    public function getQueryProfile(int $queryId): Db_ProfilerQuery
    {
        if (! \array_key_exists($queryId, $this->queryProfiles)) {
            throw new Db_Exception("Query handle '${queryId}' not found in profiler log.");
        }

        return $this->queryProfiles[$queryId];
    }

    /**
     * @return array<int, Db_ProfilerQuery>
     */
    public function getQueryProfiles(?int $queryType = null, bool $showUnfinished = false): array
    {
        $queryProfiles = [];
        foreach ($this->queryProfiles as $key => $qp) {
            if (null === $queryType) {
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

    public function getTotalElapsedSecs(?int $queryType = null): float
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

    public function getTotalNumQueries(?int $queryType = null): int
    {
        if (null === $queryType) {
            return \count($this->queryProfiles);
        }

        $numQueries = 0;
        foreach ($this->queryProfiles as $qp) {
            if ($qp->hasEnded() && ($qp->getQueryType() & $queryType)) {
                ++$numQueries;
            }
        }

        return $numQueries;
    }

    public function getLastQueryProfile(): ?Db_ProfilerQuery
    {
        if ([] === $this->queryProfiles) {
            return null;
        }

        \end($this->queryProfiles);

        return \current($this->queryProfiles);
    }
}
