# ERP Classifié — Système de Gestion d'Entreprise PHP

Système ERP complet développé en PHP/MySQL avec interface mobile-first, thème sombre, génération PDF et applications PWA. Conçu pour les PME en Afrique de l'Ouest (Côte d'Ivoire).

---

## Modules

| Module | Description |
|--------|-------------|
| **Auth** | Connexion unifiée, profil utilisateur, clés d'accès |
| **Dashboard** | Tableau de bord admin + Agent ERP IA intégré |
| **Finance / Caisse** | Point de vente (POS), facturation, dépenses, versements, export PDF |
| **Commandes** | Interface mobile de prise de commande, bons de livraison |
| **Stock** | Gestion des produits, mouvements de stock, demandes d'appro, PWA offline |
| **RH** | Employés, présence (pointage), paie, portail employé PWA |
| **Clients** | Fichier clients, créances |
| **Messagerie** | Messagerie interne, intégration WhatsApp, visioconférence, notifications temps réel |
| **Documents** | Gestion documentaire sécurisée (upload, download, versionning) |
| **Admin** | Alertes, notifications système, logs d'activité |
| **Système** | Backup BDD, restauration, diagnostic, moniteur sécurité |

---

## Stack technique

- **Backend** : PHP 8.x, PDO/MySQL
- **PDF** : FPDF 1.86 + TCPDF
- **Frontend** : Vanilla JS, CSS custom properties (thème dark), Font Awesome 6
- **PWA** : Service Workers + Web App Manifest (Stock, RH)
- **Auth** : Sessions PHP, middleware de rôles

---

## Prérequis

- PHP >= 8.0
- MySQL >= 5.7 / MariaDB >= 10.4
- Serveur Apache ou Nginx
- Extension PHP : `pdo_mysql`, `mbstring`, `gd`, `fileinfo`

---

## Installation

### 1. Cloner le dépôt

```bash
git clone https://github.com/APOLLINAIRE225/erp-php-classified.git
cd erp-php-classified
```

### 2. Configurer la base de données

Créer la base de données et mettre à jour les identifiants dans `../app/core/DB.php` :

```php
private static string $dbName = 'NOM_BDD';
private static string $dbUser = 'utilisateur';
private static string $dbPass = 'mot_de_passe';
```

> Le fichier `DB.php` se trouve dans le dossier parent `/app/core/` (structure monorepo).

### 3. Importer le schéma SQL

```bash
mysql -u root -p NOM_BDD < schema.sql
```

### 4. Configurer le serveur web

**Apache** — pointer le `DocumentRoot` vers `/var/www/html/` et activer `mod_rewrite`.

**Nginx** — exemple de bloc serveur :

```nginx
server {
    listen 80;
    server_name erp.local;
    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.0-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 5. Permissions uploads

```bash
chmod -R 775 uploads/
chown -R www-data:www-data uploads/
```

### 6. Accéder à l'application

```
http://localhost/_php_classified/auth/login_unified.php
```

---

## Structure du projet

```
_php_classified/
├── admin/              # Alertes & notifications admin
├── api_support/        # Passerelle API, endpoints JSON
├── auth/               # Authentification, profil, clés d'accès
├── clients/            # Gestion des clients
├── dashboard/          # Tableau de bord, Agent ERP IA
├── documents/          # Gestion documentaire
├── finance/            # Caisse POS, factures, dépenses, versements
├── hr/                 # RH : employés, présence, paie (PWA)
├── legal/              # Pages légales (CGU, confidentialité)
├── messaging/          # Messagerie, WhatsApp, visioconférence
├── orders/             # Commandes mobiles, bons de livraison
├── stock/              # Produits, stock, appros (PWA)
├── system/             # Debug, backup, logs, sécurité
├── uploads/            # Fichiers uploadés (images produits...)
└── bootstrap_paths.php # Helpers de chemins et URLs
```

---

## Fonctionnalités clés

- **Point de vente (POS)** complet avec panier, modes de paiement, tickets PDF
- **Interface mobile** optimisée pour commandes terrains (commande_mobile.php)
- **PWA Stock** installable sur mobile, fonctionne offline
- **PWA Employé** pour pointage et consultation de paie
- **Agent ERP IA** intégré dans le dashboard
- **Génération PDF** : factures, tickets, bons de livraison, exports comptables
- **Système de notifications** temps réel
- **Multi-société / multi-magasin** (company_id + city_id)
- **Gestion des promotions** : flash, remise %, quantité
- **Demandes d'appro** avec workflow de validation

---

## Sécurité

- Requêtes SQL préparées (PDO) — zéro injection SQL
- Contrôle d'accès par rôles (middleware PHP)
- Upload sécurisé avec vérification MIME type
- Pages de monitoring et diagnostic intégrées

---

## Licence

Projet propriétaire — tous droits réservés.  
Contact : [@APOLLINAIRE225](https://github.com/APOLLINAIRE225)
