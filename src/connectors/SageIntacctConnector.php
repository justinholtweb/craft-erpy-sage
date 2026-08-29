<?php

namespace justinholtweb\erpysage\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\NoAuth;
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
use justinholtweb\erpy\base\Response;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpStock;
use SimpleXMLElement;

/**
 * Sage Intacct, through the XML gateway.
 *
 * Intacct is the outlier in this family: there is no REST API, only one XML endpoint that
 * everything goes through, and it authenticates with *two* sets of credentials at once — a sender
 * id issued to the integration, and a user login belonging to the customer's company. Sending
 * only one of them produces an error that mentions neither.
 *
 * Paging is by `resultId` rather than by offset: the first query returns a handle, and every page
 * after it is a `readMore` against that handle. The handle expires, which is why Erpy's page loop
 * feeds the cursor straight back rather than re-issuing the query.
 *
 * Dates in a query filter are `MM/DD/YYYY HH:MM:SS` in the *company's* timezone, not ISO 8601 and
 * not UTC.
 */
class SageIntacctConnector extends Connector
{
    public static function handle(): string
    {
        return 'sage-intacct';
    }

    public static function displayName(): string
    {
        return 'Sage Intacct';
    }

    public static function vendor(): string
    {
        return 'Sage';
    }

    public static function description(): string
    {
        return 'Sage Intacct through the XML gateway, with its two-part authentication and resultId paging handled for you.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://developer.intacct.com/web-services/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 500)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 500)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: false, pageSize: 500)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 500)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 500)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 500)
            ->withMultiCompany();
    }

