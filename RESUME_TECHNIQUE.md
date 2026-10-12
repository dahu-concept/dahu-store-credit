# RESUME TECHNIQUE — Dahu Store Credit

Document interne, en français.

---

## Portage depuis la version Annad

### Version Annad d'origine

- Dépôt source Annad : `/Dev/dahu-avoir/` (slug Annad : `dahu-avoir`)
- Version de référence au moment du portage : `1.0.4`
- Date du portage : 2026-10-06
- **Slug renommé** : `dahu-avoir` → `dahu-store-credit`. « Avoir » ne dit rien à
  un anglophone ; « store credit » est le terme consacré et le mot-clé
  réellement recherché. Voir `/Dev/dahu-public/CORRESPONDANCE-ANNAD.md`.

### Correspondance des identifiants

| Annad | Public |
|---|---|
| `dahu-avoir` (text domain, slug) | `dahu-store-credit` |
| `DAHU_AVOIR_*` | `DAHU_STORE_CREDIT_*` |
| `Dahu_Avoir_Core`, `Dahu_Avoir_Admin` | `Dahu_Store_Credit_Core`, `Dahu_Store_Credit_Admin` |
| option `dahu_avoir_settings` | `dahu_store_credit_settings` |
| transient `dahu_avoir_reserved_<id>` | `dahu_store_credit_reserved_<id>` |
| méta `_dahu_avoir` | `_dahu_store_credit` |
| méta `_dahu_avoir_traite` | `_dahu_store_credit_processed` |
| méta `_dahu_avoir_historique` | `_dahu_store_credit_history` |
| méta `_dahu_avoir_restitue` | `_dahu_store_credit_restored` |
| méthode `cap_avoir_balance()` | `cap_balance_to_available()` |

Les clés de méta ont été traduites elles aussi : aucune migration à prévoir,
c'est une première publication. **Les deux versions peuvent cohabiter sur un
même site** — aucun identifiant n'est partagé — mais elles ne verront pas les
mêmes coupons.

### Corrigé pendant le portage

**Montée en charge de `get_reserved_amount()` — le point dur.** La version Annad
appelait `wc_get_orders()` avec `limit => -1` sur les statuts `pending` et
`on-hold`, puis instanciait chaque commande dans une boucle. Cette fonction
tourne sur `woocommerce_coupon_is_valid`, donc à **chaque validation de coupon
au panier**. Sur une boutique à quelques milliers de commandes en attente,
c'était un timeout au checkout ; le transient de 30 s masquait le problème sans
le résoudre.

Le calcul part désormais du coupon et non de la file des commandes : une requête
sur `woocommerce_order_items` (`order_item_type = 'coupon'`,
`order_item_name = <code>`) donne les seules commandes qui portent réellement ce
coupon, et seules celles-là sont instanciées. Le coût devient proportionnel à
l'usage de cet avoir précis — quelques commandes — au lieu de la taille de la
file. Le reste de la logique est inchangé, y compris les contrôles de statut et
de méta `_dahu_store_credit_processed`.

**`save_coupon_field()` lisait `$_POST` sans vérification.** WooCommerce vérifie
nonce et capacité en amont de ce hook, mais une écriture qui dépend des
contrôles d'une autre extension est une écriture sans contrôle propre — et c'est
systématiquement signalé en revue wordpress.org. Ajout de `current_user_can(
'edit_post' )` et de la vérification du nonce `woocommerce_meta_nonce`.

### Généralisé

| Élément | Avant | Après |
|---|---|---|
| Langue source | Français | Anglais — 35 occurrences traduites (34 chaînes) |
| Licence | GPL v2 or later | MIT |
| `Author URI` | `mailto:hugo@vialjaime.fr` | `mailto:contact@dahu-concept.fr` |
| i18n | Aucun `load_plugin_textdomain()` | Chargé sur `init`, `Domain Path: /languages` |
| Liens d'action | Version **et** Réglages | Réglages seul — la version n'apparaît que dans la ligne méta, conformément à la convention Dahu-Concept |
| Ligne méta | Chaînes françaises en dur, sans version | Version lue depuis l'en-tête, libellés traduisibles |
| Désinstallation | Aucune | `uninstall.php`, mono-site et multisite, avec garde-fou |

### Décision : désinstallation asymétrique

Les réglages et le cache partent toujours. **Les métas de coupon sont
conservées** sauf si le marchand pose explicitement
`dahu_store_credit_remove_data_on_uninstall`.

La raison est différente de celle des autres extensions : supprimer ces métas ne
perdrait pas seulement de la configuration, ça **retransformerait silencieusement
chaque avoir en coupon ordinaire consommé en entier**. Des soldes clients réels
changeraient de comportement sans que personne l'ait demandé.

### Suivi des évolutions Annad

- [ ] (rien à ce jour — portage fait depuis la 1.0.4)

---

## Reste à faire avant soumission

- [ ] **Vérifier le correctif de `get_reserved_amount()` sur une vraie
      boutique.** C'est de la logique financière et il n'a été validé que par
      lecture. Le cas à tester : deux commandes en attente utilisant le même
      avoir, vérifier que la seconde ne peut pas réserver plus que le reste.
- [ ] Vérifier que `order_item_name` contient bien le code du coupon sur une
      installation HPOS comme sur une installation classique.
- [ ] Remplacer le nombre magique `100` par
      `WC_Coupon::E_WC_COUPON_INVALID_REMOVED` dans `customize_coupon_error()`.
- [ ] Captures d'écran `screenshot-1..3.png`.
- [ ] Vérifier la disponibilité du slug `dahu-store-credit` sur wordpress.org.
- [ ] Générer les traductions françaises `.po`/`.mo`.

---

## Contexte du problème

Un coupon WooCommerce de type « Panier fixe » est consommé en entier. Un client
disposant d'un avoir de 100 qui commande pour 40 perd les 60 restants. C'est le
comportement attendu d'un bon de réduction, et le mauvais comportement pour un
avoir, un remboursement ou une carte cadeau.

## Décisions d'architecture

- **Le solde est réécrit sur le même coupon**, pas sur un nouveau code : le
  client garde la référence qu'il connaît.
- **Compteur d'utilisation remis à zéro** et client retiré de `used_by`, sinon
  WooCommerce refuserait la seconde utilisation.
- **Plafonnement en mémoire, jamais persisté** : `cap_balance_to_available()`
  réduit le montant du coupon pour la durée de la requête seulement. Persister
  le plafond corromprait le solde réel.
- **Seuil de 0,01** sous lequel le solde est considéré comme nul, pour éviter
  les reliquats d'arrondi.

## Pièges connus

- `is_dahu_store_credit_coupon()` relit la méta en base quand l'objet
  `WC_Coupon` renvoie une valeur vide : le cache objet reste parfois périmé
  juste après un enregistrement, ce qui produisait des faux négatifs.
- Le cache du montant réservé doit être vidé (`clear_reserved_cache()`) après
  chaque écriture sur le coupon, sinon la validation suivante travaille sur un
  solde périmé pendant 30 secondes.
