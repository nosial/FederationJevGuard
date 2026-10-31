<?php

    require 'ncc';

    // Resolve the dependency here so direct PHPUnit and IDE runs use the same build as make test.
    $federationBuildOutputPath = getenv('FEDERATIONLIB_BUILD_OUTPUT_PATH');
    if(!$federationBuildOutputPath)
    {
        $localFederationBuildOutputPath = __DIR__ . '/../../FederationLib/target/release/net.nosial.federation.ncc';
        $federationBuildOutputPath = is_file($localFederationBuildOutputPath)
            ? $localFederationBuildOutputPath
            : 'net.nosial.federation';
    }

    import($federationBuildOutputPath);
    if((new ReflectionMethod('FederationLib\\Classes\\Managers\\ReportManager', 'closeReport'))->getNumberOfParameters() < 2 || !defined('FederationLib\\Enums\\EventType::STARTUP') || !defined('FederationLib\\Enums\\EventType::MAINTENANCE'))
    {
        throw new RuntimeException('FederationJevGuard requires FederationLib with lifecycle events and ReportManager::closeReport() closing reports on behalf of an operator. Set FEDERATIONLIB_BUILD_OUTPUT_PATH to a current FederationLib build.');
    }

    $buildOutputPath = getenv('NCC_BUILD_OUTPUT_PATH') ?: __DIR__ . '/../target/release/net.nosial.federation_jevguard.ncc';
    if(!file_exists($buildOutputPath))
    {
        throw new Exception('Build output not found: ' . $buildOutputPath);
    }

    import($buildOutputPath);

    // Keep the tests' configuration separate from any server installed on this machine.
    if(!getenv('CONFIGLIB_PATH'))
    {
        putenv('CONFIGLIB_PATH=' . __DIR__ . '/../target/tests/configlib');
    }

    // ConfigLib falls back to the user's own configuration when the directory doesn't exist
    if(!is_dir(getenv('CONFIGLIB_PATH')))
    {
        mkdir(getenv('CONFIGLIB_PATH'), 0777, true);
    }

    foreach(glob(__DIR__ . '/FederationJevGuard/Helpers/*.php') as $helper)
    {
        require_once $helper;
    }

    \LogLib2\Logger::unregisterHandlers();
