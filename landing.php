<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/content_helper.php";

/**
 * Public front door. Shows the school identity while the page settles, then
 * offers the way in. Every visible string goes through sc_span(), so an admin
 * can correct the contact details in place with the Edit Page Text button
 * rather than needing this file changed.
 */
$siteContent = sc_load($conn);

// Someone already signed in has no use for the front door.
if(isset($_SESSION['admin_username'])){
    header("Location: home.php");
    exit();
}
if(isset($_SESSION['office_username'], $_SESSION['office_name'])){
    header("Location: office_dashboard.php?office=" . urlencode($_SESSION['office_name']));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>St. Bridget College Batangas — Quality Assurance</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --navy-deep:#0a2f74;
  --navy:#0f3f96;
  --royal:#1a5fc4;
  --wordmark:#12356f;
  --sky-top:#f2f8ff;
  --sky-mid:#dceaf9;
  --sky-low:#c3ddf6;
  --ink:#1c3357;
  --muted:#5b769f;
}
*{box-sizing:border-box}
html{background:var(--sky-mid)}
body{
  margin:0;
  background:var(--sky-mid);
  color:var(--ink);
  font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;
  min-height:100svh;
  display:flex;flex-direction:column;
}

/* ---------------- header ---------------- */
.topbar{
  background:linear-gradient(100deg,var(--navy) 0%,var(--navy-deep) 100%);
  color:#fff;flex-shrink:0;
  box-shadow:0 4px 18px rgba(10,47,116,.28);
  position:relative;z-index:20;
}
.topbar-inner{
  max-width:1560px;margin:0 auto;
  min-height:84px;
  padding-block:12px;padding-left:clamp(16px,3vw,40px);padding-right:clamp(16px,3vw,40px);
  display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;
}
.brand{display:flex;align-items:center;gap:16px;min-width:0}
.brand img{width:58px;height:58px;object-fit:contain;flex-shrink:0}
.brand-rule{width:2px;align-self:stretch;background:rgba(255,255,255,.4);flex-shrink:0}
.brand-text{display:flex;flex-direction:column;justify-content:center;min-width:0}
.brand-name{
  font-family:"Playfair Display",Georgia,serif;font-weight:700;
  font-size:clamp(19px,2.5vw,29px);line-height:1.05;letter-spacing:.01em;
}
.brand-sub{
  font-size:clamp(11px,1.4vw,15px);font-weight:600;
  letter-spacing:.34em;color:#cfe0f8;margin-top:3px;
}

