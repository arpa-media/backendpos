<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class ConsoleRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function(): void {
            $files=glob(base_path('routes/console_modules/*.php')) ?: []; sort($files,SORT_STRING); foreach($files as $file) require $file;
        });
    }
}
