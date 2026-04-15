<?php

namespace App\Domain\Person;

use App\Domain\Competition\Championship\ChampionshipRepository;
use App\Domain\Competition\CompetitionRepository;
use App\Domain\Continent\Country\Iso2Code;
use App\Domain\Rank\RankRepository;
use App\Domain\Result\ResultRepository;
use App\Infrastructure\Overview\Overview;
use App\Infrastructure\Overview\Pagination;
use Doctrine\DBAL\Connection;

readonly class PersonRepository
{
    public function __construct(
        private Connection $connection,
        private RankRepository $rankRepository,
        private CompetitionRepository $competitionRepository,
        private ChampionshipRepository $championshipRepository,
        private ResultRepository $resultRepository,
    ) {
    }

    public function findOneBy(
        Pagination $pagination,
    ): Overview {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('SQL_CALC_FOUND_ROWS p.*, c.iso2')
            ->from('persons', 'p')
            ->innerJoin('p', 'countries', 'c', 'p.country_id = c.id')
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->getLimit())
            ->andWhere('sub_id = 1')
            ->addOrderBy('wca_id', 'ASC');

        $results = $queryBuilder->executeQuery()->fetchAllAssociative();
        $total = $this->connection->executeQuery('SELECT FOUND_ROWS() as total;')->fetchOne();

        if (0 === count($results)) {
            return Overview::empty(Pagination::default());
        }

        $overview = Overview::empty(
            count($results) == $pagination->getPageSize() ? $pagination : $pagination::fromPageNumberAndSize(
                $pagination->getPageNumber(),
                count($results)
            ),
            $total
        );

        $personIds = array_column($results, 'wca_id');
        $competitionIdsMap = $this->competitionRepository->findCompetitionIdsByPersons($personIds);
        $ranksMap = $this->rankRepository->findByPersons($personIds);
        $resultsMap = $this->resultRepository->findByPersons($personIds);
        $championshipIdsMap = $this->championshipRepository->findChampionshipIdsByPersons($personIds);

        foreach ($results as $result) {
            $wcaId = $result['wca_id'];
            $overview->addItem(Person::fromState(
                id: $wcaId,
                name: $result['name'],
                country: Iso2Code::fromString($result['iso2']),
                competitionIds: $competitionIdsMap[$wcaId] ?? [],
                ranks: $ranksMap[$wcaId] ?? [],
                results: $resultsMap[$wcaId] ?? [],
                championshipIds: $championshipIdsMap[$wcaId] ?? [],
            ));
        }

        return $overview;
    }
}