    public static function settingsFields(): array
    {
        return [
            Field::heading(
                Craft::t('erpy', 'Web services sender'),
                Craft::t('erpy', 'Issued to the integration by Sage, not by the customer. The same sender id is used across every Intacct company you connect to.'),
            ),
            Field::text('senderId', Craft::t('erpy', 'Sender ID'), ['required' => true]),
            Field::secret('senderPassword', Craft::t('erpy', 'Sender password'), ['required' => true]),

            Field::heading(
                Craft::t('erpy', 'Company login'),
                Craft::t('erpy', 'A web services user in the customer’s Intacct company. It must be a Web Services user, not a normal one, and the sender id has to be authorised under Company → Security → Web Services Authorizations.'),
            ),
            Field::text('companyId', Craft::t('erpy', 'Company ID'), ['required' => true]),
            Field::text('userId', Craft::t('erpy', 'User ID'), ['required' => true]),
            Field::secret('userPassword', Craft::t('erpy', 'User password'), ['required' => true]),
            Field::text('entityId', Craft::t('erpy', 'Entity'), [
                'instructions' => Craft::t('erpy', 'For multi-entity companies. Leave blank to work at the top level.'),
            ]),

            Field::heading(
                Craft::t('erpy', 'Objects'),
                Craft::t('erpy', 'Which Intacct objects to read. The defaults suit a company running Order Entry and Inventory Control; change them if yours is configured differently.'),
            ),
            Field::text('itemObject', Craft::t('erpy', 'Items'), ['default' => 'ITEM']),
            Field::text('stockObject', Craft::t('erpy', 'Stock'), ['default' => 'ITEMWAREHOUSEINFO']),
            Field::text('customerObject', Craft::t('erpy', 'Customers'), ['default' => 'CUSTOMER']),
            Field::text('orderDocumentType', Craft::t('erpy', 'Sales order document type'), [
                'default' => 'Sales Order',
                'instructions' => Craft::t('erpy', 'The Order Entry transaction definition new orders are created as. It must match exactly, including capitalisation.'),
            ]),
            Field::text('warehouseId', Craft::t('erpy', 'Warehouse'), []),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        // Intacct authenticates inside the XML envelope, not in a header.
        return new NoAuth();
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri('https://api.intacct.com/ia/xml')
            ->setDefaultHeaders(['Content-Type' => 'application/xml'])
            ->setRateLimit(3)
            ->setTimeout(180);
    }

    protected function probe(): HealthResult
    {
        $xml = $this->call($this->readByQuery((string)$this->setting('customerObject', 'CUSTOMER'), 'CUSTOMERID', '', 1));

        if ($xml === null) {
            return HealthResult::fail($this->lastError ?? Craft::t('erpy', 'Intacct refused the request.'), [
                Craft::t('erpy', 'Intacct needs both sets of credentials: the sender id issued to the integration, and a Web Services user in the company.'),
                Craft::t('erpy', 'Check the sender id is authorised under Company → Security → Web Services Authorizations. Without that, correct credentials still fail.'),
            ]);
        }

        $data = $xml->operation->result->data ?? null;

        return HealthResult::pass(Craft::t('erpy', 'Connected to Sage Intacct.'), [
            Craft::t('erpy', 'Company') => (string)$this->setting('companyId'),
            Craft::t('erpy', 'Customers visible') => (string)($data?->attributes()->totalcount ?? '0'),
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->page(
            (string)$this->setting('itemObject', 'ITEM'),
            'ITEMID,NAME,EXTENDED_DESCRIPTION,STATUS,PRODUCTLINEID,UOMGRP,ITEMTYPE,COST,BASEPRICE,WHENMODIFIED',
            'WHENMODIFIED',
            $criteria,
            Entity::PRODUCT,
            function(array $row): ErpProduct {
                return new ErpProduct([
                    'sku' => (string)($row['ITEMID'] ?? ''),
                    'name' => (string)($row['NAME'] ?? ''),
                    'description' => $row['EXTENDED_DESCRIPTION'] ?? null,
                    'enabled' => strtolower((string)($row['STATUS'] ?? 'active')) === 'active',
                    'blocked' => strtolower((string)($row['STATUS'] ?? 'active')) !== 'active',
                    'category' => $row['PRODUCTLINEID'] ?? null,
                    'unitOfMeasure' => $row['UOMGRP'] ?? null,
                    'price' => isset($row['BASEPRICE']) ? (float)$row['BASEPRICE'] : null,
                    'cost' => isset($row['COST']) ? (float)$row['COST'] : null,
                    // Intacct calls a stocked item "Inventory"; everything else is a service or
                    // a non-inventory line with no stock to read.
                    'tracksInventory' => (string)($row['ITEMTYPE'] ?? 'Inventory') === 'Inventory',
                    'remoteId' => (string)($row['RECORDNO'] ?? $row['ITEMID'] ?? ''),
                    'remoteKey' => (string)($row['ITEMID'] ?? ''),
                    'modifiedAt' => $this->date($row['WHENMODIFIED'] ?? null),
                    'raw' => $row,
                ]);
            },
        );
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        $warehouse = (string)$this->setting('warehouseId', '');
        $filter = $warehouse !== '' ? "WAREHOUSEID = '" . $this->escape($warehouse) . "'" : '';

        return $this->page(
            (string)$this->setting('stockObject', 'ITEMWAREHOUSEINFO'),
            '*',
            null,
            $criteria,
            Entity::INVENTORY,
            function(array $row): ErpStock {
                return new ErpStock([
                    'sku' => (string)($row['ITEMID'] ?? ''),
                    'warehouse' => $row['WAREHOUSEID'] ?? null,
                    'onHand' => (float)($row['QUANTITYONHAND'] ?? $row['ONHAND'] ?? 0),
                    'allocated' => isset($row['QUANTITYALLOCATED']) ? (float)$row['QUANTITYALLOCATED'] : null,
                    'available' => isset($row['QUANTITYAVAILABLE']) ? (float)$row['QUANTITYAVAILABLE'] : null,
                    'remoteId' => (string)($row['RECORDNO'] ?? ''),
                    'raw' => $row,
                ]);
            },
            $filter,
        );
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->page(
            (string)$this->setting('customerObject', 'CUSTOMER'),
            'RECORDNO,CUSTOMERID,NAME,STATUS,EMAIL1,PHONE1,CURRENCY,TERMNAME,CREDITLIMIT,TOTALDUE,ONHOLD,TAXID,CUSTTYPE,PRICESCHEDULE,WHENMODIFIED',
            'WHENMODIFIED',
            $criteria,
            Entity::CUSTOMER,
            function(array $row): ErpCustomer {
                return new ErpCustomer([
                    'code' => (string)($row['CUSTOMERID'] ?? ''),
                    'name' => (string)($row['NAME'] ?? ''),
                    'email' => $row['EMAIL1'] ?: null,
                    'phone' => $row['PHONE1'] ?: null,
                    'enabled' => strtolower((string)($row['STATUS'] ?? 'active')) === 'active',
                    'onHold' => strtolower((string)($row['ONHOLD'] ?? 'false')) === 'true',
                    'currency' => $row['CURRENCY'] ?: null,
                    'priceListCode' => $row['PRICESCHEDULE'] ?? null,
                    'customerGroupCode' => $row['CUSTTYPE'] ?? null,
                    'paymentTermsCode' => $row['TERMNAME'] ?? null,
                    'taxId' => $row['TAXID'] ?: null,
                    'creditLimit' => isset($row['CREDITLIMIT']) ? (float)$row['CREDITLIMIT'] : null,
                    'balance' => isset($row['TOTALDUE']) ? (float)$row['TOTALDUE'] : null,
                    'remoteId' => (string)($row['RECORDNO'] ?? ''),
                    'remoteKey' => (string)($row['CUSTOMERID'] ?? ''),
                    'modifiedAt' => $this->date($row['WHENMODIFIED'] ?? null),
                    'raw' => $row,
                ]);
            },
        );
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        return $this->page(
            (string)$this->setting('customerObject', 'CUSTOMER'),
            'CUSTOMERID,CURRENCY,CREDITLIMIT,TOTALDUE,ONHOLD,TERMNAME',
            null,
            $criteria,
            Entity::CREDIT,
            function(array $row): ErpCredit {
                return new ErpCredit([
                    'customerCode' => (string)($row['CUSTOMERID'] ?? ''),
                    'currency' => (string)($row['CURRENCY'] ?: 'USD'),
                    'creditLimit' => isset($row['CREDITLIMIT']) ? (float)$row['CREDITLIMIT'] : null,
                    'balance' => (float)($row['TOTALDUE'] ?? 0),
                    'onHold' => strtolower((string)($row['ONHOLD'] ?? 'false')) === 'true',
                    'paymentTermsCode' => $row['TERMNAME'] ?? null,
                    'raw' => $row,
                ]);
            },
        );
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        $type = $this->escape((string)$this->setting('orderDocumentType', 'Sales Order'));

        return $this->page(
            'SODOCUMENT',
            'RECORDNO,DOCNO,CUSTVENDID,DOCPARID,STATE,STATUS,CUSTOMERDOCNO,WHENMODIFIED',
            'WHENMODIFIED',
            $criteria,
            Entity::ORDER_STATUS,
            function(array $row): ErpOrderStatus {
                $state = (string)($row['STATE'] ?? '');

                return new ErpOrderStatus([
                    'orderNumber' => (string)($row['CUSTOMERDOCNO'] ?? ''),
                    'status' => $state,
                    'statusCode' => $state,
                    // Intacct's SODOCUMENT states are Pending, In Progress, Converted, Closed and
                    // Draft. "Converted" means it became a delivery or an invoice downstream.
                    'isPicking' => $state === 'In Progress',
                    'isShipped' => $state === 'Converted',
                    'isClosed' => $state === 'Closed',
                    'isCancelled' => strtolower((string)($row['STATUS'] ?? '')) === 'reversed',
                    'remoteId' => (string)($row['RECORDNO'] ?? ''),
                    'remoteKey' => (string)($row['DOCNO'] ?? ''),
                    'modifiedAt' => $this->date($row['WHENMODIFIED'] ?? null),
                    'raw' => $row,
                ]);
            },
            "DOCPARID = '$type'",
        );
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        return $this->page(
            'ARINVOICE',
            'RECORDNO,RECORDID,CUSTOMERID,WHENCREATED,WHENDUE,TOTALENTERED,TOTALDUE,CURRENCY,STATE,WHENMODIFIED',
            'WHENMODIFIED',
            $criteria,
            Entity::INVOICE,
            function(array $row): ErpInvoice {
                $total = (float)($row['TOTALENTERED'] ?? 0);
                $due = (float)($row['TOTALDUE'] ?? 0);

                return new ErpInvoice([
                    'invoiceNumber' => (string)($row['RECORDID'] ?? ''),
                    'customerCode' => (string)($row['CUSTOMERID'] ?? ''),
                    'issuedAt' => $this->date($row['WHENCREATED'] ?? null),
                    'dueAt' => $this->date($row['WHENDUE'] ?? null),
                    'currency' => (string)($row['CURRENCY'] ?: 'USD'),
                    'total' => $total,
                    'balance' => $due,
                    'amountPaid' => $total - $due,
                    'isPaid' => abs($due) < 0.005,
                    'status' => (string)($row['STATE'] ?? ''),
                    'remoteId' => (string)($row['RECORDNO'] ?? ''),
                    'modifiedAt' => $this->date($row['WHENMODIFIED'] ?? null),
                    'raw' => $row,
                ]);
            },
        );
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'Intacct needs a customer id. Set a guest customer code on the order mapping, or link this customer to an Intacct customer.'));
        }

        $type = $this->escape((string)$this->setting('orderDocumentType', 'Sales Order'));
        $orderNumber = $this->escape($document->orderNumber);

        // CUSTOMERDOCNO carries the Commerce order number, so a retry can ask before it creates.
        $existing = $this->call($this->readByQuery('SODOCUMENT', 'RECORDNO,DOCNO', "CUSTOMERDOCNO = '$orderNumber' AND DOCPARID = '$type'", 1));

        if ($existing !== null && $remoteId === null) {
            $rows = $this->rowsFrom($existing);

            if ($rows !== []) {
                return PushResult::alreadyExists((string)($rows[0]['RECORDNO'] ?? ''), (string)($rows[0]['DOCNO'] ?? ''));
            }
        }

        $warehouse = (string)$this->setting('warehouseId', '');
        $lines = '';

        foreach ($document->lines as $line) {
            $lines .= '<sotransitem>'
                . $this->tag('itemid', $line->sku)
                . $this->tag('quantity', (string)$line->quantity)
                . $this->tag('price', (string)$line->unitPrice)
                . ($line->description ? $this->tag('memo', $line->description) : '')
                . ($warehouse !== '' ? $this->tag('warehouseid', $warehouse) : '')
                . '</sotransitem>';
        }

        $body = '<create_sotransaction>'
            . $this->tag('transactiontype', (string)$this->setting('orderDocumentType', 'Sales Order'))
            . $this->tag('datecreated', $this->intacctDate($document->orderedAt ?? new DateTime()))
            . $this->tag('customerid', $document->customerCode)
            . $this->tag('documentno', '')
            . $this->tag('referenceno', mb_substr($document->orderNumber, 0, 60))
            . $this->tag('customerdocno', mb_substr($document->orderNumber, 0, 60))
            . ($document->currency ? $this->tag('currency', $document->currency) : '')
            . ($document->customerNote ? $this->tag('message', $document->customerNote) : '')
            . '<sotransitems>' . $lines . '</sotransitems>'
            . '</create_sotransaction>';

        $xml = $this->call($body);

        if ($xml === null) {
            // Intacct reports its own refusals inside a 200, so both a described failure and a
            // 4xx mean the document itself is wrong and resending it unchanged cannot help.
            $refused = str_contains((string)$this->lastError, 'Could not create')
                || ($this->lastStatus >= 400 && $this->lastStatus < 500 && !in_array($this->lastStatus, [408, 429], true));

            return $refused
                ? PushResult::rejected((string)($this->lastError ?? 'Intacct refused the order.'))
                : PushResult::failed((string)($this->lastError ?? 'Intacct refused the order.'));
        }

        $key = (string)($xml->operation->result->key ?? '');
        $docNumber = (string)($xml->operation->result->data->sodocument->DOCNO ?? '');

        return $key !== ''
            ? PushResult::ok($key, $docNumber ?: null)
            : PushResult::failed(Craft::t('erpy', 'Intacct accepted the order but returned no record number.'));
    }

