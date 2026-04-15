<?php

namespace App\Domain\Event;

use App\Infrastructure\Overview\Overview;
use App\Infrastructure\Overview\Pagination;
use Doctrine\DBAL\Connection;

readonly class EventRepository
{
    public function __construct(
        private Connection $connection
    ) {
    }

    public function findAll(): Overview
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('*')
            ->from('events', 'c')
            ->orderBy('name', 'ASC');

        $results = $queryBuilder->executeQuery()->fetchAllAssociative();

        $overview = Overview::empty(Pagination::default(), count($results));
        foreach ($results as $result) {
            $overview->addItem(Event::fromState(
                id: $result['id'],
                name: $result['name'],
                format: $result['format'],
            ));
        }

        return $overview;
    }
}
