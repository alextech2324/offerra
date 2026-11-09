<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Kainodara ' . SITE_NAME;
$page_description = 'Paprasta, skaidri kainodara – ' . SITE_NAME;
$page_canonical = page_url("pricing.php");
$active_page = "pricing";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main id="top" itemscope itemtype="https://schema.org/WebPage">

<section class="kj9w4x">
  <div class="ggh3sm">
    <span class="qwce6q">Kainodara</span>
    <h1>Paprasta, skaidri kainodara.</h1>
    <p class="kpnq92g">Darbo pradžia su<?= e(SITE_NAME) ?>yra nemokama. Už sąskaitos atidarymą nėra jokių paslėptų mokesčių, o investuojate tik tai, ką pasirenkate investuoti: platforma ir jos įrankiai yra įtraukti.</p>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>02</b><i></i></div>
    <h2>Kur gali atsirasti išlaidos</h2>
    <p>Tai yra vieninteliai taškai, kai pinigai palieka jūsų likutį ne jūsų pasirinktai investicijai.</p>
    <div class="ltouuo" role="region" tabindex="0"><table class="wcle1">
      <thead><tr><th scope="col">Prekė</th><th scope="col">Apmokestina </th><th scope="col">Pastaba</th></tr></thead>
      <tbody>
        <tr><td>Sąskaitos atidarymas</td><td>—</td><td>Nemokama.</td></tr>
        <tr><td>Prieiga prie platformos</td><td>—</td><td>Įtraukta, be prenumeratos.</td></tr>
        <tr><td>Rinkos plitimas</td><td>Brokeris</td><td>Įprastas skirtumas tarp pirkimo ir pardavimo kainos.</td></tr>
        <tr><td>Tinklo / banko mokestis</td><td>Mokėjimo teikėjas</td><td>Priklauso nuo pasirinkto metodo.</td></tr>
      </tbody>
    </table></div>
    <p class="jkkyl">Kapitalas rizikuoja. Investuokite tik tai, ką galite sau leisti prarasti.</p>
    <div class="tw9z4by"><a class="qou73xg fi3abjs" href="<?= page_url() ?>#nlokf">Atidarykite paskyrą</a></div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>01</b><i></i></div>
    <h2>Kas įtraukta</h2>
    <ul class="skvsaz4">
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Nemokamas paskyros nustatymas: nėra registracijos ar licencijos mokesčių.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Jokių paslėptų mokesčių už indėlius, išėmimus ar sąskaitos priežiūrą.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Gali būti taikomi tik standartiniai brokerių skirtumai arba tinklo mokesčiai.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Pradėkite nuo minimalaus indėlio ir padidinkite savo tempą.</span></li>
    </ul>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm op6k5h">
    <h2>Kaip skaičiai atrodo praktiškai</h2>
    <h3>Pirmas indėlis žingsnis po žingsnio</h3>
    <p>Pirmasis įnašas – tai visos kainos vaizdas vienoje vietoje: jūsų siunčiama suma, skirtumas, kai ji konvertuojama, ir nieko daugiau, kol nuspręsite prekiauti. Mėnesio pabaigoje nelaukiamas sąskaitos mokestis ir likučio palikimas ten, kur jis yra.</p>
    <h4>Kas vyksta tą pačią dieną</h4>
    <p>Likutis pasirodo, kai apmokamas mokėjimas, o specialistas peržiūri planą prieš ką nors atidarant. Niekas automatiškai neįdedamas jūsų vardu.</p>
    <h3>Išėmimai ir kiek jie kainuoja</h3>
    <p>Išėmimai grįžta į mokėjimo būdą, iš kurio buvo gauti pinigai: tai yra reikalavimas, o ne pageidavimas, todėl sąskaita lieka jūsų. Apdorojimas iš mūsų pusės nemokamas; vienintelis išskaitymas, kurį galite matyti, yra tas, kurį taiko jūsų bankas arba kortelės išdavėjas.</p>
    <h4>Laikas, kurį galite planuoti</h4>
    <p>Darbo dieną pateikti prašymai peržiūrimi tą pačią dieną. Kortelių grąžinimas dažniausiai atsiskaito per tris-penkias darbo dienas, banko pavedimai – per dvi.</p>
  </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
