<?php

namespace App\View\Components;

use App\Support\ResponsiveImage;
use App\Support\ResponsiveImages;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A responsive <picture> for a variant in resources/images/variants.json. Extra attributes land on the <img>.
 *
 * `priority` is for the page's largest image (eager, high fetch priority); `eager` only skips lazy loading.
 */
class Picture extends Component
{
    public ResponsiveImage $image;

    public function __construct(
        ResponsiveImages $images,
        public string $name,
        public string $alt,
        public string $sizes = '100vw',
        public bool $priority = false,
        public bool $eager = false,
    ) {
        $this->image = $images->get($name);
    }

    public function render(): View
    {
        return view('components.picture');
    }
}
