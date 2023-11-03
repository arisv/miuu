<?php

namespace App\Trait;


trait CanDisableLeakingSqlFeaturesTrait
{
    public function disableSqlLoggers($managers = ["em"])
    {
        foreach ($managers as $manager) {
            if (property_exists($this, $manager)){
                $this->$manager?->getConnection()->getConfiguration()->setSQLLogger(null);
                $this->$manager?->getConnection()->getConfiguration()->setMiddlewares([]);
            }
        }
    }
}