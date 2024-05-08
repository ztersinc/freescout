<?php

namespace App\Http\Controllers;

use App\Misc\WpApi;
use Illuminate\Http\Request;
//use Nwidart\Modules\Traits\CanClearModulesCache;
use Symfony\Component\Console\Output\BufferedOutput;

class ModulesController extends Controller
{
    //use CanClearModulesCache;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Modules.
     */
    public function modules(Request $request)
    {
        $installed_modules = [];
        $modules_directory = [];
        $third_party_modules = [];
        $all_modules = [];
        $flashes = [];
        $updates_available = false;

        $flash = \Cache::get('modules_flash');
        if ($flash) {
            if (is_array($flash) && !isset($flash['text'])) {
                $flashes = $flash;
            } else {
                $flashes[] = $flash;
            }
            \Cache::forget('modules_flash');
        }

        // Get available modules and cache them
        if (\Cache::has('modules_directory')) {
            $modules_directory = \Cache::get('modules_directory');
        }

        if (!$modules_directory) {
            $modules_directory = WpApi::getModules();
            if ($modules_directory && is_array($modules_directory) && count($modules_directory)) {
                \Cache::put('modules_directory', $modules_directory, now()->addMinutes(15));
            }
        }

        // Get installed modules
        \Module::clearCache();
        $modules = \Module::all();
        foreach ($modules as $module) {
            $module_data = [
                'alias'                        => $module->getAlias(),
                'name'                         => $module->getName(),
                'description'                  => $module->getDescription(),
                'version'                      => $module->get('version'),
                'detailsUrl'                   => $module->get('detailsUrl'),
                'author'                       => $module->get('author'),
                'authorUrl'                    => $module->get('authorUrl'),
                'requiredAppVersion'           => $module->get('requiredAppVersion'),
                'requiredPhpExtensions'        => $module->get('requiredPhpExtensions'),
                'requiredPhpExtensionsMissing' => \App\Module::getMissingExtensions($module->get('requiredPhpExtensions')),
                'requiredModulesMissing'       => \App\Module::getMissingModules($module->get('requiredModules'), $modules),
                'img'                          => $module->get('img'),
                'active'                       => $module->active(), //\App\Module::isActive($module->getAlias()),
                'installed'                    => true,
                'activated'                    => \App\Module::isLicenseActivated($module->getAlias(), $module->get('authorUrl')),
                'license'                      => \App\Module::getLicense($module->getAlias()),
                // Update configuration for third party modules
                'latestVersionNumberUrl'       => $module->get('latestVersionUrl'),
                'latestVersionZipUrl'          => $module->get('latestVersionZipUrl'),
                // Determined later
                'new_version'        => '',
            ];
            $module_data = \App\Module::formatModuleData($module_data);
            $installed_modules[] = $module_data;
        }

        // No need, as we update modules list on each page load
        // Clear modules cache if any module has been added or removed
        // if (count($modules) != count(Module::getCached())) {
        //     $this->clearCache();
        // }

        // Prepare directory modules
        if (is_array($modules_directory)) {
            foreach ($modules_directory as $i_dir => $dir_module) {

                $modules_directory[$i_dir] = \App\Module::formatModuleData($dir_module);

                // Remove modules without aliases
                if (empty($dir_module['alias'])) {
                    unset($modules_directory[$i_dir]);
                }
                $all_modules[$dir_module['alias']] = $dir_module['name'];
                foreach ($installed_modules as $i_installed => $module) {
                    if ($dir_module['alias'] == $module['alias']) {
                        // Set image from director
                        $installed_modules[$i_installed]['img'] = $dir_module['img'];
                        // Remove installed modules from modules directory.
                        unset($modules_directory[$i_dir]);

                        // Detect if new version is available
                        if (!empty($dir_module['version']) && version_compare($dir_module['version'], $module['version'], '>')) {
                            $installed_modules[$i_installed]['new_version'] = $dir_module['version'];
                            $updates_available = true;
                        }

                        continue 2;
                    }
                }

                if (empty($dir_module['authorUrl']) || !\App\Module::isOfficial($dir_module['authorUrl'])) {
                    unset($modules_directory[$i_dir]);
                    continue;
                }

                if (!empty($dir_module['requiredPhpExtensions'])) {
                    $modules_directory[$i_dir]['requiredPhpExtensionsMissing'] = \App\Module::getMissingExtensions($dir_module['requiredPhpExtensions']);
                }
                $modules_directory[$i_dir]['active'] = \App\Module::isActive($dir_module['alias']);
                $modules_directory[$i_dir]['activated'] = false;

                // Do not show third-party modules in Modules Directory.
                if (\App\Module::isThirdParty($dir_module)) {
                    $third_party_modules[] = $modules_directory[$i_dir];
                    unset($modules_directory[$i_dir]);
                }
            }
        } else {
            $modules_directory = [];
        }

        // Loop through each installed module
        foreach ($installed_modules as $i_installed => $module) {
            // Check if the module is an official one
            if (\App\Module::isOfficial($module['authorUrl'])) {
                continue;
            }

            // Get the URL for the latest version of the module
            $latest_version_number_url = $module['latestVersionNumberUrl'] ?? null;
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
                    $current_version = $module['version'];
                }
            } catch (\Exception $e) {
                // If there's an exception, skip to the next iteration
                continue;
            }

