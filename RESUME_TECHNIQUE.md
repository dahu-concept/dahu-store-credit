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

## Le registre sur l'avoir — `B′`, le 2026-10-30

La décision qui portait l'interdiction d'installer est prise, et le trou qu'elle
nommait est **mesuré puis fermé**.

### Le défaut, en euros et non en raisonnement

L'avoir et la commande sont deux dépôts qui se sauvegardent séparément. La
trace de ce qui avait été prélevé était écrite sur la **commande**, avant que
l'avoir soit débité. Une requête morte entre les deux laissait une trace
disant « prélevé 40 » devant un avoir encore à 100 :

- le rejeu lisait la trace, sautait l'avoir, et 40 € de marchandise partaient
  contre un avoir qui n'avait rien perdu ;
- l'annulation rendait ensuite 40 € qui n'avaient jamais été pris.

**140 € sur un avoir de 100.** Ce point était écrit dans l'état final du 23,
chiffré, et **aucun banc ne le montait** : un trou décrit dans un document et
absent des bancs est un trou que la prochaine correction rouvre sans que rien
ne rougisse. `_harness/prove-store-credit-register.php` le monte, et il était
rouge sur ces quatre assertions avant la correction.

### Ce qui le ferme

Aucun ordre d'écriture ne répare deux dépôts. Ce qui répare, c'est de mettre
l'enregistrement **dans le même dépôt que l'argent** — exactement le motif qui
avait déjà fermé le côté restitution, où `REFUNDED_META` vit sur l'avoir et est
écrite par la sauvegarde qui le crédite.

- **`TAKEN_BY_META`** sur l'avoir : `array( order_id => montant pris )`, écrite
  par le `$coupon->save()` qui écrit aussi `set_amount()`. L'argent et la trace
  ne peuvent plus se dissocier.
- **`TAKEN_META`** reste sur la commande et devient une **copie d'audit** : un
  marchand ouvre une commande, pas un coupon. Elle n'est plus la source de
  vérité, et elle n'est plus consommée par une restitution — donc la piste
  d'audit survit au remboursement, ce qui était un des points assumés comme
  perdus.
- **`REGISTER_CODES_META`** sur la commande : `array( code => identifiant de
  l'avoir )`, qui dit quels avoirs ont leur registre sur l'avoir.
- Les deux listes de l'avoir deviennent **exclusives** : la restitution retire
  l'entrée du registre, le prélèvement retire la commande de `REFUNDED_META`,
  chacune par la sauvegarde qui déplace l'argent. Pour un avoir donné, une
  commande est soit prélevée, soit remboursée, jamais les deux.

### Pourquoi la bascule porte un identifiant, et pas seulement un code

Trois états se ressemblent et veulent des réponses opposées :

| état | ce qu'il faut faire |
|---|---|
| prélevé par une version antérieure | la trace de la commande gouverne, comme avant |
| avoir supprimé puis recréé sous le même code | l'avoir a bien été prélevé : la restitution doit rendre |
| sauvegarde de l'avoir perdue par une coupure | rien n'a été pris : il n'y a rien à rendre |

Du seul code, les deux derniers sont indiscernables. **Mesuré pendant cette
session** : sans l'identifiant dans la bascule, un avoir de 100 € sur une
commande consommant 120, supprimé, recréé sous le même code et remboursé,
revenait à **120 €** — c'est-à-dire le défaut que le classement par code avait
fermé le 23. L'identifiant de l'avoir dans `REGISTER_CODES_META` les
distingue.

Et la bascule est **par avoir** et non par commande, pour une raison mesurée
elle aussi : une commande à cheval sur deux versions — l'ancienne prélève A, la
requête meurt avant B, mise à jour, le rejeu prélève B — verrait, avec un
drapeau de commande, le registre déclaré compétent pour A aussi, et A, absent
de tout registre, serait prélevé une **seconde** fois.

### Les deux drapeaux décroisés, et la porte ouverte

C'était la décision laissée en attente, écrite en toutes lettres dans le code :
« the refund must clear `_dahu_store_credit_processed` in the same save that
sets its own marker ». Elle est prise, et ce qui la rend sûre est le registre
par avoir.

- Poser un drapeau efface l'autre. L'état terminal `processed = yes` **et**
  `restored = yes`, qui bloquait tout prélèvement comme toute restitution, ne
  peut plus exister.
- `_dahu_store_credit_processed` **n'est plus une porte** en tête de
  `process_order()`. Elle rendait la main avant que la boucle lise quoi que ce
  soit : mesuré sur un cycle sans la moindre interruption — prélevé, annulé,
  repassé en traitement — l'avoir restait entier à 100 € pendant que 40 € de
  marchandise partaient. Et une commande déjà dans l'état terminal, posé par
  une version antérieure, restait bloquée pour toujours : le correctif n'aurait
  réparé que l'avenir.

Les trois rouges volontaires de `prove-store-credit-cycle.php` sont donc
**fermés** : 14 passés, 0 échoué.

### Ce qui est mesuré

| banc | | |
|---|---|---|
| `prove-store-credit-register.php` | **41** | neuf : la fenêtre en euros, le chemin hérité, la commande à cheval, l'identifiant réattribué, l'avoir remplacé, l'historique, le montant réservé, et le balayage |
| `prove-store-credit-restitution.php` | **51** | 47 avant ; quatre assertions réécrites sur le vrai chemin |
| `prove-store-credit-cycle.php` | **14** | 11/3 rouge à dessein avant, désormais fermé |
| `prove-store-credit-wipe.php` | **24** | 14 avant ; le filet sur les constantes |
| `prove-store-credit-carry.php` | 7 | inchangé |
| `prove-uninstall-complete.php` · `prove-submission-ready.php` | 2 · 12 | inchangés |

**151 assertions au total, zéro rouge.**

