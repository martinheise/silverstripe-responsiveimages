<?php

namespace Mhe\SmartImages\Tests\Extensions;

use DOMDocument, DOMXPath;
use Mhe\SmartImages\Extensions\ImageExtension;
use Page;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

class ImageExtensionTest extends SapphireTest
{
    protected static $fixture_file = '../ImageExtensionTest.yml';

    protected static $fixture_hashbase = 'd4d40648f8';

    protected function setUp(): void
    {
        parent::setUp();
        // Set backend root to /ImageTest
        TestAssetStore::activate('ImageExtensionTest');
        // Copy test images for each of the fixture references
        /** @var File $image */
        $files = File::get()->exclude('ClassName', Folder::class);
        foreach ($files as $image) {
            $sourcePath = __DIR__ . '/../ImageExtensionTest/' . $image->Name;
            $image->setFromLocalFile($sourcePath, $image->Filename);
        }
        // Set default config
        InterventionBackend::config()->set('error_cache_ttl', [
            InterventionBackend::FAILED_INVALID => 0,
            InterventionBackend::FAILED_MISSING => '5,10',
            InterventionBackend::FAILED_UNKNOWN => 300,
        ]);

        Config::modify()->set(ImageExtension::class, 'minviewport', 320);
        Config::modify()->set(ImageExtension::class, 'maxviewport', 1200);

        Config::modify()->set(ImageExtension::class, 'rendering_classes', array(
            'default' => array(
                'sizes' => '100vw',
                'sizediff' => 10000,
                'maxsteps' => 4,
                'retinalevel' => 1
                ),
            'maxsteps_90vw' => array(
                'sizes' => '90vw',
                'sizediff' => 1000,
                'maxsteps' => 4,
                'retinalevel' => 1
            ),
            'sizediff_90vw_2x' => array(
                'sizes' => '90vw',
                'sizediff' => 2000000,
                'maxsteps' => 4,
                'retinalevel' => 2
            ),
            'small' => array(
                'sizes' => '120px',
                'sizediff' => 50000,
                'retinalevel' => 2
                ),
            'userwidth' => array(
                'sizes' => '(max-width:$USERWIDTHpx) calc(100vw - 80px), $USERWIDTHpx',
                'sizediff' => 50000,
                'retinalevel' => 1
                )
            ));
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    protected function assertScaledFilename($expectsize, $filename, $name)
    {
        $filebase = '/assets/folder/' . static::$fixture_hashbase . '/';
        $enc = rtrim(base64_encode('[' . $expectsize . ']'), '=');
        $this->assertEquals($filebase . $name . "__ScaleWidth$enc.jpg", $filename, "Failed asserting filename $filename match size $expectsize");
    }

    protected function getImageTags($content)
    {
        $doc = new DOMDocument();
        $doc->loadHTML("<html><body>$content</body></html>");
        $xpath = new DOMXPath($doc);
        return $xpath->query('//img');
    }

    protected function assertGeneratedImgTagSrcset($imgnode, $expectSrcCount, $expectSizes)
    {
        $srcset = $imgnode->getAttribute('srcset');
        $sources = explode(', ', $srcset);
        $this->assertEquals($expectSrcCount, count($sources), "Failed asserting count of variants match $expectSrcCount");
        foreach ($sources as $index => $source) {
            list($file, $width) = explode(' ', $source);
            if (isset($expectSizes[$index])) {
                $this->assertEquals($expectSizes[$index], intval($width));
            }
            $this->assertMatchesRegularExpression("!\d+w!", $width);
            $this->assertScaledFilename(intval($width), $file, 'testimage1');
        }
    }

    public function testImageDefaultUrl()
    {
        $image = $this->objFromFixture(Image::class, 'testimage1');
        $this->assertEquals(true, $image->exists());
        $filebase = '/assets/folder/' . static::$fixture_hashbase . '/';
        $this->assertEquals($filebase . 'testimage1.jpg', $image->getUrl());
    }

    public function testImageSrc()
    {
        $image = $this->objFromFixture(Image::class, 'testimage1');
        $this->assertScaledFilename(1200, $image->Src(), 'testimage1'); // maxvw
        $res = $image->Rendering(array('cssclass' => 'sizediff_90vw_2x'));
        $this->assertScaledFilename(2160, $res->Src(), 'testimage1'); // = 90vw * retina2 * maxvw
    }

    public function testImageCssclass()
    {
        $image = $this->objFromFixture(Image::class, 'testimage1');
        $this->assertEquals('', $image->Cssclass());

        $image = $image->Rendering(array('cssclass' => 'small'));
        $this->assertEquals('small', $image->Cssclass());
    }

    public function testVariantWidths()
    {
        $image = $this->objFromFixture(Image::class, 'testimage1');
        $widths = $image->VariantWidths();

        // don’t test all the sizes, but basically the limits
        // maxsteps_90vw (no retina) > max 4 steps
        // sizediff_90vw_2x > 2 widths

        $this->assertEquals(4, count($widths));
        $this->assertEquals(1200, $widths[0]); // max viewport

        $res = $image->Rendering(array('cssclass' => 'maxsteps_90vw'));
        $widths = $res->VariantWidths();
        $this->assertEquals(4, count($widths)); // = maxsteps
        $this->assertEquals(1080, $widths[0]); // 1200 * 90vw

        $res = $image->Rendering(array('cssclass' => 'sizediff_90vw_2x'));
        $widths = $res->VariantWidths();
        $this->assertEquals([2160, 1080], $widths);

        $res = $image->Rendering(array('cssclass' => 'small'));
        $this->assertEquals([240, 120], $res->VariantWidths());

        $res = $image->Rendering(array('cssclass' => 'small other-class'));
        $this->assertEquals([240, 120], $res->VariantWidths());

        $res = $image->Rendering(array('cssclass' => 'userwidth', 'userwidth' => 300));
        $this->assertEquals([300], $res->VariantWidths());
        $res = $image->Rendering(array('cssclass' => 'userwidth', 'userwidth' => 1143));
        $widths = $res->VariantWidths();
        $this->assertEquals(1143, $widths[0]);
    }

    public function testImageSrcSet()
    {
        $image = $this->objFromFixture(Image::class, 'testimage1');
        $filebase = '/assets/folder/' . static::$fixture_hashbase . '/';

        // expect 4 generated sources, starting with 1200px
        $sources = explode(', ', $image->Srcset());
        $this->assertEquals(4, count($sources));
        list($file, $width) = explode(' ', $sources[0]);
        $this->assertEquals('1200w', $width);
        $this->assertScaledFilename(1200, $file, 'testimage1');
        foreach ($sources as $source) {
            list($file, $width) = explode(' ', $source);
            $this->assertMatchesRegularExpression("!\d+w!", $width);
            $this->assertScaledFilename(intval($width), $file, 'testimage1');
        }

        $res = $image->Rendering(array('cssclass' => 'small'));
        $files_expected = array(
            '240' => 'testimage1__ScaleWidthWzI0MF0.jpg',
            '120' => 'testimage1__ScaleWidthWzEyMF0.jpg'
        );
        $srcset_expected = "";
        foreach ($files_expected as $px => $name) {
            $srcset_expected .= $filebase . $name . ' ' . $px . 'w, ';
        }
        $srcset_expected = substr($srcset_expected, 0, -2);
        $this->assertEquals($srcset_expected, $res->Srcset());
    }

    public function testImageForTemplate()
    {
        // ToDo: test other default attributes (loading etc.)
        $image = $this->objFromFixture(Image::class, 'testimage1');
        $imgtags = $this->getImageTags($image->forTemplate());
        $this->assertEquals(1, count($imgtags));
        $this->assertGeneratedImgTagSrcset($imgtags[0], 4, [1200]);
        $this->assertEquals('', $imgtags[0]->getAttribute('class'));
        $this->assertEquals('100vw', $imgtags[0]->getAttribute('sizes'));

        $image = $image->Rendering(array('cssclass' => 'small'));
        $imgtags = $this->getImageTags($image->forTemplate());
        $this->assertEquals(1, count($imgtags));
        $this->assertGeneratedImgTagSrcset($imgtags[0], 2, [240, 120]);
        $this->assertEquals('small', $imgtags[0]->getAttribute('class'));
        $this->assertEquals('120px', $imgtags[0]->getAttribute('sizes'));
    }

    public function testImageShortcode()
    {
        // ToDo: test other default attributes (loading etc.)
        $page = $this->objFromFixture(Page::class, 'page1');

        $imgtags = $this->getImageTags($page->obj('Content')->RAW());
        $this->assertEquals(2, count($imgtags));

        $this->assertGeneratedImgTagSrcset($imgtags[0], 4, [1200]);
        $this->assertEquals('leftAlone ss-htmleditorfield-file image', $imgtags[0]->getAttribute('class'));
        $this->assertEquals('100vw', $imgtags[0]->getAttribute('sizes'));

        $this->assertGeneratedImgTagSrcset($imgtags[1], 2, [240, 120]);
        $this->assertEquals('small ss-htmleditorfield-file image', $imgtags[1]->getAttribute('class'));
        $this->assertEquals('120px', $imgtags[1]->getAttribute('sizes'));
    }

    public function testImageShortcodeUserwidth()
    {
        $page = $this->objFromFixture(Page::class, 'page2');

        $imgtags = $this->getImageTags($page->obj('Content')->RAW());
        $this->assertEquals(2, count($imgtags));

        $this->assertGeneratedImgTagSrcset($imgtags[0], 1, [300]);
        $this->assertEquals('userwidth ss-htmleditorfield-file image', $imgtags[0]->getAttribute('class'));
        $this->assertEquals('(max-width:300px) calc(100vw - 80px), 300px', $imgtags[0]->getAttribute('sizes'));

        $this->assertGeneratedImgTagSrcset($imgtags[1], 1, [450]);
        $this->assertEquals('userwidth ss-htmleditorfield-file image', $imgtags[1]->getAttribute('class'));
        $this->assertEquals('(max-width:450px) calc(100vw - 80px), 450px', $imgtags[1]->getAttribute('sizes'));
    }
}
