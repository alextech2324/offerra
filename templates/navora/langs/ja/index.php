<?php
require_once __DIR__ . '/includes/config.php';
$page_title = page_title('AI market analysis and trading tools');
$page_description = SITE_NAME . ' helps you follow stocks and crypto with AI. From ' . min_deposit_label() . '. No promised return.';
$page_canonical = page_url();
$active_page = 'home';
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main class="main-wrapper">
<section class="section_header" data-w-id="f1ad8c20-62d4-a9ad-b6e4-f32b047e3882" id="top-of-page">
<div class="hero-pattern-wrapper" aria-hidden="true"></div>
<div class="padding-global padding-section-large is-hero">
<div class="container-large">
<div class="header_component">
<div class="w-layout-grid layout1_content main-flex" data-w-id="72dcf02e-30f5-ce58-ce90-539871cc80f9">
<div class="max-width-large hero-wrap">
<h1 id="heading-style-h1" class="heading-style-h1"><?= e(SITE_NAME) ?> — AI tools to follow stocks and crypto</h1>
<!-- mob-version form -->
<?php
  $form_id = "registrationForm-mob";
  $form_class = "leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form mob-form";
  $form_submit = '送信';
  require __DIR__ . '/includes/form.php';
?>

    <div class="spacer-small">
<img alt="Person reviewing market charts on a computer" class="hero-main-image" src="<?= asset('static/images/investor.webp') ?>" width="720" height="404" loading="eager" fetchpriority="high" decoding="async">
</div>
<div class="spacer-small"></div>
<p class="text-size-large">Get to know <?= e(SITE_NAME) ?>, a platform with AI tools to follow stocks and crypto. You can start from <?= e(min_deposit_label()) ?>, even without prior experience, and use up-to-date information to structure your analysis. Results are not promised.</p>
</div>

<?php
  $form_id = "registrationForm-pc";
  $form_class = "leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form form-pc";
  $form_submit = '送信';
  require __DIR__ . '/includes/form.php';
?>
</div>
</div>
</div>
</div>
</section>
<div class="section-features" id="why-invest-wrap">
<div class="padding-section-small">
<section class="section_header1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="header49_component">
<div class="w-layout-grid header49_content">
<div class="header49_content-left"></div>
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1" id="why-invest">
<h2 class="heading-style-h3" style="font-weight: bold; text-align: center; margin-bottom: 60px;">Why get to know <?= e(SITE_NAME) ?></h2>
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component main-flex">
<div class="layout1_content-left" data-w-id="2a2160c4-51d5-418c-ce07-7978ef29ff5d" style="
                          opacity: 1;
                          transform: none;
                          transform-style: preserve-3d;
                        ">
<div class="text-block-max">
<h3 class="heading-style-h4" style="font-weight: bold; text-align: left;">Organise capital with a long-term view, including the effect of inflation</h3>
<div class="spacer-small"></div>
<p class="text-size-regular" style="text-align: left;">Inflation can slowly reduce the real value of savings. Some people look at investment alternatives to try to preserve or grow wealth, but no solution guarantees stability or growth. Automation can simplify tasks; it does not remove risk.</p>
</div>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="9a914867-0092-21bf-63cc-727a4a9409fd" style="
                          opacity: 1;
                          transform: none;
                          transform-style: preserve-3d;
                        ">
<img alt="Stock analysis with digital tools" class="flex-image" loading="lazy" src="<?= asset('static/images/investor1.webp') ?>">
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component">
<div class="w-layout-grid layout1_content">
<div class="layout1_content-left is-right" data-w-id="c8d3a442-7e6b-afaa-23f7-91bebe60c241" id="w-node-23c7fcd4-4cfd-9f6c-b408-5a76c71bf848" style="
                          opacity: 1;
                          transform: none;
                          transform-style: preserve-3d;
                        ">
<h3 class="heading-style-h4" style="font-weight: bold;">Assisted analysis tools for people still building market experience</h3>
<div class="spacer-small"></div>
<p class="text-size-regular"><?= e(SITE_NAME) ?> brings together tools that help you read the markets in a more structured way. Instead of jumping between countless charts, you can review organised setups and signals. Nothing is applied in your name without the matching confirmation. The aim is to simplify analysis, not to promise results.</p>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="a990032b-f4b2-9409-b721-c086f525b6fb" style="
                          opacity: 1;
                          transform: none;
                          transform-style: preserve-3d;
                        ">
