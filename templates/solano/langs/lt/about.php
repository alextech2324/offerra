<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Apie ' . SITE_NAME;
$page_description = 'Viena sąskaita, aiškus jūsų kapitalo vaizdas – ' . SITE_NAME;
$page_canonical = page_url("about.php");
$active_page = "about";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main id="top" itemscope itemtype="https://schema.org/ApiePage">

<section class="kj9w4x">
  <div class="ggh3sm">
    <span class="qwce6q">Platformaa</span>
    <h1>Viena paskyra, aiškus vaizdas į viską</h1>
    <p class="kpnq92g"><?= e(SITE_NAME) ?>sujungia jūsų pusiausvyrą, strategiją ir našumą į vieną skaidrią prietaisų skydelį, todėl kiekvienas sprendimas yra pagrįstas ir kiekviena figūra matoma.</p>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>03</b><i></i></div>
    <h2>Žmonės už jūsų paskyros</h2>
    <p>Už sąsajos yra analitikai, kurie kasdien tyrinėja rinkas, inžinieriai, kurie palaiko platformą, ir palaikymo specialistai, atsakantys jūsų kalba.</p>
    <ul class="skvsaz4">
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Rinkos analitikai, kurie peržiūri sąlygas kasdien, o ne kartą per ketvirtį.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Platformaos inžinieriai, stebintys visą parą.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Pagalba specialistams, kurie tvarko priėmimą, patikrinimą ir pašalinimą.</span></li>
    </ul>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>04</b><i></i></div>
    <h2>Reguliavimas, rizika ir tai, ko mes nežadame</h2>
    <p>Investavimas yra susijęs su rizika ir jokia platforma jos nepašalina. Tai, ką platforma gali padaryti, yra jums aišku: paskelbti jos sąlygas, laikyti klientų pinigus reguliuojamuose partneriuose ir dokumentuoti, kaip vyksta pinigų išėmimai.</p>
    <ul class="skvsaz4">
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Tapatybės patvirtinimas prieš perkeliant lėšas į sąskaitą.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Išėmimas grąžinamas tuo pačiu būdu, kuris buvo naudojamas užstatui.</span></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>Sąlygos, rizikos atskleidimas ir privatumo politika paskelbta visapusiškai.</span></li>
    </ul>
    <p class="jkkyl">Investavimas yra susijęs su rizika, įskaitant galimą dalies arba viso investuoto kapitalo praradimą. Investicijų vertė gali tiek mažėti, tiek kilti, ir jūs galite atgauti mažiau, nei iš pradžių įdėjote. Neturėtumėte investuoti pinigų, kurių negalite sau leisti prarasti.</p>
    <div class="tw9z4by">
      <a class="qou73xg fi3abjs" href="<?= page_url() ?>#nlokf">Pradėti -<?= e(money_min()) ?> nuo</a>
      <a class="qou73xg ec2hno" href="<?= page_url('contacts.php') ?>">Siųsti žinutę</a>
    </div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>02</b><i></i></div>
    <h2>Kaip buvo pastatyta platforma</h2>
    <ol class="nxlk2qu">
      <li><h3>Pradinis taškas</h3><p>Nedidelė analitikų ir inžinierių grupė nuolat girdėjo tą patį skundą: įrankiai yra, bet niekas jų nepaaiškina.</p></li>
      <li><h3>Pirmoji darbinė versija</h3><p>Pirmoji versija padarė vieną dalyką: aiškiai parodė pusiausvyrą ir poziciją. Visa kita buvo nuimta, kol ta dalis buvo aiški.</p></li>
      <li><h3>Žmogiškosios pusės įtraukimas</h3><p>Automatika atsako kas ir kada; žmonės atsako kodėl. Pridėta palaikymo specialistų, todėl kiekvienas narys turėtų ko paklausti.</p></li>
      <li><h3>Atsivėrimas daugiau rinkų</h3><p>Vietiniai mokėjimo būdai, vietinės kalbos ir vietinės pagalbos valandos.</p></li>
      <li><h3>Kur esame dabar</h3><p>Tie patys principai didesniu mastu: skaidrios figūros, žmonės, kuriuos galite pasiekti, jokių netikėtumų smulkiu šriftu.</p></li>
    </ol>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>01</b><i></i></div>
    <h2>Ko mes čia</h2>
    <p>Dauguma žmonių, norinčių investuoti, niekada nepradeda, nes kiekvienas kelias atrodo skirtas tam, kuris jau žino žodyną. Mes sukūrėme priešingai: viena paskyra, aiški kalba ir specialistas, su kuriuo iš tikrųjų galite pasikalbėti.</p>
    <p>Jokio žargono, kur tiks paprastas sakinys, jokių mokesčių, atsirandančių tik pajudėjus pinigus, ir jokių grąžinimo pažadų, kurių niekas negali sąžiningai garantuoti.</p>
    <div class="luvxe">
      <div class="mvuhd"><b class="lfu72qs">33.000</b><span>Aktyvūs vartotojai</span></div>
      <div class="mvuhd"><b class="lfu72qs">€0,6B</b><span>Prekybos apimtis</span></div>
      <div class="mvuhd"><b class="lfu72qs">24/7</b><span>Parama</span></div>
    </div>
  </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
