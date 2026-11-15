<?php

namespace App\Services\Platform;

/**
 * White-label (wiki 9, "Branding e White-Label"): shows the platform name (e.g. Hostrema) where
 * the dashboard says "Coolify". Applied when Blade templates are compiled, so the upstream views
 * stay untouched; identifiers such as CoolifyTask, coolify-proxy or coolify.io are not changed.
 */
class PlatformBranding
{
    private const SAFE_NAME = '/^[\pL\pN][\pL\pN .&_-]{0,39}$/u';

    public static function enabled(): bool
    {
        return isPlatform() && (bool) config('platform.branding.enabled', true);
    }

    public static function name(): ?string
    {
        $name = trim((string) config('platform.name'));

        return $name !== '' && $name !== 'Coolify' && preg_match(self::SAFE_NAME, $name) ? $name : null;
    }

    public static function apply(string $template): string
    {
        if (is_null(self::name()) || ! str_contains($template, 'Coolify')) {
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
        $favicon = (string) config('platform.branding.favicon_url');
        if ($favicon !== '' && ! str_contains($favicon, "'")) {
            $template = str_replace(["asset('coolify-logo.svg')", "asset('coolify-logo-dev-transparent.png')"], "'".$favicon."'", $template);
        }

        return self::text($template);
    }

    public static function text(string $value): string
    {
        $name = self::name();
        if (is_null($name)) {
            return $value;
        }

        return (string) preg_replace('/(?<![\w\\\\$\/.:@-])Coolify(?![\w\\\\(:\/-])/u', $name, $value);
    }
}