<div class="layout1_image-wrapper">
<img alt="Following investments from home" class="layout1_image feature-2 flex-image" loading="lazy" sizes="(max-width: 479px) 82vw, (max-width: 767px) 85vw, (max-width: 991px) 39vw, 38vw" src="<?= asset('static/images/investathome.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component img-text-flex">
<div class="w-layout-grid layout1_content">
<div class="layout1_content-left" data-w-id="84c3d8b1-2224-2557-e5d0-25b9f9c2d71c" style="
                          opacity: 1;
                          transform: none;
                          transform-style: preserve-3d;
                        ">
<h3 class="heading-style-h4" style="font-weight: bold;">Start from <?= e(min_deposit_label()) ?> — simple to use</h3>
<div class="spacer-small"></div>
<p class="text-size-regular">With an initial deposit from <?= e(min_deposit_label()) ?>, you can try the analysis tools on <?= e(SITE_NAME) ?>. That amount is not advice, a recommendation or a guarantee of growth. It can help you explore the interface before you decide whether to increase exposure.</p>
<div class="spacer-medium"></div>
<h3 class="heading-style-h4" style="font-weight: bold;">Clearer control over how you use the account</h3>
<div class="spacer-medium"></div>
<p class="text-size-regular">With <?= e(SITE_NAME) ?>, you keep tracking and control of your capital. You can hold funds or request a withdrawal under the applicable conditions. Usage details are shown clearly, without hidden fees presented outside the process.</p>
<div class="spacer-medium"></div>
<div class="button-group">
<a class="button w-button" href="<?= page_url('sign.php') ?>">今すぐ口座を開く</a>
</div>
<br>
</div>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="56619977-0c40-7464-843f-74b2769150b4" style="
                        opacity: 1;
                        transform: none;
                        transform-style: preserve-3d;
                      ">
<div class="layout1_image-wrapper">
<img alt="Illustration of an investment over time" class="layout1_image feature-3" loading="lazy" sizes="(max-width: 479px) 82vw, (max-width: 767px) 85vw, (max-width: 991px) 39vw, 38vw" src="<?= asset('static/images/investgrowth.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</section>
</div>
</div>

