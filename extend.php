<?php

namespace LadyByron\Games;

use Flarum\Extend;
use LadyByron\Games\Controllers\PlayController;
use LadyByron\Games\Controllers\AssetController;

return [
    (new Extend\Routes('forum'))
        ->get('/play/{slug:[^/]+}', 'ladybyron-games.play', PlayController::class)
        ->get('/play/{slug:[^/]+}/asset/{path:.+}', 'ladybyron-games.asset', AssetController::class),
];
