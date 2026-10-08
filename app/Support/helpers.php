<?php

declare(strict_types=1);

if (!function_exists('now')) {
    /** The current moment, as Eloquent's own `now()` would give it outside of Laravel (illuminate/support has none). */
    function now(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now();
    }
}
