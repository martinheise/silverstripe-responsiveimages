<?php

namespace Mhe\SmartImages\Extensions;

use Mhe\SmartImages\Model\RenderConfig;
use Mhe\Imagetools\ImageResizer;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\FieldType\DBField;

class ImageExtension extends DataExtension implements Flushable
{
    /**
     * default configuration
     * @config
     */
    private static $rendering_classes = array(
        'default' => array(
            'sizes' => '100vw',
            'maxsteps' => 0,  // maxsteps is limiting the steps calculated by sizediff
            'sizediff' => 20000,
            'retinalevel' => 2
        )
    );

    /**
     * Minimum viewport width to consider for CSS calculations
     * @config
     * @var int
     */
    private static $minviewport = 320;

    /**
     * Maximum viewport width to consider for CSS calculations
     * @config
     * @var int
     */
    private static $maxviewport = 2560;

    /**
     * Value to convert CSS rem to px
     * @config
     * @var int
     */
    private static $remsize = 16;

    private static $supported_extensions = array(
        // Todo: check extensions, e.g. webp
        "bmp" ,"gif" ,"jpg" ,"jpeg" ,"pcx" ,"tif" ,"png"
    );

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * get a copy of this image for a specif rendering, usually connected with a css class
     * cssclass and other arguments are set either as string or as associative array
     * can be used from templates or from code, used especially by the short code parser by using the short code arguments
     *
     * @param $arguments
     * @return AssetContainer
     */
    public function Rendering($arguments)
    {
        /*
            create a copy of this object (preventing to modify the original) and set arguments
            Cmopare the end of ImageManipulation->manipulate(), but here we don’t do any actual processing yet
        */
        $copy = DBField::create_field('DBFile', [
            'Filename' => $this->owner->Filename,
            'Hash' => $this->owner->Hash,
            'Variant' => $this->owner->Variant
        ]);
        $copy->initAttributes($this->owner->attributes);
        $copy->setArguments($arguments);
        return $copy->setOriginal($this->owner);
    }


    /*
     ToDo: use Cache framework for calculations etc.
     */

    /**
     * set the arguments
     * @param $arguments
     * @return Image
     */
    public function setArguments($arguments)
    {
        $this->owner->setField('imageExtensionArguments', $this->parseArgString($arguments));
        $this->invalidate();
        return $this->owner;
    }

    /**
     * @return array
     */
    public function getArguments()
    {
        $arguments = $this->owner->imageExtensionArguments;
        return $arguments ?: [];
    }

    /**
     * get the all variant images, for use in advanced templates
     * @return ArrayList
     */
    public function Variants()
    {
        return ArrayList::create($this->getVariants());
    }

    /**
     * get the srcset attribute for HTML rendering, for use in templates
     * @return string
     */
    public function Srcset()
    {
        if (!static::is_supported_filetype($this->owner)) {
            return null;
        }
        $srcset = [];
        foreach ($this->getVariants() as $img) {
            $srcset[] = $img->getURL() . " " . $img->getWidth() . "w";
        }
        return implode(", ", $srcset);
    }

    /**
     * array for all variant widths – mostly for testing
     * @return array
     */
    public function VariantWidths()
    {
        $widths = [];
        foreach ($this->getVariants() as $img) {
            $widths[] = $img->getWidth();
        }
        return $widths;
    }

    /**
     * get the src attribute for HTML rendering, for use in templates
     * @return string
     */
    public function Src()
    {
        if (count($this->getVariants()) == 0) {
            return null;
        }
        $variant = $this->getVariants()[0];
        if ($variant) {
            return $variant->getURL();
        } else {
            return null;
        }
    }

    /**
     * get the class attribute for HTML rendering, for use in templates
     * could be multiple classes, spearated by whitespace
     * @return string
     */
    public function Cssclass()
    {
        if (array_key_exists('cssclass', $this->getArguments())) {
            return $this->getArguments()['cssclass'];
        }
        if (array_key_exists('class', $this->getArguments())) {
            return $this->getArguments()['class'];
        }
        return null;
    }


