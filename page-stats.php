<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;;
use Grav\Plugin\PageStats\Geolocation\Geolocation;
use Grav\Plugin\PageStats\Stats;
use RocketTheme\Toolbox\Event\EventSubscriberInterface;

/**
 * Class PageStatsPlugin
 * @package Grav\Plugin
 */
class PageStatsPlugin extends Plugin
{
    const GEO_DB = __DIR__ . '/data/geolocation.sqlite';

    const PATH_ADMIN_STATS = '/page-stats';
    const PATH_ADMIN_PAGE_DETAIL = '/page-details';
    const PATH_ADMIN_USER_DETAIL = '/user-details';
    const PATH_ADMIN_ALL_PAGES = '/all-pages';

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                // Uncomment following line when plugin requires Grav < 1.7
                // ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ],
            'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0]
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {

        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminMenu' => ['onAdminMenu', 1000],
                'onAdminPage' => ['onAdminPage', 0],
                'onTwigSiteVariables' => ['onTwigAdminVariables', 0],

            ]);
            return;
        }

        // Enable the main events we are interested in
        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 990],

        ]);
    }

    public function onAdminTwigTemplatePaths($event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/themes/admin/templates';
        $event['paths'] = $paths;
    }

    function getUserIP()
    {
        // Get real visitor IP behind CloudFlare network
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
            $_SERVER['HTTP_CLIENT_IP'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
        }
        $client  = @$_SERVER['HTTP_CLIENT_IP'];
        $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
        $remote  = $_SERVER['REMOTE_ADDR'];

        if (filter_var($client, FILTER_VALIDATE_IP)) {
            $ip = $client;
        } elseif (filter_var($forward, FILTER_VALIDATE_IP)) {
            $ip = $forward;
        } else {
            $ip = $remote;
        }

        return $ip;
    }


    /**
     * returns the value for front matter property that controls processing of a page
     * or true otherwise.
     * We return true as the default behaviour is to be enabled for all pages
     *
     * eg:
     * page-stats:
     *      process: true
     *
     * @param array $headers
     * @return bool
     */
    private function isEnabledForPage(array $headers): bool
    {
        if (isset($headers['page-stats']['process'])) {
            return $headers['page-stats']['process'];
        }

        return true;
    }

    /**
     * returns false if IP (or regexp) are in the plugin config list
     *
     * @param string $ip
     * @return bool
     */
    private function isEnabledForIp(string $ip): bool
    {
        $config = $config = $this->config();
        if (isset($config['ignored_ips']) && is_array($config['ignored_ips'])) {
            $ips = array_map(function($a) {
                return isset($a['ip']) ? $a['ip']: '' ;
            }, $config['ignored_ips']);

            $regexp = implode('|', $ips);

            return 0 === preg_match("/$regexp/", $ip);
        }


        return true;
    }

    public function onPageInitialized()
    {
        try {
            $page = $this->grav['page'];
            if (false === $this->isEnabledForPage((array)$page->header())) {
                return;
            }

            $ip = $this->getUserIP();
            if (false === $this->isEnabledForIp($ip)) {
                return;
            }
            $geo = (new Geolocation(self::GEO_DB))->locate($ip);

            $config = $this->config();
            $browser = $this->grav['browser'];
            $dbPath = $config['db'];


            (new Stats($dbPath, $this->config()))->collect($ip, $geo, $this->grav['page'], $this->grav['uri']->uri(false), $this->grav['user'], new DateTimeImmutable(), $browser);
        } catch (\Throwable $e) {
            error_log($e->getmessage());
            $this->grav['log']->addError('PageStats plugin : ' . $e->getMessage() . ' - File: ' . $e->getFile() . ' - Line: ' . $e->getLine() . ' - Trace: ' . $e->getTraceAsString());
            // $this->grav['log']->addDebug('GEO DB : ' . self::GEO_DB);
            // $this->grav['log']->addDebug('STATS DB : ' . $dbPath);

            if (false === $config['ignore_errors']) {
                throw $e;
            }
        }
    }


    public function onAdminMenu()
    {
        $twig = $this->grav['twig'];

        // Dashboard
        $twig->plugins_hooked_nav['PLUGIN_PAGE_STATS.PAGE_STATS'] = [
            'route' => 'page-stats',
            'icon' => 'fa-line-chart',
            'authorize' => ['admin.login', 'admin.super'],
            'priority' => 10
        ];
    }

    public function onTwigAdminVariables(): void
    {
        $uri = $this->grav['uri'];
        $config = $this->config();
        $dbPath = $config['db'];

        $adminRoute =  rtrim($this->config->get('plugins.admin.route'), '/') . self::PATH_ADMIN_STATS;
        $pageStatsRoute = $adminRoute;
        $pageDetailsRoute = $adminRoute . self::PATH_ADMIN_PAGE_DETAIL;
        $userDetailsRoute = $adminRoute . self::PATH_ADMIN_USER_DETAIL;
        $allPagesRoute = $adminRoute . self::PATH_ADMIN_ALL_PAGES;

        switch($uri->path()) {
            case $userDetailsRoute:
            case $pageStatsRoute:
            case $pageDetailsRoute:
            case $allPagesRoute:
                $this->grav['twig']->twig_vars['pageStats'] = [
                    'db' =>  new Stats($dbPath, $this->config()),
                    'urls' => [
                        'base' => $pageStatsRoute,
                        'pageDetails' =>  $pageDetailsRoute,
                        'userDetails' => $userDetailsRoute,
                        'allPages' => $allPagesRoute,
                    ],
                ];
                break;
            }

    }

    public function onAdminPage(Event $event)
    {
        $uri = $this->grav['uri'];
        $pages = $this->grav['pages'];
        $page = new Page;


        $adminRoute =  rtrim($this->config->get('plugins.admin.route'), '/') . self::PATH_ADMIN_STATS;
        $pageStatsRoute = $adminRoute ;
        $pageDetailsRoute = $adminRoute . self::PATH_ADMIN_PAGE_DETAIL;
        $userDetailsRoute = $adminRoute . self::PATH_ADMIN_USER_DETAIL;
        $allPagesRoute = $adminRoute . self::PATH_ADMIN_ALL_PAGES;


        switch($uri->path()) {
            case $pageStatsRoute:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/stats.md'));
                break;

            case $pageDetailsRoute:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/page-details.md'));
                break;

            case $userDetailsRoute:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/user-details.md'));
                break;

            case $allPagesRoute:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/all-pages.md'));
                break;

        }
    }
}
