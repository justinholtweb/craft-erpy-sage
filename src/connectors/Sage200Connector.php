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
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpStock;
use justinholtweb\erpy\Plugin as Erpy;

/**
 * Sage 200 Standard and Professional, through the Sage 200 API.
 *
 * Sage 200's API needs three separate things on every request and gives an unhelpful answer when
 * any one is missing: an OAuth bearer token, an Azure subscription key identifying the
 * application, and an `X-Site` header naming which of the customer's Sage 200 sites to work in.
 * A missing X-Site is the usual reason a correctly-authenticated request returns nothing at all.
 */
class Sage200Connector extends Connector
{
    public static function handle(): string
    {
        return 'sage-200';
    }

    public static function displayName(): string
    {
        return 'Sage 200';
    }

    public static function vendor(): string
    {
        return 'Sage';
    }

    public static function description(): string
    {
        return 'Sage 200 Standard and Professional through the Sage 200 API.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://developer.sage.com/api/200/uk/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 100)
            ->withMultiCompany();
    }

    public static function settingsFields(): array
    {
        return [
            Field::select('region', Craft::t('erpy', 'Region'), [
                'uk' => Craft::t('erpy', 'United Kingdom'),
                'ie' => Craft::t('erpy', 'Ireland'),
            ], ['default' => 'uk']),

            Field::heading(
                Craft::t('erpy', 'Sage application'),
                Craft::t('erpy', 'Register an app on the Sage developer portal and set its callback to exactly the value below.'),
            ),
            Field::copyable('redirectUri', Craft::t('erpy', 'Callback URL'), Erpy::redirectUri()),
            Field::text('clientId', Craft::t('erpy', 'Client ID'), ['required' => true]),
            Field::secret('clientSecret', Craft::t('erpy', 'Client secret'), ['required' => true]),
            Field::secret('subscriptionKey', Craft::t('erpy', 'Subscription key'), [
                'required' => true,
                'instructions' => Craft::t('erpy', 'The Ocp-Apim-Subscription-Key from your Sage developer account. It identifies the application, not the customer.'),
            ]),

            Field::heading(Craft::t('erpy', 'Site')),
            Field::text('siteId', Craft::t('erpy', 'Site ID'), [
                'required' => true,
                'instructions' => Craft::t('erpy', 'Sent as the X-Site header. Without it a perfectly authenticated request returns nothing rather than an error.'),
            ]),
            Field::text('warehouse', Craft::t('erpy', 'Warehouse'), []),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new OAuth2AuthorizationCode(
            authorizeUrl: 'https://id.sage.com/authorize',
            tokenUrl: 'https://id.sage.com/oauth/token',
            scope: 'openid profile email offline_access',
            extraAuthorizeParams: ['audience' => 'https://api.columbus.sage.com/uk/sage200extra'],
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(sprintf(
                'https://api.columbus.sage.com/%s/sage200extra/accounts/v1',
                (string)$this->setting('region', 'uk'),
            ))
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Site' => (string)$this->setting('siteId'),
                'ocp-apim-subscription-key' => (string)$this->setting('subscriptionKey'),
            ])
            ->setRateLimit(4)
            ->setTimeout(90);
    }