<section class="section_pricing" id="how-it-works">
<div class="padding-global padding-section-medium">
<div class="container-medium">
<div class="pricing_component">
<div class="text-align-center">
<div class="max-width-medium align-center">
<h2 class="heading-style-h2" style="font-weight: bold;">How <?= e(SITE_NAME) ?> uses AI to support investment analysis</h2> 
<div class="spacer-small"></div>
</div>
</div>
<div class="spacer-xxlarge"></div>
<div class="w-layout-grid pricing_grid-list">
<div class="gradient-wrapper" data-w-id="">
<div class="pricing_plan is-main">
<div class="pricing-header">
<div class="pricing-tag">
<h3 class="heading-style-h4" style="font-weight: bold;">Open your account in a few steps and get to know <?= e(SITE_NAME) ?></h3>
</div>
<p>After you finish registration, you get help preparing the account. Activation can complete in a few minutes, though the timing depends on the required checks.</p>
</div>
<div class="pricing-header">
<div class="pricing-tag">
<h3 class="heading-style-h4" style="font-weight: bold;">A support structure for more informed decisions</h3>
</div>
<p>On <?= e(SITE_NAME) ?>, you can set a tracking approach that matches your goals and the loss you are prepared to take. The platform does not promise automatic, regular growth; it helps you follow the rules and limits you chose.</p>
</div>
</div>
</div>
<div class="pricing_plan" data-w-id="">
<div class="pricing-header">
<h3 class="heading-style-h4" style="font-weight: bold;">Automated tracking and AI-assisted analysis</h3>
<p class="text-size-large"><?= e(SITE_NAME) ?> processes market data and highlights situations you may want to review. When automation is used, it follows rules you can inspect and change; it does not stop price swings or remove the risk of loss.</p>
</div>
<div class="pricing-header">
<h3 class="heading-style-h4" style="font-weight: bold;">More flexibility to manage capital</h3>
<p class="text-size-large">You can request a withdrawal or keep capital in the account, subject to the applicable conditions. Before you confirm, always check availability, timelines and any fees.</p>
</div>
<img alt="" class="testimonial-pattern-image" loading="lazy" src="<?= asset('static/images/testimonial-pattern.svg') ?>">
</div>
</div>
</div>
</div>
</div>
</section>
<div class="section-features" id="risk-controls">
<div class="padding-section-small">
<section class="section_header1" id="minimization">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="header49_component">
<div class="">
<div class="header49_content-left">
<h2 class="heading-style-h2" style="font-weight: bold; text-align: center;"><?= e(SITE_NAME) ?> tools to track and control risk</h2> 
</div>
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component">
<div class="w-layout-grid layout1_content img-flex-item">
<div class="layout-component" data-w-id="2a2160c4-51d5-418c-ce07-7978ef29ff5d" style="
                  max-width: max-content !important;
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<h3 class="heading-style-h4" style="font-weight: bold;">Adaptive AI analysis of the markets</h3>
<div class="spacer-small"></div>
<p class="text-size-regular"><?= e(SITE_NAME) ?> brings together adaptive algorithms that continuously follow market signals: prices, volume, news, social trends, macro indicators and technical metrics. The AI highlights patterns for analysis, outlines possible scenarios and helps flag periods of higher exposure, without being driven by emotional decisions. Parameters adjust to market pace to support a disciplined view of risk and opportunity — without guaranteeing results.</p>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="9a914867-0092-21bf-63cc-727a4a9409fd" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<div class="layout1_image-wrapper">
<img alt="Market analysis screen" class="layout1_image feature-1" loading="lazy" width="1024" height="1024" src="<?= asset('static/images/Kiintell.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component">
<div class="w-layout-grid layout1_content">
<div class="layout1_content-left is-right" data-w-id="c8d3a442-7e6b-afaa-23f7-91bebe60c241" id="w-node-risk-clarity" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<h3 class="heading-style-h4" style="font-weight: bold;">Risk parameters you can match to your priorities</h3>
<div class="spacer-small"></div>
<p class="text-size-regular">Priorities differ from person to person: some users want more stability, others accept larger swings. On <?= e(SITE_NAME) ?> you can set your risk profile, from a cautious approach to a balanced or more dynamic one. You can also adjust position size, stop-loss, take-profit and how often you enter or exit. That way the setup sits closer to your goals and the exposure you are prepared to accept.</p>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="a990032b-f4b2-9409-b721-c086f525b6fb" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<div class="layout1_image-wrapper">
<img alt="Price chart" class="layout1_image feature-2" loading="lazy" width="1024" height="1024" sizes="(max-width: 479px) 82vw, (max-width: 767px) 85vw, (max-width: 991px) 39vw, 38vw" src="<?= asset('static/images/charts.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component">
<div class="w-layout-grid layout1_content">
<div class="layout1_content-left" data-w-id="84c3d8b1-2224-2557-e5d0-25b9f9c2d71c" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<h3 class="heading-style-h4" style="font-weight: bold;">Clear information on the account and capital</h3>
<div class="spacer-small"></div>
<p class="text-size-regular">On <?= e(SITE_NAME) ?>, account activity is shown clearly. Buys, sells, changes and costs stay recorded in detail and update in real time. The dashboard shows balance, history, charts, indicators and risk positions, keeping the relevant items visible. The decision stays with you: the platform supports the process, it does not replace your judgement.</p>
<div class="spacer-medium"></div>
<div class="button-group">
<a class="button w-button" href="<?= page_url('sign.php') ?>">今すぐ口座を開く</a>
</div>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="56619977-0c40-7464-843f-74b2769150b4" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<div class="layout1_image-wrapper">
<img alt="User reviewing an investment account" class="layout1_image feature-3" loading="lazy" sizes="(max-width: 479px) 82vw, (max-width: 767px) 85vw, (max-width: 991px) 39vw, 38vw" src="<?= asset('static/images/happyinvestor.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</div>
</section>
</div>
</div>
<div class="section-features" id="benefits-wrap">
<div class="padding-section-small"><section class="section_header1" id="Beneficios">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="header49_component">
<div class="">
<div class="header49_content-left">
<h2 class="heading-style-h2" style="font-weight: bold; text-align: center;">What <?= e(SITE_NAME) ?> provides — more structured, responsible tracking</h2>
</div>
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component">
<div class="w-layout-grid layout1_content">
<div class="layout1_content-left" data-w-id="7861ebe2-0a40-a895-b575-5d598576d4fa" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<h3 class="heading-style-h4" style="font-weight: bold;">AI support to read the market with more clarity</h3>
<div class="spacer-small"></div>
<p class="text-size-regular">You do not need to watch charts all day. The AI on <?= e(SITE_NAME) ?> gathers prices, volumes and relevant signals so the context is easier to read. It cannot predict the future with certainty and it does not decide for you. Even with technical support, trading can lose money: set your limits and decide whether and when to go ahead.</p>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="e32b15ff-9b23-9d4e-02c6-908bb65a9541" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<div class="layout1_image-wrapper">
<img alt="Financial chart on the analysis dashboard" class="layout1_image feature-1" loading="lazy" width="1024" height="1024" src="<?= asset('static/images/charts2.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component">
<div class="w-layout-grid layout1_content">
<div class="layout1_content-left is-right" data-w-id="6707cc12-56ed-d80c-79b5-e1a2c78b3a76" id="w-node-03b1e5d4-0c28-9009-361c-0301dafb753f" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<h3 class="heading-style-h4" style="font-weight: bold;">An accessible starting point with simple tools and practical guidance</h3>
<div class="spacer-small"></div>
<p class="text-size-regular"><?= e(SITE_NAME) ?> was built for people with different levels of experience and resources. The interface structures the path with clear steps, accessible options and direct explanations. After you understand the applicable conditions, a beginner can try an automated setup in about 10–15 minutes. Videos, guides and support help you use the available features.</p>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="c49c59bf-ad88-2aa7-4649-79cf495dfad3" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<div class="layout1_image-wrapper">
<img alt="Investor analysing the market" class="layout1_image feature-2" loading="lazy" sizes="(max-width: 479px) 82vw, (max-width: 767px) 85vw, (max-width: 991px) 39vw, 38vw" src="<?= asset('static/images/charts3.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<section class="section_layout1">
<div class="padding-global padding-section-small">
<div class="container-medium">
<div class="layout1_component">
<div class="w-layout-grid layout1_content">
<div class="layout1_content-left" data-w-id="6a979576-837f-8a7b-1a04-ddd9ba22ba22" style="
                      opacity: 1;
                      transform: none;
                      transform-style: preserve-3d;
                    ">
