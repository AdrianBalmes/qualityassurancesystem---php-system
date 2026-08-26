<?php

/**
 * Faded school banner behind every page.
 *
 * The image sits on a fixed layer behind the content with a translucent veil
 * over it, so the photo reads as a watermark and never fights the text. If the
 * image file is absent the veil colour alone shows, which is why each variant
 * carries a fallback that matches what the page looked like before.
 *
 * Drop the banner in at PAGE_BACKGROUND_IMAGE below. Any wide photo works;
 * roughly 1920px across keeps it sharp on a large monitor.
 *
 * Usage, immediately after <body>:
 *     render_page_background();          // signed-in pages, subtle
 *     render_page_background('auth');    // login and register, more visible
 */

const PAGE_BACKGROUND_IMAGE = 'assets/sbc-banner.jpg';

/** How much veil sits over the photo. Higher = fainter photo. */
const PAGE_BACKGROUND_VEIL_APP  = '0.80';
const PAGE_BACKGROUND_VEIL_AUTH = '0.86';
const PAGE_BACKGROUND_VEIL_DARK = '0.60';

function render_page_background($variant = 'app'){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    // Fallbacks reproduce the background each page would have without an
    // image, so a missing file leaves the design intact rather than blank.
    // The veil is tinted to match its fallback.
    switch($variant){
        case 'dark':   // the sign-in pages: white card on deep navy
            $fallback = '#0f172a';
            $veilColor = 'rgba(15,23,42,' . PAGE_BACKGROUND_VEIL_DARK . ')';
            break;
        case 'auth':
            $fallback = 'linear-gradient(135deg,#f6f8fb 0%,#e9effd 100%)';
            $veilColor = 'rgba(248,250,253,' . PAGE_BACKGROUND_VEIL_AUTH . ')';
            break;
        default:
            $fallback = '#eef3fb';
            $veilColor = 'rgba(238,243,251,' . PAGE_BACKGROUND_VEIL_APP . ')';
    }

    $hasImage = is_file(__DIR__ . '/' . PAGE_BACKGROUND_IMAGE);
    $imageRule = $hasImage
        ? "background-image:url('" . htmlspecialchars(PAGE_BACKGROUND_IMAGE, ENT_QUOTES) . "');background-size:cover;background-position:center;background-repeat:no-repeat;"
        : "";

    echo <<<HTML
<div class="page-bg" aria-hidden="true"></div>
<style>
html{background:{$fallback}}
/* The layer below supplies the background, so the page's own solid colour
   would hide it. body[class] is needed as well as body: a utility class such
   as Bootstrap's .bg-light is itself !important, and a class selector
   outranks a bare element selector, so plain `body` loses to it. */
body,body[class]{background-color:transparent !important;background-image:none !important}
.page-bg{position:fixed;inset:0;z-index:-1;{$imageRule}}
.page-bg::after{content:'';position:absolute;inset:0;background:{$veilColor}}
@media print{.page-bg{display:none}}
</style>
HTML;
}