    protected function probe(): HealthResult
    {
        $auth = $this->auth();

        if ($auth instanceof OAuth2AuthorizationCode && !$auth->isAuthorized()) {
            return HealthResult::fail(
                Craft::t('erpy', 'Not connected yet.'),
                [Craft::t('erpy', 'Save the credentials, then use the Connect button to approve access in Sage once.')],
            );
        }

        $response = $this->transport()->get('stock_items', ['$top' => 1]);

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), match ($response->status) {
                401 => [Craft::t('erpy', 'The token has expired or the subscription key is wrong. They fail identically here, so check both.')],
                403 => [Craft::t('erpy', 'Check the site id — a wrong X-Site is refused rather than ignored on some Sage 200 releases.')],
                default => [],
            });
        }

        return HealthResult::pass(Craft::t('erpy', 'Connected to Sage 200.'), [
            Craft::t('erpy', 'Site') => (string)$this->setting('siteId'),
            Craft::t('erpy', 'Region') => strtoupper((string)$this->setting('region', 'uk')),
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->page('stock_items', $criteria, Entity::PRODUCT, function(array $row): ErpProduct {
            return new ErpProduct([
                'sku' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? $row['description'] ?? ''),
                'description' => $row['description'] ?? null,
                // Sage 200 status: 0 active, 1 suspended, 2 discontinued.
                'enabled' => (int)($row['status'] ?? 0) === 0,
                'blocked' => (int)($row['status'] ?? 0) !== 0,
                'category' => $row['product_group_code'] ?? null,
                'unitOfMeasure' => $row['stock_unit_name'] ?? null,
                'price' => isset($row['selling_price']) ? (float)$row['selling_price'] : null,
                'cost' => isset($row['cost_price']) ? (float)$row['cost_price'] : null,
                'barcode' => $row['barcode'] ?? null,
                'weight' => isset($row['weight']) ? (float)$row['weight'] : null,
                'tracksInventory' => (int)($row['item_type'] ?? 0) === 0,
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['code'] ?? ''),
                'modifiedAt' => $this->date($row['date_time_updated'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        return $this->page('stock_items', $criteria, Entity::INVENTORY, function(array $row): ErpStock {
            return new ErpStock([
                'sku' => (string)($row['code'] ?? ''),
                'warehouse' => $this->setting('warehouse') ?: null,
                'onHand' => (float)($row['confirmed_quantity_in_stock'] ?? $row['quantity_in_stock'] ?? 0),
                'allocated' => isset($row['quantity_allocated_stock']) ? (float)$row['quantity_allocated_stock'] : null,
                // Sage 200 publishes free stock directly, and it is the figure that already
                // accounts for allocations against other orders.
                'available' => isset($row['free_stock_quantity']) ? (float)$row['free_stock_quantity'] : null,
                'incoming' => isset($row['quantity_on_order']) ? (float)$row['quantity_on_order'] : null,
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['date_time_updated'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->page('customers', $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            $address = new ErpAddress([
                'type' => ErpAddress::TYPE_BILLING,
                'fullName' => (string)($row['name'] ?? ''),
                'addressLine1' => $row['main_address']['address_1'] ?? null,
                'addressLine2' => $row['main_address']['address_2'] ?? null,
                'locality' => $row['main_address']['city'] ?? null,
                'administrativeArea' => $row['main_address']['county'] ?? null,
                'postalCode' => $row['main_address']['postcode'] ?? null,
                'countryCode' => $row['main_address']['country_code'] ?? null,
                'isDefault' => true,
            ]);

            return new ErpCustomer([
                'code' => (string)($row['reference'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'email' => $row['main_address']['email_1'] ?? null,
                'phone' => $row['main_address']['telephone_1'] ?? null,
                'enabled' => (int)($row['account_status'] ?? 0) === 0,
                // Sage 200 account status: 0 active, 1 on hold, 2 account closed.
                'onHold' => (int)($row['account_status'] ?? 0) !== 0,
                'currency' => $row['currency']['symbol'] ?? null,
                'priceListCode' => $row['price_band_name'] ?? null,
                'paymentTermsCode' => isset($row['payment_terms_days']) ? (string)$row['payment_terms_days'] : null,
                'taxId' => $row['vat_number'] ?? null,
                'creditLimit' => isset($row['credit_limit']) ? (float)$row['credit_limit'] : null,
                'balance' => isset($row['balance']) ? (float)$row['balance'] : null,
                'discountPercent' => isset($row['discount_percentage']) ? (float)$row['discount_percentage'] : null,
                'addresses' => $address->isEmpty() ? [] : [$address],
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['reference'] ?? ''),
                'modifiedAt' => $this->date($row['date_time_updated'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        return $this->page('customers', $criteria, Entity::CREDIT, function(array $row): ErpCredit {
            return new ErpCredit([
                'customerCode' => (string)($row['reference'] ?? ''),
                'currency' => (string)($row['currency']['symbol'] ?? 'GBP'),
                'creditLimit' => isset($row['credit_limit']) ? (float)$row['credit_limit'] : null,
                'balance' => (float)($row['balance'] ?? 0),
                'onHold' => (int)($row['account_status'] ?? 0) !== 0,
                'raw' => $row,
            ]);
        }, delta: false);
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->page('sales_orders', $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            $status = (string)($row['document_status'] ?? '');
            $fulfilment = (string)($row['fulfilment_status'] ?? '');

            return new ErpOrderStatus([
                'orderNumber' => (string)($row['customer_document_no'] ?? ''),
                'status' => $status,
                'statusCode' => $status,
                'isOnHold' => stripos($status, 'hold') !== false,
                'isCancelled' => stripos($status, 'cancel') !== false,
                'isShipped' => stripos($fulfilment, 'complete') !== false,
                'isPartiallyShipped' => stripos($fulfilment, 'part') !== false,
                'isClosed' => stripos($status, 'complete') !== false,
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['document_no'] ?? ''),
                'modifiedAt' => $this->date($row['date_time_updated'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        return $this->page('sales_invoices', $criteria, Entity::INVOICE, function(array $row): ErpInvoice {
            $total = (float)($row['total_value'] ?? 0);

            return new ErpInvoice([
                'invoiceNumber' => (string)($row['document_no'] ?? ''),
                'orderNumber' => (string)($row['customer_document_no'] ?? ''),
                'customerCode' => (string)($row['customer_reference'] ?? ''),
                'issuedAt' => $this->date($row['document_date'] ?? null),
                'dueAt' => $this->date($row['due_date'] ?? null),
                'currency' => (string)($row['currency']['symbol'] ?? 'GBP'),
                'subtotal' => (float)($row['net_value'] ?? 0),
                'taxTotal' => (float)($row['tax_value'] ?? 0),
                'total' => $total,
                'balance' => (float)($row['outstanding_value'] ?? $total),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['date_time_updated'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'Sage 200 needs a customer reference. Set a guest customer code on the order mapping, or link this customer to a Sage account.'));
        }

        $existing = $this->transport()->get('sales_orders', [
            '$filter' => "customer_document_no eq '" . $this->escape($document->orderNumber) . "'",
            '$top' => 1,
        ]);

        if ($existing->ok() && $remoteId === null) {
            $rows = (array)$existing->at('$items', []);

            if (is_array($rows[0] ?? null)) {
                return PushResult::alreadyExists((string)$rows[0]['id'], (string)($rows[0]['document_no'] ?? ''));
            }
        }

        $lines = [];

        foreach ($document->lines as $line) {
            $lines[] = array_filter([
                'item_code' => $line->sku,
                'quantity' => $line->quantity,
                'unit_selling_price' => $line->unitPrice,
                'description' => $line->description,
                'discount_percent' => $line->discountPercent,
                'line_type' => 'StandardItem',
            ], static fn($value) => $value !== null && $value !== '');
        }

        $payload = array_filter([
            'customer_reference' => $document->customerCode,
            'customer_document_no' => mb_substr($document->orderNumber, 0, 60),
            'document_date' => ($document->orderedAt ?? new DateTime())->format('Y-m-d'),
            'analysis_code_1' => null,
            'lines' => $lines,
        ], static fn($value) => $value !== null && $value !== '');

        if ($document->shippingAddress && !$document->shippingAddress->isEmpty()) {
            $payload['delivery_address'] = array_filter([
                'address_1' => $document->shippingAddress->addressLine1,
                'address_2' => $document->shippingAddress->addressLine2,
                'city' => $document->shippingAddress->locality,
                'county' => $document->shippingAddress->administrativeArea,
                'postcode' => $document->shippingAddress->postalCode,
                'country_code' => $document->shippingAddress->countryCode,
            ], static fn($value) => $value !== null && $value !== '');
        }

        foreach ($document->customFields as $fieldName => $value) {
            $payload[$fieldName] = $value;
        }

        $response = $this->transport()->post('sales_orders', $payload);

        if (!$response->ok()) {
            return $response->status >= 400 && $response->status < 500
                ? PushResult::rejected($response->errorMessage(), $response->json_())
                : PushResult::failed($response->errorMessage(), $response->json_());
        }

        return PushResult::ok(
            (string)$response->at('id', $document->orderNumber),
            (string)$response->at('document_no', ''),
            $response->json_(),
        );
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    private function page(string $resource, FetchCriteria $criteria, string $entity, callable $make, bool $delta = true): Page
    {
        $limit = $this->pageSize($entity, $criteria);
        $skip = (int)($criteria->cursor ?? 0);
        $query = ['$top' => $limit, '$skip' => $skip];

        $filters = [];

        if ($delta && $criteria->since instanceof DateTimeInterface) {
            $filters[] = sprintf("date_time_updated gt '%s'", $criteria->since->format('Y-m-d\TH:i:s\Z'));
        }

        foreach ($criteria->filters as $field => $value) {
            $filters[] = sprintf("%s eq '%s'", $field, $this->escape((string)$value));
        }

        if ($filters !== []) {
            $query['$filter'] = implode(' and ', $filters);
        }

        $response = $this->transport()->get($resource, $query);

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf(
                'Sage 200 refused to read %s: %s',
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

        return new Page($items, count($rows) >= $limit ? (string)($skip + $limit) : null);
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
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