<h3 class="heading-style-h4" style="font-weight: bold;">Dedicated support while you use the platform</h3>
<div class="spacer-small"></div>
<p class="text-size-regular">After the first deposit, you may get an individual support contact, depending on the service terms. That contact can show the features, help you read the initial settings and answer usage questions, without choosing your profile or placing decisions for you.</p>
<div class="spacer-medium"></div>
<h3 class="heading-style-h4" style="font-weight: bold;">Follow the markets at your pace, without artificial expectations</h3>
<div class="spacer-medium"></div>
<p class="text-size-regular">With <?= e(SITE_NAME) ?> you can follow several markets in one place, from anywhere. That does not mean capital moves automatically or that growth is guaranteed. Check the balance, withdrawal terms and the limits you set. Because trading can result in losses, use only an amount that fits your financial situation.</p>
</div>
<div class="layout1-image-gradient-wrapper" data-w-id="ce588eda-85ca-ff61-6af7-7e16d4980d77">
<div class="layout1_image-wrapper">
<img alt="People following the markets together" class="layout1_image feature-3" loading="lazy" sizes="(max-width: 479px) 82vw, (max-width: 767px) 85vw, (max-width: 991px) 39vw, 38vw" src="<?= asset('static/images/happyinvestors.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</div>
</section>
</div>
</div>



