<?php

namespace Mhe\SmartImages\Model;

class RenderConfig extends \Mhe\Imagetools\Data\RenderConfig
{
    protected ?int $fallbackwidth;

    public function __construct(string $sizes, int $maxsteps = 10, int $sizediff = 50000, int $highres = 1, array $rendersizes = [], $fallbackwidth = null)
    {
        parent::__construct($sizes, $maxsteps, $sizediff, $highres, $rendersizes);
        $this->fallbackwidth = $fallbackwidth;
    }

    public function getFallbackwidth(): ?int
    {
        return $this->fallbackwidth;
    }

    public function setFallbackwidth(?int $fallbackwidth): void
    {
        $this->fallbackwidth = $fallbackwidth;
    }
}
