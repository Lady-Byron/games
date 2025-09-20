<?php
namespace LadyByron\Games\Controllers;

use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use LadyByron\Games\Engine\EngineChain;
use LadyByron\Games\Engine\TwineEngine;
use LadyByron\Games\Engine\InkEngine;

final class PlayController implements RequestHandlerInterface
{
    public function __construct(
        private UrlGenerator $url,
        private Paths $paths
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 仍可用 attributes 取路由参数；两种方式在 Flarum 1.x 都有效
        $rp   = (array) $request->getAttribute('routeParameters', []);
        $raw  = (string) Arr::get($rp, 'slug', '');
        $slug = trim(rawurldecode($raw), " \t\n\r\0\x0B/");

        if ($slug === '' || !preg_match('~^[a-z0-9_-]+$~i', $slug)) {
            return new HtmlResponse('Invalid slug', 400);
        }

        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            // 未登录 → 回论坛首页（避免 /login GET 405）
            return new RedirectResponse($this->url->to('forum')->base(), 302);
        }

        // 使用 Paths，而非 base_path()
        $gamesDir = $this->paths->storage . DIRECTORY_SEPARATOR . 'games';
        $index    = $gamesDir . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'index.html';
        $legacy   = $gamesDir . DIRECTORY_SEPARATOR . $slug . '.html';
        $file     = is_file($index) ? $index : $legacy;

        // debug 仅管理员可见，且不泄露物理路径
        $qp    = $request->getQueryParams();
        $debug = !empty(Arr::get($qp, 'debug')) && $actor->isAdmin();
        if ($debug) {
            $shape = is_file($index) ? 'dir' : (is_file($legacy) ? 'legacy' : 'none');
            return new HtmlResponse(
                "DEBUG (admin only)\nslug={$slug}\nengine=" . $this->guessEngine($index, $legacy) . "\nshape={$shape}",
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        if (!is_file($file) || !is_readable($file)) {
            return new HtmlResponse('Game not found', 404);
        }

        $html = @file_get_contents($file);
        if ($html === false) {
            return new HtmlResponse('Failed to load', 500);
        }

        // 注入 ForumUser + ForumAuth（csrf/userId/apiBase），供云存档前端 fetch 使用
        if (empty(Arr::get($qp, 'noinject'))) {
            $username = (string) $actor->username;
            $userId   = (int) $actor->id;

            // 从 session 取 CSRF token（forum 路由天然受 CSRF 中间件保护）
            $session = $request->getAttribute('session');
            $csrf    = method_exists($session, 'token') ? (string) $session->token() : '';

            $auth = [
                'csrf'    => $csrf,
                'userId'  => $userId,
                'apiBase' => '/playapi', // 你在 extend.php 中注册的前缀
            ];

            $inject = '<script>'
                . 'window.ForumUser=' . json_encode($username, JSON_UNESCAPED_UNICODE) . ';'
                . 'window.ForumAuth=' . json_encode($auth, JSON_UNESCAPED_UNICODE) . ';'
                . '</script>';

            $count = 0;
            $html  = preg_replace('~</body>~i', $inject . '</body>', $html, 1, $count);
            if ($count === 0) {
                $html = preg_replace('~</head>~i', $inject . '</head>', $html, 1, $count);
                if ($count === 0) $html = $inject . $html;
            }
        }

        return new HtmlResponse($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function guessEngine(string $index, string $legacy): string
    {
        // 与 EngineChain 行为保持一致的“只读”推断（便于 debug 输出）
        $chain = new EngineChain([
            new InkEngine(dirname($index, 2)),   // 传入 storage/games
            new TwineEngine(dirname($index, 2)),
        ]);

        $slug = basename(dirname($index)); // 从路径回推 slug
        $resolved = $chain->locate($slug);
        return $resolved->engine ?: 'unknown';
    }
}

