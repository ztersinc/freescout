<?php
/**
 * php artisan freescout:module-install modulealias.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ModuleUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout:module-update {module_alias?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update all modules or a single module (if module_alias is set)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $install_all = false;
        $modules = [];

        // We have to clear modules cache first to update modules cache
        \Artisan::call('cache:clear');

        // Create a symlink for the module (or all modules)
        $module_alias = $this->argument('module_alias');
        
        $modules_directory = \WpApi::getModules();
        if (\WpApi::$lastError) {
            $this->error(__('Error occurred').': '.$lastError['message'].' ('.$lastError['code'].')');
            return;
        }

        $installed_modules = \Module::all();

        $counter = 0;
        $found = false;
        foreach ($modules_directory as $dir_module) {
            // Update single module.
            if ($module_alias && $dir_module['alias'] != $module_alias) {
                continue;
            }
            
            $found = true;

            // Detect if new version is available.
            foreach ($installed_modules as $module) {
                if ($module->getAlias() != $dir_module['alias'] || !$module->active()) {
                    continue;
                }
                if (!empty($dir_module['version']) && version_compare($dir_module['version'], $module->get('version'), '>')) {
                    $update_result = \App\Module::updateModule($dir_module['alias']);

                    $this->info('['.$update_result['module_name'].' Module'.']');
                    if ($update_result['status'] == 'success') {
                        $this->line($update_result['msg_success']);
                    } else {
                        $msg = $update_result['msg'];
                        if ($update_result['download_msg']) {
                            $msg .= ' ('.$update_result['download_msg'].')';
                        }
                        $this->error('ERROR: '.$msg);
                    }
                    if (trim($update_result['output'])) {
                        $this->line(preg_replace("#\n#", "\n> ", '> '.trim($update_result['output'])));
                    }

                    $counter++;
                }
            }
        }

        // Loop through each installed module
        foreach ($installed_modules as $module) {
            // Skip if the module is an official one
            if (\App\Module::isOfficial($module->get('authorUrl'))) {
                continue;
            }

            // Get the URL for the latest version of the module
            $latest_version_number_url = $module->get('latestVersionUrl');
            if (! $latest_version_number_url) {
                continue;
            }

            // Create a new Guzzle HTTP client
            $client = new \GuzzleHttp\Client();

            try {
                // Send a GET request to the latest version URL
                $response = $client->request('GET', $latest_version_number_url, \Helper::setGuzzleDefaultOptions());

                // Get the latest version number from the response body
                $latest_version = trim((string) $response->getBody());

                if (empty($latest_version)) {
                    continue;
                } else {
                    // Get the current version of the module
                    $current_version = $module->get('version');
                }
            } catch (\Exception $e) {
                // If there's an exception, skip to the next iteration
                continue;
            }

            // If the latest version is greater than the current version
            if (version_compare($latest_version, $current_version, '>')) {
                // Update the module
                $update_result = \App\Module::updateModule($module->getAlias());

                // Print the module name and status
                $this->info('[' . $update_result['module_name'] . ' Module' . ']');
                if ($update_result['status'] == 'success') {
                    // If the update was successful, print the success message
                    $this->line($update_result['msg_success']);
                } else {
                    // If the update failed, print the error message
                    $msg = $update_result['msg'];
                    if ($update_result['download_msg']) {
                        $msg .= ' (' . $update_result['download_msg'] . ')';
                    }
                    $this->error('ERROR: ' . $msg);
                }
                // If there's any output from the update, print it
                if (trim($update_result['output'])) {
                    $this->line(preg_replace("#\n#", "\n> ", '> ' . trim($update_result['output'])));
                }

                // Increment the counter
                $counter ++;
            }
        }

        if ($module_alias && !$found) {
            $this->error('Module with the following alias not found: '.$module_alias);
        } elseif (!$counter) {
            $this->line('All modules are up-to-date');
        }

        \Artisan::call('freescout:clear-cache');
    }
}
