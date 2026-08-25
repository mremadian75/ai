<?php
/**
 * [future_island_saap] — لندینگ کامل Future Island Saap به صورت فول PHP
 *
 * نحوهٔ استفاده:
 * ۱) این اسنیپت را در WPCode Pro به صورت «PHP Snippet» (Run Everywhere) ذخیره و فعال کنید.
 * ۲) در صفحهٔ اصلی فقط این شورت‌کد را بگذارید:  [future_island_saap]
 *
 * همهٔ تنظیمات (لینک عکس‌ها، آیدی ویدیو، لینک دکمهٔ رزرو) در بلاک «تنظیمات»
 * ابتدای تابع پایین است. عکس داشبورد هنوز آماده نیست؛ هر وقت آماده شد آدرسش
 * را در $img_dash بگذارید تا سکشن «Producto» به‌صورت خودکار نمایش داده شود.
 *
 * موشن‌ها: سه پرندهٔ مینیمال در هیرو (ارتفاع/جهت/سرعت متفاوت) + ورود نرم متن
 * هیرو. همه CSS خالص، و با prefers-reduced-motion به‌طور کامل غیرفعال می‌شوند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function fisaap_landing_shortcode() {

	// ─── تنظیمات ───────────────────────────────────────────────────────────
	$video_id  = '1OZWMO8UD6M'; // آیدی ویدیوی یوتیوب (اتوپلی بی‌صدا — سیاست مرورگرهاست)
	$img_hero  = 'https://future-island.club/wp-content/uploads/2026/08/ChatGPT-Image-Aug-25-2026-04_13_24-PM.png';
	$img_cita  = 'https://future-island.club/wp-content/uploads/2026/08/ChatGPT-Image-Aug-25-2026-04_13_57-PM.png';
	$img_icons = 'https://future-island.club/wp-content/uploads/2026/08/ChatGPT-Image-Aug-25-2026-04_16_31-PM.png';
	$img_dash  = ''; // ← آدرس عکس داشبورد؛ تا وقتی خالی است سکشن «Producto» مخفی می‌ماند
	$book_url  = '#fisaap-demo'; // دکمهٔ «RESERVAR MINI CITA» به سکشن دمو اسکرول می‌کند
	$site_url  = 'https://www.future-island.club';
	// ویجت Calendly
	$calendly_url = 'https://calendly.com/mahan-future-island/25min?hide_event_type_details=1&hide_gdpr_banner=1';
	// ───────────────────────────────────────────────────────────────────────

	$video_src = 'https://www.youtube.com/embed/' . rawurlencode( $video_id ) . '?rel=0&autoplay=1&mute=1&playsinline=1';

	ob_start();
	?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Newsreader:ital,wght@0,300;0,400;1,400&display=swap">
<style>
/* ═══ Future Island Saap — scoped styles (fisaap-) ═══ */
body:has(.fisaap){ overflow-x:hidden }
.fisaap{ background:#f4f1ec; color:#0a0908; font-family:Archivo,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased; direction:ltr; text-align:left; line-height:1.5;
  margin-left:calc(50% - 50vw); margin-right:calc(50% - 50vw) } /* full-bleed از داخل کانتینر قالب */
