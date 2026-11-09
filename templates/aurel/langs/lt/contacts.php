<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Susisiekite su ' . SITE_NAME . ' ᐉ Esame pasiruošę padėti';
$page_description = 'Turite klausimų apie ' . SITE_NAME . ' arba savo paskyrą?';
$page_canonical = page_url("contacts.php");
$active_page = "contacts";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main id="top" itemscope itemtype="https://schema.org/ContactPage">

<section class="kj9w4x">
  <div class="ggh3sm">
    <span class="qwce6q">Susisiekti</span>
    <h1>Esame čia, kad padėtume</h1>
    <p class="kpnq92g">Turite klausimų apie <?= e(SITE_NAME) ?> arba savo paskyrą? Mūsų palaikymo komanda mielai padės. Parašykite mums ir mes atsakysime kai tik galėsime.</p>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>02</b><i></i></div>
    <h2>Prieš rašydami mums</h2>
    <p>Daugelis klausimų jau turi atsakymą svetainėje, o pirmiausia patikrinti paprastai yra greičiau nei laukti atsakymo.</p>
    <ul class="skvsaz4">
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><a href="<?= page_url('faq.php') ?>" style="color:var(--accent)">DUK</a>— išlaidos, išėmimai, patikrinimas ir minimalios sumos.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><a href="<?= page_url('product.php') ?>" style="color:var(--accent)">Kaip tai veikia</a>— kas vyksta po registracijos, žingsnis po žingsnio.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><a href="<?= page_url('pricing.php') ?>" style="color:var(--accent)">Kainodara</a>— kas yra nemokama ir kur gali atsirasti mokestis.</span></li>
    </ul>
    <div class="tw9z4by"><a class="qou73xg fi3abjs" href="<?= page_url() ?>#nlokf">Pradėti -<?= e(money_min()) ?> nuo</a></div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>01</b><i></i></div>
    <h2>Kaip su mumis susisiekti</h2>
    <div class="smnsm2" role="region" tabindex="0"><table class="wcle1">
      <thead><tr><th scope="col">Kanalas</th><th scope="col">Geriausia </th><th scope="col">Atsakymas</th></tr></thead>
      <tbody>
        <tr><td>Pagalba el. paštu –<a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a></td><td>Klausimai dėl sąskaitos, tikrinimas, išėmimai</td><td>Paprastai atsakome per vieną darbo dieną.</td></tr>
        <tr><td>Atskambinimo užklausa</td><td>Viską lengviau paaiškinti telefonu</td><td>Pagalbos valandos: nuo pirmadienio iki penktadienio, 9:00–18:00</td></tr>
        <tr><td>Pranešimas apie piktnaudžiavimą –<a href="<?= page_url('report-abuse.php') ?>" style="color:var(--accent)">/report-abuse</a></td><td>Apsimetinėjimas, piktnaudžiavimas prekės ženklu, įtartini pranešimai</td><td>Peržiūrėtas gavus</td></tr>
      </tbody>
    </table></div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm op6k5h">
    <h2>Ko tikėtis susisiekus</h2>
    <h3>Kurį kanalą naudoti</h3>
    <p>El. paštas yra tinkamas pasirinkimas bet kam su priedu: tapatybės patikrinimams, atsiėmimo užklausoms, klausimams apie pareiškimą. Atšaukimo forma skirta viskam kitam, nes dauguma paskyros klausimų išsprendžiami greičiau per dvi minutes pokalbio nei per keturias žinutes.</p>
    <h4>Ne palaikymo valandomis</h4>
    <p>Vakare ar savaitgalį išsiųsti pranešimai lieka eilėje ir į juos pirmiausia atsakoma kitą darbo dieną, tokia tvarka, kokia buvo gauta.</p>
    <h3>Detalės, kurias verta įtraukti</h3>
    <p>Norėdami rasti paskyrą, pakanka registruoto el. pašto ir apytikslės datos, apie kurią klausiate. Niekada nesiųskite slaptažodžio, viso kortelės numerio ar vienkartinio kodo: joks mūsų komandos narys niekada jūsų neprašys.</p>
    <h4>Jei kažkas atrodo ne taip</h4>
    <p>Praneškite tą pačią dieną. Viskas, kas susiję su mokėjimu, kurio neatpažįstate, tvarkoma nedelsiant, nelaukiant įprastoje eilėje.</p>
  </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
