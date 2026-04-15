<?php

namespace App\Domain\Continent\Country;

use App\Infrastructure\Overview\Overview;
use App\Infrastructure\Overview\Pagination;
use Doctrine\DBAL\Connection;

readonly class CountryRepository
{
    public function __construct(
        private Connection $connection
    ) {
    }

    public function findAll(): Overview
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('*')
            ->from('countries', 'c')
            ->orderBy('iso2', 'ASC');

        $results = $queryBuilder->executeQuery()->fetchAllAssociative();

        $overview = Overview::empty(Pagination::default(), count($results));
        foreach ($results as $result) {
            $overview->addItem(Country::fromState(
                Iso2Code::fromString($result['iso2']),
                $result['name'],
            ));
        }

        return $overview;
    }
}
