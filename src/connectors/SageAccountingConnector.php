<?php

namespace justinholtweb\erpysage\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\OAuth2AuthorizationCode;
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
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\Plugin as Erpy;

/**
 * Sage Business Cloud Accounting.
 *
 * Worth being plain about what this one is: Sage Accounting is a bookkeeping product, not an ERP.
 * It has no sales orders, no warehouses and no stock ledger, so this connector deliberately does
 * not advertise inventory or order-status syncing — a connector that pretended otherwise would
 * fail silently on a merchant's busiest day.
 *
 * What it does have is products, contacts and sales invoices, which covers the case merchants
 * actually ask for: keep the catalogue and the customer list in step, and post each completed
 * Commerce order into the ledger as a sales invoice. That is what "sending an order" means here,
 * and the settings say so rather than leaving it to be discovered.
 */
class SageAccountingConnector extends Connector
{
    public static function handle(): string
    {
        return 'sage-accounting';
    }

    public static function displayName(): string
    {
        return 'Sage Business Cloud Accounting';
    }

    public static function vendor(): string
    {
        return 'Sage';
    }

    public static function description(): string
    {
        return 'Sage Accounting. Syncs products and contacts, and posts completed orders into the ledger as sales invoices — it has no sales orders or stock of its own.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://developer.sage.com/accounting/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            // No inventory and no order status: Sage Accounting has neither, and declaring them
            // would produce a sync that succeeds and does nothing.
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 100);
    }

    public static function settingsFields(): array
    {
        return [
            Field::heading(
                Craft::t('erpy', 'Sage application'),
                Craft::t('erpy', 'Register an app on the Sage developer portal and set its callback to exactly the value below.'),
            ),
            Field::copyable('redirectUri', Craft::t('erpy', 'Callback URL'), Erpy::redirectUri()),
            Field::text('clientId', Craft::t('erpy', 'Client ID'), ['required' => true]),
            Field::secret('clientSecret', Craft::t('erpy', 'Client secret'), ['required' => true]),
            Field::select('region', Craft::t('erpy', 'Country'), [
                'gb' => Craft::t('erpy', 'United Kingdom'),
                'ie' => Craft::t('erpy', 'Ireland'),
                'us' => Craft::t('erpy', 'United States'),
                'ca' => Craft::t('erpy', 'Canada'),
                'fr' => Craft::t('erpy', 'France'),
                'es' => Craft::t('erpy', 'Spain'),
                'de' => Craft::t('erpy', 'Germany'),
            ], ['default' => 'gb']),

            Field::heading(
                Craft::t('erpy', 'Posting orders'),
                Craft::t('erpy', 'Sage Accounting has no sales order object, so a completed Commerce order is posted as a sales invoice.'),
            ),
            Field::text('ledgerAccountId', Craft::t('erpy', 'Sales ledger account'), [
                'instructions' => Craft::t('erpy', 'The ledger account id invoice lines are posted to. Leave blank to use the product’s own.'),
            ]),
            Field::text('taxRateId', Craft::t('erpy', 'Default tax rate'), [
                'instructions' => Craft::t('erpy', 'Used when a line has no tax rate of its own. Sage will refuse an invoice line without one.'),
            ]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new OAuth2AuthorizationCode(
            authorizeUrl: 'https://www.sageone.com/oauth2/auth/central',
            tokenUrl: 'https://oauth.accounting.sage.com/token',
            scope: 'full_access',
            extraAuthorizeParams: [
                'filter' => 'apiv3.1',
                'country' => 'gb',
            ],
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri('https://api.accounting.sage.com/v3.1')
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->setRateLimit(2)
            ->setTimeout(60);
    }

    protected function probe(): HealthResult
    {
        $auth = $this->auth();

        if ($auth instanceof OAuth2AuthorizationCode && !$auth->isAuthorized()) {
            return HealthResult::fail(
                Craft::t('erpy', 'Not connected yet.'),
                [Craft::t('erpy', 'Save the client id and secret, then use the Connect button to approve access in Sage once.')],
            );
        }

        $response = $this->transport()->get('businesses');

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), match ($response->status) {
                401 => [Craft::t('erpy', 'The token has expired and could not be refreshed. Sage refresh tokens are valid for a limited period — connect again.')],
                403 => [Craft::t('erpy', 'The app was approved for a different Sage business, or for a country other than the one selected above.')],
                default => [],
            });
        }

        $businesses = (array)$response->at('$items', []);

        return HealthResult::pass(Craft::t('erpy', 'Connected to Sage Accounting.'), [
            Craft::t('erpy', 'Business') => (string)($businesses[0]['name'] ?? '—'),
            Craft::t('erpy', 'Country') => strtoupper((string)$this->setting('region', 'gb')),
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->page('products', $criteria, Entity::PRODUCT, function(array $row): ErpProduct {
            return new ErpProduct([
                'sku' => (string)($row['item_code'] ?? $row['id'] ?? ''),
                'name' => (string)($row['description'] ?? ''),
                'description' => $row['notes'] ?? null,
                'enabled' => !($row['deleted'] ?? false),
                'price' => isset($row['sales_price']['price']) ? (float)$row['sales_price']['price'] : null,
                'cost' => isset($row['cost_price']) ? (float)$row['cost_price'] : null,
                // Sage Accounting keeps no stock ledger at all, so nothing here is stock-tracked.
                'tracksInventory' => false,
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['item_code'] ?? ''),
                'modifiedAt' => $this->date($row['updated_at'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->page('contacts', $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            $address = (array)($row['main_address'] ?? []);

            return new ErpCustomer([
                'code' => (string)($row['reference'] ?: $row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'email' => $row['email'] ?: null,
                'phone' => $row['telephone'] ?: null,
                'enabled' => !($row['deleted'] ?? false),
                'taxId' => $row['tax_number'] ?: null,
                'creditLimit' => isset($row['credit_limit']) ? (float)$row['credit_limit'] : null,
                'balance' => isset($row['balance']) ? (float)$row['balance'] : null,
                'addresses' => $address !== [] ? [new ErpAddress([
                    'type' => ErpAddress::TYPE_BILLING,
                    'fullName' => (string)($row['name'] ?? ''),
                    'addressLine1' => $address['address_line_1'] ?? null,
                    'addressLine2' => $address['address_line_2'] ?? null,
                    'locality' => $address['city'] ?? null,
                    'administrativeArea' => $address['region'] ?? null,
                    'postalCode' => $address['postal_code'] ?? null,
                    'countryCode' => $address['country']['id'] ?? null,
                    'isDefault' => true,
                ])] : [],
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['reference'] ?? ''),
                'modifiedAt' => $this->date($row['updated_at'] ?? null),
                'raw' => $row,
            ]);
        }, extraQuery: ['contact_type_id' => 'CUSTOMER']);
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        return $this->page('sales_invoices', $criteria, Entity::INVOICE, function(array $row): ErpInvoice {
            $total = (float)($row['total_amount'] ?? 0);
            $outstanding = (float)($row['outstanding_amount'] ?? 0);

            return new ErpInvoice([
                'invoiceNumber' => (string)($row['invoice_number'] ?? $row['displayed_as'] ?? ''),
                'orderNumber' => (string)($row['contact_reference'] ?? $row['reference'] ?? ''),
                'customerCode' => (string)($row['contact']['id'] ?? ''),
                'issuedAt' => $this->date($row['date'] ?? null),
                'dueAt' => $this->date($row['due_date'] ?? null),
                'currency' => (string)($row['currency']['id'] ?? 'GBP'),
                'subtotal' => (float)($row['net_amount'] ?? 0),
                'taxTotal' => (float)($row['tax_amount'] ?? 0),
                'total' => $total,
                'balance' => $outstanding,
                'amountPaid' => $total - $outstanding,
                'isPaid' => abs($outstanding) < 0.005,
                'status' => (string)($row['status']['displayed_as'] ?? ''),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['updated_at'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        $contactId = $this->resolveContact($document);

        if ($contactId === null) {
            return PushResult::rejected(Craft::t('erpy', 'No Sage contact matches this order’s customer, and an invoice cannot be posted without one.'));
        }

        // The Commerce order number goes in the invoice's reference field, which is what a retry
        // checks before posting a second invoice into somebody's ledger.
        $existing = $this->transport()->get('sales_invoices', [
            'search' => $document->orderNumber,
            'items_per_page' => 25,
        ]);

        if ($existing->ok() && $remoteId === null) {
            foreach ((array)$existing->at('$items', []) as $invoice) {
                if (is_array($invoice) && (string)($invoice['reference'] ?? '') === $document->orderNumber) {
                    return PushResult::alreadyExists((string)$invoice['id'], (string)($invoice['invoice_number'] ?? ''));
                }
            }
        }

        $taxRateId = (string)$this->setting('taxRateId', '');
        $ledgerAccountId = (string)$this->setting('ledgerAccountId', '');
        $lines = [];

        foreach ($document->lines as $line) {
            $lineItem = array_filter([
                'description' => $line->description ?: $line->sku,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'ledger_account_id' => $ledgerAccountId ?: null,
                'tax_rate_id' => $taxRateId ?: null,
            ], static fn($value) => $value !== null && $value !== '');

            if (!isset($lineItem['tax_rate_id'])) {
                return PushResult::rejected(Craft::t('erpy', 'Sage will not accept an invoice line without a tax rate. Set a default tax rate on this connection.'));
            }

            $lines[] = $lineItem;
        }

        $payload = ['sales_invoice' => array_filter([
            'contact_id' => $contactId,
            'date' => ($document->orderedAt ?? new DateTime())->format('Y-m-d'),
            'reference' => mb_substr($document->orderNumber, 0, 100),
            'notes' => $document->customerNote,
            'invoice_lines' => $lines,
        ], static fn($value) => $value !== null && $value !== '')];

        foreach ($document->customFields as $fieldName => $value) {
            $payload['sales_invoice'][$fieldName] = $value;
        }

        $response = $this->transport()->post('sales_invoices', $payload);

        if (!$response->ok()) {
            return $response->status >= 400 && $response->status < 500
                ? PushResult::rejected($response->errorMessage(), $response->json_())
                : PushResult::failed($response->errorMessage(), $response->json_());
        }

        return PushResult::ok(
            (string)$response->at('id', ''),
            (string)$response->at('invoice_number', ''),
            $response->json_(),
        );
    }

    private function resolveContact(ErpOrder $document): ?string
    {
        if ($document->customerRemoteId) {
            return $document->customerRemoteId;
        }

        foreach (array_filter([$document->customerCode, $document->email]) as $needle) {
            $response = $this->transport()->get('contacts', [
                'search' => $needle,
                'contact_type_id' => 'CUSTOMER',
                'items_per_page' => 5,
            ]);

            foreach ((array)$response->at('$items', []) as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                if ((string)($contact['reference'] ?? '') === $needle || (string)($contact['email'] ?? '') === $needle) {
                    return (string)$contact['id'];
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    private function page(string $resource, FetchCriteria $criteria, string $entity, callable $make, array $extraQuery = []): Page
    {
        $perPage = $this->pageSize($entity, $criteria);
        $pageNumber = (int)($criteria->cursor ?? 1) ?: 1;

        $query = array_merge([
            'items_per_page' => $perPage,
            'page' => $pageNumber,
        ], $extraQuery, $criteria->filters);

        if ($criteria->since instanceof DateTimeInterface) {
            // Sage's own delta parameter, and the only one it honours. A `$filter` on updated_at
            // is silently ignored.
            $query['updated_or_created_since'] = $criteria->since->format('Y-m-d\TH:i:s\Z');
        }

        $response = $this->transport()->get($resource, $query);

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf(
                'Sage Accounting refused to read %s: %s',
                $resource,
                $response->errorMessage(),
            ));
        }

        $rows = (array)$response->at('$items', []);
        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $make($row);
            }
        }

        $total = (int)($response->at('$total') ?? 0);
        $seen = ($pageNumber - 1) * $perPage + count($rows);

        return new Page($items, $seen < $total ? (string)($pageNumber + 1) : null, $total ?: null);
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