.fisaap, .fisaap *, .fisaap *::before, .fisaap *::after{ box-sizing:border-box }
/* گارد در برابر استایل‌های قالب: لینک‌ها و تیترها داخل لندینگ دست قالب نیفتند */
.fisaap p{ margin:0 }
.fisaap h1, .fisaap h2, .fisaap h3{ margin:0; padding:0 }
.fisaap a{ color:inherit !important; text-decoration:none !important; border-bottom:none !important; box-shadow:none !important }
.fisaap img{ border:0; box-shadow:none }
.fisaap-serif{ font-family:Newsreader,Georgia,serif; font-weight:400 }
.fisaap-kicker{ font-size:clamp(10px,.85vw,12.5px); font-weight:600; letter-spacing:.1em; color:#3b3731 }

/* ── Hero ── */
.fisaap-hero{ position:relative; min-height:min(88vh,820px); background-size:cover;
  background-position:56% 46%; background-color:#0a0908; overflow:hidden;
  display:flex; flex-direction:column; justify-content:flex-end; padding:clamp(22px,3.2vw,44px) }
.fisaap-hero::after{ content:""; position:absolute; inset:0; z-index:0; pointer-events:none;
  background:linear-gradient(180deg,rgba(10,9,8,0) 34%,rgba(10,9,8,.28) 62%,rgba(10,9,8,.78) 100%) }
.fisaap-hero-content{ position:relative; z-index:2; display:flex; flex-direction:column;
  gap:clamp(10px,1.2vw,18px); max-width:min(1000px,92%); padding-bottom:clamp(6px,1.4vw,20px);
  animation:fisaap-hero-in 1.1s cubic-bezier(.2,.8,.2,1) .15s both }
.fisaap-hero-kicker{ color:#fdd955; font-size:clamp(11px,1.1vw,16px); font-weight:700; letter-spacing:.14em }
.fisaap-hero h1{ margin:0; color:#fff; font-size:clamp(42px,6.6vw,108px); font-weight:800;
  line-height:.9; letter-spacing:-.03em }
.fisaap-hero-sub{ color:#fff; font-size:clamp(12px,1.2vw,18px); font-weight:700; letter-spacing:.055em; line-height:1.5 }
@keyframes fisaap-hero-in{ from{ opacity:0; transform:translateY(16px) } to{ opacity:1; transform:translateY(0) } }

/* ── پرنده‌ها: تنظیم هر پرنده روی خود المنت (--fi-*) ── */
.fisaap-birds{ --fi-bird-color:rgba(16,13,10,.78); position:absolute; inset:0; z-index:1;
  overflow:hidden; pointer-events:none }
.fisaap-bird-x{ position:absolute; left:0; top:var(--fi-top,14%); width:100%; opacity:0;
  animation-name:fisaap-fly; animation-duration:var(--fi-cycle,30s); animation-timing-function:linear;
  animation-delay:var(--fi-delay,1.6s); animation-iteration-count:infinite; will-change:transform,opacity }
.fisaap-bird-x.is-rtl{ animation-name:fisaap-fly-rtl }
.fisaap-bird-x.is-rtl .fisaap-bird-svg{ transform:scaleX(-1) }
.fisaap-bird-y{ width:var(--fi-size,32px); animation-name:fisaap-bob;
  animation-duration:var(--fi-bob,6.4s); animation-timing-function:cubic-bezier(.45,0,.55,1);
  animation-iteration-count:infinite; animation-direction:alternate }
.fisaap-bird-svg{ display:block; width:100%; height:auto; color:var(--fi-bird-color) }
.fisaap-bird-svg path{ fill:none; stroke:currentColor; stroke-width:4; stroke-linecap:round }
.fisaap-wing{ transform-box:view-box; transform-origin:32px 24px }
.fisaap-wing-l{ animation:fisaap-flap-l var(--fi-flap,2.4s) ease-in-out infinite }
.fisaap-wing-r{ animation:fisaap-flap-r var(--fi-flap,2.4s) ease-in-out infinite }
@keyframes fisaap-fly{
  0%{ transform:translateX(-8%); opacity:0 }
  2.5%{ opacity:var(--fi-op,.92) }
  69%{ opacity:var(--fi-op,.92) }
  71.5%{ transform:translateX(102%); opacity:0 }
  100%{ transform:translateX(102%); opacity:0 } }
@keyframes fisaap-fly-rtl{
  0%{ transform:translateX(102%); opacity:0 }
  2.5%{ opacity:var(--fi-op,.92) }
  69%{ opacity:var(--fi-op,.92) }
  71.5%{ transform:translateX(-8%); opacity:0 }
  100%{ transform:translateX(-8%); opacity:0 } }
@keyframes fisaap-bob{
  from{ transform:translateY(0) rotate(1.2deg) }
  to{ transform:translateY(-16px) rotate(-2.4deg) } }
@keyframes fisaap-flap-l{
  0%{ transform:rotate(8deg) } 9%{ transform:rotate(30deg) } 19%{ transform:rotate(-16deg) }
  29%{ transform:rotate(30deg) } 39%{ transform:rotate(-16deg) } 49%{ transform:rotate(26deg) }
  58%{ transform:rotate(8deg) } 100%{ transform:rotate(8deg) } }
@keyframes fisaap-flap-r{
  0%{ transform:rotate(-8deg) } 9%{ transform:rotate(-30deg) } 19%{ transform:rotate(16deg) }
  29%{ transform:rotate(-30deg) } 39%{ transform:rotate(16deg) } 49%{ transform:rotate(-26deg) }
  58%{ transform:rotate(-8deg) } 100%{ transform:rotate(-8deg) } }
@media (prefers-reduced-motion:reduce){
  .fisaap-birds{ display:none }
  .fisaap-hero-content{ animation:none } }

/* ── Intro + video ── */
.fisaap-intro{ padding:clamp(48px,6vw,96px) clamp(22px,5vw,64px) clamp(28px,3.5vw,52px) }
.fisaap-intro-inner{ max-width:1000px; margin:0 auto; display:flex; flex-direction:column; gap:clamp(20px,2.4vw,34px) }
.fisaap-intro h2{ margin:0; font-size:clamp(34px,4.4vw,62px); line-height:1.07; letter-spacing:-.015em; color:#0a0908 }
.fisaap-intro h2 em{ font-style:italic }
.fisaap-intro-text{ max-width:76ch; font-size:clamp(13px,1.05vw,15.5px); line-height:1.85; color:#2a2622 }
.fisaap-video-block{ display:flex; flex-direction:column; gap:clamp(14px,1.6vw,22px); margin-top:clamp(6px,1vw,14px) }
.fisaap-video{ position:relative; width:100%; aspect-ratio:16/8.6; background:#000; border-radius:10px;
  overflow:hidden; box-shadow:0 1px 2px rgba(10,9,8,.14) }
.fisaap-video iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; display:block }
.fisaap-video-caption{ display:flex; justify-content:center; align-items:center; gap:9px;
  font-size:clamp(10px,.85vw,12.5px); font-weight:600; letter-spacing:.09em; color:#3b3731 }

/* ── Cómo funciona ── */
.fisaap-steps{ padding:clamp(34px,4.5vw,70px) clamp(22px,5vw,64px) clamp(40px,5vw,80px) }
.fisaap-steps-inner{ max-width:880px; margin:0 auto; display:flex; flex-direction:column; gap:clamp(26px,3vw,44px) }
.fisaap-steps-head{ display:flex; flex-direction:column; align-items:center; gap:14px; text-align:center }
.fisaap-steps h2{ margin:0; font-size:clamp(26px,3.1vw,42px); line-height:1.14; letter-spacing:.02em; color:#0a0908 }
.fisaap-steps-rule{ width:52px; height:1px; background:rgba(10,9,8,.28) }
.fisaap-step{ display:flex; align-items:center; gap:clamp(18px,2.6vw,40px); flex-wrap:wrap;
  padding:clamp(16px,2vw,26px) 0; border-bottom:1px solid rgba(10,9,8,.13) }
.fisaap-step:last-child{ border-bottom:none }
.fisaap-step-icon{ flex:none; width:clamp(96px,10vw,140px) }
.fisaap-step-crop{ width:100%; overflow:hidden; position:relative }
.fisaap-step-crop img{ display:block; width:100%; height:auto }
.fisaap-step-body{ flex:1 1 300px; min-width:min(100%,260px); display:flex; flex-direction:column; gap:7px }
.fisaap-step-num{ font-size:clamp(10px,.85vw,12.5px); font-weight:600; letter-spacing:.09em; color:#57524b }
.fisaap-step-title{ font-size:clamp(14px,1.25vw,19px); font-weight:700; letter-spacing:-.005em; color:#0a0908 }
.fisaap-step-body p{ font-size:clamp(12.5px,1.02vw,15px); line-height:1.68; color:#3b3731; max-width:46ch }

/* ── Producto (فقط وقتی عکس داشبورد تنظیم شده باشد) ── */
.fisaap-product{ padding:0 clamp(22px,5vw,64px) clamp(44px,5.5vw,86px) }
.fisaap-product-inner{ max-width:1000px; margin:0 auto; display:flex; flex-direction:column;
  gap:clamp(18px,2.2vw,30px); border-top:1px solid rgba(10,9,8,.16); padding-top:clamp(34px,4.4vw,68px) }
.fisaap-product-head{ display:flex; flex-wrap:wrap; gap:clamp(14px,2vw,28px); align-items:flex-end; justify-content:space-between }
.fisaap-product-title{ display:flex; flex-direction:column; gap:clamp(10px,1.2vw,16px) }
.fisaap-product h2{ margin:0; font-size:clamp(26px,3.1vw,44px); line-height:1.1; letter-spacing:-.012em; color:#0a0908 }
.fisaap-product-tag{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  font-size:clamp(9.5px,.78vw,11.5px); letter-spacing:.1em; color:#57524b; padding-bottom:6px }
.fisaap-product-frame{ position:relative; width:100%; aspect-ratio:16/9.2; border:1px solid rgba(10,9,8,.12);
  border-radius:6px; overflow:hidden; background:#f2eee9 }
.fisaap-product-frame img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block }

/* ── Mini cita ── */
.fisaap-cita{ padding:0 clamp(22px,5vw,64px) clamp(20px,2.4vw,34px) }
.fisaap-cita-card{ max-width:1000px; margin:0 auto; display:flex; flex-wrap:wrap; background:#f2eee9;
  border:1px solid rgba(10,9,8,.12); border-radius:6px; overflow:hidden }
.fisaap-cita-img{ flex:1 1 300px; min-width:min(100%,280px); min-height:clamp(240px,26vw,340px);
  background-size:cover; background-position:50% 44%; background-color:#0a0908 }
.fisaap-cita-body{ flex:1.25 1 380px; min-width:min(100%,320px); display:flex; flex-direction:column;
  gap:clamp(12px,1.4vw,20px); padding:clamp(26px,3.4vw,48px) }
.fisaap-cita h2{ margin:0; font-size:clamp(23px,2.5vw,35px); line-height:1.2; letter-spacing:-.01em; color:#0a0908 }
.fisaap-cita-body p{ font-size:clamp(12.5px,1.02vw,15px); line-height:1.7; color:#3b3731; max-width:48ch }
.fisaap a.fisaap-btn{ align-self:flex-start; margin-top:clamp(4px,.8vw,10px); display:flex; align-items:center;
  gap:clamp(24px,3vw,52px); background:#151310 !important; color:#f4f1ec !important; padding:16px 22px;
  border:0 !important; border-radius:4px; font-size:clamp(11px,.95vw,14px); font-weight:700;
  letter-spacing:.08em; transition:background .2s,color .2s }
.fisaap a.fisaap-btn:hover{ background:#0a0908 !important; color:#fff !important }
.fisaap-btn span{ font-size:1.15em }

/* ── Diferenciadores ── */
.fisaap-diff{ padding:clamp(14px,2vw,26px) clamp(22px,5vw,64px) clamp(48px,6vw,90px) }
.fisaap-diff-grid{ max-width:1000px; margin:0 auto; background:#f2eee9; border:1px solid rgba(10,9,8,.12);
  border-radius:6px; display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)) }
.fisaap-diff-cell{ display:flex; flex-direction:column; align-items:center; text-align:center; gap:13px;
  padding:clamp(24px,3vw,38px) clamp(16px,2vw,26px); border-right:1px solid rgba(10,9,8,.1) }
.fisaap-diff-cell:last-child{ border-right:none }
.fisaap-diff-title{ font-size:clamp(10.5px,.9vw,13px); font-weight:700; letter-spacing:.07em; line-height:1.35 }
.fisaap-diff-rule{ width:100%; height:1px; background:rgba(10,9,8,.13) }
.fisaap-diff-cell p{ font-size:clamp(11.5px,.92vw,13.5px); line-height:1.62; color:#3b3731 }

/* ── Cierre ── */
.fisaap-close{ padding:0 clamp(22px,5vw,64px) }
.fisaap-close-inner{ max-width:1000px; margin:0 auto; border-top:1px solid rgba(10,9,8,.16);
  padding:clamp(40px,5vw,76px) 0 clamp(34px,4vw,60px); display:flex; flex-wrap:wrap;
  gap:clamp(28px,4vw,56px); align-items:flex-start }
.fisaap-close h2{ flex:1 1 380px; min-width:min(100%,300px); margin:0; font-size:clamp(26px,3.2vw,44px);
  line-height:1.2; letter-spacing:.005em; color:#0a0908 }
.fisaap-close-side{ flex:1 1 300px; min-width:min(100%,280px); display:flex; flex-direction:column;
  gap:clamp(12px,1.4vw,18px); border-left:1px solid rgba(10,9,8,.16); padding-left:clamp(20px,3vw,44px) }
.fisaap-close-brand{ font-size:clamp(16px,1.6vw,24px); font-weight:700; letter-spacing:-.01em }
.fisaap-close-quote{ font-size:clamp(14px,1.25vw,19px); line-height:1.5; color:#2a2622 }
.fisaap a.fisaap-close-link{ font-size:clamp(10.5px,.9vw,13px); font-weight:600; letter-spacing:.09em; color:#3b3731 !important; transition:color .2s }
.fisaap a.fisaap-close-link:hover{ color:#0a0908 !important }
.fisaap-spacer{ height:clamp(34px,4vw,64px) }

/* ── Demo (Calendly) ── */
.fisaap-demo{ padding:clamp(14px,2vw,26px) clamp(22px,5vw,64px) clamp(28px,3.5vw,52px) }
.fisaap-demo-inner{ max-width:1000px; margin:0 auto; display:flex; flex-direction:column;
  gap:clamp(18px,2.2vw,30px); border-top:1px solid rgba(10,9,8,.16); padding-top:clamp(34px,4.4vw,60px) }
.fisaap-demo-head{ display:flex; flex-direction:column; gap:clamp(10px,1.2vw,16px) }
.fisaap-demo h2{ margin:0; font-size:clamp(26px,3.1vw,44px); line-height:1.12; letter-spacing:-.012em; color:#0a0908 }
.fisaap-demo-note{ font-size:clamp(12.5px,1.02vw,15px); line-height:1.7; color:#3b3731; max-width:60ch }
.fisaap-calendly{ background:#fff; border:1px solid rgba(10,9,8,.12); border-radius:6px; overflow:hidden }
.fisaap-calendly .calendly-inline-widget{ min-width:320px }

/* ── Scroll reveal (فقط وقتی JS فعال است؛ بدون JS همه‌چیز دیده می‌شود) ── */
.fisaap-js .fisaap-reveal{ opacity:0; transform:translateY(22px);
  transition:opacity .6s cubic-bezier(.2,.8,.2,1) var(--rd,0s), transform .6s cubic-bezier(.2,.8,.2,1) var(--rd,0s) }
.fisaap-js .fisaap-reveal.is-in{ opacity:1; transform:none }

/* ═══ موبایل — بازطراحی اختصاصی ═══ */
@media (max-width:640px){
  /* Hero: کوتاه‌تر و متن درشت‌تر */
  .fisaap-hero{ min-height:min(70vh,560px); min-height:min(70svh,560px); padding:18px 18px 30px }
  .fisaap-hero h1{ font-size:12vw; line-height:.92 }
  .fisaap-hero-kicker{ font-size:11.5px }
  .fisaap-hero-sub{ font-size:12.5px }
  /* Intro: حذف شکست‌های خط دستی + تایپ خواناتر */
  .fisaap-intro{ padding:52px 20px 30px }
  .fisaap-intro h2 br, .fisaap-intro-text br{ display:none }
  .fisaap-intro h2{ font-size:31px; line-height:1.12 }
  .fisaap-intro-text{ font-size:14px; line-height:1.8 }
  .fisaap-video{ aspect-ratio:16/9; border-radius:8px }
  .fisaap-video-caption{ font-size:10.5px; text-align:center; line-height:1.6 }
  /* Steps: آیکون کوچک کنار متن، بدون شکستن ردیف */
  .fisaap-steps{ padding:40px 20px 46px }
  .fisaap-steps h2 br{ display:none }
  .fisaap-steps h2{ font-size:26px; line-height:1.2 }
  .fisaap-step{ flex-wrap:nowrap; align-items:flex-start; gap:16px; padding:18px 0 }
  .fisaap-step-icon{ width:74px }
  .fisaap-step-body{ flex:1; min-width:0 }
  .fisaap-step-title{ font-size:15px }
  .fisaap-step-body p{ font-size:13.5px }
  /* Producto */
  .fisaap-product{ padding:0 20px 40px }
  .fisaap-product h2{ font-size:26px }
  /* Mini cita */
  .fisaap-cita{ padding:0 20px 16px }
  .fisaap-cita-img{ min-height:230px }
  .fisaap-cita-body{ padding:24px 20px 26px }
  .fisaap-cita h2{ font-size:22px }
  .fisaap-cita-body p{ font-size:13.5px }
  .fisaap-btn{ align-self:stretch; justify-content:space-between }
  /* Diferenciadores: شبکهٔ ۲×۲ فشرده */
  .fisaap-diff{ padding:12px 20px 40px }
  .fisaap-diff-grid{ grid-template-columns:1fr 1fr }
  .fisaap-diff-cell{ gap:10px; padding:22px 14px; border-right:none; border-bottom:none }
  .fisaap-diff-cell:nth-child(odd){ border-right:1px solid rgba(10,9,8,.1) }
  .fisaap-diff-cell:nth-child(-n+2){ border-bottom:1px solid rgba(10,9,8,.1) }
  .fisaap-diff-title{ font-size:11px }
  .fisaap-diff-cell p{ font-size:12px }
  /* Demo: ارتفاع ویجت هم‌اندازهٔ محتوای موبایل Calendly تا فضای سفید مرده نماند */
  .fisaap-demo{ padding:14px 20px 30px }
  .fisaap-demo h2{ font-size:27px }
  .fisaap-demo-note{ font-size:13.5px }
  .fisaap-calendly .calendly-inline-widget{ height:640px !important }
  /* Cierre */
  .fisaap-close{ padding:0 20px }
  .fisaap-close-inner{ gap:26px; padding:42px 0 34px }
  .fisaap-close h2{ font-size:27px }
  .fisaap-close-side{ border-left:none; padding-left:0; border-top:1px solid rgba(10,9,8,.16); padding-top:22px }
}
</style>

<div class="fisaap" dir="ltr">

  <!-- Hero -->
  <section class="fisaap-hero" aria-labelledby="fisaap-h-hero" style="background-image:url('<?php echo esc_url( $img_hero ); ?>')">
    <div class="fisaap-birds" aria-hidden="true">
      <div class="fisaap-bird-x" style="--fi-top:14%; --fi-size:clamp(26px,2.8vw,40px); --fi-cycle:30s; --fi-delay:1.6s; --fi-flap:2.4s; --fi-op:.92; --fi-bob:6.4s">
        <div class="fisaap-bird-y">
          <svg class="fisaap-bird-svg" viewBox="0 0 64 44">
            <path class="fisaap-wing fisaap-wing-l" d="M32 24 C25.5 15.5 15 10 4 13"></path>
            <path class="fisaap-wing fisaap-wing-r" d="M32 24 C38.5 15.5 49 10 60 13"></path>
          </svg>
        </div>
      </div>
      <div class="fisaap-bird-x is-rtl" style="--fi-top:7%; --fi-size:clamp(15px,1.7vw,24px); --fi-cycle:44s; --fi-delay:9s; --fi-flap:2.9s; --fi-op:.6; --fi-bob:7.8s">
        <div class="fisaap-bird-y">
          <svg class="fisaap-bird-svg" viewBox="0 0 64 44">
            <path class="fisaap-wing fisaap-wing-l" d="M32 24 C25.5 15.5 15 10 4 13"></path>
            <path class="fisaap-wing fisaap-wing-r" d="M32 24 C38.5 15.5 49 10 60 13"></path>
          </svg>
        </div>
      </div>
      <div class="fisaap-bird-x" style="--fi-top:23%; --fi-size:clamp(19px,2.1vw,30px); --fi-cycle:24s; --fi-delay:5s; --fi-flap:2.1s; --fi-op:.8; --fi-bob:5.4s">
        <div class="fisaap-bird-y">
          <svg class="fisaap-bird-svg" viewBox="0 0 64 44">
            <path class="fisaap-wing fisaap-wing-l" d="M32 24 C25.5 15.5 15 10 4 13"></path>
            <path class="fisaap-wing fisaap-wing-r" d="M32 24 C38.5 15.5 49 10 60 13"></path>
          </svg>
        </div>
      </div>
    </div>
    <div class="fisaap-hero-content">
      <div class="fisaap-hero-kicker">YA ESTÁ AQUÍ</div>
      <h1 id="fisaap-h-hero">FUTURE ISLAND<br>SAAP</h1>
      <div class="fisaap-hero-sub">SOCIAL &amp; CULTURAL INTELLIGENCE<br>EN TIEMPO REAL</div>
    </div>
  </section>

  <!-- Intro + video -->
  <section class="fisaap-intro" aria-labelledby="fisaap-h-intro">
    <div class="fisaap-intro-inner">
      <div class="fisaap-kicker">NUESTRA PLATAFORMA SAAP (SOFTWARE AS A PRODUCT)</div>
      <h2 id="fisaap-h-intro" class="fisaap-serif">Descifra lo que mueve <br>a tu audiencia. <br><em>Antes que nadie.</em></h2>
      <p class="fisaap-intro-text">Future Island Saap convierte el ruido digital en claridad estratégica. <br>Analizamos la conversación social y cultural en tiempo real para que <br>anticipes tendencias, entiendas comportamientos y tomes mejores decisiones.</p>
      <div class="fisaap-video-block">
        <div class="fisaap-video">
          <iframe src="<?php echo esc_url( $video_src ); ?>" title="Future Island Saap"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
        </div>
        <div class="fisaap-video-caption">MIRA CÓMO FUNCIONA FUTURE ISLAND SAAP <span style="font-size:1.05em">↗</span></div>
      </div>
    </div>
  </section>

  <!-- Cómo funciona -->
  <section class="fisaap-steps" aria-labelledby="fisaap-h-steps">
    <div class="fisaap-steps-inner">
      <div class="fisaap-steps-head fisaap-reveal">
        <h2 id="fisaap-h-steps" class="fisaap-serif">DE LA CONVERSACIÓN <br>A LA ACCIÓN</h2>
        <div class="fisaap-steps-rule"></div>
      </div>
      <div>
        <div class="fisaap-step fisaap-reveal" style="--rd:.08s">
          <div class="fisaap-step-icon">
            <div class="fisaap-step-crop" style="aspect-ratio:808/726">
              <img src="<?php echo esc_url( $img_icons ); ?>" alt="" loading="lazy" style="transform:translateY(0%)">
            </div>
          </div>
          <div class="fisaap-step-body">
            <div class="fisaap-step-num">01</div>
            <div class="fisaap-step-title">PEOPLE TALK &amp; WE SEARCH</div>
            <p>Rastreamos millones de conversaciones, señales y contenidos en más de 17 fuentes digitales.</p>
          </div>
        </div>
        <div class="fisaap-step fisaap-reveal" style="--rd:.16s">
          <div class="fisaap-step-icon">
            <div class="fisaap-step-crop" style="aspect-ratio:808/601">
              <img src="<?php echo esc_url( $img_icons ); ?>" alt="" loading="lazy" style="transform:translateY(-37.67%)">
            </div>
          </div>
          <div class="fisaap-step-body">
            <div class="fisaap-step-num">02</div>
            <div class="fisaap-step-title">WE FIND PATTERNS &amp; SEE WHAT'S NEXT</div>
            <p>Detectamos patrones emergentes y cambios culturales para anticipar lo que viene.</p>
          </div>
        </div>
        <div class="fisaap-step fisaap-reveal" style="--rd:.24s">
          <div class="fisaap-step-icon">
            <div class="fisaap-step-crop" style="aspect-ratio:808/604">
              <img src="<?php echo esc_url( $img_icons ); ?>" alt="" loading="lazy" style="transform:translateY(-68.86%)">
            </div>
          </div>
          <div class="fisaap-step-body">
            <div class="fisaap-step-num">03</div>
            <div class="fisaap-step-title">WE MAKE THE MOVE</div>
            <p>Transformamos insights en estrategias y acciones claras para tu marca.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if ( '' !== $img_dash ) : ?>
  <!-- Producto -->
  <section class="fisaap-product" aria-labelledby="fisaap-h-product">
    <div class="fisaap-product-inner">
      <div class="fisaap-product-head">
        <div class="fisaap-product-title">
          <div class="fisaap-kicker">DENTRO DE LA PLATAFORMA</div>
          <h2 id="fisaap-h-product" class="fisaap-serif">Así se ve la inteligencia<br>en tu pantalla.</h2>
        </div>
        <div class="fisaap-product-tag">DASHBOARD · TENDENCIAS · AUDIENCIAS</div>
      </div>
      <div class="fisaap-product-frame">
        <img src="<?php echo esc_url( $img_dash ); ?>" alt="Dashboard de Future Island Saap" loading="lazy">
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Mini cita -->
  <section class="fisaap-cita" aria-labelledby="fisaap-h-cita">
    <div class="fisaap-cita-card">
      <div class="fisaap-cita-img" style="background-image:url('<?php echo esc_url( $img_cita ); ?>')"></div>
      <div class="fisaap-cita-body">
        <div class="fisaap-kicker">AGENDA TU MINI CITA CON LA ISLA</div>
        <h2 id="fisaap-h-cita" class="fisaap-serif">Te mostramos en 25 minutos cómo Future Island Saap puede darte ventaja real.</h2>
        <p>Una sesión personalizada para conocer tu caso, resolver dudas y enseñarte el poder de nuestra plataforma.</p>
        <a class="fisaap-btn" href="<?php echo esc_url( $book_url ); ?>">RESERVAR MINI CITA <span>→</span></a>
      </div>
    </div>
  </section>

  <!-- Diferenciadores -->
  <section class="fisaap-diff" aria-label="Diferenciadores">
    <div class="fisaap-diff-grid">
      <div class="fisaap-diff-cell">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#0a0908" stroke-width="1.3" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18"></path><path d="M12 3c3 3.4 3 14.2 0 18-3-3.8-3-14.6 0-18z"></path></svg>
        <div class="fisaap-diff-title">GLOBAL</div>
        <div class="fisaap-diff-rule"></div>
        <p>Disponible worldwide. Pensado para marcas y equipos globales.</p>
      </div>
      <div class="fisaap-diff-cell">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#0a0908" stroke-width="1.3" stroke-linejoin="round"><path d="M13 2 5 13.6h5.4L10 22l8.4-11.8H13z"></path></svg>
        <div class="fisaap-diff-title">EN TIEMPO REAL</div>
        <div class="fisaap-diff-rule"></div>
        <p>Datos y análisis actualizados al minuto para decisiones ágiles.</p>
      </div>
      <div class="fisaap-diff-cell">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#0a0908" stroke-width="1.3" stroke-linecap="round"><rect x="4.5" y="10.5" width="15" height="10.5" rx="1.6"></rect><path d="M8.2 10.5V7.4a3.8 3.8 0 0 1 7.6 0v3.1"></path></svg>
        <div class="fisaap-diff-title">100% ACCIONABLE</div>
        <div class="fisaap-diff-rule"></div>
        <p>Insights claros, visualizaciones simples y recomendaciones estratégicas.</p>
      </div>
      <div class="fisaap-diff-cell">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#0a0908" stroke-width="1.3" stroke-linecap="round"><circle cx="9" cy="8.6" r="3.1"></circle><circle cx="17.4" cy="9.6" r="2.3"></circle><path d="M3.3 19.4c.5-3.2 2.9-5 5.7-5s5.2 1.8 5.7 5"></path><path d="M17.2 14.6c2 .3 3.3 1.8 3.5 4"></path></svg>
        <div class="fisaap-diff-title">PARA EQUIPOS<br>QUE LIDERAN</div>
        <div class="fisaap-diff-rule"></div>
        <p>Marketing, estrategia, innovación, producto, comunicación y más.</p>
      </div>
    </div>
  </section>

  <!-- Cierre -->
  <section class="fisaap-close" aria-labelledby="fisaap-h-close">
    <div class="fisaap-close-inner">
      <h2 id="fisaap-h-close" class="fisaap-serif">MENOS SUPOSICIONES.<br>MÁS INTELIGENCIA.<br>MEJORES DECISIONES.</h2>
      <div class="fisaap-close-side">
        <div class="fisaap-close-brand">FUTURE ISLAND SAAP</div>
        <p class="fisaap-serif fisaap-close-quote">El futuro no se espera.<br>Se entiende.</p>
        <a class="fisaap-close-link" href="<?php echo esc_url( $site_url ); ?>">WWW.FUTURE-ISLAND.CLUB</a>
      </div>
    </div>
  </section>

  <!-- Demo (Calendly) — سکشن پایانی -->
  <section class="fisaap-demo" id="fisaap-demo" aria-labelledby="fisaap-h-demo">
    <div class="fisaap-demo-inner">
      <div class="fisaap-demo-head">
        <div class="fisaap-kicker">SOLICITA TU DEMO</div>
        <h2 id="fisaap-h-demo" class="fisaap-serif">Agenda tu demo en vivo.</h2>
        <p class="fisaap-demo-note">25 minutos, sin compromiso. Elige el horario que mejor te venga y te mostramos Future Island Saap aplicado a tu marca.</p>
      </div>
      <div class="fisaap-calendly">
        <!-- Calendly inline widget begin -->
        <div class="calendly-inline-widget" data-url="<?php echo esc_url( $calendly_url ); ?>" style="min-width:320px;height:700px;"></div>
        <!-- Calendly inline widget end -->
      </div>
    </div>
  </section>

  <div class="fisaap-spacer"></div>
</div>

<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js" async></script>
<script>
/* اسکرول‌ریویل سکشن «Cómo funciona» — بدون JS یا با reduced-motion همه‌چیز از اول دیده می‌شود */
(function () {
  var root = document.querySelector('.fisaap');
  if (!root || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var els = root.querySelectorAll('.fisaap-reveal');
  if (!els.length || !('IntersectionObserver' in window)) return;
  root.classList.add('fisaap-js');
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
    });
  }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
  els.forEach(function (el) { io.observe(el); });
})();
</script>
	<?php
	return ob_get_clean();
}
add_shortcode( 'future_island_saap', 'fisaap_landing_shortcode' );
