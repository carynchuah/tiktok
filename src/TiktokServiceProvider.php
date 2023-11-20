<?php

namespace Sellstream\Tiktok;

use Illuminate\Support\ServiceProvider;
use Sellstream\Tiktok\Console\InstallTiktok;

class TiktokServiceProvider extends ServiceProvider
{
    public function boot()
    {
        # code...
    }

    public function register()
    {
        $this->commands([
            InstallTiktok::class
        ]);

        $this->mergeConfigFrom(
            __DIR__.'/config/sellstream.php', 'sellstream'
        );
    }
}
