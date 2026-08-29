<?php

namespace justinholtweb\erpysage;

use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\erpy\services\Connectors;
use justinholtweb\erpysage\connectors\Sage200Connector;
use justinholtweb\erpysage\connectors\SageAccountingConnector;
use justinholtweb\erpysage\connectors\SageIntacctConnector;
use justinholtweb\erpysage\connectors\SageX3Connector;
use yii\base\Event;

/**
 * Erpy for Sage.
 *
 * A connector add-on and nothing else: no tables, no settings screen, no control panel section.
 * Erpy owns the sync engine, the identity map, the mapping UI and the log; this package's whole
 * job is to translate one vendor's API into Erpy's canonical documents. That is why it is free.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '5.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        Event::on(
            Connectors::class,
            Connectors::EVENT_REGISTER_CONNECTORS,
            static function(RegisterComponentTypesEvent $event) {
                // Sage sells four unrelated products under one name, so this one add-on
                // registers four connectors rather than making a merchant guess which of four
                // plugins they need.
                $event->types[] = SageIntacctConnector::class;
                $event->types[] = Sage200Connector::class;
                $event->types[] = SageX3Connector::class;
                $event->types[] = SageAccountingConnector::class;
            },
        );
    }
}