<section class="cv2-review" id="brand-review" aria-labelledby="brand-review-heading">
  <div class="cv2-review__inner">
        <p class="cv2-review__badge">Read this information before opening an account</p>
            <h2 class="cv2-review__title" id="brand-review-heading"><?= e(SITE_NAME) ?>: how it works and the limits to know</h2>
            <p class="cv2-review__lead"><?= e(SITE_NAME) ?> is a commercial platform with trading tools and AI-assisted analysis for people in <?= e(geo_country_name()) ?> who want to follow several markets in one place. This page explains the purpose of the service, the first steps, the risks and what to watch for if fraud is a concern. The aim is to clarify the path before registration, not to promise a return.</p>
    
        <article class="cv2-review__block" id="brand-review-what">
      <h3 class="cv2-review__h3">What does <?= e(SITE_NAME) ?> provide and who is it for?</h3>
      <p><?= e(SITE_NAME) ?> offers a central dashboard, charts, market data and tools that support analysis. After you open an account and, where applicable, make the stated minimum deposit, you can view the screens, set alerts and choose risk preferences. It is not a bank, it does not replace an authorised investment adviser and it does not run as an autonomous system without you. The content presents tools for market access, not personal advice. You remain responsible for the funds involved and for the orders you confirm.</p>      <p>The brand name appears in the title, footer and legal sections so you can confirm you are on the right domain, not a copy. The interface can be used by beginners and by people with experience: start with the basics and move to extra features when they help. The instruments actually available — including stocks, forex or crypto — should be confirmed in the account, not inferred from a sales line. For how-to questions, use the Contact page; it does not give buy recommendations.</p>    </article>
        <article class="cv2-review__block" id="brand-review-how">
      <h3 class="cv2-review__h3">How can you use <?= e(SITE_NAME) ?> day to day?</h3>
      <p>In general you start by creating the account, validating access, exploring the dashboard and, if you decide to continue, adding the stated minimum deposit. Those earlier steps describe the path without sales shortcuts. The AI gathers quotes, volumes and signals to support research, but it does not decide for you. Preferences, limits and confirmations stay under your control. Withdrawals follow the terms shown before you confirm, and timelines can depend on the bank or payment network.</p>      <p>In daily use you review the selected markets, assess the context and act only if the decision fits your horizon. There are no risk-free sessions and no calendars of gains. If the market pace does not fit your plan, you can choose not to trade. Where available, balance, history and security controls stay accessible so you can check activity. It is a tracking tool, not a promise of results.</p>    </article>
        <article class="cv2-review__block" id="brand-review-legit">
      <h3 class="cv2-review__h3">Is <?= e(SITE_NAME) ?> a scam, or does it carry commercial risks?</h3>
      <p>The question “Is <?= e(SITE_NAME) ?> a scam?” often appears when a new brand shows AI features. Fraud schemes usually press you to send money without protection, promise fixed gains or block withdrawals while asking for extra payments. <?= e(SITE_NAME) ?> does not promote guaranteed returns or a calendar of gains, and it asks you to check deposits, limits and withdrawals before you confirm. That does not prove a service is problem-free; it only helps you review the terms. The core risk is still the markets: even with a clear interface, you can lose some or all of your capital.</p>      <p>Judge any financial service with similar questions: does the address match the brand, are the terms available, and does support explain the process without telling you which trade to place? A promise of a fixed amount per day does not prove safety, even when the site looks professional. On <?= e(SITE_NAME) ?>, support answers account, document and timeline questions, but it does not give personal investment advice. If something is unclear, do not open an account or send funds. This page is also not a legal certificate that “this is not a scam”.</p>    </article>
        <article class="cv2-review__block" id="brand-review-geo">
      <h3 class="cv2-review__h3">What is the context in <?= e(geo_country_name()) ?> — and what <?= e(SITE_NAME) ?> is not?</h3>
      <p>In <?= e(geo_country_name()) ?>, the local financial authority takes part in supervising markets and certain investment activities. <?= e(SITE_NAME) ?> does not claim authorisation, bank status or official validation for this content. Mentioning a regulator only sets the country context; it is not a trust badge. Before you deposit, confirm the site, the legal details and that you understand the service.</p>      <p>The interface and support are aimed at users in this page language. Bank timelines, requested documents and payment methods can vary by account. This page does not replace legal or tax advice. Trading and investing can produce losses; risk only an amount you can bear. Read Terms and Privacy for the details instead of relying on a sales summary.</p>    </article>
    
        <article class="cv2-review__block" id="brand-review-who">
      <h3 class="cv2-review__h3">Who <?= e(SITE_NAME) ?> may suit — and when to look elsewhere</h3>
      <div class="cv2-review__who">
                <div class="cv2-review__who-card">
          <h4>For people discovering the markets who prefer guidance along the way</h4>
          <p>You want to understand the interface, the minimum deposit and the limits before increasing exposure. <?= e(SITE_NAME) ?> favours a clear start, not an immediate speculative answer. Read the guides carefully and do not copy gain examples shown on other pages.</p>
        </div>
                <div class="cv2-review__who-card">
          <h4>For people with little time who want tracking in one place</h4>
          <p>You already follow some assets and want to stop switching between apps. The dashboard and alerts gather the context in one place, but they do not replace your own plan or a cash buffer.</p>
        </div>
                <div class="cv2-review__who-card">
          <h4>For people who already trade and want more structured analysis</h4>
          <p>You already know the risks and want lists, dashboards and summaries that make research more organised. AI features are helper tools to select, filter and record information. If you want a system that pays out regular amounts, that is not what this platform is for.</p>
        </div>
              </div>
    </article>
    
        <article class="cv2-review__block" id="brand-review-table">
      <h3 class="cv2-review__h3">What <?= e(SITE_NAME) ?> can provide, without promising returns</h3>
            <p>A summary of features the platform highlights. Each line describes a possible use, not a financial result. Confirm in your account which resources are actually available.</p>
            <dl class="cv2-review__table">
                <div class="cv2-review__row">
          <dt>Unified dashboard and tracked markets</dt>
          <dd>A central place to check balance, available instruments and shortcuts to charts or lists, without constant switching between screens.</dd>
        </div>
                <div class="cv2-review__row">
          <dt>Data analysis with artificial-intelligence assistance</dt>
          <dd>Summaries and flagged patterns for you to review later. The AI does not forecast results and does not replace the risk rules you set.</dd>
        </div>
                <div class="cv2-review__row">
          <dt>Alerts and levels you choose</dt>
          <dd>Get notifications when the conditions you selected change. Too many alerts can make the picture harder to read, so keep only those that support your plan.</dd>
        </div>
                <div class="cv2-review__row">
          <dt>Preferences and exposure limits</dt>
          <dd>Notes on position size and the limits you configured. They help keep discipline, but they cannot stop every market-driven loss.</dd>
        </div>
                <div class="cv2-review__row">
          <dt>Stated minimum amount and deposit methods</dt>
          <dd>The starting amount, from <?= e(min_deposit_label()) ?>, unlocks certain functions; it is not a recommendation. Confirm amounts, possible fees and processing times first.</dd>
        </div>
                <div class="cv2-review__row">
          <dt>Withdrawals and balance tracking</dt>
          <dd>Requests are handled under the account rules. The platform timeline can add bank or network times. A clear withdrawal process does not remove the risk of positions that stay open.</dd>
        </div>
                <div class="cv2-review__row">
          <dt>Session security and access checks</dt>
          <dd>A protected connection and verification steps, where available. These safeguards help protect access, but they do not guarantee the value of positions.</dd>
        </div>
                <div class="cv2-review__row">
          <dt>Operational help for the account</dt>
          <dd>Help with documents, timelines and how to use the screens. It does not cover personal advice or validation of a speculative trade.</dd>
        </div>
              </dl>
    </article>
    
        <p class="cv2-review__disclaimer">Warning: trading financial instruments and digital assets can result in partial or total loss of capital. <?= e(SITE_NAME) ?> provides information, tools and access, not individual advice or a promised return. Read the terms, do not treat gain examples as a benchmark and risk only an amount whose loss you could bear.</p>
      </div>