    /**
     * get the user defined width for HTML rendering, for use in templates
     * @return string
     */
    public function Userwidth()
    {
        return array_key_exists('userwidth', $this->getArguments()) ? $this->getArguments()['userwidth'] : null;
    }

    /**
     * get an optional configured fallback width, used for unsupported file types (SVG) that can’t create a srcset attribute
     * @return string
     */
    public function Fallbackwidth()
    {
        if (empty($this->Sizes())) {
            return $this->getRenderConfig()->getFallbackwidth();
        }
        return null;
    }

    /**
     * get the sizes attribute for HTML rendering, for use in templates
     * @return string
     */
    public function Sizes()
    {
        if (!static::is_supported_filetype($this->owner)) {
            return "";
        }
        return $this->getRenderConfig()->getSizesstring();
    }

    /**
     * invalidate precalculated variants after arguments are set
     * mostly used for testing
     */
    private function invalidate()
    {
        $this->owner->setField('imageExtensionVariants', array());
        $this->owner->setField('imageExtensionRenderConfig', array());
    }

    /**
     * get the variant images in different sizes
     *
     * @return array
     */
    private function getVariants()
    {
        // use cached variants if set already
        $variants = $this->owner->imageExtensionVariants;

        // try to get from cache
        if (!is_array($variants) || (count($variants) == 0)) {
            $variants = $this->fromCache();
        }

        if (!is_array($variants) || (count($variants) == 0)) {
            if (!$this->owner->exists()) {
                return array($this->owner);
            }
            if (!static::is_supported_filetype($this->owner)) {
                return array($this->owner);
            }
            if (!($this->owner->getWidth() > 0)) {
                return array($this->owner);
            }

            // ToDo: always get new resizer instance? Better as a singleton / service for global use?
            $resizer = new ImageResizer(Config::inst()->get(self::class, 'minviewport'), Config::inst()->get(self::class, 'maxviewport'), Config::inst()->get(self::class, 'remsize'));

            $src = new ImageDataWrapper($this->owner);
            $result = $resizer->getVariants($src, $this->getRenderConfig());

            $variants = [];
            foreach ($result as $res) {
                $variants[] = $res->getSrcimage();
            }
            $this->toCache($variants);
            $this->owner->setField('imageExtensionVariants', $variants);
        }
        return $variants;
    }

    private function toCache(array $variants)
    {
        $cachekey = $this->getCacheBaseKey() . "_variants";
        $data = [];
        foreach ($variants as $variant) {
            $data[] = [
                "Hash" => $variant->Hash,
                "Filename" => $variant->Filename,
                "Variant" => $variant->Variant
            ];
        }
        $this->getCache()->set($cachekey, $data);
    }

    private function fromCache()
    {
        $cachekey = $this->getCacheBaseKey() . "_variants";
        $data = $this->getCache()->get($cachekey);
        $variants = [];
        if (is_array($data)) {
            /* @var AssetStore $store */
            $store = Injector::inst()->get(AssetStore::class);
            foreach ($data as $datum) {
                try {
                    if ($store->exists($datum["Filename"], $datum["Hash"], $datum["Variant"])) {
                        $filevalue = $datum;
                        $variants[] = DBField::create_field('DBFile', $filevalue);
                    } else {
                        // if something went wrong, abort and recalculate
                        return [];
                    }
                } catch (\Exception $e) {
                    return [];
                }
            }
        }
        return $variants;
    }

    /**
     * get cache instance for calculated resolutions
     * @return CacheInterface
     */
    public function getCache()
    {
        if (!$this->cache) {
            $this->cache = Injector::inst()->get(CacheInterface::class . '.MheImageExtension_variants');
        }
        return $this->cache;
    }

