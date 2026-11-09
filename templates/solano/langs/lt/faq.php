<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'DUK ' . SITE_NAME;
$page_description = 'Klausimai, atsakyti – ' . SITE_NAME;
$page_canonical = page_url("faq.php");
$active_page = "faq";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main id="top">

<section class="kj9w4x">
  <div class="ggh3sm">
    <span class="qwce6q">Klausimai</span>
    <h1>Klausimai, atsakyta aiškiai</h1>
    <p class="kpnq92g">Ko žmonės klausia prieš atidarydami paskyrą ir kokius atsakymus jums pateiksime telefonu.</p>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>02</b><i></i></div>
    <h2>Klausimai dėl pinigų</h2>
    <div class="fjl4d" itemscope itemtype="https://schema.org/FAQPage">
      <details itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
        <summary><h3 itemprop="name">Ar <?= e(SITE_NAME) ?> yra sukčiavimas?</h3></summary>
        <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">Ne: <?= e(SITE_NAME) ?> veikia su patvirtinimo čekiais, skelbia visas sąlygas ir rizikos atskleidimą, o išėmimas visada grąžinamas naudojant pradinį mokėjimo būdą. Be to, kiekviena investicija yra susijusi su realia rizika ir jokia rimta platforma nežada garantuotos grąžos – būkite atsargūs visiems, kurie tai daro.</p></div>
      </details>
      <details itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
        <summary><h3 itemprop="name">Kiek kainuoja atidaryti sąskaitą?</h3></summary>
        <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">Sąskaitos atidarymas nemokamas. Nėra registracijos mokesčio ir prenumeratos; įdedate tik tą sumą, kurią pasirinkote investuoti.</p></div>
      </details>
      <details itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
        <summary><h3 itemprop="name">Kiek laiko užtrunka išėmimai?</h3></summary>
        <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">Užklausos apdorojamos darbo dienomis ir grąžinamos tokiu būdu, kuriuo pateikėte deponavimą. Banko pavedimai užtrunka ilgiau nei kortelės ar elektroninės piniginės.</p></div>
      </details>
      <details itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
        <summary><h3 itemprop="name">Ar yra minimali suma?</h3></summary>
        <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">Taip, ir jis yra sąmoningai žemas, nuo<?= e(money_min()) ?>, kad galėtumėte pradėti nuo mažo ir vėliau pridėti daugiau. Tikslus skaičius rodomas prieš ką nors patvirtinant.</p></div>
      </details>
    </div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>01</b><i></i></div>
    <h2>Dažnai užduodami klausimai</h2>
    <div class="fjl4d">
      <details open><summary>Koks yra minimalus įnašas norint pradėti?</summary><p>Galite atidaryti ir finansuoti savo sąskaitą iš<?= e(money_min()) ?>minimumas. Vykdydami investavimo planą, galite pridėti daugiau lėšų.</p></details>
      <details><summary>Kaip veikia išėmimai?</summary><p>Bet kuriuo metu informacijos suvestinėje pateikite pinigų išėmimo užklausą. Lėšos grąžinamos į jūsų pasirinktą mokėjimo būdą įprastu apdorojimo laiku.</p></details>
      <details><summary>Ar mano pinigai laikomi saugiai?</summary><p>Paskyros apsaugotos profesionaliu saugumo ir tapatybės patikrinimu. Kaip ir bet kuriai investicijai, jūsų kapitalui gresia pavojus, o vertės gali kristi ir kilti.</p></details>
      <details><summary>Kiek laiko užtrunka norint pradėti investuoti?</summary><p>Dauguma narių registraciją užbaigia per kelias minutes. Kai jūsų pirmasis įnašas bus apdorotas, planą galėsite aktyvuoti iš karto.</p></details>
      <details><summary>Ar yra paslėptų mokesčių?</summary><p>Visos išlaidos skaidriai parodomos prieš įsipareigojant. Visada pamatysite, kas tinka jūsų planui, be staigmenų.</p></details>
      <details><summary>Koks minimalus amžius norint užsiregistruoti?</summary><p>Norėdami atidaryti sąskaitą ir investuoti, turite būti bent 18 metų. Gali būti paprašyta patvirtinti jūsų amžių ir tapatybę.</p></details>
      <details><summary>Kokie mokėjimo būdai yra priimtini?</summary><p>Priimami įprasti metodai, tokie kaip debeto ir kredito kortelės, banko pavedimai, tam tikros elektroninės piniginės ir kriptovaliutos. Tikslios parinktys rodomos indėlio žingsnyje.</p></details>
      <details><summary>Kada galimas klientų aptarnavimas?</summary><p>Mūsų palaikymo komanda dirba nuo pirmadienio iki penktadienio, 9:00–18:00 ir įsipareigoja atsakyti į kiekvieną užklausą per vieną darbo dieną.</p></details>
      <details><summary>Kaip tvarkomi pelno mokesčiai?</summary><p>Investicijų pelno mokesčiai priklauso nuo jūsų šalies taisyklių ir yra jūsų atsakomybė. Rekomenduojame saugoti savo įrašus ir pasikalbėti su kvalifikuotu mokesčių konsultantu.</p></details>
      <details><summary>Kas yra KYC patvirtinimas ir kodėl jis reikalingas?</summary><p>KYC (Know Your Customer) yra standartinis jūsų tapatybės patikrinimas. Tai padeda apsaugoti sąskaitas ir yra įprasta investicinės sąskaitos atidarymo dalis.</p></details>
      <details><summary>Ar man reikia ankstesnės investavimo patirties?</summary><p>Ne. Kiekvienas narys turi asmeninį finansų analitiką, kuris jums padės kiekviename žingsnyje, todėl jums nereikia išankstinių žinių apie rinkas.</p></details>
      <details><summary>Kas valdo mano investicijas?</summary><p>Atsidavęs finansų analitikas, remiamas AI įrankiais, siekiantis jūsų tikslų ir rizikos lygio. Analitikas sujungia profesionalią patirtį su technologijomis – sprendimai lieka žmogiški.</p></details>
      <details><summary>Ar platforma atitinka reguliavimo standartus?</summary><p>Taip – ​​ji atitinka nacionalinius finansinio ir kibernetinio saugumo standartus, įtaisyta paskyros apsauga ir tikrinimas.</p></details>
      <details><summary>Ar galiu vėliau pridėti daugiau lėšų į savo sąskaitą?</summary><p>Taip. Galite bet kada papildyti savo sąskaitą ir koreguoti planą su analitiku, kai jūsų tikslai vystosi.</p></details>
    </div>
  </div>
