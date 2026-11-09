<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Registracija ' . SITE_NAME;
$page_description = 'Atidarykite paskyrą su ' . SITE_NAME;
$page_canonical = page_url("sign.php");
$active_page = "sign";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main id="top" itemscope itemtype="https://schema.org/WebPage">

<section class="kj9w4x">
  <div class="ggh3sm">
    <span class="qwce6q">Pradėkite dabar</span>
    <h1>Atidarykite paskyrą</h1>
    <p class="kpnq92g">Pradėti reikia kelių detalių, o tada specialistas tai paims. Šiame žingsnyje mokėjimas nėra imamas.</p>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>02</b><i></i></div>
    <h2>Kas bus toliau</h2>
    <ol class="nxlk2qu">
      <li><h3>Jūs siunčiate formą</h3><p>Tai trunka keletą minučių ir nieko nekainuoja.</p></li>
      <li><h3>Skambina specialistas</h3><p>Jie patvirtina jūsų duomenis, atsako į klausimus ir paaiškina kitą veiksmą. Nėra spaudimo deponuoti.</p></li>
      <li><h3>Jūs patvirtinate ir pasirenkate sumą</h3><p>Tik tada juda bet kokie pinigai ir tik jūsų pasirinkta suma.</p></li>
    </ol>
    <p class="jkkyl">Investavimas yra susijęs su rizika, įskaitant galimą dalies arba viso investuoto kapitalo praradimą. Investicijų vertė gali tiek mažėti, tiek kilti, ir jūs galite atgauti mažiau, nei iš pradžių įdėjote. Neturėtumėte investuoti pinigų, kurių negalite sau leisti prarasti.</p>
    <div class="tw9z4by">
      <a class="qou73xg fi3abjs" href="<?= page_url() ?>#nlokf">Pradėti -<?= e(money_min()) ?> nuo</a>
      <a class="qou73xg ec2hno" href="<?= page_url('faq.php') ?>">DUK</a>
    </div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>01</b><i></i></div>
    <h2>Ko tau reikia</h2>
    <ul class="skvsaz4">
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Pašto adresas, kurį iš tikrųjų perskaitėte.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Telefono numeris, kad specialistas galėtų su jumis susisiekti.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Asmens tapatybės dokumentas, vėliau patikrinimo veiksmui.</span></li>
    </ul>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm op6k5h">
    <h2>Kas atsitiks išsiuntus formą</h2>
    <h3>Patvirtinimo skambutis</h3>
    <p>Specialistas paskambina, kad patvirtintų jūsų pateiktus duomenis, atsakytų į klausimus ir susitartų, kaip jums atrodo protinga pradinė suma. Skambutis yra pokalbis, o ne pardavimo scenarijus: sąskaita, atidaryta remiantis nerealiais lūkesčiais, niekam nenaudinga.</p>
    <h4>Kiek laiko užtrunka</h4>
    <p>Paprastai vienas skambutis trunka nuo dešimties iki penkiolikos minučių. Jei norėtumėte, kad jums paskambintų konkrečiu laiku, pasakykite tai formoje ir tas laikas bus gerbiamas.</p>
    <h3>Paaiškino tapatybės patikrinimus</h3>
    <p>Prieš atliekant pirmąjį įnašą jūsų bus paprašyta pateikti asmens dokumentą su nuotrauka ir naujausią dokumentą, kuriame būtų nurodytas jūsų adresas. Tai yra tas pats reikalavimas, kurį turi bet kuri reguliuojama finansinė paslauga, ir jis egzistuoja todėl, kad atšaukimas gali sugrįžti tik jums.</p>
    <h4>Kas priimta</h4>
    <p>Pasas arba nacionalinė asmens tapatybės kortelė ir komunalinių paslaugų sąskaita arba banko išrašas, išduotas per pastaruosius tris mėnesius. Puiki nuotrauka daryta telefonu.</p>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="hu2v3" id="nl3qm8">
      <h2>Atidarykite paskyrą</h2>
      <p class="pt6joj">Pradėkite po kelių minučių.</p>
<?php
  $form_id = 'sign-form';
  $form_submit = 'Pradėkite dabar';
  $form_class = 'leadform lead-form aurel-form';
  $form_variant = 'band';
  require __DIR__ . '/includes/form.php';
?>
    </div>
  </div>
</section>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
