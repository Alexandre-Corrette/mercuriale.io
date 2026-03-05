<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\FactureFournisseur;
use App\Enum\SourceFacture;
use App\Enum\StatutFacture;
use App\Message\ProcessFactureOcrMessage;
use App\MessageHandler\ProcessFactureOcrHandler;
use App\Service\Ocr\FactureExtractorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class ProcessFactureOcrHandlerTest extends TestCase
{
    private MockObject&FactureExtractorService $extractorService;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&LoggerInterface $logger;
    private ProcessFactureOcrHandler $handler;

    protected function setUp(): void
    {
        $this->extractorService = $this->createMock(FactureExtractorService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new ProcessFactureOcrHandler(
            $this->extractorService,
            $this->entityManager,
            $this->logger,
        );
    }

    public function testHandleMissingFacture(): void
    {
        $factureId = Uuid::v4()->toRfc4122();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->extractorService->expects($this->never())->method('extract');

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('introuvable'));

        ($this->handler)(new ProcessFactureOcrMessage($factureId));
    }

    public function testSkipNonBrouillonFacture(): void
    {
        $facture = $this->createFacture(StatutFacture::RECUE);
        $factureId = $facture->getIdAsString();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($facture);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->extractorService->expects($this->never())->method('extract');

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('non BROUILLON'));

        ($this->handler)(new ProcessFactureOcrMessage($factureId));
    }

    public function testHandleExtractionSuccess(): void
    {
        $facture = $this->createFacture(StatutFacture::BROUILLON);
        $factureId = $facture->getIdAsString();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($facture);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->extractorService
            ->expects($this->once())
            ->method('extract')
            ->with($facture)
            ->willReturn(['success' => true, 'warnings' => []]);

        // Don't constrain logger — just verify extraction was called
        ($this->handler)(new ProcessFactureOcrMessage($factureId));
    }

    public function testHandleExtractionFailure(): void
    {
        $facture = $this->createFacture(StatutFacture::BROUILLON);
        $factureId = $facture->getIdAsString();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($facture);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->extractorService
            ->expects($this->once())
            ->method('extract')
            ->with($facture)
            ->willReturn(['success' => false, 'warnings' => ['API timeout']]);

        $this->entityManager->expects($this->once())->method('flush');

        ($this->handler)(new ProcessFactureOcrMessage($factureId));

        // Facture should stay BROUILLON and have a comment
        $this->assertSame(StatutFacture::BROUILLON, $facture->getStatut());
        $this->assertNotNull($facture->getOcrProcessedAt());
        $this->assertStringContains('Échec OCR', $facture->getCommentaire());
    }

    public function testMessageIsReadonly(): void
    {
        $id = Uuid::v4()->toRfc4122();
        $message = new ProcessFactureOcrMessage($id);

        $this->assertSame($id, $message->factureId);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function createFacture(StatutFacture $statut): FactureFournisseur
    {
        $facture = new FactureFournisseur();
        $facture->setSource(SourceFacture::UPLOAD_OCR);
        $facture->setStatut($statut);

        return $facture;
    }

    private static function assertStringContains(string $needle, ?string $haystack): void
    {
        self::assertNotNull($haystack);
        self::assertStringContainsString($needle, $haystack);
    }
}
