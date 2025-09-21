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
        // === 路由参数读取（Flarum 会合并到 query params）+ URL 解码（支持空格/中文） ===
        $qp   = $request->getQueryParams();
        $slug = trim((string) rawurldecode((string) Arr::get($qp, 'slug', '')), " \t\n\r\0\x0B/");
        $path = (string) Arr::get($qp, 'path', '');
        $path = ltrim(str_replace('\\', '/', rawurldecode($path)), '/'); // 规范化 & 禁止以 / 开头逃逸

        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            // 未登录 -> 跳首页（与现有行为一致）
            $home = $this->url->to('forum')->base();
            return new RedirectResponse($home, 302);
        }

        // 仅拦截目录上跳；slug 保持白名单
        if ($slug === '' || !preg_match('~^[a-z0-9_-]+$~i', $slug) || str_contains($path, '..')) {
            return new HtmlResponse('Invalid path', 400);
        }

        // === 以真实路径前缀做沙箱校验（防目录穿越/软链逃逸） ===
        $baseDir  = $this->paths->storage . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR . $slug;
        $baseReal = realpath($baseDir);
        if ($baseReal === false) {
            return new HtmlResponse('Asset not found', 404);
        }

        $fullReal = realpath($baseReal . DIRECTORY_SEPARATOR . $path);
        if ($fullReal === false
            || strpos($fullReal, $baseReal . DIRECTORY_SEPARATOR) !== 0
            || !is_file($fullReal) || !is_readable($fullReal)) {
            return new HtmlResponse('Asset not found', 404);
        }

        // === MIME（补全音频/播放列表） ===
        $ext  = strtolower(pathinfo($fullReal, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            // 前端常用
            'js'   => 'application/javascript',
            'css'  => 'text/css; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            // 图片
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            // 音频 / 播放列表（关键）
            'mp3'  => 'audio/mpeg',
            'ogg'  => 'audio/ogg',
            'wav'  => 'audio/wav',
            'm4a'  => 'audio/mp4',
            'aac'  => 'audio/aac',
            'flac' => 'audio/flac',
            'm3u'  => 'audio/x-mpegurl',
            'm3u8' => 'application/vnd.apple.mpegurl',
            // 其他
            'mp4'  => 'video/mp4',
            default => 'application/octet-stream',
        };

        // 读取并返回（保持你原先的内存响应方式，改动最小）
        $bytes = @file_get_contents($fullReal);
        if ($bytes === false) {
            return new HtmlResponse('Asset read error', 500);
        }

        $res = new \Laminas\Diactoros\Response('php://memory', 200, [
            'Content-Type'              => $mime,
            'Content-Length'            => (string) strlen($bytes),
            'X-Content-Type-Options'    => 'nosniff',
        ]);
        $res->getBody()->write($bytes);
        return $res;
    }
}

