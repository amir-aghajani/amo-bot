<?php

declare(strict_types=1);

if (!function_exists('now')) {
    /** The current moment, as Laravel's `now()` gives it (illuminate/support alone does not define it). */
    function now(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now();
    }
}
