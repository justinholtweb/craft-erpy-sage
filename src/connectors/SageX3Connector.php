<?php

namespace justinholtweb\erpysage\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\BasicAuth;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpStock;

/**
 * Sage X3, through the X3 REST web services.
 *
 * X3 exposes *representations* rather than resources, and the names are the four-letter table
 * codes an X3 consultant works in every day: ITMMASTER for products, BPCUSTOMER for customers,
 * SORDER for sales orders. The representation name and its `.$query` or `.$details` suffix are
 * part of the request, and getting the suffix wrong returns a schema rather than data.
 *
 * X3 is also strongly folder-scoped: the folder is in the URL, and the same credentials against
 * two folders are two entirely separate datasets.
 */
class SageX3Connector extends Connector
{
    public static function handle(): string
    {
        return 'sage-x3';
    }

    public static function displayName(): string
    {
        return 'Sage X3';
    }

    public static function vendor(): string
    {
        return 'Sage';
    }

    public static function description(): string
    {
        return 'Sage X3 through the REST web services, addressing its representations directly.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://developer.sage.com/x3/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: false, pageSize: 200)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 100)
            ->withMultiCompany();
    }

    public static function settingsFields(): array
    {
        return [
            Field::url('serverUrl', Craft::t('erpy', 'X3 server URL'), [
                'required' => true,
                'placeholder' => 'https://x3.example.com:8124',
                'instructions' => Craft::t('erpy', 'Include the port the Syracuse web server listens on.'),
            ]),
            Field::text('folder', Craft::t('erpy', 'Folder'), [
                'required' => true,
                'placeholder' => 'SEED',
                'instructions' => Craft::t('erpy', 'The X3 folder. The same credentials against a different folder are a different dataset entirely.'),
            ]),
            Field::text('username', Craft::t('erpy', 'Username'), ['required' => true]),
            Field::secret('password', Craft::t('erpy', 'Password'), ['required' => true]),

            Field::heading(
                Craft::t('erpy', 'Representations'),
                Craft::t('erpy', 'The X3 representations to read. These are the standard ones; change any that your implementation replaced with a custom representation.'),
            ),
            Field::text('productRepresentation', Craft::t('erpy', 'Products'), ['default' => 'ITMMASTER']),
            Field::text('stockRepresentation', Craft::t('erpy', 'Stock'), ['default' => 'ITMMVT']),
            Field::text('customerRepresentation', Craft::t('erpy', 'Customers'), ['default' => 'BPCUSTOMER']),
            Field::text('orderRepresentation', Craft::t('erpy', 'Sales orders'), ['default' => 'SORDER']),

            Field::heading(Craft::t('erpy', 'Behaviour')),
            Field::text('salesSite', Craft::t('erpy', 'Sales site'), [
                'instructions' => Craft::t('erpy', 'The X3 site orders are raised against, and the one stock is read from.'),
            ]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new BasicAuth();
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(sprintf(
                '%s/api1/x3/erp/%s',
                rtrim((string)$this->setting('serverUrl'), '/'),
                (string)$this->setting('folder'),
            ))
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->setRateLimit(3)
            ->setTimeout(120);
    }

    protected function probe(): HealthResult
    {
        $representation = (string)$this->setting('productRepresentation', 'ITMMASTER');
        $response = $this->transport()->get($representation, [
            'representation' => $representation . '.$query',
            'count' => 1,
        ]);

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), match ($response->status) {
                401 => [Craft::t('erpy', 'Check the username and password, and that the X3 user has a web services role.')],
                404 => [Craft::t('erpy', 'Check the folder name and the representation. A folder the user cannot reach is a 404 here rather than a 403.')],
                default => [],
            });
        }

