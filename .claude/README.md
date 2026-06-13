# Configuration Claude Code — Plugin Photo Contest

Ce dossier contient toute la configuration pour travailler avec Claude Code sur le plugin Photo Contest Manager.

## Contenu

```
.
├── CLAUDE.md                                 # Guide projet principal (lu à chaque session)
└── .claude/
    ├── agents/                               # Subagents spécialisés
    │   ├── code-reviewer.md                  # Relecture de code
    │   ├── test-runner.md                    # Tests guidés
    │   └── db-inspector.md                   # Diagnostic BDD
    └── skills/                               # Skills (connaissances activables)
        ├── wp-conventions/
        │   └── SKILL.md                      # Conventions WordPress (sécurité, hooks, naming)
        ├── elementor-gotchas/
        │   └── SKILL.md                      # Pièges Elementor et contournements
        └── photo-contest-flows/
            ├── SKILL.md                      # Workflows métier (inscription, jury, etc.)
            └── references/
                └── diagnostic-bdd.md         # Requêtes SQL prêtes à l'emploi
```

## Installation

### Étape 1 — Copier les fichiers dans votre projet

Depuis la racine de votre WordPress (`C:\laragon\www\sdlp\` chez vous) :

```bash
# Le CLAUDE.md va à la racine du projet (pas dans le plugin lui-même)
cp CLAUDE.md C:\laragon\www\sdlp\CLAUDE.md

# Le dossier .claude/ va aussi à la racine
cp -r .claude C:\laragon\www\sdlp\.claude
```

**Important** : `CLAUDE.md` et `.claude/` vont à la **racine WordPress**, pas dans `wp-content/plugins/photo-contest/`. Comme ça, Claude Code voit toute la structure WordPress (thème, autres plugins) en plus du plugin.

### Étape 2 — Installer Claude Code (si pas déjà fait)

Prérequis : Node.js 18+.

```bash
# Vérifier Node
node --version

# Installer Claude Code
npm install -g @anthropic-ai/claude-code

# Vérifier
claude --version
```

### Étape 3 — Premier lancement

```bash
cd C:\laragon\www\sdlp
claude
```

À la première connexion, vous serez invité à vous authentifier avec votre compte Anthropic.

### Étape 4 — Vérifier que Claude voit la configuration

Dans la session Claude Code, tapez :

```
Lis le CLAUDE.md et résume-moi ce que tu sais du projet.
```

Claude devrait répondre avec un résumé incluant :
- Le nom du projet (SDLP / Photo Contest Manager)
- La stack technique (WordPress + Elementor + Stripe + Fluent CRM)
- Les 5 pages principales et leurs shortcodes
- Les pièges connus (Elementor, timezone Laragon)

Si Claude ne mentionne pas ces points, c'est que `CLAUDE.md` n'est pas au bon endroit.

## Utilisation des skills

Les skills sont **automatiquement consultés** par Claude quand leur description matche votre demande. Vous n'avez pas besoin de les invoquer manuellement.

Exemples :
- "Ajoute un nonce sur le formulaire d'export" → Claude consulte `wp-conventions/SKILL.md`
- "Le cookie n'est pas posé après vérification email" → Claude consulte `elementor-gotchas/SKILL.md`
- "Comment fonctionne le passage en statut 'retenue' ?" → Claude consulte `photo-contest-flows/SKILL.md`

## Utilisation des subagents

Les subagents s'invoquent explicitement avec la commande :

```
/agents
```

Puis vous choisissez l'agent à lancer. Exemples de cas d'usage :

### code-reviewer
Après avoir modifié un fichier :
```
Relis class-pc-photos.php avec le code-reviewer.
```

### test-runner
Pour valider un workflow :
```
Lance le test-runner pour valider l'inscription d'un candidat de bout en bout.
```

### db-inspector
Quand quelque chose cloche en BDD :
```
Avec le db-inspector, diagnostique pourquoi le candidat ID 12 ne voit pas ses photos.
```

## Personnalisation

Si vous voulez ajouter d'autres skills ou agents, créez simplement un nouveau dossier ou fichier dans la bonne arborescence. Claude Code les détecte automatiquement.

Pour modifier un skill existant, éditez son `SKILL.md`. La structure YAML frontmatter en haut est obligatoire :

```markdown
---
name: nom-du-skill
description: Quand et pourquoi utiliser ce skill (texte qui pilote le déclenchement)
---

# Contenu du skill...
```

## Travail collaboratif

Si plusieurs personnes travaillent sur le projet, commitez `CLAUDE.md` et `.claude/` dans Git. Comme ça, chacun bénéficie de la même configuration et les mises à jour des skills profitent à tous.

Suggestion de `.gitignore` à exclure (à ne pas commiter) :
- `.claude/sessions/` — historique local des sessions
- `.claude/cache/` — cache local

## Évolution

Ces fichiers sont une **base de départ**. Au fur et à mesure des interventions sur le projet :

- Quand un nouveau piège est découvert → l'ajouter dans le skill approprié
- Quand un workflow est précisé → enrichir `photo-contest-flows/SKILL.md` ou créer un fichier dans `references/`
- Quand un nouveau type de problème récurrent émerge → créer un nouveau skill dédié

Le but est que **Claude devienne de plus en plus efficace sur ce projet spécifique** au fil du temps, plutôt que de devoir tout réexpliquer à chaque session.

## Ressources

- Doc Claude Code officielle : https://docs.claude.com
- Concept de skills : https://docs.claude.com/en/docs/claude-code/skills
- Concept de subagents : https://docs.claude.com/en/docs/claude-code/sub-agents
