# Dahu Store Credit

Transforme un code promo WooCommerce à montant fixe en véritable avoir : ce qui
reste est reporté au lieu d'être perdu.

Développé et maintenu par [Hugo Vial-Jaime — Dahu-Concept](https://dahu-concept.fr).

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## Le problème

Un code promo WooCommerce à montant fixe est consommé en entier. Un client qui
détient un avoir de 100 et commande pour 40 perd les 60 autres. C'est correct
pour un bon de réduction, et faux pour un avoir, un remboursement ou une carte
cadeau.

## Ce qu'il fait

Cochez une case sur un code promo. Une fois une commande l'utilisant payée,
l'extension réécrit le solde restant sur le **même** code — le client garde le
même code, avec moins dessus — remet le compteur d'utilisations à zéro, retire
le client de la liste « déjà utilisé par » pour qu'il puisse dépenser le reste,
et consigne le mouvement dans un historique sur l'écran du code promo.

Annuler ou rembourser une commande recrédite le montant consommé.

## Commandes simultanées

Deux commandes impayées pourraient autrement réserver chacune la totalité du
solde. Avant d'accepter un code promo, l'extension soustrait ce que les
commandes en attente et en pause ont déjà réservé dessus, et plafonne le code à
ce qui reste réellement — en mémoire, sans jamais toucher au montant enregistré.

Cette recherche part du code promo, pas de la file des commandes : elle lit les
lignes de code portant ce code et n'inspecte que ces commandes-là. Parcourir
toutes les commandes en attente rendrait la validation du panier de plus en plus
lente à mesure que la boutique vieillit.

## Fonctionnalités

- Une case à cocher par code promo, dans son onglet Général
- Report automatique du solde restant après le paiement
- Restitution à l'annulation ou au remboursement
- Prolongation facultative de la date d'expiration à chaque utilisation partielle
- Protection contre deux commandes impayées réservant le même solde
- Historique des mouvements sur l'écran du code promo
- Liste des avoirs actifs avec leur solde
- Compatible avec le stockage de commandes haute performance (HPOS)

## Prérequis

| | |
|---|---|
| WordPress | 6.0 ou plus |
| PHP | 7.4 ou plus |
| WooCommerce | 7.0 ou plus |
| HPOS | Compatible |

## Limites

Seuls les codes promo de type **remise fixe sur le panier** sont traités.
Reporter un solde n'a pas de sens pour un code en pourcentage ou par produit :
un code coché mais d'un autre type est ignoré, et l'écran du code promo le dit.

## Désinstallation

Supprimer l'extension retire ses réglages. Les soldes, les marqueurs et
l'historique des codes promo sont **conservés** : les retirer retransformerait
en silence chaque avoir en code promo consommé en entier, ce qui changerait des
soldes clients réels. Définissez `dahu_store_credit_remove_data_on_uninstall`
à une valeur vraie avant la suppression pour tout effacer.

## Services externes

Cette extension ne contacte aucun service externe.

## Licence

MIT — voir [LICENSE](LICENSE).
