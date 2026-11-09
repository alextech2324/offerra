<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Privatumo politika ᐉ ' . SITE_NAME;
$page_description = 'Kaip ' . SITE_NAME . ' renka, naudoja ir saugo jūsų asmeninę informaciją.';
$page_canonical = page_url("privacy.php");
$active_page = "privacy";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main itemscope itemtype="https://schema.org/WebPage" id="yfln8q">
<section class="ykzi1">
  <div class="ggh3sm">
    <span class="vd7z9k">Teisinė informacija</span>
    <h1>Privatumo politika</h1>
    <p class="etpy2">Kaip <?= e(SITE_NAME) ?> renka, naudoja ir saugo jūsų asmeninę informaciją.</p>
  </div>
</section>

<section class="bqfjng">
  <div class="ggh3sm">
    <p class="cs7fii">Paskutinį kartą atnaujinta: 2025 m. sausio mėn</p>

    <h2>1. Įvadas</h2>
    <p><?= e(SITE_NAME) ?>('mes', 'mes', 'mūsų') yra įsipareigojusi saugoti lankytojų ir klientų privatumą. Šioje privatumo politikoje paaiškinama, kokius asmens duomenis renkame, kodėl juos renkame ir kaip juos tvarkome, kai naudojatės mūsų svetaine ir paslaugomis.</p>

    <h2>2. Informacija, kurią renkame</h2>
    <ul>
      <li><strong>Tapatybės duomenys</strong>— vardas, pavardė, gimimo data, vyriausybės išduotas tapatybės patvirtinimas ir KYC/AML atitiktis.</li>
      <li><strong>Kontaktiniai duomenys</strong>- elektroninio pašto adresą, telefono numerį, pašto adresą.</li>
      <li><strong>Finansiniai duomenys</strong>— mokėjimo duomenys, operacijų istorija, informacija apie lėšų šaltinį.</li>
      <li><strong>Techniniai duomenys</strong>– IP adresas, naršyklės tipas, įrenginio identifikatoriai, slapukai ir naudojimo analizė.</li>
    </ul>

    <h2>3. Kaip naudojame jūsų duomenis</h2>
    <ul>
      <li>Patvirtinti tapatybę ir laikytis reglamentavimo įsipareigojimų.</li>
      <li>Teikti, prižiūrėti ir tobulinti mūsų platformą ir paslaugas.</li>
      <li>Apdoroti mokėjimus ir aptikti nesąžiningą veiklą.</li>
      <li>Norėdami susisiekti su jumis apie jūsų paskyrą, atnaujinimus ir palaikymo užklausas.</li>
      <li>Siųsti rinkodaros pranešimus, kai sutikote (galite bet kada atsisakyti).</li>
    </ul>

    <h2>4. Teisinis pagrindas</h2>
    <p>Asmens duomenis tvarkome remdamiesi vienu ar keliais iš šių teisinių pagrindų: sutarties vykdymas, teisinės pareigos laikymasis, mūsų teisėti interesai arba Jūsų sutikimas.</p>

    <h2>5. Dalijimasis ir atskleidimas</h2>
    <p>Galime dalytis asmens duomenimis su reguliuojamais mokėjimo teikėjais, KYC/AML tikrinimo partneriais, debesų infrastruktūros teikėjais, profesionaliais patarėjais ir kompetentingomis institucijomis, kai to reikalauja įstatymai. Mes neparduodame asmens duomenų.</p>

    <h2>6. Tarptautiniai pervedimai</h2>
    <p>Kai asmens duomenys perduodami už jūsų jurisdikcijos ribų, užtikriname, kad būtų taikomos atitinkamos apsaugos priemonės, įskaitant standartines sutarties sąlygas.</p>

    <h2>7. Duomenų saugojimas</h2>
    <p>Asmens duomenis saugome tiek, kiek reikia, kad galėtume teikti paslaugas ir įvykdyti teisinius, reguliavimo ir apskaitos reikalavimus – paprastai mažiausiai penkerius metus nuo paskyros uždarymo.</p>

    <h2>8. Jūsų teisės</h2>
    <p>Pagal galiojančius įstatymus galite prašyti leisti susipažinti su savo asmens duomenimis, juos ištaisyti, ištrinti, apriboti arba perkelti, taip pat galite prieštarauti tam tikram tvarkymui. Norėdami pasinaudoti šiomis teisėmis, susisiekite su mumis žemiau nurodytu adresu.</p>

    <h2>9. Slapukai</h2>
    <p>Svetainės veikimui ir naudojimo supratimui naudojame esminius ir analitinius slapukus. Slapukus galite valdyti savo naršyklės nustatymuose.</p>

    <h2>10. Saugumas</h2>
    <p>Taikome administracines, technines ir fizines apsaugos priemones, skirtas apsaugoti asmens duomenis nuo neteisėtos prieigos, atskleidimo, pakeitimo ar sunaikinimo. Jokia sistema nėra visiškai saugi, ir mes negalime garantuoti visiško saugumo.</p>

    <h2>11. Pakeitimai</h2>
    <p>Retkarčiais galime atnaujinti šią politiką. Naujausia versija visada bus pasiekiama šiame puslapyje su atnaujinta data.</p>

    <h2>12. Susisiekite</h2>
    <p>Jei turite klausimų dėl privatumo arba norite pasinaudoti savo teisėmis, susisiekite su <?= e(SITE_NAME) ?> duomenų apsaugos komanda mūsų kontaktų puslapyje.</p>

    <p style="margin-top:36px"><a class="qou73xg ec2hno" href="<?= page_url() ?>">← Grįžti į namus</a></p>
  </div>
</section>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
