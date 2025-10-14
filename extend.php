<?php

use Flarum\Extend;
use LadyByron\Games\Controllers\PlayController;
use LadyByron\Games\Controllers\AssetController;

return [
    // 前台（forum）路由
    (new Extend\Routes('forum'))
        // 游戏入口：/play/{slug}
        // 如：/play/raise-the-lord
        ->get('/play/{slug:[A-Za-z0-9_-]+}', 'games.play', PlayController::class)

        // 静态资源：/play/{slug}/asset/{path}
        // 如：/play/raise-the-lord/asset/css/solid.min.css
        //     /play/raise-the-lord/asset/webfonts/fa-solid-900.woff2
        ->get('/play/{slug:[A-Za-z0-9_-]+}/asset/{path:.+}', 'games.asset', AssetController::class),
];

