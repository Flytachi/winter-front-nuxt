<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt;

/** How a Nuxt frontend's pages are made. */
enum Mode: string
{
    /** One page for every route, the browser builds the rest: `generate`, `ssr: false`. */
    case Spa = 'spa';

    /** Every page built in advance: `generate`. */
    case Ssg = 'ssg';
}