</section>

<section class="section_testimonial" id="testimonials">
<div class="review-pattern-top-wrapper">
<img alt="" class="review-pattern-image" loading="lazy" src="<?= asset('static/images/review-top-pattern.svg') ?>">
</div>
<div class="review-pattern-bottom-wrapper">
<img alt="" class="review-pattern-image" loading="lazy" src="<?= asset('static/images/review-bottom-pattern.svg') ?>">
</div>
<div class="padding-global padding-section-large">
<div class="container-medium">
<div class="testimonial_component">
<div class="text-align-center">
<div class="max-width-medium align-center">
<h2 class="heading-style-h2" style="font-weight: bold;">User reports</h2>
<div class="spacer-small"></div>
</div>
</div>
<div class="spacer-xxlarge"></div>
<div class="testimonial18_list">
<div class="testimonial18_card">
<div class="testimonial18_rating-wrapper" role="img" aria-label="Illustrative 5 out of 5 rating">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
</div>
<h3 class="text-size-medium">Explore the platform from <?= e(min_deposit_label()) ?></h3>
<p class="text-size-regular">I started with no knowledge of this topic. Opening the account and exploring the first <?= e(SITE_NAME) ?> screens was simple, with no pressure to raise the deposited amount. I prefer to move at my own pace and check the limits before any trade.</p>
<div class="text-size-small">
<strong>Marta Ferreira</strong>
</div>
<img alt="" class="testimonial-pattern-image" loading="lazy" src="<?= asset('static/images/testimonial-pattern.svg') ?>">
</div>
<div class="testimonial18_card">
<div class="testimonial18_rating-wrapper" role="img" aria-label="Illustrative 5 out of 5 rating">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
</div>
<h3 class="text-size-medium">A guided start for beginners</h3>
<p class="text-size-regular">Starting on <?= e(SITE_NAME) ?> was calm. Support showed me how to read the dashboard, without telling me an investment strategy. I stay cautious, because markets move and results are not guaranteed.</p>
<div class="text-size-small">
<strong>Carolina Rodrigues</strong>
</div>
<img alt="" class="testimonial-pattern-image" loading="lazy" src="<?= asset('static/images/testimonial-pattern.svg') ?>">
</div>
<div class="testimonial18_card">
<div class="testimonial18_rating-wrapper" role="img" aria-label="Illustrative 5 out of 5 rating">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
</div>
<h3 class="text-size-medium">Capital and decisions stay with you</h3>
<p class="text-size-regular">I used to track my funds in a messy way; now I use <?= e(SITE_NAME) ?> to watch the markets with more method. Even so, any performance stays unpredictable.</p>
<div class="text-size-small">
<strong>João Teixeira</strong>
</div>
<img alt="" class="testimonial-pattern-image" loading="lazy" src="<?= asset('static/images/testimonial-pattern.svg') ?>">
</div>
<div class="testimonial18_card">
<div class="testimonial18_rating-wrapper" role="img" aria-label="Illustrative 5 out of 5 rating">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
<img alt="" class="testimonial18_rating-icon" src="<?= asset('static/images/star.svg') ?>" width="20" height="20">
</div>
<h3 class="text-size-medium">Follow the markets on the move</h3>
<p class="text-size-regular">I wanted to check the dashboard while travelling. The mobile version of <?= e(SITE_NAME) ?> is intuitive, but I still decide whether to go ahead, without relying on promised returns.</p>
<div class="text-size-small">
<strong>Sofia Pereira</strong>
</div>
<img alt="" class="testimonial-pattern-image" loading="lazy" src="<?= asset('static/images/testimonial-pattern.svg') ?>">
</div>
</div>
</div>
</div>
</div>
</section>