            // If the latest version is greater than the current version
            if (version_compare($latest_version, $current_version, '>')) {
                // Update the installed module's version
                $installed_modules[ $i_installed ]['new_version'] = $latest_version;
                // Set the flag to indicate that updates are available
                $updates_available = true;
            }
        }

        // Check modules symlinks. Somestimes instead of symlinks folders with files appear.
        $invalid_symlinks = \App\Module::checkSymlinks(
            collect($installed_modules)->where('active', true)->pluck('alias')->toArray()
        );

        return view('modules/modules', [
            'installed_modules' => $installed_modules,
            'modules_directory' => $modules_directory,
            'third_party_modules' => $third_party_modules,
            'flashes'           => $flashes,
            'updates_available' => $updates_available,
            'all_modules'       => $all_modules,
            'invalid_symlinks'  => $invalid_symlinks,
        ]);
    }

    /**
     * Ajax.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        switch ($request->action) {

            case 'install':
            case 'activate_license':
                $license = $request->license;
                $alias = $request->alias;

                if (!$license) {
                    $response['msg'] = __('Empty license key');
                }

                if (!$response['msg']) {
                    $params = [
                        'license'      => $license,
                        'module_alias' => $alias,
                        'url'          => \App\Module::getAppUrl(),
                    ];
                    $result = WpApi::activateLicense($params);

                    if (WpApi::$lastError) {
                        $response['msg'] = WpApi::$lastError['message'];
                    } elseif (!empty($result['code']) && !empty($result['message'])) {
                        $response['msg'] = $result['message'];
                    } else {
                        if (!empty($result['status']) && $result['status'] == 'valid') {
                            if ($request->action == 'install') {
                                // Download and install module
                                $license_details = WpApi::getVersion($params);

                                if (WpApi::$lastError) {
                                    $response['msg'] = WpApi::$lastError['message'];
                                } elseif (!empty($license_details['code']) && !empty($license_details['message'])) {
                                    $response['msg'] = $license_details['message'];
                                } elseif (!empty($license_details['download_link'])) {
                                    // Download module
                                    $module_archive = \Module::getPath().DIRECTORY_SEPARATOR.$alias.'.zip';

                                    try {
                                        \Helper::downloadRemoteFile($license_details['download_link'], $module_archive);
                                    } catch (\Exception $e) {
                                        $response['msg'] = $e->getMessage();
                                    }

                                    $download_error = false;
                                    if (!file_exists($module_archive)) {
                                        $download_error = true;
                                    } else {
                                        // Extract
                                        try {
                                            \Helper::unzip($module_archive, \Module::getPath());
                                        } catch (\Exception $e) {
                                            $response['msg'] = $e->getMessage();
                                        }
                                        // Check if extracted module exists
                                        \Module::clearCache();
                                        $module = \Module::findByAlias($alias);
                                        if (!$module) {
                                            $download_error = true;
                                        }
                                    }

                                    // Remove archive
                                    if (file_exists($module_archive)) {
                                        \File::delete($module_archive);
                                    }

                                    if (!$response['msg'] && !$download_error) {
                                        // Activate license
                                        \App\Module::activateLicense($alias, $license);

                                        \Session::flash('flash_success_floating', __('Module successfully installed!'));
                                        $response['status'] = 'success';
                                    } elseif ($download_error) {
                                        $response['reload'] = true;

                                        if ($response['msg']) {
                                            \Session::flash('flash_error_floating', $response['msg']);
                                        }

                                        \Session::flash('flash_error_unescaped', __('Error occurred downloading the module. Please :%a_being%download:%a_end% module manually and extract into :folder', ['%a_being%' => '<a href="'.$license_details['download_link'].'" target="_blank">', '%a_end%' => '</a>', 'folder' => '<strong>'.\Module::getPath().'</strong>']));
                                    }
                                } else {
                                    $response['msg'] = __('Error occurred. Please try again later.');
                                }
                            } else {
                                // Just activate license
                                \App\Module::activateLicense($alias, $license);

                                \Session::flash('flash_success_floating', __('License successfully activated!'));
                                $response['status'] = 'success';
                            }
                        } elseif (!empty($result['error'])) {
                            $response['msg'] = \App\Module::getErrorMessage($result['error'], $result);
                        } else {
                            $response['msg'] = __('Error occurred. Please try again later.');
                        }
                    }
                }
                break;

            case 'activate':
                $alias = $request->alias;
                $module = \Module::findByAlias($alias);

                if (!$module) {
                    $response['msg'] = __('Module not found').': '.$alias;
                }

                // Check license
                if (!$response['msg']) {
                    if (!empty($module->get('authorUrl')) && $module->isOfficial()) {
                        $params = [
                            'license'      => $module->getLicense(),
                            'module_alias' => $alias,
                            'url'          => \App\Module::getAppUrl(),
                        ];
                        $license_result = WpApi::checkLicense($params);

                        if (!empty($license_result['code']) && !empty($license_result['message'])) {
                            // Remove remembered license key and deactivate license in DB
                            \App\Module::deactivateLicense($alias, '');

                            $response['msg'] = $license_result['message'];
                        } elseif (!empty($license_result['status']) && $license_result['status'] != 'valid' && $license_result['status'] != 'inactive') {
                            // Remove remembered license key and deactivate license in DB
                            \App\Module::deactivateLicense($alias, '');

                            switch ($license_result['status']) {
                                case 'expired':
                                    $response['msg'] = __('License key has expired');
                                    break;
                                case 'disabled':
                                    $response['msg'] = __('License key has been revoked');
                                    break;
                                case 'inactive':
                                    $response['msg'] = __('License key has not been activated yet');
                                case 'site_inactive':
                                    $response['msg'] = __('No activations left for this license key').' ('.__("Use 'Deactivate License' link above to transfer license key from another domain").')';
                                    break;
                            }
                        } elseif (!empty($license_result['status']) && $license_result['status'] == 'inactive') {
                            // Activate the license.
                            $result = WpApi::activateLicense($params);
                            if (WpApi::$lastError) {
                                $response['msg'] = WpApi::$lastError['message'];
                            } elseif (!empty($result['code']) && !empty($result['message'])) {
                                $response['msg'] = $result['message'];
                            } else {
                                if (!empty($result['status']) && $result['status'] == 'valid') {
                                    // Success.
                                } elseif (!empty($result['error'])) {
                                    $response['msg'] = \App\Module::getErrorMessage($result['error'], $result);
                                } else {
                                    // Some unknown error. Do nothing.
                                }
                            }
                        }
                    }
                }

                if (!$response['msg']) {
                    \App\Module::setActive($alias, true);

                    $outputLog = new BufferedOutput();
                    \Artisan::call('freescout:module-install', ['module_alias' => $alias], $outputLog);
                    $output = $outputLog->fetch();

                    // Get module name
                    $name = '?';
                    if ($module) {
                        $name = $module->getName();
                    }

                    $type = 'danger';
                    $msg = __('Error occurred activating ":name" module', ['name' => $name]);
                    if (session('flashes_floating') && is_array(session('flashes_floating'))) {
                        // If there was any error, module has been deactivated via modules.register_error filter
                        $msg = '';
                        foreach (session('flashes_floating') as $flash) {
                            $msg .= $flash['text'].' ';
                        }
                    } elseif (strstr($output, 'Configuration cached successfully')) {
                        $type = 'success';
                        $msg = __('":name" module successfully activated!', ['name' => $name]);
                    } else {
                        // Deactivate the module.
                        \App\Module::setActive($alias, false);
                        \Artisan::call('freescout:clear-cache');
                    }

                    // Check public folder.
                    if ($module && file_exists($module->getPath().DIRECTORY_SEPARATOR.'Public')) {
                        $symlink_path = public_path().\Module::getPublicPath($alias);
                        if (!file_exists($symlink_path)) {
                            $type = 'danger';
                            $msg = 'Error occurred creating a module symlink ('.$symlink_path.'). Please check folder permissions.';
                            \App\Module::setActive($alias, false);
                            \Artisan::call('freescout:clear-cache');
                        }
                    }

                    if ($type == 'success') {
                        // Migrate again, in case migration did not work in the moment the module was activated.
                        \Artisan::call('migrate', ['--force' => true]);
                    }

                    // \Session::flash does not work after BufferedOutput
                    $flash = [
                        'text'      => '<strong>'.$msg.'</strong><pre class="margin-top">'.$output.'</pre>',
                        'unescaped' => true,
                        'type'      => $type,
                    ];
                    \Cache::forever('modules_flash', $flash);
                    $response['status'] = 'success';
                }

                break;

            case 'deactivate':
                $alias = $request->alias;
                \App\Module::setActive($alias, false);

                $outputLog = new BufferedOutput();
                \Artisan::call('freescout:clear-cache', [], $outputLog);
                $output = $outputLog->fetch();

                // Get module name
                $module = \Module::findByAlias($alias);
                $name = '?';
                if ($module) {
                    $name = $module->getName();
                }

                $type = 'danger';
                $msg = __('Error occurred deactivating :name module', ['name' => $name]);
                if (strstr($output, 'Configuration cached successfully')) {
                    $type = 'success';
                    $msg = __('":name" module successfully Deactivated!', ['name' => $name]);
                }

                // \Session::flash does not work after BufferedOutput
                $flash = [
                    'text'      => '<strong>'.$msg.'</strong><pre class="margin-top">'.$output.'</pre>',
                    'unescaped' => true,
                    'type'      => $type,
                ];
                \Cache::forever('modules_flash', $flash);
                $response['status'] = 'success';
                break;

            case 'deactivate_license':
                $license = $request->license;
                $alias = $request->alias;

                if (!$license) {
                    $response['msg'] = __('Empty license key');
                }

                if (!$response['msg']) {
                    $params = [
                        'license'      => $license,
                        'module_alias' => $alias,
                        'url'          => (!empty($request->any_url) ? '*' : \App\Module::getAppUrl()),
                    ];
                    $result = WpApi::deactivateLicense($params);

                    if (WpApi::$lastError) {
                        $response['msg'] = WpApi::$lastError['message'];
                    } elseif (!empty($result['code']) && !empty($result['message'])) {
                        $response['msg'] = $result['message'];
                    } else {
                        if (!empty($result['status']) && $result['status'] == 'success') {
                            $db_module = \App\Module::getByAlias($alias);
                            if ($db_module && trim($db_module->license ?? '') == trim($license ?? '')) {
                                // Remove remembered license key and deactivate license in DB
                                \App\Module::deactivateLicense($alias, '');

                                // Deactivate module
                                \App\Module::setActive($alias, false);
                                \Artisan::call('freescout:clear-cache', []);
                            }

                            // Flash does not work here.
                            $flash = [
                                'text'      => '<strong>'.__('License successfully Deactivated!').'</strong>',
                                'unescaped' => true,
                                'type'      => 'success',
                            ];
                            \Cache::forever('modules_flash', $flash);

                            $response['status'] = 'success';
                        } elseif (!empty($result['error'])) {
                            $response['msg'] = \App\Module::getErrorMessage($result['error'], $result);
                        } else {
                            $response['msg'] = __('Error occurred. Please try again later.');
                        }
                    }
                }
                break;

            case 'delete':
                $alias = $request->alias;

                $module = \Module::findByAlias($alias);

                if ($module) {

                    //\App\Module::deactivateLicense($alias, $license);

                    $module->delete();
                    \Session::flash('flash_success_floating', __('Module deleted'));
                } else {
                    $response['msg'] = __('Module not found').': '.$alias;
                }

                $response['status'] = 'success';
                break;

            case 'update':
                $update_result = \App\Module::updateModule($request->alias);

                if ($update_result['download_error']) {
                    $response['reload'] = true;

                    if ($update_result['msg']) {
                        \Session::flash('flash_error_floating', $update_result['msg']);
                    }

                    if ($update_result['download_msg']) {
                        \Session::flash('flash_error_unescaped', $update_result['download_msg']);
                    }
                }

                // Install updated module.
                if ($update_result['output'] || $update_result['status']) {

                    $type = 'danger';
                    $msg = $update_result['msg'];

                    if ($update_result['status'] == 'success') {
                        $type = 'success';
                        $msg = $update_result['msg_success'];
                    }

                    // \Session::flash does not work after BufferedOutput
                    $flash = [
                        'text'      => '<strong>'.$msg.'</strong><pre class="margin-top">'.$update_result['output'].'</pre>',
                        'unescaped' => true,
                        'type'      => $type,
                    ];
                    \Cache::forever('modules_flash', $flash);
                    $response['status'] = 'success';
                }

                break;

            case 'update_all':
                $update_all_flashes = [];

                foreach ($request->aliases as $alias) {
                    $update_result = \App\Module::updateModule($alias);

                    $type = 'danger';
                    $msg = $update_result['msg'];

                    if ($update_result['status'] == 'success') {
                        $type = 'success';
                        $msg = $update_result['msg_success'];
                    } elseif ($update_result['download_msg']) {
                        $msg .= '<br/>'.$update_result['download_msg'];
                    }

                    $text = '<strong>'.$update_result['module_name'].':</strong> '.$msg;
                    if (trim($update_result['output'])) {
                        $text .= '<pre class="margin-top">'.$update_result['output'].'</pre>';
                    }

                    // \Session::flash does not work after BufferedOutput
                    $update_all_flashes[] = [
                        'text'      => $text,
                        'unescaped' => true,
                        'type'      => $type,
                    ];
                }
                if ($update_all_flashes) {
                    \Cache::forever('modules_flash', $update_all_flashes);
                }
                $response['status'] = 'success';

                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occurred';
        }

        return \Response::json($response);
    }
}