**Le balayage : 96 rangs de coupure sur 96, écart 0,00 €.** Pour chaque état de
départ (avoir vierge, avoir déjà cyclé), chaque reprise (rejeu, annulation
simple), chaque phase
(prélèvement, restitution), chaque dépôt (la commande, l'avoir) et chaque rang
d'écriture, le banc coupe, rejoue les deux opérations jusqu'au point fixe, et
exige que l'avoir vaille **exactement** ses 100 € de départ. Exactement dans
les deux sens : plus, c'est de la monnaie créée aux frais de la boutique ;
moins, c'est de l'argent pris au client et jamais rendu. Les deux ont été
mesurés sur ce module avant correction.

**Le canari : 12 violations volontaires, 12 attrapées**
(`prove-store-credit-register-canary.py`). Chacune casse une pièce du
correctif et exige que l'assertion **nommée** qui la vise tombe. L'une d'elles
a dû être revisée : viser le premier passage laissait l'assertion verte, parce
qu'au premier tour la bascule est encore vide quand le premier avoir est
examiné — c'est au tour suivant que le double prélèvement apparaît. C'est
l'injection qui l'a dit.

### Ce que la revue adverse a trouvé contre ce correctif

Cinquième relecteur du module, et il a trouvé trois défauts d'argent dans le
correctif lui-même. Les trois sont corrigés et gardés par une injection.

**40 € pris et jamais rendus, définitivement.** `_dahu_store_credit_restored`
n'est effacé que par la sauvegarde d'après-boucle. Sur un **second** cycle —
une commande déjà restituée une fois, donc `restored = yes` — une coupure sur
cette sauvegarde laissait l'avoir débité, le registre écrit, et la restitution
rendait la main sur ce drapeau. Aucun rejeu ne réparait : le prélèvement
sautait l'avoir sur la foi du registre, donc l'après-boucle n'était plus jamais
atteinte et le drapeau restait rance pour toujours. Même remède que pour
`processed`, et même raison : `REFUNDED_META`, porté par l'avoir, donne déjà
l'idempotence avoir par avoir.

**Et c'est mon balayage qui ne le voyait pas.** Il montait toujours un avoir
**vierge**, où un drapeau rance ne peut pas se produire. La leçon vaut au-delà
de ce module : *un balayage exhaustif sur un seul état de départ n'est pas
exhaustif*. Il monte désormais deux états — vierge et déjà cyclé — et passe de
24 à **48 rangs**, dont les deux qui fuyaient.

**L'historique du prélèvement recevait la ligne et non ce qui a été pris.** Un
prélèvement écrête : une ligne de 120 € sur un avoir de 100 en prend 100.
L'historique écrivait « consommé 120, nouveau solde 0 » — faux de 20 €, et ne
se sommant plus au solde posé juste à côté. Le côté restitution interdit déjà
exactement cela, par écrit.

**Le montant réservé se comptait deux fois dans la fenêtre.**
`cap_balance_to_available()` décidait de « déjà prélevée » sur le drapeau, qui
est écrit après la boucle qui déplace l'argent : entre les deux, la ligne était
retirée du solde une seconde fois et le client voyait moins d'avoir qu'il n'en
avait. La question se pose désormais au registre, qui ne peut pas être en
retard sur l'argent puisqu'il part dans la même sauvegarde.

### La sixième revue, et le défaut qui ne demandait aucune interruption

Sixième relecteur, et il a trouvé que j'avais fermé la fenêtre **d'un seul
côté**.

La restitution retombait sur la ligne du coupon dès que la trace était muette,
parce que deux cas passaient par la même porte : « trace illisible » — où
sur-payer en le disant vaut mieux qu'un remboursement silencieux — et « jamais
prélevé ». Le prélèvement, lui, lit déjà ce silence correctement. **L'asymétrie
était tout le défaut**, et elle était de moi : j'avais ajouté le choix de la
source sans toucher au repli.

Quatre formes mesurées :

| scénario | avant | après |
|---|---|---|
| coupure sur le débit, puis la boutique **annule sans rejouer** | 140 € | 100 € |
| la même, sur une ligne de 120 € | 220 € | 100 € |
| une commande à **trois** avoirs, coupée sur le premier débit | 130/140/150 € | 100 € chacun |
| **sans aucune interruption** : un second coupon qui n'était pas un avoir au prélèvement et qui l'est devenu | 145 € | 100 € |

Le dernier est le plus inquiétant : il ne demande ni coupure, ni édition de
base, ni rien d'anormal. Une case cochée après coup suffisait.

Le correctif distingue **absent** de **illisible** : `null` veut dire « rien ne
revendique de prélèvement pour cet avoir sur cette commande », donc rien n'est
dû ; une clé qui existe avec une valeur illisible veut dire « la commande
revendique un prélèvement et le montant est perdu », et le sur-paiement
documenté, annoncé par une note, s'applique alors comme avant.

**Et c'est encore mon balayage qui ne le voyait pas.** Sa reprise rejouait
toujours le prélèvement avant d'annuler, donc elle réparait la coupure avant de
mesurer la restitution. *Un balayage dont la reprise répare avant de mesurer ne
mesure que sa propre reprise.* Il joue désormais les deux séquences — rejeu et
annulation simple — et passe de 48 à **96 rangs**.

### Trois points de la sixième revue laissés tels quels, et pourquoi

**`restore_order()` n'a pas de garde `is_enabled()`** alors que
`process_order()` refuse de prélever module éteint. L'asymétrie est réelle et
elle est dans le bon sens : une boutique qui éteint le module ne doit pas
piéger l'argent de ses clients dedans. Aucune fuite mesurée.

**Rien n'est accroché à `woocommerce_order_refunded`** — un remboursement
**partiel** — ni à `order_status_failed` : un avoir débité y reste débité.
C'est un manque de fonctionnalité et non un défaut d'argent, et l'ajouter
demande de décider ce qu'un remboursement partiel doit rendre d'un avoir, ce
qui est un arbitrage commercial.

**`$coupon->update_meta_data( 'used_by', … )`** aux deux endroits où il
apparaît : le module écrit une méta `used_by` mais relit par `get_used_by()`,
un accesseur de propriété que le magasin de WooCommerce hydrate depuis des
lignes `_used_by`. L'écriture est donc probablement **inerte**, et elle crée
une méta parasite absente de `uninstall.php`. **Non vérifié sans WooCommerce
réel**, et les bancs ne peuvent pas le voir : leur doublure fait lire et écrire
le même emplacement, et aucun ne peuple jamais `used_by`, donc les deux blocs
y sont du code mort. À vérifier sur une vraie installation avant la soumission.

### Un cas épinglé et non corrigé, parce que le corriger prendrait l'argent au client

Une boutique supprime un avoir et le recrée sous le même code, à 100 €. La
commande dit qu'elle a pris 40 €. La restitution rend donc 40 € à l'avoir neuf,
qui n'a jamais rien prêté : **140 €**, et **200 €** avec une ligne de 120 € sur
un avoir de 100.

**Mesuré aussi sur la version d'avant ce correctif : mêmes montants.** Ce n'est
donc pas le registre qui l'introduit, et c'est ce qui décide de la réponse.
Refuser de créditer un avoir dont l'identifiant a changé perdrait le
remboursement légitime du client, pour un acte de comptabilité qui n'est pas le
sien. Rien ici ne peut savoir ce que l'avoir recréé était censé porter — c'est
la boutique qui a choisi ce solde.

Donc : la restitution rend ce qui a été pris, écrêté, et **le dit sur la
commande** — « the credit charged on this order has been deleted and recreated
since […] it may now hold more than it ever lent ». Le silence était tout le
défaut ; il est levé. Trois assertions l'épinglent, avec les montants.

### Deux choses trouvées en chemin, et qui n'étaient pas dans le plan

**La désinstallation ne connaissait pas mes deux clés neuves**, et
`prove-store-credit-wipe.php` était **vert par ignorance** — exactement le
défaut que ce banc avait déjà eu en 1.1.2 avec `_dahu_store_credit_refunded_orders`.
Deux fois le même défaut, c'est une liste à la main de trop : le banc lit
désormais les **constantes** de la classe au tokenizer et exige que chacune
dont le nom finit par `_META` apparaisse dans `uninstall.php`. Une clé neuve
que personne n'ajoute fait rougir le banc, sans que personne ait à y penser.

**Mes commentaires étaient en français**, et `prove-comments-english.py` les a
attrapés : treize blocs, traduits. La règle du chantier tient sans qu'on ait à
s'en souvenir, ce qui est tout l'intérêt d'un harnais.

## La septième revue, et pourquoi la structure a été réécrite

Trois revues de suite ont trouvé de l'argent réel dans la même fonction. À la
troisième, le problème n'était plus la ligne fautive : c'était la structure.

La septième revue a trouvé deux défauts GRAVES, et son analyse de cause valait
plus que les deux correctifs :

> Un registre qui *parle* est une preuve inconditionnelle que l'argent a bougé ;
> la méta de la commande n'est nécessaire que quand il se *tait*.

Ma version faisait dépendre une preuve inconditionnelle de la survie d'un
enregistrement sans rapport (`REGISTER_CODES_META`, porté par la commande).
Quand cet enregistrement disparaissait — un import qui re-sérialise mal les
tableaux de la commande, le scalaire `processed` survivant — **les deux côtés
lisaient le même état de deux façons fausses** :

- la restitution ne trouvait aucun enregistrement digne de foi, concluait que
  rien n'était dû, et **gardait 40 € au client** sans aucune note ;
- le prélèvement ne trouvait rien non plus et **reprenait les mêmes 40 €**,
  puis réécrivait le registre : la restitution n'en rendrait jamais qu'un seul.

Et le premier défaut : `restore_order()` consultait la ligne du bon
(`if ( $consumed <= 0 ) continue;`) **avant** de lire le registre. Un
administrateur qui recalcule une commande et en retire la ligne du bon, puis
l'annule : registre à 40, ligne à 0, **40 € gardés au client**, zéro note, zéro
ligne d'historique, et l'entrée de registre toujours en place.

### Ce qui a été réécrit

Un résolveur unique, `charge_record()`, appelé par les deux côtés, qui rend un
couple : le montant enregistré — ou `null`, qui veut dire « rien ne revendique
de prélèvement » et n'est **pas** la même chose qu'un enregistrement illisible
— et le fait que l'avoir ait été remplacé depuis.

### Le banc avait raison contre la revue

La règle « le registre parle, point » ne suffisait pas, et c'est une assertion
à moi qui l'a dit : `TAKEN_BY_META` est indexée par identifiant de commande, et
un identifiant **peut revenir** (import, renumérotation, dump partiel
restauré). L'entrée seule ne peut donc pas dire si elle parle de cette commande
ou d'une homonyme renumérotée — et les deux veulent des réponses opposées.
Mesuré dans les deux sens : lue comme « prélevée », une commande homonyme
neuve passe sans payer ; lue comme « non prélevée », la commande dont les métas
ont été perdues est prélevée deux fois.

Aucune lecture d'un enregistrement à un seul état ne peut être juste pour les
deux. D'où `TAKEN_KEYS_META` (`order_id => order key`), portée par l'avoir et
écrite dans le **même `save()`** que le solde et que la carte des montants.
`get_order_key()` est posée à la création et survit à une renumérotation, ce qui
est exactement la propriété qui manque à l'identifiant. Un vide d'un côté ou de
l'autre n'est pas un désaccord : seules deux clés présentes et différentes
veulent dire « une autre commande ».

### Et un défaut que j'ai introduit pendant la réécriture

En supprimant l'ancien bloc d'autorité, j'ai supprimé les lectures de
`$register`, `$on_credit` et `$taken_map` dont les **écritures** plus bas
dépendaient. En PHP, `$map[$k] = $v` sur une variable indéfinie crée un tableau
d'un seul élément : l'écriture aurait **silencieusement effacé les entrées des
autres commandes**, qui redevenaient prélevables et remboursables. Attrapé par
relecture des usages avant toute exécution, et désormais **mesuré** : un avoir
porté par deux commandes, la première remboursée, et l'entrée de la seconde
doit survivre.

### Le solde juste sur un avoir mort

Le prélèvement ne repousse la péremption que s'il reste quelque chose à
dépenser. Un avoir consommé au dernier centime gardait donc sa date : annuler
cette commande rendait **100 € sur un avoir périmé**, dont pas un centime
n'était dépensable. Le solde était exact et le client était toujours privé de
son argent. La restitution repousse maintenant la date comme le prélèvement le
fait, et ne la ramène jamais en arrière. Sans fenêtre configurée il n'y a
aucune date à inventer : le module le **dit sur la commande**.

### Mesure

- **166 assertions** vertes : `register` 57, `restitution` 51, `wipe` 25,
  `cycle` 14, `carry` 7, `submission-ready` 12.
- **19 injections sur 19 attrapées**, dont dix nouvelles visant chacune une
  pièce de cette réécriture.
- **96 rangs de coupure** à un écart de 0,00 €.
- 58/58 chaînes traduites.

Une vingtième injection a été **écrite, mesurée, puis retirée** : retirer la
garde de vérité `$claimed &&` laissait les assertions vertes, parce que l'état
qu'elle protège (avoir introuvable) sort toujours de la boucle par une note,
dans les deux sens. La garde reste dans le code — elle dit l'intention — mais
elle ne prouve rien, et la raison est écrite dans le fichier d'injections
plutôt que laissée sous forme de rouge connu. Une suite qui porte un rouge
connu apprend à lire les rouges comme du bruit.

### Une fixture à moi qui mentait

Quatre assertions du banc de restitution ont échoué après la réécriture, et
elles avaient tort. Elles prétendaient mesurer « le chemin hérité » — une
commande prélevée par une version antérieure — mais fabriquaient cet état en
lançant le prélèvement **courant** puis en trafiquant la seule commande.
L'avoir gardait donc son registre moderne : un état qui n'a jamais existé. Avec
un registre désormais inconditionnel, refuser le second prélèvement était la
bonne réponse pour cet état-là. La fixture efface maintenant aussi les cartes
portées par l'avoir, ce que « version qui n'écrivait pas de registre » veut
dire.

## La huitième revue : le côté restitution était encore gouverné par la commande

La réécriture avait honoré sa propre promesse d'un seul côté. Quatre défauts,
tous reproduits par mes propres fixtures avant d'être corrigés.

### Deux GRAVES, une seule racine

**La boucle suivait les lignes de la commande.** Retirer un coupon d'une
commande dans l'administration WooCommerce ne met pas sa ligne à zéro : elle
**supprime l'item**. La boucle ne tournait alors pas une seule fois, le registre
n'était jamais lu, et l'annulation ne rendait rien — **40 € gardés au client**,
sans note, sans historique, l'enregistrement du prélèvement toujours lisible.
La garde déplacée *dans* la boucle ne protège rien quand c'est la boucle qui
disparaît. La restitution parcourt maintenant **l'union** des lignes de coupon
et de tous les codes nommés par les deux cartes de la commande.

**La porte d'entrée jugeait sur la commande.** Ses deux conditions — le drapeau
et la copie d'audit — sont des métas de **commande**, précisément le dépôt que
ce module déclare non fiable. Un import qui perd les métas d'une commande
fermait donc la porte alors que le registre de l'avoir disait en clair que 40 €
avaient été pris : **40 € gardés au client**, sans note, tandis que le
prélèvement, lisant le MÊME état, croyait le registre et refusait correctement
de reprélever. Un état, deux lectures opposées, et l'argent tombe dans
l'intervalle. Plus rien n'est décidé à la porte : la boucle tourne et le
résolveur décide, avoir par avoir. Le commentaire qui annonçait déjà avoir
fermé ce défaut était la même leçon arrivée une première fois, à moitié.

### Le prélèvement raccourcissait la vie de l'avoir

`set_date_expires()` était posé **inconditionnellement**. Un avoir valable un
an, dépensé contre une commande avec une fenêtre de trente jours configurée,
voyait son reliquat périmer dans trente jours au lieu de trois cent
soixante-cinq : **335 jours de crédit détruits**, mesuré. Et un avoir
perpétuel se voyait **inventer une date de mort**, alors que la restitution
refuse explicitement d'en inventer une.

Ce défaut n'est devenu visible que parce que la restitution venait d'apprendre
à ne jamais ramener une date en arrière : **l'asymétrie était la preuve**. Le
réglage s'appelle une extension, donc il étend et ne raccourcit jamais, et il
n'a rien à dire d'un avoir qui ne périme pas.

### Deux notes qui mentaient

Le résolveur sait deux choses que le côté prélèvement jetait : que l'avoir a
été remplacé depuis, et qu'il a cru une entrée faute de pouvoir la rattacher à
la commande. Il écrivait donc « déjà prélevé sur CET avoir » à propos d'un
avoir qui n'avait jamais rien prêté. Chacun des deux cas a maintenant sa note,
des deux côtés — et au remboursement la clémence **donne** de l'argent, ce qui
mérite d'autant plus d'être dit.

Le prix de cette clémence est désormais écrit : une commande sans clé et un
identifiant réattribué sont indiscernables. Croire l'entrée laisse partir la
marchandise ; ne pas la croire fait payer le client deux fois. Le module
protège l'argent du client et **annonce** l'exposition de la boutique.

### Un double plus strict que la réalité

Une de mes assertions échouait pour une raison qui n'était pas le code :
`wc_get_coupon_id_by_code()` du banc comparait les codes **sensiblement à la
casse**, là où WooCommerce normalise et où la collation MySQL ignore la casse.
Un double plus strict que la réalité fabrique des échecs, et il en avait
fabriqué un. Corrigé dans les quatre bancs.

### Et une assertion à moi devenue obsolète

Une assertion mesurait le **silence** de la commande homonyme — silence qui
n'était pas une propriété, mais le symptôme de la porte que je venais de
retirer. Elle a été inversée : la commande homonyme **le dit** maintenant, ce
qui est exactement ce que la revue qui avait demandé cette note voulait.

### Mesure

- **174 assertions** vertes : `register` 65, `restitution` 51, `wipe` 25,
  `cycle` 14, `carry` 7, `submission-ready` 12.
- **24 injections sur 24 attrapées**, dont trois ré-visées parce que le code
  qu'elles ciblaient avait changé — une injection qui ne s'applique plus est un
  contrôle muet, pas un contrôle réussi.
- 61/61 chaînes traduites.

## La neuvième revue : la clémence coûtait des deux côtés

### GRAVE — croire sur un doute ne protège pas le client, il finance un autre

J'avais justifié le versement sur un enregistrement non rattachable par « ne
pas garder l'argent d'un client sur un doute ». La revue a mesuré le contraire :
dans cet état, l'argent **n'est pas celui de ce client**, c'est le prélèvement
d'une autre commande. Une commande renumérotée qui reprend un identifiant et ne
produit pas de clé voyait son prélèvement sauté (40 € de marchandise, prix déjà
connu) **puis sa restitution rendre les 40 € de l'ancienne commande** : l'avoir
remontait à 100 € pendant que **80 € de marchandise** étaient sortis sur une
ligne de 40 €.

La réparation remplace la clémence aveugle par une **corroboration** : une
commande qui a seulement *perdu* sa clé porte encore ses propres traces du
prélèvement ; une commande neuve qui a repris l'identifiant ne porte rien. Ce
n'est pas de la clémence, c'est une question mieux posée, et elle sépare les
deux états dans tous les cas sauf un — une commande qui a perdu sa clé **et**
ses traces, annoncée plutôt que devinée.

Et les deux appelants veulent désormais des réponses **opposées** sur cet état,
ce qui n'est pas une incohérence : croire coûte sa marchandise à la boutique,
verser crée de l'argent. Le prélèvement croit (il ne prélèvera jamais deux
fois) ; la restitution refuse et le dit.

