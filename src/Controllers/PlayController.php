<?php
namespace LadyByron\Games;

use Flarum\Extend;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PlayController implements RequestHandlerInterface
{
    public function __construct(protected UrlGenerator $url) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 优先使用 route parameters（与你之前的修复保持一致）
        $rp   = (array) $request->getAttribute('routeParameters', []);
        $raw  = (string) Arr::get($rp, 'slug', '');
        $slug = trim(rawurldecode($raw), " \t\n\r\0\x0B/");

        $qp    = $request->getQueryParams();
        $debug = !empty(Arr::get($qp, 'debug'));

        if ($slug === '' || !preg_match('~^[a-z0-9_-]+$~i', $slug)) {
            return new HtmlResponse('Invalid slug', 400);
        }

        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            // 未登录：按你的要求 → 跳首页
            return new RedirectResponse($this->url->to('forum')->base(), 302);
        }

        // 统一使用 storage/games 目录
        $base   = base_path() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'games';
        $index  = $base . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'index.html';
        $legacy = $base . DIRECTORY_SEPARATOR . $slug . '.html';
        $file   = is_file($index) ? $index : $legacy;

        if ($debug && $actor->isAdmin()) {
            // 管理员可见的最小化 debug，不再泄露物理路径
            $shape = is_file($index) ? 'dir' : (is_file($legacy) ? 'legacy' : 'none');
            return new HtmlResponse(
                "DEBUG (admin only)\nslug={$slug}\nshape={$shape}",
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        if (!is_file($file) || !is_readable($file)) {
            return new HtmlResponse('Game not found', 404);
        }

        $html = file_get_contents($file);
        if ($html === false) {
            return new HtmlResponse('Failed to load', 500);
        }

        // 注入 ForumUser + ForumAuth（csrf/userId/apiBase）
        if (empty(Arr::get($qp, 'noinject'))) {
            $username = (string) $actor->username;
            $userId   = (int) $actor->id;

            // 从 session 取 CSRF token（供前端 X-CSRF-Token 使用）
            $session = $request->getAttribute('session');
            $csrf    = method_exists($session, 'token') ? (string) $session->token() : '';

            $auth = [
                'csrf'    => $csrf,
                'userId'  => $userId,
                'apiBase' => '/playapi', // 统一前缀，见 extend.php
            ];

            $inject = '<script>'.
                'window.ForumUser='.json_encode($username, JSON_UNESCAPED_UNICODE).';'.
                'window.ForumAuth='.json_encode($auth, JSON_UNESCAPED_UNICODE).';'.
                '</script>';

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
