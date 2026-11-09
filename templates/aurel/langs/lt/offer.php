<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Kodėl mes ' . SITE_NAME;
$page_description = 'Kodėl žmonės pasirenka pradėti nuo ' . SITE_NAME;
$page_canonical = page_url("offer.php");
$active_page = "offer";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main id="top" itemscope itemtype="https://schema.org/WebPage">

<section class="kj9w4x">
  <div class="ggh3sm">
    <span class="qwce6q">Kodėl ši platforma</span>
    <h1>Kodėl žmonės pasirenka pradėti čia</h1>
    <p class="kpnq92g">Ne pardavimo pasiūlymas: konkrečios, patikrinamos priežastys, taip pat dalys, kurios tiks ne visiems.</p>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>02</b><i></i></div>
    <h2>Nėra skaičiuoklių. Jokių netvarkingų ekranų. Paskutinės minutės jokių abejonių</h2>
    <div class="smnsm2" role="region" tabindex="0"><table class="wcle1">
      <thead><tr><th scope="col">Platforma</th><th scope="col"><?= e(SITE_NAME) ?></th><th scope="col">Tradicinis brokeris</th><th scope="col">Prekyba savarankiškai</th></tr></thead>
      <tbody>
        <tr><td>AI užsakymo vykdymas</td><td style="color:var(--pos)">✓</td><td style="color:var(--muted)">ribotos valandos</td><td style="color:var(--muted)">rankinis </td></tr>
        <tr><td>24 valandas per parą, 7 dienas per savaitę, visose rinkose</td><td style="color:var(--pos)">✓</td><td style="color:var(--muted)">popierizmas</td><td style="color:var(--muted)">Padarykite tai patys</td></tr>
        <tr><td>Antrojo užsakymo maršrutas</td><td style="color:var(--pos)">✓</td><td style="color:var(--muted)">tik pagal pakopą</td><td style="color:var(--muted)">rankinis </td></tr>
        <tr><td>Ataskaitų teikimas keliomis valiutomis</td><td style="color:var(--pos)">✓</td><td style="color:var(--muted)">ribotos valandos</td><td style="color:var(--muted)">Padarykite tai patys</td></tr>
        <tr><td>Elektroninis sąskaitos atidarymas</td><td style="color:var(--pos)">✓</td><td style="color:var(--muted)">popierizmas</td><td style="color:var(--muted)">rankinis </td></tr>
        <tr><td>Kryžminis mainų arbitražas</td><td style="color:var(--pos)">✓</td><td style="color:var(--muted)">tik pagal pakopą</td><td style="color:var(--muted)">Padarykite tai patys</td></tr>
        <tr><td>Atsidavęs asmeninis vadybininkas</td><td style="color:var(--pos)">✓</td><td style="color:var(--muted)">ribotos valandos</td><td style="color:var(--muted)">rankinis </td></tr>
      </tbody>
    </table></div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>01</b><i></i></div>
    <h2>Tai, ką jūs gaunate, negauna 'pasidaryk pats' sąranka</h2>
    <ul class="skvsaz4">
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><b style="color:var(--heading)">Po sekundės vykdymas kiekvienoje prijungtoje rinkoje.</b> <?= e(SITE_NAME) ?>palaiko nuolatinius mažos delsos API ryšius su kiekvienu palaikomu mainu. Kai modelis generuoja signalą, užsakymas išsiunčiamas, užpildomas ir registruojamas prietaisų skydelyje prieš kitą pažymint.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><b style="color:var(--heading)">Jis veikia 24 valandas per parą, 7 dienas per savaitę, per kiekvieną rinkos sesiją.</b> Kripto nerimsta ir nesiilsi<?= e(SITE_NAME) ?>. Variklis nuolat analizuoja poras savaitgaliais ir švenčių dienomis, kad nepraleistumėte progos.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><b style="color:var(--heading)">Ataskaitų teikimas keliomis valiutomis.</b> Kiekvienas likutis, kiekvienas sandoris ir kiekvienas išėmimas rodomas jūsų vietine valiuta. Jokiu būdu nėra paslėptų konversijos veiksmų.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><b style="color:var(--heading)">Atskirtas kapitalas.</b> Jūsų lėšos lieka jūsų sąskaitoje.<?= e(SITE_NAME) ?>niekada jų nelaiko: variklis turi tik leidimą siųsti užsakymus.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><b style="color:var(--heading)">Banko lygio saugumas.</b> TLS šifravimas visoje platformoje, dviejų etapų patvirtinimas pagal numatytuosius nustatymus ir ketvirtinis trečiosios šalies infrastruktūros auditas. Prekybos kvitai užregistruoti grandinėje.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><b style="color:var(--heading)">Trys turto klasės, viena platforma.</b> Dauguma mažmeninės prekybos platformų apsiriboja viena rinka.<?= e(SITE_NAME) ?>prekiauja kriptovaliutomis, listinguojamomis akcijomis ir pagrindinėmis valiutų poromis iš tos pačios informacijos suvestinės.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span><b style="color:var(--heading)">Iš anksto nustatyti rizikos limitai kiekvienai pozicijai.</b> Nuostolių sustabdymo, didžiausio priimtino nuostolio ir kapitalo paskirstymo viršutinės ribos sukonfigūruojamos pagal turto klases. Variklis automatiškai uždaro bet kokį slenkstį pažeidžiantį sandorį, o įvykis įrašomas į jūsų audito istoriją.</span></li>
    </ul>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>03</b><i></i></div>
    <h2>Kam tai tikriausiai ne</h2>
    <p>Atvirai elgdamiesi sutaupysite kiekvieno laiko. Jei kuris nors iš toliau nurodytų dalykų apibūdina jus, jums labiau tiks kitas maršrutas.</p>
    <ul class="skvsaz4">
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Jums reikia garantuotos grąžos. Jokia sąžininga platforma jų nesiūlo ir mes taip pat.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Norite investuoti pinigus, be kurių negalite sau leisti.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Prekiaujate profesionaliu kiekiu, naudodami savo vykdymo krūvą.</span></li>
    </ul>
    <p class="jkkyl">Investavimas yra susijęs su rizika, įskaitant galimą dalies arba viso investuoto kapitalo praradimą. Investicijų vertė gali tiek mažėti, tiek kilti, ir jūs galite atgauti mažiau, nei iš pradžių įdėjote. Neturėtumėte investuoti pinigų, kurių negalite sau leisti prarasti.</p>
    <div class="tw9z4by"><a class="qou73xg fi3abjs" href="<?= page_url() ?>#nlokf">Pradėti -<?= e(money_min()) ?> nuo</a></div>
  </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