    // ---------------------------------------------------------------------------------------
    // The XML gateway
    // ---------------------------------------------------------------------------------------

    private ?string $lastError = null;

    /** Intacct answers its own failures with HTTP 200, so the status only separates a refusal
     *  from an outage — which is exactly what a push needs to know. */
    private int $lastStatus = 0;

    private function page(
        string $object,
        string $fields,
        ?string $deltaField,
        FetchCriteria $criteria,
        string $entity,
        callable $make,
        string $extraFilter = '',
    ): Page {
        // Intacct pages by handle: the first query returns a resultId, and every page after it is
        // a readMore against that handle rather than a re-query with an offset.
        if ($criteria->cursor !== null) {
            $xml = $this->call('<readMore>' . $this->tag('resultId', $criteria->cursor) . '</readMore>');
        } else {
            $filters = array_filter([$extraFilter]);

            if ($deltaField !== null && $criteria->since instanceof DateTimeInterface) {
                // Intacct query dates are MM/DD/YYYY HH:MM:SS, in the company's own timezone —
                // neither ISO 8601 nor UTC, and it silently returns nothing for either.
                $filters[] = sprintf("%s > '%s'", $deltaField, $this->intacctDateTime($criteria->since));
            }

            foreach ($criteria->filters as $field => $value) {
                $filters[] = sprintf("%s = '%s'", $field, $this->escape((string)$value));
            }

            $xml = $this->call($this->readByQuery(
                $object,
                $fields,
                implode(' AND ', $filters),
                $this->pageSize($entity, $criteria),
            ));
        }

        if ($xml === null) {
            throw new \RuntimeException(sprintf(
                'Intacct refused to read %s: %s',
                $object,
                $this->lastError ?? 'no reason given',
            ));
        }

        $items = [];

        foreach ($this->rowsFrom($xml) as $row) {
            $items[] = $make($row);
        }

        $data = $xml->operation->result->data ?? null;
        $remaining = (int)($data?->attributes()->numremaining ?? 0);
        $resultId = (string)($data?->attributes()->resultId ?? '');

        return new Page($items, ($remaining > 0 && $resultId !== '') ? $resultId : null);
    }

