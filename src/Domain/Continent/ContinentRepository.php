<?php

namespace App\Domain\Continent;

use App\Infrastructure\Overview\Overview;
use App\Infrastructure\Overview\Pagination;
use Doctrine\DBAL\Connection;

readonly class ContinentRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findAll(): Overview
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('*')
            ->from('continents', 'c')
            ->orderBy('id', 'ASC');

        $results = $queryBuilder->executeQuery()->fetchAllAssociative();

        $overview = Overview::empty(Pagination::default(), count($results));
        foreach ($results as $result) {
            $overview->addItem(Continent::fromState(
                $result['id'],
                $result['name'],
            ));
        }

        return $overview;
    }
}
