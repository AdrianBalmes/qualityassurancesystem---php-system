<?php

/**
 * Profile dropdown for the top bar.
 *
 * Deliberately self-contained rather than Bootstrap's dropdown component:
 * repository.php loads no Bootstrap at all, and none of the admin pages load
 * its JS bundle. Class names are prefixed navdd- so they cannot collide with
 * Bootstrap's own .dropdown-* styles on the pages that do load its CSS.
 *
 * Usage, inside <nav class="nav-links">:
 *     render_profile_dropdown('admin_profile.php');
 */

/**
 * @param string $profileUrl Where "Profile" should link.
 * @param string $label      Label for the entry that opens the profile page.
 */
function render_profile_dropdown($profileUrl, $label = 'My Profile'){
    render_nav_dropdown_assets();
    $safeUrl = htmlspecialchars($profileUrl, ENT_QUOTES);
    $safeLabel = htmlspecialchars($label, ENT_QUOTES);

    echo <<<HTML
<div class="navdd">
    <button type="button" class="navdd-toggle" aria-haspopup="true" aria-expanded="false"><i class="bi bi-person-circle"></i> Profile <i class="bi bi-chevron-down navdd-caret"></i></button>
    <div class="navdd-menu" role="menu">
        <a class="navdd-item" role="menuitem" href="{$safeUrl}"><i class="bi bi-person-circle"></i> {$safeLabel}</a>
        <div class="navdd-sep"></div>
        <a class="navdd-item navdd-signout" role="menuitem" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
    </div>
</div>
HTML;
}

/** Emits the styles and script once per page, however many dropdowns render. */
function render_nav_dropdown_assets(){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    // Every top bar paints .nav-links a near-white for the blue background,
    // which would leave menu entries invisible on the white menu. The two-class
    // selectors below outrank that single class + element rule.
    echo <<<'HTML'
<style>
.navdd{position:relative;display:inline-flex}
.nav-links .navdd-toggle{display:inline-flex;align-items:center;gap:6px;color:#eef4ff;background:none;border:0;padding:0;font:inherit;font-weight:700;cursor:pointer}
.nav-links .navdd-toggle:hover{color:#fff}
.navdd-caret{font-size:11px;transition:transform .15s ease}
.navdd.is-open .navdd-caret{transform:rotate(180deg)}
.navdd-menu{position:absolute;top:calc(100% + 10px);right:0;z-index:1200;min-width:210px;padding:6px;background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 10px 26px rgba(44,74,119,.2);display:none}
.navdd.is-open .navdd-menu{display:block}
.nav-links .navdd-item{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:5px;color:#344156;font-size:13.5px;font-weight:700;text-decoration:none;white-space:nowrap}
.nav-links .navdd-item:hover,.nav-links .navdd-item:focus{background:#eef4ff;color:#2e67b8;text-decoration:none}
.nav-links .navdd-signout{color:#c23b36}
.nav-links .navdd-signout:hover,.nav-links .navdd-signout:focus{background:#ffe1dc;color:#a5302b}
.navdd-sep{height:1px;margin:5px 4px;background:#e6edf7}
@media(max-width:1060px){.navdd-menu{right:auto;left:0}}
</style>
<script>
(function(){
    function closeAll(except){
        document.querySelectorAll('.navdd.is-open').forEach(function(dd){
            if(dd === except){ return; }
            dd.classList.remove('is-open');
            var t = dd.querySelector('.navdd-toggle');
            if(t){ t.setAttribute('aria-expanded', 'false'); }
        });
    }

    document.addEventListener('click', function(e){
        var toggle = e.target.closest('.navdd-toggle');
        if(!toggle){
            // A click anywhere else dismisses an open menu.
            closeAll(null);
            return;
        }
        e.preventDefault();
        var dd = toggle.closest('.navdd');
        var willOpen = !dd.classList.contains('is-open');
        closeAll(dd);
        dd.classList.toggle('is-open', willOpen);
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){ closeAll(null); }
    });
})();
</script>
HTML;
}
