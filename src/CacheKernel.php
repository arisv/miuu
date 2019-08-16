<?php

namespace App;


use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;

class CacheKernel extends HttpCache
{
    protected function getOptions()
    {
        return [
            'debug' => true,
            'trace_level' => 'full',
            'allow_reload' => true
        ];
    }
}