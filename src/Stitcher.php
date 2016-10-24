<?php

namespace brendt\stitcher;

use brendt\stitcher\exception\TemplateNotFoundException;
use brendt\stitcher\factory\ProviderFactory;
use Smarty;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class Stitcher {

    /**
     * @var SplFileInfo[]
     */
    protected $templates;

    /**
     * @var ProviderFactory
     */
    protected $factory;

    /**
     * @var string
     */
    private $root;

    /**
     * @var string
     */
    private $compileDir;

    /**
     * @var string
     */
    private $publicDir;

    /**
     * @var ProviderFactory
     */
    private $providerFactory;

    /**
     * Stitcher constructor.
     */
    public function __construct() {
        $this->root = Config::get('directories.src');
        $this->publicDir = Config::get('directories.public');
        $this->compileDir = Config::get('directories.cache');

        $this->providerFactory = Config::getDependency('factory.provider');
    }

    /**
     * @return Smarty
     */
    protected function getSmarty() {
        return Config::getDependency('engine.smarty');
    }

    public function save($blanket) {
        $fs = new Filesystem();

        $publicDirExists = $fs->exists($this->publicDir);
        if (!$publicDirExists) {
            $fs->mkdir($this->publicDir);
        }

        foreach ($blanket as $path => $page) {
            if ($path === '/') {
                $path = 'index';
            }

            $fs->dumpFile($this->publicDir . "/{$path}.html", $page);
        }
    }

    /**
     * @return array
     */
    public function loadSite() {
        $finder = new Finder();
        $files = $finder->files()->in("{$this->root}/site")->name('*.yml');
        $site = [];

        foreach ($files as $file) {
            $site += Yaml::parse($file->getContents());
        }

        return $site;
    }

    /**
     * @param string|array $routes
     * @param null         $entryId
     *
     * @return array
     * @throws TemplateNotFoundException
     */
    public function stitch($routes = [], $entryId = null) {
        $blanket = [];
        $smarty = $this->getSmarty();
        $site = $this->loadSite();
        $templates = $this->loadTemplates();

        if (is_string($routes)) {
            $routes = [$routes];
        }

        foreach ($site as $route => $page) {
            $skipRoute = count($routes) && !in_array($route, $routes);
            $templateIsset = isset($templates[$page['template']]);

            if ($skipRoute) {
                continue;
            }

            if (!$templateIsset) {
                if (isset($page['template'])) {
                    throw new TemplateNotFoundException("Template {$page['template']}.tpl not found.");
                } else {
                    throw new TemplateNotFoundException('No template was set.');
                }
            }

            $template = $templates[$page['template']];
            $detailVariable = null;
            $globalVariables = [];

            if (isset($page['data'])) {
                foreach ($page['data'] as $name => $variable) {
                    if (is_array($variable) && isset($variable['src']) && isset($variable['id'])) {
                        $detailVariable = [
                            'name' => $name,
                            'src' => $variable['src'],
                            'id' => $variable['id'],
                        ];
                    } else if (is_string($variable)) {
                        $globalVariables[$name] = $this->getData($variable);
                    }
                }
            }

            foreach ($globalVariables as $name => $variable) {
                $smarty->assign($name, $variable);
            }

            if ($detailVariable) {
                $idField = $detailVariable['id'];
                $entries = $this->getData($detailVariable['src']);
                $entryName = $detailVariable['name'];

                foreach ($entries as $entry) {
                    if (!isset($entry[$idField]) || ($entryId && $entry[$idField] != $entryId)) {
                        continue;
                    }

                    $routeName = str_replace('{' . $idField . '}', $entry[$idField], $route);

                    $smarty->assign($entryName, $entry);
                    $blanket[$routeName] = $smarty->fetch($template->getRealPath());
                    $smarty->clearAssign($entryName);
                }
            } else {
                $blanket[$route] = $smarty->fetch($template->getRealPath());
            }
        }

        return $blanket;
    }

    /**
     * @return SplFileInfo[]
     */
    public function loadTemplates() {
        $finder = new Finder();
        $files = $finder->files()->in("{$this->root}/template")->name('*.tpl');
        $templates = [];

        foreach ($files as $file) {
            $id = str_replace('.tpl', '', $file->getRelativePathname());
            $templates[$id] = $file;
        }

        return $templates;
    }

    private function getData($src) {
        $provider = $this->providerFactory->getProvider($src);

        if (!$provider) {
            return $src;
        }

        return $provider->parse($src);
    }

}


