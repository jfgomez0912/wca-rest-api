<?php

namespace App\Domain\Rank\BuildRankApi;

use App\Domain\Continent\ContinentRepository;
use App\Domain\Event\EventRepository;
use App\Domain\FileWriter;
use App\Domain\Rank\Rank;
use App\Domain\Rank\RankRepository;
use App\Domain\Rank\RankType;
use App\Infrastructure\Attribute\AsCommandHandler;
use App\Infrastructure\CQRS\CommandHandler\CommandHandler;
use App\Infrastructure\CQRS\DomainCommand;
use App\Infrastructure\Overview\Overview;
use App\Infrastructure\Overview\Pagination;
use App\Infrastructure\Serialization\Json;

#[AsCommandHandler]
readonly class BuildRankApiCommandHandler implements CommandHandler
{
    public function __construct(
        private RankRepository $rankRepository,
        private EventRepository $eventRepository,
        private ContinentRepository $continentRepository,
        private FileWriter $apiFileWriter,
    ) {
    }

    public function handle(DomainCommand $command): void
    {
        assert($command instanceof BuildRankApi);

        $progressBar = $command->getProgressBar();
        $progressBar->start();

        $events = $this->eventRepository->findAll();
        $continents = $this->continentRepository->findAll();

        /** @var array<string, string> */
        $continentSlugMap = [];
        /** @var \App\Domain\Continent\Continent $continent */
        foreach ($continents->getItems() as $continent) {
            $continentSlugMap[$continent->getId()] = (string) $continent->getSlug();
        }

        $pageSize = Pagination::default()->getPageSize();

        $progressBar->setMaxSteps(count(RankType::cases()) * $events->getTotal() + 1);
        $progressBar->advance();

        foreach (RankType::cases() as $rankType) {
            /** @var \App\Domain\Event\Event $event */
            foreach ($events->getItems() as $event) {
                $allRows = $this->rankRepository->findAllForEvent($rankType, $event->getId());

                if (empty($allRows)) {
                    $progressBar->advance();
                    continue;
                }

                $worldRows = array_filter($allRows, fn (array $r) => 0 != $r['world_rank']);
                usort($worldRows, fn (array $a, array $b) => $a['world_rank'] <=> $b['world_rank']);
                $this->writeRankFile(
                    sprintf('rank/%s/%s/%s', 'world', $rankType->value, $event->getId()),
                    $worldRows,
                    $pageSize,
                    $rankType
                );

                $byCountry = [];
                foreach ($allRows as $row) {
                    if (0 != $row['country_rank']) {
                        $byCountry[$row['iso2']][] = $row;
                    }
                }
                foreach ($byCountry as $iso2 => $rows) {
                    usort($rows, fn (array $a, array $b) => $a['country_rank'] <=> $b['country_rank']);
                    $this->writeRankFile(
                        sprintf('rank/%s/%s/%s', $iso2, $rankType->value, $event->getId()),
                        $rows,
                        $pageSize,
                        $rankType
                    );
                }

                $byContinent = [];
                foreach ($allRows as $row) {
                    if (0 != $row['continent_rank'] && isset($continentSlugMap[$row['continent_id']])) {
                        $byContinent[$row['continent_id']][] = $row;
                    }
                }
                foreach ($byContinent as $continentId => $rows) {
                    usort($rows, fn (array $a, array $b) => $a['continent_rank'] <=> $b['continent_rank']);
                    $this->writeRankFile(
                        sprintf('rank/%s/%s/%s', $continentSlugMap[$continentId], $rankType->value, $event->getId()),
                        $rows,
                        $pageSize,
                        $rankType
                    );
                }

                $progressBar->advance();
            }
        }
        $progressBar->finish();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeRankFile(string $path, array $rows, int $pageSize, RankType $rankType): void
    {
        if (empty($rows)) {
            return;
        }

        $total = count($rows);
        $rows = array_slice($rows, 0, $pageSize);

        $overview = Overview::empty(
            Pagination::fromPageNumberAndSize(1, count($rows)),
            $total
        );

        foreach ($rows as $row) {
            $overview->addItem(Rank::fromState(
                rankType: $rankType,
                personId: $row['person_id'],
                eventId: $row['event_id'],
                best: $row['best'],
                worldRank: $row['world_rank'],
                continentRank: $row['continent_rank'],
                countryRank: $row['country_rank'],
            ));
        }

        $this->apiFileWriter->write($path, Json::encode($overview));
    }
}