### Quatre notes qui mentaient, et une qui se répétait

Les notes « avoir remplacé » et « non rattachable » étaient écrites **avant**
les deux gardes qui décident s'il y a quelque chose à rendre : elles
affirmaient donc un versement dans des états où 0,00 € bougeait. Chaque note
est maintenant écrite **après la décision qu'elle décrit**.

La note « coupon introuvable » était un `add_order_note()` nu, et la porte
d'entrée que je venais de retirer masquait ce que ça coûtait : cinq passages
sur une commande qui n'avait jamais rien pris écrivaient cinq fois la même
ligne — exactement la régression que `say_once()` existe pour empêcher,
revenue par la nouvelle voie d'entrée.

Enfin, une trace illisible dont la ligne de coupon avait été retirée ne rendait
rien **et ne disait rien** : il n'y a aucun montant lisible nulle part, donc
rien ne peut être rendu, mais le silence n'était pas acceptable.

### Un défaut que j'ai introduit, posé et retiré dans le même tour

En lisant le point précédent j'ai cru devoir exiger un **silence concerté** :
ne conclure « jamais prélevé » que si la trace de la commande se tait aussi.
C'était faux, et le balayage l'a chiffré en une seconde : **140 € sur un avoir
de 100**, au rang `coupon` 1 de `process_order`.

