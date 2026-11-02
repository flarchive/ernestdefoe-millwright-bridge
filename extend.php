<?php

use ErnestDefoe\MillwrightBridge\Api\BridgeController;
use ErnestDefoe\MillwrightBridge\BridgeServiceProvider;
use ErnestDefoe\MillwrightBridge\Console\UpgradeCommand;
use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\Routes('api'))
        ->get('/bridge', 'bridge.check', BridgeController::class)
        ->post('/bridge', 'bridge.act', BridgeController::class),

    (new Extend\Console())
        ->command(UpgradeCommand::class),

    (new Extend\ServiceProvider())
        ->register(BridgeServiceProvider::class),
];
