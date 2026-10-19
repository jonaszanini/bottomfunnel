<?php

namespace App\Support;

class Options
{
    /**
     * @return array<string, mixed>
     */
    public static function forForms(): array
    {
        return [
            'pageTypes' => config('flowhub.page_types'),
            'strategies' => config('flowhub.strategies'),
            'platforms' => config('flowhub.platforms'),
            'languages' => config('flowhub.languages'),
        ];
    }
}
