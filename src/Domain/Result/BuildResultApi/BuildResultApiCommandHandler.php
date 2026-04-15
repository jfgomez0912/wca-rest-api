<?php

namespace App\Domain\Result\BuildResultApi;

use App\Domain\Competition\CompetitionRepository;
use App\Domain\FileWriter;
use App\Domain\Result\Result;
use App\Domain\Result\ResultRepository;
use App\Infrastructure\Attribute\AsCommandHandler;
use App\Infrastructure\CQRS\CommandHandler\CommandHandler;
use App\Infrastructure\CQRS\DomainCommand;
use App\Infrastructure\Overview\Overview;
use App\Infrastructure\Overview\Pagination;
use App\Infrastructure\Serialization\Json;

#[AsCommandHandler]
readonly class BuildResultApiCommandHandler implements CommandHandler
{
    public function __construct(
        private ResultRepository $resultRepository,
        private CompetitionRepository $competitionRepository,
        private FileWriter $apiFileWriter
    ) {
    }

    public function handle(DomainCommand $command): void
    {
        assert($command instanceof BuildResultApi);

        $progressBar = $command->getProgressBar();
        $progressBar->start();
        $progressBar->setMaxSteps(
            $this->competitionRepository->findOneBy(Pagination::fromOffsetAndLimit(0, 1))->getTotal()
        );

        $pagination = Pagination::default();
        do {
            $competitions = $this->competitionRepository->findOneBy($pagination);

            /** @var \App\Domain\Competition\Competition $competition */
            foreach ($competitions->getItems() as $competition) {
                $allResults = $this->resultRepository->findOneBy(
                    $competition->getId()
                );

                if ($allResults->isEmpty()) {
                    $progressBar->advance();
                    continue;
                }

                $this->apiFileWriter->write(
                    sprintf('results/%s', $competition->getId()),
                    Json::encode($allResults)
                );

                /** @var array<string, Result[]> $byEvent */
                $byEvent = [];
                /** @var Result $result */
                foreach ($allResults->getItems() as $result) {
                    $byEvent[$result->getEventId()][] = $result;
                }

                foreach ($byEvent as $eventId => $eventResults) {
                    $eventOverview = Overview::empty(
                        Pagination::fromPageNumberAndSize(1, count($eventResults)),
                        count($eventResults)
                    );
                    foreach ($eventResults as $result) {
                        $eventOverview->addItem($result);
                    }
                    $this->apiFileWriter->write(
                        sprintf('results/%s/%s', $competition->getId(), $eventId),
                        Json::encode($eventOverview)
                    );
                }

                $progressBar->advance();
            }

            $pagination = $pagination->next();
        } while (($pagination->getPageNumber() - 1) * $pagination->getPageSize() < $competitions->getTotal());
        $progressBar->finish();
    }
}
