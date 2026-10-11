<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * The URL prefix requests arrive under ("/shop"; "" at the root), so the app also runs from a sub-folder or through the
 * root .htaccess fallback. APP_BASE_PATH overrides the detection.
 */
final class BasePath
{
    private const FRONT_CONTROLLER = '/index.php';

    public static function detect(string $configured, string $scriptName, string $sapi): string
    {
        $configured = trim($configured, '/');
        if ($configured !== '') {
            return '/' . $configured;
        }

        $scriptName = str_replace('\\', '/', $scriptName);

        // Only a real front controller path ("/shop/public/index.php") tells us anything. The CLI has none,
        // and PHP's built-in dev server reports the *request path* as SCRIPT_NAME, which must not be trusted.
        if ($sapi === 'cli' || $sapi === 'cli-server' || !str_ends_with($scriptName, self::FRONT_CONTROLLER)) {
            return '';
        }

        // dirname() yields "\" for the root on Windows, so normalise after it as well.
        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -strlen('/public'));
        }

        return $dir;
    }
}
