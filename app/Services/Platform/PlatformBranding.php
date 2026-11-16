<?php

namespace App\Services\Platform;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * White-label (wiki 9, "Branding e White-Label"): shows the platform name (e.g. Hostrema) where
 * the dashboard says "Coolify". Applied when Blade templates are compiled, so the upstream views
 * stay untouched; identifiers such as CoolifyTask, coolify-proxy or coolify.io are not changed.
 *
 * Text built at runtime gets the same treatment where it leaves the application: page titles,
 * notifications of the dashboard (toasts), e-mail subjects, chat notifications (Discord, Slack,
 * Telegram, Pushover) and deployment logs. The logo is the configured one or a mark drawn from
 * the platform name (/platform/brand/logo.svg), never the Coolify logo.
 */
class PlatformBranding
{
    /**
     * Bump when the template rewrites change, so the compiled views are discarded.
     */
    public const VERSION = 2;

    public const LOGO_PATH = '/platform/brand/logo.svg';

    private const SAFE_NAME = '/^[\pL\pN][\pL\pN .&_-]{0,39}$/u';

    /**
     * Chat services Coolify sends notifications to.
     */
    private const NOTIFICATION_HOSTS = ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com', 'hooks.slack.com', 'api.telegram.org', 'api.pushover.net'];

    /**
     * Livewire events shown as notifications of the dashboard.
     */
    private const TOAST_EVENTS = ['success', 'error', 'warning', 'info'];

    public static function enabled(): bool
    {
        return isPlatform() && (bool) config('platform.branding.enabled', true);
    }

    public static function name(): ?string
    {
        $name = trim((string) config('platform.name'));

        return $name !== '' && $name !== 'Coolify' && preg_match(self::SAFE_NAME, $name) ? $name : null;
    }

    /**
     * What the compiled views depend on.
     */
    public static function signature(): string
    {
        return md5(json_encode([
            self::VERSION, config('platform.name'), config('platform.branding.enabled'), config('platform.branding.description'),
            config('platform.branding.favicon_url'), config('platform.branding.docs_url'),
        ]) ?: '');
    }

    /**
     * The configured logo, or the mark drawn from the platform name.
     */
    public static function logoUrl(): string
    {
        $configured = trim((string) config('platform.branding.favicon_url'));

        return $configured !== '' && ! preg_match('/[\'"<>\s]/', $configured) ? $configured : self::LOGO_PATH;
    }

    public static function apply(string $template): string
    {
        if (is_null(self::name()) || ! str_contains(strtolower($template), 'coolify')) {
            return $template;
        }
        $description = (string) config('platform.branding.description');
        if ($description !== '') {
            $template = str_replace(
                'Coolify: An open-source & self-hostable Heroku / Netlify / Vercel alternative',
                e($description),
                $template,
            );
        }
        $logo = self::logoUrl();
        $template = str_replace(
            ["asset('coolify-logo.svg')", "asset('coolify-logo-dev-transparent.png')", "'/coolify-logo.svg'"],
            ["'{$logo}'", "'{$logo}'", "'{$logo}'"],
            $template,
        );
        // Page titles given by components at runtime (Livewire #[Title]).
        $template = (string) preg_replace('/\{\{\s*\$title\s*\?\?\s*/', '{{ \\App\\Services\\Platform\\PlatformBranding::text($title ?? null) ?? ', $template);
        // Documentation of the platform, when it has one.
        $docs = rtrim(trim((string) config('platform.branding.docs_url')), '/');
        if ($docs !== '' && ! preg_match('/[\'"<>\s]/', $docs)) {
            $template = (string) preg_replace('#https://coolify\.io/docs(?:/[^\s"\'<>)`]*)?#', $docs, $template);
        }
        // Example domains in the field helpers.
        $template = str_replace(['app.coolify.io', 'cloud.coolify.io'], ['app.example.com', 'cloud.example.com'], $template);

        return self::text($template);
    }

    public static function text(?string $value): ?string
    {
        $name = self::name();
        if (is_null($value) || is_null($name) || ! str_contains($value, 'Coolify')) {
            return $value;
        }

        // Not part of an identifier (CoolifyTask, App\Coolify\..., Coolify::, coolify.io,
        // /coolify-logo); "Coolify: ..." is text, and so is an escaped quote ("Coolify\'s" inside
        // the attributes Blade compiles into PHP strings).
        return (string) preg_replace('/(?<![\w\\\\$\/.:@-])Coolify(?![\w(\/-])(?!::)(?!\\\\\w)/u', $name, $value);
    }

    /**
     * The messages Coolify itself writes into the deployment logs (the output of the customer's
     * build is left as it is).
     */
    public static function deploymentLogs(string $logs): string
    {
        $name = self::name();

        return is_null($name) ? $logs : str_replace("on Coolify's UI", "on {$name}'s UI", $logs);
    }

    /**
     * Notifications the components dispatch to the dashboard (Livewire "dispatches" effect).
     *
     * @param  array<int, mixed>  $dispatches
     * @return array<int, mixed>
     */
    public static function dispatches(array $dispatches): array
    {
        foreach ($dispatches as $index => $dispatch) {
            if (! is_array($dispatch) || ! in_array($dispatch['name'] ?? null, self::TOAST_EVENTS, true) || ! is_array($dispatch['params'] ?? null)) {
                continue;
            }
            $dispatches[$index]['params'] = self::brandStrings($dispatch['params']);
        }

        return $dispatches;
    }

    /**
     * Chat notifications are written by Coolify with its name in the title and the text.
     */
    public static function request(RequestInterface $request): RequestInterface
    {
        if (! self::enabled() || is_null(self::name()) || ! in_array(strtolower($request->getUri()->getHost()), self::NOTIFICATION_HOSTS, true)) {
            return $request;
        }
        $body = (string) $request->getBody();
        if (! str_contains($body, 'Coolify') || ! str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json')) {
            return $request;
        }
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return $request;
        }
        $branded = (string) json_encode(self::brandStrings($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $request->withBody(Utils::streamFor($branded))->withHeader('Content-Length', (string) strlen($branded));
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private static function brandStrings(array $values): array
    {
        array_walk_recursive($values, function (mixed &$value) {
            if (is_string($value)) {
                $value = self::text($value);
            }
        });

        return $values;
    }

    /**
     * Square mark with the first letter of the platform name, used when no logo is configured.
     */
    public static function logoSvg(): string
    {
        $name = self::name() ?? (string) config('platform.name', 'H');
        $initial = mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : 'H', 0, 1));

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="'.e($name).'">'
            .'<rect width="64" height="64" rx="14" fill="#0f766e"/>'
            .'<text x="32" y="45" text-anchor="middle" font-family="Inter, Segoe UI, Arial, sans-serif" font-size="38" font-weight="700" fill="#ffffff">'.e($initial).'</text>'
            .'</svg>';
    }
}
