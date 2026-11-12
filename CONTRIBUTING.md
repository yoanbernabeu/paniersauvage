# Contribuer à Panier Sauvage

Merci de t'intéresser à Panier Sauvage ! Le projet est porté par une seule personne : pour que ton
temps et le mien soient bien employés, **tout commence par une issue**.

## La règle : une issue d'abord, jamais une pull request directe

- **Pas de pull request sans issue acceptée au préalable.** Une pull request qui n'est pas liée à
  une issue marquée comme acceptée sera fermée sans revue, même si le code est bon.
- Ouvre d'abord une issue (bug, idée ou question) et attends une réponse. On y discute du besoin,
  de la pertinence pour les producteurs et de la façon de faire.
- Si l'issue est acceptée et que tu proposes de t'en charger, je te le confirme dans l'issue. Alors
  seulement, tu peux ouvrir une pull request qui la référence (`Closes #123`).

Pourquoi ? Panier Sauvage suit une direction produit volontairement étroite (un outil du producteur
qui vend en direct, pas une place de marché ; voir [`docs/produit/`](docs/produit/README.md) et
[`docs/SPEC.md`](docs/SPEC.md)). Beaucoup d'idées raisonnables n'y ont pas leur place : mieux vaut le
savoir avant d'écrire du code.

## Signaler un bug

Utilise le modèle « Bug » : ce que tu as fait, ce que tu attendais, ce qui s'est passé, et sur quelle
instance (en ligne ou installation locale, version ou commit). Une capture d'écran aide souvent.

**Une faille de sécurité ne se signale jamais dans une issue publique** : suis
[`SECURITY.md`](SECURITY.md).

## Proposer une idée

Utilise le modèle « Idée » et pars du problème vécu par un producteur ou un client, pas de la
solution technique. Vérifie avant qu'une issue semblable n'existe pas déjà.

## Si ta pull request a été acceptée dans une issue

1. Installe le projet en local (voir le [README](README.md)).
2. Lis [`docs/SPEC.md`](docs/SPEC.md) et [`AGENTS.md`](AGENTS.md) : règles produit, modèle de données
   et conventions de code y sont fixés.
3. Travaille sur une branche à partir de `main`, en petits commits.
4. Ajoute les tests qui verrouillent ton changement (`WebTestCase` et factories Foundry).
5. Lance toute la CI en local avant d'ouvrir la pull request :

   ```bash
   composer ci
   ```

   Sur chaque pull request, la CI lance aussi Infection sur les lignes modifiées (au moins 90 % des
   mutants couverts doivent être tués) : un mutant qui survit se corrige par une assertion, pas par
   une exclusion.

### Conventions

- Code, identifiants et commentaires en anglais ; interface et e-mails en français, au tutoiement.
- Messages de commit en anglais, à l'impératif, avec un préfixe `feat:`, `fix:`, `test:`, `chore:`,
  `docs:` ou `refactor:`, 72 caractères au plus.
- Style `@Symfony` (`vendor/bin/php-cs-fixer fix`), PHPStan au niveau max, Rector sans proposition.

## Licence des contributions

Panier Sauvage est distribué sous licence [AGPL-3.0-or-later](LICENSE). En proposant une contribution,
tu acceptes qu'elle soit publiée sous cette même licence.

## Code de conduite

Ce projet applique un [code de conduite](CODE_OF_CONDUCT.md). En participant, tu t'engages à le
respecter.