La raison est l'asymétrie même du dispositif : **la commande écrit en premier**,
délibérément, pour qu'un prélèvement soit enregistré avant d'avoir lieu. Sa
trace peut donc revendiquer ce qui n'est jamais arrivé. L'avoir écrit en
dernier et ne peut pas se tromper. Une injection garde maintenant ce piège
fermé.

### Et ma fixture s'est trompée une seconde fois sur « version antérieure »

La bascule `REGISTER_CODES_META` n'est écrite que par une version qui tient un
registre. La garder dans une fixture « héritée » fabrique un état qui n'existe
pas — et dans lequel le silence du registre veut justement dire « jamais
prélevé ». Deuxième fois sur ce même piège ; c'est noté ici pour qu'il y en ait
une troisième de moins.

### Mesure

- **184 assertions** vertes : `register` 75, `restitution` 51, `wipe` 25,
  `cycle` 14, `carry` 7, `submission-ready` 12.
- **32 injections sur 32 attrapées**. Six ont dû être ré-visées parce que le
  code qu'elles ciblaient avait bougé — dont deux des miennes mal visées, l'une
  modifiant une ligne qui ne s'exécute jamais. Une injection qui ne s'applique
  plus est un contrôle muet, pas un contrôle réussi.
- 63/63 chaînes traduites, dont une entrée orpheline retirée du catalogue.

