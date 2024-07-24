<?php

use Mhe\SmartImages\Shortcodes\ResponsiveImageShortcodeProvider;
use SilverStripe\View\Parsers\ShortcodeParser;

ShortcodeParser::get('default')
    ->register('image', [ResponsiveImageShortcodeProvider::class, 'handle_shortcode']);

// Shortcode parser which only regenerates shortcodes
ShortcodeParser::get('regenerator')
    ->register('image', [ResponsiveImageShortcodeProvider::class, 'regenerate_shortcode']);
