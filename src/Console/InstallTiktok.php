<?php

namespace Sellstream\Tiktok\Console;

use Illuminate\Console\Command;

class InstallTiktok extends Command
{
    protected $signature = 'tiktok:install';

    protected $description = 'Install Tiktok in Sellstream';

    public function handle()
    {
        $adapterTarget = __DIR__.'/../Integrations/Tiktok';
        $adapterLink = app_path('Integrations/Tiktok');

        $this->info("Creating symlink for adapters to {$adapterLink}");

        if (file_exists($adapterLink)) {
            $this->removeDirectory($adapterLink);
        }

        $symlinkGenerated = symlink($adapterTarget, $adapterLink);
        if ($symlinkGenerated) {
            $this->info('Symlink generated for adapters');
        }

        $vueTarget = __DIR__.'/../resources/vue/tiktok';
        $vueLink = resource_path('js/components/integrations/tiktok');

        $this->info("Creating symlink for vue components to {$vueLink}");

        if (file_exists($vueLink)) {
            $this->removeDirectory($vueLink);
        }

        $symlinkGenerated = symlink($vueTarget, $vueLink);
        if ($symlinkGenerated) {
            $this->info('Symlink generated for vue components');
        }

        $imgTarget = __DIR__.'/../images/tiktok.png';
        $imgLink = public_path('images/integrations/tiktok.png');

        $this->info("Creating symlink for integration logo to {$imgLink}");

        if (file_exists($imgLink)) {
            unlink($imgLink);
        }

        $symlinkGenerated = symlink($imgTarget, $imgLink);
        if ($symlinkGenerated) {
            $this->info('Symlink generated for integration logo');
        }
    }

    private function removeDirectory($dir) {
        if (is_dir($dir)) {
            // Check if directory is symbolic
            if (is_link($dir)) {
                unlink($dir);
                return;
            }

            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir."/".$object) == "dir") $this->removeDirectory($dir."/".$object); else unlink($dir."/".$object);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }
}
