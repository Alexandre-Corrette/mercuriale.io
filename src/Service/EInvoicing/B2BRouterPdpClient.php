<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * B2Brouter implementation of the PDP client.
 *
 * @see https://app.b2brouter.net/api/v3
 */
class B2BRouterPdpClient implements PdpClientInterface
{
    private const TIMEOUT_SHORT = 10;
    private const TIMEOUT_LONG = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiUrl,
        private readonly string $apiKey,
    ) {
    }

    public function registerCompany(
        string $vatNumber,
        string $companyName,
        string $address,
        string $postalCode,
        string $city,
        string $country = 'FR',
    ): string {
        $data = $this->request('POST', '/accounts', [
            'tin_type' => 'fr-vat',
            'tin_value' => $vatNumber,
            'name' => $companyName,
            'address' => $address,
            'postal_code' => $postalCode,
            'city' => $city,
            'country' => $country,
        ], self::TIMEOUT_SHORT);

        if (!isset($data['id'])) {
            throw PdpApiException::invalidResponse('Champ "id" manquant dans la réponse de création de compte.');
        }

        $this->logger->info('[EInvoicing] Compte B2Brouter créé', [
            'account_id' => $data['id'],
            'company' => $companyName,
        ]);

        return (string) $data['id'];
    }

    public function enableReception(string $accountId): void
    {
        // 1. Transport B2Brouter (réception directe)
        $this->request('POST', sprintf('/accounts/%s/transports', $accountId), [
            'type' => 'b2brouter',
            'direction' => 'incoming',
        ], self::TIMEOUT_SHORT);

        // 2. Transport Peppol (réseau européen)
        $this->request('POST', sprintf('/accounts/%s/transports', $accountId), [
            'type' => 'peppol',
            'direction' => 'incoming',
        ], self::TIMEOUT_SHORT);

        $this->logger->info('[EInvoicing] Transports de réception activés', [
            'account_id' => $accountId,
        ]);
    }

    /**
     * @return InvoiceData[]
     */
    public function fetchPendingInvoices(string $accountId): array
    {
        $data = $this->request('GET', sprintf('/accounts/%s/invoices/received', $accountId), [
            'status' => 'pending',
        ], self::TIMEOUT_LONG);

        $invoices = [];
        foreach ($data['results'] ?? $data as $item) {
            $invoices[] = $this->mapToInvoiceData($item);
        }

        $this->logger->info('[EInvoicing] Factures en attente récupérées', [
            'account_id' => $accountId,
            'count' => \count($invoices),
        ]);

        return $invoices;
    }

    public function getInvoiceWithLines(string $invoiceId): InvoiceData
    {
        $data = $this->request('GET', sprintf('/invoices/%s', $invoiceId), timeout: self::TIMEOUT_LONG);

        return $this->mapToInvoiceData($data, withLines: true);
    }

    public function getOriginalDocument(string $invoiceId): string
    {
        return $this->requestRaw('GET', sprintf('/invoices/%s/original', $invoiceId), self::TIMEOUT_LONG);
    }

    public function acknowledgeInvoice(string $invoiceId): void
    {
        $this->request('POST', sprintf('/invoices/%s/acknowledge', $invoiceId), timeout: self::TIMEOUT_SHORT);

        $this->logger->info('[EInvoicing] Facture acquittée', [
            'invoice_id' => $invoiceId,
        ]);
    }

    public function updateInvoiceStatus(string $invoiceId, string $status, ?string $reason = null): void
    {
        $payload = ['status' => $status];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        $this->request('POST', sprintf('/invoices/%s/status', $invoiceId), $payload, self::TIMEOUT_SHORT);

        $this->logger->info('[EInvoicing] Statut facture mis à jour', [
            'invoice_id' => $invoiceId,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PdpApiException
     */
    private function request(string $method, string $endpoint, array $payload = [], int $timeout = self::TIMEOUT_SHORT): array
    {
        $url = rtrim($this->apiUrl, '/') . $endpoint;

        $options = [
            'headers' => [
                'X-B2B-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ],
            'timeout' => $timeout,
        ];

        if ($method === 'GET' && $payload !== []) {
            $options['query'] = $payload;
        } elseif ($payload !== []) {
            $options['json'] = $payload;
            $options['headers']['Content-Type'] = 'application/json';
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $body = $response->getContent(false);
                throw PdpApiException::requestFailed($statusCode, $body);
            }

            $content = $response->getContent();
            $data = json_decode($content, true);

            if (json_last_error() !== \JSON_ERROR_NONE) {
                throw PdpApiException::invalidResponse('JSON malformé : ' . json_last_error_msg());
            }

            return $data;
        } catch (TransportExceptionInterface $e) {
            throw PdpApiException::connectionFailed($e->getMessage(), $e);
        }
    }

    /**
     * Makes a raw HTTP request and returns the binary response body.
     *
     * @throws PdpApiException
     */
    private function requestRaw(string $method, string $endpoint, int $timeout = self::TIMEOUT_LONG): string
    {
        $url = rtrim($this->apiUrl, '/') . $endpoint;

        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => [
                    'X-B2B-API-Key' => $this->apiKey,
                ],
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $body = $response->getContent(false);
                throw PdpApiException::requestFailed($statusCode, $body);
            }

            return $response->getContent();
        } catch (TransportExceptionInterface $e) {
            throw PdpApiException::connectionFailed($e->getMessage(), $e);
        }
    }

    /**
     * Maps B2Brouter JSON response to InvoiceData DTO.
     */
    private function mapToInvoiceData(array $data, bool $withLines = false): InvoiceData
    {
        $lines = [];
        if ($withLines && isset($data['lines'])) {
            foreach ($data['lines'] as $line) {
                $lines[] = new InvoiceLineData(
                    externalId: isset($line['id']) ? (string) $line['id'] : null,
                    productCode: $line['item_code'] ?? $line['product_code'] ?? null,
                    description: $line['description'] ?? $line['item_description'] ?? '',
                    quantity: (string) ($line['quantity'] ?? '1'),
                    unitPrice: (string) ($line['unit_price'] ?? $line['price'] ?? '0'),
                    lineTotal: (string) ($line['total'] ?? $line['line_total'] ?? '0'),
                    vatRate: isset($line['vat_rate']) ? (string) $line['vat_rate'] : null,
                    unit: $line['unit'] ?? $line['unit_code'] ?? null,
                );
            }
        }

        $supplierVat = $data['supplier_vat'] ?? $data['supplier']['vat'] ?? null;

        return new InvoiceData(
            externalId: (string) ($data['id'] ?? ''),
            invoiceNumber: $data['number'] ?? $data['invoice_number'] ?? '',
            issueDate: new \DateTimeImmutable($data['issue_date'] ?? $data['date'] ?? 'now'),
            supplierName: $data['supplier_name'] ?? $data['supplier']['name'] ?? null,
            supplierVat: $supplierVat,
            supplierSiren: InvoiceData::extractSirenFromFrenchVat($supplierVat),
            buyerName: $data['buyer_name'] ?? $data['buyer']['name'] ?? null,
            buyerVat: $data['buyer_vat'] ?? $data['buyer']['vat'] ?? null,
            totalExclTax: (string) ($data['total_excl_tax'] ?? $data['subtotal'] ?? '0'),
            totalVat: (string) ($data['total_vat'] ?? $data['tax_amount'] ?? '0'),
            totalInclTax: (string) ($data['total_incl_tax'] ?? $data['total'] ?? '0'),
            currency: $data['currency'] ?? 'EUR',
            status: $data['status'] ?? null,
            lines: $lines,
        );
    }
}
