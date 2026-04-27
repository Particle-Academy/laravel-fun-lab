<?php

declare(strict_types=1);

namespace LaravelFunLab\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * Artisan command to install and configure Laravel Fun Lab.
 *
 * Handles the complete installation workflow including config publishing,
 * migrations, and optional UI scaffolding for a smooth developer experience.
 */
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lfl:install 
                            {--ui : Install optional UI components (Blade/Livewire)}
                            {--force : Overwrite existing configuration files}
                            {--skip-migrations : Skip running database migrations}
                            {--prefix= : Table prefix to use (default: lfl_)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Laravel Fun Lab gamification package';

    /**
     * Whether we are running in interactive mode.
     */
    protected bool $interactive = true;

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $filesystem): int
    {
        // Determine if we're running interactively
        $this->interactive = $this->isInteractive();

        $this->displayWelcomeBanner();

        // Check for existing installation
        if ($this->configExists() && ! $this->option('force')) {
            if (! $this->promptConfirm('LFL config already exists. Do you want to overwrite it?', false)) {
                $this->warn('Installation aborted. Use --force to overwrite existing files.');

                return self::FAILURE;
            }
        }

        // Get table prefix (from option or prompt)
        $tablePrefix = $this->getTablePrefix();

        // Publish configuration
        $this->publishConfig($tablePrefix);

        // Run migrations unless skipped
        if (! $this->option('skip-migrations')) {
            $this->runMigrations();
        }

        // Install UI components if requested
        if ($this->option('ui')) {
            $this->installUiComponents($filesystem);
        }

        $this->displaySuccessMessage();

        return self::SUCCESS;
    }

    /**
     * Check if the command is running interactively.
     */
    protected function isInteractive(): bool
    {
        // Non-interactive if running in tests or no TTY
        if (app()->runningUnitTests()) {
            return false;
        }

        return $this->input->isInteractive();
    }

    /**
     * Display a welcome banner for the installer.
     */
    protected function displayWelcomeBanner(): void
    {
        $this->newLine();
        $this->components->info('╔══════════════════════════════════════════╗');
        $this->components->info('║       Laravel Fun Lab Installer          ║');
        $this->components->info('║   Analytics disguised as gamification    ║');
        $this->components->info('╚══════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Check if the config file already exists.
     */
    protected function configExists(): bool
    {
        return File::exists(config_path('lfl.php'));
    }

    /**
     * Get the table prefix from option or prompt.
     */
    protected function getTablePrefix(): string
    {
        // If prefix option provided, use it
        if ($prefix = $this->option('prefix')) {
            return $prefix;
        }

        $defaultPrefix = env('LFL_TABLE_PREFIX', 'lfl_');

        // If not interactive, use default
        if (! $this->interactive) {
            return $defaultPrefix;
        }

        $prefix = text(
            label: 'What table prefix would you like to use?',
            placeholder: $defaultPrefix,
            default: $defaultPrefix,
            hint: 'All LFL tables will be prefixed with this value (e.g., lfl_awards)',
        );

        return $prefix ?: $defaultPrefix;
    }

    /**
     * Prompt for confirmation, handling non-interactive mode.
     */
    protected function promptConfirm(string $question, bool $default = true): bool
    {
        if (! $this->interactive) {
            return $default;
        }

        return confirm($question, $default);
    }

    /**
     * Publish the configuration file and update the table prefix.
     */
    protected function publishConfig(string $tablePrefix): void
    {
        $this->info('Publishing configuration...');

        // Publish the config file
        $this->callSilently('vendor:publish', [
            '--tag' => 'lfl-config',
            '--force' => $this->option('force'),
        ]);

        // Update the table prefix in the published config
        $configPath = config_path('lfl.php');
        if (File::exists($configPath)) {
            $contents = File::get($configPath);
            $contents = preg_replace(
                "/env\('LFL_TABLE_PREFIX',\s*'[^']*'\)/",
                "env('LFL_TABLE_PREFIX', '{$tablePrefix}')",
                $contents
            );
            File::put($configPath, $contents);
        }

        $this->info('✓ Configuration published to config/lfl.php');
    }

    /**
     * Run the package migrations.
     */
    protected function runMigrations(): void
    {
        if (! $this->promptConfirm('Would you like to run the database migrations now?', true)) {
            $this->warn('Skipping migrations. Run "php artisan migrate" when ready.');

            return;
        }

        $this->info('Running migrations...');
        $this->callSilently('migrate');
        $this->info('✓ Database migrations completed');
    }

    /**
     * Install optional UI components.
     */
    protected function installUiComponents(Filesystem $filesystem): void
    {
        $this->info('Installing UI components...');

        // Publish views
        $this->callSilently('vendor:publish', [
            '--tag' => 'lfl-views',
            '--force' => $this->option('force'),
        ]);

        // Update config to enable UI
        $configPath = config_path('lfl.php');
        if (File::exists($configPath)) {
            $contents = File::get($configPath);
            $contents = str_replace(
                "'enabled' => false,",
                "'enabled' => true,",
                $contents,
                $count
            );

            // Only update if we found the UI enabled setting
            if ($count > 0) {
                File::put($configPath, $contents);
            }
        }

        $this->info('✓ UI components installed to resources/views/vendor/lfl');
    }

    /**
     * Display the success message with next steps.
     */
    protected function displaySuccessMessage(): void
    {
        $this->newLine();
        $this->components->info('╔══════════════════════════════════════════╗');
        $this->components->info('║   🎉 Laravel Fun Lab installed!         ║');
        $this->components->info('╚══════════════════════════════════════════╝');
        $this->newLine();

        $this->components->bulletList([
            'Config: <comment>config/lfl.php</comment>',
            'Facade: <comment>use LaravelFunLab\Facades\LFL;</comment>',
            'Docs: <comment>https://github.com/particle-academy/laravel-fun-lab</comment>',
        ]);

        $this->newLine();
        $this->line('<fg=cyan>Quick Start:</>');
        $this->newLine();
        $this->line('  1. Add the <comment>Awardable</comment> trait to your User model:');
        $this->newLine();
        $this->line('     <fg=gray>use LaravelFunLab\Traits\Awardable;</>');
        $this->line('     <fg=gray>class User extends Authenticatable</>');
        $this->line('     <fg=gray>{</>');
        $this->line('     <fg=gray>    use Awardable;</>');
        $this->line('     <fg=gray>}</>');
        $this->newLine();
        $this->line('  2. Award points to users:');
        $this->newLine();
        $this->line('     <fg=gray>LFL::award($user)->points(100)->for(\'first_login\')->save();</>');
        $this->newLine();

        if (! $this->option('ui')) {
            $this->line('<fg=yellow>💡 Tip:</> Run <comment>php artisan lfl:install --ui</comment> to add UI components.');
            $this->newLine();
        }
    }
}