## La dixième revue : la corroboration était un retour en arrière déguisé

### GRAVE — 40 € détruits : j'avais remis la preuve sous tutelle

La corroboration que la neuvième revue m'avait fait écrire demandait à la
commande d'arbitrer **chaque fois** que les deux clés n'étaient pas
comparables. C'est-à-dire exactement ce que la septième revue avait fermé :
une preuve inconditionnelle remise à la merci de métas de commande. Mesuré :
avec une carte des clés abîmée et les métas de la commande parties, **40 € du
client détruits**, et la commande devenait *ni prélevable ni restituable* —
aucun rejeu ne réparait.

Le discriminant n'est pas qu'une clé manque, **c'est pourquoi** :

- la carte **ne connaît pas** l'entrée → aucune renumérotation ne peut s'y
  cacher, puisque c'est précisément ce que la carte sert à enregistrer. Le
  registre est cru, comme avant qu'elle existe ;
- la carte **connaît** une clé et la commande n'en produit pas → c'est le seul
  vrai signal de réutilisation, et c'est là, et seulement là, que la
  corroboration vaut d'être demandée.

### MOYEN — deux lecteurs d'un même enregistrement qui divergeaient

`get_reserved_amount()` ne comparait que l'identifiant de commande. Une entrée
laissée par une commande disparue excluait donc une commande **en attente**
homonyme du montant réservé — **0 € au lieu de 30 €** — alors que
`charge_record()` tenait cette même commande pour prélevable. Le client pouvait
engager ailleurs un solde déjà promis. Les deux lisent maintenant par
`register_verdict()`, une seule méthode : c'est la seule façon qu'ils ne
redivergent pas.

### MOYEN — le marqueur des notes n'était jamais persisté sur un refus

`say_once()` posait `SAID_META`, mais les deux appelants ne sauvegardaient la
commande que sous `if ( $processed )` et `if ( $restored )`. Un passage qui ne
faisait **que refuser** ne persistait donc jamais son marqueur : cinq requêtes,
cinq notes identiques. La régression que `say_once()` existe pour empêcher,
revenue par la porte de *qui sauvegarde*. Il sauvegarde maintenant lui-même.

### Et le banc était plus PERMISSIF que la réalité

Le double `WC_Order` gardait son tampon d'écritures en attente d'un appel à
l'autre, et `get_meta()` le lit avant les métas sauvegardées — ce qui est juste
*dans* une requête. Mais le banc ne savait pas franchir une frontière de
requête, donc une méta posée et jamais sauvegardée restait lisible pour
toujours : **un marqueur jamais écrit paraissait écrit**. C'est le miroir exact
du double trop strict trouvé deux revues plus tôt, et le même aveuglement que
celui déjà consigné pour le drapeau rance. Le double sait maintenant
`nouvelle_requete()`.

