# 📝 Guide de contribution

Merci de votre intérêt pour contribuer à **African Trust Fund** ! 🎉

## 📋 Table des matières

- [Code de conduite](#code-de-conduite)
- [Comment commencer](#comment-commencer)
- [Process de contribution](#process-de-contribution)
- [Standards de code](#standards-de-code)
- [Commit messages](#commit-messages)
- [Pull Requests](#pull-requests)

## 📜 Code de conduite

Nous nous engageons à fournir un environnement inclusif et respectueux.

**Comportements attendus:**
- ✅ Utilisez un langage inclusif et respectueux
- ✅ Acceptez les critiques constructives
- ✅ Concentrez-vous sur ce qui est mieux pour la communauté

**Comportements inacceptables:**
- ❌ Le harcèlement ou les attaques personnelles
- ❌ Les commentaires offensants liés au genre, l'orientation, l'âge, etc.
- ❌ Le partage de données sensibles ou privées

## 🚀 Comment commencer

### 1. Configurer votre environnement

```bash
# Cloner votre fork
git clone https://github.com/VOTRE_USERNAME/african_strust_fund_php.git
cd african_strust_fund_php

# Ajouter le remote upstream
git remote add upstream https://github.com/jeanpaulktm/african_strust_fund_php.git

# Mettre à jour depuis upstream
git fetch upstream
git checkout main
git merge upstream/main
```

### 2. Créer une branche

```bash
# Créer une branche descriptive
git checkout -b feature/add-new-feature
# ou
git checkout -b fix/resolve-bug-issue-123
```

## 📝 Process de contribution

### Signaler des bugs

Avant de créer un bug report, vérifiez si le problème n'existe pas déjà.

**Incluez:**
- Une description claire du bug
- Les étapes pour le reproduire
- Le comportement attendu
- Le comportement réel
- Votre environnement (PHP version, OS, etc.)
- Les logs d'erreur pertinents

### Proposer des fonctionnalités

Avant de commencer à coder, ouvrez une issue pour discuter de votre idée.

**Incluez:**
- Une description claire de la fonctionnalité
- Le cas d'utilisation
- Les bénéfices potentiels
- Les détails d'implémentation proposés

## 💻 Standards de code

### PHP

```php
<?php
declare(strict_types=1);

namespace African\Trust\Fund;

/**
 * Description de la classe
 * 
 * @package African\Trust\Fund
 */
class MyClass
{
    /**
     * Description de la méthode
     *
     * @param string $param Description du paramètre
     * @return string Description du retour
     */
    public function myMethod(string $param): string
    {
        return "Result";
    }
}
```

**Conventions:**
- ✅ Utiliser le type hinting (PHP 7.4+)
- ✅ Documenter avec PHPDoc
- ✅ Noms en camelCase pour les méthodes
- ✅ Noms en PascalCase pour les classes
- ✅ Noms en UPPER_CASE pour les constantes
- ✅ Maximum 100 caractères par ligne
- ✅ 4 espaces d'indentation

### JavaScript

```javascript
/**
 * Description de la fonction
 * @param {string} param - Description du paramètre
 * @returns {string} Description du retour
 */
function myFunction(param) {
    const result = param.trim();
    return result;
}
```

**Conventions:**
- ✅ Utiliser const/let, pas var
- ✅ Documenter avec JSDoc
- ✅ Noms en camelCase
- ✅ Éviter les variables globales
- ✅ Utiliser des template literals

### SQL

```sql
-- Commentaires explicatifs
SELECT 
    id,
    name,
    email,
    created_at
FROM 
    users
WHERE 
    status = 'active'
ORDER BY 
    created_at DESC;
```

**Conventions:**
- ✅ Mots-clés en MAJUSCULES
- ✅ Indenter les sous-clauses
- ✅ Noms de tables en minuscules avec underscores
- ✅ Toujours utiliser prepared statements

## 📌 Commit messages

Utilisez le format **Conventional Commits**:

```
type(scope): subject

body

footer
```

**Types:**
- `feat`: Nouvelle fonctionnalité
- `fix`: Correction de bug
- `docs`: Modification de documentation
- `style`: Formatage, missing semicolons, etc.
- `refactor`: Refactorisation du code
- `perf`: Amélioration de performance
- `test`: Ajout ou modification de tests
- `chore`: Dépendances, build, etc.

**Exemples:**

```bash
# Nouvelle fonctionnalité
git commit -m "feat(loan): add interest calculation automation"

# Correction de bug
git commit -m "fix(auth): resolve session timeout issue #123"

# Documentation
git commit -m "docs(readme): update installation instructions"

# Refactorisation
git commit -m "refactor(database): optimize query performance"
```

## 🔀 Pull Requests

### Avant de soumettre

- [ ] Votre branche est à jour avec `main`
- [ ] Pas de conflits de fusion
- [ ] Tous les tests passent
- [ ] Le code suit les standards
- [ ] Les commentaires inutiles sont supprimés
- [ ] Les dépendances sensibles ne sont pas commitées

### Description de la PR

```markdown
## Description
Brève description des changements

## Type de changement
- [ ] Bug fix (correction sans breaking change)
- [ ] Nouvelle fonctionnalité (nouvelle feature)
- [ ] Breaking change (correction ou feature avec impact)
- [ ] Mise à jour de documentation

## Lié à
Closes #123

## Comment a été testé?
Description de la procédure de test

## Checklist
- [ ] Mon code suit les standards du projet
- [ ] J'ai fait une auto-revue de mon code
- [ ] J'ai commenté les parties complexes
- [ ] Les modifications correspondent à la documentation
- [ ] Pas de warnings inutiles
```

### Revue de code

- Soyez respectueux et constructif
- Focalisez-vous sur le code, pas la personne
- Suggérez des améliorations, pas des ordres
- Approuvez une fois satisfait

## 🧪 Tests

Tous les changements doivent inclure des tests:

```bash
# Exécuter les tests
php vendor/bin/phpunit

# Avec couverture
php vendor/bin/phpunit --coverage-html coverage/
```

## 📚 Ressources

- [PHP.net Documentation](https://www.php.net/docs.php)
- [PSR Standards](https://www.php-fig.org/psr/)
- [Conventional Commits](https://www.conventionalcommits.org/)

## ❓ Questions?

- 📧 Ouvrir une discussion
- 💬 Créer une issue
- 📖 Consulter la documentation

---

**Merci encore de votre contribution!** 🙏

Chaque contribution, grande ou petite, aide à améliorer African Trust Fund pour la communauté.
