# Politique de sécurité

## Signaler une faille

**N'ouvre pas d'issue publique pour une faille de sécurité.**

Signale-la en privé via GitHub : onglet **Security** du dépôt, puis **Report a vulnerability**
(signalement privé de vulnérabilité). Seuls les responsables du projet verront ton rapport.

Indique si possible :

- le type de faille (injection, contournement d'accès entre boutiques, XSS, fuite de données
  personnelles…) ;
- la page, la route ou le fichier concerné ;
- les étapes pour la reproduire, et l'impact que tu envisages ;
- la version ou le commit testé.

## Ce à quoi tu peux t'attendre

- un accusé de réception sous 7 jours ;
- un premier diagnostic et, si la faille est confirmée, un correctif publié dès que possible ;
- une mention dans l'avis de sécurité publié, si tu le souhaites.

Merci de laisser le temps de corriger avant toute divulgation publique, et de ne tester que sur ta
propre installation : jamais sur une instance en production ni sur les données de vrais producteurs
ou clients.

## Versions prises en charge

Seule la dernière version de la branche `main` reçoit des correctifs de sécurité. Si tu héberges ta
propre instance, tiens-la à jour (voir [`docs/DEPLOY.md`](docs/DEPLOY.md)).