### Mesure

- **190 assertions** vertes : `register` 81, `restitution` 51, `wipe` 25,
  `cycle` 14, `carry` 7, `submission-ready` 12.
- **37 injections sur 37 attrapées**. Huit ont dû être ré-visées après la
  refonte en une seule méthode de lecture.
- 96 rangs de coupure à 0,00 € d'écart.

## La onzième revue : rien de GRAVE, pour la première fois

Cinq revues de suite avaient trouvé de l'argent réel. Celle-ci n'a rien trouvé
de grave : les deux invariants ont tenu sous plus de treize cents exécutions,
dont un fuzz de six cents cycles longs, cinq cent soixante-seize **paires** de
coupures consécutives, et des frontières de requête aléatoires. Deux défauts
MOYENS, tous deux périphériques — et c'est ce qui rend leur correctif
intéressant : **chacun retire une dépendance aux métas de commande au lieu d'en
ajouter une.**

### Une note écrite une sauvegarde trop tôt

Les notes avaient été déplacées « après la décision qu'elles décrivent ». Ce
n'était pas assez : elles restaient avant le seul `save()` qui déplace
l'argent. Une coupure sur celui-ci laissait l'avoir intact — **0 € rendus sur
40 €** — et la commande affirmant que le montant avait été rendu. Et comme
`say_once()` sauvegarde désormais son propre marqueur, cette fausse
affirmation devenait **durable**.

C'est la régression que le commentaire de la note `replaced-` déclarait avoir
fermée, déplacée d'un cran plutôt que supprimée. Les notes suivent maintenant
l'argent, et le bon sens de l'erreur est inversé : une coupure perd une note
vraie au lieu de persister une note fausse — pour la même raison que la copie
d'audit est écrite tôt. Sur-revendiquer se répare ; un faux enregistrement de
versement, non.

### Un lecteur qui ne suivait pas le verdict jusqu'au bout

Sur le verdict `unmatched`, le prélèvement **croit** le registre et refuse de
redébiter : l'argent est déjà retiré du solde. `get_reserved_amount()`
comptait pourtant la même ligne en réservé, amputant le solde une seconde
fois. Mesuré : un avoir détenant réellement 60 € n'en offrait que **20 €**, et
avec une commande en attente de plus le disponible passait négatif — **l'avoir
était refusé au panier** alors que le client en détenait 60.

`other-order` reste compté, et la distinction est tout le propos : là, la ligne
appartient à une commande réellement neuve que rien n'a prélevée. Un témoin
l'interdit de « corriger » en sortant sur tout verdict.

### Mesure

- **198 assertions** vertes : `register` 88, `restitution` 51, `wipe` 25,
  `cycle` 14, `carry` 7, `submission-ready` 12.
- **40 injections sur 40 attrapées**.
- 96 rangs de coupure à 0,00 € d'écart.

### Le compromis assumé, re-mesuré

