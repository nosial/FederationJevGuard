<?php

    namespace FederationJevGuard\Handlers;

    use FederationJevGuard\Classes\ManagementAccess;
    use FederationJevGuard\Classes\Managers\DecisionManager;
    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;

    class GetDecisionHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            ManagementAccess::requireOperator();

            $uuid = self::getPathParameter('uuid');
            if($uuid === null || !Validate::uuid($uuid))
            {
                throw new RequestException('Invalid decision UUID', HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                $decision = DecisionManager::getDecision($uuid);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Failed to get the decision', HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            if($decision === null)
            {
                throw new RequestException('The decision does not exist', HttpResponseCode::NOT_FOUND);
            }

            self::successResponse($decision->toArray());
        }
    }
