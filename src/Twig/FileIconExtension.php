<?php

namespace App\Twig;

use App\Service\FileIconResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FileIconExtension extends AbstractExtension
{
    public function __construct(private FileIconResolver $resolver)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('file_icon', [$this->resolver, 'resolve']),
        ];
    }
}
