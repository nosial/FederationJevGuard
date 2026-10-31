<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Enums\DatabaseTables;
    use FederationJevGuard\Handlers\ContentScanHandler;
    use FederationJevGuard\Handlers\MaintenanceHandler;
    use FederationJevGuard\Handlers\QueryEntityHandler;
    use FederationJevGuard\Handlers\RecordChangeHandler;
    use FederationJevGuard\Handlers\StartupHandler;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Enums\EventType;
    use FederationLib\Objects\Plugin;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    /**
     * Tests the plugin through FederationLib's plugin system
     */
    class PluginTest extends TestCase
    {
        private const string PACKAGE = 'net.nosial.federation_jevguard';

        protected function tearDown(): void
        {
            PluginManager::setPlugins([]);
            Logger::unregisterHandlers();
        }

        public function testPluginIsValid(): void
        {
            $plugin = Plugin::load(self::PACKAGE);

            // Scanned content is classified but never stored
            $this->assertFalse($plugin->collectsScanContent());

            // The plugin registers no request handlers, it only handles events
            $this->assertSame([], $plugin->getRequestHandlers());

            $this->assertSame(StartupHandler::class, $plugin->getEventHandlers(EventType::STARTUP)[0]->getClass());
            $this->assertSame(MaintenanceHandler::class, $plugin->getEventHandlers(EventType::MAINTENANCE)[0]->getClass());
            $this->assertSame(ContentScanHandler::class, $plugin->getEventHandlers(EventType::CONTENT_SCAN)[0]->getClass());
            $this->assertSame(QueryEntityHandler::class, $plugin->getEventHandlers(EventType::QUERY_ENTITY)[0]->getClass());
            $this->assertSame(RecordChangeHandler::class, $plugin->getEventHandlers(EventType::RECORD_CHANGE)[0]->getClass());
            $this->assertEqualsCanonicalizing(['REPORT_CREATED', 'REPORT_CLOSED', 'EVIDENCE_CREATED', 'ATTACHMENT_CREATED', 'ENTITY_UPDATED', 'ENTITY_DELETED', 'BLACKLIST_LIFTED', 'BLACKLIST_DELETED'],
                $plugin->getEventHandlers(EventType::RECORD_CHANGE)[0]->getFilter());
            $this->assertSame([], $plugin->getEventHandlers(EventType::AUDIT_LOG));
        }

        public function testSchemaFilesAreBundled(): void
        {
            foreach(DatabaseTables::getOrderedTables() as $table)
            {
                $this->assertFileExists($table->getPath());
                $this->assertStringContainsString('create table ' . $table->getTableName(), file_get_contents($table->getPath()));
            }
        }
    }
