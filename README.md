# SilverStripe Responsive Images

An module for SilverStripe that enhances the output of images with multiple resolutions as `srcset` attribute. 

It tries to calculate the most appropriate resolutions for the given image and configuration. 

During setup of the frontend output you will configure the basic rules about which output sizes an image will have (matching the layout setup done in CSS), the particular resolutions to generate will be handled by the module.

Calculation is based on the module [mhe/imagetools](https://github.com/martinheise/imagetools) – see there for more information.

### Background

“Humans shouldn’t be doing this” – some inspiring thoughts on deciding which image resolutions make sense in responsive output can be found in this article by Jason Grigsby: [Image Breakpoints](https://cloudfour.com/thinks/responsive-images-101-part-9-image-breakpoints/), especially [Setting image breakpoints based on a performance budget](https://cloudfour.com/thinks/responsive-images-101-part-9-image-breakpoints/#setting-image-breakpoints-based-on-a-performance-budget)


## Requirements

Requires Silverstripe 6.x – for a version compatible with Silverstripe 5 see respective branch `5`


## Installation and setup

Install with composer:

    composer require mhe/silverstripe-responsiveimages

Perform `?flush`


## Usage overview

The module activates an extension on `Image` objects and also provides an enhanced Shortcode provider. This means the result can be used in templates, programmatically on image output, and also on images placed into page content via the HTML editor.

There can be different configurations for different usages of images, like different layout context and desired visual sizes.

The main parameter which configuration is used for an image is the CSS class. 
- In the HTML editor this usually set by the standard “alignment” options for the image
- In templates or programmatical use it is set directly as parameter 

The configuration _can_ consider a width set by the user, but as default the desired width is set solely by configuration to assure a consistant layout. 


## Configuration

General note: Image processing can be quite heavy on system performance, especially on memory usage. Once the desired images are generated they are cached by Silverstripe, but adding multiple new images, changing the configuration etc. can lead to problems, especially when first loading the appropriate page (Images are resized by Silverstripe on request, which usually means viewing a page).

In case of problems see the general advice below, and try to adjust the configuration options.

### Output configurations

The setup is done in the Silverstripe YAML [configuration API](https://docs.silverstripe.org/en/5/developer_guides/configuration/configuration/).

The configuration property `Mhe\SmartImages\Extensions\ImageExtension.rendering_classes` can contain multiple entries with the name of a CSS class as key.

The special entry `default` is used as a fallback for images without matching configuration – and also its properties are used as fallback for undefined properties of the other entries.

```
Mhe\SmartImages\Extensions\ImageExtension:
  rendering_classes:
    default:
      sizes: "(max-width: 1000px) 100vw, 1000px"
      sizediff: 50000
      highres: 2
      maxsteps: 5
    fullWidth:
      sizes: "100vw"
      highres: 2      
      # takes sizediff and maxsteps from "default" 
    smallThumbnail:
      sizes: "80px"
      maxsteps: 1
    fixed:
      rendersizes:
        - 50
        - 120
    variableImage:
      sizes: "(max-width:$USERWIDTHpx) 100v, $USERWIDTHpx"
```

#### Properties

Also see the information in [Imagetools documentation](https://github.com/martinheise/imagetools)

- `sizes`: Definition of responsive image sizes, possibly with media conditions. This is output directly as `sizes` attribute on the output `img` tag. I addition the extension uses this information to calculate which actual output sizes can occur and which image resolutions are appropriate.
- `sizediff`: desired difference in filesize (bytes) between two resolutions. This is not an exact values, but a rough goal. Lower values mean possibly more different files generated and better adjustment to the responsive output, higher values reduce load on image generation. `maxsteps` parameter has priority, so the resolutions might be roughly distributed evenly with a bigger file size difference.
  - A special string "$USERWIDTH" can be used inside the value – in shortcode use it will be replaced with the source’s width attribute as set by the user, to enable images with variabel, user defined size.  
- `maxsteps`: Limit the number of resolutions to create. If `highres` is > 2, this value is practically multiplied.
- `highres`: (1, 2 or 3) Create additional levels of output resolution, etc. a 2x resolution of 2400px if the maximal layout size is 1200px
- `retinalevel`: deprecated legacy parameter – use `highres` instead
- `rendersizes`: Set the desired resolutions manually, ignoring the other parameters

### Recommended general Silverstripe configuration

Don’t flush resized images, usually not necessary:
```
SilverStripe\Assets\InterventionBackend:
    flush_enabled: true
```

Using imagick backend is often much more performant and avoids memory issues – starting from Silverstripe 6 this is the default anyway if the imagick PHP extension is installed. For details see the [Silverstripe documentation](https://docs.silverstripe.org/en/6/developer_guides/files/images/#intervention-image-driver).

### Use in content

The image shortcodes created by the Silverstripe TinyMCE editor are handled by default. With a default setup you would setup configuration entries as described above, with the keys "left", "right", "center", "leftAlone", "rightAlone".

### Use in templates

Output an image based on a specific configuration:
```
$Image.Rendering('cssclass=hero-img')
```

Rendering is done with a custom `DBFile_image.ss` template, which can be adjusted in a theme or application in the usual way. 

### Use in code

The same way can be used in PHP code to get an enhanced copy of an image object: 
```
/** @var \SilverStripe\Assets\Image $image */
$variant = $image->Rendering(['cssclass' => 'maxsteps_90vw']);
$variant->Srcset();
```
