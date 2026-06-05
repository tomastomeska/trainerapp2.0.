<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

renderAthleteHeader('Všeobecné podmínky pro sportovce');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-file-contract me-2 text-warning"></i>Všeobecné podmínky pro sportovce</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Zpět na profil
        </a>
        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Vytisknout
        </button>
    </div>
</div>

<div class="alert alert-warning border-0 shadow-sm">
    Tento dokument obsahuje všeobecné podmínky používání aplikace pro sportovce, včetně omezení odpovědnosti a účelu projektu.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">1) Účel aplikace</div>
    <div class="card-body">
        <p>
            Aplikace slouží jako organizační a evidenční nástroj pro spolupráci mezi sportovcem a trenérem
            v rámci omezené, interní a úzké skupiny uživatelů.
            Nejde o veřejnou komerční službu určenou pro masové užívání.
        </p>
        <p class="mb-0">
            Aplikace má podpůrný charakter. Nenahrazuje zdravotní péči, lékařské doporučení,
            osobní konzultaci ani odborné posouzení zdravotního stavu.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">2) Ne-komerční charakter projektu</div>
    <div class="card-body">
        <p>
            Vývojář není podnikatel a tento projekt neslouží k výdělku, prodeji ani k poskytování placených služeb vývojářem.
            Projekt je provozován jako neveřejný nástroj pro omezený okruh uživatelů.
        </p>
        <p class="mb-0">
            Používání aplikace nezakládá mezi sportovcem a vývojářem obchodní vztah,
            závazek poskytování zákaznické podpory v režimu komerční služby ani garanci nepřetržité dostupnosti systému.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">3) Odpovědnost za data a jejich ztrátu</div>
    <div class="card-body">
        <p>
            Uživatel bere na vědomí, že vývojář nenese odpovědnost za ztrátu dat, poškození dat,
            neúplnost záznamů nebo nedostupnost údajů z důvodu technické poruchy,
            výpadku hostingu, chyby infrastruktury, zásahu třetích stran nebo nesprávného použití aplikace.
        </p>
        <p>
            Sportovec je odpovědný za to, aby důležité informace průběžně konzultoval s trenérem
            a nespoléhal výhradně na jediný elektronický záznam bez vlastní kontroly.
        </p>
        <p class="mb-0">
            Vkládání dat do aplikace probíhá na vlastní odpovědnost uživatele. Uživatel bere na vědomí,
            že data mohou být ovlivněna technickými limity prostředí, ve kterém je aplikace provozována.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">4) Omezení odpovědnosti vývojáře</div>
    <div class="card-body">
        <p>
            Vývojář nenese odpovědnost za přímou ani nepřímou újmu vzniklou v souvislosti s používáním aplikace,
            zejména za ušlý prospěch, provozní komplikace, ztrátu dat, zpoždění informací,
            chybné vyhodnocení výkonu nebo následky rozhodnutí učiněných na základě údajů v aplikaci.
        </p>
        <p class="mb-0">
            Aplikace je poskytována v režimu "jak stojí a leží" bez výslovných či implicitních záruk,
            včetně záruky vhodnosti pro konkrétní účel, nepřetržité funkčnosti nebo bezchybnosti.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">5) Zdravotní a bezpečnostní upozornění</div>
    <div class="card-body">
        <p>
            Tréninkové a výživové informace v aplikaci mají informativní charakter a musí být posuzovány
            s ohledem na individuální zdravotní stav sportovce.
        </p>
        <p class="mb-0">
            Vývojář neodpovídá za zranění, zdravotní komplikace ani jiné újmy vzniklé při sportovní aktivitě,
            při realizaci tréninkových plánů nebo při dodržování výživových doporučení.
            Za odborné vedení odpovídá trenér a za vlastní zdravotní rozhodnutí odpovídá sportovec.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">6) Povinnosti sportovce při používání aplikace</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Chránit své přihlašovací údaje a nesdílet účet s dalšími osobami.</li>
            <li>Pravidelně kontrolovat kalendář, zprávy, jídelníčky a tréninkové záznamy.</li>
            <li>Neprodleně řešit nejasnosti nebo chyby se svým trenérem.</li>
            <li>Nevkládat nepravdivé, zavádějící nebo protiprávní informace.</li>
            <li>Používat aplikaci pouze v souladu s jejím určením.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">7) Soukromí a zabezpečení účtu</div>
    <div class="card-body">
        <p>
            Uživatel je odpovědný za zabezpečení svého zařízení a účtu. V případě podezření na zneužití účtu
            je povinen bezodkladně změnit heslo a kontaktovat trenéra.
        </p>
        <p class="mb-0">
            Vývojář nenese odpovědnost za škody vzniklé v důsledku slabého hesla, sdíleného zařízení,
            nezabezpečené sítě nebo jiných okolností mimo přímou kontrolu vývojáře.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">8) Přijetí podmínek</div>
    <div class="card-body">
        <p>
            Používáním aplikace sportovec potvrzuje, že se s těmito podmínkami seznámil,
            rozumí jim a souhlasí s nimi.
        </p>
        <p class="mb-0">
            Pokud sportovec s podmínkami nesouhlasí, neměl by aplikaci používat.
            Vývojář si vyhrazuje právo text podmínek přiměřeně aktualizovat.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">9) Kontakt</div>
    <div class="card-body">
        <p class="mb-2">
            Pro dotazy k těmto podmínkám nebo k provozu aplikace použijte následující kontakt:
        </p>
        <p class="mb-1"><strong>E-mail:</strong> tomas.tomeska@seznam.cz</p>
        <p class="mb-0"><strong>Telefon:</strong> +420778759958</p>
    </div>
</div>

<div class="alert alert-secondary border-0 shadow-sm mb-4">
    Datum poslední úpravy podmínek: <?= date('d.m.Y') ?>
</div>

<?php renderAthleteFooter(); ?>
