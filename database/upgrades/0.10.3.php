<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.10.x database to 0.10.3: an agent's request for their agency's end to be moved on («درخواست تمدید
 * نمایندگی») is an agency request of its own kind — `agency_requests.kind`, every request before it a request to become an
 * agent (`join`) —, and its approval keeps the end it gave (`extended_to`). See docs/Database.md for the rules an upgrade
 * keeps.
 *
 * Each column is added only while it is missing, so a run that stopped half-way is simply run again.
 */
return static function (Builder $schema, Connection $db): void {
    if (!$schema->hasColumn('agency_requests', 'kind')) {
        $schema->table('agency_requests', static function (Blueprint $table): void {
            $table->string('kind', 16)->default('join');
        });
    }
    if (!$schema->hasColumn('agency_requests', 'extended_to')) {
        $schema->table('agency_requests', static function (Blueprint $table): void {
            $table->timestamp('extended_to')->nullable();
        });
    }
};
