<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.10.x database to 0.11.0: a discount code and a sale may hold for plan categories as well as plans — the
 * categories each names (`discount_codes.category_ids`, `sales.category_ids`), every one before it naming none —, and
 * each bot's webhook address kept in its settings (`telegram.webhook_url`) loses its secret. See docs/Database.md for the
 * rules an upgrade keeps.
 *
 * Each column is added only while it is missing, and an address is masked only while its secret is there, so a run that
 * stopped half-way is simply run again.
 */
return static function (Builder $schema, Connection $db): void {
    if (!$schema->hasColumn('discount_codes', 'category_ids')) {
        $schema->table('discount_codes', static function (Blueprint $table): void {
            $table->json('category_ids')->nullable();
        });
    }
    if (!$schema->hasColumn('sales', 'category_ids')) {
        $schema->table('sales', static function (Blueprint $table): void {
            $table->json('category_ids')->nullable();
        });
    }

    // —— A bot put on its webhook kept the whole address, its secret the last segment of the path, in its shop's settings
    // — which every backup holds, and a shop's admin downloads. Only whether there is one is read: the secret is masked as
    // the app masks it in a log line (Core\Logging\Redact), the main bot's (`/webhooks/telegram/<secret>`) and each
    // agent's (`/webhooks/telegram/bot/<id>/<secret>`) alike. ——
    $secret = '~(/webhooks/telegram/(?:bot/[^/\s?#"\']+/)?)[^/\s?#"\']++(?!/)~';
    foreach ($db->table('settings')->where('key', 'telegram.webhook_url')->get(['id', 'value']) as $row) {
        $url = is_string($row->value) ? json_decode($row->value, true) : null;
        $masked = is_string($url) ? (string) preg_replace($secret, '$1***', $url) : null;
        if ($masked !== null && $masked !== $url) {
            $db->table('settings')->where('id', $row->id)->update([
                'value' => json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }
};
