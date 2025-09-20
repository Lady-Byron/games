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
        // 按官方推荐，从 route attributes 读取路由参数
        $rp   = (array) $request->getAttribute('routeParameters', []);
        $raw  = (string) Arr::get($rp, 'slug', '');
        $slug = trim(rawurldecode($raw), " \t\n\r\0\x0B/");

        if ($slug === '' || !preg_match('~^[a-z0-9_-]+$~i', $slug)) {
            return new HtmlResponse('Invalid slug', 400);
        }

        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            // 访客直接回论坛首页（避免 /login GET 405）
            return new RedirectResponse($this->url->to('forum')->base(), 302);
        }

        // 统一使用 Paths::storage，避免硬编码 base_path()
        $gamesDir = $this->paths->storage . DIRECTORY_SEPARATOR . 'games';
        $index    = $gamesDir . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'index.html';
        $legacy   = $gamesDir . DIRECTORY_SEPARATOR . $slug . '.html';
        $file     = is_file($index) ? $index : $legacy;

        // 仅管理员可见的 debug，且不回显物理路径，防止目录结构泄露
        $qp    = $request->getQueryParams();
        $debug = !empty(Arr::get($qp, 'debug')) && $actor->isAdmin();
        if ($debug) {
            $shape = is_file($index) ? 'dir' : (is_file($legacy) ? 'legacy' : 'none');
            return new HtmlResponse(
                "DEBUG (admin only)\nslug={$slug}\nengine=" . $this->guessEngine($gamesDir, $slug) . "\nshape={$shape}",
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

        // 注入 ForumUser 与 ForumAuth（含 CSRF），供前端 fetch /playapi/* 使用
        if (empty(Arr::get($qp, 'noinject'))) {
            $username = (string) $actor->username;
            $userId   = (int) $actor->id;

            // 从会话取 CSRF：forum 管道下会有 session attribute
            $session = $request->getAttribute('session');
            $csrf    = (is_object($session) && method_exists($session, 'token')) ? (string) $session->token() : '';

            $auth = [
                'csrf'    => $csrf,
                'userId'  => $userId,
                'apiBase' => '/playapi', // 你的扩展为云存档注册的 API 前缀
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

    private function guessEngine(string $gamesDir, string $slug): string
    {
        // 与 EngineChain 行为一致的“只读”推断
        $chain = new EngineChain([
            new InkEngine($gamesDir),
            new TwineEngine($gamesDir),
        ]);

        $resolved = $chain->locate($slug);
        return $resolved->engine ?: 'unknown';
    }
}