        return HealthResult::pass(Craft::t('erpy', 'Connected to Sage X3.'), [
            Craft::t('erpy', 'Folder') => (string)$this->setting('folder'),
            Craft::t('erpy', 'Representation') => $representation,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('productRepresentation', 'ITMMASTER'), $criteria, Entity::PRODUCT, function(array $row): ErpProduct {
            return new ErpProduct([
                'sku' => (string)($row['ITMREF'] ?? ''),
                'name' => (string)($row['ITMDES1'] ?? ''),
                'description' => $row['ITMDES2'] ?? null,
                // X3's ITMSTA is 1 active, 2 not usable, 3 not renewable.
                'enabled' => (string)($row['ITMSTA'] ?? '1') === '1',
                'blocked' => (string)($row['ITMSTA'] ?? '1') !== '1',
                'category' => $row['TSICOD_1'] ?? $row['ITMFAM'] ?? null,
                'unitOfMeasure' => $row['STU'] ?? null,
                'price' => isset($row['BASPRI']) ? (float)$row['BASPRI'] : null,
                'barcode' => $row['EANCOD'] ?? null,
                'weight' => isset($row['ITMWEI']) ? (float)$row['ITMWEI'] : null,
                'weightUnit' => $row['WEU'] ?? null,
                // FLGSTOFCY says whether the item is stock-managed at all.
                'tracksInventory' => (int)($row['FLGSTOFCY'] ?? 2) === 2,
                'remoteId' => (string)($row['ITMREF'] ?? ''),
                'remoteKey' => (string)($row['ITMREF'] ?? ''),
                'modifiedAt' => $this->date($row['UPDDAT'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UPDDAT');
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        $site = (string)$this->setting('salesSite', '');
        $where = $site !== '' ? "STOFCY eq '" . $this->escape($site) . "'" : '';

        return $this->page((string)$this->setting('stockRepresentation', 'ITMMVT'), $criteria, Entity::INVENTORY, function(array $row): ErpStock {
            return new ErpStock([
                'sku' => (string)($row['ITMREF'] ?? ''),
                'warehouse' => $row['STOFCY'] ?? null,
                'onHand' => (float)($row['PHYSTO'] ?? 0),
                // AVASTO is X3's available stock: physical less what is already allocated.
                'available' => isset($row['AVASTO']) ? (float)$row['AVASTO'] : null,
                'allocated' => isset($row['ALLSTO']) ? (float)$row['ALLSTO'] : null,
                'incoming' => isset($row['ORDSTO']) ? (float)$row['ORDSTO'] : null,
                'remoteId' => (string)($row['ITMREF'] ?? '') . '|' . (string)($row['STOFCY'] ?? ''),
                'raw' => $row,
            ]);
        }, where: $where);
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('customerRepresentation', 'BPCUSTOMER'), $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            return new ErpCustomer([
                'code' => (string)($row['BPCNUM'] ?? ''),
                'name' => (string)($row['BPCNAM'] ?? $row['BPRNAM_0'] ?? ''),
                'email' => $row['WEB'] ?? null,
                'enabled' => (int)($row['BPCSTA'] ?? 2) === 2,
                'onHold' => (int)($row['OUTSTDAUZ'] ?? 0) === 1,
                'currency' => $row['CUR'] ?? null,
                'priceListCode' => $row['PLICRI1'] ?? null,
                'customerGroupCode' => $row['BPCCAT'] ?? null,
                'paymentTermsCode' => $row['PTE'] ?? null,
                'creditLimit' => isset($row['OSTCTL']) ? (float)$row['OSTCTL'] : null,
                'discountPercent' => isset($row['DISCRGVAL1']) ? (float)$row['DISCRGVAL1'] : null,
                'remoteId' => (string)($row['BPCNUM'] ?? ''),
                'remoteKey' => (string)($row['BPCNUM'] ?? ''),
                'modifiedAt' => $this->date($row['UPDDAT'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UPDDAT');
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('customerRepresentation', 'BPCUSTOMER'), $criteria, Entity::CREDIT, function(array $row): ErpCredit {
            return new ErpCredit([
                'customerCode' => (string)($row['BPCNUM'] ?? ''),
                'currency' => (string)($row['CUR'] ?: 'EUR'),
                'creditLimit' => isset($row['OSTCTL']) ? (float)$row['OSTCTL'] : null,
                'balance' => (float)($row['CDTAMT'] ?? 0),
                'onHold' => (int)($row['OUTSTDAUZ'] ?? 0) === 1,
                'paymentTermsCode' => $row['PTE'] ?? null,
                'raw' => $row,
            ]);
        });
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('orderRepresentation', 'SORDER'), $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            // X3 tracks delivery and invoicing separately: 1 not delivered, 2 partly, 3 fully.
            $delivery = (int)($row['DLVSTA'] ?? 1);
            $invoicing = (int)($row['INVSTA'] ?? 1);
            $closed = (int)($row['CLEFLG'] ?? 1);

            return new ErpOrderStatus([
                'orderNumber' => (string)($row['CUSORDREF'] ?? ''),
                'status' => 'DLVSTA=' . $delivery . ' INVSTA=' . $invoicing,
                'statusCode' => (string)$delivery,
                'isPicking' => $delivery === 1 && $closed !== 2,
                'isPartiallyShipped' => $delivery === 2,
                'isShipped' => $delivery === 3,
                'isInvoiced' => $invoicing === 3,
                'isClosed' => $closed === 2,
                'remoteId' => (string)($row['SOHNUM'] ?? ''),
                'remoteKey' => (string)($row['SOHNUM'] ?? ''),
                'modifiedAt' => $this->date($row['UPDDAT'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UPDDAT');
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'Sage X3 needs a customer code. Set a guest customer code on the order mapping, or link this customer to an X3 customer.'));
        }

        $representation = (string)$this->setting('orderRepresentation', 'SORDER');
        $site = (string)$this->setting('salesSite', '');

        $existing = $this->transport()->get($representation, [
            'representation' => $representation . '.$query',
            'where' => "CUSORDREF eq '" . $this->escape($document->orderNumber) . "'",
            'count' => 1,
        ]);

        if ($existing->ok() && $remoteId === null) {
            $rows = (array)$existing->at('$resources', []);

            if (is_array($rows[0] ?? null)) {
                return PushResult::alreadyExists((string)($rows[0]['SOHNUM'] ?? ''), (string)($rows[0]['SOHNUM'] ?? ''));
            }
        }

        $lines = [];

        foreach ($document->lines as $line) {
            $lines[] = array_filter([
                'ITMREF' => $line->sku,
                'QTY' => $line->quantity,
                'GROPRI' => $line->unitPrice,
                'ITMDES' => $line->description,
                'STOFCY' => $line->warehouse ?: ($site ?: null),
            ], static fn($value) => $value !== null && $value !== '');
        }

        $payload = array_filter([
            'SALFCY' => $site ?: null,
            'BPCORD' => $document->customerCode,
            'CUSORDREF' => mb_substr($document->orderNumber, 0, 30),
            'ORDDAT' => ($document->orderedAt ?? new DateTime())->format('Y-m-d'),
            'CUR' => $document->currency,
            'LIN' => $lines,
        ], static fn($value) => $value !== null && $value !== '');

        foreach ($document->customFields as $fieldName => $value) {
            $payload[$fieldName] = $value;
        }

        $response = $this->transport()->post($representation, $payload, [
            'query' => ['representation' => $representation . '.$edit'],
        ]);

        if (!$response->ok()) {
            return $response->status >= 400 && $response->status < 500
                ? PushResult::rejected($response->errorMessage(), $response->json_())
                : PushResult::failed($response->errorMessage(), $response->json_());
        }

        $number = (string)$response->at('SOHNUM', '');

        return $number !== ''
            ? PushResult::ok($number, $number, $response->json_())
            : PushResult::failed(Craft::t('erpy', 'X3 accepted the order but returned no order number.'));
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    private function page(
        string $representation,
        FetchCriteria $criteria,
        string $entity,
        callable $make,
        ?string $deltaField = null,
        string $where = '',
    ): Page {
        $count = $this->pageSize($entity, $criteria);
        // X3 counts from one rather than from zero, and an index of 0 returns the same first page
        // forever — which the engine would see as a repeated cursor and stop on.
        $startIndex = (int)($criteria->cursor ?? 1) ?: 1;

        $conditions = array_filter([$where]);

        if ($deltaField !== null && $criteria->since instanceof DateTimeInterface) {
            $conditions[] = sprintf("%s ge '%s'", $deltaField, $criteria->since->format('Y-m-d'));
        }

        foreach ($criteria->filters as $field => $value) {
            $conditions[] = sprintf("%s eq '%s'", $field, $this->escape((string)$value));
        }

        $query = [
            'representation' => $representation . '.$query',
            'count' => $count,
            'startIndex' => $startIndex,
        ];

        if ($conditions !== []) {
            $query['where'] = implode(' and ', $conditions);
        }

        $response = $this->transport()->get($representation, $query);

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf(
                'Sage X3 refused to read %s: %s',
                $representation,
                $response->errorMessage(),
            ));
        }

        $rows = (array)$response->at('$resources', []);
        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $make($row);
            }
        }

        return new Page($items, count($rows) >= $count ? (string)($startIndex + $count) : null);
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
