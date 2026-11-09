<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Pranešti apie piktnaudžiavimą ᐉ ' . SITE_NAME;
$page_description = 'Pranešti apie piktnaudžiavimą – ' . SITE_NAME;
$page_canonical = page_url("report-abuse.php");
$active_page = "abuse";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main itemscope itemtype="https://schema.org/WebPage" id="yfln8q">
<section class="ykzi1">
  <div class="ggh3sm">
    <span class="vd7z9k">Pasitikėjimas ir saugumas</span>
    <h1>Pranešti apie piktnaudžiavimą</h1>
    <p class="rmct9">Padėkite mums apsaugoti <?= e(SITE_NAME) ?>. Praneškite apie įtariamą sukčiavimą, apsimetinėjimą arba piktnaudžiavimą mūsų platforma ar prekės ženklu.</p>
  </div>
</section>

<section class="bqfjng">
  <div class="ggh3sm">
    <h2>Ką pranešti</h2>
    <p>Praneškite apie bet kurį iš šių dalykų mūsų pasitikėjimo ir saugos komandai:</p>
    <ul>
      <li>Sukčiavimo el. laiškai, apgaulingos svetainės arba netikros programos, apsimetančios <?= e(SITE_NAME) ?>.</li>
      <li>Socialinės žiniasklaidos paskyros, skelbimai ar pranešimų kanalai, kuriuose piktnaudžiaujama mūsų vardu, logotipu ar prekių ženklais.</li>
      <li>Įtariamas paskyros perėmimas, neteisėta prieiga arba tapatybės vagystė.</li>
      <li>Įtartini mokėjimo prašymai, 'išieškojimo agentai' arba trečiosios šalys, teigiančios, kad veikia mūsų vardu.</li>
      <li>Piktnaudžiavimas rinka, susirūpinimas pinigų plovimu arba bet kokia neteisėta veikla, susijusi su mūsų paslaugomis.</li>
      <li>Įžeidžiantis, grasinantis arba priekabiaujantis elgesys su mūsų darbuotojais ar naudotojais.</li>
    </ul>

    <h2>Kaip pranešti</h2>
    <p>Išsamią ataskaitą atsiųskite mums bet kuriuo iš toliau nurodytų kanalų. Jei galite, įtraukite:</p>
    <ul>
      <li>Įvykio data ir laikas.</li>
      <li>URL, ekrano kopijos, pranešimų antraštės, siuntėjų adresai arba telefono numeriai.</li>
      <li>Jūsų paskyros informacija (jei ataskaita susijusi su jūsų paskyra).</li>
      <li>Bet koks kitas kontekstas, galintis padėti mums ištirti.</li>
    </ul>

    <div class="ziavo">
      <div class="bv1ft5">
        <div class="qpw9z"><i class="erhel bf48erp"></i></div>
        <b>Pasitikėjimo ir saugos el. paštas</b>
        <span>Norėdami susisiekti su mūsų pasitikėjimo ir saugos komanda, naudokite kontaktų puslapį. Ataskaitos patikrinamos per vieną darbo dieną.</span>
      </div>
      <div class="bv1ft5">
        <div class="qpw9z"><i class="erhel ou4vm"></i></div>
        <b>Saugumo atskleidimas</b>
        <span>Norėdami atsakingai atskleisti saugumo spragas, turinčias įtakos mūsų sistemoms, susisiekite su mumis prieš viešai bendrindami informaciją.</span>
      </div>
    </div>

    <h2>Kas bus toliau?</h2>
    <p>Mes peržiūrime kiekvieną ataskaitą. Atsižvelgdami į problemos pobūdį, galime susisiekti su jumis norėdami gauti daugiau informacijos, bendradarbiauti su mokėjimo paslaugų teikėjais ar prieglobos platformomis, kad pašalintume apgaulingą turinį, arba kreiptis į teisėsaugos ar reguliavimo institucijas. Pranešimus laikome konfidencialiai ir, kai teisiškai įmanoma, saugome reporterių tapatybę.</p>

    <h2>Skubūs reikalai</h2>
    <p>Jei manote, kad tapote nusikaltimo auka, susisiekite su vietos teisėsaugos institucija ir pasakykite mums. Jei įtariate, kad jūsų paskyra buvo pažeista, nedelsdami pakeiskite slaptažodį ir nedelsdami praneškite mums.</p>

    <p style="margin-top:36px">
      <a class="qou73xg fi3abjs" href="<?= page_url('contacts.php') ?>">Susisiekite su pasitikėjimu ir sauga</a>
      <a class="qou73xg ec2hno" href="<?= page_url() ?>" style="margin-left:8px">← Grįžti į namus</a>
    </p>
  </div>
</section>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