<div class="lx-agg-strip" role="note">
  <div class="lx-agg-inner">
    <div class="lx-agg-body">
      <div class="lx-agg-left">
        <span class="lx-agg-badge">Information note</span>
        <h3 class="lx-agg-h3">Usage reports, not an official rating</h3>
      </div>
      <p class="lx-agg-meta">The texts above describe ways of using the platform. They are not a verified average or a financial result.</p>
    </div>
  </div>
</div>

<section aria-labelledby="payments-heading">
<div class="padding-global padding-section-medium">
<div class="container-medium">
<div class="text-align-center">
<h2 class="heading-style-h2" id="payments-heading" style="font-weight: bold;">Payments and markets</h2>
<div class="spacer-medium"></div>
<div class="partners-grid">
<div class="partner-logo">
<img alt="Binance" class="partner-image" width="128" height="128" src="<?= asset('static/images/binance.webp') ?>">
</div>
<div class="partner-logo">
<img alt="PayPal" class="partner-image" width="256" height="68" src="<?= asset('static/images/PayPal.svg.webp') ?>">
</div>
<div class="partner-logo">
<img alt="Visa" class="partner-image" width="768" height="768" src="<?= asset('static/images/visa.png') ?>">
</div>
<div class="partner-logo">
<img alt="Coinbase" class="partner-image" width="128" height="128" src="<?= asset('static/images/coinbase.webp') ?>">
</div>
</div>
</div>
</div>
</div>
</section>
<style>
      .partners-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin: 40px auto;
        max-width: 960px;
        padding: 0 15px;
      }

      .partner-logo img {
        height: 64px;
        width: auto;
        max-width: 140px;
      }

      .partner-logo {
        background: rgb(255, 255, 255);
        border-radius: 35px;
        padding: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.3s ease;
      }

      .partner-logo:hover {
        transform: translateY(-5px);
      }

      .partner-image {
        width: 100%;
        max-width: 250px;
        height: auto;
        object-fit: contain;
      }

      @media (max-width: 991px) {
        .partners-grid {
          grid-template-columns: repeat(2, 1fr);
        }
      }

      @media (max-width: 767px) {
        .partners-grid {
          grid-template-columns: repeat(2, 1fr);
        }
      }

      @media (max-width: 479px) {
        .partners-grid {
          grid-template-columns: 1fr;
        }

        .partner-logo {
          padding: 15px;
        }
      }
    </style>
