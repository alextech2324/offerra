<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Tinklaraštis ' . SITE_NAME;
$page_description = 'Kas keičiasi taisyklėse ir ką tai reiškia jums – ' . SITE_NAME;
$page_canonical = page_url("blog.php");
$active_page = "blog";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main id="top">

<section class="kj9w4x">
  <div class="ggh3sm">
    <span class="vd7z9k">Pastabos</span>
    <h1>Kas keičiasi taisyklėse ir ką tai reiškia jums</h1>
    <p class="kpnq92g">Trumpi, praktiški straipsniai apie taisykles, kurios turi įtakos mažmeniniams investuotojams jūsų rinkoje: jokio teisinio žargono, jokio ažiotažo.</p>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <ul class="cngnn7" itemscope itemtype="https://schema.org/Blog">
      <li class="blxco" itemprop="blogPost" itemscope itemtype="https://schema.org/BlogPosting">
        <span itemprop="author" itemscope itemtype="https://schema.org/Organization" hidden><meta itemprop="name" content="<?= e(SITE_NAME) ?>"/></span>
        <meta itemprop="datePublished" content="2026-01-15"/>
        <h2 itemprop="headline"><a href="/blog-1" itemprop="url"><?= e(SITE_NAME) ?>2026 m. apžvalga: ką naujosios kriptovaliutų taisyklės reiškia mažmeniniams investuotojams</a></h2>
        <p itemprop="description">Reguliuotojas griežtina kriptovaliutų paslaugų teikimą mažmeniniams klientams. Čia yra paprasta angliška versija ir svarbios datos.</p>
        <a class="hwtx8q" href="/blog-1">Perskaitykite pastabą →</a>
      </li>
      <li class="blxco" itemprop="blogPost" itemscope itemtype="https://schema.org/BlogPosting">
        <span itemprop="author" itemscope itemtype="https://schema.org/Organization" hidden><meta itemprop="name" content="<?= e(SITE_NAME) ?>"/></span>
        <meta itemprop="datePublished" content="2026-01-15"/>
        <h2 itemprop="headline"><a href="/blog-2" itemprop="url">Kaip įvertinti investavimo platformą prieš dedant indėlį</a></h2>
        <p itemprop="description">Penki patikrinimai, kurie užtrunka dešimt minučių ir pasako daugiau nei bet kuri apžvalgų svetainė.</p>
        <a class="hwtx8q" href="/blog-2">Perskaitykite pastabą →</a>
      </li>
      <li class="blxco" itemprop="blogPost" itemscope itemtype="https://schema.org/BlogPosting">
        <span itemprop="author" itemscope itemtype="https://schema.org/Organization" hidden><meta itemprop="name" content="<?= e(SITE_NAME) ?>"/></span>
        <meta itemprop="datePublished" content="2026-01-15"/>
        <h2 itemprop="headline"><a href="/blog-3" itemprop="url">Kodėl jūsų pirmasis indėlis <?= e(SITE_NAME) ?> jūsų rinkoje turėtų būti mažesnis, nei manote</a></h2>
        <p itemprop="description">Pigiausias būdas sužinoti, kaip veikia platforma, yra mažai dirbti su ja.</p>
        <a class="hwtx8q" href="/blog-3">Perskaitykite pastabą →</a>
      </li>
    </ul>
    <div class="tw9z4by">
      <a class="qou73xg fi3abjs" href="<?= page_url() ?>#nlokf">Pradėti -<?= e(money_min()) ?> nuo</a>
      <a class="qou73xg ec2hno" href="<?= page_url('faq.php') ?>">DUK</a>
    </div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm op6k5h">
    <h2>Kaip perskaityti toliau pateiktas pastabas</h2>
    <h3>Parašyta pradedantiesiems</h3>
    <p>Kiekviena pastaba čia daro prielaidą, kad neturite išankstinio išsilavinimo rinkose. Kai termino išvengti nepavyksta, jis paaiškinamas pirmą kartą, kai jis pasirodo, o kai taisyklė skiriasi pagal šalį, tai nurodoma, o ne praleidžiama.</p>
    <h4>Ko nerasi</h4>
    <p>Jokių kainų prognozių ir jokių signalų. Viskas, kas pateikiama kaip garantuota grąža, yra aiškiausias įspėjamasis ženklas šioje pramonės šakoje, ir mes neketiname pridėti dar vieno.</p>
    <h3>Kaip dažnai tai atnaujinama</h3>
    <p>Obligacijos peržiūrimos, kai pasikeičia pagrindinės taisyklės: naujas reglamentas, naujas ataskaitų teikimo reikalavimas, keičiasi indėlių tvarkymo tvarka. Ant kiekvieno užrašo esanti data yra paskutinės peržiūros data, o ne pirmojo parašymo data.</p>
    <h4>Pasiūlykite temą</h4>
    <p>Jei turite klausimų, į kuriuos pastabos neatsako, atsiųskite jį per kontaktų puslapį; pasikartojantys klausimai dažniausiai tampa sekančia pastaba.</p>
  </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
