<?php

/*
 * Plugin Name:       WordPress Plugin Dependencies
 * Plugin URI:        https://github.com/midweste/wp-plugin-dependencies
 * Description:       Prevent WordPress activation or deactivation of plugins based on plugin meta Required:
 * Author:            Midweste
 * Author URI:        https://github.com/midweste/wp-plugin-dependencies
 * License:           GPL-2.0+
 * Update URI:        https://raw.githubusercontent.com/midweste/wp-plugin-dependencies/main/wp-plugin-dependencies.php
 */

/**
 * TODO
 * Address how requirements are handled when a module update adds a new requirement
 * Address dropins, muplugins, and standard plugins with the same name
 */

/**
 * WordPress plugins with additional requirements handling
 */
class WordpressPlugins
{
    public static function run(): void
    {
        // only allow on plugins pages
        // TODO Allow for wp subdirectory installation w isPluginPage check
        if ($_SERVER['SCRIPT_NAME'] !== '/wp-admin/plugins.php' && $_SERVER['SCRIPT_NAME'] !== '/wp-admin/network/plugins.php') {
            return;
        }

        $plugins = new self();
        add_action('admin_enqueue_scripts', function () use ($plugins) {
            $plugins->hookAdminEnqueueScripts();
        }, PHP_INT_MAX);

        add_action('admin_init', function () use ($plugins) {
            $plugins->hookAdminInit();
        }, PHP_INT_MAX);
    }

    protected function getBasePathByType(string $type): string
    {
        if ($type === 'mustuse') {
            return \WPMU_PLUGIN_DIR;
        } elseif ($type === 'dropins') {
            return \WP_CONTENT_DIR;
        }
        return \WP_PLUGIN_DIR;
    }

    protected function getPluginsAll(): array
    {
        $dropinPlugins = get_dropins();
        foreach ($dropinPlugins as &$dropin) {
            $dropin['Type'] = 'dropins';
        }
        $muPlugins = get_mu_plugins();
        foreach ($muPlugins as &$muplugin) {
            $muplugin['Type'] = 'mustuse';
        }
        $standardPlugins = get_plugins();
        foreach ($standardPlugins as &$plugin) {
            $plugin['Type'] = 'all';
        }
        return array_merge_recursive($dropinPlugins, $muPlugins, $standardPlugins);
    }

    /**
     * Return an array of all plugins with additional information
     *
     * @return WordpressPlugin[]
     */
    public function getPluginsAllExtended(): array
    {
        static $plugins  = [];
        if (!empty($plugins)) {
            return $plugins;
        }

        $requires = [];

        foreach ($this->getPluginsAll() as $file => $plugin) {
            $pluginPath = $this->getBasePathByType($plugin['Type']) . '/' . $file;
            $plugin = new WordpressPlugin($pluginPath);

            if ($plugin->hasRequires()) {
                //TODO plug in check to address plugin depending on itself or circular
                foreach ($plugin->getRequires() as $requirement) {
                    $requires[$requirement][] = $plugin->getSlug();
                }
            }
            $plugins[$plugin->getSlug()] = $plugin;
        }

        // set required by now that we have all requirements
        foreach ($requires as $required => $requirements) {
            // plugin isnt installed locally
            if (!isset($plugins[$required])) {
                continue;
            }
            sort($requirements);
            $plugins[$required]->setRequiredBy($requirements);
        }
        return $plugins;
    }