    private function readByQuery(string $object, string $fields, string $query, int $pageSize): string
    {
        return '<readByQuery>'
            . $this->tag('object', $object)
            . $this->tag('fields', $fields)
            . $this->tag('query', $query)
            . $this->tag('pagesize', (string)$pageSize)
            . '</readByQuery>';
    }

    /**
     * Wrap a function body in Intacct's envelope, send it, and hand back the parsed response.
     */
    private function call(string $functionBody): ?SimpleXMLElement
    {
        $this->lastError = null;

        $entity = (string)$this->setting('entityId', '');

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<request>'
            . '<control>'
            . $this->tag('senderid', (string)$this->setting('senderId'))
            . $this->tag('password', (string)$this->setting('senderPassword'))
            . $this->tag('controlid', (string)time())
            . '<uniqueid>false</uniqueid>'
            . '<dtdversion>3.0</dtdversion>'
            . '<includewhitespace>false</includewhitespace>'
            . '</control>'
            . '<operation>'
            . '<authentication><login>'
            . $this->tag('userid', (string)$this->setting('userId'))
            . $this->tag('companyid', (string)$this->setting('companyId'))
            . $this->tag('password', (string)$this->setting('userPassword'))
            . ($entity !== '' ? $this->tag('locationid', $entity) : '')
            . '</login></authentication>'
            . '<content><function controlid="erpy">' . $functionBody . '</function></content>'
            . '</operation>'
            . '</request>';

        $response = $this->transport()->request('POST', 'xmlgw.phtml', ['body' => $envelope]);
        $this->lastStatus = $response->status;

        if (!$response->ok()) {
            $this->lastError = $response->errorMessage();

            return null;
        }

        $xml = $this->parse($response);

        if ($xml === null) {
            $this->lastError = 'Intacct returned something that is not XML.';

            return null;
        }

        // Intacct answers HTTP 200 for authentication failures and for rejected documents alike;
        // the status element is the only thing that says which.
        $controlStatus = (string)($xml->control->status ?? '');
        $operationStatus = (string)($xml->operation->authentication->status ?? '');
        $resultStatus = (string)($xml->operation->result->status ?? '');

        if ($controlStatus === 'failure' || $operationStatus === 'failure' || $resultStatus === 'failure') {
            $this->lastError = $this->errorFrom($xml);

            return null;
        }

        return $xml;
    }

