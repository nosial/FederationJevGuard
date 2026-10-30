<?php

    namespace FederationJevGuard\Handlers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\Logger;
    use FederationJevGuard\Classes\Managers\DecisionManager;
    use FederationJevGuard\Classes\PluginOperator;
    use FederationJevGuard\Enums\SubjectType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\QueryEntityEventHandlerInterface;
    use FederationLib\Objects\Plugin\EntityQuery;

    class QueryEntityHandler implements QueryEntityEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleQueryEntity(EntityQuery $entityQuery): void
        {
            if(!Configuration::isEnabled() || !PluginOperator::isActive())
            {
                return;
            }

            try
            {
                $decision = DecisionManager::getLatestDecision(SubjectType::ENTITY, $entityQuery->getEntityRecord()->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->warning('Unable to read the decisions of a queried entity: ' . $e->getMessage());
                return;
            }

            if($decision === null)
            {
                return;
            }

            $entityQuery->addEntityMetadata([
                'jevguard_verdict' => $decision->getVerdict(),
                'jevguard_outcome' => $decision->getOutcome()->value,
                'jevguard_probability' => $decision->getProbability() !== null ? round($decision->getProbability(), 2) : null,
                'jevguard_decided' => $decision->getCreated(),
            ]);
        }
    }