La sauvegarde que `say_once()` fait lui-même élargit d'un rang la fenêtre dans
laquelle une note peut être écrite deux fois (mesuré : un rang sur seize, 0 €
d'écart). C'est le côté inoffensif de l'échange, déjà écrit dans le docbloc :
une note en double se lit, une note perdue ne se lit pas.

## La douzième revue : rien de GRAVE, et deux préjudices qui se contredisent

Deuxième revue de suite sans rien de grave. **0 €** créé ou détruit sur
2 304 paires de coupures consécutives exhaustives, 3 200 cycles fuzzés avec
frontières de requête, et 441 combinaisons de valeurs hostiles registre × trace.

### Un vocabulaire plus pauvre dans un lecteur que dans l'autre

`charge_record()` calculait la corroboration et `get_reserved_amount()` la
jetait : le panier ne savait donc pas distinguer une commande qui a **perdu**
sa clé (argent déjà retiré, ne pas réserver) d'une commande **renumérotée
distincte** (créance neuve que rien ne prélèvera). Mesuré : **60 € de
marchandise** sortant contre un avoir que le prélèvement refuse de débiter.

La corroboration a donc été remontée dans `register_verdict()` — la lecture
unique — qui rend `speaks` quand elle tient et `unmatched` sinon. Cela
**supprime** du vocabulaire au lieu d'en ajouter : les deux appelants ne
peuvent plus diverger, et la commande n'y sert qu'à *soupçonner*, jamais à
autoriser. Un échec de corroboration peut faire réserver davantage ou verser
moins, jamais verser plus : la primauté du registre est intacte.

### Deux revues qui se contredisent, et l'arbitrage écrit

Les deux défauts MOYENS de cette revue tiraient en sens opposés, et le second
contredisait un correctif de la onzième :

- **ne pas réserver** une ligne non corroborée : 60 € de marchandise sortent
  sans contrepartie. Préjudice **boutique**, irrécupérable.
- **la réserver** : un avoir détenant réellement 60 € n'en offre que 20, et
  peut être refusé au panier. Préjudice **client**, récupérable par un
  administrateur.

Les deux mesures sont justes : c'est l'**état** qui est fautif, puisque le
prélèvement refusera cette ligne quoi qu'il arrive. On retient le sens
récupérable — on réserve, donc on bloque — et la commande porte déjà une note
qui dit pourquoi. **Ce n'est pas un fait de correction mais un arbitrage**, il
est écrit comme tel dans le banc avec les deux chiffres, et les deux sens sont
tenus par un témoin chacun. Si Hugo préfère l'autre sens, c'est une ligne à
changer et une assertion à inverser.

### Une assertion qui ne mesurait pas ce qu'elle croyait

Une injection a montré que le témoin « clé perdue mais traces intactes » était
sauvé par le drapeau `_dahu_store_credit_processed`, pas par la corroboration.
La fixture retire maintenant ce drapeau — état légitime, c'est la fenêtre même
pour laquelle le registre existe.

### Et une prose qui se lisait comme un trou ouvert

Le docbloc de `TAKEN_BY_META` raconte, au passé, le défaut de 140 € qui a
motivé sa création. Un relecteur attentif l'a lu comme une description du code
actuel. Le paragraphe dit maintenant explicitement qu'il s'agit d'histoire, et
que l'état décrit est mesuré à 0,00 € d'écart. Une prose qui se lit comme un
défaut vivant coûte une journée de relecture.

### Mesure finale

- **198 assertions** vertes : `register` 89, `restitution` 51, `wipe` 25,
  `cycle` 14, `carry` 7, `submission-ready` 12.
- **42 injections sur 42 attrapées**.
- 96 rangs de coupure à 0,00 € d'écart.
- 63/63 chaînes traduites.

**Le cycle de revues s'arrête ici** : deux revues consécutives sans rien de
grave, ce qui était la règle d'arrêt annoncée. Ce qui reste est épinglé, chiffré
et argumenté plus haut.

## Les deux questions épinglées « non vérifié sans WooCommerce réel » — closes

Elles traînaient depuis des jours. Le banc **est** un WooCommerce réel
(WordPress 7.1.3 + WooCommerce 11.1.2) : elles n'étaient pas difficiles, elles
n'avaient simplement pas été posées. `_bench/t-store-credit-reel.php` les pose
maintenant à chaque exécution, et `_bench/prove-store-credit-reel.py` prouve
qu'il sait refuser (3 injections sur 3).

### `get_reserved_amount()` : juste, aucun défaut

60 € détenus, 30 € et 20 € promis par deux commandes en attente → le panier en
offre **10 €**. Mesuré, correct. L'épingle tombe sans correctif.

### `used_by` : pas inerte, un vrai défaut fonctionnel

L'épingle disait « probablement inerte et crée une méta parasite ». La mesure a
dit pire que ça, et dans les deux sens :

- l'extension écrivait une méta `used_by` **sans souligné** ; WooCommerce tient
  cette liste dans des lignes `_used_by`, une par usage, et ne lit jamais la
  clé sans souligné. La méta était donc un parasite ;
- et le retrait qu'elle croyait effectuer **n'avait jamais lieu** :
  `get_used_by()` rendait toujours le client après le prélèvement.

Conséquence réelle, et non théorique : un avoir portant
`usage_limit_per_user` **refusait au client son propre solde restant**, sans
que rien ne dise pourquoi.

`decrease_usage_count()` a été la correction suivante, et elle a été mesurée
aussi : elle déplace le compteur et **ne retire aucune ligne `_used_by`**, dans
un ordre comme dans l'autre. WooCommerce n'expose aucune API qui le fasse.
Expédier un appel incapable de faire ce qu'il annonce aurait été un contrôle
inerte — exactement ce que ce harnais refuse partout ailleurs.

La vraie réponse est le filtre `woocommerce_coupon_validate_user_usage_limit`,
que WooCommerce pose autour de cette vérification : `allow_repeat_use()` y
répond que **la limite d'un avoir est son solde, pas un nombre d'usages**. Cela
dit la règle au lieu d'effacer ses traces.

Mesuré sur le vrai WooCommerce, avec son témoin :

| | limite par client = 1, déjà utilisé une fois |
|---|---|
| avoir | **accepté** — le client peut dépenser le reste |
| coupon ordinaire | **refusé** — « Usage limit for coupon has been reached » |

Le témoin compte autant que l'assertion : sans lui, le vert serait identique si
l'extension avait désarmé la limite pour tous les coupons du site.

## Remboursement partiel et commande en échec — les deux arbitrages tranchés

Hugo a tranché les deux crochets laissés ouverts. Les voici, et le raisonnement
qui a décidé de la part, parce qu'il a changé en route.

### La part de l'avoir dans un remboursement partiel

**Premier raisonnement, faux.** J'avais conçu « l'avoir absorbe ce qui dépasse
la part payée en caisse ». Il ne peut rien dépasser : un coupon `fixed_cart`
baisse le panier, donc le total de la commande est **déjà net de l'avoir**. Un
panier de 100 € avec un avoir de 40 € donne une commande de **60 €**, et
WooCommerce ne laissera jamais rembourser plus que ces 60 €. Le débordement que
ce calcul attendait est arithmétiquement impossible.

**Ce que dit vraiment un remboursement de 15 € sur 60 €** : un quart de la
commande est rendu. Un quart des marchandises avait été payé par l'avoir, donc
un quart de ce que l'avoir a prêté est dû — 10 €. D'où
`part = min( 1, rendu / total )` puis `du = min( taken, taken × part )`.

Et le calcul **dégénère juste** : rembourser les 60 € de caisse rend les 40 €
d'avoir, ce que le statut « remboursée » faisait déjà. Les deux chemins se
rejoignent au lieu de se contredire.

### Une seule règle d'idempotence pour les deux chemins

`GIVEN_BACK_META`, porté par l'**avoir**, `order_id => montant déjà rendu`, lu
avant tout mouvement et écrit par le `save()` qui le fait. Le montant dû est
toujours `min( taken, part ) − déjà_rendu` : jamais plus que ce qui a été pris,
jamais deux fois.

**Et effacé au prélèvement**, dans le même `save()` que le reste. C'est le
balayage qui l'a exigé : sans cet effacement, la trace du cycle précédent
survivait et le remboursement suivant concluait que la dette était soldée —
**un second remboursement rendant 0 €, aux 96 rangs de coupure**. C'est le
miroir exact de l'effacement de `REFUNDED_META`, et pour la même raison : les
deux décrivent le cycle d'avant.

### Une convergence qui a tué une branche

J'avais écrit dans `restore_order()` une branche « déjà soldé par acomptes »
qui libérait la commande sans rien rendre. Puis le chemin partiel a reçu la
même règle — **une commande est libérée exactement quand la totalité de son
prélèvement est revenue** — et il libère lui-même, en posant `REFUNDED_META`.
La garde du début de boucle répond donc la première, et elle dit quelque chose
de vrai. La branche est devenue inatteignable, et **du code inatteignable est
pire que pas de code** : rien ne peut prouver qu'il marche encore. Retirée.

### Cinq injections ont montré que mes assertions ne mesuraient pas ce qu'elles disaient

C'est le fait le plus utile du lot :

1. la soustraction `taken − déjà` était en fait protégée par `REFUNDED_META`
   dans mon scénario ; il fallait un acompte **partiel** pour l'atteindre ;
2. le refus de reprendre l'avoir était protégé par la garde `rendu <= 0`, pas
   par celle que je visais ;
3. l'assertion sur la commande en échec appelait `restore_order()` **à la
   main** : elle serait restée verte si le crochet avait disparu. Les doublures
   **enregistrent** maintenant les crochets, et cinq assertions mesurent leur
   pose ;
4. la garde du total nul ne protège aucun solde : elle protège d'une **division
   par zéro** qui tue le processus et emporte les autres avoirs de la boucle.
   L'assertion mesure donc désormais que la méthode rend la main ;
5. la borne sur la part était redondante pour le montant (`du` borne déjà) :
   ce qu'elle protège, c'est la **note** lue par le marchand, qui annoncerait
   « 200 % de cette commande ». L'assertion porte sur la note.

### Et le canari mentait sur la nature d'un échec

Il imprimait « l'assertion nommée est restée verte » alors que la suite était
**morte avant de mesurer**, sur une erreur fatale. Deux échecs très différents,
affichés comme un seul — et cette confusion m'a coûté une demi-heure. Il lit
maintenant la sortie d'erreur et distingue « restée verte » de « jamais
atteinte », en citant l'erreur fatale.

### Mesure

- **214 assertions** vertes : `register` 116, `restitution` 51, `wipe` 26,
  `cycle` 14, `carry` 7.
- **52 injections sur 52 attrapées**.
- 96 rangs de coupure à 0,00 € d'écart.
- **Vrai WooCommerce : 9 / 9**, dont le remboursement partiel déclenché par
  `wc_create_refund()` — le crochet de WooCommerce, et non un appel direct.

## La revue adverse des deux crochets : GRAVE, et le point aveugle était dans le banc

### Le dénominateur n'était pas le bon, et le cas est le plus banal du commerce

J'avais pris `$order->get_total()` comme assiette de la part. **Un total de
commande, c'est la marchandise PLUS le port, les frais et la taxe** — et un
coupon `fixed_cart` ne touche jamais le port. Les deux membres du rapport ne
mesuraient donc pas ce que l'avoir avait prêté.

Le déclencheur est le geste commercial le plus courant du détail : le colis est
en retard, on rembourse les frais de port.

| état | attendu | obtenu | écart |
|---|---|---|---|
| panier 100 € payé entièrement par l'avoir, port 10 € remboursé | 0 € rendus | **100 €** | **+100 € créés** |
| panier 100, avoir 40, port 10 remboursé | 60 € | 65,71 € | +5,71 € créés |
| tous les articles rendus, port gardé | 100 € | 94,29 € | **−5,71 € retenus au client** |
| idem avec TVA sur le port | 100 € | 93,33 € | −6,67 € retenus |

Et le sens qui coûte au client était **définitif et muet** : un retour complet
sans le port ne fait jamais atteindre le statut « remboursée », donc
`restore_order()` n'était jamais appelé.

**Les deux membres lisent maintenant les lignes d'articles seules**, hors taxe
des deux côtés — la taxe est proportionnelle à la ligne, donc elle s'annule et
il n'y a rien à réconcilier.

### Le point aveugle était dans le banc, et c'est la vraie leçon

Les onze assertions de la section 10 utilisaient toutes un total **égal au
panier net**. Le modèle n'avait donc jamais été mesuré sur une commande portant
du port. Le gabarit du banc pose désormais un port de 10 € **par défaut**, pour
qu'aucune assertion de cette section ne puisse à nouveau tourner sur une
commande sans port.

### Un statut « remboursée » atteint en ne remboursant que le port

Sur une commande dont la marchandise est payée en entier par l'avoir, les
lignes d'articles valent zéro : le total de la commande **n'est que le port**.
Le rembourser fait considérer la commande comme entièrement remboursée, et
`restore_order()` rendait l'avoir complet — 100 € créés, confirmé sur le vrai
WooCommerce.

**Le statut n'est pas contredit par de l'arithmétique.** Une boutique qui
marque une commande remboursée a déclaré quelque chose, et beaucoup tiennent
leur comptabilité ainsi sans jamais créer d'enregistrement de remboursement :
leur refuser la restitution garderait l'argent du client, qui est le pire des
deux maux. L'argent revient donc, et **l'exposition est dite sur la commande**.

### Un enregistrement abîmé fabriquait ou retenait de l'argent

`GIVEN_BACK_META` n'était gardé que par `is_numeric()`, là où tout le reste du
fichier exige `is_numeric() && is_finite()` et borne.

- valeur **négative** (`-999`) → avoir de 100 € porté à **1 099 €**, non borné ;
- valeur **`'1e400'`** (INF) → le remboursement ne rendait rien **en silence**,
  40 € retenus, et la commande restait ni remboursable ni prélevable.

Lu désormais entre bornes (`max(0, min(taken, …))`), et un enregistrement
illisible est **signalé** au lieu d'être avalé.

### Et deux fois, une doublure plus permissive que la réalité

- `get_tax_refunded_for_item()` prend un identifiant de **taux de taxe**, pas
  un article. J'avais inventé la signature ; ma doublure l'a acceptée et c'est
  le vrai WooCommerce qui a levé l'erreur fatale. C'est le miroir exact de la
  doublure *plus stricte* trouvée la veille.
- Mon gabarit réel laissait la ligne d'article à 100 € au lieu du net de 60 € :
  le banc réel a rendu 6 € au lieu de 10 €, et c'était le gabarit.

### Mesure

| | |
|---|---|
| assertions des bancs autonomes | **222** (`register` 124, `restitution` 51, `wipe` 26, `cycle` 14, `carry` 7) |
| injections | **56 / 56 attrapées** |
| vrai WooCommerce | **11 / 11**, dont le port et le partiel par `wc_create_refund()` |
| chaînes traduites | 66 / 66 |

### Ce qui reste assumé, et chiffré

Un remboursement **par montant seul**, sans ligne désignée, ne désigne aucune
marchandise : la part vaut alors zéro et l'avoir ne reçoit rien jusqu'à ce que
la commande atteigne le statut « remboursée ». C'est la lecture honnête — ni
WooCommerce ni l'extension ne savent ce qui est revenu — mais un marchand qui
rembourse toujours par montant ne verra jamais d'acompte sur l'avoir.

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
