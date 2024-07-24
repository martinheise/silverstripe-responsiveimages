<?php

namespace Mhe\SmartImages\Extensions;

use Mhe\Imagetools\Data\ImageData;
use SilverStripe\Assets\Storage\AssetContainer;

class ImageDataWrapper implements ImageData
{
    protected AssetContainer $srcimage;

    /**
     * @param AssetContainer $srcimage
     */
    public function __construct(AssetContainer $srcimage)
    {
        $this->srcimage = $srcimage;
    }

    /**
     * @return AssetContainer
     */
    public function getSrcimage(): AssetContainer
    {
        return $this->srcimage;
    }

    public function getWidth(): int
    {
        return $this->srcimage->getWidth();
    }

    public function getFilesize(): int
    {
        return $this->srcimage->getAbsoluteSize();
    }

    public function getPublicPath(): string
    {
        return $this->srcimage->getURL();
    }

    public function resize($width): ImageData
    {
        $resized = $this->srcimage->ScaleWidth($width);
        return new ImageDataWrapper($resized);
    }
}
