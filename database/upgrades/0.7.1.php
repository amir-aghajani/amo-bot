<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.7.0 database to 0.7.1. See docs/Database.md for the rules an upgrade keeps.
 *
 * It changes only what it finds as 0.7.0 left it, so a run that stopped half-way is simply run again.
 */
return static function (Builder $schema, Connection $db): void {
    // —— A card's amount to copy is in Rial now (Money::rials()), as Iran's banks take it. An owner's own wording of the
    // card texts that shows that amount in Toman digits — the default's `<code>%amount_digits%</code> تومان`, which a tap
    // copies — shows it in Rial instead, as the copy button copies it; the rest of the wording stays as they wrote it. ——
    $toman = '<code>%amount_digits%</code> تومان';
    $rial = '<code>%amount_rial%</code> ریال';
    foreach ($db->table('settings')->whereIn('key', ['bot.text.card_instructions', 'bot.text.pay_online_card'])->get(['id', 'value']) as $row) {
        $wording = is_string($row->value) ? json_decode($row->value, true) : null;
        if (is_string($wording) && str_contains($wording, $toman)) {
            $db->table('settings')->where('id', $row->id)->update([
                'value' => json_encode(str_replace($toman, $rial, $wording), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }
};
