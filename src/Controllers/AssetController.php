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

final class AssetController implements RequestHandlerInterface
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
        $path = (string) Arr::get($qp, 'path', '');

        $actor = RequestUtil::getActor($request);

        // 未登录 -> 绝对首页 URL
        if ($actor->isGuest()) {
            $home = $this->url->to('forum')->base();
            return new RedirectResponse($home, 302);
        }

        if ($slug === '' || !preg_match('~^[a-z0-9_-]+$~i', $slug) || str_contains($path, '..')) {
            return new HtmlResponse('Invalid path', 400);
        }

        $dir  = $this->paths->storage . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR . $slug;
        $full = realpath($dir . DIRECTORY_SEPARATOR . $path);

        if ($full === false || !str_starts_with($full, $dir . DIRECTORY_SEPARATOR) || !is_file($full) || !is_readable($full)) {
            return new HtmlResponse('Asset not found', 404);
        }

        $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'js'   => 'application/javascript',
            'css'  => 'text/css; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'mp3'  => 'audio/mpeg',
            'ogg'  => 'audio/ogg',
            'mp4'  => 'video/mp4',
            'html' => 'text/html; charset=UTF-8',
            default => 'application/octet-stream',
        };

        $bytes = @file_get_contents($full);
        if ($bytes === false) {
            return new HtmlResponse('Asset read error', 500);
        }

        $res = new \Laminas\Diactoros\Response('php://memory', 200, [
            'Content-Type'   => $mime,
            'Content-Length' => (string) strlen($bytes),
        ]);
        $res->getBody()->write($bytes);
        return $res;
    }
}

