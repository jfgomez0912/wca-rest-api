<?php

namespace App\Domain\Competition\Championship;

use App\Domain\Competition\CompetitionRepository;
use App\Infrastructure\Overview\Overview;
use App\Infrastructure\Overview\Pagination;
use Doctrine\DBAL\Connection;

readonly class ChampionshipRepository
{
    public function __construct(
        private Connection $connection,
        private CompetitionRepository $competitionRepository,
    ) {
    }

    public function findOneBy(
        Pagination $pagination,
        string $championshipType = null
    ): Overview {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('SQL_CALC_FOUND_ROWS champ.*')
            ->from('championships', 'champ')
            ->innerJoin('champ', 'competitions', 'comp', 'champ.competition_id = comp.id')
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->getLimit())
            ->addOrderBy('comp.year', 'DESC')
            ->addOrderBy('comp.month', 'DESC')
            ->addOrderBy('comp.day', 'DESC');

        if ($championshipType) {
            $queryBuilder->andWhere('champ.championship_type = :type');
            $queryBuilder->setParameter('type', $championshipType);
        }

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

        foreach ($results as $result) {
            $overview->addItem($this->buildResult($result));
        }

        return $overview;
    }

    /**
     * @return string[]
     */
    public function findChampionshipIdsByPerson(string $personId): array
    {
        $query = '
            SELECT competition_id
            FROM championships champ
            WHERE champ.competition_id IN (SELECT DISTINCT competition_id FROM results WHERE person_id = :personId)
        ';

        return $this->connection->executeQuery($query, [
            'personId' => $personId,
        ])->fetchFirstColumn();
    }

    /**
     * @param string[] $personIds
     *
     * @return array<string, string[]>
     */
    public function findChampionshipIdsByPersons(array $personIds): array
    {
        if (empty($personIds)) {
            return [];
        }

        $query = '
            SELECT r.person_id, champ.competition_id
            FROM championships champ
            INNER JOIN results r ON champ.competition_id = r.competition_id
            WHERE r.person_id IN (?)
            GROUP BY r.person_id, champ.competition_id
        ';

        $rows = $this->connection->executeQuery(
            $query,
            [$personIds],
            [Connection::PARAM_STR_ARRAY]
        )->fetchAllAssociative();

        /** @var array<string, string[]> $map */
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['person_id']][] = (string) $row['competition_id'];
        }

        return $map;
    }

    /**
     * @param array<mixed> $result
     */
    private function buildResult(array $result): Championship
    {
        return Championship::fromCompetitionAndRegion(
            $this->competitionRepository->find($result['competition_id']),
            $result['championship_type'],
        );
    }
}
