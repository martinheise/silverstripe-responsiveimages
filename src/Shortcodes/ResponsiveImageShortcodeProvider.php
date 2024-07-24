<?php

namespace Mhe\SmartImages\Shortcodes;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Shortcodes\ImageShortcodeProvider;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\Parsers\ShortcodeParser;

class ResponsiveImageShortcodeProvider extends ImageShortcodeProvider
{
    /**
     * Replace"[image id=n]" shortcode with an image reference.
     * instead of the default ImageShortcodeProvider this implementation uses a template to render the Image object
     *
     * @param array $args Arguments passed to the parser
     * @param string $content Raw shortcode
     * @param ShortcodeParser $parser Parser
     * @param string $shortcode Name of shortcode used to register this handler
     * @param array $extra Extra arguments
     * @return string Result of the handled shortcode
     */
    public static function handle_shortcode($args, $content, $parser, $shortcode, $extra = array())
    {
        $cache = static::getCache();
        $cacheKey = static::getCacheKey($args);

        $item = $cache->get($cacheKey);
        if ($item) {
            /** @var AssetStore $store */
            $store = Injector::inst()->get(AssetStore::class);
            if (!empty($item['filename'])) {
                $store->grant($item['filename'], $item['hash']);
            }
            return $item['markup'];
        }

        // Find appropriate record, with fallback for error handlers
        $record = static::find_shortcode_record($args, $errorCode);
        if ($errorCode) {
            $record = static::find_error_record($errorCode);
        }
        if (!$record) {
            return null; // There were no suitable matches at all.
        }

        // Build the HTML tag
        $attrs = array_merge(
        // Set overrideable defaults
            [
                'alt' => $record->Title,
                'userwidth' => $args['width']
            ],
            // Use all other shortcode arguments
            $args,
            // But enforce some values
            ['id' => '']
        );

        // Clean out any empty attributes
        $attrs = array_filter($attrs, function ($v) {
            return (bool)$v;
        });

        if (isset($attrs['class'])) {
            $record = $record->Rendering($attrs);
        }
        $record->customise($attrs);
        $markup = $record->forTemplate();

        // cache it for future reference
        $cache->set($cacheKey, [
            'markup' => $markup,
            'filename' => $record instanceof File ? $record->getFilename() : null,
            'hash' => $record instanceof File ? $record->getHash() : null,
        ]);

        return $markup;
    }
}
