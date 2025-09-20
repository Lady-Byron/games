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
        $qp   = $request->getQueryParams();
        $raw  = (string) Arr::get($qp, 'slug', '');
        $slug = trim(rawurldecode($raw), " \t\n\r\0\x0B/");

        $actor = RequestUtil::getActor($request);

        if ($slug === '' || !preg_match('~^[a-z0-9_-]+$~i', $slug)) {
            return new HtmlResponse('Invalid slug', 400);
        }

        // 未登录 -> 绝对首页 URL（避免 /login GET 405）
        if ($actor->isGuest()) {
            $home = $this->url->to('forum')->base(); // e.g. https://forum.example.com
            return new RedirectResponse($home, 302);
        }

        $base = $this->paths->storage . DIRECTORY_SEPARATOR . 'games';

        $chain = new EngineChain([
            new InkEngine($base),
            new TwineEngine($base),
        ]);

        $resolved = $chain->locate($slug);
        if (!$resolved->exists) {
            return new HtmlResponse('Game not found', 404);
        }

        // debug 仅管理员可见，且不回显绝对路径
        $debugRequested = !empty(Arr::get($qp, 'debug'));
        $debugAllowed   = $debugRequested && $actor->isAdmin();
        if ($debugAllowed) {
            $lines = [
                'DEBUG (admin only)',
                'slug=' . $slug,
                'engine=' . $resolved->engine,
                'shape=' . $resolved->shape,
            ];
            return new HtmlResponse(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $file = $resolved->file;

        $html = @file_get_contents($file);
        if ($html === false) {
            return new HtmlResponse('Failed to load', 500);
        }

        if (empty(Arr::get($qp, 'noinject'))) {
            $username = (string) $actor->username;
            $inject   = '<script>window.ForumUser=' . json_encode($username, JSON_UNESCAPED_UNICODE) . ';</script>';

            $count = 0;
            $html  = preg_replace('~</body>~i', $inject . '</body>', $html, 1, $count);
            if ($count === 0) {
                $html = preg_replace('~</head>~i', $inject . '</head>', $html, 1, $count);
                if ($count === 0) $html = $inject . $html;
            }
        }

        return new HtmlResponse($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