    private function parse(Response $response): ?SimpleXMLElement
    {
        try {
            // LIBXML_NONET and no entity substitution: this response is remote input, and an
            // XML parser that resolves entities is an XXE waiting to happen.
            $xml = new SimpleXMLElement($response->body, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (\Throwable) {
            return null;
        }

        return $xml;
    }

    private function errorFrom(SimpleXMLElement $xml): string
    {
        foreach (['control/errormessage/error', 'operation/errormessage/error', 'operation/result/errormessage/error'] as $path) {
            $errors = $xml->xpath('//' . $path) ?: [];

            foreach ($errors as $error) {
                $parts = array_filter([
                    trim((string)($error->description ?? '')),
                    trim((string)($error->description2 ?? '')),
                    trim((string)($error->correction ?? '')),
                ]);

                if ($parts !== []) {
                    return implode(' ', $parts);
                }
            }
        }

        return 'Intacct reported a failure but gave no description.';
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function rowsFrom(SimpleXMLElement $xml): array
    {
        $data = $xml->operation->result->data ?? null;

        if ($data === null) {
            return [];
        }

        $rows = [];

        foreach ($data->children() as $child) {
            $row = [];

            foreach ($child->children() as $field) {
                $row[$field->getName()] = trim((string)$field);
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function tag(string $name, string $value): string
    {
        return "<$name>" . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</$name>";
    }

    /**
     * Intacct's query language has no parameter binding, so a quote in a customer name would end
     * the literal and change the meaning of the query.
     */
    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function intacctDate(DateTimeInterface $date): string
    {
        return $date->format('m/d/Y');
    }

    private function intacctDateTime(DateTimeInterface $date): string
    {
        return $date->format('m/d/Y H:i:s');
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        // Intacct hands dates back as MM/DD/YYYY, which `new DateTime()` reads correctly only
        // because of the slashes — the same string with dashes would be read as D/M/Y.
        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