</section>

<section class="onxr8te" data-u="sec">
  <div class="ggh3sm">
    <div class="fduhcv"><b>03</b><i></i></div>
    <h2>Sąskaita ir saugumas</h2>
    <div class="fjl4d" itemscope itemtype="https://schema.org/FAQPage">
      <details itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
        <summary><h3 itemprop="name">Kaip veikia prisijungimas <?= e(SITE_NAME) ?>?</h3></summary>
        <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">Jūs prisijungiate naudodami registruotą el. pašto adresą ir slaptažodį iš svetainės arba iš mobiliosios naršyklės. Jei įjungėte patvirtinimą dviem veiksmais, jūsų bus paprašyta įvesti papildomą kodą; Jei pamiršote slaptažodį, galite jį iš naujo nustatyti prisijungimo ekrane.</p></div>
      </details>
      <details itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
        <summary><h3 itemprop="name">Kam jums reikalingi mano asmens dokumentai?</h3></summary>
        <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">Norint pervesti lėšas į sąskaitą, būtinas patvirtinimas. Tai taip pat neleidžia kitam asmeniui atidaryti sąskaitą jūsų vardu.</p></div>
      </details>
      <details itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
        <summary><h3 itemprop="name">Ar man reikia ankstesnės patirties?</h3></summary>
        <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">Ne. Dauguma narių pradeda nieko. Specialistas supažindins su pirmaisiais žingsniais, o demonstracinis balansas leidžia treniruotis.</p></div>
      </details>
      <details itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
        <summary><h3 itemprop="name">Ar galiu jį naudoti telefone?</h3></summary>
        <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">Taip, platforma veikia mobiliojoje naršyklėje ir nieko nereikia įdiegti.</p></div>
      </details>
    </div>
    <div class="tw9z4by">
      <a class="qou73xg fi3abjs" href="<?= page_url() ?>#nlokf">Pradėti -<?= e(money_min()) ?> nuo</a>
      <a class="qou73xg ec2hno" href="<?= page_url('contacts.php') ?>">Siųsti žinutę</a>
    </div>
  </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
