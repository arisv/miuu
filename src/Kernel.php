<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

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