.topnav{display:flex;align-items:center;gap:0;flex-wrap:wrap}
.topnav a{
  display:inline-flex;align-items:center;gap:9px;
  color:#eaf2ff;text-decoration:none;font-weight:600;font-size:14.5px;
  padding:9px 20px;border-radius:7px;
  transition:background .15s ease,color .15s ease;
}
.topnav a:hover,.topnav a:focus-visible{background:rgba(255,255,255,.14);color:#fff}
.topnav a i{font-size:17px;opacity:.9}
.topnav .divider{width:1px;height:26px;background:rgba(255,255,255,.3)}
.topnav .primary{font-weight:700}

/* ---------------- stage ---------------- */
.stage{
  position:relative;flex:1;
  display:grid;place-items:center;
  padding-block:clamp(36px,7vh,80px) clamp(90px,16vh,190px);
  padding-left:20px;padding-right:20px;
  background:linear-gradient(180deg,var(--sky-top) 0%,var(--sky-mid) 52%,var(--sky-low) 100%);
  overflow:hidden;
}
/* The crest again, oversized and barely there, standing in for the campus
   photograph on the right of the comp. Swap in a real photo when there is one. */
.stage::before{
  content:"";position:absolute;
  right:-9%;bottom:-14%;width:min(62vw,760px);aspect-ratio:1;
  background:url('assets/sbc-logo.png') center/contain no-repeat;
  opacity:.07;pointer-events:none;
}
.stage::after{
  content:"";position:absolute;left:-14%;top:-24%;
  width:min(58vw,680px);aspect-ratio:1;border-radius:50%;
  background:radial-gradient(circle,rgba(255,255,255,.85) 0%,rgba(255,255,255,0) 68%);
  pointer-events:none;
}

.hero{position:relative;z-index:3;text-align:center;max-width:760px}
.hero-crest{
  width:clamp(118px,17vw,182px);height:auto;display:block;margin:0 auto 26px;
  filter:drop-shadow(0 10px 22px rgba(10,47,116,.22));
  animation:crestIn .8s cubic-bezier(.2,.75,.3,1) both;
}
.hero h1{
  font-family:"Playfair Display",Georgia,serif;font-weight:700;
  font-size:clamp(30px,5.6vw,58px);line-height:1.04;letter-spacing:.005em;
  color:var(--wordmark);margin:0;text-wrap:balance;
  animation:riseIn .8s .1s cubic-bezier(.2,.75,.3,1) both;
}
.hero .place{
  display:flex;align-items:center;justify-content:center;gap:16px;
  margin:14px auto 2px;max-width:460px;
  animation:riseIn .8s .18s cubic-bezier(.2,.75,.3,1) both;
}
.hero .place span{
  font-size:clamp(13px,1.9vw,19px);font-weight:600;letter-spacing:.42em;
  color:var(--wordmark);white-space:nowrap;
}
.hero .place i{flex:1;height:1px;background:linear-gradient(90deg,transparent,rgba(18,53,111,.5),transparent)}
.hero .qa{
  font-family:"Playfair Display",Georgia,serif;font-style:italic;font-weight:600;
  font-size:clamp(23px,3.9vw,42px);color:#1b4d99;margin:8px 0 0;
  animation:riseIn .8s .26s cubic-bezier(.2,.75,.3,1) both;
}

/* ---------------- tagline ---------------- */
.tagline{
  margin:16px auto 0;max-width:34ch;
  font-size:clamp(13px,1.75vw,17px);font-weight:600;
  letter-spacing:.11em;color:#3f6299;text-wrap:balance;
  animation:riseIn .8s .32s cubic-bezier(.2,.75,.3,1) both;
}

/* ---------------- wave ---------------- */
.wave{position:absolute;left:0;right:0;bottom:-1px;z-index:2;pointer-events:none;line-height:0}
.wave svg{display:block;width:100%;height:clamp(84px,15vh,168px)}

/* ---------------- info panels ---------------- */
.backdrop{
  position:fixed;inset:0;z-index:60;background:rgba(10,30,66,.55);
  backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);
  display:none;align-items:center;justify-content:center;padding:20px;
}
.backdrop.open{display:flex}
.panel{
  background:#fff;border-radius:14px;width:min(520px,100%);
  box-shadow:0 26px 70px rgba(10,30,66,.4);
  padding:28px 30px 26px;position:relative;
  max-height:86vh;overflow:auto;
}
.panel h2{
  font-family:"Playfair Display",Georgia,serif;font-size:24px;font-weight:700;
  color:var(--wordmark);margin:0 0 4px;
}
.panel .kicker{
  font-size:11.5px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;
  color:var(--royal);margin:0 0 14px;
}
.panel p{margin:0 0 12px;font-size:14.5px;line-height:1.65;color:#33496e}
.panel .row{display:flex;gap:12px;align-items:flex-start;margin-bottom:11px;font-size:14.5px}
.panel .row i{color:var(--royal);font-size:17px;margin-top:2px;flex-shrink:0}
.panel-close{
  position:absolute;top:14px;right:14px;border:0;background:#eef4fd;
  color:#3a5c94;width:32px;height:32px;border-radius:8px;cursor:pointer;
  display:grid;place-items:center;font-size:15px;
}
.panel-close:hover{background:#dfeafb}

@keyframes crestIn{from{opacity:0;transform:translateY(14px) scale(.93)}to{opacity:1;transform:none}}
@keyframes riseIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

@media (prefers-reduced-motion:reduce){
  .hero-crest,.hero h1,.hero .place,.hero .qa,.tagline{animation:none}
}

@media (max-width:760px){
  .topbar-inner{min-height:0}
  .topnav{width:100%;justify-content:center;gap:2px}
  .topnav a{padding:8px 12px;font-size:13.5px}
  .topnav a span{display:none}
  .topnav .divider{display:none}
  .brand{width:100%;justify-content:center}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <img src="assets/sbc-logo.png" alt="St. Bridget College crest">
      <div class="brand-rule"></div>
      <div class="brand-text">
        <?php sc_span($siteContent, 'landing.brand', 'ST. BRIDGET COLLEGE', 'div', 'brand-name'); ?>
        <?php sc_span($siteContent, 'landing.brand_sub', 'BATANGAS', 'div', 'brand-sub'); ?>
      </div>
    </div>

    <nav class="topnav">
      <a class="primary" href="index.php"><i class="bi bi-person-fill"></i> <span>Login</span></a>
      <div class="divider"></div>
      <a href="#contact" data-panel="contactPanel"><i class="bi bi-envelope"></i> <span>Contact Us</span></a>
      <div class="divider"></div>
      <a href="#about" data-panel="aboutPanel"><i class="bi bi-info-circle"></i> <span>About QA Office</span></a>
    </nav>
  </div>
</header>

<main class="stage">
  <div class="hero">
    <img class="hero-crest" src="assets/sbc-logo.png" alt="St. Bridget College crest, A.D. 1913">

    <?php sc_span($siteContent, 'landing.title', 'ST. BRIDGET COLLEGE', 'h1', ''); ?>

    <div class="place">
      <i></i>
      <?php sc_span($siteContent, 'landing.place', 'BATANGAS'); ?>
      <i></i>
    </div>

    <?php sc_span($siteContent, 'landing.system', 'Quality Assurance', 'p', 'qa'); ?>

    <?php sc_span($siteContent, 'landing.tagline', 'Committed to Quality and Continuous Improvement', 'p', 'tagline'); ?>
  </div>

  <div class="wave" aria-hidden="true">
    <svg viewBox="0 0 1440 170" preserveAspectRatio="none">
      <path d="M0,96 C240,168 420,34 720,58 C1010,81 1180,150 1440,96 L1440,170 L0,170 Z" fill="#1a5fc4" opacity=".55"></path>
      <path d="M0,124 C260,182 430,66 760,88 C1040,107 1200,166 1440,120 L1440,170 L0,170 Z" fill="#0f3f96"></path>
    </svg>
  </div>
</main>

<!-- Contact -->
<div class="backdrop" id="contactPanel" role="dialog" aria-modal="true" aria-labelledby="contactTitle">
  <div class="panel">
    <button type="button" class="panel-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
    <p class="kicker">Get in touch</p>
    <?php sc_span($siteContent, 'landing.contact_title', 'Quality Assurance Office', 'h2', ''); ?>
    <div class="row"><i class="bi bi-geo-alt-fill"></i><?php sc_span($siteContent, 'landing.contact_address', 'M.H. del Pilar Street, Batangas City, Batangas'); ?></div>
    <div class="row"><i class="bi bi-envelope-fill"></i><?php sc_span($siteContent, 'landing.contact_email', 'Add the QA Office email address here'); ?></div>
    <div class="row"><i class="bi bi-telephone-fill"></i><?php sc_span($siteContent, 'landing.contact_phone', 'Add the QA Office telephone number here'); ?></div>
    <div class="row"><i class="bi bi-clock-fill"></i><?php sc_span($siteContent, 'landing.contact_hours', 'Add office hours here'); ?></div>
  </div>
</div>

<!-- About -->
<div class="backdrop" id="aboutPanel" role="dialog" aria-modal="true" aria-labelledby="aboutTitle">
  <div class="panel">
    <button type="button" class="panel-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
    <p class="kicker">About</p>
    <?php sc_span($siteContent, 'landing.about_title', 'The QA Office', 'h2', ''); ?>
    <?php sc_span($siteContent, 'landing.about_body', 'The Quality Assurance Office coordinates internal and external audits across every department of St. Bridget College, tracks the recommendations each audit produces, and keeps the supporting documents offices submit in one place. Replace this paragraph with the office\'s own description.', 'p', ''); ?>
    <?php sc_span($siteContent, 'landing.about_note', 'Department staff sign in to view the recommendations assigned to their office and upload their compliance documents.', 'p', ''); ?>
  </div>
</div>

<script>
(function(){
  // Contact / About open in place, so neither is a link to a page that does
  // not exist yet.
  function open(panel){ panel.classList.add('open'); panel.querySelector('[data-close]').focus(); }
  function closeAll(){ document.querySelectorAll('.backdrop.open').forEach(function(p){ p.classList.remove('open'); }); }

  document.querySelectorAll('[data-panel]').forEach(function(link){
    link.addEventListener('click', function(e){
      e.preventDefault();
      open(document.getElementById(link.getAttribute('data-panel')));
    });
  });
  document.querySelectorAll('[data-close]').forEach(function(b){ b.addEventListener('click', closeAll); });
  document.querySelectorAll('.backdrop').forEach(function(p){
    p.addEventListener('click', function(e){ if(e.target === p){ closeAll(); } });
  });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ closeAll(); } });
})();
</script>

<?php render_edit_toggle(); ?>
</body>
</html>