    public static function flush()
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.MheImageExtension_variants');
        $cache->clear();
    }

    /**
     * get cache key for current source image and configuration arguments
     * @return string
     */
    public function getCacheBaseKey()
    {
        $key = hash_init('sha1');
        hash_update($key, $this->owner->getHash());
        hash_update($key, $this->Cssclass() ?: 'default');
        $arguments = $this->getArguments();
        if (isset($arguments['userwidth'])) {
            hash_update($key, $arguments['userwidth']);
        }
        return hash_final($key);
    }

    /**
     * get an enhanced RenderConfig from SilverStripe configuration
     * assuring valid values that can be handled without error
     *
     * @return \Mhe\SmartImages\Model\RenderConfig
     */
    private function getRenderConfig()
    {
        $renderConfig = $this->owner->imageExtensionRenderConfig;

        // try to get from cache
        if (!$renderConfig) {
            $cachekey = $this->getCacheBaseKey() . "_config";
            $renderConfig = $this->getCache()->get($cachekey);
        }

        if (!$renderConfig) {
            $config = null;
            $allConfigs = Config::inst()->get(self::class, 'rendering_classes');

            $cssclass = $this->Cssclass();
            if (!empty($cssclass)) {
                $classes = preg_split('/\s+/', $cssclass);
                foreach ($classes as $class) {
                    if (array_key_exists($class, $allConfigs)) {
                        $config = $allConfigs[$class];
                        break;
                    }
                }
            }
            if (!$config) {
                $config = $allConfigs['default'];
            } else {
                $config = array_merge($allConfigs['default'], $config);
            }

            $config = $this->parseConfigVariables($config);

            // assure valid values
            // ToDo: move to RenderConfig class // static fromConfig() method?
            // ToDo: check special cases for rendersizes etc.
            if (!array_key_exists('rendersizes', $config)) {
                $config['rendersizes'] = [];
            }
            if (!array_key_exists('maxsteps', $config) || !is_numeric($config['maxsteps'])) {
                $config['maxsteps'] = 0;
            }
            if (!array_key_exists('sizediff', $config) && is_numeric($config['sizediff'])) {
                $conf['sizediff'] = intval($config['sizediff']);
            }
            if (!array_key_exists('retinalevel', $config) || !is_numeric($config['retinalevel'])) {
                $config['retinalevel'] = 1;
            } else {
                $config['retinalevel'] = max(min(intval($config['retinalevel']), 3), 1);
            }
            if (!array_key_exists('fallbackwidth', $config) || !is_numeric($config['fallbackwidth'])) {
                $config['fallbackwidth'] = null;
            }

            $renderConfig = new RenderConfig(
                $config['sizes'] ?: '',
                $config['maxsteps'],
                $config['sizediff'],
                $config['retinalevel'],
                $config['rendersizes'],
                $config['fallbackwidth']
            );

            $cachekey = $this->getCacheBaseKey() . "_config";
            $this->getCache()->set($cachekey, $renderConfig);

            $this->owner->setField('imageExtensionRenderConfig', $renderConfig);
        }
        return $renderConfig;
    }


    /**
     * enhances config array with properties
     *   - string $USERWIDTH > replaced by value of argument 'userwidth'
     *
     * @param $config
     * @return array|mixed
     */
    private function parseConfigVariables($config)
    {
        $arguments = $this->getArguments();
        if (!array_key_exists('userwidth', $arguments)) {
            return $config;
        }
        if (is_array($config)) {
            $config = array_map(array($this, 'parseConfigVariables'), $config);
        } elseif (is_string($config)) {
            $config = str_replace('$USERWIDTH', $arguments["userwidth"], $config);
        }
        return $config;
    }


    /**
     * parses an argument string and returns an associative array
     * argument string can be arguments from a shortcode or used by calling a specific rendering programatically or from a template
     *
     * @param $argstr
     * @return array|bool
     */
    private function parseArgString($argstr)
    {
        if (is_array($argstr)) {
            return $argstr;
        }
        if (!is_string($argstr)) {
            return false;
        }
        $arguments = array();
        $args = explode(';', $argstr);
        foreach ($args as $arg) {
            $parts = explode('=', $arg);
            if (count($parts) > 1) {
                $arguments[$parts[0]] = $parts[1];
            } else {
                $arguments[$arg] = true;
            }
        }
        return $arguments;
    }

    private static function is_supported_filetype($image)
    {
        if (!$image || !$image->getExtension()) {
            return false;
        }
        return (in_array($image->getExtension(), static::$supported_extensions));
    }
}