<section class="section_faq" id="faq">
<div class="padding-global padding-section-medium">
<div class="container-medium">
<div class="faq_component">
<div class="text-align-center">
<div class="max-width-large align-center">
<h2 class="heading-style-h3" style="font-weight: bold;">Clear answers to the most common questions</h2>
</div>
</div>
<div class="spacer-xxlarge"></div>
<div class="faq_list" id="faq_list">
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            How much do I need to start on <?= e(SITE_NAME) ?>?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>You can start with an amount from <?= e(min_deposit_label()) ?>. Many users prefer to test cautiously first, explore the tools and match usage to their goals and risk tolerance. The stated minimum is not an investment recommendation: use only funds that fit your financial situation. Markets can move and results are not assured.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            How long does a withdrawal usually take on <?= e(SITE_NAME) ?>?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>As a rule, withdrawal requests are processed by the platform within 24 hours. The bank or selected method may take another 1 to 3 business days to make funds available. Before you confirm, check timelines, any fees and the applicable checks. Support can clarify the status of the withdrawal.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            How does <?= e(SITE_NAME) ?> protect my data and account?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>As a digital service, <?= e(SITE_NAME) ?> combines several protections, such as encryption, account checks and regular monitoring of technical risks. Where that option is available, you can also set extra controls in your profile. These measures strengthen access and operations security, but they do not stop losses caused by market swings.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            Do I need experience to use <?= e(SITE_NAME) ?>?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>No. <?= e(SITE_NAME) ?> was designed so new users can start without an excessive learning curve. The screen guides setup and use, while adjustable options allow more controlled tracking. A simple interface does not remove the risks: know the tools and their limits before you trade.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            Does <?= e(SITE_NAME) ?> promise a return?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>No. <?= e(SITE_NAME) ?> provides trading and analysis resources with AI support to make market tracking easier, but it does not guarantee returns, daily profits or predetermined results. Performance can vary with market conditions, the settings you choose and the capital used. Consider the chance of losing money before you deposit or open a position.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            Which markets can I follow through <?= e(SITE_NAME) ?>?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>Depending on availability, <?= e(SITE_NAME) ?> may show information on stocks, forex and crypto, together with related analysis panels. The instrument list can differ between accounts, so check the list in your own dashboard. The data supports analysis and does not replace your own assessment.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            How do I start working with <?= e(SITE_NAME) ?>?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>After you create the account and make the stated minimum deposit, open the dashboard to explore charts, markets and analysis tools. Then set your preferences and risk limits, moving at your own pace. <?= e(SITE_NAME) ?> presents an organised path, without pushing rushed decisions. For operational questions, use customer support.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            How can I get help for my <?= e(SITE_NAME) ?> account?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p><?= e(SITE_NAME) ?> support is available for account, deposit, withdrawal and screen-navigation questions. You can send the request through the Contact page. The team explains the applicable procedures, but it does not give personal investment advice or promise results.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            Is <?= e(SITE_NAME) ?> a scam?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>It is a fair question when a platform combines forms with AI features. <?= e(SITE_NAME) ?> provides tools to watch the markets, not a promised return or a risk-free solution. Blocked deposits, guaranteed profits or extra charges to release a withdrawal are warning signs. Deposit and withdrawal processes should be explained before you confirm. This information is not a legal certificate and capital remains subject to loss. If you are not sure, do not open an account.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            Is <?= e(SITE_NAME) ?> linked to a regulated entity?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>Local financial authorities take part in supervising markets and various participants. <?= e(SITE_NAME) ?> does not claim authorisation, bank status or an investment-advice licence presented as official validation. Mentioning a regulator only sets context for residents and does not show that the service was approved. Read the domain’s legal information, confirm the site address and do not treat promotional material as proof of authorisation.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    
      <div class="faq_accordion">
        <div class="faq_question">
          <h3 class="text-size-large text-weight-semibold" style="font-weight: bold;">
            Can I use <?= e(SITE_NAME) ?> if I live in <?= e(geo_country_name()) ?>?
          </h3>
          <div class="faq1_icon-wrapper">
            <img src="<?= asset('static/images/dropdown.svg') ?>" loading="lazy" alt="" class="icon-1x1-medium">
          </div>
        </div>
        <div class="faq_answer">
          <p>The interface and support were built for users of this language, including guidance on accounts, deposits and withdrawals. Eligibility, required documents and available payment methods are checked during registration. Living in <?= e(geo_country_name()) ?> does not remove market risk or the need to read the terms. If you do not meet the access criteria, do not continue or send funds.</p>
          <div class="spacer-small"></div>
        </div>
      </div>
    </div>
</div>
</div>
</div>
</section>
<section class="section_cta">
<div class="padding-global padding-section-medium">
<div class="container-large">
<div class="gradient-wrapper">
<div class="cta_component" data-w-id="">
<div class="cta_card">
<div class="cta_card-content">
<div class="max-width-large">
<div class="cta-content-wrapper">
<h2 class="heading-style-h2">メッセージを送る</h2>

<?php
  $form_id = "registrationForm-cta";
  $form_class = "leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form";
  $form_submit = '送信';
  require __DIR__ . '/includes/form.php';
?>
</div>
</div>
</div>
<img alt="" class="cta_background-image" loading="lazy" src="<?= asset('static/images/pattern-background.svg') ?>">
</div>
</div>
</div>
</div>
</div>
</section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
