<?php

namespace AppBundle\Request\Datatables;

class Column implements \JsonSerializable
{
    /** @var int */
    private $id;
    /** @var string */
    private $data;
    /** @var string */
    private $name;
    /** @var bool */
    private $searchable;
    /** @var bool */
    private $orderable;
    /** @var Search */
    private $search;

    /**
     * @param int $id
     * @param string $data
     * @param string $name
     * @param bool $searchable
     * @param bool $orderable
     * @param Search $search
     */
    public function __construct(int $id, string $data, string $name, bool $searchable, bool $orderable, Search $search)
    {
        $this->id = $id;
        $this->data = $data;
        $this->name = $name;
        $this->searchable = $searchable;
        $this->orderable = $orderable;
        $this->search = $search;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return bool
     */
    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    /**
     * @return bool
     */
    public function isOrderable(): bool
    {
        return $this->orderable;
    }

    /**
     * @return Search
     */
    public function getSearch(): Search
    {
        return $this->search;
    }

    public function jsonSerialize()
    {
        return [
            'id' => $this->id,
            'data' => $this->data,
            'name' => $this->name,
            'searchable' => $this->searchable,
            'orderable' => $this->orderable,
            'search' => $this->search
        ];
    }
}
