<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixStoragePermissionsCommand extends Command
{
    protected $signature = 'storage:fix-permissions
        {--path= : App root (defaults to base_path())}';

    protected $description = 'Ensure Laravel storage/bootstrap cache dirs exist and are writable (fixes Permission denied on file cache)';

    public function handle(): int
    {
        $root = rtrim((string) ($this->option('path') ?: base_path()), DIRECTORY_SEPARATOR);
        $dirs = [
            $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data',
            $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions',
            $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs',
            $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache',
        ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                $this->error("Could not create {$dir}");

                return self::FAILURE;
            }
            @chmod($dir, 0775);
        }

        // Best-effort writable check / probe write for file cache
        $probe = $dirs[0].DIRECTORY_SEPARATOR.'.writable_probe';
        $ok = @file_put_contents($probe, (string) time()) !== false;
        if ($ok) {
            @unlink($probe);
            $this->info('Storage cache is writable.');

            return self::SUCCESS;
        }

        $this->warn('Directories exist but web/CLI user still cannot write to storage/framework/cache/data.');
        $this->line('On the VPS run as root:');
        $this->line("  bash {$root}/deploy/fix-storage-permissions.sh {$root} www-data");

        return self::FAILURE;
    }
}
