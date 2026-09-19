<?php

namespace App\Twig;

use App\Service\ListOrdering;
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
            // Group header text for the gallery's "group by" setting (null when not grouping).
            new TwigFunction('file_group_label', [ListOrdering::class, 'groupLabel']),
        ];
    }
}