    /**
     * Return a plugin from the plugins array based on its folder slug
     *
     * @param string $slug
     * @return WordpressPlugin
     */
    public function getPluginBySlug(string $slug): ?WordpressPlugin
    {
        foreach ($this->getPluginsAllExtended() as $plugin) {
            /** @var WordpressPlugin $plugin */
            if (trim(strtolower($plugin->getSlug())) === trim(strtolower($slug))) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * Return an array of plugin slugs where requirements have been met for this plugin
     *
     * @param WordpressPlugin $plugin
     * @return array
     */
    protected function pluginRequiresMet(WordpressPlugin $plugin): array
    {
        $requiresMet   = [];
        foreach ($plugin->getRequires() as $requireSlug) {
            $required = $this->getPluginBySlug($requireSlug);
            if (empty($required) || !$required->isActive()) {
                // $requiresUnmet[] = $requireSlug;
            } else {
                $requiresMet[] = $requireSlug;
            }
        }
        return $requiresMet;
    }

    /**
     * Return an array of plugin slugs where requirements have not been met for this plugin
     *
     * @param WordpressPlugin $plugin
     * @return array
     */
    protected function pluginRequiresUnmet(WordpressPlugin $plugin): array
    {
        $requiresUnmet = [];
        foreach ($plugin->getRequires() as $requireSlug) {
            $required = $this->getPluginBySlug($requireSlug);
            if (empty($required) || !$required->isActive()) {
                $requiresUnmet[] = $requireSlug;
            }
        }
        return $requiresUnmet;
    }

    protected function pluginRequiredByMet(WordpressPlugin $plugin): array
    {
        // $requiredByUnmet = [];
        $requiredByMet = [];
        foreach ($plugin->getRequiredBy() as $requiredSlug) {
            $requiredBy = $this->getPluginBySlug($requiredSlug);
            if (empty($requiredBy) || $requiredBy->isActive()) {
                // $requiredByUnmet[] = $requiredSlug;
            } else {
                $requiredByMet[] = $requiredSlug;
            }
        }
        return $requiredByMet;
    }

    protected function pluginRequiredByUnmet(WordpressPlugin $plugin): array
    {
        $requiredByUnmet = [];
        foreach ($plugin->getRequiredBy() as $requiredSlug) {
            $requiredBy = $this->getPluginBySlug($requiredSlug);
            if (empty($requiredBy) || $requiredBy->isActive()) {
                $requiredByUnmet[] = $requiredSlug;
            }
        }
        return $requiredByUnmet;
    }

    protected function hookAdminEnqueueScripts()
    {
        wp_register_style('wp-plugins-dependencies', false); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        wp_enqueue_style('wp-plugins-dependencies');
        wp_add_inline_style('wp-plugins-dependencies', '
                .requires {
                    color: black;
                }
                .requirements {
                    margin-top: 8px;
                    background-color: white;
                    padding: 10px;
                    border-left: 4px solid rgb(0, 160, 210);

                    -webkit-box-shadow: 1px 1px 1px 1px rgba(0,0,0,0.1);
                    -moz-box-shadow: 1px 1px 1px 1px rgba(0,0,0,0.1);
                    box-shadow: 1px 1px 1px 1px rgba(0,0,0,0.1);

                }
                a.requirement-title {
                    text-decoration: underline;
                }
                .requirement-title.met {
                    color: green;
                }
                .requirement-title.unmet {
                    color: #a00;
                }
            ');
    }

    protected function hookPluginActionMeta(WordpressPlugin $plugin, $actions, $plugin_file, $plugin_data, $context)
    {

        // bail if not this plugin
        $pluginBasepath = $this->getBasePathByType($context);
        if ($pluginBasepath . '/' . $plugin_file !== $plugin->getPluginPath()) {
            return $actions;
        }

        // requires plugins
        $requiresHtml = '';
        if ($plugin->hasRequires()) {
            $requiresUnmet = $this->pluginRequiresUnmet($plugin);
            $requiresMet   = $this->pluginRequiresMet($plugin);
            $requiresHtml .= '
                <div class="requires">
                    <span class="requires-title">Requires:</span> ' . implode(', ', $this->styleRequirements($plugin, $plugin->getRequires(), $requiresMet, $requiresUnmet)) . '
                </div>';
        }

        // required by plugins
        if ($plugin->hasRequiredBy()) {
            $requiredByUnmet = $this->pluginRequiredByUnmet($plugin);
            $requiredByMet   = $this->pluginRequiredByMet($plugin);
            $requiresHtml .= '
                <div class="required-by">
                    <span class="requires-title">Required By:</span> ' . implode(', ', $this->styleRequirements($plugin, $plugin->getRequiredBy(), $requiredByMet, $requiredByUnmet)) . '
                </div>';
        }

        // add requirements html to above links or by itself
        $requirementsWrapper = sprintf('<div class="requirements">%s</div>', $requiresHtml);
        if (!empty($actions)) {
            $key = array_key_last($actions);
            $actions[$key] = $actions[$key] . $requirementsWrapper;
        } else {
            $actions['dependencies'] = $requirementsWrapper;
        }

        return $actions;
    }

    protected function hookPluginActionLinks(WordpressPlugin $plugin, $actions, $plugin_file, $plugin_data, $context)
    {
        // action links for activate and deactive are only available for standard plugins
        if ($context === 'mustuse' || $context === 'dropins') {
            return $actions;
        }

        // bail if not this plugin
        if (\WP_PLUGIN_DIR . '/' . $plugin_file !== $plugin->getPluginPath()) {
            return $actions;
        }

        $network = strpos($_SERVER['SCRIPT_NAME'], '/wp-admin/network/plugins.php') !== false ? 'Network ' : '';
        // requires plugins
        $requiresHtml = '';
        if ($plugin->hasRequires()) {
            $requiresUnmet = $this->pluginRequiresUnmet($plugin);
            if (isset($actions['activate']) && !empty($requiresUnmet)) {
                $actions['activate'] = '<span class="required-disabled">' . $network . 'Activate</span>';
            }
        }

        // required by plugins
        if ($plugin->hasRequiredBy()) {
            $requiredByUnmet = $this->pluginRequiredByUnmet($plugin);
            if (isset($actions['deactivate']) && !empty($requiredByUnmet)) {
                $actions['deactivate'] = '<span class="required-disabled">' . $network . 'Deactivate</span>';
            }
        }

        // add requirements html to above links or by itself
        if (!empty($actions)) {
            $firstKey = array_key_first($actions);
            $actions[$firstKey] = $requiresHtml . $actions[$firstKey];
        } else {
            $actions['dependencies'] = $requiresHtml;
        }

        return $actions;
    }

    protected function hookAdminInit()
    {
        $plugins = $this->getPluginsAllExtended();
        foreach ($plugins as $plugin) {
            /** @var WordpressPlugin $plugin */
            if (!$plugin->hasRequirements()) {
                continue;
            }

            /**
             * Hook plugin_action_links to restrict turning on and off required plugins
             * Hook plugin_row_meta to add Required and Required By information
             */
            add_filter('plugin_row_meta', function ($actions, $plugin_file, $plugin_data, $context) use ($plugin) {
                return $this->hookPluginActionMeta($plugin, $actions, $plugin_file, $plugin_data, $context);
            }, PHP_INT_MAX, 4);

            add_filter('plugin_action_links', function ($actions, $plugin_file, $plugin_data, $context) use ($plugin) {
                return $this->hookPluginActionLinks($plugin, $actions, $plugin_file, $plugin_data, $context);
            }, PHP_INT_MAX, 4);

            add_filter('network_admin_plugin_action_links', function ($actions, $plugin_file, $plugin_data, $context) use ($plugin) {
                return $this->hookPluginActionLinks($plugin, $actions, $plugin_file, $plugin_data, $context);
            }, PHP_INT_MAX, 4);
        }
    }

    /** Helper Methods */

    protected function styleRequirements(WordpressPlugin $plugin, array $set, array $met, array $unMet): array
    {
        foreach ($set as &$item) {
            // color
            $class = '';
            if (in_array($item, $met, true)) {
                $class = 'met';
            } elseif (in_array($item, $unMet, true)) {
                $class = 'unmet';
            }

            // linkify
            $wpp = $this->getPluginBySlug($item);
            if (empty($wpp)) {
                $url = sprintf('/wp-admin/plugin-install.php?s=%s&tab=search&type=term', $item);
                //$url = sprintf('https://wordpress.org/plugins/search/%s/', $item);
                $item = sprintf('<a href="%s" class="requirement-title %s">%s</a>', $url, $class, $item);
            } else {
                $item = sprintf('<span class="requirement-title %s">%s</span>', $class, $wpp->getName());
            }
        }
        return $set;
    }
}

/**
 * WordPress Plugin w Dependencies
 *
 * @method public string getName()
 * @method public string getPluginURI()
 * @method public string getVersion()
 * @method public string getDescription()
 * @method public string getAuthor()
 * @method public string getAuthorURI()
 * @method public string getTextDomain()
 * @method public string getDomainPath()
 * @method public string getNetwork()
 * @method public string getRequiresWP()
 * @method public string getRequiresPHP()
 * @method public string getSitewide()
 *
 * @method public array getRequires()
 * @method public array getRequiredBy()
 * @method public string setRequires(array $requires)
 * @method public string setRequiredBy(array $requires)
 */
class WordpressPlugin
{
    protected $pluginPath;
    protected $data;
    protected $defaultHeaders = [
        'Name'        => 'Plugin Name',
        'PluginURI'   => 'Plugin URI',
        'Version'     => 'Version',
        'Description' => 'Description',
        'Author'      => 'Author',
        'AuthorURI'   => 'Author URI',
        'TextDomain'  => 'Text Domain',
        'DomainPath'  => 'Domain Path',
        'Network'     => 'Network',
        'RequiresWP'  => 'Requires at least',
        'RequiresPHP' => 'Requires PHP',
        // Site Wide Only is deprecated in favor of Network.
        '_sitewide'   => 'Site Wide Only',
    ];

    public function __construct(string $pluginPath)
    {
        $this->setPluginPath($pluginPath);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getPluginPath(): string
    {
        return $this->pluginPath;
    }

    public function setPluginPath(string $pluginPath): self
    {
        if (!file_exists($pluginPath)) {
            throw new \Exception(sprintf('Plugin file %s does not exist.', $pluginPath));
        }

        // hydrate file data
        $data = $this->getFileData($pluginPath, ['Requires' => 'Requires']);
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }

        // requires
        if (!empty($data['Requires'])) {
            $requiredPlugins = array_map('trim', (explode(',', $data['Requires'])));
            sort($requiredPlugins);
            $this->set('Requires', $requiredPlugins);
        } else {
            $this->set('Requires', []);
        }

        // required by
        $this->set('Required By', []);

        // plugin type
        $pathinfo = pathinfo($pluginPath);
        if ($pathinfo['dirname'] === \WPMU_PLUGIN_DIR) {
            $this->set('Type', 'mustuse');
        } elseif ($pathinfo['dirname'] === \WP_CONTENT_DIR) {
            $this->set('Type', 'dropins');
        } else {
            $this->set('Type', 'all');
        }

        $this->pluginPath = $pluginPath;
        return $this;
    }

    public function getBasePath(): string
    {
        if ($this->isMuPlugin()) {
            return \WPMU_PLUGIN_DIR;
        } elseif ($this->isDropin()) {
            return \WP_CONTENT_DIR;
        }
        return \WP_PLUGIN_DIR;
    }

    public function getSlug(): string
    {
        $pathInfo = pathinfo($this->getPluginPath());
        return ($this->isMuPlugin() || $this->isDropin()) ? $pathInfo['filename'] : basename($pathInfo['dirname']);
    }

    public function getRequires(): array
    {
        return $this->get('requires');
    }

    public function getRequiredBy(): array
    {
        return $this->get('required by');
    }

    public function setRequiredBy(array $requiredBy): self
    {
        return $this->set('required by', $requiredBy);
    }

    public function has(string $key): bool
    {
        return isset($this->getData()[$this->normalize($key)]);
    }

    public function get(string $key)
    {
        return (!$this->has($key)) ? null : $this->getData()[$this->normalize($key)];
    }

    public function set(string $key, $value): self
    {
        $this->data[$this->normalize($key)] = $value;
        return $this;
    }

    public function hasRequires(): bool
    {
        return !empty($this->getRequires());
    }

    public function hasRequiredBy(): bool
    {
        return !empty($this->getRequiredBy());
    }

    public function hasRequirements(): bool
    {
        return !empty($this->getRequires()) || !empty($this->getRequiredBy());
    }

    public function isMuPlugin(): bool
    {
        return $this->get('Type') === 'mustuse';
    }

    public function isDropin(): bool
    {
        return $this->get('Type') === 'dropins';
    }

    public function isStandard(): bool
    {
        return $this->get('Type') === 'all';
    }

    public function isActive(): bool
    {
        if ($this->isMuPlugin() || $this->isDropin()) {
            return true;
        }
        $fileInfo   = pathinfo($this->getPluginPath());
        $pluginFile = basename($fileInfo['dirname']) . '/' . $fileInfo['basename'];
        return is_plugin_active($pluginFile);
    }

    public function getBasename(): string
    {
        return plugin_basename($this->getPluginPath());
    }

    /**
     * Return an array of plugin meta information from the plugin comment declaration
     *
     * @param string $file
     * @param array $extraHeaders
     * @param string $context
     * @return array
     */
    public function getFileData(string $file, array $extraHeaders = [], string $context = 'plugin'): array
    {
        $headers = array_replace($this->defaultHeaders, $extraHeaders);
        return get_file_data($file, $headers, $context);
    }

    protected function normalize(string $text): string
    {
        return trim(strtolower($text));
    }

    public function __call(string $method, $args)
    {
        $property = str_replace('get', '', $this->normalize($method));
        if (!$this->has($property)) {
            throw new \Exception(sprintf('Data %s not found', $method));
        }
        return $this->get($property);
    }
}

WordpressPlugins::run();
