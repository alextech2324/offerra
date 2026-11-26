import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'templates', 'pnaf', 'index.html'), 'utf8');

const mainStart = html.indexOf('<main class="main-wrapper">');
const mainEnd = html.indexOf('</main>', mainStart);
if (mainStart < 0 || mainEnd < 0) throw new Error('main not found');
let main = html.slice(mainStart, mainEnd + '</main>'.length);

function phpEcho(expr) {
  return `<?= ${expr} ?>`;
}

const formPhp = (id, extraClass) => `
<?php
  $form_id = ${JSON.stringify(id)};
  $form_class = ${JSON.stringify('leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form ' + extraClass)};
  $form_submit = 'Enviar';
  require __DIR__ . '/includes/form.php';
?>
`;

function stripForm(html, formId, extraClass) {
  const re = new RegExp(
    `<form id="${formId}"[\\s\\S]*?</form>\\s*(?:<script>[\\s\\S]*?<\\/script>)?`,
    'i',
  );
  if (!re.test(html)) throw new Error('form missing: ' + formId);
  return html.replace(re, formPhp(formId, extraClass));
}

main = stripForm(main, 'registrationForm-mob', 'mob-form');
main = main.replace(/<script>\s*\(function \(\) \{\s*var GEO[\s\S]*?<\/script>/, '');
main = stripForm(main, 'registrationForm-pc', 'form-pc');
main = stripForm(main, 'registrationForm-cta', 'form-pc');

main = main.replace(/https:\/\/pnaf-plataforma\.site\/?/g, "<?= page_url() ?>");
main = main.replace(/href="\/about\/"/g, 'href="<?= page_url(\'about.php\') ?>"');
main = main.replace(/href="\/register\/"/g, 'href="<?= page_url(\'sign.php\') ?>"');
main = main.replace(/href="\/contact\/"/g, 'href="<?= page_url(\'contacts.php\') ?>"');
main = main.replace(/href="\/privacy-policy\/"/g, 'href="<?= page_url(\'privacy.php\') ?>"');
main = main.replace(/href="\/terms-conditions\/"/g, 'href="<?= page_url(\'conditions.php\') ?>"');
main = main.replace(/src="images\/([^"]+)"/g, "src=\"<?= asset('static/images/$1') ?>\"");
main = main.replace(/href="css\/([^"]+)"/g, "href=\"<?= asset('static/css/$1') ?>\"");

main = main.replace(/PNAF Plataforma/g, "<?= e(SITE_NAME) ?>");
main = main.replace(/250 €/g, "<?= e(min_deposit_label()) ?>");
main = main.replace(/desde 250 €/g, "desde <?= e(min_deposit_label()) ?>");
main = main.replace(/a partir de 250 €/g, "a partir de <?= e(min_deposit_label()) ?>");

main = main.replace(/em Portugal/g, "em <?= e(geo_country_name()) ?>");
main = main.replace(/em portugal/g, "em <?= e(geo_country_name()) ?>");
main = main.replace(/viver em <?= e\(geo_country_name\(\)\) \?>/g, 'viver em <?= e(geo_country_name()) ?>');
main = main.replace(/residentes e não/g, 'residentes e não');
main = main.replace(/A PNAF /g, '<?= e(SITE_NAME) ?> ');

main = main.replace(/support\.pnaf-plataforma\.site@gmail\.com/g, "<?= e(SUPPORT_EMAIL) ?>");
main = main.replace(/Avenida da Liberdade 110, 1269-046 Lisboa, Portugal/g, '');

main = main.replace(
  /<link rel="stylesheet" href="css\/intlTelInput\.css">[\s\S]*?<style>:root\{--iti-path-flags[\s\S]*?<\/style>\s*<script src="js\/intlTelInput\.min\.js"><\/script>\s*<script src="js\/utils\.js"><\/script>\s*<link rel="stylesheet" href="css\/form\.css">\s*<style>\.apx-hp-wrap[\s\S]*?<\/style>/,
  '',
);


const out = `<?php
require_once __DIR__ . '/includes/config.php';
$page_title = page_title('Trading e análise de mercados com IA');
$page_description = SITE_NAME . ' ajuda a acompanhar ações e criptomoedas com IA. Desde ' . min_deposit_label() . '. Sem promessa de retorno.';
$page_canonical = page_url();
$active_page = 'home';
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
${main}
<?php require __DIR__ . '/includes/footer.php'; ?>
`;

const dest = path.join(root, 'templates', 'navora', 'langs', 'pt', 'index.php');
fs.writeFileSync(dest, out);
console.log('wrote', dest, 'bytes', out.length);
