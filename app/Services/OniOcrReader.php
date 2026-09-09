<?php

namespace App\Services;

interface OniOcrReader
{
    /** Return JSON evidence in memory only; throw on unavailable/unsupported input. */
    public function read(string $path, ?string $portraitPath = null): string;
}
