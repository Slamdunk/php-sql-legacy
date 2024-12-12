<?php

declare(strict_types=1);

final class Db_ProfilerQuery
{
    private string $query;
    private int $queryType;
    private float $startedMicrotime;
    private ?float $endedMicrotime = null;

    /** @var array<int|string, mixed> */
    private array $boundParams = [];

    public function __construct(string $query, int $queryType)
    {
        $this->query     = $query;
        $this->queryType = $queryType;

        $this->start();
    }

    public function __clone()
    {
        $this->boundParams    = [];
        $this->endedMicrotime = null;

        $this->start();
    }

    public function start(): void
    {
        $this->startedMicrotime = \microtime(true);
    }

    public function end(): void
    {
        $this->endedMicrotime = \microtime(true);
    }

    public function hasEnded(): bool
    {
        return null !== $this->endedMicrotime;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getQueryType(): int
    {
        return $this->queryType;
    }

    public function bindParam(int|string $param, mixed $variable): void
    {
        $this->boundParams[$param] = $variable;
    }

    /** @param array<int|string, mixed> $params */
    public function bindParams(array $params): void
    {
        if (\array_key_exists(0, $params)) {
            \array_unshift($params, null);
            unset($params[0]);
        }
        foreach ($params as $param => $value) {
            $this->bindParam($param, $value);
        }
    }

    /** @return array<int|string, mixed> */
    public function getQueryParams(): array
    {
        return $this->boundParams;
    }

    public function getElapsedSecs(): bool|float
    {
        if (null === $this->endedMicrotime) {
            return false;
        }

        return $this->endedMicrotime - $this->startedMicrotime;
    }
}
