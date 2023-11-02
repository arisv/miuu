<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\RouteCollectionBuilder;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private const CONFIG_EXTS = '.{php,xml,yaml,yml}';

    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir() . '/config/bundles.php';
        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheDir(): string
    {
        if ($_ENV['SF_VAGRANT_MODE']=== "1") {
            return sprintf("%s/app/cache/%s", sys_get_temp_dir(), $this->getEnvironment());
        }

        return $this->getProjectDir() . '/var/cache/' . $this->environment;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogDir(): string
    {
        if ($_ENV['SF_VAGRANT_MODE']=== "1") {
            return sprintf("%s/app/logs/%s", sys_get_temp_dir(), $this->getEnvironment());
        }

        return $this->getProjectDir() . '/var/log';
    }
}
