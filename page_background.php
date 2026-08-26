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

const PAGE_BACKGROUND_IMAGE = 'uploads/sbc-banner.jpg';

/** How much veil sits over the photo. Higher = fainter photo. */
const PAGE_BACKGROUND_VEIL_APP  = '0.93';
const PAGE_BACKGROUND_VEIL_AUTH = '0.86';

function render_page_background($variant = 'app'){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    $isAuth = $variant === 'auth';
    $veil = $isAuth ? PAGE_BACKGROUND_VEIL_AUTH : PAGE_BACKGROUND_VEIL_APP;

    // Fallbacks reproduce each page's original background, so a missing image
    // leaves the design exactly as it was rather than a flat empty colour.
    $fallback = $isAuth
        ? 'linear-gradient(135deg,#f6f8fb 0%,#e9effd 100%)'
        : '#eef3fb';

    // rgba veil tinted to match the fallback.
    $veilColor = $isAuth ? "rgba(248,250,253,{$veil})" : "rgba(238,243,251,{$veil})";

    $hasImage = is_file(__DIR__ . '/' . PAGE_BACKGROUND_IMAGE);
    $imageRule = $hasImage
        ? "background-image:url('" . htmlspecialchars(PAGE_BACKGROUND_IMAGE, ENT_QUOTES) . "');background-size:cover;background-position:center;background-repeat:no-repeat;"
        : "";

    echo <<<HTML
<div class="page-bg" aria-hidden="true"></div>
<style>
html{background:{$fallback}}
/* The layer below supplies the background, so the page's own solid colour
   would hide it. */
body{background-color:transparent !important;background-image:none !important}
.page-bg{position:fixed;inset:0;z-index:-1;{$imageRule}}
.page-bg::after{content:'';position:absolute;inset:0;background:{$veilColor}}
@media print{.page-bg{display:none}}
</style>
HTML;
}
